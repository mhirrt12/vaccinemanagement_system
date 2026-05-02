import React, { useState, useEffect } from 'react';
import { getVaccines, addVaccine, updateVaccine, deleteVaccine, toggleVaccineActive } from '../../services/adminService';

const VaccineManager = () => {
  const [vaccines, setVaccines] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingVaccine, setEditingVaccine] = useState(null);
  const [formData, setFormData] = useState({
    name: '',
    days_from_birth: '',
    description: ''
  });

  useEffect(() => {
    loadVaccines();
  }, []);

  const loadVaccines = async () => {
    setLoading(true);
    try {
      const res = await getVaccines();
      setVaccines(res.data || []);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load vaccines');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenModal = (vaccine = null) => {
    if (vaccine) {
      setEditingVaccine(vaccine);
      setFormData({
        name: vaccine.name,
        days_from_birth: vaccine.days_from_birth,
        description: vaccine.description || ''
      });
    } else {
      setEditingVaccine(null);
      setFormData({ name: '', days_from_birth: '', description: '' });
    }
    setError('');
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingVaccine(null);
    setFormData({ name: '', days_from_birth: '', description: '' });
  };

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.name || !formData.days_from_birth) {
      setError('Vaccine name and days from birth are required');
      return;
    }
    setLoading(true);
    try {
      if (editingVaccine) {
        await updateVaccine(editingVaccine.id, formData.name, formData.days_from_birth, formData.description);
        setSuccess('Vaccine updated successfully');
      } else {
        await addVaccine(formData.name, formData.days_from_birth, formData.description);
        setSuccess('Vaccine added successfully');
      }
      handleCloseModal();
      loadVaccines();
      setTimeout(() => setSuccess(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || 'Operation failed');
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async (id, name) => {
    if (!window.confirm(`Are you sure you want to delete vaccine "${name}"? This action cannot be undone.`)) return;
    setLoading(true);
    try {
      await deleteVaccine(id);
      setSuccess('Vaccine deleted successfully');
      loadVaccines();
      setTimeout(() => setSuccess(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || 'Delete failed');
    } finally {
      setLoading(false);
    }
  };

  const handleToggleActive = async (id, currentActive) => {
    setLoading(true);
    try {
      await toggleVaccineActive(id, !currentActive);
      setSuccess(`Vaccine ${currentActive ? 'deactivated' : 'activated'} successfully`);
      loadVaccines();
      setTimeout(() => setSuccess(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || 'Toggle failed');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h4>Vaccine Management (EPI Schedule)</h4>
        <button className="btn btn-primary" onClick={() => handleOpenModal()}>
          + Add New Vaccine
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      {loading && <div className="text-center"><div className="spinner-border text-primary"></div></div>}

      <div className="table-responsive">
        <table className="table table-bordered table-hover">
          <thead className="table-light">
            <tr>
              <th>ID</th>
              <th>Vaccine Name</th>
              <th>Days from Birth</th>
              <th>Description</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {vaccines.map(vaccine => (
              <tr key={vaccine.id}>
                <td>{vaccine.id}</td>
                <td>{vaccine.name}</td>
                <td>{vaccine.days_from_birth}</td>
                <td>{vaccine.description || '-'}</td>
                <td>
                  <span className={`badge ${vaccine.is_active ? 'bg-success' : 'bg-secondary'}`}>
                    {vaccine.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td>
                  <button className="btn btn-sm btn-warning me-1" onClick={() => handleOpenModal(vaccine)}>
                    ✏️ Edit
                  </button>
                  <button className="btn btn-sm btn-danger me-1" onClick={() => handleDelete(vaccine.id, vaccine.name)}>
                    🗑️ Delete
                  </button>
                  <button className="btn btn-sm btn-secondary" onClick={() => handleToggleActive(vaccine.id, vaccine.is_active)}>
                    {vaccine.is_active ? 'Deactivate' : 'Activate'}
                  </button>
                </td>
              </tr>
            ))}
            {vaccines.length === 0 && !loading && (
              <tr><td colSpan="6" className="text-center">No vaccines found.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {/* Modal for Add/Edit */}
      {showModal && (
        <div className="modal show d-block" tabIndex="-1" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header bg-primary text-white">
                <h5 className="modal-title">{editingVaccine ? 'Edit Vaccine' : 'Add New Vaccine'}</h5>
                <button type="button" className="btn-close btn-close-white" onClick={handleCloseModal}></button>
              </div>
              <form onSubmit={handleSubmit}>
                <div className="modal-body">
                  {error && <div className="alert alert-danger">{error}</div>}
                  <div className="mb-3">
                    <label className="form-label">Vaccine Name *</label>
                    <input type="text" className="form-control" name="name" value={formData.name} onChange={handleInputChange} required />
                  </div>
                  <div className="mb-3">
                    <label className="form-label">Days from Birth *</label>
                    <input type="number" className="form-control" name="days_from_birth" value={formData.days_from_birth} onChange={handleInputChange} required />
                    <small className="text-muted">Example: 0 (at birth), 42 (6 weeks), 274 (9 months)</small>
                  </div>
                  <div className="mb-3">
                    <label className="form-label">Description (optional)</label>
                    <textarea className="form-control" name="description" rows="3" value={formData.description} onChange={handleInputChange}></textarea>
                  </div>
                </div>
                <div className="modal-footer">
                  <button type="button" className="btn btn-secondary" onClick={handleCloseModal}>Cancel</button>
                  <button type="submit" className="btn btn-primary" disabled={loading}>
                    {loading ? 'Saving...' : 'Save Vaccine'}
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

export default VaccineManager;