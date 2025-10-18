<?php
// app/middleware/AuthMiddleware.php

class AuthMiddleware {
    
    /**
     * Verify JWT token
     */
    public static function verify() {
        $f3 = \Base::instance();
        
        // Get authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        
        if (!$authHeader || !is_string($authHeader)) {
            self::unauthorized('Authorization header missing');
        }
        
        // Extract token
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        } else {
            self::unauthorized('Invalid authorization format');
        }
        
        // Verify token
        $decoded = self::decodeToken($token);
        
        if (!$decoded) {
            self::unauthorized('Invalid token');
        }
        
        // Check expiration only if 'exp' exists in token
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            self::unauthorized('Token has expired');
        }
        // Set user data in F3 instance
        $f3->set('user_id', $decoded['user_id']);
        $f3->set('user_email', $decoded['email']);
        
        return true;
    }
    
    /**
     * Decode JWT token
     */
    private static function decodeToken($token) {
        $f3 = \Base::instance();
        
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Get JWT secret
        $secret = $f3->get('JWT_SECRET');
        if (!$secret || !is_string($secret)) {
            error_log('JWT_SECRET not configured');
            return false;
        }
        
        // Verify signature
        $validSignature = hash_hmac(
            'sha256',
            $header . '.' . $payload,
            $secret,
            true
        );
        
        $validSignature = self::base64UrlEncode($validSignature);
        
        if ($signature !== $validSignature) {
            return false;
        }
        
        // Decode payload
        $payload = self::base64UrlDecode($payload);
        return json_decode($payload, true);
    }
    
    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Send unauthorized response
     */
    private static function unauthorized($message) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
}