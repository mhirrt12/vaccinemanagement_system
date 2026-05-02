<?php
/**
 * RoleMiddleware - Handles role-based access control (RBAC)
 * 
 * Responsibilities:
 * - Check if authenticated user has one of the allowed roles
 * - Restrict access to specific endpoints based on role
 * - Return 403 Forbidden response if role not permitted
 * 
 * Usage: Called after AuthMiddleware to enforce role restrictions
 * 
 * Example:
 *   RoleMiddleware::check(['admin'], $authPayload['role']);
 *   RoleMiddleware::check(['nurse', 'admin'], $authPayload['role']);
 */

require_once __DIR__ . '/../helpers/Response.php';

class RoleMiddleware {
    /**
     * Check if user's role is allowed to access the resource
     * 
     * @param array $allowedRoles Array of allowed role names (e.g., ['admin'], ['nurse', 'admin'])
     * @param string $userRole Role of the authenticated user (from JWT payload)
     * @return void Returns 403 response if not allowed
     */
    public static function check($allowedRoles, $userRole) {
        if (!in_array($userRole, $allowedRoles)) {
            Response::forbidden('Access denied. You do not have permission to perform this action.');
            exit;
        }
    }
    
    /**
     * Check if user has specific role and execute callback if yes
     * 
     * @param string $requiredRole Role required
     * @param string $userRole User's role
     * @param callable $callback Function to execute if role matches
     * @return mixed|null Result of callback or null if role doesn't match
     */
    public static function ifRole($requiredRole, $userRole, $callback) {
        if ($userRole === $requiredRole) {
            return $callback();
        }
        return null;
    }
    
    /**
     * Check multiple role conditions with different actions
     * 
     * @param string $userRole User's role
     * @param array $roleActions Associative array of role => callback
     * @return mixed|null
     */
    public static function routeByRole($userRole, $roleActions) {
        if (isset($roleActions[$userRole])) {
            return $roleActions[$userRole]();
        }
        Response::forbidden('Access denied. Insufficient privileges.');
        exit;
    }
}
?>