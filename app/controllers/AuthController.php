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
        $missing = $this->validateRequired($data, ['name', 'email', 'password', 'age', 'gender', 'height', 'weight', 'activity']);
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
        $gender = $data['gender']-1;      // 0=male, 1=female
        $age = $data['age'];
        $height = $data['height'];      // cm
        $weight = $data['weight'];      // kg
        $bmi = $weight / pow($height / 100, 2);
        $activity = $data['activity'] ?? 2;  // 1-4
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
        $features = [
            'age' => $age,
            'bmi' => $bmi,
            'activity' => $activity,
            'gender' => $gender,
            "carb_pct" => 0.50,
            "protein_pct" => 0.20,
            "fat_pct" => 0.30
        ];
        $flask_url = "http://flask:5000/get-recommendation";
        $payload = [
            'user_id' => $userId,
            'features' =>$features,
            'recent_records' => []
        ];
        $ch = curl_init($flask_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $response = json_decode($response, true);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->error($error_msg, 500);
        } else {
            $this->success('User registered successfully', [
                'token' => $token,
                'user_id' => $userId,
                'updated_at' => date('Y-m-d h:i:s'),
                'food_recommendation' => $response['foods']
            ], 201);
        }

        curl_close($ch);
        
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
        $userId = $userData['id'];
        if (!$userData || !password_verify($data['password'], $userData['password'])) {
            $this->error('Invalid credentials', 401);
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT, []);
        // Generate token
        $token = $this->generateToken($userData['id'], $data['email'], $hashedPassword);
        $model = new NutritionalConsumption();
          
        $food_features = $model->getFoodFeatures($userId);
        if(count($food_features) <30) {
            $gender = $userData['gender']-1;      // 0=male, 1=female
            $age = $userData['age'];
            $height = $userData['height'];      // cm
            $weight = $userData['weight'];      // kg
            $bmi = $weight / pow($height / 100, 2);
            $activity = $userData['activity'] ?? 2;  // 1-4
            $food_features = [[
                'age' => $age,
                'bmi' => $bmi,
                'activity' => $activity,
                'gender' => $gender,
                "carb_pct" => 0.50,
                "protein_pct" => 0.20,
                "fat_pct" => 0.30
            ]];
        }
        $data_record = $model->findByUserId($userId);
        $flask_url = "http://flask:5000/get-recommendation";
        $payload = [
            "user_id"=> $userId,
            "features"=>$food_features[0],
            "recent_records"=> $data_record
        ];
        $ch = curl_init($flask_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $response = curl_exec($ch);
        $response = json_decode($response, true);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            $this->error($error_msg, 500);
        } else {
            $this->success('Login successful', [
                'token' => $token,
                'user_id' => $userId,
                'updated_at' => date('Y-m-d h:i:s'),
                'food_recommendation' => $response['foods']
            ], 200);
        }

        curl_close($ch);

        $this->success('Login successful', [
            'user_id' => $userData['id'],
            'token' => $token
        ]);
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