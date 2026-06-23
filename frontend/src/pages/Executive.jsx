import { useQuery } from '@tanstack/react-query';
import { Activity, AlertTriangle, Building2, CheckCircle2, Clock3, Landmark, MapPinned, Radar, Siren, Target, TrendingUp, UserSquare2 } from 'lucide-react';
import { fetchExecutiveDashboard } from '../lib/api';
import { useSession } from '../context/SessionContext';
import { useProductionSimulator } from '../context/ProductionSimulatorContext';
import { Card } from '../components/ui/Card';

function SummaryCard({ icon: Icon, label, value, tone = 'primary' }) {
  const tones = {
    primary: 'text-primary',
    warning: 'text-warning',
    success: 'text-success',
  };

  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <span className="text-sm text-textMuted">{label}</span>
        <Icon size={16} className={tones[tone] ?? tones.primary} />
      </div>
      <p className="mt-3 font-display text-3xl text-text">{value}</p>
    </Card>
  );
}

export default function Executive() {
  const { activeCompanyId, currentCompany } = useSession();
  const { enabled, scenarioMeta, liveMetrics, simulateRequest, simulateExecutive } = useProductionSimulator();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['executive-dashboard', activeCompanyId, enabled, scenarioMeta.label],
    queryFn: () => simulateRequest(() => fetchExecutiveDashboard({ empresa_id: activeCompanyId })).then(simulateExecutive),
    enabled: Boolean(activeCompanyId),
    refetchInterval: 20_000,
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (isError || !data) {
    return <div className="flex items-center justify-center h-64"><p className="text-sm text-danger">No se pudo cargar el tablero ejecutivo.</p></div>;
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.24em] text-primary">Fase 4</p>
          <h1 className="font-display text-2xl font-bold text-text">Executive Command Center</h1>
          <p className="text-sm text-textMuted">Tiempo real para {currentCompany?.empresa_nombre ?? 'tu operación'}: SLA, recruiter load y embudo vivo.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-xs text-textMuted">
          <span className="inline-flex items-center gap-2"><Building2 size={14} /> {currentCompany?.empresa_nombre ?? 'Sin empresa'}</span>
          {enabled && <span className="rounded-full border border-primary/20 bg-primary/10 px-2.5 py-1 text-primary">{scenarioMeta.label}</span>}
          {enabled && <span className="rounded-full border border-border px-2.5 py-1 bg-surfaceHover text-text">CPU {liveMetrics.cpuLoad}%</span>}
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">
        <SummaryCard icon={Activity} label="Leads activos" value={data.summary.active_leads} />
        <SummaryCard icon={AlertTriangle} label="SLA vencido" value={data.summary.sla_overdue} tone="warning" />
        <SummaryCard icon={Clock3} label="Pendientes de respuesta" value={data.summary.pending_reply} tone="warning" />
        <SummaryCard icon={UserSquare2} label="Entrevistas hoy" value={data.summary.interviews_today} />
        <SummaryCard icon={CheckCircle2} label="Confirmadas hoy" value={data.summary.confirmed_today} tone="success" />
        <SummaryCard icon={CheckCircle2} label="Contratados mes" value={data.summary.hires_month} tone="success" />
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <SummaryCard icon={TrendingUp} label="Forecast 30d" value={data.forecast.projected_hires_next_30d} tone="success" />
        <SummaryCard icon={Landmark} label="Vacantes activas" value={data.finance.length} />
        <SummaryCard icon={MapPinned} label="Sedes activas" value={data.sites.length} />
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <SummaryCard icon={Siren} label="No-show alto riesgo" value={data.predictive.summary.high_risk_no_show} tone="warning" />
        <SummaryCard icon={Radar} label="Reactivaciones calientes" value={data.predictive.summary.hot_reactivation} tone="warning" />
        <SummaryCard icon={Target} label="Cierres prioritarios" value={data.predictive.summary.priority_candidates} tone="success" />
        <SummaryCard icon={AlertTriangle} label="Vacantes con cuello" value={data.predictive.summary.bottleneck_vacancies} tone="warning" />
      </div>

      <Card>
        <h3 className="text-sm font-semibold text-text mb-4">Coach ejecutivo Fase 10</h3>
        <div className="grid grid-cols-1 xl:grid-cols-4 gap-3">
          {data.predictive.coach_actions.map((action) => (
            <div key={`${action.type}-${action.title}`} className="rounded-xl border border-border bg-bg/40 p-4">
              <p className="text-xs uppercase tracking-[0.2em] text-primary">{action.type.replaceAll('_', ' ')}</p>
              <p className="mt-2 text-sm font-semibold text-text">{action.title}</p>
              <p className="mt-2 text-xs leading-5 text-textMuted">{action.detail}</p>
            </div>
          ))}
        </div>
      </Card>

      <div className="grid grid-cols-1 xl:grid-cols-[0.9fr_1.1fr] gap-6">
        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Embudo operativo vivo</h3>
          <div className="space-y-3">
            {data.funnel.map((stage) => (
              <div key={stage.current_stage} className="rounded-xl border border-border bg-bg/40 p-4 flex items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-medium text-text">{stage.current_stage}</p>
                  <p className="text-xs text-textMuted">Leads en esta etapa</p>
                </div>
                <p className="font-display text-2xl text-primary">{stage.total}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">SLA por recruiter</h3>
          <div className="space-y-3">
            {data.recruiters.map((recruiter) => (
              <div key={recruiter.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{recruiter.nombre}</p>
                    <p className="text-xs text-textMuted">{recruiter.active_leads} leads activos · {recruiter.interviews_today} entrevistas hoy</p>
                  </div>
                  <div className="flex flex-wrap gap-2 text-xs">
                    <span className="px-2.5 py-1 rounded-full bg-success/10 text-success border border-success/20">{recruiter.sla_compliance_pct}% SLA</span>
                    <span className="px-2.5 py-1 rounded-full bg-warning/10 text-warning border border-warning/20">{recruiter.sla_overdue} vencidos</span>
                    <span className="px-2.5 py-1 rounded-full bg-primary/10 text-primary border border-primary/20">{recruiter.upcoming_followups} próximos</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[1fr_1fr] gap-6">
        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Watchlist no-show</h3>
          <div className="space-y-3">
            {data.predictive.no_show_watchlist.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{item.nombre ?? `Lead ${item.lead_id}`}</p>
                    <p className="text-xs text-textMuted">
                      {item.interview_date} · {item.interview_time} · {item.recruiter_nombre ?? 'Sin recruiter'} · {item.office_location}
                    </p>
                  </div>
                  <div className="flex gap-2 text-xs">
                    <span className="px-2.5 py-1 rounded-full bg-warning/10 text-warning border border-warning/20">{item.risk_score}/99</span>
                    <span className="px-2.5 py-1 rounded-full bg-surfaceHover text-text border border-border">{item.risk_bucket}</span>
                  </div>
                </div>
                <p className="mt-3 text-xs text-textMuted">{item.risk_factors.join(' · ') || 'Sin banderas relevantes'}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Reactivación prioritaria</h3>
          <div className="space-y-3">
            {data.predictive.reactivation_queue.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{item.nombre ?? `Lead ${item.id}`}</p>
                    <p className="text-xs text-textMuted">{item.vacante} · {item.current_stage} · {item.recruiter_nombre ?? 'Sin asignar'}</p>
                  </div>
                  <div className="flex gap-2 text-xs">
                    <span className="px-2.5 py-1 rounded-full bg-primary/10 text-primary border border-primary/20">{item.reactivation_score}/99</span>
                    <span className="px-2.5 py-1 rounded-full bg-surfaceHover text-text border border-border">{item.hours_silent}h sin movimiento</span>
                  </div>
                </div>
                <p className="mt-3 text-xs text-textMuted">{item.reactivation_factors.join(' · ') || 'Sin factores adicionales'}</p>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.8fr_1.2fr] gap-6">
        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Mix por canal</h3>
          <div className="space-y-3">
            {data.channels.map((channel) => (
              <div key={channel.canal} className="rounded-xl border border-border bg-bg/40 p-4 flex items-center justify-between gap-3">
                <p className="text-sm font-medium text-text">{channel.canal}</p>
                <p className="font-display text-2xl text-text">{channel.total}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Cola en vivo</h3>
          <div className="space-y-3">
            {data.live_queue.map((lead) => (
              <div key={lead.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{lead.nombre ?? `Lead ${lead.id}`}</p>
                    <p className="text-xs text-textMuted">{lead.current_stage} · {lead.canal} · {lead.recruiter_nombre ?? 'Sin asignar'}</p>
                  </div>
                  <div className="flex gap-2 text-xs">
                    <span className="px-2.5 py-1 rounded-full bg-surfaceHover text-text border border-border">{lead.prioridad}</span>
                    {lead.next_action_at && <span className="px-2.5 py-1 rounded-full bg-warning/10 text-warning border border-warning/20">{new Date(lead.next_action_at).toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[1fr_1fr] gap-6">
        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Top candidatos para cierre</h3>
          <div className="space-y-3">
            {data.predictive.top_candidates.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{item.nombre ?? `Lead ${item.id}`}</p>
                    <p className="text-xs text-textMuted">{item.vacante} · {item.current_stage} · {item.recruiter_nombre ?? 'Sin recruiter'}</p>
                  </div>
                  <div className="flex gap-2 text-xs">
                    <span className="px-2.5 py-1 rounded-full bg-success/10 text-success border border-success/20">{item.hire_readiness_score}/99</span>
                    <span className="px-2.5 py-1 rounded-full bg-surfaceHover text-text border border-border">{item.interview_status}</span>
                  </div>
                </div>
                <p className="mt-3 text-xs text-textMuted">{item.readiness_factors.join(' · ') || 'Sin factores adicionales'}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Cuellos de botella por vacante</h3>
          <div className="space-y-3">
            {data.predictive.vacancy_bottlenecks.map((item) => (
              <div key={item.vacancy} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{item.vacancy}</p>
                    <p className="text-xs text-textMuted">
                      {item.active_leads} activos · {item.qualified_leads} calificados · {item.hires_30d} contratados 30d
                    </p>
                  </div>
                  <div className="flex gap-2 text-xs">
                    <span className="px-2.5 py-1 rounded-full bg-warning/10 text-warning border border-warning/20">{item.bottleneck_score}/99</span>
                    <span className="px-2.5 py-1 rounded-full bg-surfaceHover text-text border border-border">{item.bottleneck_label}</span>
                  </div>
                </div>
                <div className="mt-3 flex flex-wrap gap-2 text-xs">
                  <span className="px-2.5 py-1 rounded-full bg-primary/10 text-primary border border-primary/20">{item.overdue_followups} vencidos</span>
                  <span className="px-2.5 py-1 rounded-full bg-surfaceHover text-text border border-border">{item.avg_idle_hours}h idle promedio</span>
                  <span className="px-2.5 py-1 rounded-full bg-danger/10 text-danger border border-danger/20">{item.no_show_leads} no-show</span>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Finanzas por vacante</h3>
          <div className="space-y-3">
            {data.finance.map((item) => (
              <div key={item.vacancy} className="rounded-xl border border-border bg-bg/40 p-4 flex items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-semibold text-text">{item.vacancy}</p>
                  <p className="text-xs text-textMuted">Costo por contratación {item.cost_per_hire}</p>
                </div>
                <div className="text-right">
                  <p className="text-sm font-semibold text-text">{item.open_positions} vacantes</p>
                  <p className="text-xs text-textMuted">ROI {item.roi_index}</p>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
