<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Administrator actions: account approval and creation, post moderation,
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

    /** GET /api/admin/queues — pending accounts, pending posts, open reports. */
    public function queues()
    {
        $this->require_admin('GET');
        $pendingUsers = $this->db->table('users')
            ->select('id, username, first_name, middle_name, last_name, email, role, is_verified, address, age, gender, contact_number, created_at')
            ->where('is_active', 0)
            ->order_by('id', 'DESC')
            ->limit(100)
            ->get_all(PDO::FETCH_ASSOC);
        $this->api->respond([
            'pendingUsers' => $pendingUsers,
            'pendingPosts' => $this->community->pending_posts(),
            'reports' => $this->platform->reports('open'),
            'complaints' => $this->support->open_complaints(),
        ]);
    }

    /** PUT /api/admin/users/{id}/approve */
    public function approve_user($id)
    {
        $this->require_admin('PUT');
        $user = $this->users->findById((int) $id);
        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }
        $this->users->setActive((int) $id, true);
        $this->platform->notify((int) $id, 'Account approved', 'Your AgriTrace account is approved. You can now sign in.');
        $this->platform->audit($this->admin_id(), 'APPROVE_ACCOUNT', 'User', (int) $id, $user['email']);
        $this->api->respond(['message' => 'Account approved.']);
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

    /** PUT /api/admin/posts/{id}/approve — publishes a pending post and its linked product listing. */
    public function approve_post($id)
    {
        $this->require_admin('PUT');
        $post = $this->community->post_owner((int) $id);
        if (!$post) {
            $this->api->respond_error('Post not found.', 404);
        }
        if ($post['status'] !== 'pending') {
            $this->api->respond_error('This post is not waiting for approval.', 422);
        }
        $this->community->set_post_status((int) $id, 'approved');
        if (!empty($post['listing_id'])) {
            $this->community->set_listing_available((int) $post['listing_id'], true);
        }
        $this->platform->notify((int) $post['author_id'], 'Post approved', 'Your post is now live on AgriTrace.');
        $this->platform->audit($this->admin_id(), 'APPROVE_POST', 'Post', (int) $id, $post['post_type']);
        $this->api->respond(['message' => 'Post approved.']);
    }

    /** PUT /api/admin/reports/{id}/resolve */
    public function resolve_report($id)
    {
        $this->require_admin('PUT');
        if (!$this->platform->report_exists((int) $id)) {
            $this->api->respond_error('Report not found.', 404);
        }
        $this->platform->resolve_report((int) $id);
        $this->platform->audit($this->admin_id(), 'RESOLVE_REPORT', 'Report', (int) $id, null);
        $this->api->respond(['message' => 'Report resolved.']);
    }

    /** PUT /api/admin/complaints/{id}/resolve */
    public function resolve_complaint($id)
    {
        $this->require_admin('PUT');
        $complaintId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($complaintId === false || !$this->support->complaint_exists($complaintId)) {
            $this->api->respond_error('Complaint not found.', 404);
        }
        $this->support->resolve_complaint($complaintId);
        $this->platform->audit($this->admin_id(), 'RESOLVE_COMPLAINT', 'SupportComplaint', $complaintId);
        $this->api->respond(['message' => 'Complaint resolved.']);
    }

    /** GET /api/admin/reports/{report}/pdf */
    public function report_pdf($report)
    {
        $this->require_admin('GET');
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
        if (!in_array($report, ['pending-accounts', 'pending-posts', 'reports'], true)
            && !array_key_exists($report, $tableNames)) {
            $this->api->respond_error('Unknown report type.', 404);
        }

        if ($report === 'pending-accounts') {
            $columns = [
                ['field' => 'id', 'label' => 'ID'],
                ['field' => 'first_name', 'label' => 'First Name'],
                ['field' => 'last_name', 'label' => 'Last Name'],
                ['field' => 'email', 'label' => 'Email'],
                ['field' => 'role', 'label' => 'Role'],
                ['field' => 'contact_number', 'label' => 'Contact']
            ];
            $rows = $this->db->table('users')
                ->select('id, first_name, last_name, email, role, contact_number')
                ->where('is_active', 0)->order_by('id', 'DESC')->limit(1000)
                ->get_all(PDO::FETCH_ASSOC);
        } elseif ($report === 'pending-posts') {
            $rows = $this->community->pending_posts();
            $columns = $rows ? array_map(function ($field) {
                return ['field' => $field, 'label' => ucwords(str_replace('_', ' ', $field))];
            }, array_keys($rows[0])) : [];
        } elseif ($report === 'reports') {
            $rows = $this->platform->reports('open');
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
        $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
            @page { margin: 24px; }
            body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 9px; }
            h1 { font-size: 18px; margin-bottom: 4px; color: #065f46; }
            p { color: #6b7280; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #d1d5db; padding: 5px; text-align: left; vertical-align: top; }
            th { background: #ecfdf5; }
            tr { page-break-inside: avoid; }
        </style></head><body><h1>' . $escape($title) . '</h1><p>Generated ' . $escape(date('Y-m-d H:i:s')) . '</p><table><thead><tr>' . $headers . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';

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
        $pendingUsers = (int) $this->db->table('users')->where('is_active', 0)->count();
        $pendingPosts = (int) $this->db->table('community_posts')->where('status', 'pending')->count();

        $rows = '<tr><td>Paid orders</td><td align="right">' . count($paid) . '</td></tr>'
            . '<tr><td>Revenue (paid)</td><td align="right">PHP ' . number_format($revenue, 2) . '</td></tr>'
            . '<tr><td>Active listings</td><td align="right">' . $listings . '</td></tr>'
            . '<tr><td>Active farmers</td><td align="right">' . $farmers . '</td></tr>'
            . '<tr><td>Accounts waiting for approval</td><td align="right">' . $pendingUsers . '</td></tr>'
            . '<tr><td>Posts waiting for approval</td><td align="right">' . $pendingPosts . '</td></tr>';
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
