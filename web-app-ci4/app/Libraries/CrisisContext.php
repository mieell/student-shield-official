<?php

namespace App\Libraries;

/** Conservative clause-scoped exclusions aligned with ml_service/crisis_context.py. */
final class CrisisContext
{
    private const ACTION = '(?:kill\s+my\s*self|end\s+my\s+life|cut\s+my\s*self|harm\s+my\s*self|hurt\s+my\s*self)';
    private const CLAUSE_PATTERNS = [
        '/^\s*i (?:do not|don\'t|don’t|never) (?:want|plan|intend) to ' . self::ACTION . '\b/iu',
        '/^\s*(?:years ago|last year|in the past) i used to (?:say|think)\s+["“]?i (?:want|wanted) to ' . self::ACTION . '\b["”]?/iu',
        '/^[^.!?;\n]{0,100}?\bfictional character\s+(?:who\s+)?(?:says|said)\s*["“][^"”\n]{1,250}["”]/iu',
    ];
    private const PAST_EXPECTATION = '/\b(?:akala ko|kanina|earlier|this morning|waiting for|naghihintay|nung una|kagabi|kahapon|(?:we|i)\s+(?:really\s+|honestly\s+|truly\s+)?thought|(?:i\s+)?(?:really\s+|honestly\s+)?felt\s+like)\b/iu';
    private const EXPLICIT_CRISIS = '/\b(?:kill(?:ing)?\s+my\s*self|end(?:ing)?\s+(?:it\s+all|(?:my|this)\s+life)|cut(?:ting)?\s+my\s*self|harm(?:ing)?\s+my\s*self|hurt(?:ing)?\s+my\s*self|stab(?:bing)?\s+my\s*self|hang(?:ing)?\s+my\s*self|unalive\s+my\s*self|want\s+to\s+die|wanna\s+die|suicide|suicidal|magpakamatay|papakamatay|magpapakamatay|nagpakamatay|pakamatay|laslas|naglaslas|naglalaslas|maglaslas|bibigti|magbigti|nagbigti|bibitay|patiwakal|magpatiwakal|sinasaksak|sinasaktan\s+(?:ko\s+)?sarili|tapusin\s+ko\s+na\s+buhay\s+ko|gusto\s+ko\s+(?:nang?|kong)\s+mamatay|worthless|nobody\s+cares|no\s+one\s+cares|walang\s+silbi|wala\s+akong\s+silbi|hindi\s+ako\s+karapat-?dapat\s+mahalin|pabigat|mas\s+mabuti\s+pang\s+(?:maglaho|mawala))\b/iu';
    private const PERSISTENT_ONGOING = '/\b(?:araw-?araw|every\s+day|every\s+single\s+day|for\s+(?:weeks|months)|walang\s+katapusan|hanggang\s+ngayon|pa\s+rin\s+ako)\b/iu';
    private const POSITIVE_PIVOT = '/\b(?<!sana\s)(?<!kung\s)(?:plot\s+twist|sa\s+tuwa|tears\s+of\s+joy|happy\s+tears|pumasa|nakapasa|pasado(?:\s+(?:kami|ako|na))?|with\s+flying\s+colors|best\s+thesis|tapos\s+na\s+ang\s+kalbaryo|line\s+of\s+9|sobrang\s+saya|napakasaya|sobrang\s+slay|dasurv|na-upgrade)\b/iu';
    private const RELIEF_RESOLUTION = '/\b(?:pulled\s+through|completely\s+fine|pure\s+relief|from\s+(?:pure\s+)?relief|tears\s+of\s+relief|screaming\s+with\s+joy|greatest\s+miracle|turned\s+out\s+(?:fine|okay)|false\s+alarm|ligtas\s+na|maayos\s+na\s+pala|nakaligtas)\b/iu';
    private const UNRESOLVED_DISTRESS = '/\b(?:kill(?:ing)?\s+my\s*self|end(?:ing)?\s+(?:it\s+all|(?:my|this)\s+life)|cut(?:ting)?\s+my\s*self|want\s+to\s+die|wanna\s+die|suicide|suicidal|magpakamatay|papakamatay|magpapakamatay|nagpakamatay|pakamatay|laslas|naglaslas|naglalaslas|maglaslas|bibigti|magbigti|patiwakal|magpatiwakal|sinasaksak|sinasaktan\s+(?:ko\s+)?sarili|tapusin\s+ko\s+na\s+buhay\s+ko|gusto\s+ko\s+(?:nang?|kong)\s+(?:mamatay|maglaho|mawala)|wish\s+i\s+could\s+disappear|wala\s+akong\s+silbi|walang\s+silbi|wala\s+nang?\s+(?:natitirang\s+)?(?:rason|lakas|pag-?asa)|durog\s+na\s+durog|pagod\s+na\s+pagod|hindi\s+ko\s+na\s+kaya|di\s+ko\s+na\s+kaya|depressed|hopeless|no\s+hope|araw-?araw|every\s+single\s+day)\b/iu';

    public static function currentText(string $text): string
    {
        $parts = preg_split(
            '/([.!?;' . "\n" . ']+|[\x{2014}-]+(?=\s*(?:until|but|pero)\b)|\bbut\b|\bpero\b|\bhowever\b)/iu',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if (!is_array($parts)) {
            return $text;
        }

        for ($i = 0; $i < count($parts); $i += 2) {
            foreach (self::CLAUSE_PATTERNS as $pattern) {
                $parts[$i] = (string) preg_replace($pattern, ' [contextual mention] ', $parts[$i], 1);
            }
        }

        $pivotIndices = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $isPivot = preg_match(self::POSITIVE_PIVOT, $parts[$i]) === 1
                || preg_match(self::RELIEF_RESOLUTION, $parts[$i]) === 1;
            if ($isPivot && preg_match(self::UNRESOLVED_DISTRESS, $parts[$i]) !== 1) {
                $pivotIndices[] = $i;
            }
        }

        if ($pivotIndices !== [] && $pivotIndices[0] > 0) {
            $firstPivot = $pivotIndices[0];
            $postText = '';
            for ($i = $firstPivot; $i < count($parts); $i += 2) {
                $postText .= ' ' . $parts[$i];
            }

            if (preg_match(self::UNRESOLVED_DISTRESS, $postText) !== 1) {
                $hasPastMarker = false;
                $safePre = true;
                for ($i = 0; $i < $firstPivot; $i += 2) {
                    if (preg_match(self::PAST_EXPECTATION, $parts[$i]) === 1) {
                        $hasPastMarker = true;
                    }
                    if (preg_match(self::EXPLICIT_CRISIS, $parts[$i]) === 1
                        || preg_match(self::PERSISTENT_ONGOING, $parts[$i]) === 1) {
                        $safePre = false;
                        break;
                    }
                }
                if ($hasPastMarker && $safePre) {
                    for ($i = 0; $i < $firstPivot; $i += 2) {
                        $parts[$i] = ' [contextual mention] ';
                    }
                }
            }
        }

        return implode('', $parts);
    }
}
