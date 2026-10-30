<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SupportController extends Controller
{
    private $support;
    private $platform;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->support = $this->call->model('Support_model');
        $this->platform = $this->call->model('Platform_model');
    }

    /** POST /api/support/complaints */
    public function create_complaint()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $input = $this->api->body();
        $message = $input['message'] ?? '';
        if (!is_string($message) || trim($message) === '' || mb_strlen(trim($message)) > 5000) {
            $this->api->respond_error('Complaints must contain 1 to 5000 characters.', 422);
        }

        $user = $this->db->table('users')
            ->select('first_name, middle_name, last_name, username, email')
            ->where('id', (int) $auth['sub'])->limit(1)->get(PDO::FETCH_ASSOC);
        if (!$user) {
            $this->api->respond_error('Your account could not be found.', 404);
        }
        $name = trim(implode(' ', array_filter([
            $user['first_name'] ?? '',
            $user['middle_name'] ?? '',
            $user['last_name'] ?? '',
        ], function ($part) {
            return trim((string) $part) !== '';
        })));
        $name = $name !== '' ? $name : $user['username'];
        $mailer = $this->call->library('Mailer');
        if (!$mailer->is_configured()) {
            $this->api->respond_error('Complaint email is not configured. Ask the administrator to set MAIL_USERNAME and MAIL_PASSWORD.', 503);
        }
        $recipient = config_item('mail_report_to') ?: null;
        if (!$recipient) {
            $admin = $this->db->table('users')->select('email')
                ->where(['role' => 'admin', 'is_active' => 1])->limit(1)->get(PDO::FETCH_ASSOC);
            $recipient = $admin ? $admin['email'] : null;
        }
        if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('No valid complaint recipient is configured. Set MAIL_REPORT_TO or add an active administrator account.', 503);
        }

        $complaintId = $this->support->create_complaint(
            (int) $auth['sub'],
            $name,
            $user['email'],
            trim($message)
        );
        $this->platform->audit((int) $auth['sub'], 'SUBMIT_COMPLAINT', 'SupportComplaint', $complaintId);
        try {
            $mailer->send(
                $recipient,
                'AgriTrace complaint #' . $complaintId,
                $mailer->complaint_email($name, $user['email'], trim($message)),
                "Name: {$name}\nEmail: {$user['email']}\n\nComplaint:\n" . trim($message),
                [],
                $user['email']
            );
        } catch (RuntimeException $error) {
            $this->platform->audit((int) $auth['sub'], 'COMPLAINT_EMAIL_FAILED', 'SupportComplaint', $complaintId, $error->getMessage());
            $this->api->respond_error('Your complaint was saved, but its email could not be sent. Please contact the administrator and provide complaint #' . $complaintId . '.', 503);
        }
        $this->api->respond(['message' => 'Your complaint was emailed to the AgriTrace administrators and saved for follow-up.'], 201);
    }
}
