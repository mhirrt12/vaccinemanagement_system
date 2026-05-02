import React, { createContext, useState, useEffect, useContext } from 'react';
import api from '../services/api';
import { login as apiLogin, register as apiRegister, getMe } from '../services/authService';

// Create context
const AuthContext = createContext();

// Custom hook to use auth context
export const useAuth = () => useContext(AuthContext);

// JWT decode helper (simple)
const decodeToken = (token) => {
  try {
    const payload = token.split('.')[1];
    const decoded = JSON.parse(atob(payload));
    return decoded;
  } catch (err) {
    return null;
  }
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [token, setToken] = useState(localStorage.getItem('token'));

  // Set auth header on api instance
  useEffect(() => {
    if (token) {
      api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    } else {
      delete api.defaults.headers.common['Authorization'];
    }
  }, [token]);

  // Load user from token on mount
  useEffect(() => {
    const initAuth = async () => {
      const storedToken = localStorage.getItem('token');
      if (storedToken) {
        const decoded = decodeToken(storedToken);
        if (decoded && decoded.exp * 1000 > Date.now()) {
          setToken(storedToken);
          try {
            // Fetch fresh user data
            const response = await getMe();
            setUser(response.data);
          } catch (err) {
            // Token invalid or expired
            localStorage.removeItem('token');
            setToken(null);
            setUser(null);
          }
        } else {
          localStorage.removeItem('token');
          setToken(null);
        }
      }
      setLoading(false);
    };
    initAuth();
  }, []);

  const login = async (email, password) => {
    const response = await apiLogin(email, password);
    const { token: newToken, user: userData } = response.data;
    localStorage.setItem('token', newToken);
    setToken(newToken);
    setUser(userData);
    return userData;
  };

  const register = async (name, email, phone, password, confirmPassword) => {
    return await apiRegister(name, email, phone, password, confirmPassword);
  };

  const logout = () => {
    localStorage.removeItem('token');
    setToken(null);
    setUser(null);
  };

  const updateUser = (updatedData) => {
    setUser(prev => ({ ...prev, ...updatedData }));
  };

  const value = {
    user,
    loading,
    login,
    register,
    logout,
    updateUser,
    isAuthenticated: !!user,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};