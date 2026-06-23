import axios from 'axios';

export const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: { 'Content-Type': 'application/json' },
});

http.interceptors.request.use((config) => {
  const headers = config.headers ?? {};
  const apiSecret = import.meta.env.VITE_API_SECRET ?? '';
  const sessionToken = localStorage.getItem('hdreams.session_token') ?? '';

  if (apiSecret) {
    headers.Authorization = `Bearer ${apiSecret}`;
  }
  if (sessionToken) {
    headers['X-Session-Token'] = sessionToken;
  }

  config.headers = headers;
  return config;
});

export const loginAuth = (payload) => http.post('/auth/login', payload).then((r) => r.data);
export const fetchAuthMe = () => http.get('/auth/me').then((r) => r.data);
export const logoutAuth = () => http.post('/auth/logout').then((r) => r.data);
export const fetchKPIs     = (params) => http.get('/kpis',         { params }).then((r) => r.data);
export const fetchCola     = (params) => http.get('/kpis/cola',    { params }).then((r) => r.data);
export const fetchKPIHoras = (params) => http.get('/kpis/horas',   { params }).then((r) => r.data);
export const fetchAbTests  = (params) => http.get('/kpis/ab',      { params }).then((r) => r.data);
export const fetchHorasPico = (params) => http.get('/kpis/horas-pico', { params }).then((r) => r.data);
export const fetchLeads    = (params) => http.get('/leads',        { params }).then((r) => r.data);
export const fetchLeadDetail = (id) => http.get(`/leads/${id}`).then((r) => r.data);
export const fetchLeadCopilot = (id) => http.get(`/leads/${id}/copilot`).then((r) => r.data);
export const autoAssignLeadCopilot = (id) => http.post(`/leads/${id}/auto-assign`).then((r) => r.data);
export const uploadLeadVoiceNote = (id, file) => {
  const form = new FormData();
  form.append('audio', file);
  return http.post(`/leads/${id}/voice-note`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }).then((r) => r.data);
};
export const createLeadNote = (id, payload) => http.post(`/leads/${id}/notes`, payload).then((r) => r.data);
export const fetchInterviewSlots = (params) => http.get('/interview-slots', { params }).then((r) => r.data);
export const fetchInterviews = (params) => http.get('/interviews', { params }).then((r) => r.data);
export const createInterview = (payload) => http.post('/interviews', payload).then((r) => r.data);
export const updateInterview = (id, payload) => http.patch(`/interviews/${id}`, payload).then((r) => r.data);
export const confirmInterview = (id) => http.post(`/interviews/${id}/confirm`).then((r) => r.data);
export const markInterviewNoShow = (id) => http.post(`/interviews/${id}/no-show`).then((r) => r.data);
export const fetchAccountsPanel = () => http.get('/accounts/panel').then((r) => r.data);
export const fetchAccountApps = () => http.get('/accounts/apps').then((r) => r.data);
export const upsertAccountApp = (payload) => http.post('/accounts/apps', payload).then((r) => r.data);
export const fetchAccountsInbox = (params) => http.get('/accounts/inbox', { params }).then((r) => r.data);
export const fetchAccountsInboxThread = (leadId) => http.get(`/accounts/inbox/${leadId}`).then((r) => r.data);
export const replyAccountsInbox = (leadId, payload) => http.post(`/accounts/inbox/${leadId}/reply`, payload).then((r) => r.data);
export const assignAccountsInbox = (leadId, payload) => http.post(`/accounts/inbox/${leadId}/assign`, payload).then((r) => r.data);
export const applyAccountsInboxMacro = (leadId, payload) => http.post(`/accounts/inbox/${leadId}/macro`, payload).then((r) => r.data);
export const fetchAccessPermissions = (params) => http.get('/access/permissions', { params }).then((r) => r.data);
export const updateAccessPermissions = (payload) => http.post('/access/permissions', payload).then((r) => r.data);
export const fetchRecruiters = (params) => http.get('/recruiters', { params }).then((r) => r.data);
export const fetchFlowOverview = () => http.get('/flow').then((r) => r.data);
export const fetchFlowFunnel = (params) => http.get('/flow/funnel', { params }).then((r) => r.data);
export const fetchExecutiveDashboard = (params) => http.get('/operations/executive', { params }).then((r) => r.data);
export const fetchAutomationOverview = (params) => http.get('/operations/automations', { params }).then((r) => r.data);
export const fetchExecutiveReport = (params) => http.get('/operations/report', { params }).then((r) => r.data);
export const downloadExecutivePdf = (empresaId) => http.get('/operations/report-pdf', {
  params: { empresa_id: empresaId },
  responseType: 'blob',
}).then((r) => r.data);
export const fetchSupervisorRealtime = (params) => http.get('/operations/supervisor', { params }).then((r) => r.data);
export const fetchNotifications = (params) => http.get('/notifications', { params }).then((r) => r.data);
export const markNotificationRead = (id) => http.post(`/notifications/${id}/read`).then((r) => r.data);
export const fetchAuditLogs = (params) => http.get('/audit-logs', { params }).then((r) => r.data);
export const fetchKnowledgeDocuments = (params) => http.get('/knowledge/documents', { params }).then((r) => r.data);
export const uploadKnowledgeDocument = (payload) => {
  const form = new FormData();
  form.append('empresa_id', payload.empresa_id);
  form.append('title', payload.title);
  form.append('document', payload.document);
  return http.post('/knowledge/documents', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }).then((r) => r.data);
};
export const askKnowledgeBase = (payload) => http.post('/knowledge/ask', payload).then((r) => r.data);
export const fetchPlaybooks = (params) => http.get('/playbooks', { params }).then((r) => r.data);
export const upsertPlaybook = (payload) => http.post('/playbooks', payload).then((r) => r.data);
