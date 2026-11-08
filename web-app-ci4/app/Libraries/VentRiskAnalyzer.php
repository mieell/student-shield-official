<?php
namespace App\Libraries;

/** Selects the model assessment or the local fallback. */
final class VentRiskAnalyzer
{
    public const BANDS = ['LOW' => [1, 30], 'MEDIUM' => [31, 60], 'HIGH' => [61, 85], 'CRITICAL' => [86, 100]];

    public static function valid($result): bool
    {
        if (!is_array($result) || !is_string($result['risk_level'] ?? null)) return false;
        $band = self::BANDS[$result['risk_level']] ?? null;
        $score = $result['risk_score'] ?? $result['risk_percent'] ?? null;
        return $band !== null && (is_int($score) || is_float($score)) && is_finite((float) $score)
            && $score >= $band[0] && $score <= $band[1];
    }

    public static function resolve(string $text, $primary, int $httpCode, callable $local): array
    {
        $trajectory = 'INSUFFICIENT_DATA';
        $probability = 0.0;
        if ($httpCode >= 200 && $httpCode < 300 && self::valid($primary)) {
            $result = $primary;
            $source = 'model';
            if (in_array($primary['trajectory'] ?? null, ['INSUFFICIENT_DATA', 'ESCALATING', 'IMPROVING', 'STABLE'], true)) {
                $trajectory = $primary['trajectory'];
            }
            $value = $primary['escalation_probability'] ?? null;
            if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1) {
                $probability = (float) $value;
            }
        } else {
            // Always retain the full multilingual local fallback as the outage safety floor.
            $result = $local($text);
            $source = 'local';
        }
        $classification = ExplicitCrisisGuard::enforce($text, [
            'risk_level' => $result['risk_level'],
            'risk_percent' => (float) ($result['risk_score'] ?? $result['risk_percent']),
        ]);
        $classification = ViolentThreats::enforce($text, $classification);
        return $classification + ['source' => $source, 'trajectory' => $trajectory, 'escalation_probability' => $probability];
    }
}
