import React, { useState, useEffect } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { getAdminStats, getLowStock, getExpiringBatches } from '../../services/adminService';
import { Bar } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';

// Register ChartJS components
ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

const AdminDashboard = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState({
    totalChildren: 0,
    monthlyVaccinations: 0,
    totalNurses: 0,
    totalParents: 0,
    vaccineLabels: [],
    vaccineCounts: [],
    recentActivities: []
  });
  const [lowStock, setLowStock] = useState([]);
  const [expiring, setExpiring] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    loadDashboardData();
  }, []);

  const loadDashboardData = async () => {
    setLoading(true);
    try {
      const [statsRes, lowStockRes, expiringRes] = await Promise.all([
        getAdminStats(),
        getLowStock(),
        getExpiringBatches()
      ]);
      setStats(statsRes.data);
      setLowStock(lowStockRes.data || []);
      setExpiring(expiringRes.data || []);
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load dashboard data');
    } finally {
      setLoading(false);
    }
  };

  const chartData = {
    labels: stats.vaccineLabels,
    datasets: [
      {
        label: 'Vaccinations (Last 30 Days)',
        data: stats.vaccineCounts,
        backgroundColor: 'rgba(54, 162, 235, 0.6)',
        borderColor: 'rgba(54, 162, 235, 1)',
        borderWidth: 1,
      },
    ],
  };

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'top' },
      title: { display: true, text: 'Vaccination Distribution by Type' },
    },
  };

  if (loading) {
    return (
      <div className="d-flex justify-content-center align-items-center" style={{ minHeight: '60vh' }}>
        <div className="spinner-border text-primary" role="status">
          <span className="visually-hidden">Loading dashboard...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="container-fluid">
      <div className="d-flex justify-content-between align-items-center mb-4">
        <h2>Admin Dashboard</h2>
        <p className="text-muted">Welcome, {user?.name}</p>
      </div>

      {error && (
        <div className="alert alert-danger alert-dismissible">
          {error}
          <button type="button" className="btn-close" onClick={() => setError('')}></button>
        </div>
      )}

      {/* Stats Cards */}
      <div className="row mb-4">
        <div className="col-md-3 mb-3">
          <div className="card text-white bg-primary h-100">
            <div className="card-body">
              <h5 className="card-title">Total Children</h5>
              <h2 className="display-4">{stats.totalChildren}</h2>
            </div>
          </div>
        </div>
        <div className="col-md-3 mb-3">
          <div className="card text-white bg-success h-100">
            <div className="card-body">
              <h5 className="card-title">Monthly Vaccinations</h5>
              <h2 className="display-4">{stats.monthlyVaccinations}</h2>
            </div>
          </div>
        </div>
        <div className="col-md-3 mb-3">
          <div className="card text-white bg-info h-100">
            <div className="card-body">
              <h5 className="card-title">Total Nurses</h5>
              <h2 className="display-4">{stats.totalNurses}</h2>
            </div>
          </div>
        </div>
        <div className="col-md-3 mb-3">
          <div className="card text-white bg-warning h-100">
            <div className="card-body">
              <h5 className="card-title">Total Parents</h5>
              <h2 className="display-4">{stats.totalParents}</h2>
            </div>
          </div>
        </div>
      </div>

      {/* Alerts */}
      {lowStock.length > 0 && (
        <div className="alert alert-warning">
          <strong>⚠️ Low Stock Alert:</strong> The following vaccines are running low:
          <ul className="mb-0 mt-2">
            {lowStock.map(item => (
              <li key={item.id}>{item.vaccine_name} - Batch {item.batch_number}: {item.quantity} doses remaining</li>
            ))}
          </ul>
        </div>
      )}

      {expiring.length > 0 && (
        <div className="alert alert-danger">
          <strong>⚠️ Expiring Soon:</strong> The following batches expire within 15 days:
          <ul className="mb-0 mt-2">
            {expiring.map(item => (
              <li key={item.id}>{item.vaccine_name} - Batch {item.batch_number} expires on {item.expiry_date}</li>
            ))}
          </ul>
        </div>
      )}

      {/* Chart */}
      <div className="row mb-4">
        <div className="col-12">
          <div className="card shadow-sm">
            <div className="card-header bg-primary text-white">
              <h5 className="mb-0">Vaccination Statistics</h5>
            </div>
            <div className="card-body">
              <div style={{ height: '400px' }}>
                {stats.vaccineLabels && stats.vaccineLabels.length > 0 ? (
                  <Bar data={chartData} options={chartOptions} />
                ) : (
                  <div className="alert alert-info text-center">No vaccination data available for the last 30 days.</div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Recent Activities */}
      <div className="row">
        <div className="col-12">
          <div className="card shadow-sm">
            <div className="card-header bg-secondary text-white">
              <h5 className="mb-0">Recent Vaccination Activities</h5>
            </div>
            <div className="card-body">
              {stats.recentActivities && stats.recentActivities.length > 0 ? (
                <div className="table-responsive">
                  <table className="table table-sm table-bordered">
                    <thead className="table-light">
                      <tr>
                        <th>Child</th>
                        <th>Vaccine</th>
                        <th>Given Date</th>
                        <th>Batch Number</th>
                      </tr>
                    </thead>
                    <tbody>
                      {stats.recentActivities.map(activity => (
                        <tr key={activity.id}>
                          <td>{activity.child_name}</td>
                          <td>{activity.vaccine_name}</td>
                          <td>{activity.given_date}</td>
                          <td>{activity.batch_number || '-'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="alert alert-info">No recent vaccination activities.</div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default AdminDashboard;