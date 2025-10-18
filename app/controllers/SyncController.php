<?php
// app/controllers/SyncController.php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/NutritionalConsumption.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../redis/redis.php';
// require __DIR__ . '/../../vendor/autoload.php';
class SyncController extends BaseController {

    /**
     * ONE-WAY SYNC: Food database to client
     * GET /api/sync/foods?last_sync=2024-01-01T00:00:00Z
     */
    public function syncFoods() {
        $lastSync = $this->f3->get('GET.last_sync');

        $whereClause = '';
        $params = [];

        if ($lastSync && strtotime($lastSync)) {
            $syncTimestamp = date('Y-m-d H:i:s', strtotime($lastSync));
            $whereClause = 'WHERE updated_at > ?';
            $params[] = $syncTimestamp;
        }

        // Get foods
        $foods = $this->f3->get('DB')->exec(
            "SELECT *
            FROM data_makanan
            {$whereClause} 
            ORDER BY id",
            $params
        );

        // Get URTs
        $urts = $this->f3->get('DB')->exec(
            "SELECT *
            FROM urt_list 
            {$whereClause} 
            ORDER BY id",
            $params
        );

        // Get relations
        $relations = $this->f3->get('DB')->exec(
            "SELECT *
            FROM data_makanan_urt 
            {$whereClause} 
            ORDER BY data_makanan_id, urt_list_id",
            $params
        );
        $this->success('Food database retrieved', [
            'foods' => $foods,
            'urts' => $urts,
            'relations' => $relations,
            'sync_timestamp' => date('c'),
            'total_foods' => count($foods),
            'total_urts' => count($urts),
            'total_relations' => count($relations),
            'is_full_sync' => empty($lastSync)
        ]);
    }

    /**
     * TWO-WAY SYNC: User profile (MINIMAL FIELDS)
     * POST /api/sync/profile
     */
    public function syncProfile() {
        $userId = $this->f3->get('user_id');
        $data = $this->getRequestBody();

        $userModel = new User();
        $serverProfile = $userModel->findById($userId);

        if (!$serverProfile) {
            $this->error('User not found', 404);
            return;
        }

        // Check for conflicts
        if (isset($data['updated_at'])) {
            $clientTimestamp = strtotime($data['updated_at']);
            $serverTimestamp = strtotime($serverProfile['updated_at']);

            // Server is newer - conflict
            if ($serverTimestamp > $clientTimestamp) {
                unset($serverProfile['password']);
                $this->success('Profile conflict - server version returned', [
                    'profile' => $serverProfile,
                    'conflict' => true,
                    'resolution' => 'server_wins',
                    'server_updated_at' => $serverProfile['updated_at'],
                    'client_updated_at' => $data['updated_at']
                ]);
                return;
            }
        }

        // No conflict - update server with client data
        if (isset($data['profile']) && is_array($data['profile'])) {
            // MINIMAL FIELDS ONLY
            $allowedFields = [
                'name',
                'date_of_birth',
                'gender',
                'height',
                'weight',
                'activity_level',
                'health_condition'
            ];
            // Note: BMI is auto-calculated by database

            // Filter update data
            $updateData = [];
            foreach ($data['profile'] as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $updateData[$key] = $value;
                }
            }

            // Validate gender
            if (isset($updateData['gender']) && 
                !in_array($updateData['gender'], ['M', 'F'])) {
                $this->error('Invalid gender value. Must be M or F', 400);
                return;
            }

            // Validate activity_level
            $validActivityLevels = ['sedentary', 'light', 'moderate', 'active', 'very_active'];
            if (isset($updateData['activity_level']) && 
                !in_array($updateData['activity_level'], $validActivityLevels)) {
                $this->error('Invalid activity_level', 400);
                return;
            }

            // Validate health_condition
            $validHealthConditions = ['Normal', 'Diabetes', 'Hypertension', 'Heart Disease'];
            if (isset($updateData['health_condition']) && 
                !in_array($updateData['health_condition'], $validHealthConditions)) {
                $this->error('Invalid health_condition', 400);
                return;
            }

            // Validate height (100-250 cm)
            if (isset($updateData['height']) && 
                ($updateData['height'] < 100 || $updateData['height'] > 250)) {
                $this->error('Height must be between 100 and 250 cm', 400);
                return;
            }

            // Validate weight (20-300 kg)
            if (isset($updateData['weight']) && 
                ($updateData['weight'] < 20 || $updateData['weight'] > 300)) {
                $this->error('Weight must be between 20 and 300 kg', 400);
                return;
            }

            // Update server
            if (!empty($updateData)) {
                $userModel->update($userId, $updateData);
                $serverProfile = $userModel->findById($userId);
            }
        }

        // Return updated profile (BMI auto-calculated)
        unset($serverProfile['password']);
        $this->success('Profile synced successfully', [
            'profile' => $serverProfile,
            'conflict' => false
        ]);
    }

    /**
     * TWO-WAY SYNC: Nutritional consumption records
     * POST /api/sync/consumptions
     */
    public function syncConsumptions() {
        $userId = $this->f3->get('user_id');
        $data = $this->getRequestBody();

        $model = new NutritionalConsumption();
        $user = new User();
        $result = [
            'server_changes' => [],
            'accepted' => [],
            'rejected' => [],
            'conflicts' => [],
            'food_recommendation' => []
        ];

        // Get server changes since last_sync
        if (isset($data['last_sync']) && strtotime($data['last_sync'])) {
            $lastSyncDate = date('Y-m-d H:i:s', strtotime($data['last_sync']));

            $result['server_changes'] = $this->f3->get('DB')->exec(
                "SELECT * FROM consumption_history
                 WHERE user_id = ? AND updated_at > ?
                 ORDER BY date_report DESC",
                [$userId, $lastSyncDate]
            );
        }

        // Process local changes from client
        if (isset($data['local_changes']) && is_array($data['local_changes'])) {
            $counter = 0;
            foreach ($data['local_changes'] as $record) {
                try {
                    if (!$this->validateConsumptionRecord($record)) {
                        $result['rejected'][] = [
                            'id' => $record['id'] ?? 'unknown',
                            'reason' => 'Invalid record structure'
                        ];
                        continue;
                    }
                    $existing = $model->findById($record['id']);

                    if (!$existing) {
                        // New record
                        $model->create($record);
                        $result['accepted'][] = $record['id'];
                        $counter++;

                    } else {
                        // Existing record - check conflict
                        $clientTimestamp = isset($record['updated_at']) ? 
                            strtotime($record['updated_at']) : 0;
                        $serverTimestamp = strtotime($existing['updated_at']);

                        if ($serverTimestamp > $clientTimestamp) {
                            // Conflict
                            $result['conflicts'][] = [
                                $existing
                            ];
                        } else {
                            // Update
                            $model->update($record['id'], $record);
                            $result['accepted'][] = $record['id'];
                            $counter++;
                        }
                    }


                } catch (Exception $e) {
                    $result['rejected'][] = [
                        'id' => $record['id'] ?? 'unknown',
                        'reason' => $e->getMessage()
                    ];
                }
            }
        }
        $celery = new CeleryClient();
        $recommendation = $celery->getRecommendation($userId);
        if ($recommendation) {
            $newData = [];
            foreach ($recommendation as $value) {
                $newData[] = [
                    'food_id'=> $value['food_id'],
                    'recommendation_score'=> $value['wma_score'],
                    'user_id'=> $userId
                ];
            }
            $result['food_recommendation'] = $newData;
        }
        
        
    

        // Update WMA counter
        $userCounter = $user->getCounter($userId)[0]["wma_counter"];
        if ($userCounter !== null) {
            $result_counter = $userCounter + $counter;
            if ($result_counter >= 30) {
                $data_user = $user->findById($userId);
                $data_record = $model->findByUserId($userId);
                $food_features = $model->getFoodFeatures($userId);
                $bmi = $data_user["weight"]/(($data_user["height"]/100) **2);
                    $total_macro_energy = 
                        $food_features['total_karbohidrat'] * 4 +
                        $food_features['total_protein'] * 4 +
                        $food_features['total_lemak'] * 9;
                    $carb_pct = ($food_features['total_karbohidrat'] * 4) / $total_macro_energy;
                    $protein_pct = ($food_features['total_protein'] * 4) / $total_macro_energy;
                    $lemak_pct = ($food_features['total_lemak'] * 9) / $total_macro_energy;
                $data = [
                    "user_id"=> $userId,
                    "features"=> [
                        "age"=> $data_user['age'],
                        "gender"=> $data_user['gender'],
                        "activity"=> $data_user['activity'],
                        "bmi"=> $bmi,
                            "carb_pct" => $carb_pct,
                            "protein_pct" => $protein_pct,
                            "fat_pct" => $lemak_pct
                    ],
                    "recent_records"=> $data_record
                ];
            $celery->sendTask('tasks.process_ml_recommendation', [$data], new \stdClass());
                $result_counter = 0;
            }
            $user->updateCounter($userId, $result_counter);
        }
        $result['sync_timestamp'] = date('Y-m-d H:i:s');
        $this->success('Consumption records synced', $result);
    }

    /**
     * Get sync status
     * GET /api/sync/status
     */
    public function status() {
        $userId = $this->f3->get('user_id');

        $serverTime = $this->f3->get('DB')->exec(
            "SELECT NOW() as current_time"
        )[0]['current_time'];

        $latestConsumption = $this->f3->get('DB')->exec(
            "SELECT MAX(updated_at) as latest 
             FROM nutritional_consumptions 
             WHERE user_id = ?",
            [$userId]
        )[0]['latest'];

        $totalCount = $this->f3->get('DB')->exec(
            "SELECT COUNT(*) as total 
             FROM nutritional_consumptions 
             WHERE user_id = ?",
            [$userId]
        )[0]['total'];

        $this->success('Sync status retrieved', [
            'server_timestamp' => date('c', strtotime($serverTime)),
            'latest_consumption' => $latestConsumption ? 
                date('c', strtotime($latestConsumption)) : null,
            'total_consumptions' => (int)$totalCount,
            'database_version' => 1
        ]);
    }

    /**
     * Helper: Validate consumption record
     */
    private function validateConsumptionRecord($record) {
        $requiredFields = ['id', 'user_id', 'food_id', 'urt_id', 'date_report', 'portion_quantity', 'percentage', 'updated_at'];
        
        foreach ($requiredFields as $field) {
            if (!isset($record[$field])) {
                return false;
            }
        }
        
        if (!is_numeric($record['portion_quantity']) || $record['portion_quantity'] <= 0) {
            return false;
        }
        
        if (!strtotime($record['date_report'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Helper: Calculate nutrition with BDD
     */
    private function calculateNutrition($foodId, $urtId, $quantity) {
        $db = $this->f3->get('DB');
        
        $food = $db->exec(
            "SELECT * FROM data_makanan WHERE id = ?",
            [$foodId]
        );
        
        if (empty($food)) {
            return null;
        }
        
        $food = $food[0];
        $gramsPerPortion = 100;
        
        if ($urtId) {
            $urt = $db->exec(
                "SELECT gram_ml_per_porsi FROM urt_list WHERE id = ?",
                [$urtId]
            );
            if (!empty($urt)) {
                $gramsPerPortion = (float)$urt[0]['gram_ml_per_porsi'];
            }
        }
        
        $totalGrams = $quantity * $gramsPerPortion;
        
        // Apply BDD
        $bdd = isset($food['bdd']) && $food['bdd'] > 0 ? 
            (float)$food['bdd'] / 100 : 1.0;
        $edibleGrams = $totalGrams * $bdd;
        
        $multiplier = $edibleGrams / 100;
        
        return [
            'portion_grams' => round($totalGrams, 2),
            'calories' => round((float)$food['energi'] * $multiplier, 2),
            'protein' => round((float)$food['protein'] * $multiplier, 2),
            'fat' => round((float)$food['lemak'] * $multiplier, 2),
            'carbohydrates' => round((float)$food['karbohidrat'] * $multiplier, 2),
            'fiber' => round((float)$food['serat'] * $multiplier, 2),
            'calcium' => round((float)$food['kalsium'] * $multiplier, 2),
            'iron' => round((float)$food['besi'] * $multiplier, 2),
            'sodium' => round((float)$food['natrium'] * $multiplier, 2),
            'potassium' => round((float)$food['kalium'] * $multiplier, 2)
        ];
    }
}