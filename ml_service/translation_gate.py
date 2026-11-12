"""Bounded, short-lived translation reuse. No disk storage."""

from collections import OrderedDict
from hashlib import sha256
import logging
import re
from threading import Lock
from time import monotonic

from langid.langid import LanguageIdentifier, model

logger = logging.getLogger(__name__)
_identifier = LanguageIdentifier.from_modelstring(model, norm_probs=True)
_language_lock = Lock()
# Code-switched text must not skip translation just because English dominates.
_local_words = re.compile(
    r"\b(ako|akong|ko|kong|mo|siya|sya|kami|tayo|nila|hindi|di|na|ng|nang|ang|sa|"
    r"pero|kasi|wala|gusto|pagod|lungkot|nalulungkot|sakit|sarili|buhay|nako|"
    r"dili|gyud|kaayo|jud|akong|kasakit|saan|ti|iti|ken|mabannogakon)\b", re.I
)


def confidently_english(text):
    if _local_words.search(text):
        return False
    # Statistical language detection is unreliable on very short self-reports.
    if re.fullmatch(
        r"i(?: am|'m| feel)(?: feeling)? (?:(?:very|really|so) )?"
        r"(?:sad|happy|anxious|angry|tired|lonely|overwhelmed|scared|calm|okay)[.!?\s]*",
        text.strip(), re.I,
    ):
        return True
    if len(re.findall(r"[a-zA-Z]+", text)) < 4:
        return False
    with _language_lock:
        language, confidence = _identifier.classify(text)
    return language == 'en' and confidence >= 0.99


class TranslationGate:
    def __init__(self, clock=monotonic, english=confidently_english,
                 ttl=300, capacity=128):
        self.clock, self.english = clock, english
        self.ttl, self.capacity = ttl, capacity
        self.cache = OrderedDict()
        self.busy = False
        self.lock = Lock()

    def translate(self, text, translator):
        # Detect locally: language identification itself never calls Google.
        try:
            if self.english(text):
                return text
        except Exception:
            pass  # Uncertain language still takes the translation path.
        key = sha256(text.encode('utf-8')).digest()
        with self.lock:
            now = self.clock()
            for expired in [k for k, (until, _) in self.cache.items() if until <= now]:
                del self.cache[expired]
            if key in self.cache:
                self.cache.move_to_end(key)
                return self.cache[key][1]
            if self.busy:
                return text
            self.busy = True
        try:
            result = translator(text)
            if not isinstance(result, str) or not result.strip():
                raise ValueError('Empty translation')
            result = result.strip()
            if any(marker in result.lower() for marker in (
                '<html', '<!doctype', 'server error', 'error 500',
                "that's an error", 'that’s an error', 'too many requests'
            )):
                raise ValueError('Invalid translation response')
            with self.lock:
                self.cache[key] = (self.clock() + self.ttl, result)
                while len(self.cache) > self.capacity:
                    self.cache.popitem(last=False)
            return result
        except Exception:
            logger.warning('Translation unavailable; using normalized text')
            return text
        finally:
            with self.lock:
                self.busy = False
