import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

/**
 * PrivateRoute Component - Protects routes based on authentication and user role
 * 
 * @param {Object} props
 * @param {string[]} props.allowedRoles - Array of roles allowed to access this route (e.g., ['parent'], ['nurse', 'admin'])
 * @returns {JSX.Element} - Outlet if authorized, otherwise redirect to login or unauthorized page
 */
const PrivateRoute = ({ allowedRoles = [] }) => {
  const { user, loading } = useAuth();

  // Show loading spinner while checking authentication
  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: '60vh' }}>
        <div className="spinner-border text-primary" role="status">
          <span className="visually-hidden">Loading...</span>
        </div>
      </div>
    );
  }

  // Not authenticated: redirect to login
  if (!user) {
    return <Navigate to="/login" replace />;
  }

  // Check role-based access
  if (allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
    // User is authenticated but not authorized for this route
    // Redirect to appropriate dashboard based on role
    let dashboardPath = '/';
    switch (user.role) {
      case 'parent':
        dashboardPath = '/parent';
        break;
      case 'nurse':
        dashboardPath = '/nurse';
        break;
      case 'admin':
        dashboardPath = '/admin';
        break;
      default:
        dashboardPath = '/login';
    }
    return <Navigate to={dashboardPath} replace />;
  }

  // Authorized: render child routes
  return <Outlet />;
};

export default PrivateRoute;