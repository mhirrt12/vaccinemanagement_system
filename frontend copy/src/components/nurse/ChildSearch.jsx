import React, { useState } from 'react';
import { searchChildren } from '../../services/nurseService';
import VaccineAdministration from './VaccineAdministration';

const ChildSearch = () => {
  const [keyword, setKeyword] = useState('');
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [selectedChild, setSelectedChild] = useState(null);
  const [showVaccineModal, setShowVaccineModal] = useState(false);
  const [selectedAppointment, setSelectedAppointment] = useState(null);

  const handleSearch = async (e) => {
    e.preventDefault();
    if (!keyword.trim()) {
      setError('Please enter a search term (name, phone, or child ID)');
      return;
    }
    setLoading(true);
    setError('');
    setResults([]);
    try {
      const res = await searchChildren(keyword);
      setResults(res.data || []);
      if (res.data.length === 0) {
        setError('No children found matching your search.');
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Search failed');
    } finally {
      setLoading(false);
    }
  };

  const handleVaccinate = (child, appointment) => {
    setSelectedChild(child);
    setSelectedAppointment(appointment);
    setShowVaccineModal(true);
  };

  const onVaccineGiven = () => {
    setShowVaccineModal(false);
    // Refresh search results
    handleSearch(new Event('submit'));
  };

  return (
    <div>
      <div className="card shadow-sm">
        <div className="card-header bg-primary text-white">
          <h5 className="mb-0">🔍 Search Children</h5>
        </div>
        <div className="card-body">
          <form onSubmit={handleSearch} className="mb-4">
            <div className="input-group">
              <input
                type="text"
                className="form-control"
                placeholder="Search by name, phone number (09xxxxxxxx), or child ID (CH-XXXXXXXXXX)"
                value={keyword}
                onChange={(e) => setKeyword(e.target.value)}
              />
              <button className="btn btn-primary" type="submit" disabled={loading}>
                {loading ? <span className="spinner-border spinner-border-sm"></span> : 'Search'}
              </button>
            </div>
            <small className="text-muted">Example: "Abebe", "0912345678", or "CH-ABC123"</small>
          </form>

          {error && <div className="alert alert-warning">{error}</div>}

          {results.length > 0 && (
            <div>
              <h6>Search Results ({results.length})</h6>
              <div className="table-responsive">
                <table className="table table-bordered table-hover">
                  <thead className="table-light">
                    <tr>
                      <th>Child Name</th>
                      <th>Unique ID</th>
                      <th>DOB</th>
                      <th>Parent</th>
                      <th>Phone</th>
                      <th>Allergies</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {results.map((child) => (
                      <tr key={child.id}>
                        <td>{child.name}</td>
                        <td><code>{child.unique_child_id}</code></td>
                        <td>{child.dob}</td>
                        <td>{child.parent_name}</td>
                        <td>{child.parent_phone}</td>
                        <td className={child.allergies && child.allergies !== 'None' ? 'text-danger fw-bold' : ''}>
                          {child.allergies || 'None'}
                        </td>
                        <td>
                          <button
                            className="btn btn-sm btn-success"
                            onClick={() => {
                              // Find pending appointments for this child
                              // For simplicity, we'll show a list of pending vaccines to choose from
                              // We need to fetch appointments first. Let's assume we call an API
                              // But to keep it simple, we'll open a modal asking for vaccine and batch.
                              // Real implementation would fetch child's pending appointments.
                              alert(`Please go to the child's profile or use Appointment Manager.\nChild ID: ${child.id}`);
                            }}
                          >
                            💉 Record Vaccine
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Optional: Modal for vaccine administration could be added */}
      {showVaccineModal && selectedAppointment && selectedChild && (
        <VaccineAdministration
          appointment={selectedAppointment}
          onVaccineGiven={onVaccineGiven}
          compact={false}
        />
      )}
    </div>
  );
};

export default ChildSearch;