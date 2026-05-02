import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './contexts/AuthContext';
import PrivateRoute from './components/common/PrivateRoute';
import Navbar from './components/common/Navbar';
import Footer from './components/common/Footer';
import Login from './components/auth/Login';
import Register from './components/auth/Register';

// Parent Components
import ParentDashboard from './components/parent/Dashboard';
import ChildProfile from './components/parent/ChildProfile';
import VaccineTable from './components/parent/VaccineTable';
import AppointmentReschedule from './components/parent/AppointmentReschedule';
import CertificateDownload from './components/parent/CertificateDownload';

// Nurse Components
import NurseDashboard from './components/nurse/Dashboard';
import VerifyParents from './components/nurse/VerifyParents';
import WalkinRegistration from './components/nurse/WalkinRegistration';
import VaccineAdministration from './components/nurse/VaccineAdministration';
import AppointmentManager from './components/nurse/AppointmentManager';
import ChildSearch from './components/nurse/ChildSearch';
import Reports from './components/nurse/Reports';

// Admin Components
import AdminDashboard from './components/admin/Dashboard';
import VaccineManager from './components/admin/VaccineManager';
import NurseManager from './components/admin/NurseManager';
import AuditLogs from './components/admin/AuditLogs';
import ReportsView from './components/admin/ReportsView';
import CertificateApproval from './components/admin/CertificateApproval';

function App() {
  return (
    <Router>
      <AuthProvider>
        <div className="d-flex flex-column min-vh-100">
          <Navbar />
          <main className="flex-grow-1">
            <Routes>
              {/* Public Routes */}
              <Route path="/login" element={<Login />} />
              <Route path="/register" element={<Register />} />
              <Route path="/" element={<Navigate to="/login" />} />

              {/* Parent Routes */}
              <Route element={<PrivateRoute allowedRoles={['parent']} />}>
                <Route path="/parent" element={<ParentDashboard />} />
                <Route path="/parent/child-profile/:childId" element={<ChildProfile />} />
                <Route path="/parent/vaccine-table/:childId" element={<VaccineTable />} />
                <Route path="/parent/reschedule/:appointmentId" element={<AppointmentReschedule />} />
                <Route path="/parent/certificate/:childId" element={<CertificateDownload />} />
              </Route>

              {/* Nurse Routes */}
              <Route element={<PrivateRoute allowedRoles={['nurse']} />}>
                <Route path="/nurse" element={<NurseDashboard />} />
                <Route path="/nurse/verify-parents" element={<VerifyParents />} />
                <Route path="/nurse/walkin" element={<WalkinRegistration />} />
                <Route path="/nurse/vaccinate/:appointmentId" element={<VaccineAdministration />} />
                <Route path="/nurse/manage-appointments/:childId" element={<AppointmentManager />} />
                <Route path="/nurse/search" element={<ChildSearch />} />
                <Route path="/nurse/reports" element={<Reports />} />
              </Route>

              {/* Admin Routes */}
              <Route element={<PrivateRoute allowedRoles={['admin']} />}>
                <Route path="/admin" element={<AdminDashboard />} />
                <Route path="/admin/vaccines" element={<VaccineManager />} />
                <Route path="/admin/nurses" element={<NurseManager />} />
                <Route path="/admin/audit-logs" element={<AuditLogs />} />
                <Route path="/admin/reports" element={<ReportsView />} />
                <Route path="/admin/certificates" element={<CertificateApproval />} />
              </Route>

              {/* Fallback */}
              <Route path="*" element={<Navigate to="/login" />} />
            </Routes>
          </main>
          <Footer />
        </div>
      </AuthProvider>
    </Router>
  );
}

export default App;