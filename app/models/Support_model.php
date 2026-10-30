<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Support_model extends Model
{
    public function create_complaint($userId, $name, $email, $message)
    {
        return (int) $this->db->table('support_complaints')->insert([
            'user_id' => $userId,
            'reporter_name' => $name,
            'reporter_email' => $email,
            'message' => $message,
        ]);
    }

    public function open_complaints()
    {
        return $this->db->table('support_complaints')
            ->select('complaint_id, reporter_name, reporter_email, message, status, created_at')
            ->where('status', 'open')
            ->order_by('complaint_id', 'DESC')
            ->limit(100)
            ->get_all(PDO::FETCH_ASSOC);
    }

    public function complaint_exists($complaintId)
    {
        return (bool) $this->db->table('support_complaints')
            ->select('complaint_id')->where('complaint_id', $complaintId)->limit(1)->get();
    }

    public function resolve_complaint($complaintId)
    {
        return $this->db->table('support_complaints')->where('complaint_id', $complaintId)->update([
            'status' => 'resolved',
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
