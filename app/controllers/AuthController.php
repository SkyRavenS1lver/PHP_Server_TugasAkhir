<?php
// app/controllers/AuthController.php
require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/User.php';

class AuthController extends BaseController {
    
    /**
     * Register new user
     * POST /auth/register
     */
    public function register() {
        $data = $this->getRequestBody();        
        // Validate required fields
        $missing = $this->validateRequired($data, ['name', 'email', 'password', 'age', 'gender', 'height', 'weight', 'activity_description']);
        if (!empty($missing)) {
            $this->error('Missing required fields', 400, $missing);
        }

        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email format', 400);
        }

        // Check if user already exists
        $user = new User();
        if ($user->findByEmail($data['email'])) {
            $this->error('Email already registered', 409);
        }

        // Get activity level from Flask API
        $ch = curl_init('http://localhost:5000/api/analyze-activity');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['activity_description' => $data['activity_description']]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->error('Failed to analyze activity', 500);
        }

        $activityResponse = json_decode($response, true);
        if (!$activityResponse['success']) {
            $this->error('Failed to analyze activity', 500);
        }

        $gender = $data['gender']-1;      // 0=male, 1=female
        $age = $data['age'];
        $height = $data['height'];      // cm
        $weight = $data['weight'];      // kg
        $activity = $activityResponse['data']['activity_level'];
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT, []);

        // Create new user
        $userId = $user->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $hashedPassword,
            'age' => $age,
            'gender' => $gender,
            'height' => $height,
            'weight' => $weight,
            'activity' => $activity
        ]);
        
        // Generate token
        $token = $this->generateToken($userId, $data['email'], $hashedPassword);

        $this->success('User registered successfully', [
            'token' => $token,
            'user_id' => $userId,
            'activity_level' => $activity,
            'updated_at' => date('Y-m-d h:i:s')
        ], 201);
        
    }
    
    /**
     * Login user
     * POST /auth/login
     */
    public function login() {
        $data = $this->getRequestBody();
        
        // Validate required fields
        $missing = $this->validateRequired($data, ['email', 'password']);
        if (!empty($missing)) {
            $this->error('Missing required fields', 400, $missing);
        }
        
        // Find user by email
        $user = new User();
        $userData = $user->findByEmail($data['email']);
        $userId = $userData['id_user'];
        if (!$userData || !password_verify($data['password'], $userData['password'])) {
            $this->error('Invalid credentials', 401);
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT, []);
        // Generate token
        $token = $this->generateToken($userData['id_user'], $data['email'], $hashedPassword);

        $this->success('Login successful', [
            'token' => $token,
            'user_id' => $userId,
            'updated_at' => date('Y-m-d h:i:s')
        ], 200);
    }
    
    /**
     * Get current user
     * GET /auth/me
     */
    public function me() {
        $userId = $this->f3->get('user_id');
        
        $user = new User();
        $userData = $user->findById($userId);
        
        if (!$userData) {
            $this->error('User not found', 404);
        }
        
        unset($userData['password']);
        
        $this->success('User retrieved successfully', [
            'user' => $userData
        ]);
    }
    
    /**
     * Logout user
     * POST /auth/logout
     */
    public function logout() {
        // In stateless JWT, logout is handled client-side
        // You can implement token blacklist here if needed
        $this->success('Logout successful');
    }
    
    /**
     * Generate JWT token
     */
    private function generateToken($userId, $email, $password) {
        $secret = $this->f3->get('JWT_SECRET');
        if (!$secret || !is_string($secret)) {
            throw new Exception('JWT_SECRET not configured');
        }
        $header = json_encode(['typ' => 'JWT', 'alg' => $this->f3->get('JWT_ALGORITHM')]);
        $payload = json_encode([
            'user_id' => $userId,
            'email' => $email,
            'set' => $password,
            'iat' => time()
        ]);
        
        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac(
            'sha256',
            $base64UrlHeader . "." . $base64UrlPayload,
            $secret,
            true
        );
        
        $base64UrlSignature = $this->base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}