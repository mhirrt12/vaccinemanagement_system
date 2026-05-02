<?php
/**
 * AuthMiddleware - Handles JWT authentication for protected routes
 * 
 * Responsibilities:
 * - Extract Bearer token from Authorization header
 * - Validate token using JwtHelper
 * - Decode and verify token expiration and signature
 * - Attach authenticated user payload to request (global variable)
 * - Allow public routes to bypass authentication
 * 
 * Usage: Called from index.php before routing
 */

require_once __DIR__ . '/../helpers/JwtHelper.php';
require_once __DIR__ . '/../helpers/Response.php';

class AuthMiddleware {
    /**
     * List of routes that do not require authentication
     */
    private static $publicRoutes = [
        '/auth/login',
        '/auth/register',
        '/auth/forgot-password',
        '/auth/reset-password',
        '/certificates/verify',  // Public certificate verification endpoint
        '/health'                 // Health check endpoint
    ];
    
    /**
     * Authenticate the request and return user payload
     * 
     * @param string $requestPath The current request path (e.g., /auth/me)
     * @return array|null User payload if authenticated, null for public routes
     */
    public static function authenticate($requestPath) {
        // Check if route is public
        if (self::isPublicRoute($requestPath)) {
            return null;
        }
        
        // Get Authorization header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        // Extract Bearer token
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            Response::unauthorized('Authorization token required. Please provide a valid Bearer token.');
            exit;
        }
        
        $token = $matches[1];
        
        // Decode and validate token
        $payload = JwtHelper::decode($token);
        if (!$payload) {
            Response::unauthorized('Invalid or expired token. Please login again.');
            exit;
        }
        
        // Check if token has required fields
        if (!isset($payload['user_id']) || !isset($payload['role'])) {
            Response::unauthorized('Invalid token structure.');
            exit;
        }
        
        // Optionally: check if user still exists in database (prevent deleted users)
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, role_id, is_verified FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            Response::unauthorized('User account not found.');
            exit;
        }
        
        // For parent role, ensure account is verified (approved by nurse)
        if ($payload['role'] === 'parent' && !$user['is_verified']) {
            Response::forbidden('Your account is pending approval by a nurse. Please wait.');
            exit;
        }
        
        // Attach payload to global for controllers to access
        return $payload;
    }
    
    /**
     * Check if a route is public (no authentication required)
     * 
     * @param string $requestPath
     * @return bool
     */
    private static function isPublicRoute($requestPath) {
        foreach (self::$publicRoutes as $publicRoute) {
            if (strpos($requestPath, $publicRoute) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Optional: Add routes to public list dynamically
     * 
     * @param string|array $routes
     */
    public static function addPublicRoutes($routes) {
        if (is_array($routes)) {
            self::$publicRoutes = array_merge(self::$publicRoutes, $routes);
        } else {
            self::$publicRoutes[] = $routes;
        }
    }
}
?>