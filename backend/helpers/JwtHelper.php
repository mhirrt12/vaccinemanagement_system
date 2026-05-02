<?php
/**
 * JwtHelper - Handles JSON Web Token (JWT) encoding, decoding, and validation
 * 
 * Responsibilities:
 * - Generate JWT tokens with user payload
 * - Decode and verify JWT tokens (signature + expiration)
 * - Provide helper methods for token refresh and remaining time
 * - Uses HS256 algorithm with secret key from environment
 * - Includes leeway for clock skew (60 seconds)
 */

class JwtHelper {
    private static $secret = null;
    private static $algo = 'HS256';
    private static $leeway = 60; // seconds
    
    /**
     * Initialize JWT helper with secret key from environment
     * 
     * @return void
     */
    private static function init() {
        if (self::$secret === null) {
            self::$secret = getenv('JWT_SECRET') ?: 'YourSuperSecretJWTKeyChangeThisInProduction1234567890!';
        }
    }
    
    /**
     * Base64URL encode a string (URL-safe Base64)
     * 
     * @param string $data
     * @return string
     */
    private static function base64UrlEncode($data) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
    
    /**
     * Base64URL decode a string
     * 
     * @param string $data
     * @return string
     */
    private static function base64UrlDecode($data) {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
    
    /**
     * Generate a new JWT token
     * 
     * @param array $payload User data to include (user_id, name, email, role)
     * @param int $expiry Expiration time in seconds (default from env)
     * @return string JWT token
     */
    public static function encode($payload, $expiry = null) {
        self::init();
        
        if ($expiry === null) {
            $expiry = (int)(getenv('JWT_EXPIRY') ?: 86400);
        }
        
        // Set standard claims
        $payload['iat'] = time();           // Issued at
        $payload['nbf'] = time();           // Not before
        $payload['exp'] = time() + $expiry; // Expiration
        
        // Create JWT header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => self::$algo
        ]);
        
        // Encode header and payload
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));
        
        // Create signature
        $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, self::$secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);
        
        // Return full token
        return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
    }
    
    /**
     * Decode and validate a JWT token
     * 
     * @param string $token The JWT token string
     * @return array|false Decoded payload or false if invalid/expired
     */
    public static function decode($token) {
        self::init();
        
        // Split token into parts
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log("JWT decode failed: Invalid token structure");
            return false;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
        
        // Decode header and payload
        $header = json_decode(self::base64UrlDecode($base64UrlHeader), true);
        $payload = json_decode(self::base64UrlDecode($base64UrlPayload), true);
        
        if (!$header || !$payload) {
            error_log("JWT decode failed: Invalid JSON in header or payload");
            return false;
        }
        
        // Verify signature
        $signature = self::base64UrlDecode($base64UrlSignature);
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, self::$secret, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            error_log("JWT decode failed: Signature mismatch");
            return false;
        }
        
        // Check expiration with leeway
        $currentTime = time();
        if (isset($payload['exp']) && $payload['exp'] < ($currentTime - self::$leeway)) {
            error_log("JWT decode failed: Token expired at " . date('Y-m-d H:i:s', $payload['exp']));
            return false;
        }
        
        // Check not-before claim with leeway
        if (isset($payload['nbf']) && $payload['nbf'] > ($currentTime + self::$leeway)) {
            error_log("JWT decode failed: Token not yet valid until " . date('Y-m-d H:i:s', $payload['nbf']));
            return false;
        }
        
        // Check issued-at claim (optional, prevent tokens from the future)
        if (isset($payload['iat']) && $payload['iat'] > ($currentTime + self::$leeway)) {
            error_log("JWT decode failed: Token issued in the future");
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Refresh a JWT token (issue a new one before expiration)
     * 
     * @param string $token The old token (does not need to be expired)
     * @return string|false New token or false if invalid
     */
    public static function refresh($token) {
        $payload = self::decode($token);
        if (!$payload) {
            return false;
        }
        
        // Remove expiration and issued-at claims to allow fresh token
        unset($payload['exp'], $payload['iat'], $payload['nbf']);
        
        // Optional: check if token is about to expire (e.g., less than 1 hour left)
        // and only refresh if needed, but we'll just generate new one
        
        return self::encode($payload);
    }
    
    /**
     * Get remaining lifetime of a token in seconds
     * 
     * @param string $token
     * @return int Seconds until expiration, or -1 if no exp claim
     */
    public static function getRemainingTime($token) {
        $payload = self::decode($token);
        if (!$payload || !isset($payload['exp'])) {
            return -1;
        }
        $remaining = $payload['exp'] - time();
        return $remaining > 0 ? $remaining : 0;
    }
    
    /**
     * Check if a token is about to expire within a given time window
     * 
     * @param string $token
     * @param int $seconds Threshold in seconds (default 300, 5 minutes)
     * @return bool
     */
    public static function isExpiringSoon($token, $seconds = 300) {
        $remaining = self::getRemainingTime($token);
        return $remaining > 0 && $remaining <= $seconds;
    }
    
    /**
     * Extract user ID from token without full validation (for quick access)
     * 
     * @param string $token
     * @return int|null
     */
    public static function getUserIdFromToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        return $payload['user_id'] ?? null;
    }
    
    /**
     * Validate token and return payload, or throw exception
     * 
     * @param string $token
     * @return array
     * @throws Exception
     */
    public static function validateOrFail($token) {
        $payload = self::decode($token);
        if (!$payload) {
            throw new Exception('Invalid or expired token');
        }
        return $payload;
    }
}
?>