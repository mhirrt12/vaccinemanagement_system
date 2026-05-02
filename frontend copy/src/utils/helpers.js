/**
 * Helpers - Collection of utility functions
 * 
 * Includes:
 * - JWT token decoding
 * - Date formatting
 * - Age calculation from DOB
 * - Vaccine status badge mapping
 * - Pagination helpers
 * - Debounce function
 * - Local storage helpers
 * - File download helper
 */

/**
 * Decode JWT token to extract payload
 * @param {string} token - JWT token string
 * @returns {object|null} Decoded payload or null if invalid
 */
export const decodeToken = (token) => {
  try {
    const base64Url = token.split('.')[1];
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
    const jsonPayload = decodeURIComponent(
      atob(base64)
        .split('')
        .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    );
    return JSON.parse(jsonPayload);
  } catch (error) {
    console.error('Failed to decode token:', error);
    return null;
  }
};

/**
 * Check if token is expired
 * @param {string} token - JWT token string
 * @returns {boolean}
 */
export const isTokenExpired = (token) => {
  const decoded = decodeToken(token);
  if (!decoded || !decoded.exp) return true;
  return decoded.exp * 1000 < Date.now();
};

/**
 * Format date to readable string
 * @param {string|Date} date - Date to format
 * @param {string} format - 'short', 'long', or 'iso'
 * @returns {string}
 */
export const formatDate = (date, format = 'short') => {
  if (!date) return 'N/A';
  const d = new Date(date);
  if (isNaN(d.getTime())) return 'Invalid date';
  
  switch (format) {
    case 'long':
      return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    case 'iso':
      return d.toISOString().split('T')[0];
    case 'short':
    default:
      return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
  }
};

/**
 * Format datetime to readable string
 * @param {string|Date} date - Date to format
 * @returns {string}
 */
export const formatDateTime = (date) => {
  if (!date) return 'N/A';
  const d = new Date(date);
  if (isNaN(d.getTime())) return 'Invalid date';
  return d.toLocaleString('en-US', { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

/**
 * Calculate age from date of birth
 * @param {string} dob - Date of birth (YYYY-MM-DD)
 * @returns {string} Human readable age (e.g., "2 years 3 months" or "45 days")
 */
export const calculateAge = (dob) => {
  if (!dob) return 'N/A';
  const birthDate = new Date(dob);
  const today = new Date();
  if (isNaN(birthDate.getTime())) return 'Invalid DOB';
  
  let years = today.getFullYear() - birthDate.getFullYear();
  let months = today.getMonth() - birthDate.getMonth();
  let days = today.getDate() - birthDate.getDate();
  
  if (days < 0) {
    months--;
    days += new Date(today.getFullYear(), today.getMonth(), 0).getDate();
  }
  if (months < 0) {
    years--;
    months += 12;
  }
  
  if (years > 0) return `${years} year${years !== 1 ? 's' : ''} ${months} month${months !== 1 ? 's' : ''}`;
  if (months > 0) return `${months} month${months !== 1 ? 's' : ''} ${days} day${days !== 1 ? 's' : ''}`;
  return `${days} day${days !== 1 ? 's' : ''}`;
};

/**
 * Get status badge class for Bootstrap
 * @param {string} status - Vaccine appointment status
 * @returns {string} Bootstrap badge class
 */
export const getStatusBadgeClass = (status) => {
  const map = {
    'completed': 'bg-success',
    'given': 'bg-success',
    'scheduled': 'bg-primary',
    'pending': 'bg-warning text-dark',
    'overdue': 'bg-danger',
    'missed': 'bg-danger',
    'rescheduled': 'bg-info text-dark',
    'cancelled': 'bg-secondary',
    'approved': 'bg-success',
    'rejected': 'bg-danger'
  };
  return map[status?.toLowerCase()] || 'bg-secondary';
};

/**
 * Truncate text with ellipsis
 * @param {string} text - Text to truncate
 * @param {number} maxLength - Maximum length
 * @returns {string}
 */
export const truncateText = (text, maxLength = 50) => {
  if (!text) return '';
  if (text.length <= maxLength) return text;
  return text.substring(0, maxLength) + '...';
};

/**
 * Debounce function for input search
 * @param {Function} func - Function to debounce
 * @param {number} delay - Delay in milliseconds
 * @returns {Function}
 */
export const debounce = (func, delay = 300) => {
  let timeoutId;
  return (...args) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func(...args), delay);
  };
};

/**
 * Save to local storage with JSON serialization
 * @param {string} key - Storage key
 * @param {any} value - Value to store
 */
export const setLocalStorage = (key, value) => {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch (error) {
    console.error('Failed to save to localStorage:', error);
  }
};

/**
 * Get from local storage with JSON parsing
 * @param {string} key - Storage key
 * @param {any} defaultValue - Default value if not found
 * @returns {any}
 */
export const getLocalStorage = (key, defaultValue = null) => {
  try {
    const item = localStorage.getItem(key);
    return item ? JSON.parse(item) : defaultValue;
  } catch (error) {
    console.error('Failed to read from localStorage:', error);
    return defaultValue;
  }
};

/**
 * Remove from local storage
 * @param {string} key - Storage key
 */
export const removeLocalStorage = (key) => {
  localStorage.removeItem(key);
};

/**
 * Trigger file download from blob data
 * @param {Blob} blob - File blob
 * @param {string} filename - Desired filename
 */
export const downloadBlob = (blob, filename) => {
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.setAttribute('download', filename);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};

/**
 * Get query parameters from URL
 * @returns {object} Key-value pairs of query params
 */
export const getQueryParams = () => {
  const params = new URLSearchParams(window.location.search);
  const result = {};
  for (const [key, value] of params.entries()) {
    result[key] = value;
  }
  return result;
};

/**
 * Scroll to top of page smoothly
 */
export const scrollToTop = () => {
  window.scrollTo({ top: 0, behavior: 'smooth' });
};

/**
 * Copy text to clipboard
 * @param {string} text - Text to copy
 * @returns {Promise<boolean>}
 */
export const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (error) {
    console.error('Failed to copy:', error);
    return false;
  }
};

/**
 * Group array by key
 * @param {Array} array - Array to group
 * @param {string} key - Key to group by
 * @returns {object} Grouped object
 */
export const groupBy = (array, key) => {
  return array.reduce((result, item) => {
    const groupKey = item[key];
    if (!result[groupKey]) result[groupKey] = [];
    result[groupKey].push(item);
    return result;
  }, {});
};

/**
 * Sort array by date field
 * @param {Array} array - Array to sort
 * @param {string} dateField - Field containing date
 * @param {boolean} ascending - True for ascending, false for descending
 * @returns {Array} Sorted array
 */
export const sortByDate = (array, dateField, ascending = true) => {
  return [...array].sort((a, b) => {
    const dateA = new Date(a[dateField]);
    const dateB = new Date(b[dateField]);
    return ascending ? dateA - dateB : dateB - dateA;
  });
};