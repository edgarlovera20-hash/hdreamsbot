import axios from 'axios';

const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${import.meta.env.VITE_API_SECRET ?? ''}`,
  },
});

export const fetchKPIs     = (params) => http.get('/kpis',         { params }).then((r) => r.data);
export const fetchCola     = (params) => http.get('/kpis/cola',    { params }).then((r) => r.data);
export const fetchKPIHoras = (params) => http.get('/kpis/horas',   { params }).then((r) => r.data);
export const fetchAbTests  = (params) => http.get('/kpis/ab',      { params }).then((r) => r.data);
export const fetchHorasPico = (params) => http.get('/kpis/horas-pico', { params }).then((r) => r.data);
export const fetchLeads    = (params) => http.get('/leads',        { params }).then((r) => r.data);
