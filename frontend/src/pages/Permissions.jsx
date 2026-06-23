import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Shield, ShieldAlert, ShieldCheck } from 'lucide-react';
import { fetchAccessPermissions, updateAccessPermissions } from '../lib/api';
import { useSession } from '../context/SessionContext';
import { Card } from '../components/ui/Card';

export default function Permissions() {
  const queryClient = useQueryClient();
  const { activeCompanyId, currentCompany } = useSession();
  const [selectedUserId, setSelectedUserId] = useState(0);
  const [role, setRole] = useState('viewer');
  const [permissionState, setPermissionState] = useState({});

  const { data, isLoading, isError } = useQuery({
    queryKey: ['access-permissions', activeCompanyId],
    queryFn: () => fetchAccessPermissions({ empresa_id: activeCompanyId }),
    enabled: Boolean(activeCompanyId),
  });

  const users = data?.users ?? [];
  const catalog = data?.catalog ?? [];
  const roles = data?.roles ?? {};
  const selectedUser = users.find((item) => Number(item.user_id) === Number(selectedUserId)) ?? users[0] ?? null;

  useEffect(() => {
    if (!selectedUser && users.length) {
      setSelectedUserId(Number(users[0].user_id));
    }
  }, [selectedUser, users]);

  useEffect(() => {
    if (!selectedUser) return;

    setRole(selectedUser.role ?? 'viewer');
    const nextState = {};
    for (const permission of catalog) {
      nextState[permission.key] = 'inherit';
    }
    for (const override of selectedUser.permission_overrides ?? []) {
      nextState[override.key] = override.effect;
    }
    setPermissionState(nextState);
  }, [catalog, selectedUser]);

  const saveMutation = useMutation({
    mutationFn: (payload) => updateAccessPermissions(payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['access-permissions', activeCompanyId] });
      await queryClient.invalidateQueries({ queryKey: ['auth-me'] });
    },
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64"><div className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" /></div>;
  }

  if (isError || !data) {
    return <div className="flex items-center justify-center h-64"><p className="text-sm text-danger">No se pudo cargar la configuración de permisos.</p></div>;
  }

  const effectiveSet = new Set(selectedUser?.effective_permissions ?? []);

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.24em] text-primary">Seguridad</p>
          <h1 className="font-display text-2xl font-bold text-text">Permisos por empresa</h1>
          <p className="text-sm text-textMuted">Control de acceso para {currentCompany?.empresa_nombre ?? 'la empresa activa'}.</p>
        </div>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[0.9fr_1.1fr] gap-6">
        <Card>
          <h3 className="text-sm font-semibold text-text mb-4">Usuarios con acceso</h3>
          <div className="space-y-3">
            {users.map((user) => {
              const active = Number(user.user_id) === Number(selectedUser?.user_id);
              return (
                <button
                  key={user.user_company_id}
                  type="button"
                  onClick={() => setSelectedUserId(Number(user.user_id))}
                  className={`w-full rounded-xl border p-4 text-left transition-colors ${active ? 'border-primary/40 bg-primary/10' : 'border-border bg-bg/40 hover:bg-surfaceHover'}`}
                >
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-text">{user.nombre}</p>
                      <p className="text-xs text-textMuted">{user.email}</p>
                    </div>
                    <span className="px-2.5 py-1 rounded-full bg-surface text-text text-xs border border-border">{user.role}</span>
                  </div>
                  <p className="mt-2 text-xs text-textMuted">{user.recruiter_nombre ?? 'Sin recruiter vinculado'}</p>
                </button>
              );
            })}
          </div>
        </Card>

        <Card>
          {!selectedUser ? (
            <p className="text-sm text-textMuted">No hay usuarios para esta empresa.</p>
          ) : (
            <>
              <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                  <h3 className="text-sm font-semibold text-text">{selectedUser.nombre}</h3>
                  <p className="text-xs text-textMuted">{selectedUser.email}</p>
                </div>
                <div className="flex items-center gap-2 text-xs text-textMuted">
                  <ShieldCheck size={14} />
                  <span>{selectedUser.effective_permissions?.length ?? 0} permisos efectivos</span>
                </div>
              </div>

              <div className="mt-5">
                <label className="block text-xs uppercase tracking-[0.18em] text-textSubtle mb-2">Rol base</label>
                <select
                  value={role}
                  onChange={(event) => setRole(event.target.value)}
                  className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60"
                >
                  {Object.entries(roles).map(([key, item]) => (
                    <option key={key} value={key}>{item.label}</option>
                  ))}
                </select>
              </div>

              <div className="mt-6 space-y-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-text">
                  <Shield size={16} />
                  Overrides de permisos
                </div>
                {catalog.map((permission) => (
                  <div key={permission.key} className="rounded-xl border border-border bg-bg/40 p-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                      <div>
                        <p className="text-sm font-medium text-text">{permission.label}</p>
                        <p className="text-xs text-textMuted">{permission.description}</p>
                        <p className="mt-1 text-2xs text-textSubtle">{permission.key}</p>
                      </div>
                      <div className="flex items-center gap-2">
                        <select
                          value={permissionState[permission.key] ?? 'inherit'}
                          onChange={(event) => setPermissionState((prev) => ({ ...prev, [permission.key]: event.target.value }))}
                          className="rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60"
                        >
                          <option value="inherit">Heredar</option>
                          <option value="allow">Permitir</option>
                          <option value="deny">Denegar</option>
                        </select>
                        {effectiveSet.has(permission.key) ? (
                          <span className="inline-flex items-center gap-1 rounded-full border border-success/20 bg-success/10 px-2.5 py-1 text-xs text-success">
                            <ShieldCheck size={12} />
                            Activo
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 rounded-full border border-danger/20 bg-danger/10 px-2.5 py-1 text-xs text-danger">
                            <ShieldAlert size={12} />
                            Bloqueado
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              <div className="mt-6 flex justify-end">
                <button
                  type="button"
                  className="btn-primary"
                  disabled={saveMutation.isPending}
                  onClick={() => {
                    const permissions = Object.entries(permissionState)
                      .filter(([, effect]) => effect !== 'inherit')
                      .map(([key, effect]) => ({ key, effect }));

                    saveMutation.mutate({
                      empresa_id: activeCompanyId,
                      user_id: Number(selectedUser.user_id),
                      role,
                      permissions,
                    });
                  }}
                >
                  {saveMutation.isPending ? 'Guardando...' : 'Guardar permisos'}
                </button>
              </div>
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
