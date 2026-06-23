import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, FileText, Landmark, MapPinned, TrendingUp } from 'lucide-react';
import { downloadExecutivePdf, fetchExecutiveReport } from '../lib/api';
import { useSession } from '../context/SessionContext';
import { Card } from '../components/ui/Card';

function money(value) {
  return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(Number(value ?? 0));
}

export default function Reports() {
  const { activeCompanyId, currentCompany } = useSession();
  const pdfMutation = useMutation({
    mutationFn: () => downloadExecutivePdf(activeCompanyId),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener,noreferrer');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    },
  });
  const { data, isLoading, isError } = useQuery({
    queryKey: ['executive-report', activeCompanyId],
    queryFn: () => fetchExecutiveReport({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
    staleTime: 60_000,
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (isError || !data) {
    return <div className="flex items-center justify-center h-64"><p className="text-sm text-danger">No se pudo cargar el reporte ejecutivo.</p></div>;
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.24em] text-primary">Fase 8</p>
          <h1 className="font-display text-2xl font-bold text-text">Reportes y Forecast</h1>
          <p className="text-sm text-textMuted">PDF ejecutivo, forecast de contratación, finanzas por vacante y multi-sede para {currentCompany?.empresa_nombre ?? 'tu operación'}.</p>
        </div>
        <div className="flex gap-2">
          <a
            href="#"
            onClick={(event) => {
              event.preventDefault();
              pdfMutation.mutate();
            }}
            className="btn-primary inline-flex items-center gap-2"
          >
            <Download size={14} />
            Exportar PDF
          </a>
        </div>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.75fr_1.25fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <TrendingUp size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Forecast 30 días</h3>
          </div>
          <div className="space-y-3">
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <p className="text-xs text-textSubtle uppercase tracking-wide">Conversión calificado → contratado</p>
              <p className="mt-2 font-display text-3xl text-primary">{data.forecast.conversion_qualified_to_hire_pct}%</p>
            </div>
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <p className="text-xs text-textSubtle uppercase tracking-wide">Pipeline calificado activo</p>
              <p className="mt-2 font-display text-3xl text-text">{data.forecast.active_qualified_pipeline}</p>
            </div>
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <p className="text-xs text-textSubtle uppercase tracking-wide">Proyección contrataciones</p>
              <p className="mt-2 font-display text-3xl text-success">{data.forecast.projected_hires_next_30d}</p>
            </div>
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Landmark size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Finanzas por vacante</h3>
          </div>
          <div className="space-y-3">
            {data.finance.map((item) => (
              <div key={item.vacancy} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{item.vacancy}</p>
                    <p className="text-xs text-textMuted">{item.active_leads} leads activos · {item.hires_month} contratados este mes</p>
                  </div>
                  <div className="text-right">
                    <p className="text-xs text-textSubtle">Salario semanal</p>
                    <p className="text-sm font-semibold text-text">{money(item.weekly_salary)}</p>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[1fr_1fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <MapPinned size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Red multi-sede</h3>
          </div>
          <div className="space-y-3">
            {data.sites.map((site) => (
              <div key={site.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <p className="text-sm font-semibold text-text">{site.nombre}</p>
                <p className="text-xs text-textMuted">{site.ciudad ?? 'Sin ciudad'} · {site.direccion ?? 'Sin dirección'}</p>
                <div className="mt-3 flex flex-wrap gap-2 text-xs">
                  <span className="px-2.5 py-1 rounded-full bg-primary/10 text-primary border border-primary/20">{site.leads_total} leads</span>
                  <span className="px-2.5 py-1 rounded-full bg-success/10 text-success border border-success/20">{site.hires_total} contratados</span>
                  <span className="px-2.5 py-1 rounded-full bg-warning/10 text-warning border border-warning/20">{site.interviews_pending} entrevistas pendientes</span>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <FileText size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Resumen exportable</h3>
          </div>
          <pre className="rounded-xl border border-border bg-bg/40 p-4 text-xs text-textSubtle overflow-auto max-h-[30rem] whitespace-pre-wrap break-words">
            {JSON.stringify(data, null, 2)}
          </pre>
        </Card>
      </div>
    </div>
  );
}
