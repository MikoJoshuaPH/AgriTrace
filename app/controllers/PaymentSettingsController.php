<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PaymentSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
    }

    public function index()
    {
        $this->api->require_method('GET');
        $auth = $this->farmer();
        $rows = $this->db->table('farmer_payment_settings')
            ->select('setting_id, payment_method, account_name, account_reference, is_active, updated_at')
            ->where('farmer_id', $auth)
            ->order_by('payment_method', 'ASC')->get_all(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['account_reference'] = $this->mask($row['account_reference']);
        }
        $this->api->respond(['data' => $rows]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $farmer = $this->farmer();
        $input = $this->api->body();
        $method = strtolower(trim((string) ($input['payment_method'] ?? '')));
        $name = trim((string) ($input['account_name'] ?? ''));
        $reference = trim((string) ($input['account_reference'] ?? ''));
        if (!in_array($method, ['gcash', 'bank_transfer', 'paypal'], true) || $name === '' || $reference === '') {
            $this->api->respond_error('Choose a supported payment method and provide account details.', 422);
        }
        $this->db->table('farmer_payment_settings')->insert([
            'farmer_id' => $farmer, 'payment_method' => $method,
            'account_name' => substr($name, 0, 150), 'account_reference' => substr($reference, 0, 255),
            'is_active' => 1, 'updated_at' => date('Y-m-d H:i:s')
        ]);
        $this->api->respond(['message' => 'Payment setting saved.'], 201);
    }

    public function payouts()
    {
        $this->api->require_method('GET');
        $farmer = $this->farmer();
        $this->api->respond(['data' => $this->db->table('farmer_payouts')
            ->select('payout_id, order_id, amount, platform_fee, status, transaction_reference, paid_at, created_at')
            ->where('farmer_id', $farmer)->order_by('payout_id', 'DESC')->limit(100)
            ->get_all(PDO::FETCH_ASSOC)]);
    }

    public function paymongo_account()
    {
        $this->api->require_method('GET');
        $farmer = $this->farmer();
        $account = $this->account_row($farmer);
        if (empty($account['paymongo_account_id'])) {
            $this->api->respond(['account_status' => 'not_started', 'identity_status' => 'not_started']);
        }

        try {
            $remote = $this->call->library('PayMongo')->get_account($account['paymongo_account_id']);
        } catch (RuntimeException $e) {
            $this->api->respond_error('PayMongo account status could not be refreshed. Please try again shortly.', 502);
        }
        $this->save_account_status($farmer, $remote);
        $this->api->respond([
            'account_status' => (string) ($remote['activation_status'] ?? 'pending'),
            'identity_status' => (string) ($remote['person']['identity_verification_status'] ?? 'pending'),
        ]);
    }

    public function create_paymongo_account()
    {
        $this->api->require_method('POST');
        $farmer = $this->farmer();
        $paymongo = $this->call->library('PayMongo');
        if (!$paymongo->is_configured()) {
            $this->api->respond_error('PayMongo is not configured. Contact the administrator.', 503);
        }

        $user = $this->db->raw(
            'SELECT email, contact_number, paymongo_account_id FROM users WHERE id = ? LIMIT 1',
            [$farmer]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $this->api->respond_error('Farmer profile not found.', 404);
        }
        if (!empty($user['paymongo_account_id'])) {
            $this->api->respond_error('A PayMongo account is already linked to this profile.', 409);
        }

        $email = trim((string) $user['email']);
        $mobile = $this->normalize_mobile_number($user['contact_number']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $mobile === null) {
            $this->api->respond_error('Add a valid email address and Philippine mobile number to your profile first.', 422);
        }

        try {
            $account = $paymongo->create_account([
                'type' => 'merchant',
                'person' => [
                    'email_address' => $email,
                    'mobile_number' => $mobile,
                ],
            ]);
        } catch (RuntimeException $e) {
            $this->api->respond_error('PayMongo could not create the linked account. Please try again shortly.', 502);
        }

        $accountId = (string) $account['id'];
        $existing = $this->db->raw(
            'SELECT id FROM users WHERE paymongo_account_id = ? AND id <> ? LIMIT 1',
            [$accountId, $farmer]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $this->api->respond_error('This PayMongo test account is already linked to another farmer. Test mode returns a shared mock account; use one farmer account for this test.', 409);
        }
        $this->db->raw(
            'UPDATE users SET paymongo_account_id = ?, paymongo_account_status = ?, paymongo_identity_status = ? WHERE id = ? AND paymongo_account_id IS NULL',
            [
                $accountId,
                (string) ($account['activation_status'] ?? 'pending'),
                (string) ($account['person']['identity_verification_status'] ?? 'pending'),
                $farmer,
            ]
        );
        $saved = $this->account_row($farmer);
        if (!$saved || (string) $saved['paymongo_account_id'] !== $accountId) {
            $this->api->respond_error('The PayMongo account was created but could not be linked to this profile. Contact the administrator before retrying.', 500);
        }
        $this->api->respond([
            'account_status' => (string) ($account['activation_status'] ?? 'pending'),
            'identity_status' => (string) ($account['person']['identity_verification_status'] ?? 'pending'),
        ], 201);
    }

    public function create_paymongo_verification()
    {
        $this->api->require_method('POST');
        $farmer = $this->farmer();
        $account = $this->account_row($farmer);
        if (!$account || empty($account['paymongo_account_id'])) {
            $this->api->respond_error('Create your PayMongo linked account first.', 409);
        }
        if ($account['paymongo_account_status'] === 'activated') {
            $this->api->respond_error('This PayMongo account is already activated.', 409);
        }

        try {
            $verification = $this->call->library('PayMongo')->create_identity_verification($account['paymongo_account_id']);
        } catch (RuntimeException $e) {
            $this->api->respond_error('PayMongo could not start identity verification. Please try again shortly.', 502);
        }
        $attributes = $verification['attributes'] ?? [];
        $url = $attributes['hosted_url'] ?? '';
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            $this->api->respond_error('PayMongo returned an invalid identity verification URL.', 502);
        }
        $this->api->respond([
            'verification_url' => $url,
            'identity_status' => (string) ($attributes['status'] ?? 'pending'),
        ]);
    }

    public function activate_paymongo_account()
    {
        $this->api->require_method('POST');
        $farmer = $this->farmer();
        $account = $this->account_row($farmer);
        if (!$account || empty($account['paymongo_account_id'])) {
            $this->api->respond_error('Create your PayMongo linked account first.', 409);
        }
        if ($account['paymongo_account_status'] === 'activated') {
            $this->api->respond(['account_status' => 'activated']);
        }

        $input = $this->api->body();
        $user = $this->db->raw(
            'SELECT first_name, middle_name, last_name, email, contact_number FROM users WHERE id = ? LIMIT 1',
            [$farmer]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user || trim((string) $user['first_name']) === '' || trim((string) $user['last_name']) === '') {
            $this->api->respond_error('Add your legal first and last name to your profile before onboarding.', 422);
        }
        $mobile = $this->normalize_mobile_number($user['contact_number']);
        if ($mobile === null) {
            $this->api->respond_error('Update your profile with a valid Philippine mobile number before onboarding.', 422);
        }
        $person = $this->person_onboarding_details($input);
        $business = $this->business_onboarding_details($input);
        if (isset($person['error'])) {
            $this->api->respond_error($person['error'], 422);
        }
        if (isset($business['error'])) {
            $this->api->respond_error($business['error'], 422);
        }

        $paymongo = $this->call->library('PayMongo');
        try {
            $current = $paymongo->get_account($account['paymongo_account_id']);
            $identityStatus = (string) ($current['person']['identity_verification_status'] ?? 'pending');
            $this->save_account_status($farmer, $current);
            if (!in_array($identityStatus, ['passed', 'passed_attestation_form'], true)) {
                $this->api->respond_error('Complete PayMongo identity verification before submitting account details.', 409);
            }
            $person['first_name'] = trim((string) $user['first_name']);
            $person['last_name'] = trim((string) $user['last_name']);
            if (trim((string) $user['middle_name']) !== '') {
                $person['middle_name'] = trim((string) $user['middle_name']);
            }
            $person['email_address'] = (string) $user['email'];
            $person['mobile_number'] = $mobile;
            $updated = $paymongo->update_account($account['paymongo_account_id'], [
                'person' => $person,
                'business' => $business,
            ]);
            $activated = $paymongo->activate_account($account['paymongo_account_id']);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }

        $result = !empty($activated['activation_status']) ? $activated : $updated;
        if (empty($result['person']['identity_verification_status'])) {
            $result['person']['identity_verification_status'] = 'passed';
        }
        $this->save_account_status($farmer, $result);
        $status = (string) ($result['activation_status'] ?? 'pending');
        $this->api->respond([
            'account_status' => $status,
            'identity_status' => (string) ($result['person']['identity_verification_status'] ?? 'passed'),
        ]);
    }

    private function farmer()
    {
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'farmer') {
            $this->api->respond_error('Farmer access is required.', 403);
        }
        return (int) $auth['sub'];
    }

    private function account_row($farmer)
    {
        return $this->db->raw(
            'SELECT paymongo_account_id, paymongo_account_status, paymongo_identity_status
             FROM users WHERE id = ? LIMIT 1',
            [$farmer]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function save_account_status($farmer, array $account)
    {
        $this->db->raw(
            'UPDATE users SET paymongo_account_status = ?, paymongo_identity_status = ? WHERE id = ?',
            [
                (string) ($account['activation_status'] ?? 'pending'),
                (string) ($account['person']['identity_verification_status'] ?? 'pending'),
                $farmer,
            ]
        );
    }

    private function normalize_mobile_number($value)
    {
        $mobile = preg_replace('/[\s()-]/', '', (string) $value);
        if (preg_match('/^09\d{9}$/', $mobile)) {
            $mobile = '+63' . substr($mobile, 1);
        } elseif (preg_match('/^639\d{9}$/', $mobile)) {
            $mobile = '+' . $mobile;
        }
        return preg_match('/^\+639\d{9}$/', $mobile) ? $mobile : null;
    }

    private function person_onboarding_details(array $input)
    {
        $dob = (string) ($input['date_of_birth'] ?? '');
        $date = DateTime::createFromFormat('!Y-m-d', $dob);
        $now = new DateTimeImmutable('today');
        if (!$date || $date->format('Y-m-d') !== $dob || $date > $now->modify('-18 years')) {
            return ['error' => 'Enter a valid date of birth. The account representative must be at least 18.'];
        }

        $nature = (string) ($input['nature_of_work'] ?? '');
        $source = (string) ($input['source_of_funds'] ?? '');
        $natureOptions = ['employed_locally', 'retired', 'pensioner', 'ofw', 'ofw_beneficiary', 'student', 'self_employed', 'unemployed_spouse_income', 'unemployed_other_income'];
        $sourceOptions = ['salary', 'pension', 'allowance', 'commission', 'donation', 'other'];
        if (!in_array($nature, $natureOptions, true) || !in_array($source, $sourceOptions, true)) {
            return ['error' => 'Choose valid work and source-of-funds details.'];
        }
        $nationality = strtoupper(trim((string) ($input['nationality'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $nationality)) {
            return ['error' => 'Enter a valid three-letter nationality code.'];
        }

        $address = $this->address_details($input, 'person');
        if (isset($address['error'])) {
            return $address;
        }
        $result = [
            'nationality' => $nationality,
            'date_of_birth' => [
                'day' => (int) $date->format('d'),
                'month' => (int) $date->format('m'),
                'year' => (int) $date->format('Y'),
            ],
            'place_of_birth' => [
                'city' => $this->required_text($input, 'birth_city', 100),
                'country' => 'PH',
            ],
            'nature_of_work' => $nature,
            'source_of_funds' => $source,
            'tin' => $this->required_text($input, 'person_tin', 40),
            'address' => $address,
        ];
        if (is_array($result['place_of_birth']['city']) && isset($result['place_of_birth']['city']['error'])) {
            return $result['place_of_birth']['city'];
        }
        if (is_array($result['tin']) && isset($result['tin']['error'])) {
            return $result['tin'];
        }
        if ($source === 'other') {
            $result['source_of_funds_other'] = $this->required_text($input, 'source_of_funds_other', 150);
            if (is_array($result['source_of_funds_other']) && isset($result['source_of_funds_other']['error'])) {
                return $result['source_of_funds_other'];
            }
        } elseif ($source === 'salary') {
            $result['source_of_funds_salary'] = $this->required_text($input, 'source_of_funds_salary', 150);
            if (is_array($result['source_of_funds_salary']) && isset($result['source_of_funds_salary']['error'])) {
                return $result['source_of_funds_salary'];
            }
        }
        return $result;
    }

    private function business_onboarding_details(array $input)
    {
        $type = (string) ($input['business_type'] ?? '');
        $age = (string) ($input['business_age'] ?? '');
        $size = (string) ($input['business_size'] ?? '');
        $volume = (string) ($input['monthly_volume'] ?? '');
        if (!in_array($type, ['individual', 'sole_proprietor', 'partnership', 'corporation'], true)
            || !in_array($age, ['less_than_1_year', 'between_1_and_2_years', 'between_2_and_5_years', 'greater_than_5_years'], true)
            || !in_array($size, ['less_than_10', 'between_10_and_50', 'between_51_and_250', 'greater_than_250'], true)
            || !in_array($volume, ['less_than_100K', 'between_100K_and_300K', 'between_300K_and_500K', 'between_500K_and_1M', 'between_1M_and_3M', 'greater_than_3M'], true)) {
            return ['error' => 'Choose valid business type, age, size, and monthly transaction volume.'];
        }
        $industry = trim((string) ($input['industry'] ?? ''));
        if (!preg_match('/^\d{4}$/', $industry)) {
            return ['error' => 'Enter a valid four-digit Merchant Category Code (MCC) for the business.'];
        }
        $address = $this->address_details($input, 'business');
        if (isset($address['error'])) {
            return $address;
        }
        $result = [
            'trade_name' => $this->required_text($input, 'trade_name', 150),
            'type' => $type,
            'industry' => $industry,
            'description' => $this->required_text($input, 'business_description', 500),
            'age' => $age,
            'size' => $size,
            'estimated_monthly_volume' => $volume,
            'tin' => $this->required_text($input, 'business_tin', 40),
            'address' => $address,
        ];
        foreach (['trade_name', 'description', 'tin'] as $key) {
            if (is_array($result[$key]) && isset($result[$key]['error'])) {
                return $result[$key];
            }
        }
        return $result;
    }

    private function address_details(array $input, $prefix)
    {
        $address = [
            'line1' => $this->required_text($input, $prefix . '_address', 200),
            'city' => $this->required_text($input, $prefix . '_city', 100),
            'state' => $this->required_text($input, $prefix . '_state', 100),
            'country' => 'PH',
            'postal_code' => $this->required_text($input, $prefix . '_postal_code', 20),
        ];
        foreach (['line1', 'city', 'state', 'postal_code'] as $key) {
            if (is_array($address[$key]) && isset($address[$key]['error'])) {
                return $address[$key];
            }
        }
        return $address;
    }

    private function required_text(array $input, $key, $maxLength)
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '' || strlen($value) > $maxLength) {
            return ['error' => 'Provide a valid value for ' . str_replace('_', ' ', $key) . '.'];
        }
        return $value;
    }

    private function mask($value)
    {
        $value = (string) $value;
        return strlen($value) <= 4 ? '••••' : str_repeat('•', max(4, strlen($value) - 4)) . substr($value, -4);
    }
}
