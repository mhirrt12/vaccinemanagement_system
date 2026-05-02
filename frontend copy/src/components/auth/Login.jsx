import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';

const Login = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    // Basic validation
    if (!email.trim() || !password.trim()) {
      setError('Email and password are required');
      setLoading(false);
      return;
    }

    try {
      const user = await login(email, password);
      // Redirect based on role
      switch (user.role) {
        case 'parent':
          navigate('/parent');
          break;
        case 'nurse':
          navigate('/nurse');
          break;
        case 'admin':
          navigate('/admin');
          break;
        default:
          navigate('/');
      }
    } catch (err) {
      const message = err.response?.data?.message || 'Login failed. Please check your credentials.';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="row justify-content-center">
      <div className="col-md-6 col-lg-5">
        <div className="card shadow-lg border-0 rounded-4 mt-5">
          <div className="card-header bg-primary text-white text-center py-3 rounded-top-4">
            <h4 className="mb-0">Vaccine Management System</h4>
            <small>Login to your account</small>
          </div>
          <div className="card-body p-4">
            {error && (
              <div className="alert alert-danger alert-dismissible fade show" role="alert">
                {error}
                <button type="button" className="btn-close" data-bs-dismiss="alert" aria-label="Close" onClick={() => setError('')}></button>
              </div>
            )}
            <form onSubmit={handleSubmit}>
              <div className="mb-3">
                <label htmlFor="email" className="form-label">Email Address</label>
                <div className="input-group">
                  <span className="input-group-text"><i className="bi bi-envelope"></i>📧</span>
                  <input
                    type="email"
                    className="form-control"
                    id="email"
                    placeholder="name@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                    autoComplete="email"
                  />
                </div>
              </div>
              <div className="mb-3">
                <label htmlFor="password" className="form-label">Password</label>
                <div className="input-group">
                  <span className="input-group-text"><i className="bi bi-lock"></i>🔒</span>
                  <input
                    type={showPassword ? 'text' : 'password'}
                    className="form-control"
                    id="password"
                    placeholder="Enter your password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    autoComplete="current-password"
                  />
                  <button
                    type="button"
                    className="btn btn-outline-secondary"
                    onClick={() => setShowPassword(!showPassword)}
                    tabIndex="-1"
                  >
                    {showPassword ? '🙈' : '👁️'}
                  </button>
                </div>
              </div>
              <div className="d-grid gap-2">
                <button type="submit" className="btn btn-primary btn-lg" disabled={loading}>
                  {loading ? (
                    <>
                      <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                      Logging in...
                    </>
                  ) : (
                    'Login'
                  )}
                </button>
              </div>
            </form>
            <hr className="my-4" />
            <div className="text-center">
              <p className="mb-2">
                <Link to="/forgot-password">Forgot password?</Link>
              </p>
              <p className="mb-0">
                Don't have an account? <Link to="/register">Register as Parent</Link>
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Login;