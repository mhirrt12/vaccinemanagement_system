import React, { useState } from 'react';
import { generateReport, getReports } from '../../services/nurseService';

const Reports = () => {
  const [reportType, setReportType] = useState('weekly');
  const [periodStart, setPeriodStart] = useState('');
  const [periodEnd, setPeriodEnd] = useState('');
  const [loading, setLoading] = useState(false);
  const [reportData, setReportData] = useState(null);
  const [savedReports, setSavedReports] = useState([]);
  const [loadingReports, setLoadingReports] = useState(false);
  const [message, setMessage] = useState({ type: '', text: '' });

  // Set default dates: last 7 days for weekly, last 30 days for monthly
  const setDefaultDates = (type) => {
    const end = new Date();
    const start = new Date();
    if (type === 'weekly') {
      start.setDate(end.getDate() - 7);
    } else {
      start.setDate(end.getDate() - 30);
    }
    setPeriodStart(start.toISOString().split('T')[0]);
    setPeriodEnd(end.toISOString().split('T')[0]);
  };

  const handleTypeChange = (e) => {
    const type = e.target.value;
    setReportType(type);
    setDefaultDates(type);
  };

  const handleGenerate = async (e) => {
    e.preventDefault();
    if (!periodStart || !periodEnd) {
      setMessage({ type: 'error', text: 'Please select both start and end dates' });
      return;
    }
    setLoading(true);
    setMessage({ type: '', text: '' });
    try {
      const res = await generateReport(reportType, periodStart, periodEnd);
      setReportData(res.data.data || res.data);
      setMessage({ type: 'success', text: 'Report generated successfully' });
      // Refresh saved reports list
      loadSavedReports();
    } catch (err) {
      setMessage({ type: 'error', text: err.response?.data?.message || 'Failed to generate report' });
    } finally {
      setLoading(false);
    }
  };

  const loadSavedReports = async () => {
    setLoadingReports(true);
    try {
      const res = await getReports();
      setSavedReports(res.data || []);
    } catch (err) {
      console.error('Failed to load reports', err);
    } finally {
      setLoadingReports(false);
    }
  };

  // Set default dates on mount
  React.useEffect(() => {
    setDefaultDates('weekly');
    loadSavedReports();
  }, []);

  return (
    <div>
      <div className="card shadow-sm mb-4">
        <div className="card-header bg-primary text-white">
          <h5 className="mb-0">Generate New Report</h5>
        </div>
        <div className="card-body">
          {message.text && (
            <div className={`alert alert-${message.type === 'error' ? 'danger' : 'success'} alert-dismissible`}>
              {message.text}
              <button type="button" className="btn-close" onClick={() => setMessage({ type: '', text: '' })}></button>
            </div>
          )}
          <form onSubmit={handleGenerate}>
            <div className="row">
              <div className="col-md-3 mb-3">
                <label className="form-label">Report Type</label>
                <select className="form-select" value={reportType} onChange={handleTypeChange}>
                  <option value="weekly">Weekly (last 7 days)</option>
                  <option value="monthly">Monthly (last 30 days)</option>
                </select>
              </div>
              <div className="col-md-3 mb-3">
                <label className="form-label">Start Date</label>
                <input type="date" className="form-control" value={periodStart} onChange={(e) => setPeriodStart(e.target.value)} required />
              </div>
              <div className="col-md-3 mb-3">
                <label className="form-label">End Date</label>
                <input type="date" className="form-control" value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} required />
              </div>
              <div className="col-md-3 mb-3 d-flex align-items-end">
                <button type="submit" className="btn btn-primary w-100" disabled={loading}>
                  {loading ? <span className="spinner-border spinner-border-sm"></span> : 'Generate Report'}
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      {/* Display generated report */}
      {reportData && (
        <div className="card shadow-sm mb-4">
          <div className="card-header bg-success text-white d-flex justify-content-between">
            <h5 className="mb-0">Report: {reportData.period_start} to {reportData.period_end}</h5>
            <small>Generated at {reportData.generated_at}</small>
          </div>
          <div className="card-body">
            <div className="row mb-4">
              <div className="col-md-3">
                <div className="card bg-light">
                  <div className="card-body text-center">
                    <h3>{reportData.summary?.total_vaccinations || 0}</h3>
                    <p className="mb-0">Total Vaccinations</p>
                  </div>
                </div>
              </div>
              <div className="col-md-3">
                <div className="card bg-light">
                  <div className="card-body text-center">
                    <h3>{reportData.summary?.total_children_vaccinated || 0}</h3>
                    <p className="mb-0">Children Vaccinated</p>
                  </div>
                </div>
              </div>
              <div className="col-md-3">
                <div className="card bg-light">
                  <div className="card-body text-center">
                    <h3>{reportData.summary?.missed_appointments || 0}</h3>
                    <p className="mb-0">Missed Appointments</p>
                  </div>
                </div>
              </div>
              <div className="col-md-3">
                <div className="card bg-light">
                  <div className="card-body text-center">
                    <h3>{reportData.summary?.assigned_children || reportData.summary?.total_children_vaccinated || 0}</h3>
                    <p className="mb-0">Assigned Children</p>
                  </div>
                </div>
              </div>
            </div>

            <h6>Vaccination by Vaccine</h6>
            <div className="table-responsive">
              <table className="table table-sm table-bordered">
                <thead className="table-light">
                  <tr><th>Vaccine</th><th>Count</th></tr>
                </thead>
                <tbody>
                  {reportData.by_vaccine?.map((item, idx) => (
                    <tr key={idx}><td>{item.name}</td><td>{item.count}</td></tr>
                  ))}
                  {(!reportData.by_vaccine || reportData.by_vaccine.length === 0) && (
                    <tr><td colSpan="2" className="text-center">No data</td></tr>
                  )}
                </tbody>
              </table>
            </div>

            {reportData.children_list && reportData.children_list.length > 0 && (
              <>
                <h6 className="mt-3">Child Level Breakdown</h6>
                <div className="table-responsive">
                  <table className="table table-sm table-bordered">
                    <thead className="table-light">
                      <tr><th>Child Name</th><th>Unique ID</th><th>Completed</th><th>Missed</th></tr>
                    </thead>
                    <tbody>
                      {reportData.children_list.map((child, idx) => (
                        <tr key={idx}>
                          <td>{child.child_name}</td>
                          <td>{child.unique_child_id}</td>
                          <td>{child.completed_vaccinations}</td>
                          <td>{child.missed_appointments}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Saved Reports History */}
      <div className="card shadow-sm">
        <div className="card-header bg-secondary text-white d-flex justify-content-between">
          <h5 className="mb-0">Previously Generated Reports</h5>
          <button className="btn btn-sm btn-light" onClick={loadSavedReports} disabled={loadingReports}>
            ↻ Refresh
          </button>
        </div>
        <div className="card-body">
          {loadingReports ? (
            <div className="text-center"><div className="spinner-border spinner-border-sm"></div> Loading...</div>
          ) : savedReports.length === 0 ? (
            <div className="alert alert-info">No saved reports found.</div>
          ) : (
            <div className="table-responsive">
              <table className="table table-sm table-bordered">
                <thead className="table-light">
                  <tr><th>Type</th><th>Period</th><th>Generated By</th><th>Created At</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  {savedReports.map((report) => (
                    <tr key={report.id}>
                      <td><span className="badge bg-info">{report.type}</span></td>
                      <td>{report.period_start} to {report.period_end}</td>
                      <td>{report.generated_by_name}</td>
                      <td>{new Date(report.created_at).toLocaleString()}</td>
                      <td>
                        <button
                          className="btn btn-sm btn-outline-primary me-1"
                          onClick={() => setReportData(report.data ? (typeof report.data === 'string' ? JSON.parse(report.data) : report.data) : null)}
                        >
                          View
                        </button>
                        {report.file_path && (
                          <a href={`/api/reports/${report.id}/download`} className="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer">
                            Download
                          </a>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default Reports;