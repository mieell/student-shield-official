<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class AccountsDirectoryViewTest extends CIUnitTestCase
{
    public function testDirectoryEscapesNamesAndPreservesProtectedAccountForms(): void
    {
        helper(['url', 'form']);
        session()->set(['role' => 2, 'user_id' => 2, 'name' => 'Preview admin']);
        $html = view('manage_accounts', ['users' => [
            ['id' => 2, 'name' => 'Own admin', 'role' => 2, 'access_disabled' => 0],
            ['id' => 5, 'name' => '<img src=x onerror=alert(1)>', 'role' => 0, 'access_disabled' => 0, 'registration_status' => 'approved', 'lrn_suffix' => '0041', 'parent_phone' => '+639123456789', 'parent_email' => 'parent@example.test'],
        ]]);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('admin/users/update/5', $html);
        $this->assertStringContainsString('admin/users/access/5', $html);
        $this->assertStringNotContainsString('admin/users/access/2', $html);
        $this->assertStringContainsString('••••••••0041', $html);
        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
        $this->assertStringContainsString('data-role-filter="0"', $html);
        $this->assertStringContainsString('id="accountInspector"', $html);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        foreach (['admin/users/create', 'admin/users/update/5'] as $action) {
            $form = '//form[contains(@action,"' . $action . '")]';
            $this->assertCount(1, $xpath->query($form . '//input[@name="parent_phone"][@type="tel"]'));
            $this->assertCount(1, $xpath->query($form . '//input[@name="parent_email"][@type="email"]'));
            $this->assertCount(1, $xpath->query($form . '//input[@name="phone"][@type="tel"]'));
            $this->assertCount(1, $xpath->query($form . '//input[@name="email"][@type="email"]'));
        }
        $this->assertCount(1, $xpath->query('//form[contains(@action,"admin/users/update/5")]//input[@name="parent_phone"][@value="+639123456789"]'));
        $this->assertCount(1, $xpath->query('//form[contains(@action,"admin/users/update/5")]//input[@name="remove_parent_phone"][@value="0"]'));
        $this->assertCount(2, $xpath->query('//form[contains(@action,"admin/users/update/5")]//button[@data-remove-contact][not(@hidden)]'));
        $this->assertCount(2, $xpath->query('//form[contains(@action,"admin/users/update/5")]//*[@data-contact-status][not(@hidden)][text()="Unverified"]'));
        session()->destroy();
    }
}
