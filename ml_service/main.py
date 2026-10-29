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
import logging
import torch
import numpy as np
from transformers import pipeline
from deep_translator import GoogleTranslator
import nltk
from risk_history import evaluate_risk_history
from violent_threats import detect_violent_threat

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

def get_mdeberta_embedding(text: str) -> np.ndarray:
    try:
        inputs = distress_classifier.tokenizer(
            text, return_tensors="pt", truncation=True, max_length=512
        )
        with torch.no_grad():
            if hasattr(distress_classifier.model, "deberta"):
                outputs = distress_classifier.model.deberta(**inputs)
            elif hasattr(distress_classifier.model, "base_model"):
                outputs = distress_classifier.model.base_model(**inputs)
            else:
                outputs = distress_classifier.model(**inputs)
            
            last_hidden = outputs.last_hidden_state if hasattr(outputs, "last_hidden_state") else outputs[0]
            attention_mask = inputs["attention_mask"].unsqueeze(-1)
            sum_embeddings = torch.sum(last_hidden * attention_mask, dim=1)
            sum_mask = torch.clamp(attention_mask.sum(dim=1), min=1e-9)
            mean_pooled = (sum_embeddings / sum_mask).squeeze(0)
            return mean_pooled.cpu().numpy()
    except Exception as e:
        logger.error(f"Error extracting mDeBERTa embedding: {e}")
        return np.zeros(768, dtype=np.float32)

CRISIS_PHRASES = [
    "I want to kill myself", "I wish I could disappear", "I shouldn't exist",
    "maybe life would be easier without me", "there's no point in living",
    "I just want the pain to stop", "gusto ko nang mamahinga", "pagod na akong lumaban",
    "I don't want to be here anymore", "nobody would care if I was gone",
    "gusto ko maglaho na lang", "sana wag na ko magising bukas", "wala nang tutulong sakin",
    "mas okay na sana kung wala ako", "I already gave my things away",
    "everyone would be better without me", "I finished everything I needed to do",
    "take care of yourself", "I won't bother you anymore", "I'm just a problem for everyone",
    "di nako makaya ang kasakit", "mabannogakon ti panagbiag ko",
    # Added for the 7 new depression patterns
    "wala na akong gana sa lahat kahit mga bagay na gusto ko dati",
    "may magbabago ba kung wala ako",
    "para saan pa ako",
    "kung wala na ako siguro mas maayos pa",
    ### v7.1: Expanded Taglish/Gen-Z crisis anchors 
    "tapusin ko na buhay ko", "ayoko na sa mundong ito",
    "di ko na kaya ang lahat", "konti na lang susuko na ko",
    "sana kunin na ako", "hirap na hirap na ko mabuhay",
    "walang silbi ang buhay ko", "pabigat lang ako sa mundo",
    "nasa tipping point na ko", "di na worth it mabuhay",
    "gusto ko na lang mawala ng tuluyan", "di na ko lalaban pa",
    "bakit pa ako bumabangon", "wala namang maghahanap sakin",
    "mag-isa na lang ako lagi, walang may paki",
    "parang extra lang ako sa lahat ng tao",
    "normal naman ako sa harap nila pero sa loob namamatay na ko",
    "manhid na ko sa lahat, wala na kong nararamdaman",
    "ang hirap magpanggap na okay araw-araw",
    "paulit-ulit ko na lang iniisip kung may silbi pa ba ako",
    # Bisaya expanded
    "gilaay na jud ko sa tanan", "wala na koy gana mubuhi",
    "way pulos ang akong kinabuhi", "gusto na ko mahunong",
    # Ilocano expanded
    "awan serserbi ti biag ko", "kayat kon matayen",
    "narigat unay ti riknak saan ko kayat agbiag",
    ### v7.1: Breakup → crisis escalation anchors 
    "iniwan niya ako, wala na akong dahilan mabuhay",
    "pinagpalit niya ako, wala na akong silbi",
    "niloko niya ako, ayoko na sa lahat",
    "bakit di ako sapat, walang may gusto sakin",
    "break up na kami, wala nang point mag-continue",
    "iniwan na naman ako, palaging iniwan, pabigat lang talaga ako",
    "wasak puso ko, di ko na kayang magpatuloy",
    "nobody loves me, I just want to disappear",
    "she left me and I have nothing left",
    "he cheated on me, I feel so worthless and alone",
    ### v7.4: Reddit/PH research — real student passive crisis language
    "gaano katagal pa ba",                        # how much longer will this last
    "sana may makapansin",                         # I wish someone would notice
    "sana may makapansin sa akin",
    "parang hindi na ko buo",                      # I don't feel whole anymore
    "di na ko buo",
    "di ko alam kung gaano ko pa ito kakayanin",
    "di ko alam kung kailan matatapos",
    "pagod na akong magpanggap",
    "pagod na ako sa lahat",
    "tired of pretending to be okay",
    "I just want it to be over",
    "sana matapos na lahat",
    "gusto ko nang tumigil sa lahat",
    "wala na kong lakas lumaban",
    "kapag mag-isa ako naiiyak na lang ako",
    "pag mag-isa na ko iba na ang nararamdaman ko",
]

logger.info(f"Pre-computing embeddings for {len(CRISIS_PHRASES)} crisis phrases using mDeBERTa...")
CRISIS_EMBEDDINGS = np.vstack([get_mdeberta_embedding(p) for p in CRISIS_PHRASES])
_norms = np.linalg.norm(CRISIS_EMBEDDINGS, axis=1, keepdims=True)
_norms[_norms == 0] = 1e-9
CRISIS_EMBEDDINGS_NORM = CRISIS_EMBEDDINGS / _norms

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
    "my": "may",
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

logger.info(f"Encoded {len(CRISIS_PHRASES)} multilingual crisis anchor phrases.")

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

def get_semantic_similarity(text: str) -> float:
    try:
        sentences = nltk.sent_tokenize(text)
        if not sentences:
            return 0.0
            
        sent_embeds = np.vstack([get_mdeberta_embedding(s) for s in sentences])
        s_norms = np.linalg.norm(sent_embeds, axis=1, keepdims=True)
        s_norms[s_norms == 0] = 1e-9
        sent_embeds_norm = sent_embeds / s_norms

        similarities = np.dot(sent_embeds_norm, CRISIS_EMBEDDINGS_NORM.T)
        return float(np.max(similarities))
    except Exception as e:
        logger.error(f"Semantic similarity error: {e}")
        return 0.0

def get_risk_components(translated_text: str, normalized_text: str) -> tuple[float, float]:
    try:
        max_distress_conf = 0.0
        max_neg_emo_conf  = 0.0
        max_pos_emo_conf  = 0.0

        emotion_weights = {
            "sadness": 1.0, "fear": 0.9, "anger": 0.8, "grief": 1.0, "remorse": 0.8,
            "disgust": 0.6, "disappointment": 0.7, "nervousness": 0.7, "embarrassment": 0.55,
            "annoyance": 0.5, "disapproval": 0.45, "confusion": 0.2, "neutral": 0.0,
            "surprise": 0.0, "joy": 0.0, "admiration": 0.0, "amusement": 0.0,
            "approval": 0.0, "caring": 0.0, "desire": 0.0, "excitement": 0.0,
            "fascination": 0.0, "gratitude": 0.0, "love": 0.0, "optimism": 0.0,
            "pride": 0.0, "realization": 0.0, "relief": 0.0
        }
        positive_emotion_weights = {
            "gratitude": 0.85, "joy": 0.80, "optimism": 0.75, "love": 0.70,
            "excitement": 0.65, "amusement": 0.55, "admiration": 0.50, "pride": 0.45,
            "relief": 0.40, "caring": 0.35, "approval": 0.30,
        }

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
            emo_result     = emotion_classifier(chunk)[0]
            emo_label      = emo_result['label']
            emo_confidence = emo_result['score']

            neg_emo_weight = emotion_weights.get(emo_label, 0.0)
            neg_emo_conf   = neg_emo_weight * emo_confidence
            if neg_emo_conf > max_neg_emo_conf:
                max_neg_emo_conf = neg_emo_conf

            pos_emo_weight = positive_emotion_weights.get(emo_label, 0.0)
            pos_emo_conf   = pos_emo_weight * emo_confidence
            if pos_emo_conf > max_pos_emo_conf:
                max_pos_emo_conf = pos_emo_conf

        for chunk in norm_chunks:
            if not chunk.strip(): continue
            dist_result     = distress_classifier(chunk)[0]
            dist_label      = str(dist_result['label']).upper()
            dist_confidence = dist_result['score']

            is_distress = dist_label in [
                "1", "LABEL_1", "NEGATIVE", "TOXIC", "SADNESS", "GRIEF", "FEAR", "ANGER"
            ]
            if is_distress and dist_confidence > max_distress_conf:
                max_distress_conf = dist_confidence

        # Positive emotions (gratitude, joy, optimism) dampen false-negative scores,
        # BUT NOT when masked depression / smiling through pain OR sarcastic anger/frustration is present.
        scan_lower = normalized_text.lower() + " " + translated_text.lower()
        is_masked = any(p in scan_lower for p in [
            "jokes", "tumatawa", "nakangiti", "sa labas", "outside", "pretend", "nagpapanggap",
            "tahimik", "mag-isa", "pag-uwi", "alone", "dalhin mag-isa", "makaramdam",
            "anger", "throw", "galit", "bwisit", "failed", "bagsak", "mad", "never go to class",
            "hate", "punch", "drop out"
        ])

        if max_pos_emo_conf >= 0.45 and not is_masked:
            dampen = min(0.35, max_pos_emo_conf * 0.45)
            max_neg_emo_conf = max(0.0, max_neg_emo_conf - dampen)
            max_distress_conf = max(0.0, max_distress_conf - dampen * 0.5)

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
    r"\bsuicide\b", r"\bkill myself\b", r"\bend it all\b", r"\bcut myself\b",
    r"\bwant to die\b", r"\bno reason to live\b",
    r"\bmagpakamatay\b", r"\blaslas\b", r"\btatalon\b", r"\bbibigti\b", r"\bsugatan\b",
    r"\btatapusin ko na buhay ko\b", r"\btapusin ko na\b", r"\btapusin ko na lang\b",
    r"\btapusin ko na lahat\b", r"\btapusin ko na lang lahat\b", r"\btatapusin ko na\b",
    r"\bgusto ko na mawala\b", r"\bgusto ko nang mamatay\b", r"\bgusto nako mamatay\b",
    r"\bgusto kong mamatay\b", r"\bsana mamatay na (?:ako|ko|aq)?\b", r"\bmamatay na lang (?:ako|aq)?\b",
    r"\bpwede bang mamatay na\b", r"\bnaisip kong mamatay\b",
    r"\bmagbibigti\b", r"\bsana kunin na ko ni lord\b",
    r"\bunalive myself\b", r"\bbetter off dead\b", r"\bsana matulog at di na magising\b",
    r"\bsana di na lang ako pinanganak\b", r"\bwala nang pag-asa\b",
    r"\bdi ko na alam gagawin\b", r"\blulunukin ko na lang\b",
    r"i\s*wanna\s*die", r"i\s*want\s*to\s*die", r"kill\s*myself",
    r"end\s+(my|this)\s+life", r"don't\s+want\s+to\s+exist",
    r"don'?t\s+want\s+to\s+live", r"can'?t\s+do\s+this\s+anymore",
    r"wish\s+i\s+was\s+never\s+born", r"wish\s+i\s+could\s+disappear",
    r"world\s+would\s+be\s+better\s+without\s+me", r"no\s+point\s+in\s+living",
    r"\bself.?harm\b", r"\bself.?destruct\b",
    r"i'?m\s+done\s+living", r"done\s+with\s+life", r"tired\s+of\s+living",
    r"hang\s+myself", r"hanging\s+myself",
    r"\bbigti\b", r"\bbitay\b", r"\bmagbigti\b", r"\bbibitay\b",
    r"\bpabitin\b", r"\bnakabitin\b",
    r"\btumalon\b", r"\blulundag\b",
    r"slit\s+my\s+wrist", r"stab\s+myself",
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
]

CAT1_METHOD_SELF_PAIRS = [
    (r"\brope\b",           r"\b(myself|hang|neck|bigti|bitay)\b"),
    (r"\bblades?\b",        r"\b(myself|cut|wrist|laslas|arms?)\b"),
    (r"\bpills?\b",         r"\b(myself|overdose|swallow|take|inumin)\b"),
    (r"\bpoison\b",         r"\b(myself|drink|take|inumin)\b"),
    (r"\boverdose\b",       r"\b(myself|pills?|gamot|take)\b"),
    (r"jump\s+(off|from)\b", r"\b(myself|building|tulay|bridge|roof)\b"),
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
    r"\bugly\b", r"\bfat\b", r"\bloser\b", r"\bslut\b", r"\bwhore\b", r"\bdumb\b",
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
    r"\bdepressed\b", r"\bdepression\b", r"can't take this anymore",
    r"\btired all the time\b", r"\bnothing feels right\b", r"\bgoing through the motions\b",
    r"\bno point in trying\b", r"\bhoping the days pass faster\b", r"\beverything feels off\b",
    r"\bfeeling numb\b", r"\bempty inside\b", r"\bgiven up\b", r"\bgiving up\b",
    r"\blost hope\b", r"\bdon't care anymore\b", r"\bpointless\b", r"\blosing my mind\b",
    r"\bcan't do this anymore\b", r"so mentally draining", r"literally mentally exhausted",
    r"\bi am sad\b", r"\bim sad\b", r"\bi'?m\s+so\s+sad\b", r"\bso\s+sad\b",
    r"\breally sad\b", r"\bsuper sad\b", r"\bfeeling sad\b", r"\bstill sad\b",
    r"\bcrying\b", r"\bcried\b", r"\bcan'?t stop crying\b", r"\bbeen crying\b",
    r"\btears\b", r"\bsobbing\b", r"\bbreaking down\b", r"\bbroke down\b",
    r"\bhurting\b", r"\bhurts so much\b", r"\bin pain\b", r"\bheartbroken\b",
    r"\bmiserable\b", r"\bstressed\b", r"\banxious\b", r"\bpanic\b", r"\bpanic attacks?\b",
    r"\boverwhelmed\b", r"\bdrained\b", r"\bexhausted\b", r"\bburned out\b",
    r"\bbreaking apart\b", r"\bfalling apart\b", r"\bcan'?t breathe\b", r"\bd nko mkahinga\b", r"\bdi na makahinga\b",
    r"\bpagod na ako\b", r"\bsobrang lungkot\b", r"\bsobrang bigat\b", r"\bkapagod\b",
    r"\bmamatay (?:sa |s |nang |ng )?(?:pagod|kaba|stress|overwork|puyat)\b",
    r"\bhirap na hirap\b", r"\bsawang sawa na\b", r"\bdi ko na kaya\b",
    r"\bayoko na\b", r"\bdurug na durog\b", r"\bnamamatay\b",
    r"\bwasak na wasak\b", r"\bsukong suko na\b", r"\bparang sasabog utak ko\b",
    r"\bwalang silbi\b", r"\bwala akong kwenta\b", r"\bpabigat ako\b",
    r"\bnaliyak\b", r"\bnaiyak\b", r"\bnaiiyak\b", r"\bumiyak\b", r"\biyak\b",
    r"iyak nang iyak", r"\bumiiyak s[a]? cr\b", r"\bnassad\b", r"\bnasad\b", r"\bmalungkot\b",
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
]

### SOCIAL ISOLATION / WITHDRAWAL 
SOCIAL_ISOLATION = [
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
    r"\bwala naman nakakapansin\b", r"\bparang invisible\b",
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
    r"\bpabigat lang ako\b",
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
]

### NEW: MASKED DEPRESSION 
# Risk: alone → MEDIUM-HIGH (0.65+); signals a student who is suffering
# privately while appearing normal, which is a clinical red flag.
# Maps to pattern #7: "di naman halata kasi normal naman ako kausap
#                      pero pag mag-isa na, iba na"
MASKED_DEPRESSION = [
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
        "api_docs": "/docs",
        "message": "Send POST requests to /analyze-text to classify vents.",
    }

def analyze_text_sync(text: str, student_id: str = None, risk_history: list[dict] | None = None) -> dict:
    ## ┐
    ### STAGE 0: SLANG NORMALIZATION & GLOBAL TRANSLATION      
    ## ┘
    normalized_text = normalize_slang(text)
    translated_text = normalized_text
    try:
        raw_translation = GoogleTranslator(source='auto', target='en').translate(normalized_text)
        if raw_translation and isinstance(raw_translation, str):
            clean_tr = raw_translation.strip()
            # Guard against Google Translate HTML 500 / scraper rate limit errors
            if (
                clean_tr
                and not clean_tr.startswith("Error 500")
                and "Server Error" not in clean_tr
                and "That’s an error" not in clean_tr
                and "That's an error" not in clean_tr
                and "<html" not in clean_tr.lower()
            ):
                translated_text = clean_tr
            else:
                logger.warning(f"GoogleTranslator returned error/HTML response; using normalized text instead.")
    except Exception as e:
        logger.warning(f"Translation failed, falling back to normalized: {e}")
        translated_text = normalized_text

    logger.info(f"Normalized/Translated: '{text[:80]}' → '{translated_text[:80]}'")

    ## ┐
    ### STAGE 1: SCORE COMPONENTS                              
    ## ┘
    distress_conf, neg_emo_conf = get_risk_components(translated_text, normalized_text)

    ## ┐
    ### STAGE 2: SCORE BALANCING                               
    ## ┘
    risk_score = (0.50 * distress_conf) + (0.50 * neg_emo_conf)

    ## ┐
    ### STAGE 3: REGEX COMPILE (once, then cached globally)    
    ## ┘
    global COMPILED_REGEXES
    if not COMPILED_REGEXES:
        COMPILED_REGEXES['cat1']        = compile_regexes(CAT1_DIRECT)
        COMPILED_REGEXES['passive_ideation'] = compile_regexes(PASSIVE_DISAPPEARANCE_IDEATION) # NEW
        COMPILED_REGEXES['cat2']        = compile_regexes(CAT2_WORDS)
        COMPILED_REGEXES['cat3']        = compile_regexes(CAT3_WORDS)
        COMPILED_REGEXES['emotional']   = compile_regexes(EMOTIONAL_DISTRESS)
        COMPILED_REGEXES['isolation']   = compile_regexes(SOCIAL_ISOLATION)
        COMPILED_REGEXES['joke']        = compile_regexes(JOKE_PATTERNS)
        COMPILED_REGEXES['dist']        = compile_regexes(DISTORTIONS)
        COMPILED_REGEXES['hope']        = compile_regexes(HOPELESSNESS)
        COMPILED_REGEXES['ext']         = compile_regexes(EXTREME_VENTING)
        COMPILED_REGEXES['final']       = compile_regexes(FINALITY)
        COMPILED_REGEXES['anhedonia']   = compile_regexes(ANHEDONIA)
        COMPILED_REGEXES['sexual']      = compile_regexes(SEXUAL_HARASSMENT)
        COMPILED_REGEXES['academic']    = compile_regexes(ACADEMIC_PRESSURE)
        COMPILED_REGEXES['school_avoid']= compile_regexes(SCHOOL_AVOIDANCE_SOMATIC) # NEW v7.6
        COMPILED_REGEXES['self_worth']  = compile_regexes(SELF_WORTH)       # NEW v7
        COMPILED_REGEXES['masked_dep']  = compile_regexes(MASKED_DEPRESSION) # NEW v7
        COMPILED_REGEXES['relationship']= compile_regexes(RELATIONSHIP_DISTRESS) # NEW v7.1
        COMPILED_REGEXES['positive']    = compile_regexes(POSITIVE_SENTIMENT)
        COMPILED_REGEXES['method_pairs'] = [
            (re.compile(m, re.IGNORECASE), re.compile(s, re.IGNORECASE))
            for m, s in CAT1_METHOD_SELF_PAIRS
        ]
    if 'positive' not in COMPILED_REGEXES:
        COMPILED_REGEXES['positive'] = compile_regexes(POSITIVE_SENTIMENT)
    if 'passive_ideation' not in COMPILED_REGEXES:
        COMPILED_REGEXES['passive_ideation'] = compile_regexes(PASSIVE_DISAPPEARANCE_IDEATION)

    ## ┐
    ### STAGE 4: SARCASM / JOKE DETECTION (v7)                 
    ## ┘
    scan_text = text + " " + normalized_text
    is_joke = any(pat.search(scan_text) for pat in COMPILED_REGEXES['joke'])

    ## ┐
    ### STAGE 5: KEYWORD MATCH FLAGS                           
    ## ┘
    has_cat1_direct = any(pat.search(scan_text) for pat in COMPILED_REGEXES['cat1'])

    has_cat1_method = False
    for method_pat, self_pat in COMPILED_REGEXES['method_pairs']:
        if method_pat.search(scan_text) and self_pat.search(scan_text):
            has_cat1_method = True
            break

    has_cat1       = has_cat1_direct or has_cat1_method
    violent_threat = detect_violent_threat(text)
    has_passive_ideation = any(pat.search(scan_text) for pat in COMPILED_REGEXES['passive_ideation'])
    has_cat2       = any(pat.search(scan_text) for pat in COMPILED_REGEXES['cat2'])
    has_cat3       = any(pat.search(scan_text) for pat in COMPILED_REGEXES['cat3'])
    has_emotional  = any(pat.search(scan_text) for pat in COMPILED_REGEXES['emotional'])
    has_isolation  = any(pat.search(scan_text) for pat in COMPILED_REGEXES['isolation'])
    has_distortion = any(pat.search(scan_text) for pat in COMPILED_REGEXES['dist'])
    has_hopeless   = any(pat.search(scan_text) for pat in COMPILED_REGEXES['hope'])
    has_extreme    = any(pat.search(scan_text) for pat in COMPILED_REGEXES['ext'])
    has_finality   = any(pat.search(scan_text) for pat in COMPILED_REGEXES['final'])
    has_anhedonia  = any(pat.search(scan_text) for pat in COMPILED_REGEXES['anhedonia'])
    has_sexual     = any(pat.search(scan_text) for pat in COMPILED_REGEXES['sexual'])
    has_academic   = any(pat.search(scan_text) for pat in COMPILED_REGEXES['academic'])
    has_school_avoid = any(pat.search(scan_text) for pat in COMPILED_REGEXES['school_avoid']) # NEW v7.6
    has_self_worth    = any(pat.search(scan_text) for pat in COMPILED_REGEXES['self_worth'])  # NEW
    has_masked_dep   = any(pat.search(scan_text) for pat in COMPILED_REGEXES['masked_dep'])  # NEW
    has_relationship = any(pat.search(scan_text) for pat in COMPILED_REGEXES['relationship']) # NEW v7.1
    has_positive     = any(pat.search(scan_text) for pat in COMPILED_REGEXES['positive'])

    ## ┐
    ### STAGE 6: PSYCHOLOGICAL HEURISTICS                      
    ## ┘
    expert_reasoning = []

    if has_distortion:
        risk_score += 0.10
        expert_reasoning.append("Cognitive distortion detected.")
    if has_hopeless:
        risk_score += 0.15
        expert_reasoning.append("Hopelessness detected.")
    if has_extreme and risk_score > 0.4:
        risk_score += 0.10
        expert_reasoning.append("High emotional intensity.")
    if has_emotional:
        risk_score += 0.12
        expert_reasoning.append("Emotional distress keywords present.")
    if has_isolation:
        risk_score += 0.12
        expert_reasoning.append("Social isolation/withdrawal detected.")
    if has_school_avoid:
        risk_score += 0.18
        expert_reasoning.append("School avoidance / somatic distress detected.")
    if has_self_worth:
        risk_score += 0.12
        expert_reasoning.append("Self-worth impairment detected.")
    if has_masked_dep:
        risk_score += 0.14
        expert_reasoning.append("Masked depression pattern: hides feelings when around others.")
    if has_relationship:
        risk_score += 0.10
        expert_reasoning.append("Relationship distress (breakup/rejection/betrayal) detected.")

    # Compound signal detection — expanded to include school avoidance
    signal_count = sum([
        has_emotional, has_isolation, has_anhedonia,
        has_hopeless, has_distortion, has_self_worth, has_masked_dep,
        has_relationship, has_school_avoid,
    ])
    if signal_count >= 2:
        compound_boost = 0.15 * (signal_count - 1)
        risk_score += compound_boost
        expert_reasoning.append(
            f"Compound signal: {signal_count} concurrent distress indicators."
        )

    risk_score = max(min(risk_score, 1.0), 0.0)

    ## ┐
    ### STAGE 7: FINAL RULE ENGINE (Sequential Priority)       
    ### 
    ### Rule 1:   Explicit suicide (Cat1)                      
    ### Rule 2:   Positive sentiment override                  
    ### Rule 3:   Finality / preparation                       
    ### Rule 4:   Physical violence (Cat2)                     
    ### Rule 4.5: Sexual harassment                            
    ### Rule 5:   Emotional / Anhedonia / Isolation            
    ### Rule 5.5: Self-worth issues          ← NEW v7          
    ### Rule 5.7: Masked depression          ← NEW v7          
    ### Rule 6:   Verbal abuse (Cat3)                          
    ### Rule 6.3: Relationship distress     ← NEW v7.1         
    ### Rule 6.5: Academic pressure                            
    ### Rule 7:   Pure risk score                              
    ## ┘
    risk   = "LOW"
    reason = "NLP: No severe negativity detected."

    if risk_score > 0.4:
        risk   = "MEDIUM"
        reason = "NLP: Negative sentiment detected."

    ### RULE 1: Explicit threats to life (never downgraded by jokes or positivity)
    if violent_threat:
        risk = "CRITICAL"
        risk_score = max(0.99, risk_score)
        reason = violent_threat['flag_reason']
    elif has_cat1:
        risk       = "CRITICAL"
        risk_score = max(0.95, risk_score)
        if is_joke:
            reason = "CRITICAL: Masked Depression — sarcasm disguising explicit suicidal intent."
        else:
            reason = "CRITICAL: Explicit self-harm or suicidal intent detected."
    ### RULE 1.5: Passive disappearance & Invisibility ideation (C-SSRS Level 2)
    elif has_passive_ideation:
        risk       = "HIGH"
        risk_score = max(0.79, risk_score)
        reason     = "HIGH: Passive disappearance ideation and social invisibility detected (C-SSRS Level 2)."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)
    ### RULE 2: Finality / preparation signals 
    elif has_finality:
        if is_joke:
            risk       = "HIGH"
            risk_score = max(0.85, risk_score)
            reason     = "HIGH: Masked Depression — sarcasm disguising preparation signals."
        else:
            risk       = "HIGH"
            risk_score = max(0.80, risk_score)
            reason     = "HIGH: Subtle preparation hints (closure, peace, organizing) detected."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 4: Physical violence / bullying 
    elif has_cat2:
        if is_joke:
            risk       = "MEDIUM"
            risk_score = max(0.5, risk_score)
            reason     = "Bullying keywords found but context indicates sarcasm."
        else:
            risk       = "HIGH"
            risk_score = max(0.80, risk_score)
            reason     = "HIGH: Physical bullying or threat detected."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 4.5: Sexual harassment / assault 
    elif has_sexual:
        risk       = "CRITICAL"
        risk_score = max(0.95, risk_score)
        reason     = "CRITICAL: Sexual harassment, assault, or inappropriate conduct detected."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 5: Emotional distress, Anhedonia, or Isolation 
    elif has_emotional or has_anhedonia or has_isolation:
        if is_joke:
            if signal_count >= 3:
                # v7.2: Heavy compound distress through ironic tone is still
                # clinically significant — bitter sarcasm masking real pain.
                risk       = "HIGH"
                risk_score = max(0.70, risk_score)
                reason     = (
                    f"HIGH: {signal_count} concurrent distress indicators detected "
                    f"through ironic/sarcastic expression — monitoring for escalation."
                )
            else:
                risk       = "MEDIUM"
                risk_score = max(0.4, risk_score)
                reason     = "Distress/isolation keywords found but may be casual venting."
        elif signal_count >= 3:
            risk       = "HIGH"
            risk_score = max(0.78, risk_score)
            reason     = (
                f"HIGH: Compound emotional signal — "
                f"{signal_count} concurrent distress indicators detected."
            )
        elif signal_count == 2:
            risk       = "MEDIUM"
            risk_score = max(0.68, risk_score)
            reason     = "MEDIUM: Compound emotional signal (2 indicators)."
        else:
            risk       = "MEDIUM"
            risk_score = max(0.55 if not has_anhedonia else 0.60, risk_score)
            reason     = (
                f"MEDIUM: "
                f"{'Anhedonia/dissociation' if has_anhedonia else 'Emotional distress or social withdrawal'} detected."
            )
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 5.5: Self-worth issues (NEW v7) 
    elif has_self_worth:
        if is_joke:
            risk       = "LOW"
            risk_score = max(0.35, risk_score)
            reason     = "Self-worth keywords found but context suggests casual expression."
        elif signal_count >= 3:
            # Self-worth + multiple signals = HIGH (clinical concern)
            risk       = "HIGH"
            risk_score = max(0.78, risk_score)
            reason     = (
                f"HIGH: Self-worth impairment compounded with "
                f"{signal_count} concurrent distress signals."
            )
        else:
            risk       = "MEDIUM"
            risk_score = max(0.58, risk_score)
            reason     = "MEDIUM: Self-worth and self-value impairment detected."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 5.7: Masked depression (NEW v7) 
    elif has_masked_dep:
        # Masked depression is clinically significant on its own — a student
        # who says "I'm fine around others but different alone" is disclosing
        # a hidden struggle.
        if is_joke:
            risk       = "LOW"
            risk_score = max(0.35, risk_score)
            reason     = "Masked depression language detected but tone appears casual."
        elif signal_count >= 3:
            risk       = "HIGH"
            risk_score = max(0.78, risk_score)
            reason     = (
                f"HIGH: Masked depression compounded with "
                f"{signal_count} concurrent distress signals — hidden suffering pattern."
            )
        else:
            risk       = "MEDIUM"
            risk_score = max(0.60, risk_score)
            reason     = (
                "MEDIUM: Masked depression — student reports appearing normal to others "
                "but experiencing different internal emotional state when alone."
            )
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 6: Verbal abuse / cyberbullying 
    elif has_cat3:
        if is_joke:
            risk   = "LOW"
            reason = "Verbal insults detected but contextualized as banter."
        elif has_school_avoid or signal_count >= 2:
            risk       = "HIGH"
            risk_score = max(0.78, risk_score)
            reason     = (
                f"HIGH: Targeted bullying/cyberbullying compounded with "
                f"{'school avoidance and somatic distress' if has_school_avoid else f'{signal_count} concurrent distress indicators'}."
            )
        elif risk_score < 0.35:
            risk   = "LOW"
            reason = "NLP Context Override: Verbal keyword matched but ML model indicates safe usage."
        else:
            risk       = "MEDIUM"
            risk_score = max(0.6, risk_score)
            reason = "Verbal cyberbullying or toxicity detected."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 6.3: Relationship distress (NEW v7.1) 
    # Breakups are a leading trigger for student suicidal ideation.
    # Alone → MEDIUM; compounded with distress signals → HIGH.
    elif has_relationship:
        if is_joke:
            risk       = "LOW"
            risk_score = max(0.35, risk_score)
            reason     = "Relationship keywords found but context suggests casual mention."
        elif signal_count >= 3:
            # Breakup + multiple signals = HIGH
            risk       = "HIGH"
            risk_score = max(0.78, risk_score)
            reason     = (
                f"HIGH: Relationship distress compounded with "
                f"{signal_count} concurrent emotional signals — "
                f"elevated risk for crisis escalation."
            )
        else:
            risk       = "MEDIUM"
            risk_score = max(0.55, risk_score)
            reason     = (
                "MEDIUM: Relationship distress detected (breakup, rejection, betrayal). "
                "Monitoring for escalation."
            )
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 6.5: Academic pressure & School Avoidance
    elif has_academic or has_school_avoid:
        has_academic_destruct = any(w in scan_text.lower() for w in [
            "throw", "anger", "bwisit", "galit", "punch", "failed the whole class",
            "never go to class", "drop out", "bagsak", "stop going to class", "mad"
        ])
        if is_joke and not (has_school_avoid or has_academic_destruct):
            risk   = "LOW"
            reason = "Academic frustration contextualized as hyperbolic venting."
        elif has_school_avoid and (has_cat3 or has_isolation or has_emotional or has_hopeless):
            risk       = "HIGH"
            risk_score = max(0.78, risk_score)
            reason     = "HIGH: School avoidance/truancy risk compounded with psychological distress/isolation."
        elif has_school_avoid or has_academic_destruct:
            risk       = "MEDIUM"
            risk_score = max(0.55, risk_score)
            reason     = "MEDIUM: Academic distress, school avoidance ideation, or elevated frustration detected."
        elif signal_count >= 2:
            risk       = "HIGH"
            risk_score = max(0.75, risk_score)
            reason     = (
                f"HIGH: Academic pressure/school distress compounded with "
                f"{signal_count} concurrent emotional distress signals."
            )
        else:
            risk       = "MEDIUM"
            risk_score = max(0.50, risk_score)
            reason     = "MEDIUM: Academic pressure or school attendance distress detected."
        if expert_reasoning: reason += " " + " ".join(expert_reasoning)

    ### RULE 7: Pure risk score 
    else:
        if risk_score >= 0.70 or (distress_conf >= 0.85 and neg_emo_conf >= 0.80):
            risk       = "HIGH"
            risk_score = max(0.75, risk_score)
            reason     = "HIGH: Strong negative sentiment/distress from ML neural networks."
            if expert_reasoning: reason += " " + " ".join(expert_reasoning)
        elif risk_score >= 0.40:
            risk   = "MEDIUM"
            reason = "Moderate negative sentiment."
            if expert_reasoning: reason += " " + " ".join(expert_reasoning)
        elif expert_reasoning:
            risk       = "MEDIUM"
            risk_score = max(0.5, risk_score)
            reason     = "Atypical sentiment pattern flagged by heuristics."

    ## ┐
    ### STAGE 7.5: POSITIVE VENT GUARD (suppress false MEDIUM on grateful posts)
    ## ┘
    severe_flags = violent_threat or has_cat1 or has_cat2 or has_sexual or has_finality
    if has_positive and not severe_flags and not (has_emotional or has_isolation or has_anhedonia or has_self_worth or has_masked_dep):
        risk       = "LOW"
        risk_score = min(risk_score, 0.28)
        reason     = "NLP: Positive/grateful vent — no active distress indicators."

    ## ┐
    ### STAGE 8: DURABLE CONTEXTUAL RISK HISTORY              
    ## ┘
    # Defaults — overwritten below if student_id is present
    trajectory     = "INSUFFICIENT_DATA"
    escalation_prob = 0.0

    if student_id:
        b_escalate, trajectory, escalation_prob = evaluate_risk_history(
            risk_history or [], risk
        )
        if escalation_prob > 0:
            reason = f"[Predictive Recurrence Risk: {int(escalation_prob*100)}%] " + reason
        if b_escalate:
            if risk == "HIGH" and (trajectory == "ESCALATING" or escalation_prob > 0.6):
                risk       = "CRITICAL"
                risk_score = max(0.92, risk_score)
                reason     = "CRITICAL ESCALATION: Repeated HIGH distress with escalating trajectory. " + reason
            elif risk == "MEDIUM" and trajectory == "ESCALATING":
                risk       = "HIGH"
                risk_score = max(0.78, risk_score)
                reason     = "HIGH ESCALATION: Persistent MEDIUM distress with repeated high-risk history. " + reason
            elif risk == "LOW":
                risk       = "MEDIUM"
                risk_score = max(0.45, risk_score)
                reason     = "MEDIUM ESCALATION: Repeated high-risk history, monitoring mild vent. " + reason
        elif trajectory == "ESCALATING":
            if risk == "LOW":
                risk       = "MEDIUM"
                risk_score = max(0.45, risk_score)
                reason     = "MEDIUM ESCALATION: Repeated worsening emotional distress over 14 days. " + reason
            elif risk == "MEDIUM":
                risk       = "HIGH"
                risk_score = max(0.75, risk_score)
                reason     = "HIGH ESCALATION: Repeated worsening emotional distress over 14 days. " + reason


    ## ┐
    ### STAGE 9: RISK PERCENTAGE BANDING                       
    ## ┘
    raw_percent = int(round(risk_score * 99)) + 1
    raw_percent = max(1, min(100, raw_percent))

    RISK_RANGES = {
        "LOW":      (1,  30),
        "MEDIUM":   (31, 60),
        "HIGH":     (61, 85),
        "CRITICAL": (86, 100),
    }
    lo, hi     = RISK_RANGES.get(risk, (1, 100))
    risk_percent = lo + (raw_percent / 100.0) * (hi - lo)
    risk_percent = int(round(max(lo, min(hi, risk_percent))))

    return {
        "risk_score":             float(risk_percent),
        "risk_level":             risk,
        "flag_reason":            reason,
        "reasoning":              reason,
        "trajectory":             trajectory,
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
