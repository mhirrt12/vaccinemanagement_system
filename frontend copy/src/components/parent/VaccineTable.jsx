import React, { useState } from 'react';

/**
 * VaccineTable Component - Displays the vaccination schedule for a child
 * 
 * @param {Object} props
 * @param {Array} props.schedule - Array of vaccine objects with fields: vaccine_name, due_date, status, can_reschedule, id
 * @param {Function} props.onReschedule - Callback function to request reschedule (appointmentId, newDate)
 * @returns {JSX.Element}
 */
const VaccineTable = ({ schedule = [], onReschedule }) => {
  const [showRescheduleModal, setShowRescheduleModal] = useState(false);
  const [selectedAppointment, setSelectedAppointment] = useState(null);
  const [newDate, setNewDate] = useState('');
  const [error, setError] = useState('');

  if (!schedule.length) {
    return (
      <div className="alert alert-info text-center">
        No vaccination schedule available. Please add a child or contact your nurse.
      </div>
    );
  }

  const openRescheduleModal = (appointment) => {
    setSelectedAppointment(appointment);
    // Default new date to the original due date (or tomorrow if already passed)
    const defaultDate = appointment.due_date >= new Date().toISOString().split('T')[0]
      ? appointment.due_date
      : new Date().toISOString().split('T')[0];
    setNewDate(defaultDate);
    setError('');
    setShowRescheduleModal(true);
  };

  const handleRescheduleSubmit = () => {
    if (!newDate) {
      setError('Please select a new date');
      return;
    }
    const selectedDate = new Date(newDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (selectedDate < today) {
      setError('New date cannot be in the past');
      return;
    }
    // Check max 5 days from original due date
    const originalDate = new Date(selectedAppointment.due_date);
    const diffDays = Math.abs(selectedDate - originalDate) / (1000 * 60 * 60 * 24);
    if (diffDays > 5) {
      setError('You can only reschedule within 5 days of the original appointment date');
      return;
    }
    onReschedule(selectedAppointment.id, newDate);
    setShowRescheduleModal(false);
    setSelectedAppointment(null);
    setNewDate('');
    setError('');
  };

  const getStatusBadgeClass = (status) => {
    switch (status) {
      case 'Given on':
        return 'bg-success';
      case 'Scheduled':
        return 'bg-primary';
      case 'Overdue':
        return 'bg-danger';
      case 'Pending':
        return 'bg-warning';
      default:
        return 'bg-secondary';
    }
  };

  const getStatusText = (status) => {
    if (status.startsWith('Given on')) return 'Completed';
    return status;
  };

  return (
    <>
      <div className="table-responsive">
        <table className="table table-bordered table-hover">
          <thead className="table-light">
            <tr>
              <th>Vaccine</th>
              <th>Due Date</th>
              <th>Status</th>
              <th>Batch No.</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            {schedule.map((item) => (
              <tr key={item.id || item.vaccine_name}>
                <td>{item.vaccine_name}</td>
                <td>{item.due_date}</td>
                <td>
                  <span className={`badge ${getStatusBadgeClass(item.status)}`}>
                    {getStatusText(item.status)}
                  </span>
                </td>
                <td>{item.batch !== '-' ? item.batch : '—'}</td>
                <td>
                  {item.status === 'Scheduled' && item.can_reschedule !== false && (
                    <button
                      className="btn btn-sm btn-outline-warning"
                      onClick={() => openRescheduleModal(item)}
                      title="Request to reschedule (max 5 days change)"
                    >
                      🔄 Reschedule
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Reschedule Modal */}
      {showRescheduleModal && selectedAppointment && (
        <div className="modal show d-block" tabIndex="-1" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="modal-dialog">
            <div className="modal-content">
              <div className="modal-header bg-warning">
                <h5 className="modal-title">Request Reschedule</h5>
                <button type="button" className="btn-close" onClick={() => setShowRescheduleModal(false)}></button>
              </div>
              <div className="modal-body">
                <p>
                  <strong>Vaccine:</strong> {selectedAppointment.vaccine_name}<br />
                  <strong>Original Date:</strong> {selectedAppointment.due_date}
                </p>
                <p className="text-muted">You can reschedule within 5 days of the original date.</p>
                <div className="mb-3">
                  <label htmlFor="newDate" className="form-label">Select New Date</label>
                  <input
                    type="date"
                    className="form-control"
                    id="newDate"
                    value={newDate}
                    min={new Date().toISOString().split('T')[0]}
                    onChange={(e) => setNewDate(e.target.value)}
                  />
                  {error && <div className="text-danger mt-1">{error}</div>}
                </div>
              </div>
              <div className="modal-footer">
                <button className="btn btn-secondary" onClick={() => setShowRescheduleModal(false)}>Cancel</button>
                <button className="btn btn-primary" onClick={handleRescheduleSubmit}>Submit Request</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default VaccineTable;