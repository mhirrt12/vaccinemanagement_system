import React from 'react';

/**
 * ChildProfile Component - Displays detailed information about a child
 * 
 * @param {Object} props
 * @param {Object} props.child - Child object from API
 * @returns {JSX.Element}
 */
const ChildProfile = ({ child }) => {
  if (!child) {
    return <div className="alert alert-warning">No child data available.</div>;
  }

  const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
  };

  const calculateAge = (dob) => {
    if (!dob) return 'N/A';
    const birthDate = new Date(dob);
    const today = new Date();
    let years = today.getFullYear() - birthDate.getFullYear();
    let months = today.getMonth() - birthDate.getMonth();
    let days = today.getDate() - birthDate.getDate();
    if (days < 0) {
      months--;
      days += new Date(today.getFullYear(), today.getMonth(), 0).getDate();
    }
    if (months < 0) {
      years--;
      months += 12;
    }
    if (years > 0) return `${years} year${years > 1 ? 's' : ''} ${months} month${months !== 1 ? 's' : ''}`;
    if (months > 0) return `${months} month${months > 1 ? 's' : ''} ${days} day${days !== 1 ? 's' : ''}`;
    return `${days} day${days !== 1 ? 's' : ''}`;
  };

  return (
    <div className="card shadow-sm">
      <div className="card-header bg-info text-white">
        <h5 className="mb-0">Child Profile</h5>
      </div>
      <div className="card-body">
        <div className="row">
          <div className="col-md-6">
            <table className="table table-sm table-borderless">
              <tbody>
                <tr>
                  <td className="fw-bold" style={{ width: '40%' }}>Full Name:</td>
                  <td>{child.name || 'N/A'}</td>
                </tr>
                <tr>
                  <td className="fw-bold">Unique ID:</td>
                  <td><code>{child.unique_child_id || 'N/A'}</code></td>
                </tr>
                <tr>
                  <td className="fw-bold">Date of Birth:</td>
                  <td>{child.dob ? formatDate(child.dob) : 'N/A'}</td>
                </tr>
                <tr>
                  <td className="fw-bold">Age:</td>
                  <td>{calculateAge(child.dob)}</td>
                </tr>
                <tr>
                  <td className="fw-bold">Gender:</td>
                  <td>{child.gender || 'N/A'}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div className="col-md-6">
            <table className="table table-sm table-borderless">
              <tbody>
                <tr>
                  <td className="fw-bold" style={{ width: '40%' }}>Blood Type:</td>
                  <td>{child.blood_type || 'Not recorded'}</td>
                </tr>
                <tr>
                  <td className="fw-bold">Allergies:</td>
                  <td className={child.allergies && child.allergies !== 'None' ? 'text-danger fw-bold' : ''}>
                    {child.allergies || 'None'}
                  </td>
                </tr>
                <tr>
                  <td className="fw-bold">Birth Weight:</td>
                  <td>{child.birth_weight ? `${child.birth_weight} kg` : 'N/A'}</td>
                </tr>
                <tr>
                  <td className="fw-bold">Delivery Type:</td>
                  <td>{child.delivery_type || 'N/A'}</td>
                </tr>
                <tr>
                  <td className="fw-bold">Birth Place:</td>
                  <td>{child.birth_place || 'N/A'}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div className="mt-2">
          <span className={`badge ${child.is_verified ? 'bg-success' : 'bg-warning'}`}>
            {child.is_verified ? '✓ Verified' : '⏳ Pending Verification'}
          </span>
        </div>
      </div>
    </div>
  );
};

export default ChildProfile;