<?php
namespace App\Controllers;

class UserController extends BaseController
{
    public function index()
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        // Account and guardian contacts are available only to Super Admins.
        $users = \Config\Database::connect()->table('users')
            ->select('id, name, role_id AS role, access_disabled, is_locked, registration_status, created_at')
            ->select("CASE WHEN role_id = 0 THEN student_id END AS student_id,
                CASE WHEN role_id = 0 THEN grade_level END AS grade_level,
                CASE WHEN role_id = 0 THEN section END AS section,
                CASE WHEN role_id = 0 THEN adviser_name END AS adviser_name,
                CASE WHEN role_id = 0 THEN parent_phone END AS parent_phone,
                CASE WHEN role_id = 0 THEN parent_email END AS parent_email,
                CASE WHEN role_id = 0 THEN SUBSTR(lrn, -4) END AS lrn_suffix,
                CASE WHEN role_id <> 0 THEN phone END AS phone,
                CASE WHEN role_id <> 0 THEN email END AS email", false)
            ->orderBy('role_id', 'DESC')->orderBy('name')->get()->getResultArray();
        return view('manage_accounts', ['users' => $users]);
    }

    public function create()
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        return $this->save();
    }

    public function update($id)
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        return $this->save((int) $id);
    }

    private function save(?int $id = null)
    {
        $db = \Config\Database::connect();
        $old = $id ? $db->table('users')->where('id', $id)->get()->getRowArray() : null;
        if ($id && !$old) return $this->response->setStatusCode(404);
        $name = trim((string) $this->request->getPost('name'));
        if ($old && (int) $old['role_id'] === 0) $name = $old['name'];
        $rawRole = (string) $this->request->getPost('role');
        $password = (string) $this->request->getPost('passcode');
        if (!in_array($rawRole, ['0', '1', '2', '3'], true) || $name === '' || mb_strlen($name) > 255) {
            return redirect()->to('/admin/users')->with('error', 'Enter a valid account name and role.');
        }
        $role = (int) $rawRole;
        $email = $this->request->getPost($role === 0 ? 'parent_email' : 'email');
        $phone = $this->request->getPost($role === 0 ? 'parent_phone' : 'phone');
        if (($email !== null && !is_string($email)) || ($phone !== null && !is_string($phone))) {
            return redirect()->to('/admin/users')->with('error', 'Enter a valid email and mobile number.');
        }
        $email = trim($email ?? '');
        $phone = preg_replace('/[\s()\-]/', '', trim($phone ?? ''));
        if ($email !== '' && (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return redirect()->to('/admin/users')->with('error', 'Enter a valid contact email or leave it blank.');
        }
        if ($phone !== '' && !preg_match('/\A(?:09|\+?639)\d{9}\z/D', $phone)) {
            return redirect()->to('/admin/users')->with('error', 'Enter a Philippine mobile number such as 09123456789 or +639123456789, or leave it blank.');
        }
        if (str_starts_with($phone, '09')) $phone = '+63' . substr($phone, 1);
        if (str_starts_with($phone, '639')) $phone = '+' . $phone;
        $removeEmail = $this->request->getPost($role === 0 ? 'remove_parent_email' : 'remove_email');
        $removePhone = $this->request->getPost($role === 0 ? 'remove_parent_phone' : 'remove_phone');
        if (!in_array($removeEmail, [null, '0', '1'], true) || !in_array($removePhone, [null, '0', '1'], true)
            || ($removeEmail === '1' && $email !== '') || ($removePhone === '1' && $phone !== '')) {
            return redirect()->to('/admin/users')->with('error', 'Choose either a replacement contact or removal.');
        }
        $removeEmail = $removeEmail === '1';
        $removePhone = $removePhone === '1';
        if ($id === null || $password !== '') {
            if (strlen($password) < 8 || strlen($password) > 72
                || !preg_match('/[A-Z]/', $password)
                || !preg_match('/[a-z]/', $password)
                || !preg_match('/[0-9]/', $password)
                || !preg_match('/[\W_]/', $password)) {
                return redirect()->to('/admin/users')->with('error', 'Use 8–72 characters with at least one uppercase letter, lowercase letter, number, and special character.');
            }
        }
        if ($id === (int) session()->get('user_id') && $role !== 2) return redirect()->to('/admin/users')->with('error', 'You cannot demote your own account.');
        $duplicate = $db->table('users')->where('name', $name);
        if ($id) $duplicate->where('id !=', $id);
        if ($duplicate->countAllResults()) return redirect()->to('/admin/users')->with('error', 'Account name already exists.');
        $data = ['name' => $name, 'role_id' => $role, 'remember_token' => null,
            'auth_version' => (int) ($old['auth_version'] ?? 0) + 1];
        if ($password !== '') $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        if (!$old || ((int) $old['role_id'] !== $role && $role === 0)) {
            $data['registration_status'] = $role === 0 ? 'pending' : 'approved';
            $data['is_active'] = $role === 0 ? 0 : 1;
        }
        try {
            \App\Libraries\ManilaTime::syncDatabaseSession($db);
            $db->transStart();
            // Read the same account under lock so simultaneous edits use current contacts.
            $saved = $old;
            if ($id && $db->DBDriver === 'MySQLi') {
                $saved = $db->query('SELECT * FROM users WHERE id = ? FOR UPDATE', [$id])->getRowArray();
                if (!$saved || (int) $saved['auth_version'] !== (int) $old['auth_version']) {
                    $db->transRollback();
                    return redirect()->to('/admin/users')->with('error', 'This account changed. Review its details and try again.');
                }
            }
            $emailField = $role === 0 ? 'parent_email' : 'email';
            $phoneField = $role === 0 ? 'parent_phone' : 'phone';
            $finalEmail = $removeEmail ? '' : ($email !== '' ? $email : trim((string) ($saved[$emailField] ?? '')));
            $finalPhone = $removePhone ? '' : ($phone !== '' ? $phone : trim((string) ($saved[$phoneField] ?? '')));
            $validEmail = $finalEmail !== '' && filter_var($finalEmail, FILTER_VALIDATE_EMAIL) !== false;
            $validPhone = preg_match('/\A(?:09|\+?639)\d{9}\z/D', preg_replace('/[\s()\-]/', '', $finalPhone)) === 1;
            if (($role === 0 && !$validEmail && !$validPhone) || ($role !== 0 && !$validEmail)) {
                $db->transRollback();
                return redirect()->to('/admin/users')->with('error', $role === 0
                    ? 'Keep at least one valid parent or guardian phone number or email.'
                    : 'A valid staff email is required for OTP verification.');
            }
            $contactsChanged = $finalEmail !== (string) ($saved[$emailField] ?? '')
                || $finalPhone !== (string) ($saved[$phoneField] ?? '');
            if ($id && $role === 0 && $contactsChanged && $db->table('guardian_consents')
                ->where('user_id', $id)->where('response', 'accepted')->countAllResults()) {
                $db->transRollback();
                return redirect()->to('/admin/users')->with('error', 'This student has a verified guardian contact. Refer any changes to the Guidance Office.');
            }
            if ($removeEmail || $email !== '') $data[$emailField] = $removeEmail ? null : $email;
            if ($removePhone || $phone !== '') $data[$phoneField] = $removePhone ? null : $phone;
            if ($id && $role === 0 && (int) $saved['role_id'] !== 0 && empty($saved['student_id'])) {
                $data['student_id'] = 'SS-' . $id;
            }
            if ($id) {
                $db->table('users')->where('id', $id)->update($data + \App\Libraries\ManilaTime::stampFields($db, 'users', ['updated_at']));
            } else {
                $userInsert = $data + \App\Libraries\ManilaTime::stampFields($db, 'users', ['created_at']);
                if (!$db->table('users')->insert($userInsert)) throw new \RuntimeException('Account insert failed');
                $id = (int) $db->insertID();
                if ($id < 1) throw new \RuntimeException('Account ID not generated');
            }
            if (!$old && $role === 0) {
                $db->table('users')->where('id', $id)->update(['student_id' => 'SS-' . $id]);
            }
            $db->transComplete();
            if (!$db->transStatus()) throw new \RuntimeException('Account transaction failed');
            $this->logAudit(!$old ? 'CREATE_USER' : ((int) $old['role_id'] !== $role ? 'ROLE_CHANGED' : ($password !== '' ? 'PASSWORD_RESET' : 'UPDATE_USER')), 'user', $id);
            if ($old && $contactsChanged) {
                $this->logAudit($removeEmail || $removePhone ? 'CONTACTS_REMOVED' : 'CONTACTS_UPDATED', 'user', $id);
            }
            if ($id === (int) session()->get('user_id')) session()->set('auth_version', $data['auth_version']);
            return redirect()->to('/admin/users')->with('success', 'Account saved. Student accounts require counselor approval.');
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Account update failed for user {id}', ['id' => $id]);
            return redirect()->to('/admin/users')->with('error', 'Account could not be saved.');
        }
    }

    public function access($id)
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        if ((int) $id === (int) session()->get('user_id')) return $this->response->setStatusCode(409)->setJSON(['message' => 'You cannot disable your own account.']);
        $disabled = $this->request->getPost('disabled');
        if (!in_array($disabled, ['0', '1'], true)) return $this->response->setStatusCode(422);
        $db = \Config\Database::connect();
        $user = $db->table('users')->where('id', (int) $id)->get()->getRowArray();
        if (!$user) return $this->response->setStatusCode(404);
        // Enabling system access never approves a pending or rejected student registration.
        $db->table('users')->where('id', (int) $id)->update(['access_disabled' => (int) $disabled,
            'remember_token' => null, 'auth_version' => (int) $user['auth_version'] + 1]);
        $this->logAudit($disabled === '1' ? 'ACCOUNT_DISABLED' : 'ACCOUNT_ENABLED', 'user', (int) $id);
        return redirect()->to('/admin/users')->with('success', 'Account access updated.');
    }

    public function delete($id)
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        return $this->response->setStatusCode(405)->setJSON(['message' => 'Use account deactivation to retain records.']);
    }

    public function unlock($id)
    {
        if (!$this->hasRole(2)) return $this->forbidden();
        \Config\Database::connect()->table('users')->where('id', (int) $id)->update(['is_locked' => 0]);
        $this->logAudit('UNLOCK_ACCOUNT', 'user', (int) $id);
        return redirect()->to('/admin/users')->with('success', 'Account reporting lock cleared.');
    }
}
