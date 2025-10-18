<?php
// app/models/User.php

class User {
    private $db;
    private $table = 'users';

    public function __construct() {
        $f3 = \Base::instance();
        $this->db = $f3->get('DB');
    }

    /**
     * Create new user
     */
    public function create($data) {
        $requiredFields = [
            "name",
            "email",
            "password",
            "age",
            "height",
            "weight",
            "activity",
            "gender"
        ];;
        $gender = $data['gender'];      // 0=male, 1=female
        $age = $data['age'];
        $height = $data['height'];      // cm
        $weight = $data['weight'];      // kg
        $activity = $data['activity'] ?? 2;  // 1-4
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        $this->db->exec(
            "INSERT INTO {$this->table} 
             (name, email, password, age, gender, height, weight, 
              activity) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['name'],
                $data['email'],
                $data['password'],
                $age,
                $gender,
                $height,
                $weight,
                $activity
            ]
        );
        
        return $this->db->lastInsertId();
    }

    /**
     * Find user by email
     */
    public function findByEmail($email) {
        $result = $this->db->exec(
            "SELECT * FROM {$this->table} WHERE email = ? LIMIT 1",
            [$email]
        );
        return $result ? $result[0] : null;
    }

    /**
     * Find user by ID
     * Returns raw profile data - Android calculates age & age_range locally
     */
    public function findById($id) {
        $result = $this->db->exec(
            "SELECT *
             FROM {$this->table} 
             WHERE id = ? LIMIT 1",
            [$id]
        );
        
        return $result ? $result[0] : null;
    }

    public function updateCounter($userId, $counter) {
        return $this->db->exec(
            "UPDATE {$this->table} SET wma_counter = ? WHERE id = ?",
            [$counter, $userId]
        );
    }
    public function getCounter($userId) {
        $row = $this->db->exec(
            "SELECT wma_counter FROM {$this->table} WHERE id = ?",
            $userId
        );
        return $row;
    }

    /**
     * Update user profile (minimal fields)
     */
    public function update($id, $data) {
        $allowedFields = [
            "name",
            "email",
            "password",
            "age",
            "height",
            "weight",
            "activity",
            "gender"
        ];

        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "{$key} = ?";
                $values[] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        
        return $this->db->exec(
            "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?",
            $values
        );
    }

    /**
     * Delete user
     */
    public function delete($id) {
        return $this->db->exec(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get all users (admin function)
     */
    // public function findAll($limit = 100, $offset = 0) {
    //     return $this->db->exec(
    //         "SELECT 
    //             id, name, email, gender, 
    //             activity_level, health_condition, 
    //             created_at 
    //          FROM {$this->table}
    //          ORDER BY created_at DESC 
    //          LIMIT ? OFFSET ?",
    //         [$limit, $offset]
    //     );
    // }

    /**
     * Update password
     */
    public function updatePassword($id, $hashedPassword) {
        return $this->db->exec(
            "UPDATE {$this->table} SET password = ?, updated_at = NOW() WHERE id = ?",
            [$hashedPassword, $id]
        );
    }

    /**
     * Check if email exists
     */
    public function emailExists($email) {
        $result = $this->db->exec(
            "SELECT COUNT(*) as count FROM {$this->table} WHERE email = ?",
            [$email]
        );
        return $result && $result[0]['count'] > 0;
    }

    /**
     * Update last login timestamp
     */
    // public function updateLastLogin($id) {
    //     return $this->db->exec(
    //         "UPDATE {$this->table} SET last_login = NOW() WHERE id = ?",
    //         [$id]
    //     );
    // }
}