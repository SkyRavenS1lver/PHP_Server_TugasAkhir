<?php
// app/controllers/SyncController.php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/NutritionalConsumption.php';
require_once __DIR__ . '/../models/User.php';
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
        // $userId = 74;
        $data = $this->getRequestBody();

        $userModel = new User();
        $serverProfile = $userModel->findById($userId);

        if (!$serverProfile) {
            $this->error('User not found', 404);
            return;
        }

        // Check for conflicts
        if (!isset($data['updated_at'])) {
            unset($serverProfile['password']);
            unset($serverProfile['wma_counter']);
            $this->success('server version returned', 
                $serverProfile,
            );
            return;
        }
        else if (isset($data['updated_at'])) {
            $clientTimestamp = strtotime($data['updated_at']);
            $serverTimestamp = strtotime($serverProfile['updated_at']);
            // Server is newer - conflict
            if ($serverTimestamp > $clientTimestamp) {
                unset($serverProfile['password']);
                $this->success('server version returned', 
                    $serverProfile,
                );
                return;
            }
        }

        // No conflict - update server with client data
        if (isset($data['profile'])) {
            // MINIMAL FIELDS ONLY
            $allowedFields = [
                'name',
                'age',
                'gender',
                'height',
                'weight',
                'activity',
                'updated_at'
            ];

            // Filter update data
            $updateData = [];
            foreach ($data['profile'] as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $updateData[$key] = $value;
                }
            }

            // Validate gender
            if (isset($updateData['gender']) && 
                !in_array($updateData['gender'], [1, 2])) {
                $this->error('Invalid gender value.', 400);
                return;
            }

            // Validate activity_level
            $validActivityLevels = [1,2,3,4];
            if (isset($updateData['activity']) && 
                !in_array($updateData['activity'], $validActivityLevels)) {
                $this->error('Invalid activity level', 400);
                return;
            }

            // Validate height (100-250 cm)
            if (isset($updateData['height']) && 
                ($updateData['height'] < 100 || $updateData['height'] > 250)) {
                $this->error('Invalid height', 400);
                return;
            }

            // Validate weight (20-300 kg)
            if (isset($updateData['weight']) && 
                ($updateData['weight'] < 20 || $updateData['weight'] > 300)) {
                $this->error('Invalid weight', 400);
                return;
            }

            // Update server
            if (!empty($updateData)) {
                $userModel->update($userId, $updateData);
                $serverProfile = $userModel->findById($userId);
            }
        }

        // Return updated profile
        unset($serverProfile['password']);
        $this->success('No changes needed', null);
    }

    /**
     * TWO-WAY SYNC: Nutritional consumption records
     * POST /api/sync/consumptions
     */
    public function syncConsumptions() {
        $userId = $this->f3->get('user_id');
        // $userId = 74;
        $data = $this->getRequestBody();

        $model = new NutritionalConsumption();
        $user = new User();
        $result = [
            'server_changes' => [],
            'accepted' => [],
            'rejected' => [],
            'conflicts' => []
        ];
        // Get server changes since last_sync
        if (!isset($data['last_sync'])) {
            $result['server_changes'] = $this->f3->get('DB')->exec(
                "SELECT * FROM consumption_history
                WHERE user_id = ?
                ORDER BY date_report DESC",
                [$userId]
            );
        }
        elseif(strtotime($data['last_sync'])){
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
        $result['sync_timestamp'] = date('Y-m-d H:i:s');
        $this->success('Consumption records synced', $result);
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
}