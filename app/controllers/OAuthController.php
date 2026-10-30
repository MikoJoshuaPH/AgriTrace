<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Google and Facebook OAuth login/registration.
 */
class OAuthController extends Controller
{
    private $api;
    private $http;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->api = $this->call->library('api');
        $this->http = $this->call->library('Http_client');
    }

    public function start($provider)
    {
        if (!in_array($provider, ['google', 'facebook'], true)) {
            $this->api->respond_error('Unsupported sign-in provider.', 404);
        }
        $credentials = $this->credentials($provider);
        if (!$credentials['client_id'] || !$credentials['client_secret']) {
            $variables = $provider === 'google'
                ? 'GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET'
                : 'FACEBOOK_APP_ID and FACEBOOK_APP_SECRET';
            $this->redirect_frontend([
                'social_error' => ucfirst($provider) . ' sign-in is not configured. Set ' . $variables . ' in the backend .env file.'
            ]);
        }
        $query = $this->api->get_query_params();
        $intent = $query['intent'] ?? 'login';
        $role = $query['role'] ?? 'farmer';
        if (!in_array($intent, ['login', 'register'], true)
            || !in_array($role, ['farmer', 'seller', 'collector', 'transporter'], true)) {
            $this->redirect_frontend(['social_error' => 'The sign-in request is invalid.']);
        }

        $this->start_session();
        $state = bin2hex(random_bytes(32));
        $_SESSION['agritrace_oauth'][$provider] = [
            'state' => $state,
            'intent' => $intent,
            'role' => $role,
            'created_at' => time()
        ];
        $redirectUri = $this->callback_url($provider);
        if ($provider === 'google') {
            $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $credentials['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account'
            ], '', '&', PHP_QUERY_RFC3986);
        } else {
            $url = 'https://www.facebook.com/v22.0/dialog/oauth?' . http_build_query([
                'client_id' => $credentials['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'email,public_profile',
                'state' => $state
            ], '', '&', PHP_QUERY_RFC3986);
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    public function callback($provider)
    {
        if (!in_array($provider, ['google', 'facebook'], true)) {
            $this->redirect_frontend(['social_error' => 'Unsupported sign-in provider.']);
        }
        $this->start_session();
        $expected = $_SESSION['agritrace_oauth'][$provider] ?? null;
        unset($_SESSION['agritrace_oauth'][$provider]);
        $state = $_GET['state'] ?? '';
        $code = $_GET['code'] ?? '';
        if (!is_array($expected) || time() - (int) $expected['created_at'] > 600
            || !is_string($state) || !hash_equals((string) $expected['state'], $state)) {
            $this->redirect_frontend(['social_error' => 'Sign-in expired or could not be verified. Please try again.']);
        }
        if (isset($_GET['error'])) {
            $this->redirect_frontend(['social_error' => 'Sign-in was cancelled or declined.']);
        }
        if (!is_string($code) || $code === '') {
            $this->redirect_frontend(['social_error' => 'Sign-in expired or could not be verified. Please try again.']);
        }

        try {
            $identity = $provider === 'google'
                ? $this->google_identity($code)
                : $this->facebook_identity($code);
        } catch (RuntimeException $error) {
            $this->redirect_frontend(['social_error' => $error->getMessage()]);
        }

        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirect_frontend(['social_error' => 'The provider did not share a valid email address.']);
        }
        $model = $this->call->model('User_model');
        $user = $model->findByEmail($email);
        if (!$user) {
            if (($expected['intent'] ?? 'login') !== 'register') {
                $this->redirect_frontend(['social_error' => 'No AgriTrace account uses this email. Choose Register to create one.']);
            }
            $name = preg_split('/\s+/u', trim((string) ($identity['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $firstName = $name ? array_shift($name) : 'AgriTrace';
            $lastName = $name ? array_pop($name) : 'Member';
            $base = strtolower((string) strstr($email, '@', true));
            $base = substr(preg_replace('/[^a-z0-9]+/', '', $base) ?: 'member', 0, 20);
            do {
                $username = $base . random_int(1000, 999999);
            } while ($model->usernameOrEmailExists($username, $email));
            $newId = $model->createUser([
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'role' => $expected['role'] ?? 'farmer',
                'profile_pic' => $identity['picture'] ?? null,
                'is_active' => 0
            ]);
            $this->call->model('Platform_model')->audit((int) $newId, 'CREATE_ACCOUNT', 'User', $newId, 'OAuth registration (' . $provider . ')');
            $this->redirect_frontend(['social_status' => 'pending']);
        }
        if ((int) $user['is_active'] !== 1) {
            $this->redirect_frontend(['social_status' => 'pending']);
        }

        $activeUser = $model->findById((int) $user['id']);
        $tokens = $this->api->issue_tokens(['id' => $activeUser['id'], 'role' => $activeUser['role']]);
        $this->call->model('Platform_model')->audit((int) $activeUser['id'], 'LOGIN', 'Auth', (int) $activeUser['id'], 'OAuth sign-in (' . $provider . ')');
        $this->redirect_frontend([], $tokens);
    }

    private function credentials($provider)
    {
        if ($provider === 'google') {
            return [
                'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
                'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: ''
            ];
        }
        return [
            'client_id' => getenv('FACEBOOK_APP_ID') ?: '',
            'client_secret' => getenv('FACEBOOK_APP_SECRET') ?: ''
        ];
    }

    private function callback_url($provider)
    {
        return rtrim((string) config_item('app_public_url'), '/') . '/api/oauth/' . $provider . '/callback';
    }

    private function google_identity($code)
    {
        $credentials = $this->credentials('google');
        $response = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
        ], http_build_query([
            'code' => $code,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $this->callback_url('google'),
            'grant_type' => 'authorization_code'
        ], '', '&', PHP_QUERY_RFC3986));
        $token = $this->decode_response($response, 'Google token exchange failed.');
        if (empty($token['access_token'])) {
            throw new RuntimeException('Google sign-in could not be completed.');
        }
        $profile = $this->http->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
            'Authorization' => 'Bearer ' . $token['access_token'],
            'Accept' => 'application/json'
        ]);
        $identity = $this->decode_response($profile, 'Could not load your Google profile.');
        if (empty($identity['email_verified'])) {
            throw new RuntimeException('Please use a Google account with a verified email address.');
        }
        return $identity;
    }

    private function facebook_identity($code)
    {
        $credentials = $this->credentials('facebook');
        $tokenUrl = 'https://graph.facebook.com/v22.0/oauth/access_token?' . http_build_query([
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $this->callback_url('facebook'),
            'code' => $code
        ], '', '&', PHP_QUERY_RFC3986);
        $token = $this->decode_response($this->http->request('GET', $tokenUrl, ['Accept' => 'application/json']), 'Facebook token exchange failed.');
        if (empty($token['access_token'])) {
            throw new RuntimeException('Facebook sign-in could not be completed.');
        }
        $profileUrl = 'https://graph.facebook.com/v22.0/me?' . http_build_query([
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $token['access_token']
        ], '', '&', PHP_QUERY_RFC3986);
        $profile = $this->decode_response($this->http->request('GET', $profileUrl, ['Accept' => 'application/json']), 'Could not load your Facebook profile.');
        return [
            'email' => $profile['email'] ?? '',
            'name' => $profile['name'] ?? '',
            'picture' => $profile['picture']['data']['url'] ?? null
        ];
    }

    private function decode_response(array $response, $message)
    {
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            throw new RuntimeException($message);
        }
        return $data;
    }

    private function start_session()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }

    private function redirect_frontend(array $parameters, array $tokens = [])
    {
        $frontend = rtrim((string) config_item('app_frontend_url'), '/');
        if ($tokens) {
            $fragment = http_build_query([
                'social_access' => $tokens['access_token'],
                'social_refresh' => $tokens['refresh_token']
            ], '', '&', PHP_QUERY_RFC3986);
            header('Location: ' . $frontend . '/#' . $fragment, true, 302);
            exit;
        }
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $frontend . '/' . ($query === '' ? '' : '?' . $query), true, 302);
        exit;
    }
}
