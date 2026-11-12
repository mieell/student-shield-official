<?php

namespace App\Libraries;

/** Explicit crisis signals cannot be downgraded by model scores or sarcasm. */
final class ExplicitCrisisGuard
{
    private const PHRASES = [
        'magpakamatay', 'kill myself', 'cut myself', 'slit my wrist',
        'bibigti', 'patayin sarili ko', 'magbibigti', 'self-harm',
        'tapusin ko na buhay ko', 'tapusin ko na lang lahat', 'tapusin ko na lahat', 'tapusin ko na',
        'sana matulog at di na magising', 'mas magiging madali para sa lahat', 'pain to stop tonight',
        'pain to stop for good', 'want all this pain to stop',
        'gusto ko nang mamatay', 'i want to die', 'i wanna die',
        'end my life', 'gusto nako mamatay', 'kayat kon matayen',
        'nag-iwan ng sulat para kay', 'huling paalam sa inyo',
        'sasabay na ako', 'sasabay na ko', 'sasabay na aq', 'susunod na ako', 'susunod na ko',
        'sasama na ako sa kanya', 'sasama na ko sa kanya', 'gusto ko nang sumunod',
        'lost my whole motivation to live', 'lost my motivation to live',
    ];

    // Keep these Tagalog self-harm patterns aligned with CAT1_DIRECT in main.py.
    private const SELF_HARM_PATTERNS = [
        '/\b(?:laslas|naglaslas|naglalaslas|maglaslas|maglalaslas|nilaslas|nilalaslas|paglaslas|paglalaslas|lalaslas|laslasin|lilaslasin)\b/iu',
        '/\b(?:sinasaktan|sinaktan|sasaktan|saktan|sinasaksak|sinaksak|sasaksakin|saksakin)\s+(?:ko\s+(?:ang\s+)?sarili(?:\s+ko)?|(?:ang\s+)?sarili\s+ko)\b/iu',
        '/\b(?:mag|nag|i)?pa(?:pa)?kamatay\b/iu',
        '/\b(?:mag|nag)?bi(?:bi)?gti\b/iu',
        '/\b(?:mag|nag)?bi(?:bi)?tay\b/iu',
        '/\b(?:(?:pag)?(?:mag|nag)?pa(?:pa)?tiwakal|patiwakal)\b/iu',
    ];

    public static function enforce(string $text, array $classification): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        foreach (self::PHRASES as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                return self::critical($classification);
            }
        }
        if (self::hasTagalogSelfHarm($text)) {
            return self::critical($classification);
        }
        return $classification;
    }

    public static function hasTagalogSelfHarm(string $text): bool
    {
        foreach (self::SELF_HARM_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function critical(array $classification): array
    {
        $classification['risk_level'] = 'CRITICAL';
        $classification['risk_percent'] = max((float) ($classification['risk_percent'] ?? 0), 99.0);
        return $classification;
    }
}
