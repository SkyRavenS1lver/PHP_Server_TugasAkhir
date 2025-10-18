<?php
// app/controllers/HealthController.php
require_once __DIR__ . '/BaseController.php';

class HealthController extends BaseController {
    
    /**
     * Health check endpoint
     * GET /
     */
    public function index() {
        $dbStatus = 'disconnected';
        
        try {
            // Test database connection
            $this->db->exec('SELECT 1');
            $dbStatus = 'connected';
        } catch (Exception $e) {
            // Database connection failed
        }
        
        $this->success('API is running', [
            'app' => $this->f3->get('APP_NAME'),
            'version' => $this->f3->get('APP_VERSION'),
            'timestamp' => date('Y-m-d H:i:s'),
            'database' => $dbStatus
        ]);
    }
}