"""Conservative clause-scoped exclusions for unambiguous English mentions only.

This is not a clinical intent resolver. Uncertainty, multilingual disclosures and
new clauses remain available to safety rules. Keep aligned with CrisisContext.php.
"""
import re

ACTION = r"(?:kill myself|end my life|cut myself|harm myself|hurt myself)"
DENIAL = re.compile(r"^\s*i (?:do not|don't|don’t|never) (?:want|plan|intend) to " + ACTION + r"\b", re.I)
HISTORY = re.compile(r"^\s*(?:years ago|last year|in the past) i used to (?:say|think)\s+[\"“]?i (?:want|wanted) to " + ACTION + r"\b[\"”]?", re.I)
FICTION = re.compile(r'^[^.!?;\n]{0,100}?\bfictional character\s+(?:who\s+)?(?:says|said)\s*["“][^"”\n]{1,250}["”]', re.I)
PAST_EXPECTATION = re.compile(r"\bakala ko\b", re.I)
PHYSICAL_METHOD = re.compile(r"\b(?:kill myself|end my life|cut myself|harm myself|hurt myself|magpakamatay|laslas|naglaslas|maglaslas|bibigti|magbigti|mamatay|sinasaksak|sinasaktan ko sarili)\b", re.I)
POSITIVE_PIVOT = re.compile(r"\b(?:plot twist|sa tuwa|tears of joy|happy tears|pumasa|nakapasa|line of 9|sobrang saya|napakasaya)\b", re.I)


def current_disclosure_text(text: str) -> str:
    parts = re.split(r"([.!?;\n]+|\bbut\b|\bpero\b|\bhowever\b)", text, flags=re.I)
    for i in range(0, len(parts), 2):
        clause = parts[i]
        for pattern in (DENIAL, HISTORY, FICTION):
            clause = pattern.sub(' [contextual mention] ', clause, count=1)
        if PAST_EXPECTATION.search(clause) and not PHYSICAL_METHOD.search(clause):
            later_text = ''.join(parts[i + 2::2])
            if POSITIVE_PIVOT.search(later_text):
                clause = ' [contextual mention] '
        parts[i] = clause
    return ''.join(parts)
