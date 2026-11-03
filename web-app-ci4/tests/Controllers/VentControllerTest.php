<?php

namespace App\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\ControllerTestTrait;
use Config\Services;

/**
 * @internal
 */
final class VentControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure user_id is not set in session
        $session = Services::session();
        $session->remove('user_id');
    }

    public function testSubmitUnauthenticatedRedirectsToLogin(): void
    {
        $result = $this->controller(VentController::class)
                       ->execute('submit');

        $this->assertTrue($result->isRedirect());
        $this->assertStringContainsString('/login', $result->getRedirectUrl() ?? '');

        $session = Services::session();
        $this->assertSame('Authentication required. Please log in.', $session->getFlashdata('error'));
    }

    public function testSubmitUnauthenticatedAjaxReturns401Json(): void
    {
        $request = Services::request();
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');

        $result = $this->withRequest($request)
                       ->controller(VentController::class)
                       ->execute('submit');

        $this->assertSame(401, $result->response()->getStatusCode());

        $json = json_decode($result->response()->getBody(), true);
        $this->assertIsArray($json);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Authentication required.', $json['message']);
    }
}
