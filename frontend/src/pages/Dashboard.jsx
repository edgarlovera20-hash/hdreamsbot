import { useQuery } from '@tanstack/react-query';
import { fetchKPIs, fetchHorasPico, fetchCola } from '../lib/api';
import { KPIGrid } from '../components/dashboard/KPIGrid';
import { HoursChart } from '../components/dashboard/HoursChart';
import { LeadQueue } from '../components/dashboard/LeadQueue';
import { useSession } from '../context/SessionContext';
import { useProductionSimulator } from '../context/ProductionSimulatorContext';

export default function Dashboard({ seccionId = 1 }) {
  const { activeCompanyId: empresaId } = useSession();
  const { enabled, scenarioMeta, liveMetrics, simulateRequest, simulateKpis, simulateHoursPico, simulateQueue } = useProductionSimulator();
  const params = { empresa_id: empresaId, seccion_id: seccionId };

  const { data: kpis, isLoading, isError } = useQuery({
    queryKey: ['kpis', empresaId, seccionId, enabled, scenarioMeta.label],
    queryFn: () => simulateRequest(() => fetchKPIs(params)).then(simulateKpis),
    enabled: Boolean(empresaId),
    refetchInterval: 60_000,
  });

  const { data: horasPico } = useQuery({
    queryKey: ['horas-pico', empresaId, seccionId, enabled, scenarioMeta.label],
    queryFn: () => simulateRequest(() => fetchHorasPico(params)).then(simulateHoursPico),
    enabled: Boolean(empresaId),
    refetchInterval: 300_000,
  });

  const { data: cola } = useQuery({
    queryKey: ['cola', empresaId, enabled, scenarioMeta.label],
    queryFn: () => simulateRequest(() => fetchCola({ empresa_id: empresaId, limite: 20 }).then((d) => d?.leads ?? d)).then(simulateQueue),
    enabled: Boolean(empresaId),
    refetchInterval: 30_000,
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-red-400 text-sm">Error al cargar los datos. Verifica la conexión con el backend.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div>
          <h1 className="font-display text-2xl font-bold text-text">Dashboard</h1>
          <p className="text-sm text-textMuted">Métricas en tiempo real · {new Date().toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })}</p>
        </div>
        {enabled && (
          <div className="flex flex-wrap gap-2 text-xs text-textMuted">
            <span className="rounded-full border border-primary/20 bg-primary/10 px-2.5 py-1 text-primary">{scenarioMeta.label}</span>
            <span className="rounded-full border border-border px-2.5 py-1 bg-surfaceHover text-text">{liveMetrics.requestsPerMinute} rpm</span>
            <span className="rounded-full border border-border px-2.5 py-1 bg-surfaceHover text-text">SLA risk {liveMetrics.slaRisk}%</span>
          </div>
        )}
      </div>

      <KPIGrid data={kpis} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <HoursChart data={horasPico ?? []} />
        <LeadQueue leads={Array.isArray(cola) ? cola : []} />
      </div>
    </div>
  );
}
