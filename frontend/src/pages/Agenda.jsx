import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, CheckCircle2, Clock3, UserRoundX } from 'lucide-react';
import { confirmInterview, fetchInterviews, markInterviewNoShow } from '../lib/api';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { useSession } from '../context/SessionContext';

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

export default function Agenda() {
  const { activeCompanyId: empresaId } = useSession();
  const [date, setDate] = useState(todayIso());
  const queryClient = useQueryClient();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['interviews', empresaId, date],
    queryFn: () => fetchInterviews({ empresa_id: empresaId, date }),
    enabled: Boolean(empresaId),
    staleTime: 30_000,
  });

  const confirmMutation = useMutation({
    mutationFn: (id) => confirmInterview(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['interviews', empresaId, date] }),
  });

  const noShowMutation = useMutation({
    mutationFn: (id) => markInterviewNoShow(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['interviews', empresaId, date] }),
  });

  const items = data?.items ?? [];
  const summary = useMemo(() => ({
    total: items.length,
    confirmadas: items.filter((item) => item.status === 'confirmada').length,
    noShow: items.filter((item) => item.status === 'no_show').length,
  }), [items]);

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="font-display text-2xl font-bold text-text">Agenda</h1>
          <p className="text-sm text-textMuted">Entrevistas del día, confirmaciones y no-show</p>
        </div>
        <input
          type="date"
          value={date}
          onChange={(event) => setDate(event.target.value)}
          className="rounded-lg text-sm bg-surface border border-border px-3 py-2 text-text focus:outline-none focus:border-primary/60"
        />
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">Entrevistas</span>
            <CalendarDays size={16} className="text-primary" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary.total}</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">Confirmadas</span>
            <CheckCircle2 size={16} className="text-success" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary.confirmadas}</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <span className="text-sm text-textMuted">No show</span>
            <UserRoundX size={16} className="text-danger" />
          </div>
          <p className="text-3xl font-display text-text mt-3">{summary.noShow}</p>
        </Card>
      </div>

      <Card>
        <div className="flex items-center gap-2 mb-4">
          <Clock3 size={16} className="text-primary" />
          <h3 className="text-sm font-semibold text-text">Agenda del día</h3>
        </div>

        {isLoading ? (
          <div className="flex items-center justify-center h-40">
            <div className="w-7 h-7 border-2 border-primary border-t-transparent rounded-full animate-spin" />
          </div>
        ) : isError ? (
          <p className="text-red-400 text-sm">No se pudo cargar la agenda.</p>
        ) : items.length === 0 ? (
          <p className="text-textMuted text-sm">No hay entrevistas para esta fecha.</p>
        ) : (
          <div className="space-y-3">
            {items.map((item) => (
              <div key={item.id} className="rounded-xl border border-border p-4 bg-bg/40">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                  <div>
                    <div className="flex items-center gap-2 mb-2">
                      <p className="text-sm font-semibold text-text">{item.lead_nombre}</p>
                      <Badge value={item.lead_prioridad} />
                    </div>
                    <p className="text-sm text-textMuted">{item.interview_time} · {item.office_location}</p>
                    <p className="text-xs text-textSubtle mt-1">{item.recruiter_nombre ?? 'Sin reclutador'} · {item.lead_telefono ?? 'Sin teléfono'}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="px-2.5 py-1 rounded-full text-xs bg-surfaceHover text-text border border-border">
                      {item.status}
                    </span>
                    <button
                      className="btn-ghost"
                      onClick={() => confirmMutation.mutate(item.id)}
                      disabled={confirmMutation.isPending || item.status === 'confirmada'}
                    >
                      Confirmar
                    </button>
                    <button
                      className="btn-ghost"
                      onClick={() => noShowMutation.mutate(item.id)}
                      disabled={noShowMutation.isPending || item.status === 'no_show'}
                    >
                      No show
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
