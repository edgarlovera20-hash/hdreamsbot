import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, CheckCheck, ShieldAlert } from 'lucide-react';
import { fetchAuditLogs, fetchNotifications, markNotificationRead } from '../lib/api';
import { useSession } from '../context/SessionContext';
import { Card } from '../components/ui/Card';

function severityTone(severity) {
  if (severity === 'critical') return 'bg-danger/10 text-danger border-danger/20';
  if (severity === 'warning') return 'bg-warning/10 text-warning border-warning/20';
  return 'bg-primary/10 text-primary border-primary/20';
}

export default function Alerts() {
  const { activeCompanyId } = useSession();
  const queryClient = useQueryClient();

  const notificationsQuery = useQuery({
    queryKey: ['notifications', activeCompanyId],
    queryFn: () => fetchNotifications({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
    refetchInterval: 20_000,
  });

  const auditQuery = useQuery({
    queryKey: ['audit-logs', activeCompanyId],
    queryFn: () => fetchAuditLogs({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
    refetchInterval: 30_000,
  });

  const readMutation = useMutation({
    mutationFn: (id) => markNotificationRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications', activeCompanyId] }),
  });

  const items = notificationsQuery.data?.items ?? [];
  const unread = notificationsQuery.data?.unread ?? 0;
  const auditItems = auditQuery.data?.items ?? [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.24em] text-primary">Fase 5</p>
          <h1 className="font-display text-2xl font-bold text-text">Alertas y Auditoría</h1>
          <p className="text-sm text-textMuted">Notificaciones operativas y trazabilidad completa de acciones.</p>
        </div>
        <div className="inline-flex items-center gap-2 rounded-full border border-warning/30 bg-warning/10 px-3 py-1.5 text-sm text-warning">
          <Bell size={14} />
          {unread} sin leer
        </div>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.9fr_1.1fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <ShieldAlert size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Centro de notificaciones</h3>
          </div>
          <div className="space-y-3">
            {items.length === 0 ? (
              <p className="text-sm text-textMuted">Sin alertas pendientes.</p>
            ) : items.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className={`inline-flex rounded-full border px-2.5 py-1 text-xs ${severityTone(item.severity)}`}>{item.severity}</div>
                    <p className="mt-3 text-sm font-semibold text-text">{item.title}</p>
                    <p className="mt-1 text-sm text-textMuted">{item.message}</p>
                    <p className="mt-2 text-xs text-textSubtle">{new Date(item.created_at).toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
                  </div>
                  {!item.read_at && (
                    <button
                      type="button"
                      className="btn-ghost inline-flex items-center gap-2"
                      onClick={() => readMutation.mutate(item.id)}
                      disabled={readMutation.isPending}
                    >
                      <CheckCheck size={14} />
                      Leída
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Bell size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Bitácora de auditoría</h3>
          </div>
          <div className="space-y-3 max-h-[42rem] overflow-auto">
            {auditItems.length === 0 ? (
              <p className="text-sm text-textMuted">Sin eventos auditados todavía.</p>
            ) : auditItems.map((item) => (
              <div key={item.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{item.action}</p>
                    <p className="text-xs text-textMuted">{item.user_nombre ?? 'system'} · {item.entity_type} #{item.entity_id ?? 'n/a'}</p>
                    {Object.keys(item.details ?? {}).length > 0 && (
                      <pre className="mt-2 whitespace-pre-wrap break-words text-xs text-textSubtle">{JSON.stringify(item.details, null, 2)}</pre>
                    )}
                  </div>
                  <p className="text-xs text-textSubtle">{new Date(item.created_at).toLocaleString('es-MX', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}
