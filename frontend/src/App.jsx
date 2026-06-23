import { BrowserRouter, Routes, Route, NavLink, Navigate, useNavigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { LayoutDashboard, Users, BarChart2, LogOut, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { AuthProvider, useAuth } from './context/AuthContext';
import Dashboard from './pages/Dashboard';
import Leads     from './pages/Leads';
import Stats     from './pages/Stats';
import Login     from './pages/Login';

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, staleTime: 30_000 } },
});

const NAV = [
  { to: '/',       label: 'Dashboard', icon: LayoutDashboard },
  { to: '/leads',  label: 'Leads',     icon: Users },
  { to: '/stats',  label: 'Stats',     icon: BarChart2 },
];

function ProtectedRoute({ children }) {
  const { user, loading } = useAuth();
  if (loading) {
    return (
      <div className="min-h-screen bg-bg flex items-center justify-center">
        <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }
  if (!user) return <Navigate to="/login" replace />;
  return children;
}

function EmpresaSelector() {
  const { empresas, empresaId, switchEmpresa } = useAuth();
  const [open, setOpen] = useState(false);
  const current = empresas.find((e) => e.id === empresaId);

  if (empresas.length <= 1) {
    return <p className="text-xs text-textMuted truncate">{current?.nombre ?? '—'}</p>;
  }

  return (
    <div className="relative">
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center gap-1 text-xs text-textMuted hover:text-text transition-colors w-full"
      >
        <span className="truncate">{current?.nombre ?? '—'}</span>
        <ChevronDown size={12} className="shrink-0" />
      </button>
      {open && (
        <div className="absolute bottom-6 left-0 w-44 bg-surface border border-border rounded-lg shadow-xl z-50 py-1">
          {empresas.map((e) => (
            <button
              key={e.id}
              onClick={() => { switchEmpresa(e.id); setOpen(false); }}
              className={`w-full text-left px-3 py-2 text-xs transition-colors ${
                e.id === empresaId
                  ? 'text-primary bg-primary/10'
                  : 'text-textMuted hover:text-text hover:bg-surfaceHover'
              }`}
            >
              {e.nombre}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

function Layout({ children }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <div className="min-h-screen bg-bg flex">
      <aside className="w-56 border-r border-border shrink-0 flex flex-col">
        <div className="px-5 py-5 border-b border-border">
          <h1 className="font-display text-lg font-bold text-text">HDreams</h1>
          <p className="text-2xs text-textMuted">Bot de reclutamiento</p>
        </div>

        <nav className="flex-1 p-3 space-y-1">
          {NAV.map(({ to, label, icon: Icon }) => (
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

        <div className="p-4 border-t border-border space-y-3">
          <EmpresaSelector />
          <div className="flex items-center justify-between">
            <div className="min-w-0">
              <p className="text-xs font-medium text-text truncate">{user?.nombre}</p>
              <p className="text-2xs text-textSubtle truncate">{user?.rol}</p>
            </div>
            <button
              onClick={handleLogout}
              title="Cerrar sesión"
              className="text-textSubtle hover:text-danger transition-colors shrink-0 ml-2"
            >
              <LogOut size={15} />
            </button>
          </div>
        </div>
      </aside>

      <main className="flex-1 overflow-auto">
        <div className="max-w-screen-xl mx-auto p-6">
          {children}
        </div>
      </main>
    </div>
  );
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route
              path="/*"
              element={
                <ProtectedRoute>
                  <Layout>
                    <Routes>
                      <Route path="/"      element={<Dashboard />} />
                      <Route path="/leads" element={<Leads />} />
                      <Route path="/stats" element={<Stats />} />
                    </Routes>
                  </Layout>
                </ProtectedRoute>
              }
            />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  );
}
