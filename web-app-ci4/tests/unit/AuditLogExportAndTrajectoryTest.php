<?php

use App\Libraries\AuditLogExport;
use App\Libraries\VentRiskAnalyzer;
use CodeIgniter\Test\CIUnitTestCase;

final class AuditLogExportAndTrajectoryTest extends CIUnitTestCase
{
    public function testCounselorCategoriesExcludeSystem(): void
    {
        $counselorCategories = AuditLogExport::categoriesForRole(1);
        $this->assertArrayNotHasKey('SYSTEM', $counselorCategories);
        $this->assertSame(
            ['INCIDENT', 'VENT', 'STUDENT', 'MEETING', 'COUNSELOR_NOTE', 'USER'],
            array_keys($counselorCategories)
        );

        $adminCategories = AuditLogExport::categoriesForRole(2);
        $this->assertArrayHasKey('SYSTEM', $adminCategories);
        $this->assertCount(7, $adminCategories);
    }

    public function testResolveSelectedCategoriesEnforcesRoleScoping(): void
    {
        $counselorSelected = AuditLogExport::resolveSelectedCategories(1, ['system', 'vent', 'incident', 'unknown']);
        $this->assertSame(['VENT', 'INCIDENT'], $counselorSelected);

        $adminSelected = AuditLogExport::resolveSelectedCategories(2, ['system', 'vent']);
        $this->assertSame(['SYSTEM', 'VENT'], $adminSelected);

        $noneSelected = AuditLogExport::resolveSelectedCategories(1, []);
        $this->assertSame([], $noneSelected);
    }

    public function testBuildWorkbookProducesValidXlsxWithRoleScopedColumns(): void
    {
        $rows = [
            [
                'id' => 42,
                'action' => 'VENT_RESOLVED',
                'entity_type' => 'vent',
                'entity_id' => 101,
                'created_at' => '2026-11-15 10:30:00',
                'actor_role' => 1,
                'actor_name' => 'Counselor Reyes',
                'ip_address' => '203.0.113.9',
            ],
        ];

        $counselorXlsx = AuditLogExport::buildWorkbook($rows, true, ['VENT'], null);
        $this->assertStringStartsWith("PK\x03\x04", $counselorXlsx);
        $this->assertStringContainsString('STUDENTSHIELD | GUIDANCE AUDIT LOGS', $counselorXlsx);
        $this->assertStringContainsString('Counselor Reyes', $counselorXlsx);
        $this->assertStringNotContainsString('203.0.113.9', $counselorXlsx);

        $adminXlsx = AuditLogExport::buildWorkbook($rows, false, ['VENT'], null);
        $this->assertStringStartsWith("PK\x03\x04", $adminXlsx);
        $this->assertStringContainsString('STUDENTSHIELD | SYSTEM AUDIT LOGS', $adminXlsx);
        $this->assertStringContainsString('203.0.113.9', $adminXlsx);
    }

    public function testEvaluateRiskHistoryDetectsRepeatedHighAndCriticalAsEscalating(): void
    {
        $now = new DateTimeImmutable('2026-11-15T12:00:00+00:00');
        $history = [
            ['risk_level' => 'HIGH', 'created_at' => $now->modify('-3 days')->format(DateTimeInterface::ATOM)],
            ['risk_level' => 'CRITICAL', 'created_at' => $now->modify('-2 days')->format(DateTimeInterface::ATOM)],
            ['risk_level' => 'HIGH', 'created_at' => $now->modify('-1 day')->format(DateTimeInterface::ATOM)],
        ];

        [$trajectory, $probability] = VentRiskAnalyzer::evaluateRiskHistory($history, 'CRITICAL', $now);

        $this->assertSame('ESCALATING', $trajectory);
        $this->assertGreaterThanOrEqual(0.75, $probability);
    }

    public function testResolveUsesEwmaFallbackTrajectoryWhenMlServiceIsDown(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $history = [
            ['risk_level' => 'HIGH', 'created_at' => $now->modify('-12 hours')->format(DateTimeInterface::ATOM)],
            ['risk_level' => 'HIGH', 'created_at' => $now->modify('-4 hours')->format(DateTimeInterface::ATOM)],
        ];

        $assessment = VentRiskAnalyzer::resolve(
            'I cannot take this anymore, I want to disappear',
            null,
            503,
            static fn (): array => ['risk_level' => 'HIGH', 'risk_percent' => 78],
            $history
        );

        $this->assertSame('local', $assessment['source']);
        $this->assertSame('ESCALATING', $assessment['trajectory']);
        $this->assertGreaterThan(0.5, $assessment['escalation_probability']);
    }

    public function testResolveOverridesStaleStablePrimaryWhenRepeatedSevereVentsExist(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $history = [
            ['risk_level' => 'CRITICAL', 'created_at' => $now->modify('-10 days')->format(DateTimeInterface::ATOM)],
            ['risk_level' => 'LOW', 'created_at' => $now->modify('-7 days')->format(DateTimeInterface::ATOM)],
            ['risk_level' => 'HIGH', 'created_at' => $now->modify('-2 days')->format(DateTimeInterface::ATOM)],
            ['risk_level' => 'CRITICAL', 'created_at' => $now->modify('-1 day')->format(DateTimeInterface::ATOM)],
        ];

        $assessment = VentRiskAnalyzer::resolve(
            'I am still feeling hopeless and exhausted',
            [
                'risk_level' => 'HIGH',
                'risk_score' => 78,
                'trajectory' => 'STABLE',
                'escalation_probability' => 0.3,
                'reasoning' => 'Simulated stale primary response',
            ],
            200,
            static fn (): array => ['risk_level' => 'HIGH', 'risk_percent' => 78],
            $history
        );

        $this->assertSame('ESCALATING', $assessment['trajectory']);
        $this->assertGreaterThanOrEqual(0.7, $assessment['escalation_probability']);
    }

    public function testRecomputeBatchTrajectoriesUpgradesExistingStoredStableVents(): void
    {
        $ventsDesc = [
            [
                'id' => 4,
                'user_id' => 15,
                'risk_level' => 'CRITICAL',
                'created_at' => '2026-11-15 16:00:00',
                'trajectory' => 'STABLE',
                'escalation_probability' => 0.25,
            ],
            [
                'id' => 3,
                'user_id' => 15,
                'risk_level' => 'HIGH',
                'created_at' => '2026-11-15 14:00:00',
                'trajectory' => 'STABLE',
                'escalation_probability' => 0.25,
            ],
            [
                'id' => 2,
                'user_id' => 15,
                'risk_level' => 'HIGH',
                'created_at' => '2026-11-14 20:00:00',
                'trajectory' => 'STABLE',
                'escalation_probability' => 0.20,
            ],
            [
                'id' => 1,
                'user_id' => 15,
                'risk_level' => 'CRITICAL',
                'created_at' => '2026-11-13 10:00:00',
                'trajectory' => 'INSUFFICIENT_DATA',
                'escalation_probability' => 0.0,
            ],
        ];

        $recomputed = VentRiskAnalyzer::recomputeBatchTrajectories($ventsDesc);

        // Caller's original DESC order is preserved, while vents 2, 3, and 4 upgrade to ESCALATING.
        $this->assertSame(4, $recomputed[0]['id']);
        $this->assertSame('ESCALATING', $recomputed[0]['trajectory']);
        $this->assertGreaterThan(0.7, $recomputed[0]['escalation_probability']);
        $this->assertSame('ESCALATING', $recomputed[1]['trajectory']);
        $this->assertSame('ESCALATING', $recomputed[2]['trajectory']);
        $this->assertSame('INSUFFICIENT_DATA', $recomputed[3]['trajectory']);
    }
}
