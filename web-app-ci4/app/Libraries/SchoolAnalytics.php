<?php
namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SchoolAnalytics
{
    public function __construct(private object $db) {}

    public static function filters(array $input, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->setTime(0, 0);
        $preset = (string) ($input['period'] ?? 'last30');
        $ranges = [
            'today' => [$today, $today], 'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'last7' => [$today->modify('-6 days'), $today], 'last30' => [$today->modify('-29 days'), $today],
            'week' => [$today->modify('monday this week'), $today], 'month' => [$today->modify('first day of this month'), $today],
        ];
        if ($preset === 'custom') {
            $dates = [];
            foreach (['start', 'end'] as $key) {
                $value = (string) ($input[$key] ?? '');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());
                if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Choose valid start and end dates.');
                $dates[] = $date;
            }
            [$start, $end] = $dates;
        } elseif (isset($ranges[$preset])) [$start, $end] = $ranges[$preset];
        else throw new InvalidArgumentException('Choose a valid date period.');
        if ($start > $end || $end > $today || $start->diff($end)->days > 365) throw new InvalidArgumentException('Use a date range of up to 366 days ending today or earlier.');
        $category = (string) ($input['category'] ?? 'all');
        $risk = (string) ($input['risk'] ?? 'all');
        if (!in_array($category, ['all', 'Safe Space', 'Bullying'], true) || !in_array($risk, ['all', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true)) throw new InvalidArgumentException('Invalid category or risk filter.');
        $grade = trim((string) ($input['grade'] ?? ''));
        $section = trim((string) ($input['section'] ?? ''));
        if (mb_strlen($grade) > 50 || mb_strlen($section) > 100) throw new InvalidArgumentException('Invalid grade or section.');
        return ['period' => $preset, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
            'grade' => $grade, 'section' => $section, 'category' => $category, 'risk' => $risk, 'today' => $today->format('Y-m-d')];
    }

    private function events(): string
    {
        // This projection excludes names, message text, contacts, notes, evidence and meeting links.
        return "(SELECT v.user_id AS student_key, v.created_at AS occurred_at, 'Safe Space' AS category,
            UPPER(v.risk_level) AS risk, v.status AS state, s.grade_level AS grade, s.section
            FROM vent_logs v JOIN users u ON u.id = v.user_id AND u.role = 0 LEFT JOIN students s ON s.user_id = u.id
            WHERE v.deleted_at IS NULL
            UNION ALL
            SELECT COALESCE(b.victim_student_id, b.reporter_student_id), b.created_at, 'Bullying', 'UNCLASSIFIED', b.status, s.grade_level, s.section
            FROM bully_reports b JOIN users u ON u.id = COALESCE(b.victim_student_id, b.reporter_student_id) AND u.role = 0
            LEFT JOIN students s ON s.user_id = u.id WHERE b.deleted_at IS NULL) e";
    }

    private function where(array $f): array
    {
        $end = (new DateTimeImmutable($f['end'], ManilaTime::zone()))->modify('+1 day')->format('Y-m-d');
        $sql = 'e.occurred_at >= ? AND e.occurred_at < ?';
        $params = [$f['start'], $end];
        foreach (['grade' => 'grade', 'section' => 'section', 'category' => 'category', 'risk' => 'risk'] as $key => $column) {
            if ($f[$key] !== '' && $f[$key] !== 'all') { $sql .= " AND e.$column = ?"; $params[] = $f[$key]; }
        }
        return [$sql, $params];
    }

    private function rows(string $sql, array $params): array
    {
        return $this->db->query($sql, $params)->getResultArray();
    }

    public function summary(array $f): array
    {
        [$where, $params] = $this->where($f);
        $events = $this->events();
        $today = new DateTimeImmutable($f['today'], ManilaTime::zone());
        $stats = $this->rows("SELECT COUNT(*) AS events,
            SUM(CASE WHEN e.occurred_at >= ? AND e.occurred_at < ? THEN 1 ELSE 0 END) AS today_count,
            SUM(CASE WHEN e.occurred_at >= ? AND e.occurred_at < ? THEN 1 ELSE 0 END) AS week_count,
            SUM(CASE WHEN e.occurred_at >= ? AND e.occurred_at < ? THEN 1 ELSE 0 END) AS month_count,
            COUNT(DISTINCT CASE WHEN e.category = 'Safe Space' AND e.risk IN ('HIGH','CRITICAL') AND e.state != 'Reviewed' THEN e.student_key END) AS attention
            FROM $events WHERE $where", [$f['today'], $today->modify('+1 day')->format('Y-m-d'), $today->modify('monday this week')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), $today->modify('first day of this month')->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), ...$params])[0];
        $studentWhere = 'u.role = 0 AND u.created_at >= ? AND u.created_at < ?';
        $studentParams = [$params[0], $params[1]];
        foreach (['grade' => 'grade_level', 'section' => 'section'] as $key => $col) {
            if ($f[$key] !== '') { $studentWhere .= " AND s.$col = ?"; $studentParams[] = $f[$key]; }
        }
        if ($f['category'] !== 'all' || $f['risk'] !== 'all') {
            $studentWhere .= " AND EXISTS (SELECT 1 FROM $events WHERE $where AND e.student_key = u.id)";
            $studentParams = [...$studentParams, ...$params];
        }
        $students = $this->rows("SELECT COUNT(*) AS registered, SUM(CASE WHEN u.is_active = 1 AND u.access_disabled = 0 AND u.registration_status = 'approved' THEN 1 ELSE 0 END) AS active
            FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE $studentWhere", $studentParams)[0];
        $start = new DateTimeImmutable($f['start'], ManilaTime::zone()); $end = new DateTimeImmutable($f['end'], ManilaTime::zone());
        $days = $start->diff($end)->days + 1;
        $previous = $f; $previous['end'] = $start->modify('-1 day')->format('Y-m-d'); $previous['start'] = $start->modify("-$days days")->format('Y-m-d');
        [$previousWhere, $previousParams] = $this->where($previous);
        $prior = (int) $this->rows("SELECT COUNT(*) AS n FROM $events WHERE $previousWhere", $previousParams)[0]['n'];
        $groups = [];
        foreach (['category' => 'e.category', 'risk' => 'e.risk', 'grade' => "COALESCE(NULLIF(e.grade, ''), 'Not specified')", 'section' => "COALESCE(NULLIF(e.section, ''), 'Not specified')", 'trend' => 'DATE(e.occurred_at)'] as $key => $expr) {
            $riskOnly = $key === 'risk' ? " AND e.category = 'Safe Space'" : '';
            $groups[$key] = $this->rows("SELECT $expr AS label, COUNT(*) AS total FROM $events WHERE $where $riskOnly GROUP BY $expr ORDER BY $expr", $params);
        }
        // Fill zero-event dates so the trend shows quiet days too.
        $daily = array_column($groups['trend'], 'total', 'label'); $groups['trend'] = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $label = $day->format('Y-m-d'); $groups['trend'][] = ['label' => $label, 'total' => (int) ($daily[$label] ?? 0)];
        }
        foreach ($groups as &$rows) foreach ($rows as &$row) $row['total'] = (int) $row['total'];
        unset($rows, $row);
        return ['filters' => $f, 'metrics' => array_map('intval', $stats + $students), 'previous_events' => $prior,
            'change_percent' => $prior ? round(((int) $stats['events'] - $prior) * 100 / $prior, 1) : null, 'groups' => $groups];
    }

    public function options(): array
    {
        return ['grades' => $this->rows("SELECT DISTINCT grade_level AS label FROM students WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level", []),
            'sections' => $this->rows("SELECT DISTINCT section AS label FROM students WHERE section IS NOT NULL AND section != '' ORDER BY section", [])];
    }
}
