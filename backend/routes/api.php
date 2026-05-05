<?php
/**
 * API Routes Configuration
 * 
 * This file defines all API endpoints and maps them to controller methods.
 * Routes are organized by resource and role-based access.
 * 
 * URL Structure: /api/{version?}/{endpoint}
 * All routes (except public ones) require JWT authentication via AuthMiddleware.
 * Role-based access is enforced using RoleMiddleware.
 */

// ==================== PUBLIC ROUTES (No Authentication) ====================

// Authentication
$router->post('/auth/register', 'AuthController', 'register');
$router->post('/auth/login', 'AuthController', 'login');
$router->post('/auth/forgot-password', 'AuthController', 'forgotPassword');
$router->post('/auth/reset-password', 'AuthController', 'resetPassword');

// Public certificate verification (anyone can verify a certificate by child ID)
$router->get('/certificates/verify/{uniqueChildId}', 'CertificateController', 'verify');

// Health check endpoint
$router->get('/health', 'AuthController', 'health');

// ==================== PROTECTED ROUTES (Authentication Required) ====================

// All routes below require valid JWT token

// --- Authenticated User Info (all roles) ---
$router->get('/auth/me', 'AuthController', 'me');
$router->post('/auth/logout', 'AuthController', 'logout');

// --- Vaccine Endpoints (all authenticated users) ---
$router->get('/vaccines', 'VaccineController', 'getAll');
$router->get('/vaccines/{id}', 'VaccineController', 'getById');
$router->get('/vaccines/calculate-schedule', 'VaccineController', 'calculateSchedule');
$router->get('/vaccines/due', 'VaccineController', 'getDueVaccines');
$router->get('/vaccines/overdue', 'VaccineController', 'getOverdueVaccines');
$router->get('/vaccines/epi-schedule', 'VaccineController', 'getEpiSchedule');

// --- Appointment Endpoints (all authenticated users, role-specific actions) ---
$router->get('/appointments/child/{childId}', 'AppointmentController', 'getByChild');
$router->get('/appointments/{id}', 'AppointmentController', 'getById');
$router->post('/appointments/{id}/request-reschedule', 'AppointmentController', 'requestReschedule'); // Parents only
$router->put('/appointments/{id}/status', 'AppointmentController', 'updateStatus'); // Nurses/Admin
$router->post('/appointments/{id}/approve-reschedule', 'AppointmentController', 'approveReschedule'); // Nurses only
$router->post('/appointments/generate-for-child', 'AppointmentController', 'generateForChild'); // Nurses/Admin
$router->post('/appointments/mark-missed', 'AppointmentController', 'markMissed'); // Admin only
$router->post('/appointments/send-reminders', 'AppointmentController', 'sendReminders'); // Admin only

// --- Notification Endpoints (all authenticated users) ---
$router->get('/notifications', 'NotificationController', 'getNotifications');
$router->get('/notifications/unread-count', 'NotificationController', 'getUnreadCount');
$router->get('/notifications/{id}', 'NotificationController', 'getNotification');
$router->post('/notifications/{id}/read', 'NotificationController', 'markAsRead');
$router->post('/notifications/mark-all-read', 'NotificationController', 'markAllAsRead');
$router->delete('/notifications/{id}', 'NotificationController', 'deleteNotification');
$router->delete('/notifications/delete-all', 'NotificationController', 'deleteAll');

// --- Certificate Endpoints (all authenticated users) ---
$router->post('/certificates/generate', 'CertificateController', 'generate');
$router->get('/certificates/child/{childId}', 'CertificateController', 'getByChild');
$router->get('/certificates/download/{certificateId}', 'CertificateController', 'download');
$router->post('/certificates/nurse-approve/{certificateId}', 'CertificateController', 'nurseApprove'); // Nurses only
$router->post('/certificates/admin-approve/{certificateId}', 'CertificateController', 'adminApprove'); // Admin only
$router->get('/certificates/pending', 'CertificateController', 'getPending'); // Admin only
$router->get('/certificates', 'CertificateController', 'listAll'); // Admin only

// ==================== PARENT-SPECIFIC ROUTES ====================
$router->get('/parent/children', 'ParentController', 'getChildren');
$router->get('/parent/child/{childId}/schedule', 'ParentController', 'getChildSchedule');
$router->post('/parent/appointment/{appointmentId}/reschedule', 'ParentController', 'requestReschedule');
$router->get('/parent/child/{childId}/certificate', 'ParentController', 'downloadCertificate');
$router->get('/parent/notifications', 'ParentController', 'getNotifications');
$router->post('/parent/notifications/{notificationId}/read', 'ParentController', 'markNotificationRead');
$router->get('/parent/upcoming-appointments', 'ParentController', 'getUpcomingAppointments');
// ==================== PARENT-SPECIFIC ROUTES ====================
$router->get('/parent/children', 'ParentController', 'getChildren');
$router->post('/parent/child', 'ParentController', 'addChild');               // ✅ NEW
$router->get('/parent/child/{childId}/schedule', 'ParentController', 'getChildSchedule');
// ==================== NURSE-SPECIFIC ROUTES ====================
$router->get('/nurse/pending-parents', 'NurseController', 'getPendingParents');
$router->post('/nurse/approve-parent/{parentId}', 'NurseController', 'approveParent');
$router->get('/nurse/my-children', 'NurseController', 'getMyAssignedChildren');
$router->post('/nurse/walkin', 'NurseController', 'walkinRegistration');
$router->post('/nurse/record-vaccine', 'NurseController', 'recordVaccine');
$router->post('/nurse/appointment/{appointmentId}/approve-reschedule', 'NurseController', 'approveReschedule');
$router->get('/nurse/search', 'NurseController', 'searchChildren');
$router->get('/nurse/filter-by-vaccine', 'NurseController', 'filterByVaccine');
$router->post('/nurse/generate-report', 'NurseController', 'generateReport');
$router->post('/nurse/approve-certificate/{certificateId}', 'NurseController', 'approveCertificate');
$router->get('/nurse/upcoming-appointments', 'NurseController', 'getUpcomingAppointments');
$router->post('/nurse/child/{childId}/notes', 'NurseController', 'addChildNotes');
$router->get('/nurse/pending-children', 'NurseController', 'getPendingChildren');
$router->post('/nurse/approve-child/{childId}', 'NurseController', 'approveChild');
$router->post('/nurse/reject-child/{childId}', 'NurseController', 'rejectChild');
$router->get('/nurse/pending-children', 'NurseController', 'getPendingChildren');
$router->post('/nurse/approve-child/{childId}', 'NurseController', 'approveChild');
$router->post('/nurse/reject-child/{childId}', 'NurseController', 'rejectChild');
// ==================== ADMIN-SPECIFIC ROUTES ====================

// Dashboard statistics
$router->get('/admin/stats', 'AdminController', 'getStats');

// Vaccine management (full CRUD)
$router->get('/admin/vaccines', 'AdminController', 'getVaccines');
$router->post('/admin/vaccines', 'AdminController', 'addVaccine');
$router->put('/admin/vaccines/{id}', 'AdminController', 'updateVaccine');
$router->delete('/admin/vaccines/{id}', 'AdminController', 'deleteVaccine');
$router->post('/admin/vaccines/{id}/toggle', 'AdminController', 'toggleVaccine');

// Inventory management
$router->get('/admin/inventory', 'AdminController', 'getInventory');
$router->post('/admin/inventory', 'AdminController', 'addInventoryBatch');
$router->get('/admin/low-stock', 'AdminController', 'getLowStock');
$router->get('/admin/expiring-batches', 'AdminController', 'getExpiringBatches');

// Nurse management
$router->get('/admin/nurses', 'AdminController', 'getNurses');
$router->post('/admin/nurses', 'AdminController', 'createNurse');
$router->delete('/admin/nurses/{id}', 'AdminController', 'deleteNurse');

// Certificate admin approval
$router->get('/admin/pending-certificates', 'AdminController', 'getPendingCertificates');
$router->post('/admin/approve-certificate/{certificateId}', 'AdminController', 'approveCertificate');

// Audit logs
$router->get('/admin/audit-logs', 'AdminController', 'getAuditLogs');

// Reports (admin view)
$router->get('/admin/reports', 'AdminController', 'getReports');
$router->get('/admin/reports/{id}', 'AdminController', 'getReport');

// System alerts (cron can call this)
$router->get('/admin/check-alerts', 'AdminController', 'checkAlerts');

// ==================== REPORT ENDPOINTS (shared, role-specific) ====================
$router->post('/reports/generate', 'ReportController', 'generate');
$router->get('/reports', 'ReportController', 'getAll');
$router->get('/reports/{id}', 'ReportController', 'getById');
$router->delete('/reports/{id}', 'ReportController', 'delete');
$router->get('/reports/{id}/download', 'ReportController', 'download');
$router->get('/reports/summary', 'ReportController', 'getSummary');
?>