import React, { useState } from 'react';

/**
 * AppointmentReschedule Component - Handles reschedule request for a single appointment
 * 
 * @param {Object} props
 * @param {Object} props.appointment - Appointment object with id, vaccine_name, due_date
 * @param {boolean} props.show - Whether the modal is visible
 * @param {Function} props.onClose - Callback to close the modal
 * @param {Function} props.onReschedule - Callback to submit reschedule request (appointmentId, newDate)
 * @returns {JSX.Element|null}
 */
const AppointmentReschedule = ({ appointment, show, onClose, onReschedule }) => {
  const [newDate, setNewDate] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  // Reset state when modal opens with new appointment
  React.useEffect(() => {
    if (show && appointment) {
      // Default new date to original due date (or tomorrow if overdue)
      const today = new Date().toISOString().split('T')[0];
      const defaultDate = appointment.due_date >= today ? appointment.due_date : today;
      setNewDate(defaultDate);
      setError('');
      setLoading(false);
    }
  }, [show, appointment]);

  if (!show || !appointment) return null;

  const handleSubmit = async () => {
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

    // Check max 5 days change from original due date
    const originalDate = new Date(appointment.due_date);
    const diffDays = Math.abs(selectedDate - originalDate) / (1000 * 60 * 60 * 24);
    if (diffDays > 5) {
      setError('You can only reschedule within 5 days of the original appointment date');
      return;
    }

    setLoading(true);
    try {
      await onReschedule(appointment.id, newDate);
      onClose();
    } catch (err) {
      setError(err.message || 'Failed to submit reschedule request');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="modal show d-block" tabIndex="-1" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="modal-dialog">
        <div className="modal-content">
          <div className="modal-header bg-warning text-dark">
            <h5 className="modal-title">Request Reschedule</h5>
            <button type="button" className="btn-close" onClick={onClose} disabled={loading}></button>
          </div>
          <div className="modal-body">
            <p>
              <strong>Vaccine:</strong> {appointment.vaccine_name}<br />
              <strong>Current Due Date:</strong> {appointment.due_date}
            </p>
            <p className="text-muted">You may reschedule within 5 days of the original date.</p>
            <div className="mb-3">
              <label htmlFor="reschedule_date" className="form-label">New Date</label>
              <input
                type="date"
                id="reschedule_date"
                className="form-control"
                value={newDate}
                min={new Date().toISOString().split('T')[0]}
                onChange={(e) => setNewDate(e.target.value)}
                disabled={loading}
              />
              {error && <div className="text-danger mt-1">{error}</div>}
            </div>
          </div>
          <div className="modal-footer">
            <button className="btn btn-secondary" onClick={onClose} disabled={loading}>
              Cancel
            </button>
            <button className="btn btn-primary" onClick={handleSubmit} disabled={loading}>
              {loading ? (
                <>
                  <span className="spinner-border spinner-border-sm me-2" role="status"></span>
                  Submitting...
                </>
              ) : (
                'Submit Request'
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AppointmentReschedule;