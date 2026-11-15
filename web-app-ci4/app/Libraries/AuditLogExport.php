<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Role-scoped audit log category filtering and zero-dependency XLSX workbook export.
 */
final class AuditLogExport
{
    public const COUNSELOR_ENTITY_TYPES = ['vent', 'incident', 'student', 'meeting', 'counselor_note'];
    public const COUNSELOR_USER_ACTIONS = [
        'USER_REGISTERED',
        'STUDENT_APPROVED',
        'STUDENT_REGISTRATION_REJECTED',
        'STUDENT_REGISTRATION_ARCHIVED',
        'GUARDIAN_CONTACT_VERIFIED',
        'GUARDIAN_CONSENT_LINK_GENERATED',
        'GUARDIAN_CONSENT_EMAIL_SENT',
        'AUTO_LOCK_ACCOUNT',
    ];

    /**
     * Ordered category metadata keyed by uppercase entity_type.
     *
     * @return array<string, array{label: string, badge: string}>
     */
    public static function categoriesForRole(int $role): array
    {
        $isCounselor = $role === 1;
        $categories = [
            'INCIDENT' => ['label' => 'Incident', 'badge' => 'orange'],
            'VENT' => ['label' => 'Vent', 'badge' => 'blue'],
            'STUDENT' => ['label' => 'Case & Student', 'badge' => 'purple'],
            'MEETING' => ['label' => 'Session', 'badge' => 'emerald'],
            'COUNSELOR_NOTE' => ['label' => 'Counselor Note', 'badge' => 'teal'],
            'USER' => ['label' => $isCounselor ? 'Registration & Consent' : 'User', 'badge' => 'purple'],
        ];

        if (!$isCounselor) {
            $categories['SYSTEM'] = ['label' => 'System (Login/Logout)', 'badge' => 'indigo'];
        }

        return $categories;
    }

    /**
     * Filter requested category keys strictly against what the given role is allowed to access.
     *
     * @param mixed $requested
     * @return string[] Uppercase entity_type keys
     */
    public static function resolveSelectedCategories(int $role, $requested): array
    {
        $allowed = array_keys(self::categoriesForRole($role));
        if (!is_array($requested)) {
            return $allowed;
        }

        $selected = [];
        foreach ($requested as $item) {
            $key = strtoupper(trim((string) $item));
            if ($key !== '' && in_array($key, $allowed, true) && !in_array($key, $selected, true)) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    /**
     * Fetch matching audit logs in a single JOIN query (no N+1 queries).
     *
     * @param string[] $selectedCategories Uppercase entity_type values
     */
    public static function fetchRows(
        BaseConnection $db,
        int $role,
        array $selectedCategories,
        ?string $dateFilter = null
    ): array {
        if ($selectedCategories === [] || !$db->tableExists('audit_logs')) {
            return [];
        }

        $isCounselor = $role === 1;
        $select = "audit_logs.id, audit_logs.action, audit_logs.entity_type, audit_logs.entity_id, audit_logs.created_at, users.role AS actor_role, CASE WHEN users.role = 0 THEN 'Student account' ELSE users.name END AS actor_name";
        if (!$isCounselor) {
            $select = "audit_logs.id, audit_logs.action, audit_logs.entity_type, audit_logs.entity_id, audit_logs.created_at, audit_logs.ip_address, users.role AS actor_role, CASE WHEN users.role = 0 THEN 'Student account' ELSE users.name END AS actor_name";
        }

        $builder = $db->table('audit_logs')
            ->select($select, false)
            ->join('users', 'users.id = audit_logs.user_id', 'left');

        $selectedLower = array_map('strtolower', $selectedCategories);

        if ($isCounselor) {
            $entityTypes = array_values(array_intersect($selectedLower, self::COUNSELOR_ENTITY_TYPES));
            $includeUser = in_array('user', $selectedLower, true);

            if ($entityTypes === [] && !$includeUser) {
                return [];
            }

            $builder->groupStart();
            if ($entityTypes !== []) {
                $builder->whereIn('audit_logs.entity_type', $entityTypes);
            }
            if ($includeUser) {
                if ($entityTypes !== []) {
                    $builder->orGroupStart();
                } else {
                    $builder->groupStart();
                }
                $builder->where('audit_logs.entity_type', 'user')
                    ->whereIn('audit_logs.action', self::COUNSELOR_USER_ACTIONS)
                    ->groupEnd();
            }
            $builder->groupEnd();
        } else {
            $builder->whereIn('audit_logs.entity_type', $selectedLower);
        }

        if ($dateFilter !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter) === 1) {
            $builder->where('DATE(audit_logs.created_at) =', $dateFilter);
        }

        return $builder
            ->orderBy('audit_logs.created_at', 'DESC')
            ->orderBy('audit_logs.id', 'DESC')
            ->limit(5000)
            ->get()
            ->getResultArray();
    }

    /**
     * Build a styled OOXML .xlsx workbook for the selected audit log rows.
     *
     * @param string[] $selectedCategories
     */
    public static function buildWorkbook(
        array $rows,
        bool $isCounselorView,
        array $selectedCategories,
        ?string $dateFilter = null
    ): string {
        $title = $isCounselorView ? 'STUDENTSHIELD | GUIDANCE AUDIT LOGS' : 'STUDENTSHIELD | SYSTEM AUDIT LOGS';
        $categoryLabels = self::categoriesForRole($isCounselorView ? 1 : 2);
        $selectedText = implode(', ', array_map(
            static fn (string $key): string => $categoryLabels[$key]['label'] ?? $key,
            $selectedCategories
        ));
        $exportedAt = ManilaTime::now('M d, Y g:i A') . ' PHT';
        $subtitle = sprintf(
            'Exported %s | Categories: %s%s',
            $exportedAt,
            $selectedText ?: 'None',
            $dateFilter ? ' | Date: ' . $dateFilter : ''
        );

        $headers = ['Timestamp (PHT)', 'Actor', 'Role', 'Category', 'Action', 'Summary'];
        $widths = [24, 24, 18, 18, 28, 44];
        if (!$isCounselorView) {
            $headers[] = 'IP Address';
            $widths[] = 18;
        }

        $sheetRows = [
            [[$title], 1],
            [[$subtitle], 2],
            [$headers, 3],
        ];

        if ($rows === []) {
            $emptyRow = ['No matching audit logs found for the selected categories.', '', '', '', '', ''];
            if (!$isCounselorView) {
                $emptyRow[] = '';
            }
            $sheetRows[] = [$emptyRow, 0];
        } else {
            foreach ($rows as $index => $log) {
                $dt = ManilaTime::parse((string) ($log['created_at'] ?? ''));
                $timestamp = $dt ? $dt->format('Y-m-d g:i:s A') : (string) ($log['created_at'] ?? '');
                $actorName = (string) ($log['actor_name'] ?? 'System / Unknown');
                $actorRole = isset($log['actor_role'])
                    ? (RoleAccess::NAMES[(int) $log['actor_role']] ?? 'Unknown')
                    : 'Unknown Role';
                $category = strtoupper((string) ($log['entity_type'] ?? ''));
                $action = (string) ($log['action'] ?? '');
                $entityId = (int) ($log['entity_id'] ?? 0);
                $summary = ucfirst(strtolower(str_replace('_', ' ', $action)))
                    . ($entityId > 0 ? ' (reference #' . $entityId . ')' : '');

                $cells = [$timestamp, $actorName, $actorRole, $category, $action, $summary];
                if (!$isCounselorView) {
                    $cells[] = ($log['ip_address'] ?? '') !== '' ? (string) $log['ip_address'] : 'Not recorded';
                }
                $sheetRows[] = [$cells, $index % 2 === 0 ? 0 : 4];
            }
        }

        return self::zip([
            '[Content_Types].xml' => self::xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>'),
            '_rels/.rels' => self::relationships(['officeDocument' => ['xl/workbook.xml']]),
            'xl/_rels/workbook.xml.rels' => self::relationships(['worksheet' => ['worksheets/sheet1.xml'], 'styles' => ['styles.xml']]),
            'xl/workbook.xml' => self::xml('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="Audit Logs" sheetId="1" r:id="rId1"/></sheets></workbook>'),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => self::sheet($sheetRows, $widths),
        ]);
    }

    private static function zip(array $files): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $offset = strlen($body);
            $size = strlen($content);
            $metadata = pack('vvvvvVVVvv', 20, 0, 0, 0, 33, crc32($content), $size, $size, strlen($name), 0);
            $body .= "PK\x03\x04" . $metadata . $name . $content;
            $directory .= "PK\x01\x02" . pack('v', 20) . $metadata
                . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        }
        $count = count($files);
        return $body . $directory . "PK\x05\x06"
            . pack('vvvvVVv', 0, 0, $count, $count, strlen($directory), strlen($body), 0);
    }

    private static function sheet(array $rows, array $widths): string
    {
        $last = chr(64 + count($widths));
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($widths as $i => $width) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';
        foreach ($rows as $index => $row) {
            [$cells, $style] = $row;
            $number = $index + 1;
            $height = $style === 1 ? 36 : ($style === 3 ? 25 : 23);
            if ($style === 0 || $style === 4) {
                foreach ($cells as $column => $value) {
                    if (is_string($value) && $value !== '') {
                        $height = max($height, 16 * (int) ceil(mb_strlen($value) / max(1, $widths[$column] - 3)) + 7);
                    }
                }
            }
            $xml .= '<row r="' . $number . '" ht="' . $height . '" customHeight="1">';
            foreach ($cells as $column => $value) {
                $ref = chr(65 + $column) . $number;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
                } else {
                    $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8');
                    $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return self::xml($xml . '</sheetData><mergeCells count="2"><mergeCell ref="A1:' . $last . '1"/><mergeCell ref="A2:' . $last . '2"/></mergeCells></worksheet>');
    }

    private static function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $body;
    }

    private static function relationships(array $targets): string
    {
        $xml = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $id = 1;
        foreach ($targets as $type => $paths) {
            foreach ($paths as $path) {
                $xml .= '<Relationship Id="rId' . $id++ . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type . '" Target="' . $path . '"/>';
            }
        }
        return self::xml($xml . '</Relationships>');
    }

    private static function styles(): string
    {
        return self::xml('<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <fonts count="4">
                <font><sz val="11"/><color rgb="FF173A2A"/><name val="Calibri"/></font>
                <font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
                <font><b/><sz val="10"/><color rgb="FF0B5E2E"/><name val="Calibri"/></font>
                <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
            </fonts>
            <fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FF0B5E2E"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFF8E9BE"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFF1F7F3"/><bgColor indexed="64"/></patternFill></fill>
            </fills>
            <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
            <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
            <cellXfs count="5">
                <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
                <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
                <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
                <xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>
                <xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
            </cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    }
}
