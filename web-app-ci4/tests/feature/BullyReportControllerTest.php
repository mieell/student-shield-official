<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\Request;
use Config\Services;

class TestUploadedFile extends UploadedFile
{
    public function isValid(): bool
    {
        return ($this->error ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK && file_exists($this->path);
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $targetPath = rtrim($targetPath, '/') . '/';
        $this->setPath($targetPath);

        if ($this->hasMoved) {
            throw HTTPException::forAlreadyMoved();
        }

        $name ??= $this->getName();
        $destination = $overwrite ? $targetPath . $name : $this->getDestination($targetPath . $name);

        $this->hasMoved = copy($this->path, $destination);
        if ($this->hasMoved) {
            @unlink($this->path);
            $this->path = $targetPath;
            $this->name = basename($destination);
            return true;
        }

        return false;
    }
}

/**
 * @internal
 */
final class BullyReportControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait {
        populateGlobals as parentPopulateGlobals;
    }
    use DatabaseTestTrait;

    protected array $uploadedFiles = [];

    public function withFiles(array $files): self
    {
        $this->uploadedFiles = $files;
        return $this;
    }

    protected function populateGlobals(string $name, Request $request, ?array $params = null)
    {
        $request = $this->parentPopulateGlobals($name, $request, $params);
        if (!empty($this->uploadedFiles) && $request instanceof \CodeIgniter\HTTP\IncomingRequest) {
            $collection = new FileCollection();
            $this->setPrivateProperty($collection, 'files', $this->uploadedFiles);
            $this->setPrivateProperty($request, 'files', $collection);
            $this->uploadedFiles = [];
        }
        return $request;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure routes are configured for testing submit with GET and POST
        $routes = Services::routes();
        $routes->get('/report/bullying', 'BullyReportController::index');
        $routes->get('/report/bullying/submit', 'BullyReportController::submit');
        $routes->post('/report/bullying/submit', 'BullyReportController::submit');

        // Set up test database tables
        $db = \Config\Database::connect();
        $db->setPrefix('');

        $db->query("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role INTEGER DEFAULT 0,
            is_locked INTEGER DEFAULT 0,
            remember_token TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS students (
            user_id INTEGER PRIMARY KEY,
            student_id TEXT,
            parent_phone TEXT,
            parent_email TEXT,
            section TEXT,
            adviser_name TEXT,
            grade_level TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS counselors (
            user_id INTEGER PRIMARY KEY,
            counselor_email TEXT,
            counselor_phone TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS admins (
            user_id INTEGER PRIMARY KEY,
            email TEXT,
            phone TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )");

        $db->query("CREATE TABLE IF NOT EXISTS bully_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reporter_student_id INTEGER,
            reporter_type TEXT DEFAULT 'self',
            victim_student_id INTEGER NULL,
            bully_student_id INTEGER NULL,
            incident_details TEXT,
            evidence_file TEXT NULL,
            status TEXT DEFAULT 'pending_review',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )");

        // Clear existing test data
        $db->table('bully_reports')->truncate();
        $db->table('students')->truncate();
        $db->table('counselors')->truncate();
        $db->table('admins')->truncate();
        $db->table('users')->truncate();
    }

    public function testSubmitNonPostRedirectsToForm(): void
    {
        $result = $this->withSession(['user_id' => 1, 'isLoggedIn' => true])->get('/report/bullying/submit');
        $result->assertRedirectTo('/report/bullying');
    }

    public function testSubmitUnauthenticatedRedirectsToLogin(): void
    {
        $result = $this->post('/report/bullying/submit', [
            'reporter_type'    => 'self',
            'incident_details' => 'Test details',
        ]);

        $result->assertRedirectTo('/login');
        $result->assertSessionHas('error', 'Please log in to access StudentShield.');
    }

    public function testSubmitLockedUserRedirectsToDashboard(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'            => 10,
            'name'          => 'Locked Student',
            'password_hash' => 'hash',
            'role'          => 0,
            'is_locked'     => 1,
        ]);

        $result = $this->withSession([
            'user_id'    => 10,
            'isLoggedIn' => true,
        ])->post('/report/bullying/submit', [
            'reporter_type'    => 'self',
            'incident_details' => 'Test details',
        ]);

        $result->assertRedirectTo('/student/dashboard');
        $result->assertSessionHas('error', 'Your reporting privileges have been suspended.');
    }

    public function testSubmitRateLimitedRedirectsBack(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'            => 1,
            'name'          => 'Active Student',
            'password_hash' => 'hash',
            'role'          => 0,
            'is_locked'     => 0,
        ]);

        $result = $this->withSession([
            'user_id'                 => 1,
            'isLoggedIn'             => true,
            'last_bully_report_time' => time(),
        ])->post('/report/bullying/submit', [
            'reporter_type'    => 'self',
            'incident_details' => 'Test details',
        ]);

        $result->assertStatus(302);
        $result->assertSessionHas('error');
    }

    public function testSubmitSuccessfulSelfReport(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'            => 1,
            'name'          => 'Active Student',
            'password_hash' => 'hash',
            'role'          => 0,
            'is_locked'     => 0,
        ]);

        $result = $this->withSession([
            'user_id'    => 1,
            'isLoggedIn' => true,
        ])->post('/report/bullying/submit', [
            'reporter_type'    => 'self',
            'victim_name'      => '',
            'bully_name'       => 'John Doe',
            'incident_details' => 'Pushed me during recess.',
            'incident_type'    => 'Physical',
        ]);

        $result->assertRedirectTo('/report/bullying');
        $result->assertSessionHas('message', 'Your report has been securely submitted. A school counselor has been alerted and will review it shortly.');

        $report = $db->table('bully_reports')->where('reporter_student_id', 1)->get()->getRowArray();
        $this->assertNotNull($report);
        $this->assertEquals('self', $report['reporter_type']);
        $this->assertEquals(1, $report['victim_student_id']);
        $this->assertEquals('pending_review', $report['status']);

        // Decrypt details and verify
        $encrypter = Services::encrypter();
        $decrypted = $encrypter->decrypt(hex2bin($report['incident_details']));
        $this->assertStringContainsString('INCIDENT CATEGORY: Physical', $decrypted);
        $this->assertStringContainsString('Pushed me during recess.', $decrypted);
    }

    public function testSubmitBystanderReportResolvesVictimAndBully(): void
    {
        $db = \Config\Database::connect();

        $db->table('users')->insertBatch([
            ['id' => 1, 'name' => 'Reporter Student', 'password_hash' => 'hash', 'role' => 0, 'is_locked' => 0],
            ['id' => 2, 'name' => 'Jane Victim', 'password_hash' => 'hash', 'role' => 0, 'is_locked' => 0],
            ['id' => 3, 'name' => 'Bob Bully', 'password_hash' => 'hash', 'role' => 0, 'is_locked' => 0],
        ]);

        $result = $this->withSession([
            'user_id'    => 1,
            'isLoggedIn' => true,
        ])->post('/report/bullying/submit', [
            'reporter_type'    => 'bystander',
            'victim_name'      => 'Jane Victim',
            'bully_name'       => 'Bob Bully',
            'incident_details' => 'I witnessed verbal bullying in class.',
        ]);

        $result->assertRedirectTo('/report/bullying');

        $report = $db->table('bully_reports')->where('reporter_student_id', 1)->get()->getRowArray();
        $this->assertNotNull($report);
        $this->assertEquals('bystander', $report['reporter_type']);
        $this->assertEquals(2, $report['victim_student_id']);
        $this->assertEquals(3, $report['bully_student_id']);
    }

    public function testSubmitBystanderReportMultipleBullyNamesDoesNotResolveBullyId(): void
    {
        $db = \Config\Database::connect();

        $db->table('users')->insertBatch([
            ['id' => 1, 'name' => 'Reporter Student', 'password_hash' => 'hash', 'role' => 0, 'is_locked' => 0],
            ['id' => 2, 'name' => 'Jane Victim', 'password_hash' => 'hash', 'role' => 0, 'is_locked' => 0],
        ]);

        $result = $this->withSession([
            'user_id'    => 1,
            'isLoggedIn' => true,
        ])->post('/report/bullying/submit', [
            'reporter_type'    => 'bystander',
            'victim_name'      => 'Jane Victim',
            'bully_name'       => 'Bob Bully, Charlie Aggressor',
            'incident_details' => 'A group was picking on Jane.',
        ]);

        $result->assertRedirectTo('/report/bullying');

        $report = $db->table('bully_reports')->where('reporter_student_id', 1)->get()->getRowArray();
        $this->assertNotNull($report);
        $this->assertEquals(2, $report['victim_student_id']);
        $this->assertNull($report['bully_student_id']);
    }

    public function testSubmitWithInvalidEvidenceFileFailsValidation(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'            => 1,
            'name'          => 'Active Student',
            'password_hash' => 'hash',
            'role'          => 0,
            'is_locked'     => 0,
        ]);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'test_file_') . '.txt';
        file_put_contents($tempFilePath, 'disallowed content');

        $file = new TestUploadedFile(
            $tempFilePath,
            'test.txt',
            'text/plain',
            filesize($tempFilePath),
            UPLOAD_ERR_OK
        );

        $result = $this->withFiles(['evidence_file' => $file])
            ->withSession([
                'user_id'    => 1,
                'isLoggedIn' => true,
            ])->post('/report/bullying/submit', [
                'reporter_type'    => 'self',
                'incident_details' => 'Details',
            ]);

        $result->assertStatus(302);
        $result->assertSessionHas('error');

        if (file_exists($tempFilePath)) {
            @unlink($tempFilePath);
        }
    }

    public function testSubmitWithValidEvidenceFile(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'            => 1,
            'name'          => 'Active Student',
            'password_hash' => 'hash',
            'role'          => 0,
            'is_locked'     => 0,
        ]);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        file_put_contents($tempFilePath, $pngContent);

        $file = new TestUploadedFile(
            $tempFilePath,
            'evidence.png',
            'image/png',
            filesize($tempFilePath),
            UPLOAD_ERR_OK
        );

        $result = $this->withFiles(['evidence_file' => $file])
            ->withSession([
                'user_id'    => 1,
                'isLoggedIn' => true,
            ])->post('/report/bullying/submit', [
                'reporter_type'    => 'self',
                'incident_details' => 'Incident with image proof.',
            ]);

        $result->assertRedirectTo('/report/bullying');

        $report = $db->table('bully_reports')->where('reporter_student_id', 1)->get()->getRowArray();
        $this->assertNotNull($report);
        $this->assertNotEmpty($report['evidence_file']);
        $this->assertStringStartsWith('evidence/', $report['evidence_file']);

        // Clean up uploaded test file
        $uploadedFile = WRITEPATH . 'uploads/' . $report['evidence_file'];
        if (file_exists($uploadedFile)) {
            @unlink($uploadedFile);
        }
        if (file_exists($tempFilePath)) {
            @unlink($tempFilePath);
        }
    }

    public function testSubmitDatabaseErrorHandledGracefully(): void
    {
        $db = \Config\Database::connect();
        $db->table('users')->insert([
            'id'            => 1,
            'name'          => 'Active Student',
            'password_hash' => 'hash',
            'role'          => 0,
            'is_locked'     => 0,
        ]);

        // Drop the bully_reports table to force database failure
        $db->query("DROP TABLE bully_reports");

        $result = $this->withSession([
            'user_id'    => 1,
            'isLoggedIn' => true,
        ])->post('/report/bullying/submit', [
            'reporter_type'    => 'self',
            'incident_details' => 'Details causing DB error',
        ]);

        $result->assertStatus(302);
        $result->assertSessionHas('error', 'Submission failed due to a system error. Please try again.');
    }
}
