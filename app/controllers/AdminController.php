<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Administrator actions: account creation, post moderation,
 * reports and the moderation queue. Every route requires the admin role.
 */
class AdminController extends Controller
{
    private $users;
    private $community;
    private $platform;
    private $support;
    private const ROLES = ['admin', 'farmer', 'collector', 'transporter', 'seller'];

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->users = $this->call->model('User_model');
        $this->community = $this->call->model('Community_model');
        $this->platform = $this->call->model('Platform_model');
        $this->support = $this->call->model('Support_model');
    }

    /** GET /api/admin/queues — reports and complaints, including reviewed items. */
    public function queues()
    {
        $this->require_admin('GET');
        $this->api->respond([
            'reports' => $this->platform->reports(),
            'complaints' => $this->support->all_complaints(),
        ]);
    }

    /** PUT /api/admin/users/{id}/verify {verified:boolean} */
    public function verify_user($id)
    {
        $this->require_admin('PUT');
        $user = $this->users->findById((int) $id);
        if (!$user || $user['role'] !== 'farmer') {
            $this->api->respond_error('Farmer not found.', 404);
        }
        $input = $this->api->body();
        if (!array_key_exists('verified', $input) || !is_bool($input['verified'])) {
            $this->api->respond_error('Verified must be true or false.', 422);
        }
        $this->users->updateUser((int) $id, ['is_verified' => $input['verified'] ? 1 : 0]);
        $this->platform->audit($this->admin_id(), $input['verified'] ? 'VERIFY_FARMER' : 'UNVERIFY_FARMER', 'User', (int) $id, $user['email']);
        $this->api->respond(['message' => $input['verified'] ? 'Farmer verified.' : 'Farmer verification removed.']);
    }

    /** POST /api/admin/users — creates an active account directly. */
    public function create_user()
    {
        $this->require_admin('POST');
        $input = $this->api->body();
        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? '');

        if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, self::ROLES, true)) {
            $this->api->respond_error('Username, a valid email, a password of at least 8 characters, and a valid role are required.', 422);
        }
        if ($this->users->usernameOrEmailExists($username, $email)) {
            $this->api->respond_error('Username or email already exists.', 422);
        }
        $age = filter_var($input['age'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 18, 'max_range' => 120]]);
        $id = $this->users->createUser([
            'username' => $username,
            'first_name' => trim((string) ($input['first_name'] ?? '')) ?: null,
            'middle_name' => trim((string) ($input['middle_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($input['last_name'] ?? '')) ?: null,
            'address' => trim((string) ($input['address'] ?? '')) ?: null,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'age' => $age === false ? null : $age,
            'gender' => trim((string) ($input['gender'] ?? '')) ?: null,
            'contact_number' => trim((string) ($input['contact_number'] ?? '')) ?: null,
            'is_active' => 1
        ]);
        $this->platform->audit($this->admin_id(), 'CREATE_ACCOUNT', 'User', $id, $email);
        $this->api->respond(['message' => 'Account created.', 'user_id' => (int) $id], 201);
    }

    /** PUT /api/admin/reports/{id}/resolve */
    public function resolve_report($id)
    {
        $this->require_admin('PUT');
        $reportId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $report = $reportId === false ? null : $this->platform->report_details($reportId);
        if (!$report) {
            $this->api->respond_error('Report not found.', 404);
        }
        if ($report['status'] !== 'open') {
            $this->api->respond_error('This report has already been reviewed.', 409);
        }

        if (!empty($report['post_id'])) {
            $input = $this->api->body();
            $action = $input['action'] ?? '';
            if (!in_array($action, ['remove', 'dismiss'], true)) {
                $this->api->respond_error('Choose whether to remove the reported post or dismiss the report.', 422);
            }
            if ($action === 'remove') {
                $post = $this->community->post_for_report((int) $report['post_id']);
                if (!$post) {
                    $this->api->respond_error('The reported post no longer exists. Dismiss the report without changing any content.', 409);
                }
                $this->community->delete_post((int) $post['post_id']);
                if (!empty($post['listing_id'])) {
                    $this->community->set_listing_available((int) $post['listing_id'], false);
                }
                $this->platform->notify(
                    (int) $report['reported_user_id'],
                    'Your post was removed',
                    'The administrator removed your post #' . (int) $post['post_id'] . ' after reviewing user report #' . (int) $reportId . '. Please follow the community guidelines when posting.'
                );
                if (!empty($report['reporter_id'])) {
                    $this->platform->notify(
                        (int) $report['reporter_id'],
                        'Post report reviewed',
                        'Thank you for reporting post #' . (int) $post['post_id'] . '. The administrator reviewed your report and removed the post.'
                    );
                }
            } elseif (!empty($report['reporter_id'])) {
                $this->platform->notify(
                    (int) $report['reporter_id'],
                    'Post report reviewed',
                    'The administrator reviewed report #' . (int) $reportId . ' and found no reason to remove the reported post. No action was taken on that post.'
                );
            }
            $reviewAction = $action === 'remove' ? 'removed' : 'dismissed';
            $this->platform->resolve_report((int) $reportId, $reviewAction);
            $this->platform->audit($this->admin_id(), strtoupper($action) . '_REPORTED_POST', 'Post', (int) $report['post_id'], 'Report #' . (int) $reportId);
            $this->api->respond(['message' => $action === 'remove' ? 'Post removed and both users notified.' : 'Report dismissed; the post was not changed and the reporter was notified.']);
        }

        $this->platform->resolve_report((int) $reportId);
        $this->platform->audit($this->admin_id(), 'RESOLVE_REPORT', 'Report', (int) $reportId, null);
        $this->api->respond(['message' => 'Report resolved.']);
    }

    /** PUT /api/admin/complaints/{id}/resolve */
    public function resolve_complaint($id)
    {
        $this->require_admin('PUT');
        $complaintId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $complaint = $complaintId === false ? null : $this->support->complaint($complaintId);
        if (!$complaint) {
            $this->api->respond_error('Complaint not found.', 404);
        }
        if ($complaint['status'] !== 'open') {
            $this->api->respond_error('This complaint has already been resolved.', 409);
        }
        if (!filter_var($complaint['reporter_email'], FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('The complaint has no valid email address for its resolution notice.', 422);
        }

        $mailer = $this->call->library('Mailer');
        if (!$mailer->is_configured()) {
            $this->api->respond_error('Email is not set up yet. Set MAIL_USERNAME and MAIL_PASSWORD before resolving complaints.', 503);
        }
        $resolvedAt = date('Y-m-d H:i:s');
        $name = htmlspecialchars($complaint['reporter_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars($complaint['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<p>Hi ' . $name . ',</p>'
            . '<p>Your complaint <strong>#' . (int) $complaintId . '</strong> has been reviewed and marked as resolved by the AgriTrace administration team.</p>'
            . '<p><strong>Resolved on:</strong> ' . htmlspecialchars($resolvedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p><strong>Issue submitted:</strong></p>'
            . '<div style="white-space:pre-wrap;border-left:3px solid #10b981;padding:10px 14px;background:#f8fafc">' . $message . '</div>'
            . '<p>If you believe the issue is not resolved, please reply to this email so our team can follow up.</p>';
        try {
            $mailer->send(
                $complaint['reporter_email'],
                'AgriTrace complaint #' . (int) $complaintId . ' resolved',
                $mailer->layout('Your complaint has been resolved', $body),
                "Hi {$complaint['reporter_name']},\n\nYour complaint #" . (int) $complaintId
                    . " has been reviewed and marked resolved on {$resolvedAt}.\n\nIssue submitted:\n{$complaint['message']}"
                    . "\n\nIf you believe the issue is not resolved, reply to this email."
            );
        } catch (RuntimeException $error) {
            $this->platform->audit($this->admin_id(), 'COMPLAINT_RESOLUTION_EMAIL_FAILED', 'SupportComplaint', $complaintId, $error->getMessage());
            $this->api->respond_error('The resolution email could not be sent. The complaint remains open. ' . $error->getMessage(), 503);
        }

        if (!$this->support->resolve_complaint($complaintId)) {
            $this->api->respond_error('The resolution email was sent, but the complaint status could not be updated. Refresh and verify the complaint status.', 500);
        }
        $this->platform->audit($this->admin_id(), 'RESOLVE_COMPLAINT', 'SupportComplaint', $complaintId);
        $this->api->respond(['message' => 'Complaint resolved and a transparency email was sent to ' . $complaint['reporter_email'] . '.']);
    }

    /** GET /api/admin/reports/{report}/pdf */
    public function report_pdf($report)
    {
        $this->require_admin('GET');
        if ($report === 'introduction-letter') {
            $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
                @page { margin: 42px 48px; }
                body { font-family: DejaVu Sans, sans-serif; color: #24332d; font-size: 11px; line-height: 1.55; }
                .letterhead { width: 100%; padding-bottom: 14px; border-bottom: 3px solid #087443; }
                .brand-layout { width: auto; border-collapse: collapse; }
                .brand-layout td { border: 0; padding: 0; vertical-align: middle; }
                .logo-cell { padding-right: 12px !important; }
                .logo-mark { width: 44px; height: 44px; }
                .brand { color: #075c37; font-size: 20px; font-weight: bold; letter-spacing: .3px; }
                .subtitle { color: #64746b; font-size: 9px; margin-top: 2px; }
                .date { margin: 22px 0 16px; color: #59665f; }
                .recipient { margin-bottom: 14px; line-height: 1.5; }
                .subject { margin: 0 0 15px; color: #075c37; font-size: 13px; font-weight: bold; }
                p { margin: 0 0 11px; }
                .principles { width: 100%; margin: 8px 0 13px; border-collapse: collapse; }
                .principles th, .principles td { border: 1px solid #d5e4da; padding: 7px 9px; text-align: left; vertical-align: top; }
                .principles th { background: #eaf5ee; color: #075c37; font-size: 10px; }
                .principles td:first-child { width: 25%; color: #075c37; font-weight: bold; }
                .signature { margin-top: 18px; }
                .signature-name { color: #075c37; font-weight: bold; }
                .contact { color: #64746b; font-size: 9px; }
            </style></head><body>
                <div class="letterhead"><table class="brand-layout"><tr>
                    <td class="logo-cell"><svg class="logo-mark" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-label="AgriTrace logo">
                        <circle cx="24" cy="24" r="23" fill="#eaf5ee"/>
                        <path d="M35 11C24 12 14 17 13 27c-.4 4 2.5 7 6.5 6.7C29 32.8 33.6 22 35 11Z" fill="#0b8a52"/>
                        <path d="M12 38c5.5-7.5 11-13.2 19-18" fill="none" stroke="#075c37" stroke-width="2.5" stroke-linecap="round"/>
                    </svg></td>
                    <td><div class="brand">AgriTrace Reports</div><div class="subtitle">Independent Agricultural Traceability Reporting</div></td>
                </tr></table></div>
                <p class="date">[Date]</p>
                <div class="recipient"><strong>[Recipient Name]</strong><br>[Recipient Title]<br>[Organization Name]</div>
                <p class="subject">Subject: Supporting Your Agricultural Traceability Goals</p>
                <p>Dear [Recipient Name],</p>
                <p>AgriTrace Reports is an independent agricultural traceability reporting organization. We help partners understand the journey of agricultural products from origin to consumption, presenting clear records of where products come from, how they move, and the standards they meet.</p>
                <p>Our services include tracing products through each stage of the supply chain, verifying relevant compliance information, and supporting transparent reporting for business partners and other stakeholders. We aim to make information useful for decisions, not simply collect data. Our approach is designed to build confidence in the supply chain while respecting the people and organizations who contribute to it.</p>
                <p>Three principles guide our work:</p>
                <table class="principles"><thead><tr><th>Core principle</th><th>Our commitment</th></tr></thead><tbody>
                    <tr><td>Verifiable records</td><td>We organize clear, traceable information that can be reviewed and supported by records at each relevant stage.</td></tr>
                    <tr><td>Practicality</td><td>We work toward processes that are realistic for farmers and cooperatives, mindful of their time, tools, and day-to-day responsibilities.</td></tr>
                    <tr><td>Confidentiality</td><td>We treat partner information with care and handle it responsibly, sharing it only in ways agreed with the organizations involved.</td></tr>
                </tbody></table>
                <p>These principles allow us to support stronger reporting without losing sight of the people behind each product. We would welcome the opportunity to learn about [Organization Name]’s priorities and discuss where our services could be useful.</p>
                <p>Please let us know a convenient time for a meeting, either in person or online. We are available on [Proposed Date or Dates], and would be glad to work around your schedule. You may reach us at the contact details below to arrange a time or request further information.</p>
                <p>Thank you for considering this introduction. We look forward to the possibility of working with you to support practical, transparent agricultural traceability.</p>
                <div class="signature">Yours sincerely,<br><br><span class="signature-name">[Your Name]</span><br>[Your Title]<br>AgriTrace Reports<br><span class="contact">[Email Address] &nbsp;|&nbsp; [Phone Number]</span></div>
            </body></html>';

            $pdf = new \Dompdf\Dompdf();
            $pdf->set_option('isRemoteEnabled', false);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'portrait');
            $pdf->render();
            $output = $pdf->output();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="AgriTrace_Introduction_Letter.pdf"');
            header('Content-Length: ' . strlen($output));
            echo $output;
            exit;
        }
        $query = $this->api->get_query_params();
        $dateFrom = $this->validReportDate($query['start_date'] ?? null);
        $dateTo = $this->validReportDate($query['end_date'] ?? null);
        if (($query['start_date'] ?? '') !== '' && $dateFrom === null) {
            $this->api->respond_error('The report start date must use YYYY-MM-DD format.', 422);
        }
        if (($query['end_date'] ?? '') !== '' && $dateTo === null) {
            $this->api->respond_error('The report end date must use YYYY-MM-DD format.', 422);
        }
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            $this->api->respond_error('The report start date cannot be after the end date.', 422);
        }
        $tableNames = [
            'farms' => 'farms',
            'products' => 'products',
            'batches' => 'batches',
            'harvests' => 'harvests',
            'qrcodes' => 'qrcodes',
            'traceability' => 'traceability',
            'shipments' => 'shipments',
            'markets' => 'markets',
            'notifications' => 'notifications',
            'weather' => 'weather',
            'logs' => 'logs',
            'users' => 'users'
        ];
        if (!in_array($report, ['reports'], true)
            && !array_key_exists($report, $tableNames)) {
            $this->api->respond_error('Unknown report type.', 404);
        }

        if ($report === 'reports') {
            $rows = array_merge(
                array_map(static function ($row) {
                    return [
                        'report_id' => $row['report_id'],
                        'record_type' => 'User report',
                        'reported_user' => $row['reported_user'],
                        'reported_by' => $row['reported_by'],
                        'post_id' => $row['post_id'],
                        'reason' => $row['reason'],
                        'review_action' => $row['review_action'],
                        'status' => $row['status'],
                        'created_at' => $row['created_at'],
                    ];
                }, $this->platform->reports('open')),
                array_map(static function ($complaint) {
                    return [
                        'report_id' => $complaint['complaint_id'],
                        'reported_user' => $complaint['reporter_name'],
                        'reason' => $complaint['message'],
                        'reported_by' => $complaint['reporter_email'],
                        'record_type' => 'Resolved complaint',
                        'status' => $complaint['status'],
                        'created_at' => $complaint['created_at'],
                        'resolved_at' => $complaint['resolved_at'],
                    ];
                }, $this->support->resolved_complaints())
            );
            $columns = $rows ? array_map(function ($field) {
                return ['field' => $field, 'label' => ucwords(str_replace('_', ' ', $field))];
            }, array_keys($rows[0])) : [];
        } else {
            $snapshot = $this->call->model('AdminDatabase_model')->getSnapshot();
            $key = $tableNames[$report];
            $table = $snapshot['tables'][$key] ?? ['columns' => [], 'rows' => []];
            $columns = $table['columns'];
            $rows = $table['rows'];
        }
        if ($dateFrom || $dateTo) {
            $rows = array_values(array_filter($rows, function ($row) use ($dateFrom, $dateTo) {
                $value = $row['created_at'] ?? $row['harvest_date'] ?? $row['paid_at'] ?? null;
                if (!$value) return false;
                $date = substr((string) $value, 0, 10);
                return (!$dateFrom || $date >= $dateFrom) && (!$dateTo || $date <= $dateTo);
            }));
        }

        $escape = static function ($value) {
            return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $headers = '';
        foreach ($columns as $column) {
            $headers .= '<th>' . $escape($column['label']) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($columns as $column) {
                $body .= '<td>' . $escape($row[$column['field']] ?? '') . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="' . max(1, count($columns)) . '">No records.</td></tr>';
        }
        $title = 'AgriTrace ' . ucwords(str_replace('-', ' ', $report)) . ' Report';
        $period = ($dateFrom || $dateTo) ? 'Reporting period: ' . ($dateFrom ?: 'Beginning') . ' to ' . ($dateTo ?: 'Present') : 'All available records';
        $generatedAt = date('F j, Y \a\t g:i A');
        $formalDate = date('F j, Y');
        $recordCount = count($rows);
        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
            @page { margin: 28px; }
            body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 9px; }
            .letterhead { border-bottom: 3px solid #065f46; padding-bottom: 10px; margin-bottom: 22px; }
            .brand-layout { width: auto; border-collapse: collapse; }
            .brand-layout td { border: 0; padding: 0; vertical-align: middle; }
            .logo-cell { padding-right: 10px !important; }
            .logo-mark { width: 38px; height: 38px; }
            .brand { color: #065f46; font-size: 21px; font-weight: bold; letter-spacing: .5px; }
            .subtitle { color: #6b7280; font-size: 9px; margin-top: 3px; }
            .letter { margin-bottom: 24px; line-height: 1.5; font-size: 10px; }
            .letter p { margin: 0 0 8px; }
            .subject { font-weight: bold; color: #1f2937; }
            h1 { font-size: 17px; margin: 0 0 5px; color: #065f46; }
            .summary { color: #6b7280; margin: 0 0 14px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #d1d5db; padding: 5px; text-align: left; vertical-align: top; }
            th { background: #ecfdf5; }
            tr { page-break-inside: avoid; }
        </style></head><body>
            <div class="letterhead"><table class="brand-layout"><tr>
                <td class="logo-cell"><svg class="logo-mark" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-label="AgriTrace logo">
                    <circle cx="24" cy="24" r="23" fill="#eaf5ee"/>
                    <path d="M35 11C24 12 14 17 13 27c-.4 4 2.5 7 6.5 6.7C29 32.8 33.6 22 35 11Z" fill="#0b8a52"/>
                    <path d="M12 38c5.5-7.5 11-13.2 19-18" fill="none" stroke="#075c37" stroke-width="2.5" stroke-linecap="round"/>
                </svg></td>
                <td><div class="brand">AgriTrace</div><div class="subtitle">Agricultural Traceability and Administration System</div></td>
            </tr></table></div>
            <div class="letter">
                <p>' . $escape($formalDate) . '</p>
                <p><strong>To:</strong> Administrator<br><strong>Subject:</strong> Submission of ' . $escape($title) . '</p>
                <p>Dear Administrator,</p>
                <p>Please find attached the official ' . $escape($title) . ' for your review and administrative reference. This document contains ' . $recordCount . ' record(s) covering the period specified below.</p>
                <p class="subject">' . $escape($period) . '</p>
                <p>This report was generated through the AgriTrace administrative system on ' . $escape($generatedAt) . '. Please retain this document as part of the system records.</p>
                <p>Respectfully submitted,<br><strong>AgriTrace Administration</strong></p>
            </div>
            <h1>' . $escape($title) . '</h1><p class="summary">' . $escape($period) . '<br>Generated ' . $escape($generatedAt) . ' &bull; Records: ' . $recordCount . '</p>
            <table><thead><tr>' . $headers . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';

        $pdf = new \Dompdf\Dompdf();
        $pdf->set_option('isRemoteEnabled', false);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $output = $pdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="AgriTrace_' . preg_replace('/[^a-z0-9_-]/i', '_', $report) . '.pdf"');
        header('Content-Length: ' . strlen($output));
        echo $output;
        exit;
    }

    private function validReportDate($value)
    {
        if ($value === null || $value === '') return null;
        $date = DateTime::createFromFormat('!Y-m-d', (string) $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * POST /api/admin/reports/email — emails the sales summary with an orders CSV attached.
     * Sent to MAIL_REPORT_TO, or to the administrator account when that is not set.
     */
    public function email_report()
    {
        $this->require_admin('POST');
        $mailer = $this->call->library('Mailer');
        if (!$mailer->is_configured()) {
            $this->api->respond_error('Email is not set up yet. Set MAIL_USERNAME and MAIL_PASSWORD in .env.', 503);
        }
        $recipient = config_item('mail_report_to') ?: null;
        if (!$recipient) {
            $admin = $this->db->table('users')->select('email')->where(['role' => 'admin', 'is_active' => 1])->limit(1)->get(PDO::FETCH_ASSOC);
            $recipient = $admin ? $admin['email'] : null;
        }
        if (!$recipient) {
            $this->api->respond_error('No report recipient is set. Set MAIL_REPORT_TO in .env.', 422);
        }

        $orders = $this->db->table('orders')
            ->select('orders.order_id, u.username AS buyer, orders.total_amount, orders.payment_status, orders.status, orders.paid_at, orders.created_at')
            ->inner_join('users u', 'u.id = orders.buyer_id')
            ->order_by('orders.order_id', 'DESC')
            ->limit(1000)
            ->get_all(PDO::FETCH_ASSOC);
        $paid = array_filter($orders, fn ($o) => $o['payment_status'] === 'paid');
        $revenue = array_sum(array_column($paid, 'total_amount'));
        $listings = (int) $this->db->table('marketplace_listings')->where(['is_available' => 1])->where('stock', '>', 0)->count();
        $farmers = (int) $this->db->table('users')->where(['role' => 'farmer', 'is_active' => 1])->count();
        $rows = '<tr><td>Paid orders</td><td align="right">' . count($paid) . '</td></tr>'
            . '<tr><td>Revenue (paid)</td><td align="right">PHP ' . number_format($revenue, 2) . '</td></tr>'
            . '<tr><td>Active listings</td><td align="right">' . $listings . '</td></tr>'
            . '<tr><td>Active farmers</td><td align="right">' . $farmers . '</td></tr>'
            . '<tr><td>Published community posts</td><td align="right">' . (int) $this->db->table('community_posts')->where('status', 'approved')->count() . '</td></tr>';
        $html = $mailer->report_email('<p>Generated ' . date('Y-m-d H:i') . '.</p><table style="width:100%;font-size:14px">' . $rows . '</table><p>The full order list is attached as CSV.</p>');

        $csv = "order_id,buyer,total_amount,payment_status,status,paid_at,created_at\n";
        foreach ($orders as $o) {
            $csv .= implode(',', [$o['order_id'], '"' . str_replace('"', '""', $o['buyer']) . '"', $o['total_amount'],
                $o['payment_status'], $o['status'], $o['paid_at'], $o['created_at']]) . "\n";
        }

        try {
            $mailer->send($recipient, 'AgriTrace sales report ' . date('Y-m-d'), $html, '', [
                ['name' => 'agritrace-orders-' . date('Ymd') . '.csv', 'content' => $csv, 'type' => 'text/csv'],
            ]);
        } catch (RuntimeException $e) {
            $this->api->respond_error($e->getMessage(), 502);
        }
        $this->platform->audit($this->admin_id(), 'REPORT_EMAILED', 'Report', 'sales', $recipient);
        $this->api->respond(['message' => 'The report was emailed.', 'sent_to' => $recipient]);
    }

    private function require_admin($method)
    {
        $this->api->require_method($method);
        $auth = $this->api->require_jwt();
        if (($auth['role'] ?? '') !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }
        $this->adminId = (int) $auth['sub'];
    }

    private $adminId = 0;

    private function admin_id()
    {
        return $this->adminId;
    }
}
