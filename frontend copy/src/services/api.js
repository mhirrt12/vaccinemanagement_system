import axios from 'axios';

// Get API base URL from environment or use default
const API_BASE_URL = process.env.REACT_APP_API_URL || 'http://localhost:8000/api';

// Create axios instance with default config
const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  timeout: 30000, // 30 seconds timeout
});

// Request interceptor to add token to headers
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor to handle common errors
api.interceptors.response.use(
  (response) => {
    return response;
  },
  (error) => {
    const { response } = error;
    
    // Handle 401 Unauthorized - token expired or invalid
    if (response && response.status === 401) {
      localStorage.removeItem('token');
      // Redirect to login page if not already there
      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }
    
    // Handle 403 Forbidden
    if (response && response.status === 403) {
      console.error('Access denied:', response.data?.message);
    }
    
    // Handle 500 Server Error
    if (response && response.status >= 500) {
      console.error('Server error:', response.data?.message);
    }
    
    return Promise.reject(error);
  }
);

export default api;