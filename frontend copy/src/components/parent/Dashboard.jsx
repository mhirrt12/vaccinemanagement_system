import React, { useState, useEffect } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { getMyChildren, getChildSchedule, requestReschedule, downloadCertificate } from '../../services/parentService';
import VaccineTable from './VaccineTable';
import ChildProfile from './ChildProfile';

const ParentDashboard = () => {
  const { user } = useAuth();
  const [children, setChildren] = useState([]);
  const [selectedChild, setSelectedChild] = useState(null);
  const [schedule, setSchedule] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [successMessage, setSuccessMessage] = useState('');

  useEffect(() => {
    loadChildren();
  }, []);

  useEffect(() => {
    if (selectedChild) {
      loadSchedule(selectedChild.id);
    }
  }, [selectedChild]);

  const loadChildren = async () => {
    setLoading(true);
    setError('');
    try {
      const res = await getMyChildren();
      setChildren(res.data);
      if (res.data && res.data.length > 0) {
        setSelectedChild(res.data[0]);
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load children');
    } finally {
      setLoading(false);
    }
  };

  const loadSchedule = async (childId) => {
    setLoading(true);
    try {
      const res = await getChildSchedule(childId);
      setSchedule(res.data.schedule || []);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load vaccination schedule');
    } finally {
      setLoading(false);
    }
  };

  const handleReschedule = async (appointmentId, newDate) => {
    setError('');
    setSuccessMessage('');
    try {
      await requestReschedule(appointmentId, newDate);
      setSuccessMessage('Reschedule request submitted. Nurse will review and approve.');
      // Refresh schedule after reschedule request
      await loadSchedule(selectedChild.id);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to request reschedule');
    }
  };

  const handleDownloadCertificate = async () => {
    if (!selectedChild) return;
    setError('');
    try {
      const res = await downloadCertificate(selectedChild.id);
      // Create blob download link
      const blob = new Blob([res.data], { type: 'application/pdf' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `certificate_${selectedChild.unique_child_id}.pdf`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      setSuccessMessage('Certificate downloaded successfully.');
    } catch (err) {
      setError(err.response?.data?.message || 'Certificate not available yet. Please wait for nurse and admin approval.');
    }
  };

  if (loading && children.length === 0) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: '60vh' }}>
        <div className="spinner-border text-primary" role="status">
          <span className="visually-hidden">Loading dashboard...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="container mt-4">
      <div className="d-flex justify-content-between align-items-center mb-4">
        <h2>Parent Dashboard</h2>
        <p className="text-muted mb-0">Welcome, {user?.name}</p>
      </div>

      {error && (
        <div className="alert alert-danger alert-dismissible fade show" role="alert">
          {error}
          <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setError('')}></button>
        </div>
      )}
      {successMessage && (
        <div className="alert alert-success alert-dismissible fade show" role="alert">
          {successMessage}
          <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setSuccessMessage('')}></button>
        </div>
      )}

      {children.length === 0 ? (
        <div className="card text-center">
          <div className="card-body py-5">
            <h4>No children registered yet</h4>
            <p>Click the button below to register your first child.</p>
            <a href="/parent/add-child" className="btn btn-primary">Register a Child</a>
          </div>
        </div>
      ) : (
        <div className="row">
          {/* Sidebar - Child List */}
          <div className="col-md-4 mb-4">
            <div className="card shadow-sm">
              <div className="card-header bg-primary text-white">
                <h5 className="mb-0">My Children</h5>
              </div>
              <div className="list-group list-group-flush">
                {children.map(child => (
                  <button
                    key={child.id}
                    className={`list-group-item list-group-item-action d-flex justify-content-between align-items-center ${selectedChild?.id === child.id ? 'active' : ''}`}
                    onClick={() => setSelectedChild(child)}
                  >
                    <div>
                      <strong>{child.name}</strong>
                      <br />
                      <small>ID: {child.unique_child_id}</small>
                    </div>
                    <span className="badge bg-secondary rounded-pill">{child.vaccine_progress || 0}%</span>
                  </button>
                ))}
              </div>
              <div className="card-footer">
                <a href="/parent/add-child" className="btn btn-sm btn-success w-100">
                  + Register New Child
                </a>
              </div>
            </div>
          </div>

          {/* Main Content - Child Details & Schedule */}
          <div className="col-md-8">
            {selectedChild && (
              <>
                <ChildProfile child={selectedChild} />

                {/* Assigned Nurse Info */}
                {selectedChild.assigned_nurse && (
                  <div className="alert alert-info d-flex justify-content-between align-items-center mt-3">
                    <div>
                      <strong>👩‍⚕️ Assigned Nurse:</strong> {selectedChild.assigned_nurse.name}
                      <br />
                      <small>📞 {selectedChild.assigned_nurse.phone} | ✉️ {selectedChild.assigned_nurse.email}</small>
                    </div>
                  </div>
                )}

                {/* Vaccination Schedule Table */}
                <div className="card shadow-sm mt-4">
                  <div className="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 className="mb-0">Vaccination Roadmap</h5>
                    <button className="btn btn-sm btn-light" onClick={handleDownloadCertificate}>
                      📄 Download Certificate
                    </button>
                  </div>
                  <div className="card-body">
                    <VaccineTable schedule={schedule} onReschedule={handleReschedule} />
                  </div>
                </div>

                {/* Next Appointment Highlight */}
                {schedule.filter(s => s.status === 'Scheduled' && new Date(s.due_date) >= new Date()).length > 0 && (
                  <div className="alert alert-warning mt-3">
                    <strong>Next Appointment:</strong>{' '}
                    {schedule.find(s => s.status === 'Scheduled' && new Date(s.due_date) >= new Date())?.due_date}
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default ParentDashboard;