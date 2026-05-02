<?php
/**
 * Validator Helper - Provides static validation methods
 * 
 * Responsibilities:
 * - Validate common input types (email, phone, password, dates, numbers)
 * - Ethiopian phone number validation (10 digits starting with 09)
 * - Strong password validation (min 8 chars, letter, number, symbol)
 * - Date validations (future, past, range)
 * - Numeric validations (min, max, between)
 * - String length validations
 * - Sanitization methods
 */

class Validator {
    /**
     * Check if a value is not empty after trimming
     *
     * @param mixed $value
     * @return bool
     */
    public static function required($value) {
        if (is_null($value)) return false;
        if (is_string($value)) return trim($value) !== '';
        return !empty($value);
    }
    
    /**
     * Validate email address format
     *
     * @param string $email
     * @return bool
     */
    public static function email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate Ethiopian phone number
     * Format: 10 digits, starts with 09
     * Examples: 0912345678, 0987654321
     *
     * @param string $phone
     * @return bool
     */
    public static function ethiopianPhone($phone) {
        return preg_match('/^09[0-9]{8}$/', $phone) === 1;
    }
    
    /**
     * Validate strong password
     * Requirements: at least 8 characters, at least one letter, one number, and one symbol
     *
     * @param string $password
     * @return bool
     */
    public static function strongPassword($password) {
        return preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{8,}$/', $password) === 1;
    }
    
    /**
     * Validate that a date string is in Y-m-d format and is a valid date
     *
     * @param string $date
     * @return bool
     */
    public static function date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Validate that a date is in the future (not including today)
     *
     * @param string $date (Y-m-d)
     * @return bool
     */
    public static function futureDate($date) {
        if (!self::date($date)) return false;
        return strtotime($date) > strtotime(date('Y-m-d'));
    }
    
    /**
     * Validate that a date is in the past (not including today)
     *
     * @param string $date (Y-m-d)
     * @return bool
     */
    public static function pastDate($date) {
        if (!self::date($date)) return false;
        return strtotime($date) < strtotime(date('Y-m-d'));
    }
    
    /**
     * Validate that a date is today or in the future
     *
     * @param string $date (Y-m-d)
     * @return bool
     */
    public static function todayOrFuture($date) {
        if (!self::date($date)) return false;
        return strtotime($date) >= strtotime(date('Y-m-d'));
    }
    
    /**
     * Validate that a date is today or in the past
     *
     * @param string $date (Y-m-d)
     * @return bool
     */
    public static function todayOrPast($date) {
        if (!self::date($date)) return false;
        return strtotime($date) <= strtotime(date('Y-m-d'));
    }
    
    /**
     * Validate numeric value
     *
     * @param mixed $value
     * @return bool
     */
    public static function numeric($value) {
        return is_numeric($value);
    }
    
    /**
     * Validate integer value
     *
     * @param mixed $value
     * @return bool
     */
    public static function integer($value) {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
    
    /**
     * Validate float/decimal value
     *
     * @param mixed $value
     * @return bool
     */
    public static function float($value) {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }
    
    /**
     * Validate that a number is within a range (inclusive)
     *
     * @param mixed $value
     * @param int|float $min
     * @param int|float $max
     * @return bool
     */
    public static function between($value, $min, $max) {
        if (!self::numeric($value)) return false;
        $value = (float)$value;
        return $value >= $min && $value <= $max;
    }
    
    /**
     * Validate that a number is at least a minimum value
     *
     * @param mixed $value
     * @param int|float $min
     * @return bool
     */
    public static function min($value, $min) {
        if (!self::numeric($value)) return false;
        return (float)$value >= $min;
    }
    
    /**
     * Validate that a number is at most a maximum value
     *
     * @param mixed $value
     * @param int|float $max
     * @return bool
     */
    public static function max($value, $max) {
        if (!self::numeric($value)) return false;
        return (float)$value <= $max;
    }
    
    /**
     * Validate string length (minimum)
     *
     * @param string $value
     * @param int $min
     * @return bool
     */
    public static function minLength($value, $min) {
        return strlen($value) >= $min;
    }
    
    /**
     * Validate string length (maximum)
     *
     * @param string $value
     * @param int $max
     * @return bool
     */
    public static function maxLength($value, $max) {
        return strlen($value) <= $max;
    }
    
    /**
     * Validate string length between min and max
     *
     * @param string $value
     * @param int $min
     * @param int $max
     * @return bool
     */
    public static function lengthBetween($value, $min, $max) {
        $len = strlen($value);
        return $len >= $min && $len <= $max;
    }
    
    /**
     * Validate that value is one of the allowed options
     *
     * @param mixed $value
     * @param array $allowed
     * @return bool
     */
    public static function inArray($value, $allowed) {
        return in_array($value, $allowed);
    }
    
    /**
     * Validate URL format
     *
     * @param string $url
     * @return bool
     */
    public static function url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Validate boolean value (true/false, 1/0, 'true'/'false')
     *
     * @param mixed $value
     * @return bool
     */
    public static function boolean($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null;
    }
    
    /**
     * Validate that a string contains only letters and spaces
     *
     * @param string $value
     * @return bool
     */
    public static function alpha($value) {
        return preg_match('/^[A-Za-z\s]+$/', $value) === 1;
    }
    
    /**
     * Validate that a string contains only alphanumeric characters
     *
     * @param string $value
     * @return bool
     */
    public static function alphanumeric($value) {
        return preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
    }
    
    /**
     * Validate blood type format (A+, A-, B+, B-, AB+, AB-, O+, O-)
     *
     * @param string $bloodType
     * @return bool
     */
    public static function bloodType($bloodType) {
        $allowed = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        return in_array($bloodType, $allowed);
    }
    
    /**
     * Validate gender (Male, Female, Other)
     *
     * @param string $gender
     * @return bool
     */
    public static function gender($gender) {
        $allowed = ['Male', 'Female', 'Other'];
        return in_array($gender, $allowed);
    }
    
    /**
     * Sanitize string (basic XSS prevention)
     *
     * @param string $input
     * @return string
     */
    public static function sanitizeString($input) {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize email
     *
     * @param string $email
     * @return string
     */
    public static function sanitizeEmail($email) {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }
    
    /**
     * Sanitize phone number (remove non-numeric)
     *
     * @param string $phone
     * @return string
     */
    public static function sanitizePhone($phone) {
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
?>