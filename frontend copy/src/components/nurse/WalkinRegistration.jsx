import React, { useState } from 'react';
import { walkinRegistration } from '../../services/nurseService';

const WalkinRegistration = ({ onSuccess }) => {
  const [step, setStep] = useState(1);
  const [parentPhone, setParentPhone] = useState('');
  const [parentExists, setParentExists] = useState(null);
  const [parentName, setParentName] = useState('');
  const [childData, setChildData] = useState({
    name: '',
    dob: '',
    gender: 'Male',
    blood_type: '',
    allergies: '',
    birth_weight: '',
    delivery_type: 'Normal',
    birth_place: ''
  });
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });

  // EPI vaccine list for historical check (optional, can be added later)
  const vaccineList = [
    'BCG', 'OPV-0', 'OPV-1', 'Pentavalent-1', 'PCV-1', 'Rota-1',
    'OPV-2', 'Pentavalent-2', 'PCV-2', 'Rota-2',
    'OPV-3', 'Pentavalent-3', 'PCV-3', 'IPV', 'MCV1', 'MCV2'
  ];
  const [historicalVaccines, setHistoricalVaccines] = useState([]);

  const handleParentPhoneSubmit = async (e) => {
    e.preventDefault();
    if (!parentPhone.match(/^09[0-9]{8}$/)) {
      setMessage({ type: 'error', text: 'Please enter a valid 10-digit Ethiopian phone number starting with 09' });
      return;
    }
    setLoading(true);
    setMessage({ type: '', text: '' });
    try {
      // Check if parent exists (we'll use a quick API call or just assume flow)
      // For simplicity, we'll ask nurse if parent exists? Better to have an endpoint.
      // We'll use a prompt but since we don't have a check endpoint, we'll let nurse decide.
      // Actually, we have parentExists function in backend, but frontend can call a separate endpoint.
      // For now, we'll ask nurse via confirm dialog.
      const exists = window.confirm(`Does parent with phone ${parentPhone} already exist in the system? Click OK for existing, Cancel for new.`);
      if (exists) {
        setParentExists(true);
        setStep(2);
      } else {
        setParentExists(false);
        setStep(2);
      }
    } catch (err) {
      setMessage({ type: 'error', text: 'Error checking parent' });
    } finally {
      setLoading(false);
    }
  };

  const handleChildInputChange = (e) => {
    const { name, value } = e.target;
    setChildData(prev => ({ ...prev, [name]: value }));
  };

  const handleHistoricalChange = (vaccine) => {
    setHistoricalVaccines(prev =>
      prev.includes(vaccine) ? prev.filter(v => v !== vaccine) : [...prev, vaccine]
    );
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    // Validate child data
    if (!childData.name || !childData.dob || !childData.birth_place) {
      setMessage({ type: 'error', text: 'Child name, date of birth, and birth place are required' });
      return;
    }
    setLoading(true);
    const payload = {
      parent_phone: parentPhone,
      parent_name: parentExists ? undefined : parentName,
      child: {
        ...childData,
        birth_weight: childData.birth_weight ? parseFloat(childData.birth_weight) : null,
        historical_vaccines: historicalVaccines
      }
    };
    try {
      await walkinRegistration(payload);
      setMessage({ type: 'success', text: 'Child registered successfully and assigned to you!' });
      // Reset form
      setParentPhone('');
      setParentName('');
      setChildData({ name: '', dob: '', gender: 'Male', blood_type: '', allergies: '', birth_weight: '', delivery_type: 'Normal', birth_place: '' });
      setHistoricalVaccines([]);
      setStep(1);
      setParentExists(null);
      if (onSuccess) onSuccess();
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Registration failed' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card">
      <div className="card-header bg-primary text-white">
        <h5 className="mb-0">Walk-in Child Registration</h5>
      </div>
      <div className="card-body">
        {message.text && (
          <div className={`alert alert-${message.type === 'error' ? 'danger' : 'success'} alert-dismissible`}>
            {message.text}
            <button type="button" className="btn-close" onClick={() => setMessage({ type: '', text: '' })}></button>
          </div>
        )}

        {step === 1 && (
          <form onSubmit={handleParentPhoneSubmit}>
            <div className="mb-3">
              <label className="form-label">Parent Phone Number (Ethiopian)</label>
              <input
                type="tel"
                className="form-control"
                placeholder="09xxxxxxxx"
                value={parentPhone}
                onChange={(e) => setParentPhone(e.target.value)}
                required
              />
              <small className="text-muted">Example: 0912345678</small>
            </div>
            <button type="submit" className="btn btn-primary" disabled={loading}>
              {loading ? 'Checking...' : 'Continue'}
            </button>
          </form>
        )}

        {step === 2 && (
          <form onSubmit={handleSubmit}>
            {!parentExists && (
              <div className="mb-3">
                <label className="form-label">Parent Full Name (New Parent)</label>
                <input
                  type="text"
                  className="form-control"
                  value={parentName}
                  onChange={(e) => setParentName(e.target.value)}
                  required
                />
              </div>
            )}
            {parentExists && (
              <div className="alert alert-info">Linking child to existing parent with phone {parentPhone}</div>
            )}

            <h6 className="mt-3">Child Information</h6>
            <div className="row">
              <div className="col-md-6 mb-2">
                <label className="form-label">Child Name *</label>
                <input type="text" name="name" className="form-control" value={childData.name} onChange={handleChildInputChange} required />
              </div>
              <div className="col-md-6 mb-2">
                <label className="form-label">Date of Birth *</label>
                <input type="date" name="dob" className="form-control" value={childData.dob} onChange={handleChildInputChange} required />
              </div>
            </div>
            <div className="row">
              <div className="col-md-4 mb-2">
                <label className="form-label">Gender</label>
                <select name="gender" className="form-select" value={childData.gender} onChange={handleChildInputChange}>
                  <option>Male</option><option>Female</option><option>Other</option>
                </select>
              </div>
              <div className="col-md-4 mb-2">
                <label className="form-label">Blood Type</label>
                <select name="blood_type" className="form-select" value={childData.blood_type} onChange={handleChildInputChange}>
                  <option value="">Select</option>
                  <option>A+</option><option>A-</option><option>B+</option><option>B-</option>
                  <option>AB+</option><option>AB-</option><option>O+</option><option>O-</option>
                </select>
              </div>
              <div className="col-md-4 mb-2">
                <label className="form-label">Birth Weight (kg)</label>
                <input type="number" step="0.01" name="birth_weight" className="form-control" value={childData.birth_weight} onChange={handleChildInputChange} />
              </div>
            </div>
            <div className="row">
              <div className="col-md-6 mb-2">
                <label className="form-label">Delivery Type</label>
                <select name="delivery_type" className="form-select" value={childData.delivery_type} onChange={handleChildInputChange}>
                  <option>Normal</option><option>C-Section</option>
                </select>
              </div>
              <div className="col-md-6 mb-2">
                <label className="form-label">Birth Place *</label>
                <input type="text" name="birth_place" className="form-control" placeholder="Hospital name or 'Home'" value={childData.birth_place} onChange={handleChildInputChange} required />
              </div>
            </div>
            <div className="mb-2">
              <label className="form-label">Allergies (if any)</label>
              <textarea name="allergies" className="form-control" rows="2" placeholder="e.g., Penicillin, Eggs, None" value={childData.allergies} onChange={handleChildInputChange}></textarea>
            </div>

            <div className="mb-3">
              <label className="form-label">Historical Vaccines (already taken at other hospitals)</label>
              <div className="row">
                {vaccineList.map(v => (
                  <div className="col-md-3 col-sm-4" key={v}>
                    <div className="form-check">
                      <input
                        className="form-check-input"
                        type="checkbox"
                        value={v}
                        id={`hist_${v}`}
                        checked={historicalVaccines.includes(v)}
                        onChange={() => handleHistoricalChange(v)}
                      />
                      <label className="form-check-label" htmlFor={`hist_${v}`}>{v}</label>
                    </div>
                  </div>
                ))}
              </div>
              <small className="text-muted">Select only vaccines already given elsewhere</small>
            </div>

            <div className="d-flex justify-content-between">
              <button type="button" className="btn btn-secondary" onClick={() => { setStep(1); setParentExists(null); }}>
                Back
              </button>
              <button type="submit" className="btn btn-success" disabled={loading}>
                {loading ? 'Registering...' : 'Register Child'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

export default WalkinRegistration;