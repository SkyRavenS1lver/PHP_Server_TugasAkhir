<?php
require __DIR__ . '/../../vendor/autoload.php';

class CeleryClient {
    private $redis;
    
    public function __construct() {
        $this->redis = new \Predis\Client('redis://redis:6379/0');
    }
    
    public function sendTask($taskName, $args = [], $kwargs = []) {
        $taskId = $this->uuid4();
        // CRITICAL: kwargs must be an object (stdClass), not an array
        if (empty($kwargs)) {
            $kwargs = new \stdClass();  // Empty object, not empty array
        }
        
        $message = [
            'id' => $taskId,
            'task' => $taskName,
            'args' => $args,
            'kwargs' => $kwargs,
            'retries' => 0,
            'eta' => null,
            'expires' => null,
        ];
        
        $body = json_encode($message);
        $contentType = 'application/json';
        $contentEncoding = 'utf-8';
        
        // Celery protocol format
        $payload = json_encode([
            'body' => base64_encode($body),
            'content-encoding' => $contentEncoding,
            'content-type' => $contentType,
            'headers' => new \stdClass(),
            'properties' => [
                'body_encoding' => 'base64',
                'correlation_id' => $taskId,
                'delivery_info' => [
                    'exchange' => '',
                    'routing_key' => 'celery',
                ],
                'delivery_mode' => 2,
                'delivery_tag' => $taskId,
                'reply_to' => $taskId,
            ],
        ]);
        
        $this->redis->lpush('celery', $payload);
        
        return $taskId;
    }
    public function getRecommendation($userId) {
        $resultKey = "recommendation:$userId";
        $result = $this->redis->get($resultKey);
        
        if ($result) {
            return json_decode($result, true);
        }
        return null;
    }
    
    private function uuid4() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}