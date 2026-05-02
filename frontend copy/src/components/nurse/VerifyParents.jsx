import React, { useState, useEffect } from 'react';
import { getPendingParents, approveParent } from '../../services/nurseService';

const VerifyParents = ({ onVerified }) => {
  const [pendingParents, setPendingParents] = useState([]);
  const [loading, setLoading] = useState(false);
  const [processingId, setProcessingId] = useState(null);
  const [message, setMessage] = useState({ type: '', text: '' });

  useEffect(() => {
    loadPendingParents();
  }, []);

  const loadPendingParents = async () => {
    setLoading(true);
    try {
      const res = await getPendingParents();
      setPendingParents(res.data || []);
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Failed to load pending parents' });
    } finally {
      setLoading(false);
    }
  };

  const handleApprove = async (parentId) => {
    setProcessingId(parentId);
    try {
      await approveParent(parentId);
      setMessage({ type: 'success', text: 'Parent approved successfully. Children assigned to nurse.' });
      // Refresh list
      await loadPendingParents();
      // Notify parent component (dashboard) to refresh assigned children
      if (onVerified) onVerified();
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Approval failed' });
    } finally {
      setProcessingId(null);
      // Clear message after 5 seconds
      setTimeout(() => setMessage({ type: '', text: '' }), 5000);
    }
  };

  if (loading && pendingParents.length === 0) {
    return (
      <div className="text-center py-5">
        <div className="spinner-border text-primary" role="status">
          <span className="visually-hidden">Loading...</span>
        </div>
      </div>
    );
  }

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h4>Pending Parent Registrations</h4>
        <button className="btn btn-sm btn-outline-primary" onClick={loadPendingParents} disabled={loading}>
          🔄 Refresh
        </button>
      </div>

      {message.text && (
        <div className={`alert alert-${message.type === 'error' ? 'danger' : 'success'} alert-dismissible fade show`} role="alert">
          {message.text}
          <button type="button" className="btn-close" data-bs-dismiss="alert" onClick={() => setMessage({ type: '', text: '' })}></button>
        </div>
      )}

      {pendingParents.length === 0 ? (
        <div className="alert alert-info">No pending parent registrations.</div>
      ) : (
        <div className="table-responsive">
          <table className="table table-bordered table-hover">
            <thead className="table-light">
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Registered On</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {pendingParents.map((parent) => (
                <tr key={parent.id}>
                  <td>{parent.name}</td>
                  <td>{parent.email}</td>
                  <td>{parent.phone}</td>
                  <td>{new Date(parent.created_at).toLocaleDateString()}</td>
                  <td>
                    <button
                      className="btn btn-sm btn-success"
                      onClick={() => handleApprove(parent.id)}
                      disabled={processingId === parent.id}
                    >
                      {processingId === parent.id ? (
                        <>
                          <span className="spinner-border spinner-border-sm me-1" role="status"></span>
                          Approving...
                        </>
                      ) : (
                        '✓ Approve'
                      )}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

export default VerifyParents;