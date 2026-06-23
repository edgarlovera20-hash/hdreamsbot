import { useQuery } from '@tanstack/react-query';
import { Bot, CalendarClock, MessageSquareDashed, Repeat2 } from 'lucide-react';
import { fetchAutomationOverview } from '../lib/api';
import { useSession } from '../context/SessionContext';
import { Card } from '../components/ui/Card';

function Summary({ icon: Icon, label, value }) {
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <span className="text-sm text-textMuted">{label}</span>
        <Icon size={16} className="text-primary" />
      </div>
      <p className="mt-3 font-display text-3xl text-text">{value}</p>
    </Card>
  );
}

export default function Automations() {
  const { activeCompanyId } = useSession();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['automation-overview', activeCompanyId],
    queryFn: () => fetchAutomationOverview({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
    refetchInterval: 20_000,
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (isError || !data) {
    return <div className="flex items-center justify-center h-64"><p className="text-sm text-danger">No se pudo cargar el centro de automatizaciones.</p></div>;
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <p className="text-xs uppercase tracking-[0.24em] text-primary">Fase 6</p>
        <h1 className="font-display text-2xl font-bold text-text">Automation Center</h1>
        <p className="text-sm text-textMuted">Recordatorios de entrevista, recuperación de no-show y playbooks por vacante.</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <Summary icon={CalendarClock} label="Pendientes 24h" value={data.summary.pending_24h} />
        <Summary icon={CalendarClock} label="Pendientes 2h" value={data.summary.pending_2h} />
        <Summary icon={MessageSquareDashed} label="No-show recovery" value={data.summary.pending_no_show} />
        <Summary icon={Repeat2} label="Playbooks activos" value={data.summary.pending_playbooks} />
      </div>

      <Card>
        <div className="flex items-center gap-2 mb-4">
          <Bot size={16} className="text-primary" />
          <h3 className="text-sm font-semibold text-text">Historial de ejecuciones</h3>
        </div>
        <div className="space-y-3">
          {(data.runs ?? []).length === 0 ? (
            <p className="text-sm text-textMuted">Todavía no hay ejecuciones registradas.</p>
          ) : data.runs.map((run) => (
            <div key={run.id} className="rounded-xl border border-border bg-bg/40 p-4">
              <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                  <p className="text-sm font-semibold text-text">{run.automation_key}</p>
                  <p className="text-xs text-textMuted">Lead #{run.lead_id ?? 'n/a'} · Interview #{run.interview_id ?? 'n/a'} · {run.status}</p>
                </div>
                <p className="text-xs text-textSubtle">{new Date(run.created_at).toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
              </div>
              {Object.keys(run.payload ?? {}).length > 0 && (
                <pre className="mt-3 whitespace-pre-wrap break-words text-xs text-textSubtle">{JSON.stringify(run.payload, null, 2)}</pre>
              )}
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
