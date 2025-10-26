<?php
// app/models/NutritionalConsumption.php

class NutritionalConsumption {
    private $db;
    private $table = 'consumption_history';

    public function __construct() {
        $f3 = \Base::instance();
        $this->db = $f3->get('DB');
    }

    /**
     * Generate unique ID: userId|timestamp_microseconds
     * Example: 123|1704067200.123456
     * 
     * Microsecond precision ensures uniqueness without device_id
     */
    public function generateId($userId, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = microtime(true); // Float with microseconds
        }
        
        return $userId . '|' . number_format($timestamp, 6, '.', '');
    }

    /**
     * Create new nutritional consumption record
     */
    public function create($data) {
        // Generate ID if not provided
        if (!isset($data['id'])) {
            $timestamp = isset($data['consumed_at']) 
                ? strtotime($data['consumed_at']) + (microtime(true) - floor(microtime(true)))
                : microtime(true);
            $data['id'] = $this->generateId($data['user_id'], $timestamp);
        }

        $fields = ['id', 'user_id', 'food_id', 'urt_id', 'date_report', 'portion_quantity', 'percentage', 'updated_at'];

        $values = [];
        $placeholders = [];
        $insertFields = [];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $insertFields[] = $field;
                $placeholders[] = '?';
                $values[] = $data[$field];
            }
        }

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $insertFields) . ")
                VALUES (" . implode(', ', $placeholders) . ")";

        $this->db->exec($sql, $values);

        return $data['id'];
    }

    /**
     * Find consumption record by ID
     */    
    public function findById($id) {
        $result = $this->db->exec(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
        return $result ? $result[0] : null;
    }

    /**
     * Get all consumption records for a user
     */
    public function findByUserId($userId, $limit = 30, $offset = 0) {
        return $this->db->exec(
            "SELECT food_id, date_report FROM {$this->table}
             WHERE user_id = ?
             ORDER BY date_report DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }
    public function getFoodFeatures($userId) {
        return $this->db->exec(
            "SELECT * FROM get_user_nutrition_summary(?)",
            [$userId]
        );
    }

    /**
     * Get consumption records for a user within date range
     */
    public function findByUserIdAndDateRange($userId, $startDate, $endDate, $limit = 1000, $offset = 0) {
        return $this->db->exec(
            "SELECT * FROM {$this->table}
             WHERE user_id = ?
             AND consumed_at >= ?
             AND consumed_at <= ?
             ORDER BY consumed_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $startDate, $endDate, $limit, $offset]
        );
    }

    /**
     * Get daily summary for a user
     */
    public function getDailySummary($userId, $date) {
        $startOfDay = date('Y-m-d 00:00:00', strtotime($date));
        $endOfDay = date('Y-m-d 23:59:59', strtotime($date));

        $result = $this->db->exec(
            "SELECT
                SUM(calories) as total_calories,
                SUM(protein) as total_protein,
                SUM(carbohydrates) as total_carbohydrates,
                SUM(fat) as total_fat,
                SUM(fiber) as total_fiber,
                SUM(calcium) as total_calcium,
                SUM(iron) as total_iron,
                SUM(sodium) as total_sodium,
                SUM(potassium) as total_potassium,
                COUNT(*) as meal_count
             FROM {$this->table}
             WHERE user_id = ?
             AND consumed_at >= ?
             AND consumed_at <= ?",
            [$userId, $startOfDay, $endOfDay]
        );

        return $result ? $result[0] : null;
    }

    public function getUserMealCount($userId) {
        $result = $this->db->exec(
            "SELECT COUNT(*) as count FROM {$this->table} WHERE user_id = ?",
            [$userId]
        );
        return $result ? (int)$result[0]['count'] : 0;
    }

    public function update($id, $data) {
        $allowedFields = ['id', 'user_id', 'food_id', 'urt_id', 'date_report', 'portion_quantity', 'percentage', 'updated_at'];

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
        $values[] = $id;

        return $this->db->exec(
            "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?",
            $values
        );
    }

    public function calculateNutrition($foodId, $urtId, $quantity) {
        $f3 = \Base::instance();
        $db = $f3->get('DB');

        $food = $db->exec(
            "SELECT * FROM data_makanan WHERE id = ? LIMIT 1",
            [$foodId]
        );

        if (!$food) {
            throw new Exception("Food not found: ID {$foodId}");
        }
        $food = $food[0];

        $gramsPerPortion = 100;
        $portionName = 'gram';

        if ($urtId) {
            $urt = $db->exec(
                "SELECT * FROM urt_list WHERE id = ? LIMIT 1",
                [$urtId]
            );

            if (!$urt) {
                throw new Exception("URT not found: ID {$urtId}");
            }
            $urt = $urt[0];

            $relation = $db->exec(
                "SELECT * FROM data_makanan_urt
                 WHERE data_makanan_id = ? AND urt_list_id = ? LIMIT 1",
                [$foodId, $urtId]
            );

            if (!$relation) {
                throw new Exception("Invalid food-URT combination");
            }

            $gramsPerPortion = floatval($urt['gram_ml_per_porsi']);
            $portionName = $urt['nama_urt'];
        }

        $totalGrams = $gramsPerPortion * floatval($quantity);

        // Apply BDD (Edible Portion)
        $bdd = isset($food['bdd']) && floatval($food['bdd']) > 0 
            ? floatval($food['bdd']) / 100 
            : 1.0;
        
        $edibleGrams = $totalGrams * $bdd;
        $multiplier = $edibleGrams / 100;

        return [
            'food_name' => $food['nama_bahan'],
            'portion_name' => $portionName,
            'portion_grams' => round($totalGrams, 2),
            'calories' => round(floatval($food['energi']) * $multiplier, 2),
            'protein' => round(floatval($food['protein']) * $multiplier, 2),
            'carbohydrates' => round(floatval($food['karbohidrat']) * $multiplier, 2),
            'fat' => round(floatval($food['lemak']) * $multiplier, 2),
            'fiber' => round(floatval($food['serat']) * $multiplier, 2),
            'calcium' => round(floatval($food['kalsium']) * $multiplier, 2),
            'iron' => round(floatval($food['besi']) * $multiplier, 2),
            'sodium' => round(floatval($food['natrium']) * $multiplier, 2),
            'potassium' => round(floatval($food['kalium']) * $multiplier, 2),
        ];
    }

    /**
     * Delete consumption record
     */
    public function delete($id) {
        return $this->db->exec(
            "DELETE FROM {$this->table} WHERE id = ?",
            [$id]
        );
    }

    public function getUpdatedSince($userId, $timestamp) {
        return $this->db->exec(
            "SELECT * FROM {$this->table}
             WHERE user_id = ?
             AND updated_at > ?
             ORDER BY updated_at ASC",
            [$userId, date('Y-m-d H:i:s', strtotime($timestamp))]
        );
    }

    public function getUserConsumedFoods($userId) {
        return $this->db->exec(
            "SELECT DISTINCT data_makanan_id, food_name
             FROM {$this->table}
             WHERE user_id = ?
             ORDER BY consumed_at DESC",
            [$userId]
        );
    }
}