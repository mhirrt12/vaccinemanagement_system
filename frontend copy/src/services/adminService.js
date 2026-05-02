import api from './api';

// ==================== Dashboard Stats ====================

/**
 * Get admin dashboard statistics
 * @returns {Promise} Axios response with stats (total children, monthly vaccinations, etc.)
 */
export const getAdminStats = () => {
  return api.get('/admin/stats');
};

// ==================== Vaccine Management ====================

/**
 * Get all vaccines (including inactive)
 * @returns {Promise} Axios response with vaccines list
 */
export const getVaccines = () => {
  return api.get('/admin/vaccines');
};

/**
 * Add a new vaccine
 * @param {string} name - Vaccine name
 * @param {number} daysFromBirth - Days from birth when due
 * @param {string} description - Optional description
 * @returns {Promise} Axios response
 */
export const addVaccine = (name, daysFromBirth, description) => {
  return api.post('/admin/vaccines', { name, days_from_birth: daysFromBirth, description });
};

/**
 * Update an existing vaccine
 * @param {number} id - Vaccine ID
 * @param {string} name - Vaccine name
 * @param {number} daysFromBirth - Days from birth
 * @param {string} description - Optional description
 * @returns {Promise} Axios response
 */
export const updateVaccine = (id, name, daysFromBirth, description) => {
  return api.put(`/admin/vaccines/${id}`, { name, days_from_birth: daysFromBirth, description });
};

/**
 * Delete a vaccine (hard delete)
 * @param {number} id - Vaccine ID
 * @returns {Promise} Axios response
 */
export const deleteVaccine = (id) => {
  return api.delete(`/admin/vaccines/${id}`);
};

/**
 * Toggle vaccine active status (soft delete / reactivate)
 * @param {number} id - Vaccine ID
 * @param {boolean} isActive - New active status
 * @returns {Promise} Axios response
 */
export const toggleVaccineActive = (id, isActive) => {
  return api.post(`/admin/vaccines/${id}/toggle`, { is_active: isActive });
};

// ==================== Inventory Management ====================

/**
 * Get all inventory (vaccine batches)
 * @returns {Promise} Axios response with inventory list
 */
export const getInventory = () => {
  return api.get('/admin/inventory');
};

/**
 * Add a new vaccine batch
 * @param {number} vaccineId - Vaccine ID
 * @param {string} batchNumber - Batch number
 * @param {string} expiryDate - Expiry date (YYYY-MM-DD)
 * @param {number} quantity - Number of doses
 * @returns {Promise} Axios response
 */
export const addInventoryBatch = (vaccineId, batchNumber, expiryDate, quantity) => {
  return api.post('/admin/inventory', { vaccine_id: vaccineId, batch_number: batchNumber, expiry_date: expiryDate, quantity });
};

/**
 * Get low stock items (quantity < 100)
 * @returns {Promise} Axios response with low stock list
 */
export const getLowStock = () => {
  return api.get('/admin/low-stock');
};

/**
 * Get expiring batches (within 15 days)
 * @returns {Promise} Axios response with expiring batches list
 */
export const getExpiringBatches = () => {
  return api.get('/admin/expiring-batches');
};

// ==================== Nurse Management ====================

/**
 * Get all nurses
 * @returns {Promise} Axios response with nurses list
 */
export const getNurses = () => {
  return api.get('/admin/nurses');
};

/**
 * Create a new nurse account
 * @param {string} name - Full name
 * @param {string} email - Email address
 * @param {string} phone - Ethiopian phone number
 * @param {string} password - Plain password (will be hashed)
 * @returns {Promise} Axios response
 */
export const createNurse = (name, email, phone, password) => {
  return api.post('/admin/nurses', { name, email, phone, password });
};

/**
 * Revoke (delete) a nurse account
 * @param {number} id - Nurse user ID
 * @returns {Promise} Axios response
 */
export const deleteNurse = (id) => {
  return api.delete(`/admin/nurses/${id}`);
};

// ==================== Audit Logs ====================

/**
 * Get audit logs with pagination and filters
 * @param {Object} params - { limit, offset, user_id, action, start_date, end_date }
 * @returns {Promise} Axios response with logs list and total count
 */
export const getAuditLogs = (params = {}) => {
  const queryParams = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value) queryParams.append(key, value);
  });
  const queryString = queryParams.toString();
  return api.get(`/admin/audit-logs${queryString ? `?${queryString}` : ''}`);
};

// ==================== Reports ====================

/**
 * Get all generated reports (admin view)
 * @param {number} limit - Pagination limit
 * @param {number} offset - Pagination offset
 * @returns {Promise} Axios response with reports list
 */
export const getReports = (limit = 20, offset = 0) => {
  return api.get(`/admin/reports?limit=${limit}&offset=${offset}`);
};

/**
 * Get a specific report by ID
 * @param {number} id - Report ID
 * @returns {Promise} Axios response with report details
 */
export const getReport = (id) => {
  return api.get(`/admin/reports/${id}`);
};

/**
 * Download a report file
 * @param {number} id - Report ID
 * @returns {Promise} Axios response as blob
 */
export const downloadReport = (id) => {
  return api.get(`/reports/${id}/download`, { responseType: 'blob' });
};

/**
 * Generate a full system report (admin)
 * @param {string} type - 'weekly' or 'monthly'
 * @param {string} periodStart - Start date (YYYY-MM-DD)
 * @param {string} periodEnd - End date (YYYY-MM-DD)
 * @param {string} format - 'json', 'csv', 'pdf'
 * @returns {Promise} Axios response
 */
export const generateFullReport = (type, periodStart, periodEnd, format = 'json') => {
  return api.post('/reports/generate', { type, period_start: periodStart, period_end: periodEnd, format });
};

// ==================== Certificate Approval ====================

/**
 * Get all certificates pending admin approval (already approved by nurse)
 * @returns {Promise} Axios response with certificates list
 */
export const getPendingCertificates = () => {
  return api.get('/admin/pending-certificates');
};

/**
 * Approve a certificate (final admin approval)
 * @param {number} certificateId - Certificate ID
 * @returns {Promise} Axios response
 */
export const approveCertificateAdmin = (certificateId) => {
  return api.post(`/admin/approve-certificate/${certificateId}`);
};

// ==================== System Alerts ====================

/**
 * Trigger manual check of low stock and expiry alerts
 * @returns {Promise} Axios response with alert summary
 */
export const checkSystemAlerts = () => {
  return api.get('/admin/check-alerts');
};