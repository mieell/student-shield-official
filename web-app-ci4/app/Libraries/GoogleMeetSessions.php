<?php
namespace App\Libraries;

use DomainException;
use RuntimeException;

/** Google Meet publication and participant access; no Google API credentials required. */
class GoogleMeetSessions
{
    public const LIFETIME_SECONDS = 7200;
    private $db;
    private $clock;

    public function __construct($db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? static fn () => time();
    }

    public static function normalizeLink(string $input): string
    {
        // Validate URL format only; this does not verify that Google created the meeting.
        $input = trim($input);
        if (!preg_match('~\Ahttps://meet\.google\.com/([a-z]{3}-[a-z]{4}-[a-z]{3})/?\z~D', $input, $match)) {
            throw new DomainException('Paste a Google Meet link in the form https://meet.google.com/abc-defg-hij, without extra parameters.');
        }
        return 'https://meet.google.com/' . $match[1];
    }

    public function isOpen(array $row): bool
    {
        try {
            self::normalizeLink((string) ($row['meet_link'] ?? ''));
        } catch (DomainException $e) {
            return false;
        }
        $created = ManilaTime::unix($row['created_at'] ?? '');
        $now = ($this->clock)();
        return ($row['status'] ?? '') === 'active' && $created !== null
            && $created <= $now && $created > $now - self::LIFETIME_SECONDS;
    }

    public function status(int $studentId): array
    {
        $active = $this->db->table('virtual_meetings')->where('student_id', $studentId)
            ->where('status', 'active')->orderBy('id', 'DESC')->get()->getRowArray();
        $pending = $this->db->table('virtual_meetings')->where('student_id', $studentId)
            ->where('status', 'requested')->get()->getRowArray();
        return ['active' => $active && $this->isOpen($active) ? $active : null, 'pending' => $pending];
    }

    public function request(int $studentId): void
    {
        $this->locked(function () use ($studentId) {
            $this->retireStale($studentId);
            $state = $this->status($studentId);
            if ($state['active'] || $state['pending']) return;
            $this->db->table('virtual_meetings')->insert([
                'student_id' => $studentId, 'counselor_id' => null, 'meet_link' => '',
                'status' => 'requested', 'created_at' => ManilaTime::now('Y-m-d H:i:s', ($this->clock)()),
            ]);
        });
    }

    public function cancelRequest(int $studentId): void
    {
        $this->locked(function () use ($studentId) {
            $this->db->table('virtual_meetings')->where('student_id', $studentId)
                ->where('status', 'requested')->update(['status' => 'cancelled']);
        });
    }

    public function publish(int $studentId, int $hostId, string $input, bool $confirmed): array
    {
        $link = self::normalizeLink($input);
        if (!$confirmed) throw new DomainException('Confirm the Google Meet host and admission settings before publishing.');
        return $this->locked(function () use ($studentId, $hostId, $link) {
            $student = $this->db->table('users')->where('id', $studentId)->where('role', 0)
                ->where('is_active', 1)->get()->getRowArray();
            if (!$student) throw new DomainException('An active student account is required.');
            $this->retireStale($studentId);
            $state = $this->status($studentId);
            if ($state['active']) throw new DomainException('This student already has an active session. End it before publishing another.');
            // Keep ended rows: they are the record that prevents application-level link reuse.
            if ($this->db->table('virtual_meetings')->where('meet_link', $link)->get()->getRowArray()) {
                throw new DomainException('This Meet link was already used. Create a new meeting for each counseling session.');
            }
            $data = ['student_id' => $studentId, 'counselor_id' => $hostId, 'meet_link' => $link,
                'status' => 'active', 'created_at' => ManilaTime::now('Y-m-d H:i:s', ($this->clock)())];
            if ($state['pending']) {
                $id = (int) $state['pending']['id'];
                $this->db->table('virtual_meetings')->where('id', $id)->update($data);
                $this->db->table('virtual_meetings')->where('student_id', $studentId)
                    ->where('status', 'requested')->update(['status' => 'cancelled']);
            } else {
                $this->db->table('virtual_meetings')->insert($data);
                $id = (int) $this->db->insertID();
            }
            return $data + ['id' => $id];
        });
    }

    public function join(int $meetingId, int $userId, int $role): string
    {
        // This application gate checks assignment before redirecting. Google Meet Restricted admission
        // remains necessary because a forwarded Google URL can bypass this redirect.
        $row = $this->db->table('virtual_meetings')->where('id', $meetingId)->get()->getRowArray();
        $allowed = $row && (($role === 0 && (int) $row['student_id'] === $userId)
            || ($role === 1 && (int) $row['counselor_id'] === $userId));
        if (!$allowed || !$this->isOpen($row)) throw new DomainException('This session is unavailable or is not assigned to you.');
        return self::normalizeLink($row['meet_link']);
    }

    public function end(int $studentId, int $hostId): void
    {
        $this->locked(function () use ($studentId, $hostId) {
            $active = $this->db->table('virtual_meetings')->where('student_id', $studentId)
                ->where('status', 'active')->get()->getRowArray();
            if (!$active || (int) $active['counselor_id'] !== $hostId) {
                throw new DomainException('Only the assigned host can end this session.');
            }
            $this->db->table('virtual_meetings')->where('id', $active['id'])->update(['status' => 'ended']);
        });
    }

    private function retireStale(int $studentId): void
    {
        $rows = $this->db->table('virtual_meetings')->where('student_id', $studentId)
            ->where('status', 'active')->get()->getResultArray();
        foreach ($rows as $row) {
            if (!$this->isOpen($row)) $this->db->table('virtual_meetings')->where('id', $row['id'])->update(['status' => 'ended']);
        }
    }

    private function locked(callable $operation)
    {
        // MySQL/MariaDB advisory lock serializes publication across all hosts and students.
        // This prevents concurrent requests from assigning the same link to different students.
        $lock = $this->db->query("SELECT GET_LOCK('studentshield_meeting_publish', 5) AS acquired")->getRowArray();
        if ((int) ($lock['acquired'] ?? 0) !== 1) throw new RuntimeException('Meeting service busy. Try again.');
        try {
            if (!$this->db->transBegin()) throw new RuntimeException('Meeting transaction could not start.');
            $result = $operation();
            if (!$this->db->transStatus()) throw new RuntimeException('Meeting could not be saved.');
            if (!$this->db->transCommit()) throw new RuntimeException('Meeting transaction could not commit.');
            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('studentshield_meeting_publish')");
        }
    }
}
