import React, { useState, useEffect } from 'react';
import { getNurses, createNurse, deleteNurse } from '../../services/adminService';

const NurseManager = () => {
  const [nurses, setNurses] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: ''
  });
  const [formErrors, setFormErrors] = useState({});

  useEffect(() => {
    loadNurses();
  }, []);

  const loadNurses = async () => {
    setLoading(true);
    try {
      const res = await getNurses();
      setNurses(res.data || []);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load nurses');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenModal = () => {
    setFormData({ name: '', email: '', phone: '', password: '' });
    setFormErrors({});
    setError('');
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
  };

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    // Clear field error when user types
    if (formErrors[name]) {
      setFormErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  const validateForm = () => {
    const errors = {};
    const { name, email, phone, password } = formData;
    if (!name.trim()) errors.name = 'Name is required';
    if (!email.trim()) errors.email = 'Email is required';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Invalid email format';
    if (!phone.trim()) errors.phone = 'Phone is required';
    else if (!/^09[0-9]{8}$/.test(phone)) errors.phone = 'Phone must be 10 digits starting with 09';
    if (!password) errors.password = 'Password is required';
    else if (password.length < 8) errors.password = 'Password must be at least 8 characters';
    else if (!/(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])/.test(password)) {
      errors.password = 'Password must include letter, number, and symbol';
    }
    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validateForm()) return;
    setLoading(true);
    try {
      await createNurse(formData.name, formData.email, formData.phone, formData.password);
      setSuccess('Nurse account created successfully');
      handleCloseModal();
      loadNurses();
      setTimeout(() => setSuccess(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to create nurse');
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async (id, name) => {
    if (!window.confirm(`Are you sure you want to revoke nurse account for "${name}"? This action cannot be undone.`)) return;
    setLoading(true);
    try {
      await deleteNurse(id);
      setSuccess('Nurse account revoked');
      loadNurses();
      setTimeout(() => setSuccess(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || 'Delete failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h4>Manage Nurses</h4>
        <button className="btn btn-primary" onClick={handleOpenModal}>
          + Add New Nurse
        </button>
      </div>

      {error && <div className="alert alert-danger alert-dismissible"><button type="button" className="btn-close" onClick={() => setError('')}></button>{error}</div>}
      {success && <div className="alert alert-success alert-dismissible"><button type="button" className="btn-close" onClick={() => setSuccess('')}></button>{success}</div>}

      {loading && nurses.length === 0 && <div className="text-center"><div className="spinner-border text-primary"></div></div>}

      <div className="table-responsive">
        <table className="table table-bordered table-hover">
          <thead className="table-light">
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Created At</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {nurses.map(nurse => (
              <tr key={nurse.id}>
                <td>{nurse.id}</td>
                <td>{nurse.name}</td>
                <td>{nurse.email}</td>
                <td>{nurse.phone}</td>
                <td>{new Date(nurse.created_at).toLocaleDateString()}</td>
                <td>
                  <button className="btn btn-sm btn-danger" onClick={() => handleDelete(nurse.id, nurse.name)}>
                    Revoke
                  </button>
                </td>
              </tr>
            ))}
            {nurses.length === 0 && !loading && (
              <tr><td colSpan="6" className="text-center">No nurses found</td></tr>
            )}
          </tbody>
        ｜｜DSML｜｜
      </div>

      {/* Modal for adding nurse */}
      {showModal && (
        <div className="modal show d-block" tabIndex="-1" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header bg-primary text-white">
                <h5 className="modal-title">Add New Nurse</h5>
                <button type="button" className="btn-close btn-close-white" onClick={handleCloseModal}></button>
              </div>
              <form onSubmit={handleSubmit}>
                <div className="modal-body">
                  <div className="mb-3">
                    <label className="form-label">Full Name *</label>
                    <input type="text" className={`form-control ${formErrors.name ? 'is-invalid' : ''}`} name="name" value={formData.name} onChange={handleInputChange} />
                    {formErrors.name && <div className="invalid-feedback">{formErrors.name}</div>}
                  </div>
                  <div className="mb-3">
                    <label className="form-label">Email *</label>
                    <input type="email" className={`form-control ${formErrors.email ? 'is-invalid' : ''}`} name="email" value={formData.email} onChange={handleInputChange} />
                    {formErrors.email && <div className="invalid-feedback">{formErrors.email}</div>}
                  </div>
                  <div className="mb-3">
                    <label className="form-label">Phone (Ethiopian) *</label>
                    <input type="tel" className={`form-control ${formErrors.phone ? 'is-invalid' : ''}`} name="phone" value={formData.phone} onChange={handleInputChange} placeholder="09xxxxxxxx" />
                    {formErrors.phone && <div className="invalid-feedback">{formErrors.phone}</div>}
                    <small className="text-muted">10 digits starting with 09</small>
                  </div>
                  <div className="mb-3">
                    <label className="form-label">Password *</label>
                    <input type="password" className={`form-control ${formErrors.password ? 'is-invalid' : ''}`} name="password" value={formData.password} onChange={handleInputChange} />
                    {formErrors.password && <div className="invalid-feedback">{formErrors.password}</div>}
                    <small className="text-muted">Min 8 chars, at least one letter, one number, one symbol</small>
                  </div>
                </div>
                <div className="modal-footer">
                  <button type="button" className="btn btn-secondary" onClick={handleCloseModal}>Cancel</button>
                  <button type="submit" className="btn btn-primary" disabled={loading}>
                    {loading ? 'Creating...' : 'Create Nurse'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default NurseManager;