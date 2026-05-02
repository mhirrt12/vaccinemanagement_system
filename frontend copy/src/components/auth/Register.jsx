import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { register } from '../../services/authService';

const Register = () => {
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    confirmPassword: ''
  });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);
  const [successMessage, setSuccessMessage] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const navigate = useNavigate();

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    // Clear field error when user types
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  const validateForm = () => {
    const newErrors = {};
    const { name, email, phone, password, confirmPassword } = formData;

    // Name validation
    if (!name.trim()) newErrors.name = 'Full name is required';
    else if (name.trim().length < 3) newErrors.name = 'Name must be at least 3 characters';

    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email) newErrors.email = 'Email is required';
    else if (!emailRegex.test(email)) newErrors.email = 'Enter a valid email address';

    // Ethiopian phone validation (10 digits, starts with 09)
    const phoneRegex = /^09[0-9]{8}$/;
    if (!phone) newErrors.phone = 'Phone number is required';
    else if (!phoneRegex.test(phone)) newErrors.phone = 'Phone must be 10 digits and start with 09 (e.g., 0912345678)';

    // Strong password validation: min 8 chars, at least one letter, one number, one symbol
    const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{8,}$/;
    if (!password) newErrors.password = 'Password is required';
    else if (!passwordRegex.test(password)) {
      newErrors.password = 'Password must be at least 8 characters, include a letter, a number, and a symbol (@$!%*#?&)';
    }

    // Confirm password
    if (password !== confirmPassword) newErrors.confirmPassword = 'Passwords do not match';

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validateForm()) return;

    setLoading(true);
    setSuccessMessage('');
    try {
      await register(formData.name, formData.email, formData.phone, formData.password, formData.confirmPassword);
      setSuccessMessage('Registration successful! Your account is pending nurse approval. You will be notified when approved.');
      // Clear form
      setFormData({ name: '', email: '', phone: '', password: '', confirmPassword: '' });
      // Redirect to login after 3 seconds
      setTimeout(() => navigate('/login'), 3000);
    } catch (err) {
      const message = err.response?.data?.message || 'Registration failed. Please try again.';
      setErrors({ general: message });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="row justify-content-center">
      <div className="col-md-8 col-lg-7">
        <div className="card shadow-lg border-0 rounded-4 mt-4 mb-5">
          <div className="card-header bg-success text-white text-center py-3 rounded-top-4">
            <h4 className="mb-0">Parent Registration</h4>
            <small>Create an account to manage your child's vaccinations</small>
          </div>
          <div className="card-body p-4">
            {errors.general && (
              <div className="alert alert-danger alert-dismissible fade show" role="alert">
                {errors.general}
                <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setErrors({})}></button>
              </div>
            )}
            {successMessage && (
              <div className="alert alert-success alert-dismissible fade show" role="alert">
                {successMessage}
                <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setSuccessMessage('')}></button>
              </div>
            )}
            <form onSubmit={handleSubmit}>
              <div className="mb-3">
                <label htmlFor="name" className="form-label">Full Name *</label>
                <input
                  type="text"
                  className={`form-control ${errors.name ? 'is-invalid' : ''}`}
                  id="name"
                  name="name"
                  placeholder="Enter your full name"
                  value={formData.name}
                  onChange={handleChange}
                  required
                />
                {errors.name && <div className="invalid-feedback">{errors.name}</div>}
              </div>

              <div className="mb-3">
                <label htmlFor="email" className="form-label">Email Address *</label>
                <input
                  type="email"
                  className={`form-control ${errors.email ? 'is-invalid' : ''}`}
                  id="email"
                  name="email"
                  placeholder="you@example.com"
                  value={formData.email}
                  onChange={handleChange}
                  required
                />
                {errors.email && <div className="invalid-feedback">{errors.email}</div>}
              </div>

              <div className="mb-3">
                <label htmlFor="phone" className="form-label">Phone Number *</label>
                <input
                  type="tel"
                  className={`form-control ${errors.phone ? 'is-invalid' : ''}`}
                  id="phone"
                  name="phone"
                  placeholder="09xxxxxxxx"
                  value={formData.phone}
                  onChange={handleChange}
                  required
                />
                {errors.phone && <div className="invalid-feedback">{errors.phone}</div>}
                <small className="text-muted">Ethiopian phone number (10 digits, starts with 09)</small>
              </div>

              <div className="mb-3">
                <label htmlFor="password" className="form-label">Password *</label>
                <div className="input-group">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    className={`form-control ${errors.password ? 'is-invalid' : ''}`}
                    id="password"
                    name="password"
                    placeholder="Create a strong password"
                    value={formData.password}
                    onChange={handleChange}
                    required
                  />
                  <button
                    type="button"
                    className="btn btn-outline-secondary"
                    onClick={() => setShowPassword(!showPassword)}
                  >
                    {showPassword ? '🙈' : '👁️'}
                  </button>
                  {errors.password && <div className="invalid-feedback">{errors.password}</div>}
                </div>
                <small className="text-muted">Minimum 8 characters, at least one letter, one number, and one symbol (@$!%*#?&)</small>
              </div>

              <div className="mb-4">
                <label htmlFor="confirmPassword" className="form-label">Confirm Password *</label>
                <input
                  type={showPassword ? 'text' : 'password'}
                  className={`form-control ${errors.confirmPassword ? 'is-invalid' : ''}`}
                  id="confirmPassword"
                  name="confirmPassword"
                  placeholder="Confirm your password"
                  value={formData.confirmPassword}
                  onChange={handleChange}
                  required
                />
                {errors.confirmPassword && <div className="invalid-feedback">{errors.confirmPassword}</div>}
              </div>

              <div className="d-grid gap-2">
                <button type="submit" className="btn btn-success btn-lg" disabled={loading}>
                  {loading ? (
                    <>
                      <span className="spinner-border spinner-border-sm me-2" role="status"></span>
                      Registering...
                    </>
                  ) : (
                    'Register'
                  )}
                </button>
              </div>
            </form>
            <hr className="my-4" />
            <div className="text-center">
              Already have an account? <Link to="/login">Login</Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Register;