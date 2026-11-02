<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class PrincipalOverviewViewTest extends CIUnitTestCase
{
    public function testCompleteFiltersAndEmptyRiskReportRemainUsable(): void
    {
        helper(['url', 'form']);
        session()->set(['role' => 3, 'name' => 'Principal']);
        $filters = \App\Libraries\SchoolAnalytics::filters(['grade' => '<script>alert(1)</script>']);
        $report = ['filters' => $filters, 'metrics' => array_fill_keys(['registered', 'active', 'events', 'attention', 'today_count', 'week_count', 'month_count'], 0), 'change_percent' => null, 'previous_events' => 0, 'groups' => array_fill_keys(['trend', 'risk', 'category', 'grade', 'section'], [])];
        $html = view('principal_dashboard', ['report' => $report, 'options' => ['grades' => [], 'sections' => []]]);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        foreach (['period', 'start', 'end', 'grade', 'section', 'category', 'risk'] as $field) {
            $this->assertCount(1, $xpath->query('//form[@aria-label="Analytics filters"]//*[@name="' . $field . '"]'));
        }
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('No classified Safe Space entries in this period.', $html);
        $this->assertStringContainsString('No baseline', $html);
        $this->assertStringContainsString('86–100%', $html);
        $this->assertStringContainsString('eligible guardian notification attempts', $html);
        $this->assertStringContainsString('principal/reports/export?', $html);
        $this->assertStringNotContainsString('NAN', $html);
        $information = '//section[@aria-labelledby="reportInformationTitle"]';
        $this->assertCount(1, $xpath->query($information . '//h2[contains(.,"Additional information")]'));
        $this->assertCount(2, $xpath->query($information . '//details/summary'));
        $this->assertStringContainsString('not currently online', $xpath->query($information)->item(0)->textContent);
        $this->assertStringContainsString('Archived records are excluded', $xpath->query($information)->item(0)->textContent);
        session()->destroy();
    }
}
