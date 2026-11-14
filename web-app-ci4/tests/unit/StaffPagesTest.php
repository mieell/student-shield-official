<?php

use CodeIgniter\Test\CIUnitTestCase;

final class StaffPagesTest extends CIUnitTestCase
{
    public function testCaseProfileKeepsNotesAndMeetingsWithoutDuplicateStatusControls(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 1]);
        $html = view('case_summary', ['studentId' => 10]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//*[@id="statusModal" or @id="newStatusSelect" or @id="currentStatusText"]')->length);
        foreach (['noteType', 'noteContent', 'statusHistoryTableBody', 'btnStartVideoMeeting', 'pendingRequestBadge'] as $id) {
            $this->assertSame(1, $xpath->query('//*[@id="' . $id . '"]')->length);
        }
        $this->assertStringContainsString('Review incident reports', $html);
        $this->assertStringNotContainsString('submitStatus(', $html);
        session()->remove(['isLoggedIn', 'role']);
    }

    public function testRegistrationNotificationIsCounselorOnly(): void
    {
        helper(['url', 'form']);
        foreach ([1, 2, 3] as $role) {
            session()->set(['isLoggedIn' => true, 'role' => $role, 'user_id' => $role]);
            $html = view('partials/staff_navigation', ['showSessionRequests' => false]);
            if ($role === 1) {
                $this->assertStringContainsString('id="registrationNotification"', $html);
                $this->assertStringContainsString('admin/api/pending-registrations', $html);
                $this->assertStringContainsString('id="navRegistrationsBadge"', $html);
            } else {
                $this->assertStringNotContainsString('id="registrationNotification"', $html);
                $this->assertStringNotContainsString('admin/api/pending-registrations', $html);
            }
        }
        session()->remove(['isLoggedIn', 'role', 'user_id']);
    }

    public function testNonStaffCannotOpenAWorkspaceBeforeAnyDatabaseAccess(): void
    {
        foreach ([null, 0] as $role) {
            session()->set(['isLoggedIn' => $role !== null, 'role' => $role]);
            $response = (new \App\Controllers\AdminController())->dashboard('sessions');
            $this->assertStringEndsWith('/admin/login', $response->getHeaderLine('Location'));
        }
        session()->remove(['isLoggedIn', 'role']);
    }

    public function testUnknownWorkspaceIsRejectedBeforeAnyDatabaseAccess(): void
    {
        session()->set(['isLoggedIn' => true, 'role' => 1]);
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        try {
            (new \App\Controllers\AdminController())->dashboard('unknown');
        } finally {
            session()->remove(['isLoggedIn', 'role']);
        }
    }

    public function testEachStaffPageOnlyRendersItsOwnWorkspace(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);
        $sections = ['vents' => 'section-vents', 'incidents' => 'section-incidents',
            'sessions' => 'section-sessions', 'alerts' => 'section-alerts', 'analytics' => 'section-charts'];

        foreach ($sections as $page => $section) {
            $html = view('admin_dashboard', ['dashboard_page' => $page, 'pending_meetings_count' => 0]);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            foreach ($sections as $candidate) {
                $this->assertSame($candidate === $section ? 1 : 0,
                    $xpath->query('//*[@id="' . $candidate . '"]')->length, $page . ': ' . $candidate);
            }
            $current = $xpath->query('//nav[@aria-label="Staff pages"]/a[@aria-current="page"]');
            $this->assertCount(1, $current);
            $this->assertStringEndsWith('/admin/' . $page, $current->item(0)->getAttribute('href'));
            $this->assertSame(1, $xpath->query('//aside//form[contains(@action,"/logout")]')->length);
            $this->assertSame(0, $xpath->query('//header//form[contains(@action,"/logout")]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="staffMenuButton"][@aria-controls="staffSidebar"]')->length);
            $this->assertSame(0, $xpath->query('//*[@id="pauseUpdatesToggle"]')->length);
            $this->assertStringNotContainsString('href="#section-', $html);
            $this->assertSame(0, $xpath->query('//main//nav[@aria-label="Staff pages"]')->length);
        }
        session()->remove(['isLoggedIn', 'role', 'user_id']);
    }

    public function testCounselorMenuDoesNotExposeSuperAdminLinks(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);
        $html = view('admin_dashboard', ['dashboard_page' => 'sessions', 'pending_meetings_count' => 1]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $this->assertStringNotContainsString('href="/admin/users"', $html);
        $this->assertStringContainsString('/admin/pending-registrations', $html);
        $this->assertSame(1, $xpath->query('//aside//nav[@aria-label="Administration"]//a[contains(@href,"/admin/audit-logs")]')->length);
        $this->assertSame(0, $xpath->query('//aside//nav[@aria-label="Staff pages"]//a[contains(@href,"/admin/audit-logs")]')->length);
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Mobile staff pages"]//a[contains(@href,"/admin/audit-logs")]')->length);
        session()->remove(['isLoggedIn', 'role', 'user_id']);
    }

    public function testAdministrationPagesShareNavigationAndPreserveRolePermissions(): void
    {
        helper(['url', 'form']);
        foreach ([1, 2] as $role) {
            session()->set(['isLoggedIn' => true, 'role' => $role, 'name' => '<script>staff</script>']);
            foreach (['users', 'registrations', 'audit-logs'] as $page) {
                $html = view('partials/staff_navigation', ['staffPage' => $page, 'staffPageLabel' => 'Staff']);
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);
                $this->assertSame($role === 2 ? 1 : 0, $xpath->query('//aside//a[contains(@href,"/admin/users")]')->length);
                $this->assertSame(1, $xpath->query('//aside//a[contains(@href,"/admin/audit-logs")]')->length);
                $this->assertSame(1, $xpath->query('//aside//a[contains(@href,"/admin/pending-registrations")]')->length);
                $this->assertStringNotContainsString('<script>staff</script>', $html);
                $this->assertSame(1, $xpath->query('//form[contains(@action,"/logout")]')->length);
            }
        }
        session()->remove(['isLoggedIn', 'role', 'name']);
    }

    public function testManualConsentModalRendersCleanDialogOnlyForSuperAdmin(): void
    {
        helper(['url', 'form']);
        $link = 'https://studentshield.app/guardian-consent/abc123def456';

        try {
            // Counselor view must never expose manual consent modal even if flashdata exists.
            session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1, 'name' => 'Counselor']);
            session()->setFlashdata('manual_consent_link', $link);
            $counselorHtml = view('admin_pending_registrations', [
                'pendingUsers' => [],
                'registrationStatus' => 'pending',
                'canVerifyContact' => false,
            ]);
            $this->assertStringNotContainsString('id="manualConsentModal"', $counselorHtml);
            $this->assertStringNotContainsString($link, $counselorHtml);

            // Super Admin gets dedicated accessible modal outside the table wrapper, even if list is empty.
            session()->set(['isLoggedIn' => true, 'role' => 2, 'user_id' => 2, 'name' => 'Super Admin']);
            session()->setFlashdata('manual_consent_link', $link);
            session()->setFlashdata('manual_consent_meta', [
                'student_name' => 'Jc <script>alert(1)</script>',
                'guardian_name' => 'Maria Cruz',
                'phone' => '+639188564586',
                'expires_at' => 'Nov 16, 2026 9:13 AM',
            ]);
            $adminHtml = view('admin_pending_registrations', [
                'pendingUsers' => [],
                'registrationStatus' => 'pending',
                'canVerifyContact' => true,
            ]);
            $dom = new DOMDocument();
            @$dom->loadHTML($adminHtml);
            $xpath = new DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//*[@id="manualConsentModal"][@role="dialog"][@aria-modal="true"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="manual-consent-link"][@value="' . $link . '"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="copyConsentLinkBtn"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="copyConsentMessageBtn"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="manualConsentBanner"]')->length);
            $this->assertSame(0, $xpath->query('//*[contains(@class,"overflow-x-auto")]//*[contains(@class,"guardian-verification")]')->length);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $adminHtml);
            $this->assertStringContainsString('Maria Cruz', $adminHtml);
            $this->assertStringContainsString('+639188564586', $adminHtml);
            $this->assertStringContainsString('Nov 16, 2026 9:13 AM', $adminHtml);
        } finally {
            session()->remove(['isLoggedIn', 'role', 'user_id', 'name']);
        }
    }
}
