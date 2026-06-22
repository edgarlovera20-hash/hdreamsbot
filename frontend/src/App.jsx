import { BrowserRouter, Routes, Route, NavLink } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { LayoutDashboard, Users, BarChart2 } from 'lucide-react';
import Dashboard from './pages/Dashboard';
import Leads     from './pages/Leads';
import Stats     from './pages/Stats';

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, staleTime: 30_000 } },
});

const NAV = [
  { to: '/',       label: 'Dashboard', icon: LayoutDashboard },
  { to: '/leads',  label: 'Leads',     icon: Users },
  { to: '/stats',  label: 'Stats',     icon: BarChart2 },
];

function Layout({ children }) {
  return (
    <div className="min-h-screen bg-bg flex">
      {/* Sidebar */}
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
        <div className="p-4 border-t border-border">
          <p className="text-2xs text-textSubtle">v1.0.0 · HDreams 2026</p>
        </div>
      </aside>

      {/* Main */}
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
      <BrowserRouter>
        <Layout>
          <Routes>
            <Route path="/"      element={<Dashboard />} />
            <Route path="/leads" element={<Leads />} />
            <Route path="/stats" element={<Stats />} />
          </Routes>
        </Layout>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
