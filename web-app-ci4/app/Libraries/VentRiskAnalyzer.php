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

    public static function resolve(string $text, $primary, int $httpCode, callable $local, array $riskHistory = []): array
    {
        $trajectory = 'INSUFFICIENT_DATA';
        $probability = 0.0;
        $hasPrimaryTrajectory = false;
        if ($httpCode >= 200 && $httpCode < 300 && self::valid($primary)) {
            $result = $primary;
            $primarySource = $primary['source'] ?? null;
            $source = in_array($primarySource, ['groq', 'gemini'], true) ? $primarySource : 'model';
            if (in_array($primary['trajectory'] ?? null, ['INSUFFICIENT_DATA', 'ESCALATING', 'IMPROVING', 'STABLE'], true)) {
                $trajectory = $primary['trajectory'];
                $hasPrimaryTrajectory = true;
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
        if (!$hasPrimaryTrajectory && $riskHistory !== []) {
            [$trajectory, $probability] = self::evaluateRiskHistory($riskHistory, $classification['risk_level']);
        }
        $hasModelReason = in_array($source, ['model', 'groq', 'gemini'], true) && is_string($result['reasoning'] ?? $result['flag_reason'] ?? null);
        $reasoning = $hasModelReason
            ? ($result['reasoning'] ?? $result['flag_reason'])
            : ($source === 'groq'
                ? 'Clinical backup screening analyzed this entry while the primary engine was unavailable.'
                : ($source === 'gemini'
                    ? 'Legacy backup screening analyzed this entry while the primary engine was unavailable.'
                    : ($source === 'model'
                        ? 'The ML service did not return a detailed explanation for this entry.'
                        : 'Local keyword rules determined this review priority. Review the full entry and ask the student about its context.')));
        if ($classification['risk_level'] !== $result['risk_level']) {
            $reasoning .= ' The PHP safety rules raised the final review priority after the initial analysis.';
        }
        return $classification + ['reasoning' => $reasoning, 'source' => $source, 'trajectory' => $trajectory, 'escalation_probability' => $probability];
    }

    /**
     * EWMA + recency-weighted fallback trajectory scorer when the primary ML service
     * does not supply a trajectory (e.g. outage or backup LLM path).
     *
     * @return array{0: string, 1: float}
     */
    public static function evaluateRiskHistory(array $history, string $currentRisk, ?\DateTimeImmutable $now = null): array
    {
        $riskValues = ['LOW' => 1.0, 'MEDIUM' => 2.0, 'HIGH' => 3.0, 'CRITICAL' => 4.0];
        $reference = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $cutoff = $reference->modify('-14 days');
        $records = [];

        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }
            $risk = strtoupper((string) ($item['risk_level'] ?? ''));
            $rawDate = (string) ($item['created_at'] ?? '');
            if (!isset($riskValues[$risk]) || $rawDate === '') {
                continue;
            }
            try {
                $dt = (new \DateTimeImmutable($rawDate))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                continue;
            }
            if ($dt >= $cutoff && $dt <= $reference) {
                $records[] = ['ts' => $dt, 'risk' => $risk];
            }
        }

        $normalizedCurrent = strtoupper($currentRisk);
        if (!isset($riskValues[$normalizedCurrent])) {
            $normalizedCurrent = 'LOW';
        }
        $records[] = ['ts' => $reference, 'risk' => $normalizedCurrent];
        usort($records, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        if (count($records) < 3) {
            return ['INSUFFICIENT_DATA', 0.0];
        }

        $scores = array_map(static fn (array $r): float => $riskValues[$r['risk']], $records);
        $count = count($scores);

        // Recency-weighted linear slope (base 1.35)
        $weights = [];
        $totalWeight = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $w = 1.35 ** $i;
            $weights[] = $w;
            $totalWeight += $w;
        }
        $meanX = 0.0;
        $meanY = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $meanX += $weights[$i] * $i;
            $meanY += $weights[$i] * $scores[$i];
        }
        $meanX /= $totalWeight;
        $meanY /= $totalWeight;
        $den = 0.0;
        $num = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dx = $i - $meanX;
            $den += $weights[$i] * $dx * $dx;
            $num += $weights[$i] * $dx * ($scores[$i] - $meanY);
        }
        $weightedSlope = $den > 0.0 ? ($num / $den) : 0.0;

        // EWMA (alpha = 0.45)
        $alpha = 0.45;
        $ewma = [$scores[0]];
        for ($i = 1; $i < $count; $i++) {
            $ewma[] = $alpha * $scores[$i] + (1.0 - $alpha) * $ewma[$i - 1];
        }
        $ewmaCurrent = $ewma[$count - 1];
        $ewmaDelta = $ewma[$count - 1] - $ewma[$count - 2];

        $recentSevere = 0;
        $recent48hSevere = 0;
        $cutoff48h = $reference->modify('-48 hours');
        foreach ($records as $r) {
            if (in_array($r['risk'], ['HIGH', 'CRITICAL'], true)) {
                $recentSevere++;
                if ($r['ts'] >= $cutoff48h) {
                    $recent48hSevere++;
                }
            }
        }
        $tailRisks = array_map(static fn (array $r): string => $r['risk'], array_slice($records, -4));
        $hasRecentCritical = in_array('CRITICAL', $tailRisks, true);

        $acuteControlBreach = in_array($normalizedCurrent, ['HIGH', 'CRITICAL'], true)
            && (($ewmaCurrent >= 3.25 && $hasRecentCritical) || $recent48hSevere >= 3);
        $upwardShift = ($weightedSlope > 0.05) || ($ewmaDelta > 0.15 && $normalizedCurrent !== 'LOW');
        $downwardShift = ($weightedSlope < -0.05) || ($ewmaDelta < -0.15);

        if ($acuteControlBreach || $upwardShift) {
            $trajectory = 'ESCALATING';
        } elseif ($downwardShift && !$acuteControlBreach) {
            $trajectory = 'IMPROVING';
        } else {
            $trajectory = 'STABLE';
        }

        $ewmaFactor = max(0.0, ($ewmaCurrent - 1.0) / 3.0) * 0.45;
        $slopeFactor = max(0.0, min(0.30, max($weightedSlope, $ewmaDelta) * 0.6));
        $severityFactor = min(1.0, $recentSevere / 3.0) * 0.25;
        $frequencyFactor = min(1.0, $count / 10.0) * 0.10;
        $probability = round(min(0.99, 0.10 + $ewmaFactor + $slopeFactor + $severityFactor + $frequencyFactor), 2);

        return [$trajectory, $probability];
    }
}
