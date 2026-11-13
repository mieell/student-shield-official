from fastapi import FastAPI, HTTPException, Security, Depends, Request
from fastapi.security import APIKeyHeader
from pydantic import BaseModel
from typing import Optional
from slowapi import Limiter, _rate_limit_exceeded_handler
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.responses import JSONResponse
import re
import os
import hmac
import contextvars
import logging
import torch
from transformers import pipeline
from deep_translator import GoogleTranslator, MyMemoryTranslator
import nltk
from risk_history import evaluate_risk_history
from violent_threats import detect_violent_threat
from translation_gate import TranslationGate
from crisis_context import current_disclosure_text

translation_gate = TranslationGate()

try:
    nltk.data.find('tokenizers/punkt')
except LookupError:
    nltk.download('punkt', quiet=True)
try:
    nltk.data.find('tokenizers/punkt_tab')
except LookupError:
    nltk.download('punkt_tab', quiet=True)

### Performance 
torch.set_num_threads(2)

### Logging 
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("ML_Service")
# Suppress noisy Hugging Face download telemetry & unauthenticated warnings
logging.getLogger("huggingface_hub").setLevel(logging.ERROR)
logging.getLogger("filelock").setLevel(logging.ERROR)


app = FastAPI(
    title="StudentShield ML Microservice",
    description=(
        "Advanced NLP for Emotion, Depression, Bullying, Sarcasm, "
        "Sexual Harassment, Academic Pressure, Self-Worth, and Masked Depression Detection"
    ),
    version="7.3.0",
    # Do not publish the API schema/Swagger UI publicly; /analyze-text is the only intended surface.
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)

### Rate Limiting 
limiter = Limiter(key_func=get_remote_address)
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)

### IP Whitelist Middleware 
ALLOWED_IPS = [ip.strip() for ip in os.getenv("ALLOWED_IPS", "").split(",") if ip.strip()]

class IPWhitelistMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request, call_next):
        # Skip whitelist if not configured (development mode)
        if not ALLOWED_IPS:
            return await call_next(request)
        client_ip = request.client.host
        # Allow health checks from anywhere
        if request.url.path in ("/", "/docs", "/openapi.json"):
            return await call_next(request)
        if client_ip not in ALLOWED_IPS:
            logger.warning(f"Blocked request from unauthorized IP: {client_ip}")
            return JSONResponse(status_code=403, content={"detail": f"IP {client_ip} not authorized"})
        return await call_next(request)

app.add_middleware(IPWhitelistMiddleware)

### Security 
API_KEY = os.getenv("ML_API_KEY", "").strip()
API_KEY_NAME = "X-API-KEY"
api_key_header = APIKeyHeader(name=API_KEY_NAME, auto_error=True)

async def verify_api_key(api_key: str = Security(api_key_header)):
    if not API_KEY:
        logger.error("ML_API_KEY is not configured; rejecting analysis requests")
        raise HTTPException(status_code=503, detail="Service authentication is not configured")
    if not hmac.compare_digest(api_key, API_KEY):
        raise HTTPException(status_code=401, detail="Invalid API Key")
    return api_key

base_dir = os.path.dirname(os.path.abspath(__file__))

# MODEL LOADING

emotion_classifier = pipeline(
    "text-classification",
    model="SamLowe/roberta-base-go_emotions",
    device=-1, truncation=True, max_length=512
)

local_config_file = os.path.join(base_dir, "models", "mdeberta_finetuned", "config.json")
model_hub_path = "ashlogg/student-shield-mdeberta"

distress_classifier = None

# 1. Try local fine-tuned model if valid config.json exists
if os.path.exists(local_config_file):
    try:
        local_model_dir = os.path.join(base_dir, "models", "mdeberta_finetuned")
        distress_classifier = pipeline(
            "text-classification", model=local_model_dir,
            device=-1, truncation=True, max_length=512
        )
        logger.info(f"Loaded local custom model from: {local_model_dir}")
    except Exception as e:
        logger.warning(f"Could not load local model: {e}")

# 2. Try Hugging Face Hub fine-tuned model
if distress_classifier is None:
    try:
        distress_classifier = pipeline(
            "text-classification", model=model_hub_path,
            device=-1, truncation=True, max_length=512
        )
        logger.info(f"Loaded fine-tuned model from Hugging Face Hub: {model_hub_path}")
    except Exception as e:
        logger.warning(f"Could not load model from Hugging Face Hub ({model_hub_path}): {e}")

# 3. Final fallback if custom model is unavailable
if distress_classifier is None:
    fallback_model = "SamLowe/roberta-base-go_emotions"
    distress_classifier = pipeline(
        "text-classification", model=fallback_model,
        device=-1, truncation=True, max_length=512
    )
    logger.info(f"Loaded baseline fallback model: {fallback_model}")

# Cast mDeBERTa/CPU models to float32 for ~6x faster CPU inference with <1e-4 logit delta.
if distress_classifier is not None and callable(getattr(getattr(distress_classifier, "model", None), "float", None)):
    try:
        distress_classifier.model.float()
    except Exception as e:
        logger.warning(f"Could not cast distress model to float32: {e}")

### Taglish / Gen-Z Slang Normalizer 
TAGLISH_SLANG_MAP = {
    "naliyak": "umiyak", "naiyak": "umiyak", "naiiyak": "umiiyak",
    "naluha": "umiyak", "luhaan": "umiyak", "iyaq": "umiyak",
    "nassad": "malungkot", "nasad": "malungkot", "na-sad": "malungkot",
    "nakakasad": "nakakalungkot", "nakaka-sad": "nakakalungkot",
    "na-depress": "depressed", "nadedepress": "depressed",
    "nafefeel": "nararamdaman", "nafeefeel": "nararamdaman",
    "na-feel": "naramdaman", "nakaka-anxious": "nag-aalala",
    "na-stress": "stressed", "nastress": "stressed", "stressed af": "sobrang stressed",
    "na-overwhelm": "sobrang bigat", "na-drain": "sobrang pagod",
    "burned out": "pagod na pagod", "burnout": "pagod na pagod",
    "lowkey sad": "medyo malungkot", "highkey sad": "sobrang lungkot",
    # Added for the 7 new depression patterns
    "wala na kong gana": "wala na akong gana",
    "wala na kong feel": "wala na akong nararamdaman",
    "mag isa": "mag-isa",
    "makipag usap": "makipag-usap",
    "nagrereply": "sumasagot",
    "inaabangan": "inaasahan",
    ### v7.1: Expanded Gen-Z / Taglish slang 
    "lods": "friend", "lodi": "idol", "mars": "friend",
    "besh": "friend", "bes": "friend", "mamsh": "friend",
    "siz": "friend", "dzai": "friend", "accla": "friend",
    "chariz": "charot", "chz": "charot", "dejk": "de joke",
    "de jk": "de joke", "eme": "joke", "eme lang": "joke lang",
    "skl": "share ko lang", "nmn": "naman", "dn": "din",
    "lng": "lang", "kc": "kasi", "kse": "kasi",
    "dko": "di ko", "dna": "di na",
    "pko": "pa ko", "nko": "na ko", "nlng": "na lang", "nlang": "na lang",
    "kms": "kill myself", "kys": "kill yourself",
    "deadz": "dead", "dedz": "dead", "deds": "dead", "ded": "dead",
    "hugot": "emotional statement", "sanaol": "sana all",
    "dasurv": "deserve", "laftrip": "laugh trip",
    "naurrr": "no", "slayyy": "excellent", "slaying": "doing great", "periodt": "period",
    "ate girl": "older sister", "kuya boy": "older brother",
    "na-trauma": "traumatized", "na-trigger": "triggered",
    "na-bully": "bullied", "binully": "bullied",
    "na-cancel": "cancelled", "na-ghost": "ghosted",
    "petsa de peligro": "broke", "walwal": "sobrang lasing",
    "gigil": "frustrated", "inis": "annoyed",
    "badtrip": "frustrated", "bwisit": "annoyed",
    "pagod na pagod": "pagod na pagod", "sobrang pagod": "sobrang pagod",
    ### v7.7: Shorthand / Texting Typo Mapping
    "tlga": "talaga", "tlg": "talaga",
    "ung": "yung", "yng": "yung",
    "bgt": "bigat", "bgat": "bigat",
    "wla": "wala", "wl": "wala",
    "nkkaintndi": "nakakaintindi", "nkakaintindi": "nakakaintindi", "naintndhan": "naintindihan",
    "skin": "sa akin", "skn": "sa akin", "sakin": "sa akin",
    "kht": "kahit", "khit": "kahit",
    "q": "ko", "aq": "ako", "ak": "ako",
    "mwla": "mawala", "mawla": "mawala", "mwala": "mawala",
    "mgpanggap": "magpanggap", "magpangap": "magpanggap", "gpanggap": "nagpanggap",
    "prang": "parang", "parng": "parang",
    "twa": "tawa", "twang": "tawang",
    "pro": "pero", "pr": "pero",
    "pg": "pag", "kya": "kaya", "ky": "kaya",
    "plang": "pala", "pla": "pala",
    "d": "di", "ndi": "di", "hndi": "hindi", "hnd": "hindi",
    ### v7.7: English common typos & SMS shortcuts
    "suicde": "suicide", "suicid": "suicide", "sucide": "suicide", "suiside": "suicide",
    "evryone": "everyone", "evrybody": "everybody", "every1": "everyone",
    "bettr": "better", "btr": "better",
    "anymor": "anymore",
    "tonite": "tonight", "2nite": "tonight",
    "depresed": "depressed", "depresion": "depression",
    "exhasted": "exhausted", "hopeles": "hopeless",
    "nobodie": "nobody", "nobdy": "nobody", "nobadi": "nobody",
    "bullid": "bullied", "bullyd": "bullied",
    "peopl": "people", "ppl": "people",
    "clas": "class", "tomorow": "tomorrow", "tmrw": "tomorrow",
    "wana": "want to", "kmt": "kill myself",
    # Existing contractions
    "yk": "you know", "ngl": "not gonna lie", "tbh": "to be honest",
    "fr": "for real", "rn": "right now", "nvm": "nevermind",
    "idk": "i don't know", "prolly": "probably", "kinda": "kind of",
    "tryna": "trying to", "gonna": "going to", "wanna": "want to",
    "smth": "something", "srsly": "seriously", "def": "definitely",
    "deadass": "seriously", "no cap": "seriously", "istg": "i swear to god",
    "im": "i am", "ive": "i have", "dont": "don't", "cant": "can't",
    "wont": "won't", "didnt": "didn't", "doesnt": "doesn't",
}

def normalize_slang(text: str) -> str:
    normalized = text.lower()
    # Collapse repeated/elongated characters (e.g. "pagoooooood" -> "pagod", "naaaaa" -> "na")
    normalized = re.sub(r'([a-zA-Z])\1{2,}', r'\1', normalized)
    for slang, proper in sorted(TAGLISH_SLANG_MAP.items(), key=lambda x: len(x[0]), reverse=True):
        normalized = re.sub(r'\b' + re.escape(slang) + r'\b', proper, normalized, flags=re.IGNORECASE)
    return normalized

class RiskHistoryEntry(BaseModel):
    risk_level: str
    created_at: str

class TextPayload(BaseModel):
    raw_text: str
    student_id: Optional[str] = None
    risk_history: list[RiskHistoryEntry] = []

class MLResponse(BaseModel):
    risk_score: float
    risk_level: str
    flag_reason: Optional[str] = None
    trajectory: Optional[str] = None
    escalation_probability: Optional[float] = None


# HELPER FUNCTIONS

_POSITIVE_EMO_WEIGHTS = {
    "gratitude": 0.85, "joy": 0.80, "optimism": 0.75, "love": 0.70,
    "excitement": 0.65, "amusement": 0.55, "admiration": 0.50, "pride": 0.45,
    "relief": 0.40, "caring": 0.35, "approval": 0.30,
}

_NEGATIVE_EMO_WEIGHTS = {
    "sadness": 1.0, "fear": 0.9, "anger": 0.8, "grief": 1.0, "remorse": 0.8,
    "disgust": 0.6, "disappointment": 0.7, "nervousness": 0.7, "embarrassment": 0.55,
    "annoyance": 0.5, "disapproval": 0.45, "confusion": 0.2, "neutral": 0.0,
    "surprise": 0.0, "joy": 0.0, "admiration": 0.0, "amusement": 0.0,
    "approval": 0.0, "caring": 0.0, "desire": 0.0, "excitement": 0.0,
    "fascination": 0.0, "gratitude": 0.0, "love": 0.0, "optimism": 0.0,
    "pride": 0.0, "realization": 0.0, "relief": 0.0,
}

_DISTRESS_LABELS = {"1", "LABEL_1", "NEGATIVE", "TOXIC", "SADNESS", "GRIEF", "FEAR", "ANGER"}
_request_pos_emo_var: contextvars.ContextVar[Optional[float]] = contextvars.ContextVar(
    "_request_pos_emo_var", default=None
)


def _run_classifier(classifier, text: str) -> list[dict]:
    """Run a HuggingFace pipeline requesting all label scores when supported."""
    try:
        raw = classifier(text, top_k=None)
    except TypeError:
        raw = classifier(text)
    if isinstance(raw, list) and raw and isinstance(raw[0], list):
        raw = raw[0]
    if isinstance(raw, dict):
        return [raw]
    return [item for item in raw if isinstance(item, dict)] if isinstance(raw, list) else []


def _aggregate_weighted_scores(items: list[dict], weights: dict[str, float]) -> float:
    """Combine weighted multi-label probabilities without saturating on single neutral labels."""
    weighted = sorted(
        (weights.get(str(item.get("label", "")), 0.0) * float(item.get("score", 0.0)) for item in items),
        reverse=True,
    )
    if not weighted:
        return 0.0
    if len(weighted) == 1:
        return weighted[0]
    return min(1.0, weighted[0] + 0.20 * sum(weighted[1:3]))


def _get_positive_emotion_conf(translated_text: str) -> float:
    """Safely inspect go_emotions positive confidence without altering get_risk_components' signature."""
    try:
        if not translated_text or not translated_text.strip():
            return 0.0
        items = _run_classifier(emotion_classifier, translated_text[:1500])
        return _aggregate_weighted_scores(items, _POSITIVE_EMO_WEIGHTS)
    except Exception:
        return 0.0


def get_risk_components(translated_text: str, normalized_text: str) -> tuple[float, float]:
    try:
        max_distress_conf = 0.0
        max_neg_emo_conf  = 0.0
        max_pos_emo_conf  = 0.0

        trans_sentences = nltk.sent_tokenize(translated_text)
        norm_sentences = nltk.sent_tokenize(normalized_text)

        def build_chunks(sentences, max_words=250):
            chunks = []
            curr_chunk = []
            curr_words = 0
            for s in sentences:
                s_words = len(s.split())
                if curr_words + s_words > max_words and curr_chunk:
                    chunks.append(" ".join(curr_chunk))
                    curr_chunk = [s]
                    curr_words = s_words
                else:
                    curr_chunk.append(s)
                    curr_words += s_words
            if curr_chunk:
                chunks.append(" ".join(curr_chunk))
            return chunks

        trans_chunks = build_chunks(trans_sentences)
        norm_chunks = build_chunks(norm_sentences)

        for chunk in trans_chunks:
            if not chunk.strip(): continue
            emo_items = _run_classifier(emotion_classifier, chunk)
            neg_emo_conf = _aggregate_weighted_scores(emo_items, _NEGATIVE_EMO_WEIGHTS)
            if neg_emo_conf > max_neg_emo_conf:
                max_neg_emo_conf = neg_emo_conf

            pos_emo_conf = _aggregate_weighted_scores(emo_items, _POSITIVE_EMO_WEIGHTS)
            if pos_emo_conf > max_pos_emo_conf:
                max_pos_emo_conf = pos_emo_conf

        _request_pos_emo_var.set(max_pos_emo_conf)

        for chunk in norm_chunks:
            if not chunk.strip(): continue
            dist_items = _run_classifier(distress_classifier, chunk)
            for item in dist_items:
                dist_label = str(item.get("label", "")).upper()
                if dist_label in _DISTRESS_LABELS:
                    dist_confidence = float(item.get("score", 0.0))
                    if dist_confidence > max_distress_conf:
                        max_distress_conf = dist_confidence

        # Positive emotions (gratitude, joy, optimism) dampen false-negative scores proportionally,
        # BUT NOT when masked depression / smiling through pain OR sarcastic anger/frustration is present.
        scan_lower = normalized_text.lower() + " " + translated_text.lower()
        is_masked = any(p in scan_lower for p in [
            "jokes", "tumatawa", "nakangiti", "sa labas", "outside", "pretend", "nagpapanggap",
            "tahimik", "mag-isa", "pag-uwi", "alone", "dalhin mag-isa", "makaramdam",
            "anger", "throw", "galit", "bwisit", "failed", "bagsak", "mad", "never go to class",
            "hate", "punch", "drop out"
        ])

        if max_pos_emo_conf >= 0.45 and not is_masked:
            dampen = min(0.45, max_pos_emo_conf * 0.50)
            max_neg_emo_conf *= (1.0 - dampen)
            max_distress_conf *= (1.0 - dampen * 0.5)

        return max_distress_conf, max_neg_emo_conf
    except Exception as e:
        logger.error(f"Inference error: {e}")
        return 0.0, 0.0


# KEYWORD DEFINITIONS

COMPILED_REGEXES = {}

def compile_regexes(patterns):
    return [re.compile(pat, re.IGNORECASE) for pat in patterns]

### CATEGORY 1: CRITICAL — Explicit Suicide Intent/Methods 
CAT1_DIRECT = [
    # Tagalog self-harm inflections; keep aligned with ExplicitCrisisGuard.php.
    r"\b(?:laslas|naglaslas|naglalaslas|maglaslas|maglalaslas|nilaslas|nilalaslas|paglaslas|paglalaslas|lalaslas|laslasin|lilaslasin)\b",
    r"\b(?:sinasaktan|sinaktan|sasaktan|saktan|sinasaksak|sinaksak|sasaksakin|saksakin)\s+(?:ko\s+(?:ang\s+)?sarili(?:\s+ko)?|(?:ang\s+)?sarili\s+ko)\b",
    r"\b(?:mag|nag|i)?pa(?:pa)?kamatay\b",
    r"\b(?:mag|nag)?bi(?:bi)?gti\b",
    r"\b(?:mag|nag)?bi(?:bi)?tay\b",
    r"\b(?:(?:pag)?(?:mag|nag)?pa(?:pa)?tiwakal|patiwakal)\b",
    r"\bsuicide\b", r"\bkill\s+my\s*self\b", r"\bend it all\b", r"\bcut\s+my\s*self\b",
    r"\b(?:hang|stab|unalive)\s+my\s*self\b",
    r"\bwant to die\b", r"\bno reason to live\b",
    r"\blaslas\b", r"\btatalon\b", r"\bsugatan\b",
    r"\btatapusin ko na buhay ko\b", r"\btapusin ko na\b", r"\btapusin ko na lang\b",
    r"\btapusin ko na lahat\b", r"\btapusin ko na lang lahat\b", r"\btatapusin ko na\b",
    r"\bgusto ko na mawala\b", r"\bgusto ko nang mamatay\b", r"\bgusto nako mamatay\b",
    r"\bgusto kong mamatay\b", r"\bsana mamatay na (?:ako|ko|aq)?\b", r"\bmamatay na lang (?:ako|aq)?\b",
    r"\bpwede bang mamatay na\b", r"\bnaisip kong mamatay\b",
    r"\bmagbibigti\b", r"\bsana kunin na ko ni lord\b",
    r"\bunalive myself\b", r"\bbetter off dead\b", r"\bsana matulog at di na magising\b",
    r"\bsana di na lang ako pinanganak\b", r"\bwala nang pag-asa\b",
    r"\bdi ko na alam gagawin\b", r"\blulunukin ko na lang\b",
    r"i\s*wanna\s*die", r"i\s*want\s*to\s*die", r"kill\s*my\s*self",
    r"end\s+(my|this)\s+life", r"don't\s+want\s+to\s+exist",
    r"don'?t\s+want\s+to\s+live", r"can'?t\s+do\s+this\s+anymore",
    r"wish\s+i\s+was\s+never\s+born",
    r"world\s+would\s+be\s+better\s+without\s+me", r"no\s+point\s+in\s+living",
    r"\bself.?harm\b", r"\bself.?destruct\b",
    r"i'?m\s+done\s+living", r"done\s+with\s+life", r"tired\s+of\s+living",
    r"hang\s+my\s*self", r"hanging\s+my\s*self",
    r"\bbigti\b", r"\bbitay\b", r"\bmagbigti\b", r"\bbibitay\b",
    r"\bpabitin\b", r"\bnakabitin\b",
    r"\btumalon\b", r"\blulundag\b",
    r"slit\s+my\s+wrist", r"stab\s+my\s*self",
    r"\bpain to stop\b", r"\bwant (?:all )?(?:this )?pain to stop\b",
    r"\bstop (?:the |this )?pain\b", r"\bmake the pain stop\b",
    r"\bfor good\b.{0,20}\b(stop|end|pain|buhay|tonight)\b",
    r"\btonight for good\b",
    # Bereavement-induced suicidal ideation (Following a deceased loved one / pet in death)
    r"\bsasabay na (?:ako|ko|aq|ko na lang|ako na lang)\b",
    r"\bsusunod na (?:ako|ko|aq|ko na lang|ako na lang)(?: sa kanya| sa kanila)?\b",
    r"\bsasama na (?:ako|ko|aq|ko na lang|ako na lang)(?: sa kanya| sa kanila)?\b",
    r"\bgusto ko nang? sumunod\b",
    r"\bgusto ko nang? sumama sa (?:kanya|kanila|langit|hukay)\b",
    r"\bsana sumabay na lang ako\b",
    r"\bsana sumunod na lang ako\b",
    r"\bmagkakasama na tayo\b.{0,20}\b(soon|malapit na|sa langit|sa kabilang buhay)\b",
    r"\bmagkikita na tayo\b.{0,20}\b(soon|malapit na|sa langit|sa kabilang buhay)\b",
    r"\bwait for me\b.{0,20}\b(soon|in heaven|sa kabilang buhay)\b",
    r"\bhintayin mo (?:ako|ko|aq)\b",
    r"\blost (?:my )?(?:whole )?motivation to live\b",
]

### CATEGORY 1.5: HIGH — Passive Disappearance & Invisibility Ideation (C-SSRS Level 2)
PASSIVE_DISAPPEARANCE_IDEATION = [
    r"wish\s+i\s+could\s+disappear",
    r"\bmay magbabago\b.{0,30}\bkung wala ako\b",
    r"\bmas maayos\b.{0,20}\bkung wala ako\b",
    r"\bmas maganda\b.{0,20}\bkung wala ako\b",
    r"\bmas okay\b.{0,20}\bkung wala ako\b",
    r"\bmas (?:magiging )?madali\b.{0,25}\bpara sa lahat\b",
    r"\bkung wala na ako\b",
    r"\bpara saan pa ako\b",
    r"\bbakit pa ako narito\b", r"\bbakit pa ako nandito\b",
    r"\bbakit pa ako nabuhay\b",
    r"\bwonder if anyone would notice\b",
    r"\banyone would notice if\b",
    r"\bwould anyone notice\b",
    r"\bnobody would notice\b",
    r"\bno one would notice\b",
    r"\bwould notice if\b.{0,40}\b(disappear|disappeared|gone|mawala|left)\b",
    r"\bwould\s+(?:anyone\s+)?(?:even\s+)?notice\s+if\s+i\b.{0,25}\banymore\b",
    r"\bdisappeared from\b.{0,25}\b(life|lives|everyone|everybody)\b",
    r"\bslowly disappeared\b",
    r"\bnever really there\b",
    r"\bwas never really there\b",
    r"\bfeel like i was never\b",
    r"\bstarted to feel like i was never\b",
    r"\bmakakapansin ba kung\b.{0,35}\bmawala\b",
    r"\bmawala sa lahat ng buhay\b",
    r"\bbigla.{0,15}mawala\b.{0,30}\b(buhay|life|lives)\b",
    r"\bnapapaisip ako kung\b.{0,30}\b(mawala|mapansin|notice)\b",
    r"\bwla rn nmn makakapansin\b",
    r"\bwala rin namang? makakapansin\b",
    r"if i (?:just )?disappeared\b",
    r"if i slowly disappeared\b",
    r"what if i disappeared\b",
    r"feel like i was never really there\b",
    r"hardest part.{0,40}never really there\b",
    r"\bdisappered\b",
    r"\b(?:hindi|di)\s+ko\s+na\s+makita\s+(?:ang\s+|yung\s+)?sarili\s+ko\s+sa\s+(?:future|hinaharap|bukas)\b",
    r"\b(?:can\s+no\s+longer|can'?t|cannot|don'?t)\s+(?:see|picture)\s+myself\s+(?:having\s+a\s+future|in\s+the\s+future)\b",
    r"\b(?:no\s+longer|can'?t|cannot)\s+(?:see|picture)\s+a\s+future\s+(?:for\s+myself|anymore)\b",
    r"(?:\b(?:ako|ko|aq)\b.{0,35})?\bmas\s+(?:mabuti|maganda|okay)\s+pang?\s+(?:maglaho|mawala\s+na\s+lang)(?:\s+na\s+lang)?\b",
]

CAT1_METHOD_SELF_PAIRS = [
    (r"\brope\b",           r"\b(my\s*self|hang|neck|bigti|bitay)\b"),
    (r"\bblades?\b",        r"\b(my\s*self|cut|wrist|laslas|arms?)\b"),
    (r"\bpills?\b",         r"\b(my\s*self|overdose|swallow|take|inumin)\b"),
    (r"\bpoison\b",         r"\b(my\s*self|drink|take|inumin)\b"),
    (r"\boverdose\b",       r"\b(my\s*self|pills?|gamot|take)\b"),
    (r"jump\s+(off|from)\b", r"\b(my\s*self|building|tulay|bridge|roof)\b"),
]

### CATEGORY 2: HIGH — Physical Violence, Bullying, Abuse 
CAT2_WORDS = [
    r"\bpunched\b", r"\bkicked\b", r"\bchoked\b", r"\bslapped\b", r"\bbeat me up\b",
    r"\bpushed\b", r"\bcornered\b", r"\bhit me\b", r"\bweapon\b", r"\bbleeding\b",
    r"\bbruises\b", r"\bsinapak\b", r"\bbinugbog\b", r"\bsinampal\b", r"\bbinatukan\b",
    r"\btinulak\b", r"\bsinabunutan\b", r"\bbinato\b", r"\bsinipa\b", r"\bkinulong\b",
    r"\bsinakal\b", r"\bhinawakan\b", r"\bkinuyog\b", r"\bpinagkaisahan\b",
    r"\bkinuwelyuhan\b", r"\bsinungalngal\b",
    r"\bgitukmod\b", r"\bgilabayan\b", r"\bkinabil\b", r"\bdinungpar\b", r"\bpinarigat\b",
    r"\bbinagsol\b", r"\bginulpi\b", r"\binambush\b", r"\bni-jump\b", r"\bjumped\b",
    r"\bextort\b", r"\bblackmail\b", r"\bdeath threat\b", r"\bharassed\b",
    r"\bnanampal\b", r"\bpumatol\b", r"\bpananakit\b", r"\binabuso\b",
    r"\bpinalo\b", r"\bpinagsamantalahan\b", r"\btinutukan\b", r"\bsinaksak\b",
    r"\bpinagbantaan\b", r"\btatakutin\b", r"\bhinamon\b", r"\bpinuruhan\b",
    r"\bnananakit\b", r"\btinadyakan\b", r"\bpinagtulungan\b", r"\bsabunot\b",
    r"\bdumugo\b", r"\bsugat\b", r"\bpinagsusuntok\b", r"\bsinuntok\b",
    r"\bpinagsipa\b", r"\bsinisipa\b", r"\bpinagbubugbog\b",
    r"\bsinaktan\b", r"\bsinasaktan\b", r"\bpinagsakitan\b",
    r"\bdinukot\b", r"\bkidnap\b", r"\bgrabbed\b", r"\bpinned\b", r"\bstrangled\b",
    r"\bpinagmalupitan\b", r"\binapi\b", r"\bpinag-iinitan\b",
]

### CATEGORY 3: MEDIUM — Verbal Abuse, Cyberbullying 
CAT3_WORDS = [
    r"\bugly\b(?!-crying\b|\s+crying\b)", r"\bfat\b", r"\bloser\b", r"\bslut\b", r"\bwhore\b", r"\bdumb\b",
    r"\bstupid\b", r"\bcancel\b", r"\bdoxx\b", r"\bexposed\b", r"\bhate you\b",
    r"\bbobo\b", r"\btanga\b", r"\bpangit\b", r"\bmataba\b", r"\bpabuhat\b",
    r"\bchismis\b", r"\bmalandi\b", r"\bpokpok\b", r"\bpabida\b", r"\bsquatter\b",
    r"\bbida-bida\b", r"\bsalot\b", r"\bepal\b", r"\bplastik\b", r"\bsipsip\b",
    r"\bkupal\b", r"\bpapansin\b", r"\bjejemon\b", r"\bfeelingera\b", r"\bwalang ambag\b",
    r"\blibak\b", r"\bin-insulto\b", r"\bna-cancel\b", r"\bna-bash\b",
    r"\btoxic\b", r"\bshamed\b", r"\bhumiliated\b",
    r"\bsiniraan\b", r"\bpinagchismisan\b", r"\bpinahiya\b", r"\btinawanan\b",
    r"\bmanyak\b", r"\bwalang kwenta\b", r"\bidyota\b", r"\bgago\b",
    r"\btarantado\b", r"\binutil\b", r"\bpinagtatawanan\b",
    r"\bcyberbully\b", r"\bposer\b", r"\bfake account\b",
    # Subtle GC / Group chat harassment / Screenshots
    r"\bgroup chat\b", r"\bgc\b", r"\bscreenshots?\b",
    r"\bsubtle jokes\b", r"\bjokes about me\b", r"\bmaking fun of me\b",
    r"\btalking behind my back\b", r"\bsecret gc\b", r"\bpinag-uusapan sa gc\b",
    r"\bpinagtitripan\b", r"\bpinagtripan\b", r"\bpinag-uusapan ako\b",
    r"\bpinagtatawanan ako\b",
]

### EMOTIONAL DISTRESS 
EMOTIONAL_DISTRESS = [
    # Persistent first-person fatigue differs from ordinary tiredness after an activity.
    r"\bi(?:['’]ve| have)\s+been\s+tired\s+for\s+(?:so\s+long|weeks|months)\b",
    r"\bdepressed\b", r"\bdepression\b", r"can't take this anymore",
    r"\btired all the time\b", r"\bnothing feels right\b", r"\bgoing through the motions\b",
    r"\bno point in trying\b", r"\bhoping the days pass faster\b", r"\beverything feels off\b",
    r"\bfeeling numb\b", r"\bempty inside\b", r"\bgiven up\b", r"\bgiving up\b",
    r"\blost hope\b", r"\bdon't care anymore\b", r"\bpointless\b", r"\blosing my mind\b",
    r"\bcan't do this anymore\b", r"so mentally draining", r"literally mentally exhausted",
    r"\bi am sad\b", r"\bim sad\b", r"\bi'?m\s+so\s+sad\b", r"\bso\s+sad\b",
    r"\breally sad\b", r"\bsuper sad\b", r"\bfeeling sad\b", r"\bstill sad\b",
    r"(?<!\bnot )(?<!\bnever )\bi['’]?(?:m| am)\s+(?:feeling\s+)?(?:just\s+|really\s+|very\s+|so\s+)?(?:sad|down|lonely)\b",
    r"(?<!\bnot )(?<!\bnever )\b(?:feel(?:ing)?\s+(?:really\s+|very\s+|so\s+)?(?:down|sad)|(?:really\s+|very\s+|so\s+)?down\s+and\s+sad)\b",
    r"(?<!\bnot )(?<!\bnever )\bsad\s+and\s+(?:lonely|down|tired)\b",
    r"\bunbearable\s+weight\b", r"\bno\s+strength\s+left\b",
    r"\bcrying\b", r"\bcried\b", r"\bcan'?t stop crying\b", r"\bbeen crying\b",
    r"\btears\b", r"\bsobbing\b", r"\bbreaking down\b", r"\bbroke down\b",
    r"\bhurting\b", r"\bhurts so much\b", r"\bin pain\b", r"\bheartbroken\b",
    r"\bmiserable\b", r"\bstressed\b", r"\banxious\b", r"\bpanic\b", r"\bpanic attacks?\b",
    r"\boverwhelmed\b", r"\bdrained\b", r"\bexhausted\b", r"\bburned out\b",
    r"\bbreaking apart\b", r"\bfalling apart\b", r"\bcan'?t breathe\b", r"\bd nko mkahinga\b", r"\bdi na makahinga\b",
    r"\bpagod na ako\b", r"\bsobrang\s+pagod\s+na\s+(?:ako|ko|aq)\b", r"\bsobrang lungkot\b", r"\bsobrang bigat\b", r"\bkapagod\b",
    r"\bdrowning\s+in\s+(?:sadness|sorrow|pain|grief|darkness)\b",
    r"\bnalulunod\s+sa\s+(?:lungkot|sakit|kalungkutan)\b",
    r"\bmamatay (?:sa |s |nang |ng )?(?:pagod|kaba|stress|overwork|puyat)\b",
    r"\bhirap na hirap\b", r"\bsawang sawa na\b", r"\bdi ko na kaya\b",
    r"\bayoko na\b", r"\bdurug na durog\b", r"\bnamamatay\b",
    r"\bwasak na wasak\b", r"\bsukong suko na\b", r"\bparang sasabog utak ko\b",
    r"\bwalang silbi\b", r"\bwala akong kwenta\b", r"\bpabigat ako\b",
    r"\bnaliyak\b", r"\bnaiyak\b", r"\bnaiiyak\b", r"\bumiyak\b", r"\biyak\b",
    r"iyak nang iyak", r"\bumiiyak s[a]? cr\b", r"\bnassad\b", r"\bnasad\b", r"\bmalungkot\b", r"\bnalulungkot\b", r"\bnalungkot\b",
    r"\blungkot\b", r"\bsobrang sad\b", r"\bna-depress\b", r"\bnadedepress\b",
    r"\bnastress\b", r"\bna-stress\b", r"\bna-overwhelm\b",
    r"\bsakit ng dibdib\b", r"\bmasakit\b", r"\bnasasaktan\b",
    r"\bnagmumukmok\b", r"\bnagku-kuba\b", r"\bdi makatulog\b",
    r"gikapoy nakog maayo", r"di nako makaya", r"wala koy pulos", r"gilaay na jud",
    r"bug-at kaayo", r"kapoy na mabuhi", r"way kwenta", r"suko nako", r"di na mada",
    r"\bnagbakho\b", r"\bnagdugo ang dughan\b", r"\bnagul-an\b",
    r"mabannogakon ti panagbiag", r"narigat unay", r"awan pag-asa", r"awan inka serserbi",
    r"saan ko nga kaya", r"nadagsen unay",
    ### Added for pattern #2: Persistent sadness 
    r"\bang bigat lang lagi\b",
    r"\bbigat lagi\b.{0,20}\bkahit walang nangyayari\b",
    r"\bkahit walang nangyayari\b.{0,30}\bbigat\b",
    r"parang may dala.?dala.{0,30}hindi ko maalis",
    r"\bbigat na hindi ko maalis\b",
    r"\bbigat sa dibdib\b",
    r"\bparang may pasan\b.{0,20}\bhindi ko maalis\b",
    r"\bpalaging mabigat\b", r"\bpalagi akong malungkot\b",
    r"\blagging malungkot\b", r"\bwala namang dahilan\b.{0,20}\bmalungkot\b",
    ### Added for nuanced exhaustion / burnout
    r"\beverything feels heavy\b", r"\bsmallest things take so much energy\b",
    r"\btake so much energy\b", r"\bdon'?t even recognize myself\b",
    r"\bmentally exhausting\b",
    r"\bang sakit\b", r"\bang sakit no\b",
    ### v7.4 & v7.5: Masked Depression, Smiling Depression, Severe Burnout & Anhedonia
    r"\bang bigat bigat\b",                         # emotional exhaustion
    r"\bdi ko gets kung bakit\b.{0,30}\b(malungkot|sad|ganito)\b",
    r"\bparang gusto ko lang matulog\b",             # burnout / escape
    r"\bgusto ko lang matulog\b.{0,30}\b(na|at|hindi|di)\b",
    r"\bsana matapos na\b",                          # hopeless fatigue
    r"\bgusto ko na lang matapos yung araw\b",      # emotional exhaustion
    r"\bmatapos yung araw nang walang\b",
    r"\bgaano katagal pa ba\b",                      # how much longer
    r"\bdi ko na alam kung kailan\b",
    r"\bdi ko alam kung gaano ko pa\b",
    r"\bhindi ko alam kung kailan ako naging ganito\b",
    r"\bwala na kong lakas\b",                       # no more strength
    r"\bwala na akong lakas\b",
    r"\bnawalan na ko ng lakas\b",
    r"\bnawalan na ako ng lakas\b",
    r"\bdi na kaya ng katawan ko\b",
    r"\bnakakapagod din pala\b",                     # quiet realization of exhaustion
    r"\bgaano pala ako kapagod\b",
    r"\bpagod na pagod na ko sa lahat\b",
    r"\bsobrang pagod ko na\b",
    r"\bhindi ko na kaya\b",                         # variant of di ko na kaya
    r"\bndi ko na kaya\b",
    r"\bnahihirapan ako\b",
    r"\bnahihirapan na ako\b",
    r"\byung feeling na parang\b.{0,30}\b(wala|numb|manhid|blangko)\b",
    r"\bkapag mag.?isa lang\b.{0,30}\b(iba|lumalabas|naiiyak|malungkot)\b",
    r"\bkapag nag.?iisa\b.{0,30}\b(iba|lumalabas|naiiyak|malungkot)\b",
    r"\biyak nalang lagi\b", r"\bnaiiyak nalang\b",
    r"\bdi ko mapigilan ang luha\b", r"\bdi ko mapigilan umiyak\b",
    r"\bparang walang katapusan\b",                  # feels endless
    r"\bkelan ba matatapos\b",
    r"\bbakit ganito\b.{0,20}\b(lagi|palagi|parati)\b",
    r"\bdi ko na gets ang buhay\b",
    r"\bparang wala nang iba\b",
    r"\binuubos na ko nito\b",
    r"\bkumakapit na lang\b",                        # just hanging on
    r"\bkahit paano\b.{0,20}\bkumakapit\b",
    r"\bsinusubukan ko pang\b.{0,30}\b(maging okay|lumaban|magpatuloy)\b",
    r"\bwala\s+nang\s+natitirang\s+lakas\b",
    r"\b(?:sobrang\s+)?pagod\s+na\s+pagod\s+na\s+(?:ako|akong|ko|aq)\b",
    r"\bpasan\s+ko\s+ang\s+(?:buong\s+)?mundo\b",
    r"\bwalang\s+katapusang\s+(?:sakit|pagod|hirap|lungkot)\b",
]

### SOCIAL ISOLATION / WITHDRAWAL 
SOCIAL_ISOLATION = [
    r"\b(?:thinking|feel(?:ing)?)(?:\s+like|\s+that)?\s+(?:nobody|no one)\s+(?:really\s+)?(?:knows|understands)\s+me\b",
    r"want(?:ed)?\s+to\s+be\s+alone", r"\bleave me alone\b", r"\bi feel alone\b",
    r"\bso alone\b", r"\ball alone\b", r"\bfeel(?:ing)?\s+lonely\b",
    r"\bno one (?:cares|understands|listens)\b",
    r"\bnobody (?:cares|understands|gets it|gets me)\b",
    r"\bisolat(?:ed|ing)\b", r"\bpushing everyone away\b", r"\bdon'?t want to talk\b",
    r"\bwithdraw(?:ing|n)?\b", r"\bshut(?:ting)?\s+everyone out\b",
    r"\bno\s+(?:real\s+)?friends\b", r"\bnobody\s+(?:to\s+)?talk\s+to\b",
    r"\bdistancing\b", r"\bpulling away\b", r"\bhiding from everyone\b",
    r"\bdon'?t belong\b", r"\boutcast\b", r"\bleft out\b", r"\bexcluded\b",
    r"\bnag-iisa\b", r"\bmag-isa\b", r"\bwalang kasama\b", r"\bwalang kaibigan\b",
    r"\bwala akong kausap\b", r"\biniwanan\b", r"\bnaiwan\b",
    r"\bwala naman(?:g)? nagmamahal\b", r"\bwala naman(?:g)? may paki\b",
    r"\bwala akong paki\b", r"\biwan na lang\b",
    r"\bwala naman nakakapansin\b", r"\b(?:parang|feel(?:ing)?\s+ko)\s+(?:lang\s+)?invisible\b",
    r"\bdi naman ako hinahanap\b", r"\bwala naman akong silbi sa kanila\b",
    r"\bnag-inusara\b", r"\bwalay kauban\b", r"\bwalay higala\b",
    r"\bginabiyaan\b", r"\bnagpadayon nga mag-inusara\b",
    ### Added for pattern #4: Isolation / low communication energy 
    r"\bdi na masyado nagrereply\b",
    r"\bdi na nagrereply\b.{0,20}\b(friends|tropa|barkada|kaibigan)\b",
    r"\bwala akong energy makipag.?usap\b",
    r"\bwala na akong energy makipag.?usap\b",
    r"\bnawalan ng energy makipag.?usap\b",
    r"\bhindi na ako sumasagot\b.{0,20}\b(friends|tropa|kaibigan)\b",
    r"\bndi na ako nagrereply\b",
    r"\btamad na makipag.?usap\b",
    r"\bdi na gusto makipag.?socialize\b",
    r"\bhinahayaan ko na lang\b.{0,20}\b(messages|chats|texts)\b",
    r"\bnabawasan na ang\b.{0,20}\b(pakikipag.?usap|pakikisalamuha)\b",
    ### Passive invisibility — social isolation (non-ideation, kept here)
    r"\bwala.{0,20}talagang kasama\b",
    r"\bwalang talagang kasama\b",
    r"\bparang wala ka pa rin\b.{0,20}\bkasama\b",
    r"\bsurrounded.{0,50}\b(wala|walang).{0,15}kasama\b",
    ### v7.4: Reddit/PH research — real Filipino student isolation language
    r"\bwala akong malapitan\b",                    # nobody to turn to
    r"\bwala akong mapuntahan\b",
    r"\bwala akong mapag.?usapan\b",
    r"\bwala akong matakbuhan\b",
    r"\bparang walang nagmamahal\b",
    r"\bparang wala akong katangkilik\b",
    r"\bine.?exclude\b",                             # excluded (Taglish)
    r"\btinatalikuran\b",                            # turned away from / ignored
    r"\bina-avoid\b", r"\bin.?avoid\b",
    r"\bpinag.?iiwasan\b",
    r"\bwala silang ginagawa\b.{0,30}\b(help|tulong|report)\b", # nobody helping
    r"\bparang invisible lang ako\b",
    r"\bparang ghost ako\b",
    r"\bkahit nandito ako\b.{0,30}\bparang wala\b",
    r"\bnag.?eenjoy sila\b.{0,30}\bwala ako\b",    # they enjoy without me
    r"\bsana may kasamahan\b",
    r"\bgusto ko lang magkaroon ng\b.{0,20}\b(tao|kaibigan|kasama|kausap)\b",
    r"\bnobody checks on me\b",
    r"\bno one checks on me\b",
    r"\bnobody reaches out\b",
    r"\bnapag-?iiwanan(?:\s+na\s+(?:ako|ko|aq))?(?:\s+ng\s+lahat)?\b",
    r"\bleft\s+behind\s+by\s+(?:everyone|everybody|all\s+of\s+them)\b",
]

### SARCASM / GEN-Z TAGLISH BIAS-CORRECTOR (v7) 
# NOTE: Removed the overly broad bare-suffix pattern (naman/lang/daw)
# that falsely matched "di naman halata" as a joke.
JOKE_PATTERNS = [
    # Core laugh markers
    r"\bhaha\b", r"\bchar\b", r"\bcharot\b", r"\blol\b", r"\blmao\b",
    r"\bHAHAHA\b", r"\bhehe\b", r"\blmfao\b",
    r"\bjk\b", r"\bjust kidding\b", r"\bkidding\b",
    r"\bjoke lang\b", r"\bde joke\b", r"\bchar lang\b", r"\bcharot lang\b",
    r"\bhaha char\b", r"\bjoke lang naman\b",
    r"\bdi naman totoo\b", r"\bdi seryoso\b",
    r"\bnagbibiro lang\b", r"\bbiruan lang\b",
    # Gen-Z death humor (laughing, NOT crisis)
    r"\bim dead\b", r"\bi'm dead\b",
    r"\bded\b", r"\bdedz\b", r"\bdedge\b",
    r"\bnamatay\b.{0,20}\b(tawa|katatawa)\b",
    r"\b(dead|ded)\b.{0,15}\b(haha|lol|lmao|char|charot)\b",
    r"😂", r"🤣", r"💀",
    r"😂💀", r"💀😂", r"😭💀",
    # Academic hyperbole neutralizer
    r"\b(kms|kys)\b.{0,40}\b(math|exam|test|quiz|class|homework|hw|assignment|"
    r"project|thesis|defense|recit|plate|subject|prof|teacher|guro|module|output)\b",
    r"\b(exam|test|quiz|math|school|homework|hw|thesis|recit|plate|subject|"
    r"prof|module|assignment)\b.{0,40}\b(kms|kill me|end me|want to die|wanna die|"
    r"i want to die|magpakamatay|mamatay na ko)\b",
    r"\bbro\b.{0,60}\b(exam|test|quiz|hw|homework|assignment|project|thesis|recit|"
    r"subject|plate|defense)\b",
    r"\bthis\s+(exam|quiz|test|subject|prof|thesis|plate|recit|defense)\b"
    r".{0,30}\b(kill|destroy|ruin|end|murder)\b.{0,10}\bme\b",
    # Trivial trigger neutralizer
    r"\b(kill me|want to die|wanna die|kms)\b.{0,40}"
    r"\b(traffic|bored|boring|food|hungry|hot|tanga|gago|kupal|petsa de peligro)\b",
    r"\b(traffic|bored|boring|food|hungry|hot)\b.{0,40}"
    r"\b(kill me|want to die|wanna die|kms)\b",
    # Taglish casual deflection
    r"\brelax lang\b", r"\bchill lang\b", r"\bnot serious\b",
    r"\bsarcasm\b", r"\birony\b",
    # Gen-Z filler
    r"\bgg\b", r"\bawit\b", r"\bslay\b", r"\bfr fr\b",
    r"\b(life|day|week|month|life is)\b.{0,20}\b(slaying|slay)\b",
    r"\bslaying rn\b",
    r"\bnaol\b", r"\beme\b", r"\bsige\b",
    r"\bno cap\b.{0,20}\b(haha|lol|joke)\b",
    # Bisaya casual
    r"\bhahaha\b", r"\bkuyawa\b.{0,20}\b(haha|lol)\b",
    r"\bnagkatawa\b", r"\bkatawa kaayo\b",
    # Repeated punctuation (exaggeration signal)
    r"(!{3,})", r"(\?{3,})",
    ### v7.2: Ironic / sardonic tone markers
    # Detect bitter sarcasm WITHOUT explicit joke keywords.
    # Common in Gen-Z venting: genuine frustration expressed through ironic praise.
    # English ironic constructions
    r"\bwhat a privilege\b",
    r"\btruly living the dream\b",
    r"\bliving the dream\b",
    r"\babsolutely fantastic\b",
    r"\bi just love\b.{0,40}\b(stress|disappoint|problem|same|waking up|every day)\b",
    r"\bso lucky\b.{0,40}\b(everything|falls apart|worst|every time|consistently)\b",
    r"\bmy favorite part\b",
    r"\bsuch a\b.{0,10}\b(heartwarming|delightful|wonderful|lovely)\b",
    r"\bnothing says\b.{0,30}\bquite like\b",
    r"\bcharacter development\b",
    r"\bcharacter.?building\b",
    r"\bcharacter.?arc\b",
    r"\bfun little talent\b",
    r"\bhow delightful\b", r"\bhow wonderful\b",
    r"\bwhat a joy\b", r"\bwhat a treat\b",
    r"\bwhat a time to be alive\b",
    r"\b(r[ée]sum[ée]|resume)\b.{0,20}\bsomeday\b",
    r"\bthe consistency is\b.{0,15}\b(comforting|nice|great)\b",
    # Taglish ironic constructions
    r"\bthank you\b.{0,10}\btalaga\b.{0,20}\b(sa life|sa buhay|universe|mundo)\b",
    r"\bang (galing|saya|comforting|nice)\b.{0,20}\b(talaga|naman)\b",
    r"\bpero okay lang\b.{0,20}\bsanay\b",
    r"\bsanay naman\b.{0,20}\bakong\b",
    r"\bpara hindi.{0,15}(ma-?bore|mabore)\b",
    r"\bdaily character development\b",
]

### POSITIVE SENTIMENT (grateful / thriving vents — used to suppress false MEDIUM/CRITICAL)
POSITIVE_SENTIMENT = [
    r"\bthankful\b", r"\bgrateful\b", r"\bso good lately\b", r"\blife has been so good\b",
    r"\bang saya\b", r"\bfeel like you belong\b", r"\bappreciated\b", r"\bappreciating\b",
    r"\bappreciation\b", r"\bexcited ako\b", r"\bexcited about\b", r"\bexcited for\b",
    r"\bmagandang mangyayari\b", r"\bslaying rn\b", r"\blife is kinda slay\b",
    r"\bsobrang thankful\b", r"\bnapapangiti\b", r"\bang sarap pala\b", r"\bsarap sa feeling\b",
    r"\bgenuinely happy\b", r"\bnagpapagaan ng mood\b", r"\bexcited sa future\b",
    r"\bpahalagahan\b", r"\bmasaya ako\b", r"\bmasaya naman\b", r"\bblessed\b",
    r"\bnagpapasalamat\b", r"\bmemorable\b", r"\btawanan\b", r"\brandom moments\b",
    r"\bpeaceful\b", r"\bokay na muna\b", r"\blittle things\b", r"\bso much more lately\b",
    r"\bsobrang happy\b", r"\bhappy ko\b", r"\bnag-?pay off\b", r"\bfeeling blessed\b", r"\bgood vibes\b",
    r"\bnapakasaya\b", r"\bsobrang saya\b", r"\bnakaka-?proud\b", r"\bnakaka-?inspire\b",
    r"\bnaiiyak(?: ako)? sa tuwa\b", r"\btears of joy\b", r"\bhappy tears\b",
    r"(?<!\bnot )(?<!\bhindi )(?<!\bdi )(?<!\bwalang )\bhappy\b",
    r"(?<!\bnot )(?<!\bhindi )(?<!\bdi )(?<!\bwalang )\bmasaya\b",
    r"\byay\b", r"\byey\b", r"\bsobrang slay\b", r"\bslay\b",
    r"\bfeeling good\b", r"\bfeeling great\b", r"\bawesome\b", r"\bamazing\b",
    r"\bna-upgrade\b", r"\bswerte\b", r"\bpanalo\b", r"\bproud\b",
    r"\bnakakatuwa\b", r"\bnatutuwa\b",
    r"\bpure\s+relief\b", r"\btears\s+of\s+relief\b",
    r"\bscreaming\s+with\s+joy\b", r"\bgreatest\s+miracle\b",
]

### COGNITIVE DISTORTIONS 
DISTORTIONS = [
    r"\balways\b", r"\bnever\b", r"\bpalaging\b",
    # "laging" alone is too broad — e.g. "may laging something na nagpapagaan ng mood"
    r"\blaging\b.{0,25}\b(malungkot|sakit|bigat|problem|fail|bagsak|lungkot|iyak|pagod|hirap|sama)\b",
    r"\bwala na talaga\b", r"\bhinding hindi\b", r"\beveryone hates me\b",
    r"\blahat sila\b", r"\bpalagi na lang\b",
    # Added for pattern #6: Overthinking / rumination
    r"\blagi ko iniisip\b",
    r"\bhindi ko ma.?isip\b.{0,20}\b(kung|bakit|paano)\b",
    r"\bpaulit.?ulit\b.{0,20}\biisip\b",
    r"\bdi ko matigilan\b.{0,20}\biisip\b",
    r"\bpalagi akong nag.?iisip\b",
    r"\bmay silbi ba ako\b",
    r"\bkung may silbi pa ako\b",
    r"\bpara ba akong may silbi\b",
    r"\bpara bang wala akong silbi\b",
    r"\b(?:pagod\s+na\s+(?:ako\s+)?kakaisip|kakaisip\s+kung\s+kaya\s+ko)\b",
]

### HOPELESSNESS 
HOPELESSNESS = [
    r"\bbahala na\b", r"\bwhatever happens\b", r"\byaan mo na\b",
    r"\bwala rin naman magbabago\b", r"\bpointless\b", r"\buseless to try\b",
    r"\btired of trying\b", r"\bhopeless\b", r"\bno hope\b",
    # Added for pattern #5: Quiet giving up
    r"\bwala na yung feeling na may inaabangan\b",
    r"\bwala na akong inaabangan\b",
    r"\bwala nang inaabangan\b",
    r"\bwalang inaabangan\b",
    r"\bwala na akong inaasahan\b",
    r"\bwala na akong hinihintay\b",
    r"\bhindi ko na alam bakit pa\b",
    r"\bwala na akong motive\b",
    r"\bginagawa ko lang dahil kailangan\b",
    r"\bgagawin ko lang kasi kailangan\b",
    r"\bkailangan lang gawin\b.{0,20}\bkaya gagawin\b",
    r"\bwala na yung excitement\b",
    r"\bwala na yung saya\b.{0,20}\bgagawin ko\b",
    r"\bparang robot na lang\b",
    r"\bwala nang point\b",
    r"\bwalang\s+point\b",
    r"\bwalang\s+mangyay(?:a)?ri\s+sa\s+buhay\b",
    r"\bwala nang dahilan\b.{0,20}\bsubukan\b",
    r"\btired of trying\b",
    r"\bsuko na talaga\b",
    ### v7.4: Reddit/PH research — real Filipino student hopelessness language
    r"\bdi na worth it\b",                           # not worth it anymore
    r"\bdi na worth it ang lahat\b",
    r"\bpara saan pa ba\b",                           # what's the point anyway
    r"\bparang walang silbi\b.{0,20}\b(mag.?aral|gawin|subukan|ituloy)\b",
    r"\bwala namang magiging mangyayari\b",
    r"\bwala namang magiging pagbabago\b",
    r"\bganito na lang ba palagi\b",
    r"\bpagod na ko sa pag.?asa\b",                  # tired of hoping
    r"\bpagod na ko umasa\b",
    r"\bkasalanan ko pa rin\b.{0,20}\b(lagi|palagi|parati)\b",
    r"\bsuka na ko sa sistema\b",
    r"\bdi na ako naniniwala\b.{0,20}\b(magbabago|mas magiging okay)\b",
    r"\bpag.?asa na lang talaga\b.{0,20}\bwala na ko\b",
    r"\bwala na ko\b.{0,20}\bpag.?asa\b",
    r"\bit never gets better\b",
    r"\bit won'?t get better\b",
    r"\bbakit pa ba magpapagal\b",
    r"\bbakit pa ba magsisikap\b",
    r"\bgusto\s+(?:ko\s+)?nang?\s+sumuko\b",
    r"\bwala\s+(?:na|naman)ng?\s+nagbabago\b",
    r"\bno\s+(?:direction|purpose)(?:\s+or\s+purpose)?(?:\s+left)?(?:\s+anymore)?\b",
    r"\bno\s+strength\s+left\s+to\s+(?:keep\s+)?fighting\b",
    r"\bnothing\s+(?:ever\s+)?changes\b",
    r"(?<!\bdon't )(?<!\bdo not )(?<!\bnever )\b(?:just\s+)?want\s+to\s+give\s+up(?:\s+(?:on\s+(?:life|everything)|already|now|because|[.!?,])|$)",
    r"\bnothing\s+(?:ever\s+)?gets\s+better\b",
    r"\bthings\s+(?:will\s+)?never\s+get\s+better\b",
    r"\bwala\s+nang\s+(?:natitirang\s+)?lakas(?:\s+pa)?\s+(?:para|upang)?\s*(?:lumaban|magpatuloy)\b",
    r"(?<!\bhindi )(?<!\bdi )\bmatagal\s+na\s+(?:ako|akong)\s+sumuko(?!\s+sa\s+(?:game|laro|ml|valo|dota|quiz|puzzle))\b",
    r"\b(?:naghihintay|nag-?aantay)\s+na\s+lang\s+(?:na\s+)?matapos\s+ang\s+lahat\b",
]

### EXTREME VENTING 
EXTREME_VENTING = [
    r"\bputangina\b", r"\bgago\b", r"\bfuck\b", r"\bshit\b",
    r"\btangina\b", r"\bbullshit\b",
]

### ANHEDONIA / DISSOCIATION 
ANHEDONIA = [
    r"\bfeeling nothing\b", r"\bempty\b", r"\bwatching myself\b",
    r"\bsitting in my chest\b", r"heavy.*chest", r"\bdull weight\b",
    r"functioning from a distance", r"blur together", r"going through the motions",
    r"don't care about anything anymore", r"loss of interest",
    r"can't feel happiness", r"world feels grey", r"colorless",
    r"zombie", r"autopilot", r"dead inside",
    # Nuanced Anhedonia
    r"\bquiet shift\b", r"\bdon'?t feel like myself\b",
    r"lutang lang", r"parang mag-isa",
    r"hindi ka fully nandun", r"hirap gumalaw", r"nawalan ng gana",
    r"walang nararamdaman", r"manhid\b", r"blangko\b", r"walang kagana-gana",
    r"tinatamad mabuhay", r"so draining", r"literally nothing", r"spaced out",
    r"just existing", r"autopilot era", r"wala na kong pake", r"wala nakong pake",
    r"wala na akong maramdaman",
    r"wala nakoy paki", r"wa nakoy paki", r"lutaw kaayo", r"wala koy gana",
    r"wa koy gana", r"gilaay nako", r"way pulos", r"di na maka feel",
    r"kapoy na tanan", r"wa na koy emosyon",
    r"awan ganak", r"awan rikna", r"buyong ti panunot", r"awanen serserbi",
    r"nauma akon", r"awan serserbik", r"awan marikrikna",
    ### Added for pattern #1: Low energy / numb 
    r"\bwala na akong gana sa lahat\b",
    r"\bwala na akong gana\b",
    r"\bnawalan ng gana sa lahat\b",
    r"kahit.{0,20}gusto ko dati",      # "kahit yung mga bagay na gusto ko dati"
    r"\bkahit gusto ko dati\b",
    r"\bhindi ko na nae.?enjoy\b",
    r"\bhindi ko na ini.?enjoy\b",
    r"\bwala na akong feel sa\b",
    r"\bwala na kong feel sa\b",
    r"\bwala na akong masaya\b",
    r"\bdi ko na feel ang saya\b",
    r"\bhindi naman umiiyak\b.{0,40}\bwala.{0,10}gana\b",  # exact pattern #1 structure
    r"\bwala akong gana\b.{0,20}\bkahit anong\b",
    r"\bkahit anong gawin ko\b.{0,20}\bwala\b.{0,10}\b(feel|gana|saya)\b",
    r"\bpati na rin\b.{0,20}\b(gusto ko dati|dati kong gusto)\b",
]

### FINALITY / PREPARATION 
FINALITY = [
    r"sorted .* stuff", r"done fighting", r"won't .* notice the difference",
    r"won't be a burden", r"better off without me",
    r"giving away my", r"ready to leave", r"time to go", r"better place soon",
    r"no one will miss me", r"everyone will be happier", r"won't be here much longer",
    r"cleaning up my notes", r"organizing a few things", r"felt oddly peaceful",
    r"wrote down .* messages just in case", r"want things to be settled",
    r"mind feels really quiet", r"finally quiet",
    r"inayos ko na.* gamit", r"di na ko lalaban", r"suko na ko",
    r"di naman nila mapapansin", r"sana matapos agad",
    r"di na ko magiging pabigat", r"mas okay kung wala ako",
    r"mas mabuti pang wala ako", r"ipapamigay ko na",
    r"handa na ko umalis", r"oras na para umalis",
    r"walang makakamiss sakin", r"mas masaya sila kung wala ako",
    r"di na ko magtatagal", r"di nyo na ko poproblemahin",
    r"ayoko na mag-isip", r"tuloy pa rin ang buhay",
    r"ang payapa", r"tahimik na ang isip ko",
    ### Added for pattern #5: Quiet giving up / no more anticipation
    r"ginagawa ko na lang.{0,20}kailangan",
    r"wala na yung feeling.{0,20}may inaabangan",
    r"basta gagawin ko na lang",
    r"walang point.{0,20}mag.?aabang pa",
]

### SEXUAL HARASSMENT 
SEXUAL_HARASSMENT = [
    r"\brape[d]?\b", r"\bsexual assault\b", r"\bsexually assaulted\b",
    r"\bsexually abused\b", r"\bsexual abuse\b", r"\bmolested\b",
    r"\bsexual harassment\b", r"\bsexually harassed\b",
    r"\bgroped\b", r"\bgroping\b",
    r"\btouched me inappropriately\b", r"\bunwanted touching\b",
    r"\bhe touched\b", r"\bshe touched\b", r"\bthey touched\b",
    r"\bforced me\b", r"\bcoerced\b", r"\bgroomed\b", r"\bfelt up\b",
    r"\bunsolicited\b.{0,20}\b(photo|pic|image|video|content)\b",
    r"\bdick pic\b", r"\bnudes?\b.{0,20}\bwithout\b.{0,10}\bconsent\b",
    r"\bcatcalled\b", r"\bcatcalling\b", r"\bflashed\b", r"\bflashing\b",
    r"\bsexual comments\b",
    r"\bpervert\b.{0,20}\b(touched|grabbed|cornered)\b",
    r"\bhe\b.{0,10}\bfollowing me\b.{0,20}\b(home|school|dark)\b",
    r"\bsent\b.{0,10}\b(explicit|nude|inappropriate)\b.{0,10}\b(photo|video|pic)\b",
    r"\bginahasa\b", r"\bgahasa\b", r"\binahasa\b",
    r"\bnagahasa\b", r"\bginagahasa\b",
    r"\bpinagsamantalahan\b.{0,20}\b(katawan|body|sekso|babae|babagi)\b",
    r"\binabuso\b.{0,20}\b(katawan|body|sekso)\b",
    r"\bhinalikan\b.{0,20}\bwalang pahintulot\b",
    r"\bhinipo\b.{0,20}\b(di dapat|walang pahintulot|ayaw ko)\b",
    r"\bpinilit\b.{0,20}\b(halik|hawak|gawa|gawin|hubad|makipag)\b",
    r"\bginawang laruan\b", r"\binilantad\b", r"\bpinakita\b.{0,20}\bpribado\b",
    r"\bpinagsabihan\b.{0,20}\bbastos\b", r"\bbinabastos\b", r"\bbinabastos ako\b",
    r"\bmay gumapang\b", r"\bhinawakan\b.{0,20}\b(di dapat|ayaw ko|puerta)\b",
    r"\bpinag-aapuhap\b", r"\bnilapitan\b.{0,20}\bbastos\b",
    r"\bnakahubad\b.{0,20}\bpinanonood\b",
    r"\bpinagmasdan\b.{0,20}\b(malaswa|muta|bastos)\b",
    r"\bsinulyapan\b.{0,20}\b(bastos|muta)\b",
    r"\bpilit\b.{0,20}\b(halik|yakap|galawin)\b",
    r"\btinutukan\b.{0,20}\b(katawan|damit)\b",
    r"\bgigahasa\b", r"\bginagahasa\b",
    r"\bgiabuso\b.{0,20}\b(lawas|body|babaye)\b",
    r"\bgipugos\b", r"\bgihikap\b.{0,20}\bwalay\b.{0,10}\bpagtugot\b",
    r"\bgibasol\b", r"\bgihubaran\b", r"\bgikulbaan\b",
    r"\bgisulong\b.{0,20}\b(babaye|lawas)\b",
    r"\bginrape\b", r"\bginabuso\b.{0,20}\b(bagi|lawas)\b",
    r"\bnaginggina\b", r"\bpinugsan\b",
]

### ACADEMIC PRESSURE 
ACADEMIC_PRESSURE = [
    r"\bfailed\b.{0,20}\b(exam|test|subject|class|course|semester)\b",
    r"\bfailing\b.{0,20}\b(school|class|subject|course|semester)\b",
    r"\bacademic probation\b",
    r"\bdismissed\b.{0,20}\b(school|university|college)\b",
    r"\bdropped\b.{0,20}\b(class|subject|school|course)\b",
    r"\bcan'?t graduate\b", r"\bwon'?t graduate\b", r"\bnot graduating\b",
    r"\bscholarship\b.{0,20}\b(lost|revoked|gone|pulled|cancelled)\b",
    r"\bthesis\b.{0,20}\b(stress|pressure|panic|fail|rejected|disapproved)\b",
    r"\bdefense\b.{0,20}\b(scared|nervous|anxious|fail|rejected|disapproved)\b",
    r"\bpanel\b.{0,20}\b(rejected|failed|harsh|unfair)\b",
    r"\bacademic pressure\b", r"\bacademic stress\b",
    r"\bschool\b.{0,20}\b(killing me|is too much|overwhelming|ruins everything)\b",
    r"\bgrades\b.{0,20}\b(bad|terrible|failing|zero|crashed|ruined)\b",
    r"\bpressured\b.{0,20}\b(school|grades|study|parents|professor)\b",
    r"\bcan'?t keep up\b.{0,20}\b(school|studies|class|deadlines)\b",
    r"\boverwhelmed\b.{0,20}\b(school|studies|academics|deadlines)\b",
    r"\bno time to sleep\b.{0,20}\b(school|study|thesis|project)\b",
    r"\bdeadline\b.{0,20}\b(stress|panic|overwhelmed|too many|missed)\b",
    r"\bprofessor\b.{0,20}\b(hate|unfair|threatening|failing me|biased)\b",
    r"\bparents\b.{0,20}\b(pressure|force|expect|disappointed|angry)\b"
    r".{0,30}\b(grades|school|study)\b",
    r"\bgrades\b.{0,20}\b(parents|pressure|disappoint|force)\b",
    r"\bbagsak\b.{0,20}\b(exam|subject|klase|test|board|bar)\b",
    r"\bhindi makapasa\b", r"\bdi makapasa\b",
    r"\bdi makagraduate\b", r"\bhindi makagraduate\b",
    r"\bdi makapagtapos\b", r"\bhindi makapagtapos\b",
    r"\bscholarship\b.{0,20}\b(mawawala|nawala|bawi|binawi|kinuha)\b",
    r"\bpressure\b.{0,20}\b(pag-aaral|eskwela|grado|grades|thesis)\b",
    r"\bsobrang pressure\b.{0,20}\b(school|pag-aaral|thesis|defense)\b",
    r"\bsobrang stress\b.{0,20}\b(school|pag-aaral|thesis|grades)\b",
    r"\bdi makatulog\b.{0,20}\b(dahil sa|school|thesis|deadlines|assignment)\b",
    r"\bpinagalitan\b.{0,20}\b(guro|teacher|prof|magulang|parents)\b"
    r".{0,20}\b(grades|grado|school|pag-aaral)\b",
    r"\bpinagbabantaan\b.{0,20}\b(guro|prof|school)\b",
    r"\bwala nang pag-asa\b.{0,20}\b(eskwela|grado|pag-aaral|thesis)\b",
    r"\bnakakahiya\b.{0,20}\b(grades|grado|bagsak|failed)\b",
    r"\bdisappoint\b.{0,20}\b(magulang|parents|pamilya|tatay|nanay)\b",
    r"\bplate\b.{0,20}\b(stress|marami|dami|too many|hindi matapos)\b",
    r"\brecit\b.{0,20}\b(stress|hindi alam|failed|bagsak)\b",
    r"\bbagsak\b.{0,20}\b(subject|klase|eskwela|board)\b",
    r"\bdi makatungha\b",
    r"\bpressure\b.{0,20}\b(eskwela|pag-eskwela|grado|thesis)\b",
    r"\bwala koy\b.{0,20}\b(grade|grado|pasa|mahatag sa parents)\b",
    r"\bdi ko maka\b.{0,20}\b(graduate|finish|tapos)\b",
    r"\bbassit\b.{0,20}\b(grade|grado)\b",
    r"\bnabayag\b.{0,20}\b(eskwela|pag-aaral)\b",
    r"\b(?:tapusin|matapos)\s+(?:lahat\s+ng\s+)?(?:mga\s+)?requirements\b",
    r"\b(?:requirements|modules|deadlines)\b.{0,25}\b(?:pagod|drained|kulang|hindi\s+kaya|di\s+kaya|overwhelm(?:ed|ing)?|stressed)\b",
    r"\b(?:pagod|drained|overwhelm(?:ed|ing)?|stressed)\b.{0,35}\b(?:requirements|modules|deadlines)\b",
]

### SCHOOL AVOIDANCE & SOMATIC DISTRESS (v7.6)
# Truancy, school phobia, somatic anxiety symptoms, dropout risk
SCHOOL_AVOIDANCE_SOMATIC = [
    r"\bnot want to go to class\b", r"\bdon'?t want to go to class\b",
    r"\bnot want to go to school\b", r"\bdon'?t want to go to school\b",
    r"\bnever want to go back\b", r"\bnever going back\b",
    r"\bnot going back to class\b", r"\bnot going back to school\b",
    r"\bnot going tomorrow\b", r"\bor ever again\b",
    r"\bsick to my stomach\b", r"\bstomach hurts thinking about\b",
    r"\bnauseous\b.{0,20}\b(class|school|face them|people)\b",
    r"\bhave to face them\b", r"\bcan'?t face them\b", r"\bafraid to face them\b",
    r"\btakot pumasok\b", r"\bayaw pumasok\b", r"\bayoko na pumasok\b",
    r"\bdi na ako papasok\b", r"\bhindi na ako papasok\b",
    r"\bayoko nang pumasok\b", r"\bayaw ko nang pumasok\b",
    r"\btakot pumunta ng school\b", r"\bayoko na pumunta sa school\b",
    r"\bnasasuka ako\b.{0,20}\b(kaba|takot|school|klase|kanila)\b",
    r"\bnagsusuka sa kaba\b", r"\bpinagpapawisan sa kaba\b",
    r"\bnanginginig kapag naiisip\b", r"\bnanginginig pumasok\b",
    r"\bdi ko kayang pumasok\b", r"\bhindi ko kayang pumasok\b",
    r"\bgusto ko na mag-drop\b", r"\bmag-drop out\b", r"\bmagdadrop out\b",
    r"\bmag quit sa school\b", r"\btitigil na sa pag-aaral\b",
]

### NEW: SELF-WORTH ISSUES 
# Risk: alone → MEDIUM (0.58+); + any other signal → HIGH (0.75+)
# Maps to pattern #3: "feel ko ang useless ko... parang extra lang ako sa lahat"
SELF_WORTH = [
    # English
    r"\bi feel useless\b", r"\bi am useless\b", r"\bi'?m useless\b",
    r"\bi feel worthless\b", r"\bi am worthless\b", r"\bi'?m worthless\b",
    r"\bi have no value\b", r"\bi don'?t matter\b", r"\bi don'?t deserve\b",
    r"\bi'?m a burden\b", r"\bi'?m just a burden\b", r"\bi'?m nothing\b",
    r"\bi'?m not enough\b", r"\bi'?ll never be enough\b",
    r"\bnobody needs me\b", r"\bno one needs me\b",
    r"\bi'?m replaceable\b", r"\banyone could replace me\b",
    r"\bwhat's the point of me\b", r"\bwhy do i even exist\b",
    # Tagalog — specific to pattern #3
    r"\bang useless ko\b",
    r"\bparang extra lang ako\b",
    r"\bextra lang ako\b.{0,20}\b(sa lahat|sa grupo|sa kanila|sa mundo)\b",
    r"\bwala namang value\b.{0,20}\b(ako|ginagawa ko|pagkatao ko)\b",
    r"\bfeel ko.{0,20}useless\b",
    r"\buseless ako\b",
    r"\bwala akong value\b",
    r"\bparang walang silbi ako\b",
    r"\bwalang silbi ang pagkatao ko\b",
    r"\bparang hindi na ko kailangan\b",
    r"\bdi ko alam kung para saan pa ako\b",
    r"\bkahit anong gawin ko\b.{0,30}\bwala naman\b",
    r"\bkahit ano\b.{0,20}\bwala rin naman\b.{0,20}\b(epekto|mangyayari|value)\b",
    r"\bparang wala akong silbi\b",
    r"\bparang hindi ko kailangan\b",
    r"\blahat naman okay na kahit wala ako\b",
    r"\bwala naman nagbabago kahit nandito ako\b",
    r"\bparang wala akong nagagawa\b.{0,20}\btama\b",
    r"\bkahit anong subukan ko\b.{0,20}\bpalpak\b",
    r"\bhindi ako kailangan ng sinuman\b",
    r"\bpabigat\s+(?:na\s+)?lang(?:\s+naman)?\s+ako\b",
    r"\bwala\s+(?:na\s+)?akong\s+halaga\b",
    r"\bpalo lang ako\b",
    # Bisaya
    r"\bway pulos ako\b",
    r"\bwala koy pulos\b",
    r"\bwalay silbi ako\b",
    r"\bdi ako importante\b",
    r"\bextra lang ko\b",
    # Ilocano
    r"\bawan serserbik\b",
    r"\bawan pulos ko\b",
    r"\bawan inka serserbi\b",
    r"\bkahit\s+anong\s+effort\b.{0,30}\b(?:kulang|walang\s+(?:kwenta|silbi)|hindi\s+sapat|di\s+sapat)\b",
    r"\b(?:kulang\s+pa\s+rin|hindi\s+pa\s+rin\s+sapat|di\s+pa\s+rin\s+sapat)\b",
]

### NEW: MASKED DEPRESSION 
# Risk: alone → MEDIUM-HIGH (0.65+); signals a student who is suffering
# privately while appearing normal, which is a clinical red flag.
# Maps to pattern #7: "di naman halata kasi normal naman ako kausap
#                      pero pag mag-isa na, iba na"
MASKED_DEPRESSION = [
    r"\bi(?:['’]m| am)\s+(?:just\s+)?pretending\s+to\s+keep\s+up\b",
    # English
    r"\bi seem fine\b.{0,30}\b(but|pero|except)\b",
    r"\blook okay\b.{0,30}\b(but|pero|outside|sa labas)\b",
    r"\bsmiling but\b", r"\blaughing but\b.{0,20}\b(inside|sa loob|hurt)\b",
    r"\bnobody knows\b.{0,30}\b(struggling|hurting|sad|depressed)\b",
    r"\bno one knows\b.{0,30}\b(struggling|hurting|sad|depressed)\b",
    r"\bhiding\b.{0,20}\b(pain|sadness|depression|feelings|how i feel)\b",
    r"\bpretending\b.{0,20}\b(okay|fine|happy|normal)\b",
    r"\bwhen i'?m alone\b.{0,20}\b(different|it'?s different|iba)\b",
    r"\bwhen no one\b.{0,20}\b(sees|watches|looks|is around)\b",
    r"\bin public\b.{0,20}\b(okay|fine|normal|smile)\b.{0,20}\b(but|pero)\b",
    r"\baround people\b.{0,20}\b(okay|fine|normal)\b.{0,20}\b(but|pero|alone)\b",
    r"\bmasking\b.{0,20}\b(depression|pain|sadness|feelings)\b",
    r"\bhigh.?functioning\b.{0,20}\b(depression|anxiety)\b",
    # Tagalog — core patterns from pattern #7 & conversational Taglish
    r"\bdi naman halata\b",
    r"\bnormal naman ako\b.{0,20}\b(kausap|sa labas|sa harap)\b",
    r"\bpag mag.?isa na\b.{0,20}\biba na\b",
    r"\bpag nag.?iisa na\b.{0,20}\biba na\b",
    r"\bpag mag.?isa\b.{0,20}\biba\b",
    r"\bokay naman sa labas\b",
    r"\bokay naman kapag may kasama\b",
    r"\bnakangiti pero\b.{0,30}\b(sa loob|inside|hurt|malungkot|wala)\b",
    r"\bsa loob\b.{0,20}\biba\b.{0,20}\bkumpara\b",
    r"\bpag kasama sila\b.{0,20}\bokay\b.{0,20}\bpag mag.?isa\b.{0,20}\biba\b",
    r"\bnormal ang dating\b.{0,20}\bpero sa totoo\b",
    r"\bkakausapin mo ako\b.{0,20}\bokay\b.{0,20}\bpero\b",
    r"\bpag nandoon sila\b.{0,20}\bokay\b.{0,20}\bpag wala\b.{0,20}\biba\b",
    r"\bhindi halata\b.{0,20}\b(sa labas|sa harap|sa kanila)\b",
    r"\bpanget sa loob\b", r"\bhindi maganda sa loob\b",
    r"\bkahit nakangiti\b.{0,20}\bmalungkot\b",
    r"\btinatago\b.{0,20}\b(lungkot|sakit|nararamdaman|feelings)\b",
    r"\bpinipigilan\b.{0,20}\b(iyak|luha|lungkot)\b.{0,20}\b(harap|kasama)\b",
    r"\blagi mong sinasabi na\b.{0,20}\bokay lang\b",
    r"\bhindi mo na alam kung okay ka pa\b",
    r"\bdi mo na alam kung okay ka pa\b",
    r"\bnakikisabay sa jokes\b",
    r"\btumatawa pa nga minsan\b",
    r"\btumatawa pa\b.{0,30}\bpag.?uwi\b",
    r"\bdoon ko mararamdaman\b.{0,20}\b(kapagod|pagod|lungkot|bigat)\b",
    r"\bkahit gusto kong mapansin\b",
    r"\bhindi ko kayang sabihin directly\b",
    r"\bdi ko kayang sabihin directly\b",
    r"\bsanay na lang akong dalhin mag.?isa\b",
    r"\bdalhin mag.?isa\b",
    r"\btahimik na lang\b",
    r"\bmakaramdam ulit ng kahit ano\b",
    r"\bmakaramdam ng kahit ano\b",
    r"\bgusto ko lang talagang makaramdam\b",
    r"\bmakaramdam ulit\b",
    # Bisaya
    r"\bwala mahibawo\b.{0,20}\b(sakit|gibati|naa ko)\b",
    r"\bnormal lang gawas\b", r"\bnaa koy gisagubat\b.{0,20}\bsolo\b",
    r"\bpag nag.?inusara\b.{0,20}\biba na\b",
    r"\bokay rako sa atubangan\b",
    # Ilocano
    r"\bdi mabalin maamuan\b",
    r"\bokay sa ruar\b.{0,20}\bsaan sa uneg\b",
    # Masked tiredness & forced smiling
    r"\b(?:hirap|pagod(?:\s+na\s+pagod)?)\s+(?:na\s+)?(?:ako|akong|ko)\s+magpanggap\b",
    r"\b(?:pilit|pinipilit)\s+kong\s+ngumiti\b",
]

### NEW: RELATIONSHIP DISTRESS 
# Risk: alone → MEDIUM (0.55); + emotional/isolation/hopeless → HIGH (0.78+)
# Breakups are a top trigger for student suicidal ideation. Even casual
# breakup venting must be tracked because it can escalate.
RELATIONSHIP_DISTRESS = [
    # English — breakup / heartbreak / rejection
    r"\bbreak.?up\b", r"\bbroke up\b", r"\bbroken up\b",
    r"\bbreakup\b", r"\bheartbreak\b", r"\bheartbroken\b",
    r"\bhe left me\b", r"\bshe left me\b", r"\bthey left me\b",
    r"\bleft me for\b", r"\bcheated on me\b", r"\bcheating on me\b",
    r"\bgot cheated\b", r"\bex.?boyfriend\b", r"\bex.?girlfriend\b",
    r"\bmy ex\b", r"\brejected me\b", r"\bgot rejected\b",
    r"\bdumped me\b", r"\bgot dumped\b", r"\bhe dumped\b", r"\bshe dumped\b",
    r"\bunloved\b", r"\bnot loved\b", r"\bno one loves me\b",
    r"\bnobody loves me\b", r"\bnot lovable\b",
    r"\bfalling out of love\b", r"\bfell out of love\b",
    r"\bmoving on\b.{0,20}\b(hard|can'?t|impossible|hurts)\b",
    r"\bcan'?t move on\b", r"\bcan'?t get over\b",
    r"\bstill in love\b.{0,20}\b(ex|him|her|them)\b",
    r"\bhe doesn'?t love\b", r"\bshe doesn'?t love\b",
    r"\bused me\b", r"\bplayed me\b", r"\bwas just using me\b",
    r"\btrust issues\b", r"\bbetrayed\b", r"\bbetrayal\b",
    r"\brelationship\b.{0,20}\b(over|ended|done|toxic|abusive|ruined)\b",
    r"\bwhy wasn'?t i enough\b", r"\bwasn'?t enough for\b",
    # Nuanced Relationship Fading / Burnout
    r"\bnot really connecting\b", r"\bstopped choosing each other\b",
    r"\breason to walk away\b", r"\bquiet shift\b",
    r"\bcounting how long it takes you to respond\b",
    r"\bslowly fading\b", r"\bfit into your day\b",
    r"\bwondering if i'?m holdin\b", r"\bslowly becoming someone\b",
    # Tagalog — breakup / sakit ng puso
    r"\bnakipaghiwalay\b", r"\bhiniwalayan\b", r"\bnakipag.?break\b",
    r"\bnag.?break kami\b", r"\bnag.?break na kami\b",
    r"\biniwan niya ako\b", r"\biniwan ako\b", r"\biniwan na ako\b",
    r"\bbinasag puso ko\b", r"\bwasak puso ko\b",
    r"\bsakit ng puso\b", r"\bmasakit sa puso\b",
    r"\bniloko niya ako\b", r"\bniloko ako\b", r"\bnanloko\b",
    r"\bnag.?cheat\b", r"\bchineat\b", r"\bchineat ako\b",
    r"\bmay iba\b.{0,20}\b(siya|sya|xa)\b",
    r"\bmay iba na siya\b", r"\bmay bago na siya\b",
    r"\bdi na niya ko mahal\b", r"\bdi na niya ako mahal\b",
    r"\bhindi na niya ko mahal\b", r"\bhindi na niya ako mahal\b",
    r"\bdi na ko mahal\b", r"\bwala na siyang paki\b",
    r"\bginamit lang ako\b", r"\bpinaglaruan\b", r"\blaro lang\b",
    r"\bpinalitan\b", r"\bpinagpalit\b", r"\bpinagpalit ako\b",
    r"\bhindi ako sapat\b", r"\bdi ako sapat\b",
    r"\bbakit di ako sapat\b", r"\bkulang pa ba ako\b",
    r"\bdi na ko makamove on\b", r"\bhindi makapag move on\b",
    r"\bmahal ko pa siya\b.{0,20}\b(pero|pero wala na|pero tapos na)\b",
    r"\bmasakit\b.{0,20}\b(iniwan|niloko|pinagpalit|break)\b",
    r"\bjowa\b.{0,20}\b(iniwan|nanloko|nakipag.?break|may iba)\b",
    r"\bex ko\b.{0,20}\b(masakit|iniwan|niloko|hindi na mahal)\b",
    r"\bsakit\b.{0,20}\b(mag.?mahal|umibig|magmahal)\b",
    r"\bhindi na magkakasama\b", r"\btapos na\b.{0,20}\b(kami|relasyon|lahat)\b",
    r"\bghinated\b", r"\bghinost\b", r"\bin.?ghost\b",
    r"\bnag.?ghost\b", r"\bghinost ako\b",
    # Bisaya — breakup / bulag
    r"\bnagbulag\b", r"\bnag.?break mi\b", r"\bnag.?break na mi\b",
    r"\bgibiyaan ko\b", r"\bgibiyaan niya ko\b",
    r"\bnanloko siya\b", r"\bnag.?cheat siya\b",
    r"\bsakit sa dughan\b", r"\bwasak ang akong dughan\b",
    r"\bdi nako mahimo mag.?move on\b",
    # Ilocano
    r"\bpinanawan nak\b", r"\bnagbulag kami\b",
    r"\bsakit ti pusok\b", r"\bsaan na nak ay-ayaten\b",
]


# MAIN ANALYSIS ENDPOINT

@app.get("/")
async def root_health_check():
    return {
        "status": "online",
        "service": "StudentShield AI Engine",
        "version": "7.3.0",
        "translation_policy": "english-skip-cache-retry-v2",
        "message": "Send POST requests to /analyze-text to classify vents.",
    }

_CRYING_ONLY_PATTERNS = {
    r"\bnaliyak\b", r"\bnaiyak\b", r"\bnaiiyak\b", r"\bumiyak\b", r"\bumiiyak\b", r"\biyak\b",
    r"\bcrying\b", r"\bcried\b", r"\bsobbing\b", r"\btears\b",
}
_JOY_CRIER_RE = re.compile(
    r"\b(sa tuwa|tears of joy|happy tears|sobrang saya|napakasaya|nakaka-?proud|"
    r"pure relief|from (?:pure )?relief|tears of relief|screaming with joy|"
    r"crying (?:with|from) joy|greatest miracle)\b",
    re.I,
)
_ACADEMIC_DESTRUCT_WORDS = (
    "throw", "anger", "bwisit", "galit", "punch", "failed the whole class",
    "never go to class", "drop out", "bagsak", "stop going to class", "mad",
)
_HEURISTIC_RULES = (
    ("dist", 0.10, "Cognitive distortion detected.", False),
    ("hope", 0.15, "Hopelessness detected.", False),
    ("ext", 0.10, "High emotional intensity.", True),
    ("emotional", 0.12, "Emotional distress keywords present.", False),
    ("isolation", 0.12, "Social isolation/withdrawal detected.", False),
    ("school_avoid", 0.18, "School avoidance / somatic distress detected.", False),
    ("self_worth", 0.12, "Self-worth impairment detected.", False),
    ("masked_dep", 0.14, "Masked depression pattern: hides feelings when around others.", False),
    ("relationship", 0.10, "Relationship distress (breakup/rejection/betrayal) detected.", False),
)
_COMPOUND_FLAG_KEYS = (
    "emotional", "isolation", "anhedonia", "hope", "dist",
    "self_worth", "masked_dep", "relationship", "school_avoid",
)
_RISK_RANGES = {
    "LOW": (1, 30),
    "MEDIUM": (31, 60),
    "HIGH": (61, 85),
    "CRITICAL": (86, 100),
}


def _get_compiled_regexes() -> dict:
    """Compile and cache all clinical lexicon regexes on first request."""
    global COMPILED_REGEXES
    if not COMPILED_REGEXES:
        pattern_groups = {
            "cat1": CAT1_DIRECT,
            "passive_ideation": PASSIVE_DISAPPEARANCE_IDEATION,
            "cat2": CAT2_WORDS,
            "cat3": CAT3_WORDS,
            "emotional": EMOTIONAL_DISTRESS,
            "isolation": SOCIAL_ISOLATION,
            "joke": JOKE_PATTERNS,
            "dist": DISTORTIONS,
            "hope": HOPELESSNESS,
            "ext": EXTREME_VENTING,
            "final": FINALITY,
            "anhedonia": ANHEDONIA,
            "sexual": SEXUAL_HARASSMENT,
            "academic": ACADEMIC_PRESSURE,
            "school_avoid": SCHOOL_AVOIDANCE_SOMATIC,
            "self_worth": SELF_WORTH,
            "masked_dep": MASKED_DEPRESSION,
            "relationship": RELATIONSHIP_DISTRESS,
            "positive": POSITIVE_SENTIMENT,
        }
        for key, patterns in pattern_groups.items():
            COMPILED_REGEXES[key] = compile_regexes(patterns)
        COMPILED_REGEXES["method_pairs"] = [
            (re.compile(m, re.IGNORECASE), re.compile(s, re.IGNORECASE))
            for m, s in CAT1_METHOD_SELF_PAIRS
        ]
    return COMPILED_REGEXES


def _extract_signal_flags(text: str, scan_text: str, compiled: dict) -> tuple[dict[str, bool], object]:
    """Evaluate all safety, distress, joke, and positive-sentiment flags on the text."""
    flags = {
        key: any(pat.search(scan_text) for pat in compiled[key])
        for key in (
            "joke", "passive_ideation", "cat2", "cat3", "emotional", "isolation",
            "dist", "hope", "ext", "final", "anhedonia", "sexual", "academic",
            "school_avoid", "self_worth", "masked_dep", "relationship", "positive",
        )
    }
    has_cat1_direct = any(pat.search(scan_text) for pat in compiled["cat1"])
    has_cat1_method = any(
        method_pat.search(scan_text) and self_pat.search(scan_text)
        for method_pat, self_pat in compiled["method_pairs"]
    )
    flags["cat1"] = has_cat1_direct or has_cat1_method
    violent_threat = detect_violent_threat(text)

    # Suppress standalone crying keywords when context is explicitly tears of joy/pride.
    if flags["emotional"] and not any(
        flags[k] for k in (
            "cat1", "passive_ideation", "hope", "isolation",
            "anhedonia", "self_worth", "masked_dep", "dist",
        )
    ):
        matched_emo_pats = [pat.pattern for pat in compiled["emotional"] if pat.search(scan_text)]
        if matched_emo_pats and all(p in _CRYING_ONLY_PATTERNS for p in matched_emo_pats):
            if _JOY_CRIER_RE.search(scan_text):
                flags["emotional"] = False

    return flags, violent_threat


def _apply_psychological_heuristics(risk_score: float, flags: dict[str, bool]) -> tuple[float, list[str], int]:
    """Adjust base ML score using Stage 6 psychological heuristics and compound signals."""
    expert_reasoning = []
    for key, delta, message, requires_elevated in _HEURISTIC_RULES:
        if flags[key] and (not requires_elevated or risk_score > 0.4):
            risk_score += delta
            expert_reasoning.append(message)

    signal_count = sum(flags[k] for k in _COMPOUND_FLAG_KEYS)
    if signal_count >= 2:
        risk_score += 0.15 * (signal_count - 1)
        expert_reasoning.append(f"Compound signal: {signal_count} concurrent distress indicators.")

    return max(min(risk_score, 1.0), 0.0), expert_reasoning, signal_count


def _append_reasoning(reason: str, expert_reasoning: list[str]) -> str:
    return f"{reason} {' '.join(expert_reasoning)}" if expert_reasoning else reason


def _apply_rule_engine(
    risk_score: float,
    distress_conf: float,
    neg_emo_conf: float,
    pos_emo_conf: float,
    scan_text: str,
    flags: dict[str, bool],
    violent_threat,
    expert_reasoning: list[str],
    signal_count: int,
) -> tuple[str, float, str]:
    """Run Stage 7 priority rules and Stage 7.5 positive vent guard."""
    risk = "LOW"
    reason = "NLP: No severe negativity detected."

    if risk_score > 0.4 and (neg_emo_conf >= 0.15 or expert_reasoning):
        risk = "MEDIUM"
        reason = "NLP: Negative sentiment detected."

    is_joke = flags["joke"]

    if violent_threat:
        risk = "CRITICAL"
        risk_score = max(0.99, risk_score)
        reason = violent_threat["flag_reason"]
    elif flags["cat1"]:
        risk = "CRITICAL"
        risk_score = max(0.95, risk_score)
        reason = (
            "CRITICAL: Masked Depression — sarcasm disguising explicit suicidal intent."
            if is_joke
            else "CRITICAL: Explicit self-harm or suicidal intent detected."
        )
    elif flags["passive_ideation"]:
        risk = "HIGH"
        risk_score = max(0.79, risk_score)
        reason = _append_reasoning(
            "HIGH: Passive disappearance ideation and social invisibility detected (C-SSRS Level 2).",
            expert_reasoning,
        )
    elif flags["final"]:
        risk = "HIGH"
        risk_score = max(0.85 if is_joke else 0.80, risk_score)
        base = (
            "HIGH: Masked Depression — sarcasm disguising preparation signals."
            if is_joke
            else "HIGH: Subtle preparation hints (closure, peace, organizing) detected."
        )
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["cat2"]:
        if is_joke:
            risk = "MEDIUM"
            risk_score = max(0.5, risk_score)
            base = "Bullying keywords found but context indicates sarcasm."
        else:
            risk = "HIGH"
            risk_score = max(0.80, risk_score)
            base = "HIGH: Physical bullying or threat detected."
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["sexual"]:
        risk = "CRITICAL"
        risk_score = max(0.95, risk_score)
        reason = _append_reasoning(
            "CRITICAL: Sexual harassment, assault, or inappropriate conduct detected.",
            expert_reasoning,
        )
    elif flags["emotional"] or flags["anhedonia"] or flags["isolation"]:
        if is_joke:
            if signal_count >= 3:
                risk = "HIGH"
                risk_score = max(0.70, risk_score)
                base = (
                    f"HIGH: {signal_count} concurrent distress indicators detected "
                    f"through ironic/sarcastic expression — monitoring for escalation."
                )
            else:
                risk = "MEDIUM"
                risk_score = max(0.4, risk_score)
                base = "Distress/isolation keywords found but may be casual venting."
        elif signal_count >= 3 or (
            signal_count >= 2
            and (
                flags["hope"]
                or re.search(
                    r"\b(?:gusto\s+(?:ko\s+)?nang?\s+sumuko|suko\s+na\s+talaga|no\s+strength\s+left\s+to\s+(?:keep\s+)?fighting)\b",
                    scan_text,
                    re.I,
                )
            )
        ):
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            base = f"HIGH: Compound emotional signal — {signal_count} concurrent distress indicators detected."
        elif signal_count == 2:
            risk = "MEDIUM"
            risk_score = max(0.68, risk_score)
            base = "MEDIUM: Compound emotional signal (2 indicators)."
        else:
            risk = "MEDIUM"
            risk_score = max(0.60 if flags["anhedonia"] else 0.55, risk_score)
            kind = "Anhedonia/dissociation" if flags["anhedonia"] else "Emotional distress or social withdrawal"
            base = f"MEDIUM: {kind} detected."
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["self_worth"]:
        if is_joke:
            risk = "LOW"
            risk_score = max(0.35, risk_score)
            base = "Self-worth keywords found but context suggests casual expression."
        elif signal_count >= 3 or (signal_count >= 2 and flags["hope"]):
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            base = f"HIGH: Self-worth impairment compounded with {signal_count} concurrent distress signals."
        else:
            risk = "MEDIUM"
            risk_score = max(0.58, risk_score)
            base = "MEDIUM: Self-worth and self-value impairment detected."
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["masked_dep"]:
        if is_joke:
            risk = "LOW"
            risk_score = max(0.35, risk_score)
            base = "Masked depression language detected but tone appears casual."
        elif signal_count >= 3 or (signal_count >= 2 and flags["hope"]):
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            base = f"HIGH: Masked depression compounded with {signal_count} concurrent distress signals — hidden suffering pattern."
        else:
            risk = "MEDIUM"
            risk_score = max(0.60, risk_score)
            base = (
                "MEDIUM: Masked depression — student reports appearing normal to others "
                "but experiencing different internal emotional state when alone."
            )
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["cat3"]:
        if is_joke:
            risk = "LOW"
            base = "Verbal insults detected but contextualized as banter."
        elif flags["school_avoid"] or signal_count >= 2:
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            detail = "school avoidance and somatic distress" if flags["school_avoid"] else f"{signal_count} concurrent distress indicators"
            base = f"HIGH: Targeted bullying/cyberbullying compounded with {detail}."
        elif risk_score < 0.35:
            risk = "LOW"
            base = "NLP Context Override: Verbal keyword matched but ML model indicates safe usage."
        else:
            risk = "MEDIUM"
            risk_score = max(0.6, risk_score)
            base = "Verbal cyberbullying or toxicity detected."
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["relationship"]:
        if is_joke:
            risk = "LOW"
            risk_score = max(0.35, risk_score)
            base = "Relationship keywords found but context suggests casual mention."
        elif signal_count >= 3:
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            base = (
                f"HIGH: Relationship distress compounded with "
                f"{signal_count} concurrent emotional signals — elevated risk for crisis escalation."
            )
        else:
            risk = "MEDIUM"
            risk_score = max(0.55, risk_score)
            base = "MEDIUM: Relationship distress detected (breakup, rejection, betrayal). Monitoring for escalation."
        reason = _append_reasoning(base, expert_reasoning)
    elif flags["academic"] or flags["school_avoid"]:
        scan_lower = scan_text.lower()
        has_academic_destruct = any(w in scan_lower for w in _ACADEMIC_DESTRUCT_WORDS)
        if is_joke and not (flags["school_avoid"] or has_academic_destruct):
            risk = "LOW"
            base = "Academic frustration contextualized as hyperbolic venting."
        elif flags["school_avoid"] and any(flags[k] for k in ("cat3", "isolation", "emotional", "hope")):
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            base = "HIGH: School avoidance/truancy risk compounded with psychological distress/isolation."
        elif flags["school_avoid"] or has_academic_destruct:
            risk = "MEDIUM"
            risk_score = max(0.55, risk_score)
            base = "MEDIUM: Academic distress, school avoidance ideation, or elevated frustration detected."
        elif signal_count >= 2:
            risk = "HIGH"
            risk_score = max(0.75, risk_score)
            base = f"HIGH: Academic pressure/school distress compounded with {signal_count} concurrent emotional distress signals."
        else:
            risk = "MEDIUM"
            risk_score = max(0.50, risk_score)
            base = "MEDIUM: Academic pressure or school attendance distress detected."
        reason = _append_reasoning(base, expert_reasoning)
    else:
        if risk_score >= 0.70 or (distress_conf >= 0.85 and neg_emo_conf >= 0.80):
            risk = "HIGH"
            risk_score = max(0.75, risk_score)
            reason = _append_reasoning(
                "HIGH: Strong negative sentiment/distress from ML neural networks.",
                expert_reasoning,
            )
        elif risk_score >= 0.40 and (neg_emo_conf >= 0.15 or expert_reasoning or distress_conf >= 0.85):
            risk = "MEDIUM"
            reason = _append_reasoning("Moderate negative sentiment.", expert_reasoning)
        elif expert_reasoning:
            risk = "MEDIUM"
            risk_score = max(0.5, risk_score)
            reason = "Atypical sentiment pattern flagged by heuristics."
        elif risk_score > 0.35:
            # Prevent uncorroborated binary distress alone from forcing MEDIUM when emotion & rules are clean.
            risk_score = min(risk_score, 0.35)

    severe_flags = bool(
        violent_threat or flags["cat1"] or flags["cat2"] or flags["sexual"] or flags["final"]
    )
    distress_flags = any(
        flags[k] for k in (
            "emotional", "isolation", "anhedonia", "self_worth", "masked_dep",
            "cat3", "academic", "relationship", "school_avoid", "hope", "dist",
        )
    )
    is_positive_vent = bool(
        (flags["positive"] or pos_emo_conf >= 0.40)
        and not severe_flags
        and not distress_flags
        and neg_emo_conf < 0.45
    )
    if risk in ("LOW", "MEDIUM") and is_positive_vent:
        risk = "LOW"
        risk_score = min(risk_score, 0.28)
        reason = "NLP: Positive/grateful vent — no active distress indicators."

    return risk, risk_score, reason


def _apply_risk_history_escalation(
    student_id: str | None,
    risk_history: list[dict] | None,
    risk: str,
    risk_score: float,
    reason: str,
) -> tuple[str, float, str, str, float]:
    """Apply Stage 8 14-day durable risk history escalation."""
    trajectory = "INSUFFICIENT_DATA"
    escalation_prob = 0.0
    if not student_id:
        return risk, risk_score, reason, trajectory, escalation_prob

    b_escalate, trajectory, escalation_prob = evaluate_risk_history(risk_history or [], risk)
    if escalation_prob > 0:
        reason = f"[Predictive Recurrence Risk: {int(escalation_prob * 100)}%] " + reason

    if b_escalate:
        if risk == "HIGH" and trajectory == "ESCALATING":
            risk = "CRITICAL"
            risk_score = max(0.92, risk_score)
            reason = "CRITICAL ESCALATION: Repeated HIGH distress with escalating trajectory. " + reason
        elif risk == "MEDIUM" and trajectory == "ESCALATING":
            risk = "HIGH"
            risk_score = max(0.78, risk_score)
            reason = "HIGH ESCALATION: Persistent MEDIUM distress with repeated high-risk history. " + reason
    elif trajectory == "ESCALATING" and risk == "MEDIUM":
        risk = "HIGH"
        risk_score = max(0.75, risk_score)
        reason = "HIGH ESCALATION: Repeated worsening emotional distress over 14 days. " + reason

    return risk, risk_score, reason, trajectory, escalation_prob


def _band_risk_score(risk: str, risk_score: float) -> int:
    """Map internal 0..1 risk score into the display percentage band for the risk level."""
    lo, hi = _RISK_RANGES.get(risk, (1, 100))
    if risk == "LOW":
        scaled = max(0.0, min(1.0, risk_score / 0.40))
        return int(round(lo + scaled * (hi - lo)))
    raw_percent = max(1, min(100, int(round(risk_score * 99)) + 1))
    return int(round(max(lo, min(hi, lo + (raw_percent / 100.0) * (hi - lo)))))


def _translate_to_english(value: str) -> str:
    """Translate text to English via GoogleTranslator, falling back to MyMemory when rate-limited."""
    try:
        translated = GoogleTranslator(source="auto", target="en").translate(value)
        if isinstance(translated, str) and translated.strip():
            return translated
    except Exception:
        pass

    # MyMemory enforces a 500-char limit per request; chunk long vents by sentence/word.
    translator = MyMemoryTranslator(source="tl-PH", target="en-US")
    chunks: list[str] = []
    current = ""
    for sentence in nltk.sent_tokenize(value):
        if len(sentence) > 450:
            words = sentence.split()
            part = ""
            for word in words:
                candidate = f"{part} {word}".strip()
                if len(candidate) > 450 and part:
                    chunks.append(part)
                    part = word
                else:
                    part = candidate
            if part:
                if current:
                    chunks.append(current)
                    current = ""
                chunks.append(part)
        elif len(f"{current} {sentence}".strip()) > 450 and current:
            chunks.append(current)
            current = sentence
        else:
            current = f"{current} {sentence}".strip()
    if current:
        chunks.append(current)

    translated_parts: list[str] = []
    for chunk in chunks or [value[:450]]:
        part = translator.translate(chunk)
        if not isinstance(part, str) or not part.strip() or "mymemory warning" in part.lower():
            raise ValueError("Backup translation unavailable")
        translated_parts.append(part.strip())
    return " ".join(translated_parts)


def analyze_text_sync(text: str, student_id: str = None, risk_history: list[dict] | None = None) -> dict:
    """Run the multi-stage NLP classification, rule engine, and history escalation pipeline."""
    normalized_text = normalize_slang(text)
    translated_text = translation_gate.translate(normalized_text, _translate_to_english)

    scoped_translated = current_disclosure_text(translated_text)
    scoped_normalized = current_disclosure_text(normalized_text)
    pos_token = _request_pos_emo_var.set(None)
    try:
        distress_conf, neg_emo_conf = get_risk_components(scoped_translated, scoped_normalized)
        cached_pos_emo_conf = _request_pos_emo_var.get()
    finally:
        _request_pos_emo_var.reset(pos_token)
    pos_emo_conf = (
        cached_pos_emo_conf
        if cached_pos_emo_conf is not None
        else _get_positive_emotion_conf(scoped_translated)
    )
    base_score = (0.50 * distress_conf) + (0.50 * neg_emo_conf)

    compiled = _get_compiled_regexes()
    scan_text = current_disclosure_text(text) + " " + scoped_normalized
    flags, violent_threat = _extract_signal_flags(text, scan_text, compiled)

    risk_score, expert_reasoning, signal_count = _apply_psychological_heuristics(base_score, flags)
    risk, risk_score, reason = _apply_rule_engine(
        risk_score, distress_conf, neg_emo_conf, pos_emo_conf, scan_text,
        flags, violent_threat, expert_reasoning, signal_count,
    )
    risk, risk_score, reason, trajectory, escalation_prob = _apply_risk_history_escalation(
        student_id, risk_history, risk, risk_score, reason,
    )
    risk_percent = _band_risk_score(risk, risk_score)

    return {
        "risk_score": float(risk_percent),
        "risk_level": risk,
        "flag_reason": reason,
        "reasoning": reason,
        "trajectory": trajectory,
        "escalation_probability": round(float(escalation_prob), 2),
    }


@app.post("/analyze-text", response_model=MLResponse)
@limiter.limit("30/minute")
async def analyze_text(request: Request, payload: TextPayload, api_key: str = Depends(verify_api_key)):
    text       = payload.raw_text
    student_id = payload.student_id

    if not text.strip():
        raise HTTPException(status_code=400, detail="Text payload cannot be empty")

    # Security: Input length validation (prevent memory abuse)
    MAX_INPUT_CHARS = 5000
    if len(text) > MAX_INPUT_CHARS:
        raise HTTPException(status_code=400, detail=f"Input too long ({len(text)} chars). Max is {MAX_INPUT_CHARS}.")

    history = [
        item.model_dump() if hasattr(item, "model_dump") else item.dict()
        for item in payload.risk_history
    ]
    res = analyze_text_sync(text, student_id, history)
    return {
        "risk_score":             res["risk_score"],
        "risk_level":             res["risk_level"],
        "flag_reason":            res["flag_reason"],
        "trajectory":             res.get("trajectory", "INSUFFICIENT_DATA"),
        "escalation_probability": res.get("escalation_probability", 0.0),
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
