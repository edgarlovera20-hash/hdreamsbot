import axios from 'axios';

const BASE = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api';

const http = axios.create({ baseURL: BASE });

// Token dinámico desde localStorage
http.interceptors.request.use((config) => {
  const token = localStorage.getItem('hdreams_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// 401 → limpiar sesión y redirigir al login
http.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401 && !err.config.url.includes('/auth/')) {
      localStorage.removeItem('hdreams_token');
      window.location.href = '/login';
    }
    return Promise.reject(err);
  }
);

// Auth
export const authLogin  = (email, password) =>
  axios.post(`${BASE}/auth/login`, { email, password }).then((r) => r.data);
export const authLogout = () => http.post('/auth/logout').then((r) => r.data);
export const authMe     = () => http.get('/auth/me').then((r) => r.data);
export const authSwitchEmpresa = (empresa_id) =>
  http.post('/auth/empresa', { empresa_id }).then((r) => r.data);

// KPIs y Leads
export const fetchKPIs      = (params) => http.get('/kpis',           { params }).then((r) => r.data);
export const fetchCola      = (params) => http.get('/kpis/cola',      { params }).then((r) => r.data);
export const fetchKPIHoras  = (params) => http.get('/kpis/horas',     { params }).then((r) => r.data);
export const fetchAbTests   = (params) => http.get('/kpis/ab',        { params }).then((r) => r.data);
export const fetchHorasPico = (params) => http.get('/kpis/horas-pico',{ params }).then((r) => r.data);
export const fetchLeads     = (params) => http.get('/leads',          { params }).then((r) => r.data);

// Conversaciones WhatsApp
export const fetchConversaciones = (params) =>
  http.get('/conversaciones',         { params }).then((r) => r.data);
export const fetchMensajes = (wa_id, params) =>
  http.get('/conversaciones/mensajes', { params: { ...params, wa_id } }).then((r) => r.data);
