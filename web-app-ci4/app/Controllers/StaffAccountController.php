<?php
namespace App\Controllers;

use App\Libraries\RoleAccess;
use Config\Database;

class StaffAccountController extends BaseController
{
    private function allowed(): bool
    {
        return session()->get('isLoggedIn') && in_array((int) session()->get('role'), [1, 2, 3], true);
    }
    public function settings() { if (!$this->allowed()) return $this->forbidden(); return view('staff_preferences'); }
    public function profile() { return $this->page('profile'); }
    public function updateProfile()
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) session()->get('user_id');
        $db = Database::connect();
        $user = $db->table('users')->where('id', $id)->get()->getRowArray();
        if (!$user || !in_array((int) $user['role'], [1, 2, 3], true) || !RoleAccess::enabled($user)) return $this->forbidden();
        $name = trim((string) $this->request->getPost('name'));
        $phone = trim((string) $this->request->getPost('phone'));
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name)
            || ($phone !== '' && !preg_match('/^[+0-9() .-]{7,20}$/', $phone))) {
            return redirect()->to('/staff/profile')->with('error', 'Enter an account name and a valid phone number (or leave the phone blank).');
        }
        if (!password_verify((string) $this->request->getPost('current_password'), $user['password_hash'])) {
            return redirect()->to('/staff/profile')->with('error', 'Your current password is incorrect. No changes were saved.');
        }
        if ($db->table('users')->where('name', $name)->where('id !=', $id)->countAllResults()) {
            return redirect()->to('/staff/profile')->with('error', 'That account name is already in use. Choose another name.');
        }
        $table = (int) $user['role'] === 1 ? 'counselors' : 'admins';
        $phoneField = (int) $user['role'] === 1 ? 'counselor_phone' : 'phone';
        try {
            $db->transBegin();
            // Identity comes only from the session; never accept a target user or role from this form.
            if (!$db->table('users')->where('id', $id)->update(['name' => $name])) throw new \RuntimeException('Name update failed');
            $contacts = $db->table($table)->where('user_id', $id);
            $ok = $contacts->countAllResults() ? $db->table($table)->where('user_id', $id)->update([$phoneField => $phone])
                : $db->table($table)->insert(['user_id' => $id, $phoneField => $phone]);
            if (!$ok || !$db->transStatus()) throw new \RuntimeException('Profile update failed');
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Staff profile update failed for user {id}', ['id' => $id]);
            return redirect()->to('/staff/profile')->with('error', 'Your profile could not be saved. Please try again.');
        }
        session()->set('name', $name);
        $this->logAudit('UPDATE_PROFILE', 'user', $id);
        return redirect()->to('/staff/profile')->with('success', 'Profile saved. Use your updated account name the next time you sign in.');
    }

    public function changePassword()
    {
        if (!$this->allowed()) return $this->forbidden();
        $id = (int) session()->get('user_id');
        $db = Database::connect();
        $user = $db->table('users')->where('id', $id)->get()->getRowArray();
        if (!$user || !in_array((int) $user['role'], [1, 2, 3], true) || !RoleAccess::enabled($user)) {
            return $this->forbidden();
        }

        $current = (string) $this->request->getPost('current_password');
        $newPassword = (string) $this->request->getPost('new_password');
        $confirmPassword = (string) $this->request->getPost('new_password_confirm');

        if (!password_verify($current, (string) ($user['password_hash'] ?? ''))) {
            return redirect()->to('/staff/profile')->with('error', 'Your current password is incorrect. Password was not changed.');
        }
        if ($newPassword !== $confirmPassword) {
            return redirect()->to('/staff/profile')->with('error', 'New passwords do not match.');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72
            || !preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)
            || !preg_match('/[\W_]/', $newPassword)) {
            return redirect()->to('/staff/profile')->with('error', 'Use 8–72 characters with at least one uppercase letter, lowercase letter, number, and special character.');
        }
        if (password_verify($newPassword, (string) ($user['password_hash'] ?? ''))) {
            return redirect()->to('/staff/profile')->with('error', 'Choose a new password that is different from your current password.');
        }

        $newAuthVersion = (int) ($user['auth_version'] ?? 0) + 1;
        $updated = $db->table('users')->where('id', $id)->update([
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'remember_token' => null,
            'auth_version' => $newAuthVersion,
        ] + \App\Libraries\ManilaTime::stampFields($db, 'users', ['updated_at']));

        if (!$updated) {
            return redirect()->to('/staff/profile')->with('error', 'Your password could not be updated. Please try again.');
        }

        session()->set('auth_version', $newAuthVersion);
        $this->logAudit('PASSWORD_CHANGED', 'user', $id);
        return redirect()->to('/staff/profile')->with('success', 'Password updated successfully.');
    }
    private function page(string $page)
    {
        if (!$this->allowed()) return $this->forbidden();
        $user = Database::connect()->table('users')->select('id, name, role')->where('id', (int) session()->get('user_id'))->get()->getRowArray();
        if (!$user) return $this->forbidden();
        $contact = Database::connect()->table((int) $user['role'] === 1 ? 'counselors' : 'admins')->where('user_id', $user['id'])->get()->getRowArray() ?? [];
        $user['phone'] = $contact[(int) $user['role'] === 1 ? 'counselor_phone' : 'phone'] ?? '';
        $user['email'] = $contact[(int) $user['role'] === 1 ? 'counselor_email' : 'email'] ?? '';
        return view('staff_account', ['page' => $page, 'account' => $user, 'roleName' => RoleAccess::NAMES[(int) $user['role']]]);
    }
}
