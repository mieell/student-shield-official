<?php
namespace App\Controllers;

use App\Libraries\SchoolAnalytics;
use App\Libraries\SchoolSummaryWorkbook;

class PrincipalController extends BaseController
{
    private function report(): array
    {
        $filters = SchoolAnalytics::filters($this->request->getGet());
        return (new SchoolAnalytics(\Config\Database::connect()))->summary($filters);
    }

    public function index()
    {
        if (!$this->hasRole(3)) return $this->forbidden();
        return $this->overview();
    }

    public function counselorReports()
    {
        if (!$this->hasRole(1)) return $this->forbidden();
        return redirect()->to(site_url('admin/analytics'))->setHeader('Cache-Control', 'private, no-store');
    }

    private function overview()
    {
        try {
            $report = $this->report();
            $options = (new SchoolAnalytics(\Config\Database::connect()))->options();
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setBody(view('principal_dashboard', compact('report', 'options')));
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setContentType('text/plain')->setBody($e->getMessage());
        }
    }

    public function analytics()
    {
        if (!$this->hasRole(3)) return $this->forbidden();
        try { return $this->response->setHeader('Cache-Control', 'private, no-store')->setJSON($this->report()); }
        catch (\InvalidArgumentException $e) { return $this->response->setStatusCode(422)->setJSON(['message' => $e->getMessage()]); }
    }

    public function export()
    {
        if (!$this->hasRole(3)) return $this->forbidden();
        return $this->exportSummary();
    }

    public function counselorExport()
    {
        if (!$this->hasRole(1)) return $this->forbidden();
        return $this->exportSummary();
    }

    private function exportSummary()
    {
        try { $report = $this->report(); }
        catch (\InvalidArgumentException $e) { return $this->response->setStatusCode(422)->setJSON(['message' => $e->getMessage()]); }
        try {
            $workbook = SchoolSummaryWorkbook::build($report);
        } catch (\Throwable $e) {
            log_message('error', 'School summary workbook export failed: {message}', ['message' => $e->getMessage()]);
            return $this->response->setStatusCode(503)->setContentType('text/plain')->setBody('The Excel report could not be generated. Try again later.');
        }
        $filename = 'StudentShield-Summary-' . $report['filters']['start'] . '-to-' . $report['filters']['end'] . '.xlsx';
        $this->logAudit('EXPORT_AGGREGATE_REPORT', 'system', 0);
        return $this->response->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setBody($workbook);
    }
}
