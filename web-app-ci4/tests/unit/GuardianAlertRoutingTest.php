<?php

namespace Tests\Unit;

use App\Libraries\GuardianAlertRouting;
use App\Libraries\RoleAccess;
use CodeIgniter\Test\CIUnitTestCase;

final class GuardianAlertRoutingTest extends CIUnitTestCase
{
    private array $parent = ['parent_phone' => '09171234567', 'parent_email' => 'parent@example.test'];

    public function testUnsafeGuardianWithApprovedAlternateNeverUsesParent(): void
    {
        $route = GuardianAlertRouting::resolve($this->parent, [
            'unsafe_guardian' => 1, 'alternate_phone' => '09181234567',
            'alternate_email' => 'school-approved@example.test', 'verified_by' => 3, 'verified_at' => '2026-11-14 10:00:00',
        ]);
        self::assertSame('+639181234567', $route['phone']);
        self::assertSame('school-approved@example.test', $route['email']);
        self::assertFalse($route['blocked']);
    }

    public function testUnapprovedAlternateCannotReceiveAlerts(): void
    {
        $route = GuardianAlertRouting::resolve($this->parent, [
            'unsafe_guardian' => 1, 'alternate_phone' => '09181234567',
            'alternate_email' => 'alternate@example.test', 'verified_by' => null, 'verified_at' => null,
        ]);
        self::assertNull($route['phone']);
        self::assertNull($route['email']);
        self::assertTrue($route['blocked']);
        self::assertStringContainsString('Arrange safe outreach', $route['note']);
    }


    public function testUnsafeParentCannotBeReusedAsApprovedAlternate(): void
    {
        $route = GuardianAlertRouting::resolve($this->parent, [
            'unsafe_guardian' => 1, 'alternate_phone' => '+639171234567',
            'alternate_email' => 'PARENT@example.test', 'verified_by' => 3, 'verified_at' => '2026-11-14 10:00:00',
        ]);
        self::assertNull($route['phone']);
        self::assertNull($route['email']);
        self::assertTrue($route['blocked']);
    }


    public function testVerifiedConsentPhoneCannotBeReusedAsAlternate(): void
    {
        $route = GuardianAlertRouting::resolve($this->parent, [
            'unsafe_guardian' => 1, 'alternate_phone' => '09201234567',
            'alternate_email' => '', 'verified_guardian_phone' => '+639201234567',
            'verified_by' => 3, 'verified_at' => '2026-11-14 10:00:00',
        ]);
        self::assertNull($route['phone']);
        self::assertTrue($route['blocked']);
    }

    public function testMalformedFormFieldsCannotClearUnsafeStatus(): void
    {
        foreach (['unsafe_guardian', 'alternate_name', 'alternate_phone', 'alternate_email', 'school_approved'] as $field) {
            try {
                GuardianAlertRouting::validate([$field => ['unexpected']]);
                self::fail('Malformed ' . $field . ' was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('invalid field', $error->getMessage());
            }
        }
    }

    public function testUnknownSafetyValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GuardianAlertRouting::validate(['unsafe_guardian' => 'yes']);
    }


    public function testSettingsIncludesVerifiedConsentPhoneForDuplicateProtection(): void
    {
        $db = \Config\Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => ''], false);
        try {
            $db->query('CREATE TABLE guardian_alert_routes (user_id INTEGER PRIMARY KEY, unsafe_guardian INTEGER)');
            $db->query('CREATE TABLE guardian_consents (user_id INTEGER PRIMARY KEY, phone TEXT)');
            $db->table('guardian_alert_routes')->insert(['user_id' => 10, 'unsafe_guardian' => 1]);
            $db->table('guardian_consents')->insert(['user_id' => 10, 'phone' => '+639201234567']);
            self::assertSame('+639201234567', GuardianAlertRouting::settings($db, 10)['verified_guardian_phone']);
        } finally {
            $db->close();
        }
    }

    public function testMissingSafetyTableBlocksGuardianOutreach(): void
    {
        self::assertTrue(GuardianAlertRouting::resolve($this->parent, null)['blocked']);
    }

    public function testSafeGuardianKeepsExistingRegisteredContact(): void
    {
        $route = GuardianAlertRouting::resolve($this->parent, ['unsafe_guardian' => 0]);
        self::assertSame('+639171234567', $route['phone']);
        self::assertSame('parent@example.test', $route['email']);
    }


    public function testSchoolVerifiedGuardianPhoneOverridesRegistrationPhone(): void
    {
        $route = GuardianAlertRouting::resolve($this->parent, [
            'unsafe_guardian' => 0, 'verified_guardian_phone' => '09201234567',
        ]);
        self::assertSame('+639201234567', $route['phone']);
        self::assertFalse($route['blocked']);
    }

    public function testUnsafeFlagCanBeSavedWhileSafeContactIsBeingArranged(): void
    {
        $validated = GuardianAlertRouting::validate(['unsafe_guardian' => '1']);
        self::assertSame(1, $validated['unsafe_guardian']);
        self::assertNull($validated['alternate_phone']);
    }

    public function testAlternateRequiresSchoolApproval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GuardianAlertRouting::validate([
            'unsafe_guardian' => '1', 'alternate_name' => 'School contact', 'alternate_phone' => '09181234567',
        ]);
    }

    public function testAlternateRejectsInvalidPhone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GuardianAlertRouting::validate([
            'unsafe_guardian' => '1', 'alternate_name' => 'School contact',
            'alternate_phone' => 'letters', 'school_approved' => '1',
        ]);
    }

    public function testApprovedEmailOnlyAlternateIsAllowed(): void
    {
        $data = GuardianAlertRouting::validate([
            'unsafe_guardian' => '1', 'alternate_name' => 'School contact',
            'alternate_email' => 'school@example.test', 'school_approved' => '1',
        ]);
        self::assertSame('school@example.test', $data['alternate_email']);
        self::assertNull($data['alternate_phone']);
    }

    public function testRestoringGuardianRouteClearsAlternateDetails(): void
    {
        $data = GuardianAlertRouting::validate(['alternate_phone' => '09181234567', 'alternate_name' => 'Old alternate']);
        self::assertSame(0, $data['unsafe_guardian']);
        self::assertNull($data['alternate_phone']);
        self::assertNull($data['alternate_name']);
    }

    public function testOnlyCounselorsCanEditGuardianSafety(): void
    {
        self::assertTrue(RoleAccess::allows(1, 'admin/students/5/guardian-safety'));
        foreach ([0, 2, 3] as $role) {
            self::assertFalse(RoleAccess::allows($role, 'admin/students/5/guardian-safety'));
        }
    }
}
