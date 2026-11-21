<?php

use App\Libraries\RoleAccess;
use CodeIgniter\Test\CIUnitTestCase;

final class UiLogicAuditFixesTest extends CIUnitTestCase
{
    public function testLoginDisplaysSuccessFlashAndForgotPasswordModal(): void
    {
        helper(['url', 'form']);
        session()->setFlashdata('success', 'Registration submitted cleanly.');

        $html = view('login');

        $this->assertStringContainsString('Registration submitted cleanly.', $html);
        $this->assertStringContainsString('id="forgotPasswordBtn"', $html);
        $this->assertStringContainsString('id="forgotPasswordModal"', $html);
        $this->assertStringContainsString('btn.disabled = true', $html);
    }

    public function testLoginShowsRegistrationHandoffAfterSubmission(): void
    {
        helper(['url', 'form']);
        $this->assertStringNotContainsString('id="registrationReceiptTitle"', view('login'));

        session()->setFlashdata('registration_submitted', true);
        $html = view('login');
        $this->assertStringContainsString('Registration submitted</h2>', $html);
        $this->assertStringContainsString('Waiting for guardian contact verification', $html);
        $this->assertStringContainsString('You can sign in once your account is approved.', $html);
        session()->remove('registration_submitted');
    }

    public function testManageAccountsShowsUnlockControlOnlyForLockedStudents(): void
    {
        helper(['url', 'form']);
        session()->set(['role' => 2, 'user_id' => 2, 'name' => 'Super Admin']);

        $html = view('manage_accounts', [
            'users' => [
                ['id' => 2, 'name' => 'Super Admin', 'role' => 2, 'access_disabled' => 0, 'is_locked' => 0],
                ['id' => 7, 'name' => 'Locked Student', 'role' => 0, 'access_disabled' => 0, 'is_locked' => 1, 'registration_status' => 'approved'],
                ['id' => 8, 'name' => 'Normal Student', 'role' => 0, 'access_disabled' => 0, 'is_locked' => 0, 'registration_status' => 'approved'],
            ],
        ]);

        $this->assertStringContainsString('Reporting locked', $html);
        $this->assertStringContainsString('admin/users/unlock/7', $html);
        $this->assertStringNotContainsString('admin/users/unlock/8', $html);

        session()->destroy();
    }

    public function testStaffProfileIncludesChangePasswordFormAndRoleAccessAllowsRoute(): void
    {
        helper(['url', 'form']);
        foreach ([1, 2, 3] as $role) {
            $this->assertTrue(RoleAccess::allows($role, 'staff/password'));
        }
        $this->assertFalse(RoleAccess::allows(0, 'staff/password'));

        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1, 'name' => 'Counselor One']);
        $html = view('staff_account', [
            'page' => 'profile',
            'account' => ['id' => 1, 'name' => 'Counselor One', 'role' => 1, 'phone' => '09171234567', 'email' => 'counselor@example.test'],
            'roleName' => 'Counselor',
        ]);

        $this->assertStringContainsString('id="staffPasswordForm"', $html);
        $this->assertStringContainsString('staff/password', $html);
        $this->assertStringContainsString('name="new_password"', $html);
        $this->assertStringContainsString('name="new_password_confirm"', $html);

        session()->destroy();
    }

    public function testAuditLogsRendersFlashBannerAndServerSideFilterForm(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1, 'name' => 'Counselor One']);
        session()->setFlashdata('error', 'Select at least one audit log category to download.');

        $html = view('admin_audit_logs', [
            'logs' => [],
            'pager' => null,
            'isCounselorView' => true,
            'exportCategories' => \App\Libraries\AuditLogExport::categoriesForRole(1),
            'filterSearch' => 'RESOLVED',
            'filterCategory' => 'VENT',
            'filterDate' => '2026-11-20',
        ]);

        $this->assertStringContainsString('Select at least one audit log category to download.', $html);
        $this->assertStringContainsString('id="auditFilterForm"', $html);
        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('value="RESOLVED"', $html);
        $this->assertStringContainsString('name="category"', $html);
        $this->assertStringContainsString('name="date"', $html);

        session()->destroy();
    }

    public function testCaseSummaryAndBullyingAndVentAndGuardianViewsAvoidRawAlertsAndDoubleSubmit(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);

        $caseHtml = view('case_summary', ['studentId' => 10]);
        $this->assertStringContainsString('showCaseToast', $caseHtml);
        $this->assertStringNotContainsString('alert(', $caseHtml);
        $this->assertStringNotContainsString('href="/admin/dashboard"', $caseHtml);
        $this->assertStringNotContainsString('fetch("/admin/', $caseHtml);

        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);
        $bullyingHtml = view('reports/bullying_form');
        $this->assertStringContainsString('id="bullyingClientError"', $bullyingHtml);
        $this->assertStringContainsString('id="clearEvidenceBtn"', $bullyingHtml);
        $this->assertStringNotContainsString('alert(', $bullyingHtml);

        $ventHtml = view('vent_form');
        $this->assertStringContainsString('btn.disabled = true', $ventHtml);
        $this->assertStringContainsString('showVentErrorToast', $ventHtml);

        $consentHtml = view('guardian_consent', [
            'message' => '',
            'notice' => 'Sample notice',
            'token' => null,
            'showLookup' => false,
            'lookupPhone' => '09123456789',
            'lookupCode' => 'ABCD-EFGH',
        ]);
        $this->assertStringContainsString("form.dataset.submitting = '1'", $consentHtml);

        session()->destroy();
    }
}
