<?php

use CodeIgniter\Test\CIUnitTestCase;

final class StudentReportLayoutTest extends CIUnitTestCase
{
    public function testReportViewsShowOnlyTheSelectedPanel(): void
    {
        helper(['url', 'form']);
        foreach ([false, true] as $history) {
            $dom = new DOMDocument();
            @$dom->loadHTML(view('reports/bullying_form', ['showReportHistory' => $history]));
            $xpath = new DOMXPath($dom);
            $this->assertSame($history ? 1 : 0, $xpath->query('//*[@id="bullyingForm"]/ancestor::*[@hidden]')->length);
            $this->assertSame($history ? 0 : 1, $xpath->query('//*[@id="my-reports"]/ancestor::*[@hidden]')->length);
            $this->assertSame(1, $xpath->query('//nav[@aria-label="Incident report views"]/a[@aria-current="page"]')->length);
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
        $this->assertSame(1, $xpath->query('//input[@name="bully_name"][@required]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="incident_details"][@maxlength="2000"][@required]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="evidence_file"][@type="file"][@accept="image/*,video/*"]')->length);
        $this->assertSame(1, $xpath->query('//button[@id="saveDraftBtn"][@type="button"]')->length);
        $this->assertSame(1, $xpath->query('//form[contains(@action,"/logout")]')->length);
        $this->assertSame(1, $xpath->query('//nav//a[contains(@href,"student/vent")]')->length);
    }
}
