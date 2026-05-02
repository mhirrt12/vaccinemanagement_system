import React, { useState, useEffect } from 'react';
import { getAuditLogs } from '../../services/adminService';

const AuditLogs = () => {
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [pagination, setPagination] = useState({ limit: 50, offset: 0, total: 0 });
  const [filters, setFilters] = useState({
    user_id: '',
    action: '',
    start_date: '',
    end_date: ''
  });

  useEffect(() => {
    loadLogs();
  }, [pagination.offset, pagination.limit, filters]);

  const loadLogs = async () => {
    setLoading(true);
    try {
      const params = {
        limit: pagination.limit,
        offset: pagination.offset,
        ...filters
      };
      const res = await getAuditLogs(params);
      setLogs(res.data.logs || res.data || []);
      setPagination(prev => ({ ...prev, total: res.data.total || 0 }));
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load audit logs');
    } finally {
      setLoading(false);
    }
  };

  const handleFilterChange = (e) => {
    const { name, value } = e.target;
    setFilters(prev => ({ ...prev, [name]: value }));
    setPagination(prev => ({ ...prev, offset: 0 })); // Reset to first page on filter change
  };

  const handlePageChange = (newOffset) => {
    setPagination(prev => ({ ...prev, offset: newOffset }));
  };

  const formatDate = (dateString) => {
    return new Date(dateString).toLocaleString();
  };

  const renderDetails = (details) => {
    if (!details) return '—';
    if (typeof details === 'string') return details;
    return JSON.stringify(details, null, 2);
  };

  const totalPages = Math.ceil(pagination.total / pagination.limit);
  const currentPage = Math.floor(pagination.offset / pagination.limit) + 1;

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-3">
        <h4>Audit Logs</h4>
        <button className="btn btn-outline-primary btn-sm" onClick={loadLogs} disabled={loading}>
          {loading ? 'Refreshing...' : 'Refresh'}
        </button>
      </div>

      {/* Filters */}
      <div className="card mb-3">
        <div className="card-header bg-secondary text-white">
          <h6 className="mb-0">Filters</h6>
        </div>
        <div className="card-body">
          <div className="row g-2">
            <div className="col-md-3">
              <input type="text" className="form-control" name="user_id" placeholder="User ID" value={filters.user_id} onChange={handleFilterChange} />
            </div>
            <div className="col-md-3">
              <input type="text" className="form-control" name="action" placeholder="Action (e.g., login, approve)" value={filters.action} onChange={handleFilterChange} />
            </div>
            <div className="col-md-3">
              <input type="date" className="form-control" name="start_date" placeholder="Start Date" value={filters.start_date} onChange={handleFilterChange} />
            </div>
            <div className="col-md-3">
              <input type="date" className="form-control" name="end_date" placeholder="End Date" value={filters.end_date} onChange={handleFilterChange} />
            </div>
          </div>
        </div>
      </div>

      {error && <div className="alert alert-danger">{error}</div>}

      {loading ? (
        <div className="text-center py-5"><div className="spinner-border text-primary"></div></div>
      ) : logs.length === 0 ? (
        <div className="alert alert-info">No audit logs found.</div>
      ) : (
        <>
          <div className="table-responsive">
            <table className="table table-bordered table-sm table-hover">
              <thead className="table-dark">
                <tr>
                  <th>ID</th>
                  <th>User</th>
                  <th>Action</th>
                  <th>Details</th>
                  <th>IP Address</th>
                  <th>Created At</th>
                </tr>
              </thead>
              <tbody>
                {logs.map(log => (
                  <tr key={log.id}>
                    <td>{log.id}</td>
                    <td>{log.user_name || `User ${log.user_id}`}</td>
                    <td><code>{log.action}</code></td>
                    <td style={{ maxWidth: '300px', wordBreak: 'break-word' }}>
                      <pre style={{ fontSize: '0.8rem', margin: 0, whiteSpace: 'pre-wrap' }}>{renderDetails(log.details)}</pre>
                    </td>
                    <td>{log.ip_address || '-'}</td>
                    <td>{formatDate(log.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <nav>
              <ul className="pagination justify-content-center">
                <li className={`page-item ${currentPage === 1 ? 'disabled' : ''}`}>
                  <button className="page-link" onClick={() => handlePageChange(0)}>First</button>
                </li>
                <li className={`page-item ${currentPage === 1 ? 'disabled' : ''}`}>
                  <button className="page-link" onClick={() => handlePageChange(pagination.offset - pagination.limit)}>Previous</button>
                </li>
                <li className="page-item disabled"><span className="page-link">Page {currentPage} of {totalPages}</span></li>
                <li className={`page-item ${currentPage === totalPages ? 'disabled' : ''}`}>
                  <button className="page-link" onClick={() => handlePageChange(pagination.offset + pagination.limit)}>Next</button>
                </li>
                <li className={`page-item ${currentPage === totalPages ? 'disabled' : ''}`}>
                  <button className="page-link" onClick={() => handlePageChange((totalPages - 1) * pagination.limit)}>Last</button>
                </li>
              </ul>
            </nav>
          )}
        </>
      )}
    </div>
  );
};

export default AuditLogs;