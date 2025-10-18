<?php
// app/controllers/BaseController.php

class BaseController {
    protected $f3;
    protected $db;

    public function __construct() {
        $this->f3 = \Base::instance();
        $this->db = $this->f3->get('DB');
    }

    /**
     * Send JSON response
     */
    protected function jsonResponse($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Send success response
     */
    protected function success($message, $data = null, $status = 200) {
        $response = [
            'success' => true,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        $this->jsonResponse($response, $status);
    }

    /**
     * Send error response
     */
    protected function error($message, $status = 400, $errors = null) {
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        $this->jsonResponse($response, $status);
    }

    /**
     * Get request body as array
     */
    protected function getRequestBody() {
        $body = file_get_contents('php://input');
        return json_decode($body, true) ?? [];
    }

    /**
     * Validate required fields
     */
    protected function validateRequired($data, $required) {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}