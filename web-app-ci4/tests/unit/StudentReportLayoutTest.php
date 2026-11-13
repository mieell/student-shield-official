<?php

use CodeIgniter\Test\CIUnitTestCase;

final class StudentReportLayoutTest extends CIUnitTestCase
{
    public function testReadableDatesRetainTheOriginalAndEscapeMalformedValues(): void
    {
        helper('url');
        $html = view('reports/student_report_status', ['studentReports' => [
            ['id' => 1, 'status' => 'pending_review', 'created_at' => '2026-11-01 17:40:20'],
            ['id' => 2, 'status' => 'unknown', 'created_at' => '<script>alert(1)</script>'],
            ['id' => 3, 'status' => 'resolved', 'created_at' => '1970-01-01 00:00:00 UTC'],
        ]]);
        $this->assertStringContainsString('Nov 1, 2026 at 5:40 PM', $html);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $date = (new DOMXPath($dom))->query('//span[contains(@class,"student-report-date")]')->item(0);
        $this->assertSame('2026-11-01 17:40:20', $date->getAttribute('title'));
        $this->assertStringContainsString('student-report-status--pending_review', $html);
        $this->assertStringContainsString('Jan 1, 1970', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Awaiting update', $html);
        $this->assertStringNotContainsString('Your counselor has not updated this yet', $html);
    }

    public function testReportProgressReflectsOnlyRecordedStatus(): void
    {
        helper('url');
        foreach (['pending_review' => [3, 'Submitted'], 'verified' => [3, 'Under review'], 'resolved' => [3, 'Closed'], 'rejected_fake' => [2, 'Review completed'], 'unknown' => [0, '']] as $status => [$steps, $current]) {
            $dom = new DOMDocument();
            @$dom->loadHTML(view('reports/student_report_status', ['studentReports' => [['id' => 1, 'status' => $status, 'created_at' => '2026-11-01 10:00:00']]]));
            $xpath = new DOMXPath($dom);
            $this->assertSame($steps, $xpath->query('//ol[contains(@class,"report-progress")]/li')->length);
            $selected = $xpath->query('//ol/li[@aria-current="step"]/span[last()]');
            $this->assertSame($steps ? 1 : 0, $selected->length);
            if ($steps) $this->assertSame($current, trim($selected->item(0)->textContent));
            if ($status === 'rejected_fake') $this->assertSame(0, $xpath->query('//ol/li/span[text()="Closed"]')->length);
        }
    }

    public function testWorkspaceNavigationHasTheSameThreeDestinationsOnEveryPage(): void
    {
        helper(['url', 'form']);
        foreach (['vent', 'report', 'reports'] as $page) {
            $dom = new DOMDocument();
            @$dom->loadHTML(view('partials/student_vent_header', ['page' => $page]));
            $xpath = new DOMXPath($dom);
            $links = $xpath->query('//nav[@aria-label="Student page navigation"]/a');
            $this->assertCount(3, $links);
            $this->assertSame(['Safe Space', 'Report Bullying', 'My reports'], array_map(
                static fn ($link) => trim($link->textContent), iterator_to_array($links)
            ));
            $this->assertSame(1, $xpath->query('//nav/a[@aria-current="page"]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-student-back]')->length);
            $this->assertSame(1, $xpath->query('//a[contains(@class,"vent-brand")][contains(@href,"student/dashboard")]')->length);
        }
    }

    public function testReportViewsShowOnlyTheSelectedPanel(): void
    {
        helper(['url', 'form']);
        foreach ([false, true] as $history) {
            $dom = new DOMDocument();
            @$dom->loadHTML(view('reports/bullying_form', ['showReportHistory' => $history]));
            $xpath = new DOMXPath($dom);
            $this->assertSame($history ? 1 : 0, $xpath->query('//*[@id="bullyingForm"]/ancestor::*[@hidden]')->length);
            $this->assertSame($history ? 0 : 1, $xpath->query('//*[@id="my-reports"]/ancestor::*[@hidden]')->length);
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Student page navigation"]/a[@aria-current="page"]')->length);
        }
    }

    public function testReportLayoutPreservesNativeUploadAndReporterContracts(): void
    {
        helper(['url', 'form']);
        $dom = new DOMDocument();
        @$dom->loadHTML(view('reports/bullying_form'));
        $xpath = new DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//form[@id="bullyingForm"][@method="POST"][@enctype="multipart/form-data"][contains(@action,"report-bullying/submit")]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="bullyingForm"]//input[@name="' . csrf_token() . '"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="reporter_type"][@value="self"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-role="self"][@aria-pressed="true"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-role="bystander"][@aria-pressed="false"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="victim_name"][not(@required)]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="bully_student_id"][@required]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="victim_student_id"][not(@required)]')->length);
        $this->assertSame(1, $xpath->query('//select[@name="bully_student_id"]/option[@value="unknown"]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="incident_details"][@maxlength="2000"][@required]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="evidence_file"][@type="file"][@accept="image/*,video/*"]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="saveDraftBtn"][@type="button"]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@action,"/logout")]')->length);
        $this->assertSame(1, $xpath->query('//nav//a[contains(@href,"student/vent")]')->length);
    }
}
