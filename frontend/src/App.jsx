import { BrowserRouter, Routes, Route, NavLink } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Activity, BellRing, Bot, BriefcaseBusiness, CalendarDays, BarChart2, FlaskConical, LayoutDashboard, Layers3, LogOut, Shield, Users, Workflow, FileSpreadsheet, Radar, X } from 'lucide-react';
import Dashboard from './pages/Dashboard';
import Leads from './pages/Leads';
import Stats from './pages/Stats';
import Flow from './pages/Flow';
import LeadDetail from './pages/LeadDetail';
import Agenda from './pages/Agenda';
import AccountsPanel from './pages/AccountsPanel';
import Executive from './pages/Executive';
import Login from './pages/Login';
import Alerts from './pages/Alerts';
import Automations from './pages/Automations';
import Reports from './pages/Reports';
import Supervisor from './pages/Supervisor';
import Permissions from './pages/Permissions';
import ProductionSimulator from './pages/ProductionSimulator';
import { SessionProvider, useSession } from './context/SessionContext';
import { ProductionSimulatorProvider, useProductionSimulator } from './context/ProductionSimulatorContext';

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, staleTime: 30_000 } },
});

const NAV = [
  { to: '/executive', label: 'Executive', icon: BriefcaseBusiness, permission: 'executive.view' },
  { to: '/production', label: 'Simulación', icon: FlaskConical },
  { to: '/automations', label: 'Automations', icon: Bot, permission: 'executive.view' },
  { to: '/alerts', label: 'Alertas', icon: BellRing, permission: 'notifications.view' },
  { to: '/reports', label: 'Reports', icon: FileSpreadsheet, permission: 'executive.view' },
  { to: '/supervisor', label: 'Supervisor', icon: Radar, permission: 'executive.view' },
  { to: '/permissions', label: 'Permisos', icon: Shield, permission: 'accounts.manage' },
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view' },
  { to: '/leads', label: 'Leads', icon: Users, permission: 'leads.view' },
  { to: '/agenda', label: 'Agenda', icon: CalendarDays, permission: 'interviews.manage' },
  { to: '/accounts', label: 'Cuentas', icon: Layers3, permission: 'leads.view' },
  { to: '/stats', label: 'Stats', icon: BarChart2, permission: 'dashboard.view' },
  { to: '/flow', label: 'Flujo', icon: Workflow, permission: 'flow.view' },
];

function ProductionSimulatorDock() {
  const {
    enabled,
    panelOpen,
    togglePanel,
    setEnabled,
    setScenario,
    scenario,
    scenarios,
    latencyMs,
    setLatencyMs,
    liveMetrics,
    incidents,
  } = useProductionSimulator();

  return (
    <>
      <button
        type="button"
        onClick={togglePanel}
        className={`fixed bottom-5 right-5 z-40 rounded-2xl border px-4 py-3 shadow-xl backdrop-blur ${enabled ? 'border-primary/40 bg-primary/15 text-primary' : 'border-border bg-surface/90 text-text'}`}
      >
        <div className="flex items-center gap-3">
          <FlaskConical size={16} />
          <div className="text-left">
            <p className="text-[11px] uppercase tracking-[0.18em]">Prod Sim</p>
            <p className="text-sm font-semibold">{enabled ? scenarios[scenario].label : 'Modo local'}</p>
          </div>
        </div>
      </button>

      {panelOpen && (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/35 backdrop-blur-[1px]">
          <div className="h-full w-full max-w-md border-l border-border bg-bg/96 p-5 shadow-2xl">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs uppercase tracking-[0.24em] text-primary">Production Simulator</p>
                <h3 className="mt-1 font-display text-xl font-semibold text-text">Cabina de simulación</h3>
              </div>
              <button type="button" className="btn-ghost" onClick={togglePanel}>
                <X size={14} />
              </button>
            </div>

            <div className="mt-5 space-y-4">
              <button type="button" className="btn-primary w-full" onClick={() => setEnabled(!enabled)}>
                {enabled ? 'Desactivar simulación' : 'Activar simulación'}
              </button>

              <div>
                <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Escenario</label>
                <select
                  value={scenario}
                  onChange={(event) => setScenario(event.target.value)}
                  className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60"
                >
                  {Object.entries(scenarios).map(([key, item]) => (
                    <option key={key} value={key}>{item.label}</option>
                  ))}
                </select>
              </div>

              <div>
                <div className="mb-2 flex items-center justify-between text-xs text-textSubtle">
                  <span>Latencia simulada</span>
                  <span>{latencyMs} ms</span>
                </div>
                <input type="range" min="120" max="1800" step="20" value={latencyMs} onChange={(event) => setLatencyMs(Number(event.target.value))} className="w-full" />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl border border-border bg-surface/70 p-4">
                  <p className="text-xs text-textMuted">RPM</p>
                  <p className="mt-2 font-display text-2xl text-text">{liveMetrics.requestsPerMinute}</p>
                </div>
                <div className="rounded-xl border border-border bg-surface/70 p-4">
                  <p className="text-xs text-textMuted">Error rate</p>
                  <p className="mt-2 font-display text-2xl text-text">{liveMetrics.errorRate}%</p>
                </div>
                <div className="rounded-xl border border-border bg-surface/70 p-4">
                  <p className="text-xs text-textMuted">Queue</p>
                  <p className="mt-2 font-display text-2xl text-text">{liveMetrics.queueDepth}</p>
                </div>
                <div className="rounded-xl border border-border bg-surface/70 p-4">
                  <p className="text-xs text-textMuted">Lag</p>
                  <p className="mt-2 font-display text-2xl text-text">{liveMetrics.webhookLagSec}s</p>
                </div>
              </div>

              <div>
                <div className="mb-2 flex items-center gap-2 text-sm font-semibold text-text">
                  <Activity size={14} className="text-primary" />
                  Incidentes activos
                </div>
                <div className="space-y-2">
                  {incidents.map((incident) => (
                    <div key={incident.id} className="rounded-xl border border-border bg-surface/70 p-3">
                      <div className="flex items-center justify-between gap-3">
                        <p className="text-sm font-medium text-text">{incident.title}</p>
                        <span className="text-[11px] uppercase tracking-wide text-textSubtle">{incident.severity}</span>
                      </div>
                      <p className="mt-1 text-xs text-textMuted">{incident.detail}</p>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function Layout({ children }) {
  const { companies, activeCompanyId, setActiveCompanyId, user, logout, hasPermission } = useSession();
  const { enabled, scenarioMeta, liveMetrics, togglePanel } = useProductionSimulator();
  const visibleNav = NAV.filter((item) => !item.permission || hasPermission(item.permission));

  return (
    <div className="min-h-screen bg-bg flex">
      <aside className="w-56 border-r border-border shrink-0 flex flex-col">
        <div className="px-5 py-5 border-b border-border">
          <h1 className="font-display text-lg font-bold text-text">HDreams</h1>
          <p className="text-2xs text-textMuted">Bot de reclutamiento</p>
        </div>
        <nav className="flex-1 p-3 space-y-1">
          {visibleNav.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                  isActive
                    ? 'bg-primary/10 text-primary'
                    : 'text-textMuted hover:text-text hover:bg-surfaceHover'
                }`
              }
            >
              <Icon size={16} />
              {label}
            </NavLink>
          ))}
        </nav>
        <div className="p-4 border-t border-border">
          <p className="text-2xs text-textSubtle">v1.0.0 · HDreams 2026</p>
        </div>
      </aside>

      <main className="flex-1 overflow-auto">
        <div className="max-w-screen-xl mx-auto p-6">
          {enabled && (
            <button
              type="button"
              onClick={togglePanel}
              className="mb-4 w-full rounded-2xl border border-primary/30 bg-primary/10 px-4 py-3 text-left transition-colors hover:bg-primary/15"
            >
              <div className="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
                <div>
                  <p className="text-xs uppercase tracking-[0.24em] text-primary">Entorno simulado</p>
                  <p className="text-sm font-semibold text-text">Producción simulada activa: {scenarioMeta.label}</p>
                </div>
                <div className="flex flex-wrap gap-2 text-xs text-textMuted">
                  <span className="rounded-full border border-primary/20 px-2.5 py-1 bg-white/5">{liveMetrics.requestsPerMinute} rpm</span>
                  <span className="rounded-full border border-primary/20 px-2.5 py-1 bg-white/5">error {liveMetrics.errorRate}%</span>
                  <span className="rounded-full border border-primary/20 px-2.5 py-1 bg-white/5">lag {liveMetrics.webhookLagSec}s</span>
                </div>
              </div>
            </button>
          )}

          <div className="mb-6 flex flex-col gap-3 rounded-2xl border border-border bg-surface/70 px-4 py-3 md:flex-row md:items-center md:justify-between">
            <div>
              <p className="text-xs uppercase tracking-[0.24em] text-textSubtle">Sesión activa</p>
              <p className="text-sm font-medium text-text">{user?.nombre ?? 'Sin usuario'} · {user?.email ?? ''}</p>
            </div>
            <div className="flex flex-col gap-3 md:flex-row md:items-center">
              <select
                value={activeCompanyId}
                onChange={(event) => setActiveCompanyId(Number(event.target.value))}
                className="rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60"
              >
                {companies.map((company) => (
                  <option key={company.empresa_id} value={company.empresa_id}>
                    {company.empresa_nombre} · {company.role}
                  </option>
                ))}
              </select>
              <button type="button" className="btn-ghost inline-flex items-center gap-2" onClick={logout}>
                <LogOut size={14} />
                Salir
              </button>
            </div>
          </div>
          {children}
        </div>
      </main>
      <ProductionSimulatorDock />
    </div>
  );
}

function AppShell() {
  const { isAuthenticated, isInitializing } = useSession();

  if (isInitializing) {
    return <div className="min-h-screen bg-bg flex items-center justify-center"><div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (!isAuthenticated) {
    return <Login />;
  }

  return (
    <BrowserRouter>
      <Layout>
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/executive" element={<Executive />} />
          <Route path="/production" element={<ProductionSimulator />} />
          <Route path="/automations" element={<Automations />} />
          <Route path="/alerts" element={<Alerts />} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/supervisor" element={<Supervisor />} />
          <Route path="/permissions" element={<Permissions />} />
          <Route path="/leads" element={<Leads />} />
          <Route path="/leads/:id" element={<LeadDetail />} />
          <Route path="/agenda" element={<Agenda />} />
          <Route path="/accounts" element={<AccountsPanel />} />
          <Route path="/stats" element={<Stats />} />
          <Route path="/flow" element={<Flow />} />
        </Routes>
      </Layout>
    </BrowserRouter>
  );
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <SessionProvider>
        <ProductionSimulatorProvider>
          <AppShell />
        </ProductionSimulatorProvider>
      </SessionProvider>
    </QueryClientProvider>
  );
}
