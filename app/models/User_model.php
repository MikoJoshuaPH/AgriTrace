<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';

    public function findById($id)
    {
        return $this->db->table('users')
            ->select('id, username, first_name, middle_name, last_name, address, email, role, is_verified, is_active, age, gender, contact_number, profile_pic, latitude, longitude, location_label, created_at, updated_at')
            ->where('id', $id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function findByLogin($login)
    {
        return $this->db->table('users')
            ->select('id, username, email, password, role, is_active, first_name, last_name, two_factor_code_hash, two_factor_attempts, two_factor_expires_at')
            ->grouped(function ($db) use ($login) {
                $db->where('username', $login)->or_where('email', $login);
            })
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function findByEmail($email)
    {
        return $this->db->table('users')
            ->select('id, username, email, first_name, last_name, is_active')
            ->where('email', $email)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function password_hash_for($id)
    {
        $row = $this->db->table('users')->select('password')->where('id', $id)->limit(1)->get(PDO::FETCH_ASSOC);
        return $row ? $row['password'] : '';
    }

    public function usernameOrEmailExists($username, $email, $exceptId = null)
    {
        $query = $this->db->table('users')->select('id')->grouped(function ($db) use ($username, $email) {
            $db->where('username', $username)->or_where('email', $email);
        });
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        return (bool) $query->limit(1)->get(PDO::FETCH_ASSOC);
    }

    public function createUser(array $data)
    {
        $data['is_active'] = isset($data['is_active']) ? (int) $data['is_active'] : 1;
        return $this->db->table('users')->insert([
            'username' => $data['username'], 'first_name' => $data['first_name'] ?? null,
            'middle_name' => $data['middle_name'] ?? null, 'last_name' => $data['last_name'] ?? null,
            'address' => $data['address'] ?? null, 'email' => $data['email'], 'password' => $data['password'],
            'role' => $data['role'], 'age' => $data['age'] ?? null, 'gender' => $data['gender'] ?? null,
            'contact_number' => $data['contact_number'] ?? null, 'profile_pic' => $data['profile_pic'] ?? null,
            'is_active' => $data['is_active']
        ]);
    }

    public function updateUser($id, array $updates)
    {
        if (!$updates) {
            return;
        }
        $updates['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('users')->where('id', $id)->update($updates);
    }

    public function setActive($id, $active)
    {
        $this->db->table('users')->where('id', $id)->update([
            'is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function count_admins()
    {
        return (int) $this->db->table('users')->where(['role' => 'admin', 'is_active' => 1])->count();
    }

    /** Stores a hashed one-time sign-in code, valid for 10 minutes. */
    public function set_two_factor($id, $hash)
    {
        $this->db->table('users')->where('id', $id)->update([
            'two_factor_code_hash' => $hash,
            'two_factor_expires_at' => date('Y-m-d H:i:s', time() + 600),
            'two_factor_attempts' => 0,
        ]);
    }

    public function bump_two_factor_attempts($id)
    {
        $this->db->table('users')->where('id', $id)->increment('two_factor_attempts', 1);
    }

    public function clear_two_factor($id)
    {
        $this->db->table('users')->where('id', $id)->update([
            'two_factor_code_hash' => null, 'two_factor_expires_at' => null, 'two_factor_attempts' => 0,
        ]);
    }

    /** Stores a hashed password-reset token, valid for 30 minutes. */
    public function set_reset($id, $hash)
    {
        $this->db->table('users')->where('id', $id)->update([
            'password_reset_hash' => $hash,
            'password_reset_expires_at' => date('Y-m-d H:i:s', time() + 1800),
        ]);
    }

    /** Returns the active user id that owns a valid reset-token hash, or null. */
    public function find_by_reset_hash($hash)
    {
        $row = $this->db->table('users')
            ->select('id, password_reset_expires_at')
            ->where(['password_reset_hash' => $hash, 'is_active' => 1])
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
        if (!$row || strtotime((string) $row['password_reset_expires_at']) < time()) {
            return null;
        }
        return (int) $row['id'];
    }

    public function clear_reset($id)
    {
        $this->db->table('users')->where('id', $id)->update([
            'password_reset_hash' => null, 'password_reset_expires_at' => null,
        ]);
    }
}
