import api from './api';

/**
 * Login user with email and password
 * @param {string} email - User email
 * @param {string} password - User password
 * @returns {Promise} Axios response with token and user data
 */
export const login = (email, password) => {
  return api.post('/auth/login', { email, password });
};

/**
 * Register a new parent account
 * @param {string} name - Full name
 * @param {string} email - Email address
 * @param {string} phone - Ethiopian phone number (10 digits, starts with 09)
 * @param {string} password - Password (strong)
 * @param {string} confirmPassword - Password confirmation
 * @returns {Promise} Axios response
 */
export const register = (name, email, phone, password, confirmPassword) => {
  return api.post('/auth/register', { name, email, phone, password, confirm_password: confirmPassword });
};

/**
 * Get current authenticated user details
 * @returns {Promise} Axios response with user data
 */
export const getMe = () => {
  return api.get('/auth/me');
};

/**
 * Request password reset (send reset link to email)
 * @param {string} email - User email
 * @returns {Promise} Axios response
 */
export const forgotPassword = (email) => {
  return api.post('/auth/forgot-password', { email });
};

/**
 * Reset password using token from email
 * @param {string} email - User email
 * @param {string} token - Reset token from email link
 * @param {string} newPassword - New password
 * @param {string} confirmPassword - Password confirmation
 * @returns {Promise} Axios response
 */
export const resetPassword = (email, token, newPassword, confirmPassword) => {
  return api.post('/auth/reset-password', { email, token, new_password: newPassword, confirm_password: confirmPassword });
};

/**
 * Logout user (client-side only for JWT; no server endpoint needed)
 * @returns {void}
 */
export const logout = () => {
  localStorage.removeItem('token');
  delete api.defaults.headers.common['Authorization'];
  window.location.href = '/login';
};