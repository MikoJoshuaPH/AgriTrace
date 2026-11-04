<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Farmer_application_model extends Model
{
    private const SELECT = 'fa.application_id, fa.applicant_id, fa.farm_name, fa.farm_address,
        fa.province, fa.municipality, fa.barangay, fa.latitude, fa.longitude, fa.farm_size,
        fa.farm_size_unit, fa.farm_description, fa.status, fa.rejection_reason,
        fa.reviewed_by, fa.reviewed_at, fa.created_at, fa.updated_at,
        u.username, u.email, u.first_name, u.last_name, u.role';

    public function findById($applicationId)
    {
        return $this->db->table('farmer_applications fa')
            ->select(self::SELECT)
            ->inner_join('users u', 'u.id = fa.applicant_id')
            ->where('fa.application_id', $applicationId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function latestForApplicant($applicantId)
    {
        return $this->db->table('farmer_applications')
            ->select('application_id, farm_name, province, municipality, barangay, latitude, longitude, farm_size, farm_size_unit, farm_description, status, rejection_reason, reviewed_at, created_at, updated_at')
            ->where('applicant_id', $applicantId)
            ->order_by('application_id', 'DESC')
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function pendingForApplicant($applicantId)
    {
        return $this->db->table('farmer_applications')
            ->select('application_id')
            ->where(['applicant_id' => $applicantId, 'status' => 'pending'])
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function listAll($status = null, $limit = 100)
    {
        $query = $this->db->table('farmer_applications fa')
            ->select(self::SELECT)
            ->inner_join('users u', 'u.id = fa.applicant_id')
            ->order_by('fa.application_id', 'DESC')
            ->limit(max(1, min((int) $limit, 200)));
        if ($status !== null) {
            $query->where('fa.status', $status);
        }
        return $query->get_all(PDO::FETCH_ASSOC);
    }

    public function create($applicantId, array $data)
    {
        $this->db->transaction();
        try {
            $applicationId = (int) $this->db->table('farmer_applications')->insert([
                'applicant_id' => $applicantId,
                'farm_name' => $data['farm_name'],
                'farm_address' => $data['farm_address'] ?? null,
                'province' => $data['province'] ?? null,
                'municipality' => $data['municipality'] ?? null,
                'barangay' => $data['barangay'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'farm_size' => $data['farm_size'] ?? null,
                'farm_size_unit' => $data['farm_size_unit'] ?? null,
                'farm_description' => $data['farm_description'] ?? null,
                'status' => 'pending',
            ]);
            $this->db->table('farmer_application_history')->insert([
                'application_id' => $applicationId,
                'previous_status' => null,
                'new_status' => 'pending',
                'changed_by' => $applicantId,
                'reason' => null,
            ]);
            $this->db->commit();
            return $applicationId;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function review($applicationId, $adminId, $status, $reason = null)
    {
        $application = $this->findById($applicationId);
        if (!$application) {
            return null;
        }
        if ($application['status'] !== 'pending') {
            return false;
        }

        $this->db->transaction();
        try {
            $updated = $this->db->table('farmer_applications')
                ->where(['application_id' => $applicationId, 'status' => 'pending'])
                ->update([
                    'status' => $status,
                    'rejection_reason' => $status === 'rejected' ? $reason : null,
                    'reviewed_by' => $adminId,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ]);
            if (!$updated) {
                $this->db->roll_back();
                return false;
            }

            if ($status === 'approved') {
                $this->db->table('users')->where('id', $application['applicant_id'])->update([
                    'role' => 'farmer',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->db->table('farms')->insert([
                    'farmer_id' => $application['applicant_id'],
                    'farm_name' => $application['farm_name'],
                    'farm_address' => $application['farm_address'],
                    'province' => $application['province'],
                    'municipality' => $application['municipality'],
                    'barangay' => $application['barangay'],
                    'latitude' => $application['latitude'],
                    'longitude' => $application['longitude'],
                    'farm_size' => $application['farm_size'],
                    'farm_size_unit' => $application['farm_size_unit'],
                    'farm_description' => $application['farm_description'],
                ]);
            }

            $this->db->table('farmer_application_history')->insert([
                'application_id' => $applicationId,
                'previous_status' => $application['status'],
                'new_status' => $status,
                'changed_by' => $adminId,
                'reason' => $reason,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
