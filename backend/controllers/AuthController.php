<?php
/**
 * AuthController - Handles user authentication and registration
 * 
 * Responsibilities:
 * - Parent registration with validation (Ethiopian phone, strong password)
 * - Login with JWT token generation
 * - Password reset functionality
 * - Email verification placeholder
 * 
 * Security:
 * - Passwords hashed with bcrypt
 * - Input validation on both client and server side
 * - Rate limiting (optional, can be added)
 * - JWT tokens with expiration
 */

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../helpers/JwtHelper.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Logger.php';
require_once __DIR__ . '/../config/database.php';

class AuthController {
    private $userModel;
    
    public function __construct() {
        $this->userModel = new User();
    }
    
    /**
     * Parent registration
     * POST /api/auth/register
     * Input: name, email, phone, password, confirm_password
     */
    public function register() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        $phone = $input['phone'] ?? '';
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        
        // Validate required fields
        if (!Validator::required($name) || !Validator::required($email) || !Validator::required($phone) || !Validator::required($password)) {
            Response::badRequest('All fields are required');
            return;
        }
        
        // Validate email format
        if (!Validator::email($email)) {
            Response::badRequest('Invalid email format');
            return;
        }
        
        // Validate Ethiopian phone number (10 digits, starts with 09)
        if (!Validator::ethiopianPhone($phone)) {
            Response::badRequest('Phone number must be 10 digits and start with 09');
            return;
        }
        
        // Validate strong password (>=8 chars, alphanumeric + symbol)
        if (!Validator::strongPassword($password)) {
            Response::badRequest('Password must be at least 8 characters and include at least one letter, one number, and one symbol');
            return;
        }
        
        // Check password confirmation
        if ($password !== $confirmPassword) {
            Response::badRequest('Passwords do not match');
            return;
        }
        
        // Check if email or phone already exists
        if ($this->userModel->findByEmail($email)) {
            Response::conflict('Email already registered');
            return;
        }
        
        if ($this->userModel->findByPhone($phone)) {
            Response::conflict('Phone number already registered');
            return;
        }
        
        // Create user with role = parent (role_id = 1)
        $userId = $this->userModel->create($name, $email, $phone, $password, 1);
        
        if ($userId) {
            // Log registration
            Logger::info("Parent registered", $userId, ['email' => $email, 'phone' => $phone]);
            
            // TODO: Send email verification link if required
            // sendVerificationEmail($email, $userId);
            
            Response::success([
                'user_id' => $userId,
                'message' => 'Registration successful. Your account is pending nurse approval. You will be notified once approved.'
            ]);
        } else {
            Response::internalError('Registration failed. Please try again later.');
        }
    }
    
    /**
     * Login for all roles (parent, nurse, admin)
     * POST /api/auth/login
     * Input: email, password
     */
    public function login() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        // Validate input
        if (!Validator::required($email) || !Validator::required($password)) {
            Response::badRequest('Email and password are required');
            return;
        }
        
        // Find user by email
        $user = $this->userModel->findByEmail($email);
        
        // Check if user exists and password matches
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Logger::warning("Failed login attempt", null, ['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR']]);
            Response::unauthorized('Invalid email or password');
            return;
        }
        
        // Check if parent account is verified (approved by nurse)
        if ($user['role_id'] == 1 && !$user['is_verified']) {
            Response::forbidden('Your account is pending approval by a nurse. Please wait for verification.');
            return;
        }
        
        // Determine role name based on role_id
        $roleName = $this->getRoleName($user['role_id']);
        
        // Prepare JWT payload
        $payload = [
            'user_id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $roleName
        ];
        
        // Generate JWT token
        $token = JwtHelper::encode($payload);
        
        // Log successful login
        Logger::info("User logged in", $user['id'], ['role' => $roleName]);
        
        // Return token and user info (excluding password)
        unset($user['password_hash']);
        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $roleName,
                'is_verified' => (bool)$user['is_verified']
            ]
        ], 'Login successful');
    }
    
    /**
     * Get authenticated user details (protected route)
     * GET /api/auth/me
     * Requires valid JWT token
     */
    public function me() {
        global $authPayload;
        
        if (!$authPayload) {
            Response::unauthorized('Not authenticated');
            return;
        }
        
        $user = $this->userModel->findById($authPayload['user_id']);
        if (!$user) {
            Response::notFound('User not found');
            return;
        }
        
        unset($user['password_hash']);
        $user['role'] = $this->getRoleName($user['role_id']);
        
        Response::success($user);
    }
    
    /**
     * Request password reset (sends reset link via email)
     * POST /api/auth/forgot-password
     * Input: email
     */
    public function forgotPassword() {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        
        if (!Validator::required($email) || !Validator::email($email)) {
            Response::badRequest('Valid email is required');
            return;
        }
        
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            // For security, do not reveal if email exists
            Response::success(null, 'If your email is registered, you will receive a password reset link.');
            return;
        }
        
        // Generate reset token (store in a password_resets table - simplified)
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database (create password_resets table if needed)
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO password_resets (email, token, expires_at) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$email, $resetToken, $expiresAt]);
        
        // Send email with reset link
        $resetLink = getenv('APP_URL') . "/reset-password?token=$resetToken&email=" . urlencode($email);
        
        // In production, send email using mail config
        // sendPasswordResetEmail($email, $resetLink);
        
        Logger::info("Password reset requested", $user['id'], ['email' => $email]);
        
        Response::success(null, 'Password reset link has been sent to your email address.');
    }
    
    /**
     * Reset password using token
     * POST /api/auth/reset-password
     * Input: email, token, new_password, confirm_password
     */
    public function resetPassword() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $email = $input['email'] ?? '';
        $token = $input['token'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        
        // Validation
        if (!Validator::required($email) || !Validator::required($token) || !Validator::required($newPassword)) {
            Response::badRequest('Email, token, and new password are required');
            return;
        }
        
        if (!Validator::strongPassword($newPassword)) {
            Response::badRequest('Password must be at least 8 characters and include at least one letter, one number, and one symbol');
            return;
        }
        
        if ($newPassword !== $confirmPassword) {
            Response::badRequest('Passwords do not match');
            return;
        }
        
        // Verify token
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM password_resets 
            WHERE email = ? AND token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$email, $token]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            Response::badRequest('Invalid or expired reset token');
            return;
        }
        
        // Update password
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            Response::notFound('User not found');
            return;
        }
        
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $updateStmt->execute([$newHash, $user['id']]);
        
        // Delete used token
        $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
        
        Logger::info("Password reset completed", $user['id']);
        
        Response::success(null, 'Password has been reset successfully. You can now login with your new password.');
    }
    
    /**
     * Logout (invalidate token) – optional, since JWT is stateless
     * POST /api/auth/logout
     * For JWT, logout is handled client-side by deleting token.
     * Server-side can add token to blacklist if needed.
     */
    public function logout() {
        // For JWT, we don't need server-side logout unless implementing blacklist.
        // Client should delete the token from localStorage.
        Response::success(null, 'Logout successful');
    }
    
    /**
     * Helper: Convert role_id to role name
     */
    private function getRoleName($roleId) {
        $roles = ['parent', 'nurse', 'admin'];
        return $roles[$roleId - 1] ?? 'parent';
    }
    
    /**
     * Helper: Send verification email (placeholder)
     * In production, implement with mail.php
     */
    private function sendVerificationEmail($email, $userId) {
        // Placeholder for email verification logic
        // Use mail functions from config/mail.php
    }
    /**
 * Health check endpoint
 * GET /api/health
 */
public function health() {
    Response::success(['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s')]);
}
}
?>