<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class StudentNavigationViewTest extends CIUnitTestCase
{
    public function testEveryStudentWorkspaceHasDirectSecureSignOutAndPageLinks(): void
    {
        helper(['url', 'form']);
        foreach (['vent', 'report', 'reports'] as $page) {
            $dom = new \DOMDocument();
            @$dom->loadHTML(view('partials/student_vent_header', ['page' => $page]));
            $xpath = new \DOMXPath($dom);
            $logout = '//form[@method="POST"][contains(@action,"/logout")]';
            $this->assertCount(1, $xpath->query($logout));
            $this->assertCount(1, $xpath->query($logout . '/button[@type="submit"][contains(.,"Sign Out")]'));
            $this->assertCount(1, $xpath->query($logout . '/input[@name="' . csrf_token() . '"]'));
            $this->assertCount(0, $xpath->query('//details | //summary'));
            $this->assertCount(3, $xpath->query('//nav[@aria-label="Student page navigation"]/a'));
            $this->assertCount(1, $xpath->query('//nav/a[@aria-current="page"]'));
        }
    }

    public function testRegistrationRetainsRequiredFieldsValidationAndTerms(): void
    {
        helper(['url', 'form']);
        $dom = new \DOMDocument();
        @$dom->loadHTML(view('register'));
        $xpath = new \DOMXPath($dom);
        $form = '//form[@method="post"][contains(@action,"register/process")]';
        $this->assertCount(1, $xpath->query($form));
        $this->assertCount(1, $xpath->query($form . '/input[@name="' . csrf_token() . '"]'));
        foreach (['name', 'lrn', 'grade_level', 'section', 'parent_contact', 'password', 'password_confirm', 'terms'] as $name) {
            $this->assertCount(1, $xpath->query($form . '//input[@name="' . $name . '"][@required]'));
        }
        $this->assertCount(1, $xpath->query($form . '//input[@name="lrn"][@pattern="[0-9]{12}"][@maxlength="12"]'));
        $this->assertCount(1, $xpath->query('//*[@id="termsModal"]'));
        $this->assertCount(1, $xpath->query('//*[@id="terms-section-privacy"]'));
        $this->assertCount(1, $xpath->query($form . '//button[@type="submit"]'));
    }
}
