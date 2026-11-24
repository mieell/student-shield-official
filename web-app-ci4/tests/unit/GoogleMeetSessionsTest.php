<?php
namespace Tests\Unit;

use App\Libraries\GoogleMeetSessions;
use CodeIgniter\Test\CIUnitTestCase;
use DomainException;

final class GoogleMeetSessionsTest extends CIUnitTestCase
{
    private function service(): array
    {
        $db = new MeetMemoryDatabase();
        return [new GoogleMeetSessions($db, static fn () => 1800000000), $db];
    }

    public function testPublicationWithoutRequestAndOwnership(): void
    {
        [$service, $db] = $this->service();
        $row = $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', true);
        $this->assertSame(1, $row['id']);
        $this->assertSame($row['meet_link'], $service->join(1, 10, 0));
        $this->assertSame($row['meet_link'], $service->join(1, 20, 1));
        foreach ([[11, 0], [21, 1], [21, 2], [10, 1], [20, 0]] as [$id, $role]) {
            try { $service->join(1, $id, $role); $this->fail('Unassigned user joined.'); }
            catch (DomainException $e) { $this->assertStringContainsString('not assigned', $e->getMessage()); }
        }
        try { $service->end(10, 21); $this->fail('Unassigned host ended session.'); }
        catch (DomainException $e) { $this->assertSame('active', $db->rows['virtual_meetings'][0]['status']); }
        $service->end(10, 20);
        $this->assertNull($service->status(10)['active']);
        $this->expectException(DomainException::class);
        $service->join(1, 10, 0);
    }

    public function testRequestIsIdempotentAndPublicationUpdatesActualRow(): void
    {
        [$service, $db] = $this->service();
        $service->request(10);
        $service->request(10);
        $this->assertCount(1, $db->rows['virtual_meetings']);
        $row = $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij/', true);
        $this->assertSame(1, $row['id']);
        $this->assertNull($service->status(10)['pending']);
        $this->assertCount(1, $db->rows['virtual_meetings']);
    }

    public function testEndedLinkCannotBeReusedForAnotherStudent(): void
    {
        [$service] = $this->service();
        $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', true);
        $service->end(10, 20);
        $this->expectException(DomainException::class);
        $service->publish(11, 20, 'https://meet.google.com/abc-defg-hij/', true);
    }

    public function testActiveSessionCannotBeOverwritten(): void
    {
        [$service] = $this->service();
        $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', true);
        $this->expectException(DomainException::class);
        $service->publish(10, 21, 'https://meet.google.com/xyz-abcd-efg', true);
    }

    public function testExpiryAndLegacyRoomsFailClosed(): void
    {
        [$service, $db] = $this->service();
        $row = $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', true);
        $row['created_at'] = date('Y-m-d H:i:s', 1800000000 - 7200);
        $this->assertFalse($service->isOpen($row));
        $db->rows['virtual_meetings'][0] = $row;
        $this->assertNull($service->status(10)['active']);
        $row['created_at'] = date('Y-m-d H:i:s', 1800000000);
        $row['meet_link'] = 'StudentShield_10_oldJitsiRoom';
        $this->assertFalse($service->isOpen($row));
        $this->expectException(DomainException::class);
        $service->join(1, 10, 0);
    }

    public function testDetachingAnUnavailableHostKeepsMeetingHistoryClosed(): void
    {
        [$service, $db] = $this->service();
        $row = $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', true);
        $row['legacy_counselor_id'] = $row['counselor_id'];
        $row['counselor_id'] = null;
        $db->rows['virtual_meetings'][0] = $row;
        $this->assertFalse($service->isOpen($row));
        $this->assertNull($service->status(10)['active']);
        $this->expectException(DomainException::class);
        $service->join($row['id'], 10, 0);
    }

    public function testInvalidUrlsCannotBecomeRedirects(): void
    {
        foreach (['https://evil.test/abc-defg-hij', 'https://meet.google.com.evil.test/abc-defg-hij',
            'https://meet.google.com@evil.test/abc-defg-hij', 'javascript:alert(1)',
            'https://meet.google.com/abc-defg-hij?redirect=evil', 'StudentShield_123'] as $url) {
            try { GoogleMeetSessions::normalizeLink($url); $this->fail('Unsafe URL accepted'); }
            catch (DomainException $e) { $this->assertStringContainsString('Google Meet link', $e->getMessage()); }
        }
    }

    public function testConfirmationRequiredAndLockFailureDoesNotWrite(): void
    {
        [$service, $db] = $this->service();
        try { $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', false); $this->fail(); }
        catch (DomainException $e) { $this->assertSame([], $db->rows['virtual_meetings']); }
        $db->lockAvailable = false;
        try { $service->request(10); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame([], $db->rows['virtual_meetings']); }
    }

    public function testCancellationAndFailedCommitRollback(): void
    {
        [$service, $db] = $this->service();
        $service->request(10);
        $service->cancelRequest(10);
        $this->assertNull($service->status(10)['pending']);
        $db->commitSucceeds = false;
        try { $service->publish(10, 20, 'https://meet.google.com/abc-defg-hij', true); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertNull($service->status(10)['active']); }
    }
}

/** In-memory query-builder double: no live database or network is used. */
final class MeetMemoryDatabase
{
    public array $rows = ['users' => [['id' => 10, 'role_id' => 0, 'is_active' => 1], ['id' => 11, 'role_id' => 0, 'is_active' => 1]], 'virtual_meetings' => []];
    public bool $lockAvailable = true;
    public bool $commitSucceeds = true;
    private array $snapshot = [];
    public int $lastId = 0;
    public function table($name) { return new MeetMemoryQuery($this, $name); }
    public function query($sql) { return new MeetMemoryResult([['acquired' => (int) $this->lockAvailable]]); }
    public function transBegin() { $this->snapshot = $this->rows; return true; }
    public function transStatus() { return true; }
    public function transCommit() { return $this->commitSucceeds; }
    public function transRollback() { $this->rows = $this->snapshot; }
    public function insertID() { return $this->lastId; }
}
final class MeetMemoryQuery
{
    private array $filters = [];
    private bool $descending = false;
    public function __construct(private MeetMemoryDatabase $db, private string $name) {}
    public function where($key, $value) { $this->filters[$key] = $value; return $this; }
    public function orderBy($key, $direction) { $this->descending = $direction === 'DESC'; return $this; }
    private function matches($row) { foreach ($this->filters as $key => $value) if (($row[$key] ?? null) != $value) return false; return true; }
    public function get() {
        $rows = array_values(array_filter($this->db->rows[$this->name], fn ($row) => $this->matches($row)));
        if ($this->descending) usort($rows, fn ($a, $b) => $b['id'] <=> $a['id']);
        return new MeetMemoryResult($rows);
    }
    public function insert($data) { $data['id'] = ++$this->db->lastId; $this->db->rows[$this->name][] = $data; return true; }
    public function update($data) { foreach ($this->db->rows[$this->name] as &$row) if ($this->matches($row)) $row = array_replace($row, $data); return true; }
}
final class MeetMemoryResult
{
    public function __construct(private array $rows) {}
    public function getRowArray() { return $this->rows[0] ?? null; }
    public function getResultArray() { return $this->rows; }
}
