<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class FarmerApplicationController extends Controller
{
    private $applications;
    private $platform;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->applications = $this->call->model('Farmer_application_model');
        $this->platform = $this->call->model('Platform_model');
    }

    public function mine()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $application = $this->applications->latestForApplicant((int) $auth['sub']);
        $this->api->respond(['data' => $application]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'collector') {
            $this->api->respond_error('Only buyer accounts can apply to become farmers.', 403);
        }
        if ($this->applications->pendingForApplicant((int) $auth['sub'])) {
            $this->api->respond_error('You already have a pending farmer application.', 409);
        }

        $input = $this->api->body();
        if (!is_string($input['farm_name'] ?? null)) {
            $this->api->respond_error('Farm name must be text.', 422);
        }
        $farmName = trim($input['farm_name']);
        if ($farmName === '' || strlen($farmName) > 150) {
            $this->api->respond_error('A farm name of 1 to 150 characters is required.', 422);
        }

        $farmSize = $input['farm_size'] ?? null;
        if ($farmSize !== null && $farmSize !== '' && (!is_scalar($farmSize) || !is_numeric($farmSize) || (float) $farmSize <= 0)) {
            $this->api->respond_error('Farm size must be a positive number.', 422);
        }
        $latitude = $this->coordinate($input['latitude'] ?? null, -90, 90);
        $longitude = $this->coordinate($input['longitude'] ?? null, -180, 180);
        $id = $this->applications->create((int) $auth['sub'], [
            'farm_name' => $farmName,
            'farm_address' => $this->optionalText($input['farm_address'] ?? null, 1000, 'Farm address'),
            'province' => $this->optionalText($input['province'] ?? null, 100, 'Province'),
            'municipality' => $this->optionalText($input['municipality'] ?? null, 100, 'Municipality'),
            'barangay' => $this->optionalText($input['barangay'] ?? null, 100, 'Barangay'),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'farm_size' => $farmSize === null || $farmSize === '' ? null : (float) $farmSize,
            'farm_size_unit' => $this->optionalText($input['farm_size_unit'] ?? null, 20, 'Farm size unit'),
            'farm_description' => $this->optionalText($input['farm_description'] ?? null, 5000, 'Farm description'),
        ]);
        $this->platform->audit((int) $auth['sub'], 'FARMER_APPLICATION_SUBMITTED', 'FarmerApplication', $id, $farmName);
        $this->api->respond(['message' => 'Farmer application submitted for administrator review.', 'application_id' => $id], 201);
    }

    public function admin_index()
    {
        $this->require_admin('GET');
        $query = $this->api->get_query_params();
        $status = isset($query['status']) && in_array($query['status'], ['pending', 'approved', 'rejected'], true)
            ? $query['status'] : null;
        $this->api->respond(['data' => $this->applications->listAll($status, $query['limit'] ?? 100)]);
    }

    public function review($id)
    {
        $admin = $this->require_admin('PUT');
        $applicationId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($applicationId === false) {
            $this->api->respond_error('Invalid application ID.', 422);
        }
        $application = $this->applications->findById($applicationId);
        if (!$application) {
            $this->api->respond_error('Farmer application not found.', 404);
        }
        $input = $this->api->body();
        $status = $input['status'] ?? '';
        if (!in_array($status, ['approved', 'rejected'], true)) {
            $this->api->respond_error('Status must be approved or rejected.', 422);
        }
        $reason = $this->optionalText($input['reason'] ?? null, 500, 'Review reason');
        if ($status === 'rejected' && $reason === null) {
            $this->api->respond_error('A rejection reason is required.', 422);
        }
        try {
            if (!$this->applications->review($applicationId, $admin, $status, $reason)) {
                $this->api->respond_error('This application has already been reviewed.', 409);
            }
        } catch (Throwable $e) {
            error_log('AgriTrace farmer application review failed: ' . $e->getMessage());
            $this->api->respond_error('The application could not be reviewed.', 500);
        }
        $this->platform->audit($admin, 'FARMER_APPLICATION_' . strtoupper($status), 'FarmerApplication', $applicationId, $reason);
        $this->platform->notify(
            (int) $application['applicant_id'],
            $status === 'approved' ? 'Farmer application approved' : 'Farmer application rejected',
            $status === 'approved'
                ? 'Your account is now approved as a farmer. Your registered farm is ready to use.'
                : 'Your farmer application was rejected. Reason: ' . $reason
        );
        $this->api->respond(['message' => 'Farmer application ' . $status . '.']);
    }

    private function require_admin($method)
    {
        $this->api->require_method($method);
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }
        return (int) $auth['sub'];
    }

    private function optionalText($value, $maxLength, $label)
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $this->api->respond_error($label . ' must be text.', 422);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxLength) {
            $this->api->respond_error($label . ' must be ' . $maxLength . ' characters or fewer.', 422);
        }
        return $value;
    }

    private function coordinate($value, $min, $max)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value) || !is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            $this->api->respond_error('Farm coordinates are invalid.', 422);
        }
        return (float) $value;
    }
}
