<?php
class CorsMiddleware {
    public static function handle() {
        // Allow requests from your React dev server
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:3001'
        ];
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true");
        } else {
            // For development, you can also set a specific allowed origin
            header("Access-Control-Allow-Origin: http://localhost:3000");
        }
        
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Max-Age: 86400");
        
        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}