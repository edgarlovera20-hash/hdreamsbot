import { useQuery } from '@tanstack/react-query';
import { Briefcase, CalendarClock, CircleHelp, GitBranch, MapPin, Tags } from 'lucide-react';
import { fetchFlowFunnel, fetchFlowOverview } from '../lib/api';
import { Card } from '../components/ui/Card';
import { Metric } from '../components/ui/Metric';
import { Badge } from '../components/ui/Badge';
import { useSession } from '../context/SessionContext';

export default function Flow() {
  const { activeCompanyId: empresaId } = useSession();
  const { data: overview, isLoading, isError } = useQuery({
    queryKey: ['flow-overview'],
    queryFn: fetchFlowOverview,
    staleTime: 300_000,
  });

  const { data: funnel } = useQuery({
    queryKey: ['flow-funnel', empresaId],
    queryFn: () => fetchFlowFunnel({ empresa_id: empresaId }),
    enabled: Boolean(empresaId),
    staleTime: 60_000,
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (isError || !overview) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-red-400 text-sm">No se pudo cargar el flujo maestro.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 className="font-display text-2xl font-bold text-text">Flujo Maestro</h1>
          <p className="text-sm text-textMuted">Heavenly Dreams 2026 · operacion, mensajes y embudo en una sola vista</p>
        </div>
        <div className="flex items-center gap-2 text-xs text-textMuted">
          <MapPin size={14} />
          <span>{overview.company.office_address.join(', ')}</span>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        {(funnel?.stages ?? []).slice(0, 4).map((stage, index) => (
          <Metric key={stage.key} label={stage.label} value={stage.value} delay={index * 0.05} />
        ))}
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <Card className="xl:col-span-2">
          <div className="flex items-center gap-2 mb-4">
            <GitBranch size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Etapas operativas</h3>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {overview.stages.map((stage, index) => (
              <div key={stage.key} className="rounded-xl border border-border p-4 bg-bg/40">
                <div className="flex items-center justify-between gap-3 mb-2">
                  <span className="text-xs text-textSubtle">Etapa {index + 1}</span>
                  <span className="text-xs text-primary font-medium uppercase tracking-wide">{stage.key.replace(/_/g, ' ')}</span>
                </div>
                <h4 className="text-sm font-semibold text-text">{stage.title}</h4>
                <p className="text-sm text-textMuted mt-1">{stage.summary}</p>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <CalendarClock size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Horarios de entrevista</h3>
          </div>
          <div className="flex flex-wrap gap-2">
            {overview.interview_slots.map((slot) => (
              <span key={slot} className="px-2.5 py-1 rounded-full text-xs bg-primary/10 text-primary border border-primary/20">
                {slot}
              </span>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Briefcase size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Vacantes y criterios</h3>
          </div>
          <div className="space-y-4">
            {overview.vacancies.map((vacancy) => (
              <div key={vacancy.slug} className="rounded-xl border border-border p-4 bg-bg/40">
                <div className="flex items-start justify-between gap-3 mb-2">
                  <div>
                    <h4 className="text-sm font-semibold text-text">{vacancy.name}</h4>
                    <p className="text-xs text-textMuted">${vacancy.weekly_salary.toLocaleString('es-MX')} semanales · {vacancy.age_min}-{vacancy.age_max} anos</p>
                  </div>
                </div>
                <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Requisitos</p>
                <div className="flex flex-wrap gap-2 mb-3">
                  {vacancy.requirements.map((item) => <Badge key={item} value={item} showDot={false} className="text-[11px]" />)}
                </div>
                <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Actividades</p>
                <p className="text-sm text-textMuted">{vacancy.activities.join(' · ')}</p>
              </div>
            ))}
          </div>
        </Card>

        <div className="space-y-6">
          <Card>
            <div className="flex items-center gap-2 mb-4">
              <Tags size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Variables y etiquetas CRM</h3>
            </div>
            <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Variables</p>
            <div className="flex flex-wrap gap-2 mb-4">
              {overview.system_variables.map((item) => (
                <span key={item} className="px-2.5 py-1 rounded-full text-xs bg-surfaceHover text-text border border-border">
                  {`{{${item}}}`}
                </span>
              ))}
            </div>
            <p className="text-xs uppercase tracking-wide text-textSubtle mb-2">Etiquetas</p>
            <div className="flex flex-wrap gap-2">
              {overview.crm_tags.map((item) => (
                <span key={item} className="px-2.5 py-1 rounded-full text-xs bg-primary/10 text-primary border border-primary/20">
                  {item}
                </span>
              ))}
            </div>
          </Card>

          <Card>
            <div className="flex items-center gap-2 mb-4">
              <CircleHelp size={16} className="text-primary" />
              <h3 className="text-sm font-semibold text-text">Preguntas frecuentes</h3>
            </div>
            <div className="space-y-3">
              {overview.faq.map((item) => (
                <div key={item.question} className="rounded-xl border border-border p-4 bg-bg/40">
                  <p className="text-sm font-medium text-text">{item.question}</p>
                  <p className="text-sm text-textMuted mt-1">{item.answer}</p>
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
