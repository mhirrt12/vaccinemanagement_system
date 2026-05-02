import React, { useState, useEffect } from 'react';
import { getChildAppointments, updateAppointmentStatus, approveReschedule } from '../../services/nurseService';

/**
 * AppointmentManager Component - Manages appointments for a specific child (nurse view)
 * 
 * @param {Object} props
 * @param {number} props.childId - ID of the child
 * @param {Function} props.onVaccineGiven - Callback when vaccine is administered
 * @param {Function} props.onRescheduleApproval - Callback when reschedule is approved/rejected
 * @returns {JSX.Element}
 */
const AppointmentManager = ({ childId, onVaccineGiven, onRescheduleApproval }) => {
  const [appointments, setAppointments] = useState([]);
  const [loading, setLoading] = useState(false);
  const [actionInProgress, setActionInProgress] = useState(null);
  const [message, setMessage] = useState({ type: '', text: '' });
  const [showBatchModal, setShowBatchModal] = useState(false);
  const [selectedAppointment, setSelectedAppointment] = useState(null);
  const [batchNumber, setBatchNumber] = useState('');
  const [notes, setNotes] = useState('');
  const [availableBatches, setAvailableBatches] = useState([]);

  useEffect(() => {
    if (childId) {
      loadAppointments();
    }
  }, [childId]);

  const loadAppointments = async () => {
    setLoading(true);
    try {
      const res = await getChildAppointments(childId);
      setAppointments(res.data || []);
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Failed to load appointments' });
    } finally {
      setLoading(false);
    }
  };

  const loadAvailableBatches = async (vaccineId) => {
    try {
      // Assuming we have a service to get batches
      const res = await getAvailableBatches(vaccineId);
      setAvailableBatches(res.data || []);
      if (res.data && res.data.length > 0) {
        setBatchNumber(res.data[0].batch_number);
      } else {
        setMessage({ type: 'error', text: 'No available batches for this vaccine' });
      }
    } catch (err) {
      setMessage({ type: 'error', text: 'Failed to load batches' });
    }
  };

  const openVaccineModal = (appointment) => {
    setSelectedAppointment(appointment);
    loadAvailableBatches(appointment.vaccine_id);
    setShowBatchModal(true);
    setNotes('');
  };

  const handleVaccineSubmit = async () => {
    if (!batchNumber) {
      setMessage({ type: 'error', text: 'Please select a batch number' });
      return;
    }
    setActionInProgress(selectedAppointment.id);
    try {
      await updateAppointmentStatus(selectedAppointment.id, 'completed', batchNumber, notes);
      setMessage({ type: 'success', text: 'Vaccine administered successfully' });
      setShowBatchModal(false);
      loadAppointments();
      if (onVaccineGiven) onVaccineGiven(selectedAppointment.id, batchNumber, notes);
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Failed to record vaccine' });
    } finally {
      setActionInProgress(null);
    }
  };

  const handleRescheduleAction = async (appointmentId, approved) => {
    setActionInProgress(appointmentId);
    try {
      await approveReschedule(appointmentId, approved);
      setMessage({ type: 'success', text: `Reschedule ${approved ? 'approved' : 'rejected'}` });
      loadAppointments();
      if (onRescheduleApproval) onRescheduleApproval(appointmentId, approved);
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Action failed' });
    } finally {
      setActionInProgress(null);
    }
  };

  const getStatusBadge = (status) => {
    switch (status) {
      case 'completed': return <span className="badge bg-success">Completed</span>;
      case 'pending': return <span className="badge bg-primary">Pending</span>;
      case 'missed': return <span className="badge bg-danger">Missed</span>;
      case 'rescheduled': return <span className="badge bg-warning text-dark">Reschedule Requested</span>;
      case 'cancelled': return <span className="badge bg-secondary">Cancelled</span>;
      default: return <span className="badge bg-secondary">{status}</span>;
    }
  };

  if (loading) return <div className="text-center p-3"><div className="spinner-border spinner-border-sm"></div> Loading appointments...</div>;

  if (appointments.length === 0) {
    return <div className="alert alert-info">No appointments found for this child.</div>;
  }

  return (
    <div>
      {message.text && (
        <div className={`alert alert-${message.type === 'error' ? 'danger' : 'success'} alert-dismissible fade show`} role="alert">
          {message.text}
          <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setMessage({ type: '', text: '' })}></button>
        </div>
      )}

      <div className="table-responsive">
        <table className="table table-sm table-bordered">
          <thead className="table-light">
            <tr>
              <th>Vaccine</th>
              <th>Scheduled Date</th>
              <th>Status</th>
              <th>Given Date</th>
              <th>Batch</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            {appointments.map(app => (
              <tr key={app.id}>
                <td>{app.vaccine_name}</td>
                <td>{app.scheduled_date}</td>
                <td>{getStatusBadge(app.status)}</td>
                <td>{app.given_date || '-'}</td>
                <td>{app.batch_number || '-'}</td>
                <td>
                  {app.status === 'pending' && (
                    <button
                      className="btn btn-sm btn-success me-1"
                      onClick={() => openVaccineModal(app)}
                      disabled={actionInProgress === app.id}
                    >
                      💉 Give
                    </button>
                  )}
                  {app.status === 'rescheduled' && app.reschedule_request_date && (
                    <div className="btn-group btn-group-sm">
                      <button
                        className="btn btn-sm btn-success"
                        onClick={() => handleRescheduleAction(app.id, true)}
                        disabled={actionInProgress === app.id}
                      >
                        Approve
                      </button>
                      <button
                        className="btn btn-sm btn-danger"
                        onClick={() => handleRescheduleAction(app.id, false)}
                        disabled={actionInProgress === app.id}
                      >
                        Reject
                      </button>
                    </div>
                  )}
                  {actionInProgress === app.id && <span className="spinner-border spinner-border-sm"></span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Batch Selection Modal */}
      {showBatchModal && selectedAppointment && (
        <div className="modal show d-block" tabIndex="-1" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header bg-success text-white">
                <h5 className="modal-title">Administer Vaccine</h5>
                <button type="button" className="btn-close btn-close-white" onClick={() => setShowBatchModal(false)}></button>
              </div>
              <div className="modal-body">
                <p><strong>Vaccine:</strong> {selectedAppointment.vaccine_name}</p>
                <p><strong>Scheduled Date:</strong> {selectedAppointment.scheduled_date}</p>
                <div className="mb-3">
                  <label className="form-label">Batch Number *</label>
                  <select
                    className="form-select"
                    value={batchNumber}
                    onChange={(e) => setBatchNumber(e.target.value)}
                    required
                  >
                    <option value="">Select batch</option>
                    {availableBatches.map(batch => (
                      <option key={batch.batch_number} value={batch.batch_number}>
                        {batch.batch_number} (Exp: {batch.expiry_date}, Qty: {batch.quantity})
                      </option>
                    ))}
                  </select>
                </div>
                <div className="mb-3">
                  <label className="form-label">Clinical Notes (optional)</label>
                  <textarea
                    className="form-control"
                    rows="2"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="e.g., Reaction, site of injection"
                  ></textarea>
                </div>
              </div>
              <div className="modal-footer">
                <button className="btn btn-secondary" onClick={() => setShowBatchModal(false)}>Cancel</button>
                <button className="btn btn-success" onClick={handleVaccineSubmit} disabled={actionInProgress !== null}>
                  Confirm Administered
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default AppointmentManager;