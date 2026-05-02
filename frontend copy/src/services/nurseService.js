import api from './api';

// ==================== Parent Verification ====================

/**
 * Get all pending parent registrations
 * @returns {Promise} Axios response with pending parents list
 */
export const getPendingParents = () => {
  return api.get('/nurse/pending-parents');
};

/**
 * Approve a parent registration
 * @param {number} parentId - Parent user ID
 * @returns {Promise} Axios response
 */
export const approveParent = (parentId) => {
  return api.post(`/nurse/approve-parent/${parentId}`);
};

// ==================== Assigned Children ====================

/**
 * Get all children assigned to the logged-in nurse
 * @returns {Promise} Axios response with children list
 */
export const getMyAssignedChildren = () => {
  return api.get('/nurse/my-children');
};

// ==================== Walk-in Registration ====================

/**
 * Register a walk-in child (with optional parent creation)
 * @param {Object} data - Registration data
 * @param {string} data.parent_phone - Parent phone number
 * @param {string} data.parent_name - Parent name (if new parent)
 * @param {Object} data.child - Child information
 * @returns {Promise} Axios response
 */
export const walkinRegistration = (data) => {
  return api.post('/nurse/walkin', data);
};

// ==================== Vaccine Administration ====================

/**
 * Record vaccine administration for an appointment
 * @param {number} appointmentId - Appointment ID
 * @param {string} batchNumber - Vaccine batch number
 * @param {string} notes - Clinical notes (optional)
 * @returns {Promise} Axios response
 */
export const recordVaccine = (appointmentId, batchNumber, notes) => {
  return api.post('/nurse/record-vaccine', { appointment_id: appointmentId, batch_number: batchNumber, notes });
};

/**
 * Get available batches for a specific vaccine
 * @param {number} vaccineId - Vaccine ID
 * @returns {Promise} Axios response with batches list
 */
export const getAvailableBatches = (vaccineId) => {
  return api.get(`/nurse/available-batches?vaccine_id=${vaccineId}`);
};

// ==================== Appointment Management ====================

/**
 * Get appointments for a specific child
 * @param {number} childId - Child ID
 * @returns {Promise} Axios response with appointments list
 */
export const getChildAppointments = (childId) => {
  return api.get(`/appointments/child/${childId}`);
};

/**
 * Update appointment status (complete, miss, etc.)
 * @param {number} appointmentId - Appointment ID
 * @param {string} status - New status (completed, missed, cancelled)
 * @param {string} batchNumber - Batch number (for completed)
 * @param {string} notes - Notes (optional)
 * @returns {Promise} Axios response
 */
export const updateAppointmentStatus = (appointmentId, status, batchNumber, notes) => {
  return api.put(`/appointments/${appointmentId}/status`, { status, batch_number: batchNumber, notes });
};

/**
 * Approve or reject a reschedule request
 * @param {number} appointmentId - Appointment ID
 * @param {boolean} approved - True to approve, false to reject
 * @returns {Promise} Axios response
 */
export const approveReschedule = (appointmentId, approved) => {
  return api.post(`/nurse/appointment/${appointmentId}/approve-reschedule`, { approved });
};

// ==================== Search & Filter ====================

/**
 * Search children by name, phone, or child ID
 * @param {string} keyword - Search term
 * @returns {Promise} Axios response with matching children
 */
export const searchChildren = (keyword) => {
  return api.get(`/nurse/search?keyword=${encodeURIComponent(keyword)}`);
};

/**
 * Filter children by vaccine type (pending for that vaccine)
 * @param {number} vaccineId - Vaccine ID
 * @returns {Promise} Axios response with children list
 */
export const filterByVaccine = (vaccineId) => {
  return api.get(`/nurse/filter-by-vaccine?vaccine_id=${vaccineId}`);
};

// ==================== Reports ====================

/**
 * Generate a weekly or monthly report
 * @param {string} type - 'weekly' or 'monthly'
 * @param {string} periodStart - Start date (YYYY-MM-DD)
 * @param {string} periodEnd - End date (YYYY-MM-DD)
 * @returns {Promise} Axios response with report data
 */
export const generateReport = (type, periodStart, periodEnd) => {
  return api.post('/nurse/generate-report', { type, period_start: periodStart, period_end: periodEnd });
};

/**
 * Get list of previously generated reports
 * @param {number} limit - Pagination limit
 * @param {number} offset - Pagination offset
 * @returns {Promise} Axios response with reports list
 */
export const getReports = (limit = 20, offset = 0) => {
  return api.get(`/reports?limit=${limit}&offset=${offset}`);
};

// ==================== Certificate ====================

/**
 * Approve a certificate (nurse approval)
 * @param {number} certificateId - Certificate ID
 * @returns {Promise} Axios response
 */
export const approveCertificate = (certificateId) => {
  return api.post(`/nurse/approve-certificate/${certificateId}`);
};

// ==================== Upcoming Appointments ====================

/**
 * Get upcoming appointments for the nurse (next 7 days)
 * @returns {Promise} Axios response with appointments list
 */
export const getUpcomingAppointments = () => {
  return api.get('/nurse/upcoming-appointments');
};

// ==================== Child Notes ====================

/**
 * Add clinical notes to a child record
 * @param {number} childId - Child ID
 * @param {string} notes - Notes to add
 * @returns {Promise} Axios response
 */
export const addChildNotes = (childId, notes) => {
  return api.post(`/nurse/child/${childId}/notes`, { notes });
};