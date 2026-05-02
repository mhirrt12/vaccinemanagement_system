import React, { useState, useEffect } from 'react';
import { getPendingCertificates, approveCertificateAdmin } from '../../services/adminService';

const CertificateApproval = () => {
  const [certificates, setCertificates] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [processingId, setProcessingId] = useState(null);

  useEffect(() => {
    loadPendingCertificates();
  }, []);

  const loadPendingCertificates = async () => {
    setLoading(true);
    try {
      const res = await getPendingCertificates();
      setCertificates(res.data || []);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load pending certificates');
    } finally {
      setLoading(false);
    }
  };

  const handleApprove = async (certificateId) => {
    setProcessingId(certificateId);
    try {
      await approveCertificateAdmin(certificateId);
      setSuccess('Certificate approved successfully. Parent can now download.');
      loadPendingCertificates(); // Refresh list
      setTimeout(() => setSuccess(''), 3000);
    } catch (err) {
      setError(err.response?.data?.message || 'Approval failed');
      setTimeout(() => setError(''), 3000);
    } finally {
      setProcessingId(null);
    }
  };

  const getStatusBadge = (cert) => {
    if (cert.is_approved_by_nurse && cert.is_approved_by_admin) {
      return <span className="badge bg-success">Fully Approved</span>;
    } else if (cert.is_approved_by_nurse) {
      return <span className="badge bg-warning text-dark">Pending Admin Approval</span>;
    } else {
      return <span className="badge bg-secondary">Pending Nurse Approval</span>;
    }
  };

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h4>Certificate Approval (Admin)</h4>
        <button className="btn btn-outline-primary btn-sm" onClick={loadPendingCertificates} disabled={loading}>
          🔄 Refresh
        </button>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      {loading ? (
        <div className="text-center py-5"><div className="spinner-border text-primary"></div></div>
      ) : certificates.length === 0 ? (
        <div className="alert alert-info">No certificates pending admin approval.</div>
      ) : (
        <div className="table-responsive">
          <table className="table table-bordered table-hover">
            <thead className="table-light">
              <tr>
                <th>ID</th>
                <th>Child Name</th>
                <th>Unique ID</th>
                <th>Parent Name</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {certificates.map(cert => (
                <tr key={cert.id}>
                  <td>{cert.id}</td>
                  <td>{cert.child_name}</td>
                  <td><code>{cert.unique_child_id}</code></td>
                  <td>{cert.parent_name}</td>
                  <td>{getStatusBadge(cert)}</td>
                  <td>{new Date(cert.created_at).toLocaleDateString()}</td>
                  <td>
                    {cert.is_approved_by_nurse && !cert.is_approved_by_admin && (
                      <button
                        className="btn btn-sm btn-success"
                        onClick={() => handleApprove(cert.id)}
                        disabled={processingId === cert.id}
                      >
                        {processingId === cert.id ? (
                          <span className="spinner-border spinner-border-sm"></span>
                        ) : (
                          '✓ Approve'
                        )}
                      </button>
                    )}
                    {cert.is_approved_by_admin && (
                      <span className="text-success">Approved</span>
                    )}
                    {!cert.is_approved_by_nurse && (
                      <span className="text-muted">Awaiting nurse approval</span>
                    )}
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

export default CertificateApproval;