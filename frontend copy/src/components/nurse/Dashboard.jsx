import React, { useState, useEffect } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import {
  getMyAssignedChildren,
  getUpcomingAppointments,
  recordVaccine,
  approveReschedule,
  searchChildren,
  getPendingParents,
  approveParent
} from '../../services/nurseService';
import VerifyParents from './VerifyParents';
import WalkinRegistration from './WalkinRegistration';
import ChildSearch from './ChildSearch';
import VaccineAdministration from './VaccineAdministration';
import AppointmentManager from './AppointmentManager';
import Reports from './Reports';

const NurseDashboard = () => {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState('assigned');
  const [assignedChildren, setAssignedChildren] = useState([]);
  const [upcomingAppointments, setUpcomingAppointments] = useState([]);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });

  useEffect(() => {
    if (activeTab === 'assigned') loadAssignedChildren();
    if (activeTab === 'upcoming') loadUpcomingAppointments();
  }, [activeTab]);

  const loadAssignedChildren = async () => {
    setLoading(true);
    try {
      const res = await getMyAssignedChildren();
      setAssignedChildren(res.data || []);
    } catch (err) {
      showMessage('error', err.response?.data?.message || 'Failed to load assigned children');
    } finally {
      setLoading(false);
    }
  };

  const loadUpcomingAppointments = async () => {
    setLoading(true);
    try {
      const res = await getUpcomingAppointments();
      setUpcomingAppointments(res.data || []);
    } catch (err) {
      showMessage('error', err.response?.data?.message || 'Failed to load upcoming appointments');
    } finally {
      setLoading(false);
    }
  };

  const showMessage = (type, text) => {
    setMessage({ type, text });
    setTimeout(() => setMessage({ type: '', text: '' }), 5000);
  };

  const handleVaccineGiven = async (appointmentId, batchNumber, notes) => {
    try {
      await recordVaccine(appointmentId, batchNumber, notes);
      showMessage('success', 'Vaccine administered successfully');
      loadAssignedChildren();
      loadUpcomingAppointments();
    } catch (err) {
      showMessage('error', err.response?.data?.message || 'Failed to record vaccine');
    }
  };

  const handleRescheduleApproval = async (appointmentId, approved) => {
    try {
      await approveReschedule(appointmentId, approved);
      showMessage('success', `Reschedule request ${approved ? 'approved' : 'rejected'}`);
      loadAssignedChildren();
      loadUpcomingAppointments();
    } catch (err) {
      showMessage('error', err.response?.data?.message || 'Failed to process reschedule');
    }
  };

  const tabButtons = [
    { id: 'assigned', label: 'My Assigned Children', icon: '👶' },
    { id: 'upcoming', label: 'Upcoming Appointments', icon: '📅' },
    { id: 'verify', label: 'Verify Parents', icon: '✅' },
    { id: 'walkin', label: 'Walk-in Registration', icon: '🚶' },
    { id: 'search', label: 'Search Child', icon: '🔍' },
    { id: 'reports', label: 'Reports', icon: '📊' }
  ];

  return (
    <div className="container-fluid mt-4">
      <div className="d-flex justify-content-between align-items-center mb-4">
        <h2>Nurse Dashboard</h2>
        <p className="text-muted mb-0">Welcome, {user?.name}</p>
      </div>

      {message.text && (
        <div className={`alert alert-${message.type === 'error' ? 'danger' : 'success'} alert-dismissible fade show`} role="alert">
          {message.text}
          <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setMessage({ type: '', text: '' })}></button>
        </div>
      )}

      <ul className="nav nav-tabs mb-4">
        {tabButtons.map(tab => (
          <li className="nav-item" key={tab.id}>
            <button
              className={`nav-link ${activeTab === tab.id ? 'active' : ''}`}
              onClick={() => setActiveTab(tab.id)}
            >
              {tab.icon} {tab.label}
            </button>
          </li>
        ))}
      </ul>

      {/* Assigned Children Tab */}
      {activeTab === 'assigned' && (
        <div>
          {loading ? (
            <div className="text-center py-5">
              <div className="spinner-border text-primary" role="status"></div>
            </div>
          ) : assignedChildren.length === 0 ? (
            <div className="alert alert-info">No children assigned to you yet.</div>
          ) : (
            assignedChildren.map(child => (
              <div key={child.id} className="card mb-4 shadow-sm">
                <div className="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                  <h5 className="mb-0">{child.name} (ID: {child.unique_child_id})</h5>
                  <span className="badge bg-light text-dark">Progress: {child.vaccine_progress || 0}%</span>
                </div>
                <div className="card-body">
                  <div className="row mb-3">
                    <div className="col-md-6">
                      <strong>Parent:</strong> {child.parent_name}<br />
                      <strong>Phone:</strong> {child.parent_phone}<br />
                      <strong>DOB:</strong> {child.dob}<br />
                      <strong>Age:</strong> {calculateAge(child.dob)}
                    </div>
                    <div className="col-md-6">
                      <strong>Allergies:</strong>{' '}
                      <span className={child.allergies && child.allergies !== 'None' ? 'text-danger fw-bold' : ''}>
                        {child.allergies || 'None'}
                      </span><br />
                      <strong>Blood Type:</strong> {child.blood_type || 'N/A'}<br />
                      <strong>Next Appointment:</strong> {child.next_appointment_date || 'None scheduled'}
                    </div>
                  </div>
                  <AppointmentManager
                    childId={child.id}
                    onVaccineGiven={handleVaccineGiven}
                    onRescheduleApproval={handleRescheduleApproval}
                  />
                </div>
              </div>
            ))
          )}
        </div>
      )}

      {/* Upcoming Appointments Tab */}
      {activeTab === 'upcoming' && (
        <div>
          <h4 className="mb-3">Appointments in Next 7 Days</h4>
          {loading ? (
            <div className="text-center py-5"><div className="spinner-border text-primary"></div></div>
          ) : upcomingAppointments.length === 0 ? (
            <div className="alert alert-info">No upcoming appointments in the next 7 days.</div>
          ) : (
            <div className="table-responsive">
              <table className="table table-bordered table-hover">
                <thead className="table-light">
                  <tr><th>Child</th><th>Vaccine</th><th>Scheduled Date</th><th>Parent Phone</th><th>Action</th></tr>
                </thead>
                <tbody>
                  {upcomingAppointments.map(app => (
                    <tr key={app.id}>
                      <td>{app.child_name}<br /><small>ID: {app.unique_child_id}</small></td>
                      <td>{app.vaccine_name}</td>
                      <td>{app.scheduled_date}</td>
                      <td>{app.parent_phone}</td>
                      <td>
                        <VaccineAdministration
                          appointment={app}
                          onVaccineGiven={handleVaccineGiven}
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Verify Parents Tab */}
      {activeTab === 'verify' && <VerifyParents onVerified={loadAssignedChildren} />}

      {/* Walk-in Registration Tab */}
      {activeTab === 'walkin' && <WalkinRegistration onSuccess={loadAssignedChildren} />}

      {/* Search Child Tab */}
      {activeTab === 'search' && <ChildSearch />}

      {/* Reports Tab */}
      {activeTab === 'reports' && <Reports />}
    </div>
  );
};

// Helper function to calculate age from DOB
const calculateAge = (dob) => {
  if (!dob) return 'N/A';
  const birthDate = new Date(dob);
  const today = new Date();
  let years = today.getFullYear() - birthDate.getFullYear();
  let months = today.getMonth() - birthDate.getMonth();
  let days = today.getDate() - birthDate.getDate();
  if (days < 0) { months--; days += new Date(today.getFullYear(), today.getMonth(), 0).getDate(); }
  if (months < 0) { years--; months += 12; }
  if (years > 0) return `${years} yr ${months} mo`;
  if (months > 0) return `${months} mo ${days} d`;
  return `${days} d`;
};

export default NurseDashboard;