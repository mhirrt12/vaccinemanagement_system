/**
 * Validators - Collection of frontend validation functions
 * 
 * Used for:
 * - Email format validation
 * - Ethiopian phone number validation (10 digits, starts with 09)
 * - Strong password validation (min 8 chars, letter, number, symbol)
 * - Required field validation
 * - Date validations
 * - Blood type validation
 */

/**
 * Check if a value is not empty after trimming
 * @param {string} value - Input value
 * @returns {boolean}
 */
export const isRequired = (value) => {
  return typeof value === 'string' ? value.trim().length > 0 : !!value;
};

/**
 * Validate email format
 * @param {string} email - Email address
 * @returns {boolean}
 */
export const isValidEmail = (email) => {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
};

/**
 * Validate Ethiopian phone number
 * Format: 10 digits, starts with 09
 * Example: 0912345678
 * @param {string} phone - Phone number
 * @returns {boolean}
 */
export const isValidEthiopianPhone = (phone) => {
  const phoneRegex = /^09[0-9]{8}$/;
  return phoneRegex.test(phone);
};

/**
 * Validate strong password
 * Requirements:
 * - At least 8 characters
 * - At least one letter (A-Z or a-z)
 * - At least one number (0-9)
 * - At least one special character (@$!%*#?&)
 * @param {string} password - Password
 * @returns {boolean}
 */
export const isStrongPassword = (password) => {
  const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{8,}$/;
  return passwordRegex.test(password);
};

/**
 * Validate that two passwords match
 * @param {string} password - Original password
 * @param {string} confirmPassword - Confirmation password
 * @returns {boolean}
 */
export const doPasswordsMatch = (password, confirmPassword) => {
  return password === confirmPassword;
};

/**
 * Validate that a string is within min and max length
 * @param {string} value - Input string
 * @param {number} min - Minimum length
 * @param {number} max - Maximum length
 * @returns {boolean}
 */
export const isLengthBetween = (value, min, max) => {
  const len = value ? value.length : 0;
  return len >= min && len <= max;
};

/**
 * Validate that a value is a number and within range
 * @param {number} value - Numeric value
 * @param {number} min - Minimum allowed
 * @param {number} max - Maximum allowed
 * @returns {boolean}
 */
export const isNumberInRange = (value, min, max) => {
  if (value === undefined || value === null || value === '') return false;
  const num = parseFloat(value);
  return !isNaN(num) && num >= min && num <= max;
};

/**
 * Validate date string in YYYY-MM-DD format
 * @param {string} date - Date string
 * @returns {boolean}
 */
export const isValidDate = (date) => {
  const regex = /^\d{4}-\d{2}-\d{2}$/;
  if (!regex.test(date)) return false;
  const d = new Date(date);
  return d instanceof Date && !isNaN(d) && d.toISOString().split('T')[0] === date;
};

/**
 * Validate that date is not in the future
 * @param {string} date - Date string (YYYY-MM-DD)
 * @returns {boolean}
 */
export const isNotFutureDate = (date) => {
  if (!isValidDate(date)) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const inputDate = new Date(date);
  return inputDate <= today;
};

/**
 * Validate that date is not in the past
 * @param {string} date - Date string (YYYY-MM-DD)
 * @returns {boolean}
 */
export const isNotPastDate = (date) => {
  if (!isValidDate(date)) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const inputDate = new Date(date);
  return inputDate >= today;
};

/**
 * Validate blood type format
 * @param {string} bloodType - Blood type (A+, A-, B+, B-, AB+, AB-, O+, O-)
 * @returns {boolean}
 */
export const isValidBloodType = (bloodType) => {
  const validTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', ''];
  return validTypes.includes(bloodType);
};

/**
 * Validate gender (Male, Female, Other)
 * @param {string} gender - Gender
 * @returns {boolean}
 */
export const isValidGender = (gender) => {
  const validGenders = ['Male', 'Female', 'Other'];
  return validGenders.includes(gender);
};

/**
 * Validate delivery type (Normal, C-Section)
 * @param {string} deliveryType - Delivery type
 * @returns {boolean}
 */
export const isValidDeliveryType = (deliveryType) => {
  const validTypes = ['Normal', 'C-Section'];
  return validTypes.includes(deliveryType);
};

/**
 * Validate URL format
 * @param {string} url - URL string
 * @returns {boolean}
 */
export const isValidUrl = (url) => {
  try {
    new URL(url);
    return true;
  } catch {
    return false;
  }
};