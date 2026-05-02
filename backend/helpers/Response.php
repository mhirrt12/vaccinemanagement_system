<?php
/**
 * Response Helper - Standardizes API responses
 * 
 * Responsibilities:
 * - Send JSON responses with consistent structure
 * - Set appropriate HTTP status codes
 * - Provide static methods for common response types (success, error, unauthorized, etc.)
 * - Include optional data and messages
 * - Automatically terminate script execution after sending response
 */

class Response {
    /**
     * Send a JSON response with custom HTTP status code
     *
     * @param int $statusCode HTTP status code (200, 201, 400, 401, 403, 404, 500, etc.)
     * @param mixed $data Response data (array or object)
     * @param string $message Optional message (e.g., "User created successfully")
     * @return void
     */
    public static function send($statusCode, $data = null, $message = '') {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        
        $response = [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Send success response (200 OK)
     *
     * @param mixed $data
     * @param string $message
     * @return void
     */
    public static function success($data = null, $message = 'Success') {
        self::send(200, $data, $message);
    }
    
    /**
     * Send created response (201 Created)
     *
     * @param mixed $data
     * @param string $message
     * @return void
     */
    public static function created($data = null, $message = 'Resource created successfully') {
        self::send(201, $data, $message);
    }
    
    /**
     * Send no content response (204 No Content)
     *
     * @return void
     */
    public static function noContent() {
        http_response_code(204);
        header('Content-Type: application/json');
        exit;
    }
    
    /**
     * Send bad request response (400 Bad Request)
     *
     * @param string $message
     * @param mixed $errors Optional validation errors
     * @return void
     */
    public static function badRequest($message = 'Bad request', $errors = null) {
        self::send(400, $errors, $message);
    }
    
    /**
     * Send unauthorized response (401 Unauthorized)
     *
     * @param string $message
     * @return void
     */
    public static function unauthorized($message = 'Unauthorized. Please login.') {
        self::send(401, null, $message);
    }
    
    /**
     * Send forbidden response (403 Forbidden)
     *
     * @param string $message
     * @return void
     */
    public static function forbidden($message = 'Access denied. Insufficient permissions.') {
        self::send(403, null, $message);
    }
    
    /**
     * Send not found response (404 Not Found)
     *
     * @param string $message
     * @return void
     */
    public static function notFound($message = 'Resource not found') {
        self::send(404, null, $message);
    }
    
    /**
     * Send conflict response (409 Conflict)
     *
     * @param string $message
     * @return void
     */
    public static function conflict($message = 'Resource already exists') {
        self::send(409, null, $message);
    }
    
    /**
     * Send unprocessable entity response (422 Unprocessable Entity)
     *
     * @param string $message
     * @param mixed $errors Validation errors
     * @return void
     */
    public static function unprocessable($message = 'Validation failed', $errors = null) {
        self::send(422, $errors, $message);
    }
    
    /**
     * Send internal server error response (500 Internal Server Error)
     *
     * @param string $message
     * @return void
     */
    public static function internalError($message = 'Internal server error. Please try again later.') {
        self::send(500, null, $message);
    }
    
    /**
     * Send service unavailable response (503 Service Unavailable)
     *
     * @param string $message
     * @return void
     */
    public static function serviceUnavailable($message = 'Service temporarily unavailable. Please try again later.') {
        self::send(503, null, $message);
    }
}
?>