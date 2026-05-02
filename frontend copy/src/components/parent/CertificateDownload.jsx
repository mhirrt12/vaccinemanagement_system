import React, { useState } from 'react';
import { downloadCertificate } from '../../services/parentService';

/**
 * CertificateDownload Component - Button and logic to download vaccination certificate
 * 
 * @param {Object} props
 * @param {number} props.childId - ID of the child
 * @param {string} props.childName - Name of the child (for display)
 * @param {string} props.uniqueChildId - Unique ID of the child (for filename)
 * @returns {JSX.Element}
 */
const CertificateDownload = ({ childId, childName, uniqueChildId }) => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const handleDownload = async () => {
    if (!childId) {
      setError('Invalid child information');
      return;
    }

    setLoading(true);
    setError('');
    setSuccess('');

    try {
      const response = await downloadCertificate(childId);
      
      // Create blob download link
      const blob = new Blob([response.data], { type: 'application/pdf' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `vaccination_certificate_${uniqueChildId || childId}.pdf`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      
      setSuccess('Certificate downloaded successfully!');
      
      // Clear success message after 5 seconds
      setTimeout(() => setSuccess(''), 5000);
    } catch (err) {
      const message = err.response?.data?.message || 'Certificate not available. Please ensure it has been approved by nurse and admin.';
      setError(message);
      
      // Clear error after 5 seconds
      setTimeout(() => setError(''), 5000);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      <button
        className="btn btn-outline-success btn-sm"
        onClick={handleDownload}
        disabled={loading}
        title="Download vaccination certificate (requires nurse and admin approval)"
      >
        {loading ? (
          <>
            <span className="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            Generating...
          </>
        ) : (
          '📄 Download Certificate'
        )}
      </button>
      {error && (
        <div className="text-danger small mt-1">
          {error}
        </div>
      )}
      {success && (
        <div className="text-success small mt-1">
          {success}
        </div>
      )}
    </div>
  );
};

export default CertificateDownload;