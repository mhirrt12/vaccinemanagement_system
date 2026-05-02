<?php
/**
 * JWT Configuration and Token Management
 * 
 * This file loads JWT settings from environment variables and provides
 * helper functions for token generation, validation, and refresh.
 * 
 * Security:
 * - Uses HS256 algorithm with a strong secret key
 * - Token expiration is configurable via .env
 * - Leeway for clock skew (60 seconds)
 */

// Ensure environment variables are loaded
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}

// JWT Configuration Constants
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'YourSuperSecretJWTKeyChangeThisInProduction1234567890!');
define('JWT_ALGO', 'HS256');
define('JWT_EXPIRY', (int)(getenv('JWT_EXPIRY') ?: 86400)); // 24 hours default
define('JWT_LEEWAY', 60); // 60 seconds clock skew tolerance

// Helper functions for JWT operations

/**
 * Generate a new JWT token for a user payload
 * 
 * @param array $payload The data to encode (user_id, name, email, role)
 * @return string JWT token
 */
function jwt_encode($payload) {
    // Set expiration time
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload['iat'] = time(); // Issued at
    $payload['nbf'] = time(); // Not before
    
    // Create JWT header
    $header = json_encode([
        'typ' => 'JWT',
        'alg' => JWT_ALGO
    ]);
    
    // Encode header and payload to base64url
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    
    // Create signature
    $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    // Return full token
    return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
}

/**
 * Decode and validate a JWT token
 * 
 * @param string $token The JWT token string
 * @return array|false Decoded payload or false if invalid/expired
 */
function jwt_decode($token) {
    // Split token into parts
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        error_log("JWT decode failed: Invalid token structure");
        return false;
    }
    
    list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
    
    // Decode header and payload
    $header = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlHeader)), true);
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlPayload)), true);
    
    if (!$header || !$payload) {
        error_log("JWT decode failed: Invalid JSON in header or payload");
        return false;
    }
    
    // Verify signature
    $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $base64UrlSignature));
    $expectedSignature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, JWT_SECRET, true);
    
    if (!hash_equals($signature, $expectedSignature)) {
        error_log("JWT decode failed: Signature mismatch");
        return false;
    }
    
    // Check expiration with leeway
    $currentTime = time();
    if (isset($payload['exp']) && $payload['exp'] < ($currentTime - JWT_LEEWAY)) {
        error_log("JWT decode failed: Token expired at " . date('Y-m-d H:i:s', $payload['exp']));
        return false;
    }
    
    // Check not-before claim with leeway
    if (isset($payload['nbf']) && $payload['nbf'] > ($currentTime + JWT_LEEWAY)) {
        error_log("JWT decode failed: Token not yet valid");
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
function jwt_refresh($token) {
    $payload = jwt_decode($token);
    if (!$payload) {
        return false;
    }
    // Remove expiration and issued time to allow fresh token
    unset($payload['exp'], $payload['iat'], $payload['nbf']);
    return jwt_encode($payload);
}

/**
 * Validate that a token exists and is valid, then extract user
 * 
 * @param string|null $authHeader The Authorization header value (Bearer token)
 * @return array|false User payload or false
 */
function jwt_authenticate($authHeader = null) {
    if ($authHeader === null) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
    }
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        return false;
    }
    
    $token = $matches[1];
    return jwt_decode($token);
}

/**
 * Get remaining time of a token in seconds
 * 
 * @param string $token
 * @return int Seconds until expiration, or -1 if no exp claim
 */
function jwt_remaining_time($token) {
    $payload = jwt_decode($token);
    if (!$payload || !isset($payload['exp'])) {
        return -1;
    }
    $remaining = $payload['exp'] - time();
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Check if a token is about to expire (within X seconds)
 * 
 * @param string $token
 * @param int $seconds Threshold in seconds (default 300 = 5 minutes)
 * @return bool
 */
function jwt_is_expiring_soon($token, $seconds = 300) {
    $remaining = jwt_remaining_time($token);
    return $remaining > 0 && $remaining <= $seconds;
}
?>