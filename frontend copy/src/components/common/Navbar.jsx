import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import LanguageSwitcher from './LanguageSwitcher';

const Navbar = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  // Determine dashboard link based on role
  const getDashboardLink = () => {
    if (!user) return '/login';
    switch (user.role) {
      case 'parent':
        return '/parent';
      case 'nurse':
        return '/nurse';
      case 'admin':
        return '/admin';
      default:
        return '/login';
    }
  };

  return (
    <nav className="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
      <div className="container">
        <Link className="navbar-brand d-flex align-items-center" to={getDashboardLink()}>
          <img src="/assets/img/logo.png" alt="VMS Logo" style={{ height: '35px', marginRight: '10px' }} />
          <span>🇪🇹 Vaccine Management System</span>
        </Link>
        <button
          className="navbar-toggler"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#navbarMain"
          aria-controls="navbarMain"
          aria-expanded="false"
          aria-label="Toggle navigation"
        >
          <span className="navbar-toggler-icon"></span>
        </button>
        <div className="collapse navbar-collapse" id="navbarMain">
          <ul className="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
            {user ? (
              <>
                <li className="nav-item">
                  <span className="nav-link text-white-50">
                    Hello, {user.name} ({user.role})
                  </span>
                </li>
                <li className="nav-item">
                  <Link className="nav-link" to={getDashboardLink()}>
                    Dashboard
                  </Link>
                </li>
                {user.role === 'parent' && (
                  <li className="nav-item">
                    <Link className="nav-link" to="/parent/children">
                      My Children
                    </Link>
                  </li>
                )}
                {user.role === 'nurse' && (
                  <>
                    <li className="nav-item">
                      <Link className="nav-link" to="/nurse/assigned">
                        Assigned Children
                      </Link>
                    </li>
                    <li className="nav-item">
                      <Link className="nav-link" to="/nurse/walkin">
                        Walk-in
                      </Link>
                    </li>
                    <li className="nav-item">
                      <Link className="nav-link" to="/nurse/reports">
                        Reports
                      </Link>
                    </li>
                  </>
                )}
                {user.role === 'admin' && (
                  <>
                    <li className="nav-item">
                      <Link className="nav-link" to="/admin/vaccines">
                        Vaccines
                      </Link>
                    </li>
                    <li className="nav-item">
                      <Link className="nav-link" to="/admin/inventory">
                        Inventory
                      </Link>
                    </li>
                    <li className="nav-item">
                      <Link className="nav-link" to="/admin/nurses">
                        Nurses
                      </Link>
                    </li>
                    <li className="nav-item">
                      <Link className="nav-link" to="/admin/audit-logs">
                        Audit Logs
                      </Link>
                    </li>
                  </>
                )}
                <li className="nav-item">
                  <button className="btn btn-outline-light btn-sm ms-2" onClick={handleLogout}>
                    Logout
                  </button>
                </li>
              </>
            ) : (
              <>
                <li className="nav-item">
                  <Link className="nav-link" to="/login">
                    Login
                  </Link>
                </li>
                <li className="nav-item">
                  <Link className="nav-link" to="/register">
                    Register as Parent
                  </Link>
                </li>
              </>
            )}
            <li className="nav-item ms-3">
              <LanguageSwitcher />
            </li>
          </ul>
        </div>
      </div>
    </nav>
  );
};

export default Navbar;