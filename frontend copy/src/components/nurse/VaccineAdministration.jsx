import React, { useState, useEffect } from 'react';
import { recordVaccine, getAvailableBatches } from '../../services/nurseService';

/**
 * VaccineAdministration Component - Form to record vaccine administration
 * 
 * @param {Object} props
 * @param {Object} props.appointment - Appointment object with id, child_id, vaccine_id, scheduled_date, vaccine_name
 * @param {Function} props.onVaccineGiven - Callback after successful administration
 * @param {boolean} props.compact - Whether to show a compact button version
 * @returns {JSX.Element}
 */
const VaccineAdministration = ({ appointment, onVaccineGiven, compact = false }) => {
  const [showModal, setShowModal] = useState(false);
  const [batchNumber, setBatchNumber] = useState('');
  const [notes, setNotes] = useState('');
  const [availableBatches, setAvailableBatches] = useState([]);
  const [loading, setLoading] = useState(false);
  const [fetchingBatches, setFetchingBatches] = useState(false);
  const [error, setError] = useState('');

  // Fetch available batches when modal opens and vaccine_id is available
  useEffect(() => {
    if (showModal && appointment?.vaccine_id) {
      loadAvailableBatches();
    }
  }, [showModal, appointment?.vaccine_id]);

  const loadAvailableBatches = async () => {
    setFetchingBatches(true);
    setError('');
    try {
      const res = await getAvailableBatches(appointment.vaccine_id);
      setAvailableBatches(res.data || []);
      if (res.data && res.data.length > 0) {
        setBatchNumber(res.data[0].batch_number);
      } else {
        setError('No batches available for this vaccine. Please add stock.');
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load batches');
    } finally {
      setFetchingBatches(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!batchNumber) {
      setError('Please select a batch number');
      return;
    }
    setLoading(true);
    setError('');
    try {
      await recordVaccine(appointment.id, batchNumber, notes);
      setShowModal(false);
      // Reset form
      setBatchNumber('');
      setNotes('');
      if (onVaccineGiven) onVaccineGiven(appointment.id, batchNumber, notes);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to record vaccine');
    } finally {
      setLoading(false);
    }
  };

  const openModal = () => setShowModal(true);
  const closeModal = () => {
    setShowModal(false);
    setError('');
    setBatchNumber('');
    setNotes('');
  };

  if (compact) {
    return (
      <>
        <button className="btn btn-sm btn-success" onClick={openModal}>
          💉 Give Vaccine
        </button>
        {showModal && (
          <div className="modal show d-block" tabIndex="-1" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
            <div className="modal-dialog">
              <div className="modal-content">
                <div className="modal-header bg-success text-white">
                  <h5 className="modal-title">Administer Vaccine</h5>
                  <button type="button" className="btn-close btn-close-white" onClick={closeModal}></button>
                </div>
                <div className="modal-body">
                  <p><strong>Child:</strong> {appointment?.child_name}</p>
                  <p><strong>Vaccine:</strong> {appointment?.vaccine_name}</p>
                  <p><strong>Scheduled Date:</strong> {appointment?.scheduled_date}</p>
                  <form onSubmit={handleSubmit}>
                    <div className="mb-3">
                      <label className="form-label">Batch Number *</label>
                      {fetchingBatches ? (
                        <div className="spinner-border spinner-border-sm"></div>
                      ) : (
                        <select
                          className="form-select"
                          value={batchNumber}
                          onChange={(e) => setBatchNumber(e.target.value)}
                          required
                          disabled={availableBatches.length === 0}
                        >
                          <option value="">Select batch</option>
                          {availableBatches.map(batch => (
                            <option key={batch.batch_number} value={batch.batch_number}>
                              {batch.batch_number} (Exp: {batch.expiry_date}, Stock: {batch.quantity})
                            </option>
                          ))}
                        </select>
                      )}
                    </div>
                    <div className="mb-3">
                      <label className="form-label">Clinical Notes (optional)</label>
                      <textarea
                        className="form-control"
                        rows="2"
                        placeholder="e.g., Given in left thigh, no adverse reaction"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                      ></textarea>
                    </div>
                    {error && <div className="alert alert-danger">{error}</div>}
                  </form>
                </div>
                <div className="modal-footer">
                  <button className="btn btn-secondary" onClick={closeModal}>Cancel</button>
                  <button className="btn btn-success" onClick={handleSubmit} disabled={loading || fetchingBatches || availableBatches.length === 0}>
                    {loading ? 'Recording...' : 'Confirm Administered'}
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </>
    );
  }

  // Full form version
  return (
    <div className="card mb-3">
      <div className="card-header bg-info text-white">Administer Vaccine</div>
      <div className="card-body">
        <p><strong>Child:</strong> {appointment?.child_name}</p>
        <p><strong>Vaccine:</strong> {appointment?.vaccine_name}</p>
        <p><strong>Scheduled Date:</strong> {appointment?.scheduled_date}</p>
        <form onSubmit={handleSubmit}>
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
                  {batch.batch_number} (Exp: {batch.expiry_date}, Stock: {batch.quantity})
                </option>
              ))}
            </select>
          </div>
          <div className="mb-3">
            <label className="form-label">Clinical Notes (optional)</label>
            <textarea className="form-control" rows="2" value={notes} onChange={(e) => setNotes(e.target.value)}></textarea>
          </div>
          {error && <div className="alert alert-danger">{error}</div>}
          <button type="submit" className="btn btn-success" disabled={loading}>
            {loading ? 'Recording...' : 'Confirm Administered'}
          </button>
        </form>
      </div>
    </div>
  );
};

export default VaccineAdministration;