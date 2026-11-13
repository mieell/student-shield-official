"""Conservative clause-scoped exclusions for unambiguous English mentions only.

This is not a clinical intent resolver. Uncertainty, multilingual disclosures and
new clauses remain available to safety rules. Keep aligned with CrisisContext.php.
"""
import re

ACTION = r"(?:kill\s+my\s*self|end\s+my\s+life|cut\s+my\s*self|harm\s+my\s*self|hurt\s+my\s*self)"
DENIAL = re.compile(r"^\s*i (?:do not|don't|don’t|never) (?:want|plan|intend) to " + ACTION + r"\b", re.I)
HISTORY = re.compile(r"^\s*(?:years ago|last year|in the past) i used to (?:say|think)\s+[\"“]?i (?:want|wanted) to " + ACTION + r"\b[\"”]?", re.I)
FICTION = re.compile(r'^[^.!?;\n]{0,100}?\bfictional character\s+(?:who\s+)?(?:says|said)\s*["“][^"”\n]{1,250}["”]', re.I)
PAST_EXPECTATION = re.compile(r"\b(?:akala ko|kanina|earlier)\b", re.I)
RESOLVED_SCARE = re.compile(
    r"\b(?:(?:we|i)\s+(?:really\s+|honestly\s+|truly\s+)?thought|(?:i\s+)?(?:really\s+|honestly\s+)?felt\s+like)\b",
    re.I,
)
PHYSICAL_METHOD = re.compile(
    r"\b(?:kill(?:ing)?\s+my\s*self|end(?:ing)?\s+(?:it\s+all|(?:my|this)\s+life)|cut\s+my\s*self|harm\s+my\s*self|hurt\s+my\s*self|"
    r"want\s+to\s+die|wanna\s+die|suicide|suicidal|magpakamatay|papakamatay|magpapakamatay|"
    r"laslas|naglaslas|maglaslas|bibigti|magbigti|patiwakal|magpatiwakal|mamatay|"
    r"sinasaksak|sinasaktan\s+(?:ko\s+)?sarili|worthless|nobody\s+cares|no\s+one\s+cares|"
    r"walang\s+silbi|pabigat|maglaho)\b",
    re.I,
)
POSITIVE_PIVOT = re.compile(r"\b(?:plot twist|sa tuwa|tears of joy|happy tears|pumasa|nakapasa|line of 9|sobrang saya|napakasaya|sobrang slay|slay|na-upgrade|happy|masaya|blessed|thankful|grateful)\b", re.I)
RELIEF_RESOLUTION = re.compile(
    r"\b(?:pulled\s+through|completely\s+fine|pure\s+relief|from\s+(?:pure\s+)?relief|tears\s+of\s+relief|"
    r"screaming\s+with\s+joy|greatest\s+miracle|turned\s+out\s+(?:fine|okay)|"
    r"false\s+alarm|ligtas\s+na|maayos\s+na\s+pala|nakaligtas)\b",
    re.I,
)


def current_disclosure_text(text: str) -> str:
    parts = re.split(
        r"([.!?;\n]+|[\u2014-]+(?=\s*(?:until|but|pero)\b)|\bbut\b|\bpero\b|\bhowever\b)",
        text,
        flags=re.I,
    )
    for i in range(0, len(parts), 2):
        clause = parts[i]
        for pattern in (DENIAL, HISTORY, FICTION):
            clause = pattern.sub(' [contextual mention] ', clause, count=1)
        if not PHYSICAL_METHOD.search(clause):
            later_text = ''.join(parts[i + 2::2])
            if (
                (PAST_EXPECTATION.search(clause) and (POSITIVE_PIVOT.search(later_text) or RELIEF_RESOLUTION.search(later_text)))
                or (RESOLVED_SCARE.search(clause) and RELIEF_RESOLUTION.search(later_text))
            ):
                clause = ' [contextual mention] '
        parts[i] = clause
    return ''.join(parts)
