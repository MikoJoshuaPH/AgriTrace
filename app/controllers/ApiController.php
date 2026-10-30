<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: ApiController
 * 
 * Automatically generated via CLI.
 */
class ApiController extends Controller {
    private $user_id;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
    }
    
    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login', 5, 60); // 5 requests per minute

        $input = $this->api->body();

        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            $this->api->respond_error('Username and password are required.', 400);
        }
        $model = $this->call->model('User_model');
        $user = $model->findByLogin($username);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid username or password.', 401);
        }
        if ((int) $user['is_active'] !== 1) {
            $this->api->respond_error('Your account is waiting for admin approval.', 403);
        }

        // Two-factor sign-in: the password is only the first step. A one-time code is emailed,
        // and tokens are issued by login_verify() once the code is entered.
        $mailer = $this->call->library('Mailer');
        if (!$mailer->is_configured()) {
            $this->api->respond_error('Sign-in codes are sent by email, and email is not set up yet. Ask the administrator to configure MAIL_USERNAME and MAIL_PASSWORD.', 503);
        }
        $code = (string) random_int(100000, 999999);
        $this->call->model('User_model')->set_two_factor((int) $user['id'], $this->two_factor_hash($code));
        try {
            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];
            $mailer->send($user['email'], 'Your AgriTrace sign-in code', $mailer->otp_email($name, $code));
        } catch (RuntimeException $e) {
            error_log('AgriTrace sign-in email failed: ' . $e->getMessage());
            $this->api->respond_error('The sign-in code could not be sent. Please try again in a moment.', 503);
        }
        $this->call->model('Platform_model')->audit((int) $user['id'], 'LOGIN_CODE_SENT', 'Auth', $user['id'], 'Two-factor code emailed');

        $this->api->respond([
            'requires_2fa' => true,
            'message' => 'We sent a 6-digit sign-in code to ' . $this->mask_email($user['email']) . '.',
        ]);
    }

    /** POST /api/login/verify {username, code} — second step of sign-in. */
    public function login_verify()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login_verify', 10, 300);
        $input = $this->api->body();
        $login = trim((string) ($input['username'] ?? ''));
        $code = preg_replace('/\D/', '', (string) ($input['code'] ?? ''));
        if ($login === '' || strlen($code) !== 6) {
            $this->api->respond_error('Enter the 6-digit code from your email.', 422);
        }

        $model = $this->call->model('User_model');
        $user = $model->findByLogin($login);
        if (!$user || (int) $user['is_active'] !== 1 || empty($user['two_factor_code_hash']) || strtotime((string) $user['two_factor_expires_at']) < time()) {
            $this->api->respond_error('The code is invalid or has expired. Sign in again to get a new code.', 401);
        }
        if ((int) $user['two_factor_attempts'] >= 5) {
            $this->api->respond_error('Too many wrong codes. Sign in again to get a new code.', 429);
        }
        if (!hash_equals($user['two_factor_code_hash'], $this->two_factor_hash($code))) {
            $model->bump_two_factor_attempts((int) $user['id']);
            $this->api->respond_error('The code is invalid or has expired. Sign in again to get a new code.', 401);
        }

        $model->clear_two_factor((int) $user['id']);
        $tokens = $this->api->issue_tokens(['id' => $user['id'], 'role' => $user['role']]);
        $this->call->model('Platform_model')->audit((int) $user['id'], 'LOGIN', 'Auth', $user['id'], 'Signed in with email code');

        $this->api->respond([
            'message' => 'Login successful.',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'is_active' => $user['is_active']
            ],
            'tokens' => $tokens
        ]);
    }

    /** POST /api/password/forgot {email} — always answers the same way, so it cannot reveal which emails exist. */
    public function forgot_password()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('password_forgot', 5, 300);
        $email = strtolower(trim((string) ($this->api->body()['email'] ?? '')));
        $generic = 'If an active account uses this email, a reset link has been sent.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond(['message' => $generic]);
        }

        $model = $this->call->model('User_model');
        $user = $model->findByEmail($email);
        if ($user && (int) $user['is_active'] === 1) {
            $mailer = $this->call->library('Mailer');
            if (!$mailer->is_configured()) {
                $this->api->respond_error('Password reset emails are not set up yet. Please contact the administrator.', 503);
            }
            $token = bin2hex(random_bytes(32));
            $model->set_reset((int) $user['id'], hash('sha256', $token));
            $link = rtrim(config_item('app_frontend_url'), '/') . '/loginpage.html?reset=' . $token;
            try {
                $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['username'];
                $mailer->send($user['email'], 'Reset your AgriTrace password', $mailer->reset_email($name, $link));
            } catch (RuntimeException $e) {
                $this->api->respond_error('The reset email could not be sent. Please try again later.', 503);
            }
            $this->call->model('Platform_model')->audit((int) $user['id'], 'PASSWORD_RESET_REQUESTED', 'Auth', $user['id'], null);
        }
        $this->api->respond(['message' => $generic]);
    }

    /** POST /api/password/reset {token, password} */
    public function reset_password()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('password_reset', 10, 300);
        $input = $this->api->body();
        $token = (string) ($input['token'] ?? '');
        $password = (string) ($input['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->api->respond_error('This reset link is invalid or has expired.', 422);
        }
        if (strlen($password) < 8) {
            $this->api->respond_error('Password must be at least 8 characters long.', 422);
        }

        $model = $this->call->model('User_model');
        $userId = $model->find_by_reset_hash(hash('sha256', $token));
        if (!$userId) {
            $this->api->respond_error('This reset link is invalid or has expired.', 422);
        }
        $model->updateUser((int) $userId, ['password' => password_hash($password, PASSWORD_DEFAULT)]);
        $model->clear_reset((int) $userId);
        $this->call->model('Platform_model')->audit((int) $userId, 'PASSWORD_RESET', 'Auth', $userId, null);
        $this->api->respond(['message' => 'Your password has been reset. You can sign in now.']);
    }

    public function refresh()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('refresh', 20, 60);

        $input = $this->api->body();
        $refresh_token = trim($input['refresh_token'] ?? '');

        if (empty($refresh_token)) {
            $this->api->respond_error('Refresh token is required.', 422);
        }
        $this->api->refresh_access_token($refresh_token);
    }

    public function logout()
    {
        $this->api->require_method('POST');

        $input = $this->api->body();
        $refresh_token = trim($input['refresh_token'] ?? '');

        if (empty($refresh_token)) {
            $this->api->respond_error('Refresh token is required.', 422);
        }

        $this->api->revoke_refresh_token($refresh_token);
        $this->api->respond(['message' => 'Logged out successfully.']);
    }

    public function create()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('register', 5, 300); // 10 requests per minute

        $input = $this->api->body();

        $username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
        $email = is_string($input['email'] ?? null) ? trim($input['email']) : '';
        $password = $input['password'] ?? '';
        $role = 'farmer';

        if (array_key_exists('role', $input)) {
            if (!is_string($input['role'])) {
                $this->api->respond_error('Role must be a string.', 422);
            }

            $requested_role = strtolower(trim($input['role']));
            if (!in_array($requested_role, ['farmer', 'collector', 'transporter', 'seller'], true)) {
                $this->api->respond_error('Invalid role.', 422);
            }

            $role = $requested_role;
        }

        // Validation
        if (empty($username) || empty($email) || empty($password)) {
            $this->api->respond_error('Username, email, and password are required.', 400);
        }

        if (strlen($username) > 150) {
            $this->api->respond_error('Username must be 150 characters or fewer.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Invalid email format.', 422);
        }

        if(strlen($password) < 8) {
            $this->api->respond_error('Password must be at least 8 characters long.', 422);
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $first_name = is_string($input['first_name'] ?? null) ? trim($input['first_name']) : null;
        $middle_name = is_string($input['middle_name'] ?? null) ? trim($input['middle_name']) : null;
        $last_name = is_string($input['last_name'] ?? null) ? trim($input['last_name']) : null;
        $address = is_string($input['address'] ?? null) ? trim($input['address']) : null;
        $contact = is_string($input['contact_number'] ?? null) ? trim($input['contact_number']) : null;
        $gender = is_string($input['gender'] ?? null) ? trim($input['gender']) : null;
        $age = $this->validate_age($input['age'] ?? null);

        $model = $this->call->model('User_model');
        if ($model->findByEmail($email)) {
            $this->api->respond_error('An account with this email already exists. Log in or use Forgot password.', 422);
        }
        if ($model->usernameOrEmailExists($username, $email)) {
            $this->api->respond_error('This username is already in use. Please try again.', 422);
        }

        // Public registrations are inactive until an administrator approves them.
        $new_id = $model->createUser([
            'username' => $username,
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'address' => $address,
            'email' => $email,
            'password' => $hashed_password,
            'role' => $role,
            'age' => $age,
            'gender' => $gender,
            'contact_number' => $contact,
            'is_active' => 0
        ]);
        $this->call->model('Platform_model')->audit((int) $new_id, 'CREATE_ACCOUNT', 'User', $new_id, 'Self-registration');

        $this->api->respond([
            'message' => 'Registration received. You can sign in after an administrator approves your account.',
            'user_id' => (int) $new_id
        ], 201);
    }

    public function list()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();

        if ($auth['role'] !== 'admin') {
            $this->api->respond_error('Unauthorized access.', 403);
        }

        $users = $this->db->raw(
            'SELECT id, username, email, role, created_at FROM users ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->api->respond(['data' => $users]);
    }

    public function profile()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();

        $this->user_id = $auth['sub'];
        $model = $this->call->model('User_model');
        $user = $model->findById($this->user_id);

        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }

        $this->api->respond($user);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            $this->api->respond_error('Invalid user ID.', 422);
        }

        $model = $this->call->model('User_model');
        $user = $model->findById($id);

        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }

        $is_admin = ($auth['role'] ?? '') === 'admin';
        if (!$is_admin && (string) $auth['sub'] !== (string) $user['id']) {
            $this->api->respond_error('Unauthorized access.', 403);
        }

        $input = $this->api->body();
        $allowedProfileFields = ['username', 'first_name', 'middle_name', 'last_name', 'address', 'email', 'contact_number', 'gender', 'profile_pic', 'location_label'];
        $profileUpdates = [];

        foreach ($allowedProfileFields as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if (!is_string($input[$field])) {
                $this->api->respond_error(ucwords(str_replace('_', ' ', $field)) . ' must be a string.', 422);
            }
            $value = trim($input[$field]);
            if ($field === 'username' && $value === '') {
                $this->api->respond_error('Username cannot be empty.', 422);
            }
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->api->respond_error('Invalid email format.', 422);
            }
            $profileUpdates[$field] = $value === '' ? null : $value;
            $user[$field] = $profileUpdates[$field];
        }

        if (array_key_exists('age', $input)) {
            $profileUpdates['age'] = $this->validate_age($input['age']);
            $user['age'] = $profileUpdates['age'];
        }

        if (array_key_exists('latitude', $input) || array_key_exists('longitude', $input)) {
            $lat = $input['latitude'] ?? null;
            $lng = $input['longitude'] ?? null;
            if ($lat === null || $lat === '' || $lng === null || $lng === '') {
                $profileUpdates['latitude'] = null;
                $profileUpdates['longitude'] = null;
            } else {
                if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    $this->api->respond_error('The map location is not valid.', 422);
                }
                $profileUpdates['latitude'] = round((float) $lat, 6);
                $profileUpdates['longitude'] = round((float) $lng, 6);
            }
        }

        if (array_key_exists('password', $input)) {
            if (!is_string($input['password']) || strlen($input['password']) < 8) {
                $this->api->respond_error('Password must be at least 8 characters long.', 422);
            }
            if (!$is_admin || (string) $auth['sub'] === (string) $id) {
                $current = (string) ($input['current_password'] ?? '');
                if ($current === '' || !password_verify($current, $model->password_hash_for($id))) {
                    $this->api->respond_error('Your current password is incorrect.', 403);
                }
            }
            $profileUpdates['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
        }

        if (array_key_exists('role', $input)) {
            if (!$is_admin) {
                $this->api->respond_error('Only administrators can change user roles.', 403);
            }
            if (!is_string($input['role'])) {
                $this->api->respond_error('Role must be a string.', 422);
            }
            $role = strtolower(trim($input['role']));
            if (!in_array($role, ['admin', 'farmer', 'collector', 'transporter', 'seller'], true)) {
                $this->api->respond_error('Invalid role.', 422);
            }
            $profileUpdates['role'] = $role;
            $user['role'] = $role;
        }

        if (!$profileUpdates) {
            $this->api->respond_error('Provide at least one profile field to update.', 422);
        }

        $checkUsername = $user['username'] ?? '';
        $checkEmail = $user['email'] ?? '';
        if ($model->usernameOrEmailExists($checkUsername, $checkEmail, $id)) {
            $this->api->respond_error('Username or email already exists.', 422);
        }

        $model->updateUser($id, $profileUpdates);
        $user = $model->findById($id);
        $this->call->model('Platform_model')->audit((int) $auth['sub'], 'UPDATE_ACCOUNT', 'User', $id, implode(',', array_keys($profileUpdates)));

        $this->api->respond([
            'message' => 'User updated successfully.',
            'user' => $user
        ]);
    }

    public function delete($id)
    {
        $this->api->require_method('DELETE');
        $auth = $this->api->require_jwt();
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            $this->api->respond_error('Invalid user ID.', 422);
        }

        $user = $this->db->raw(
            'SELECT id FROM users WHERE id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }

        $is_admin = ($auth['role'] ?? '') === 'admin';
        if (!$is_admin && (string) $auth['sub'] !== (string) $user['id']) {
            $this->api->respond_error('Unauthorized access.', 403);
        }

        $this->db->raw('DELETE FROM refresh_tokens WHERE user_id = ?', [$id]);
        $this->db->raw('DELETE FROM users WHERE id = ?', [$id]);
        $this->call->model('Platform_model')->audit((int) $auth['sub'], 'DELETE_ACCOUNT', 'User', $id, null);

        $this->api->respond(['message' => 'User deleted successfully.']);
    }

    /** Age is optional, but when given it must be a whole number of 18 or more. */
    private function validate_age($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $age = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 18, 'max_range' => 120]]);
        if ($age === false) {
            $this->api->respond_error('Age must be a whole number between 18 and 120.', 422);
        }
        return $age;
    }

    /** HMAC of a one-time code, so codes are never stored in plain text. */
    private function two_factor_hash($code)
    {
        return hash_hmac('sha256', (string) $code, $this->two_factor_key());
    }

    private function two_factor_key()
    {
        return getenv('JWT_SECRET') ?: getenv('APP_KEY') ?: 'agritrace-two-factor';
    }

    private function mask_email($email)
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];
        return substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain;
    }
}