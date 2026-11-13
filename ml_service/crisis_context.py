"""Conservative clause-scoped exclusions for resolved panic or framed mentions.

This is not a clinical intent resolver. Uncertainty, persistent distress, explicit
self-harm disclosures, and post-pivot concerns remain intact for safety rules.
Keep aligned with web-app-ci4/app/Libraries/CrisisContext.php.
"""
import re

ACTION = r"(?:kill\s+my\s*self|end\s+my\s+life|cut\s+my\s*self|harm\s+my\s*self|hurt\s+my\s*self)"
DENIAL = re.compile(r"^\s*i (?:do not|don't|don’t|never) (?:want|plan|intend) to " + ACTION + r"\b", re.I)
HISTORY = re.compile(r"^\s*(?:years ago|last year|in the past) i used to (?:say|think)\s+[\"“]?i (?:want|wanted) to " + ACTION + r"\b[\"”]?", re.I)
FICTION = re.compile(r'^[^.!?;\n]{0,100}?\bfictional character\s+(?:who\s+)?(?:says|said)\s*["“][^"”\n]{1,250}["”]', re.I)

PAST_EXPECTATION = re.compile(
    r"\b(?:akala ko|kanina|earlier|this morning|waiting for|naghihintay|nung una|kagabi|kahapon|"
    r"(?:we|i)\s+(?:really\s+|honestly\s+|truly\s+)?thought|(?:i\s+)?(?:really\s+|honestly\s+)?felt\s+like)\b",
    re.I,
)
EXPLICIT_CRISIS = re.compile(
    r"\b(?:kill(?:ing)?\s+my\s*self|end(?:ing)?\s+(?:it\s+all|(?:my|this)\s+life)|"
    r"cut(?:ting)?\s+my\s*self|harm(?:ing)?\s+my\s*self|hurt(?:ing)?\s+my\s*self|"
    r"stab(?:bing)?\s+my\s*self|hang(?:ing)?\s+my\s*self|unalive\s+my\s*self|"
    r"want\s+to\s+die|wanna\s+die|suicide|suicidal|magpakamatay|papakamatay|magpapakamatay|"
    r"nagpakamatay|pakamatay|laslas|naglaslas|naglalaslas|maglaslas|bibigti|magbigti|nagbigti|bibitay|"
    r"patiwakal|magpatiwakal|sinasaksak|sinasaktan\s+(?:ko\s+)?sarili|tapusin\s+ko\s+na\s+buhay\s+ko|"
    r"gusto\s+ko\s+(?:nang?|kong)\s+mamatay|worthless|nobody\s+cares|no\s+one\s+cares|"
    r"walang\s+silbi|wala\s+akong\s+silbi|hindi\s+ako\s+karapat-?dapat\s+mahalin|pabigat|"
    r"mas\s+mabuti\s+pang\s+(?:maglaho|mawala))\b",
    re.I,
)
PERSISTENT_ONGOING = re.compile(
    r"\b(?:araw-?araw|every\s+day|every\s+single\s+day|for\s+(?:weeks|months)|walang\s+katapusan|"
    r"hanggang\s+ngayon|pa\s+rin\s+ako)\b",
    re.I,
)
POSITIVE_PIVOT = re.compile(
    r"\b(?<!sana\s)(?<!kung\s)(?:plot\s+twist|sa\s+tuwa|tears\s+of\s+joy|happy\s+tears|"
    r"pumasa|nakapasa|pasado(?:\s+(?:kami|ako|na))?|with\s+flying\s+colors|best\s+thesis|"
    r"tapos\s+na\s+ang\s+kalbaryo|line\s+of\s+9|sobrang\s+saya|napakasaya|sobrang\s+slay|"
    r"dasurv|na-upgrade)\b",
    re.I,
)
RELIEF_RESOLUTION = re.compile(
    r"\b(?:pulled\s+through|completely\s+fine|pure\s+relief|from\s+(?:pure\s+)?relief|tears\s+of\s+relief|"
    r"screaming\s+with\s+joy|greatest\s+miracle|turned\s+out\s+(?:fine|okay)|"
    r"false\s+alarm|ligtas\s+na|maayos\s+na\s+pala|nakaligtas)\b",
    re.I,
)
UNRESOLVED_DISTRESS = re.compile(
    r"\b(?:kill(?:ing)?\s+my\s*self|end(?:ing)?\s+(?:it\s+all|(?:my|this)\s+life)|"
    r"cut(?:ting)?\s+my\s*self|want\s+to\s+die|wanna\s+die|suicide|suicidal|magpakamatay|"
    r"papakamatay|magpapakamatay|nagpakamatay|pakamatay|laslas|naglaslas|naglalaslas|maglaslas|"
    r"bibigti|magbigti|patiwakal|magpatiwakal|sinasaksak|sinasaktan\s+(?:ko\s+)?sarili|"
    r"tapusin\s+ko\s+na\s+buhay\s+ko|gusto\s+ko\s+(?:nang?|kong)\s+(?:mamatay|maglaho|mawala)|"
    r"wish\s+i\s+could\s+disappear|wala\s+akong\s+silbi|walang\s+silbi|"
    r"wala\s+nang?\s+(?:natitirang\s+)?(?:rason|lakas|pag-?asa)|durog\s+na\s+durog|"
    r"pagod\s+na\s+pagod|hindi\s+ko\s+na\s+kaya|di\s+ko\s+na\s+kaya|depressed|hopeless|"
    r"no\s+hope|araw-?araw|every\s+single\s+day)\b",
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
            clause = pattern.sub(" [contextual mention] ", clause, count=1)
        parts[i] = clause

    pivot_indices = [
        i for i in range(0, len(parts), 2)
        if (POSITIVE_PIVOT.search(parts[i]) or RELIEF_RESOLUTION.search(parts[i]))
        and not UNRESOLVED_DISTRESS.search(parts[i])
    ]
    if pivot_indices and pivot_indices[0] > 0:
        first_pivot = pivot_indices[0]
        post_text = " ".join(parts[first_pivot::2])
        if not UNRESOLVED_DISTRESS.search(post_text):
            pre_clauses = [parts[i] for i in range(0, first_pivot, 2)]
            if (
                any(PAST_EXPECTATION.search(c) for c in pre_clauses)
                and all(not EXPLICIT_CRISIS.search(c) and not PERSISTENT_ONGOING.search(c) for c in pre_clauses)
            ):
                for i in range(0, first_pivot, 2):
                    parts[i] = " [contextual mention] "

    return "".join(parts)
