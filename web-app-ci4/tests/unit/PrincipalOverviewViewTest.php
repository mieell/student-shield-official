<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class PrincipalOverviewViewTest extends CIUnitTestCase
{
    public function testWorkbookKeepsFilterTextLiteralAndCountsNumeric(): void
    {
        $filters = \App\Libraries\SchoolAnalytics::filters(['section' => '=1+2']);
        $report = [
            'filters' => $filters, 'metrics' => array_fill_keys(['registered', 'active', 'events', 'attention', 'today_count', 'week_count', 'month_count'], 0),
            'change_percent' => -25.0, 'previous_events' => 4,
            'groups' => ['trend' => [['label' => $filters['start'], 'total' => 3]], 'risk' => [], 'category' => [], 'grade' => [], 'section' => []],
        ];
        $path = WRITEPATH . 'cache/test-summary-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            file_put_contents($path, \App\Libraries\SchoolSummaryWorkbook::build($report));
            $zip = new \PharData($path);
            $summary = simplexml_load_string($zip['xl/worksheets/sheet1.xml']->getContent());
            $summary->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame('=1+2', (string) $summary->xpath('//s:c[@r="B6" and @t="inlineStr"]//s:t')[0]);
            $this->assertSame('-0.25', (string) $summary->xpath('//s:c[@s="5"]/s:v')[0]);
            $this->assertCount(0, $summary->xpath('//s:f'));
            $this->assertStringContainsString('<v>3</v>', $zip['xl/worksheets/sheet2.xml']->getContent());
            $this->assertStringContainsString('No matching events', $zip['xl/worksheets/sheet3.xml']->getContent());
        } finally {
            unset($zip);
            if (is_file($path)) unlink($path);
        }
    }

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
