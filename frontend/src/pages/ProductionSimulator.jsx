import { Activity, AlertTriangle, Bot, Clock3, RadioTower, ShieldCheck, Sparkles, TrendingUp } from 'lucide-react';
import { Card } from '../components/ui/Card';
import { useProductionSimulator } from '../context/ProductionSimulatorContext';

function toneClasses(severity) {
  if (severity === 'critical') return 'bg-danger/10 text-danger border-danger/30';
  if (severity === 'high') return 'bg-warning/10 text-warning border-warning/30';
  if (severity === 'medium') return 'bg-primary/10 text-primary border-primary/30';
  return 'bg-surfaceHover text-text border-border';
}

function statusClasses(status) {
  if (status === 'connected') return 'bg-success/10 text-success border-success/30';
  if (status === 'warning') return 'bg-warning/10 text-warning border-warning/30';
  return 'bg-danger/10 text-danger border-danger/30';
}

export default function ProductionSimulator() {
  const {
    enabled,
    scenario,
    scenarios,
    scenarioMeta,
    latencyMs,
    autoPulse,
    setEnabled,
    setScenario,
    setLatencyMs,
    setAutoPulse,
    liveMetrics,
    incidents,
    channelHealth,
  } = useProductionSimulator();

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div>
          <p className="text-xs uppercase tracking-[0.24em] text-primary">Modo avanzado</p>
          <h1 className="font-display text-2xl font-bold text-text">Simulación de Producción</h1>
          <p className="text-sm text-textMuted">Entorno controlado para simular carga real, backlog, degradación de canales y presión operativa sin salir del entorno local.</p>
        </div>
        <div className="flex flex-wrap gap-2 text-xs">
          <span className={`rounded-full border px-3 py-1 ${enabled ? 'bg-success/10 text-success border-success/30' : 'bg-surfaceHover text-text border-border'}`}>
            {enabled ? 'Simulación activa' : 'Simulación inactiva'}
          </span>
          <span className="rounded-full border border-border px-3 py-1 bg-surfaceHover text-text">{scenarioMeta.label}</span>
          <span className="rounded-full border border-border px-3 py-1 bg-surfaceHover text-text">Latencia base {latencyMs} ms</span>
        </div>
      </div>

      <Card className="p-5">
        <div className="grid grid-cols-1 xl:grid-cols-[1.15fr_0.85fr] gap-6">
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Escenario</label>
                <select
                  value={scenario}
                  onChange={(event) => setScenario(event.target.value)}
                  className="w-full rounded-lg border border-border bg-bg px-3 py-2 text-sm text-text focus:outline-none focus:border-primary/60"
                >
                  {Object.entries(scenarios).map(([key, item]) => (
                    <option key={key} value={key}>{item.label}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs uppercase tracking-wide text-textSubtle mb-2">Latencia inyectada</label>
                <input
                  type="range"
                  min="120"
                  max="1800"
                  step="20"
                  value={latencyMs}
                  onChange={(event) => setLatencyMs(Number(event.target.value))}
                  className="w-full"
                />
                <p className="mt-2 text-xs text-textMuted">{latencyMs} ms añadidos a requests simulados.</p>
              </div>
            </div>

            <div className="flex flex-wrap gap-3">
              <button type="button" className="btn-primary" onClick={() => setEnabled(!enabled)}>
                {enabled ? 'Desactivar simulación' : 'Activar simulación'}
              </button>
              <label className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm text-text">
                <input type="checkbox" checked={autoPulse} onChange={(event) => setAutoPulse(event.target.checked)} />
                Auto pulse operacional
              </label>
            </div>

            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <p className="text-xs uppercase tracking-[0.2em] text-primary">Brief de escenario</p>
              <p className="mt-2 text-sm text-text">{scenarioMeta.incidentLabel}</p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <div className="flex items-center justify-between text-textMuted"><RadioTower size={15} /><span className="text-xs">RPM</span></div>
              <p className="mt-3 font-display text-3xl text-text">{liveMetrics.requestsPerMinute}</p>
            </div>
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <div className="flex items-center justify-between text-textMuted"><Clock3 size={15} /><span className="text-xs">Lag webhook</span></div>
              <p className="mt-3 font-display text-3xl text-text">{liveMetrics.webhookLagSec}s</p>
            </div>
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <div className="flex items-center justify-between text-textMuted"><AlertTriangle size={15} /><span className="text-xs">Error rate</span></div>
              <p className="mt-3 font-display text-3xl text-text">{liveMetrics.errorRate}%</p>
            </div>
            <div className="rounded-xl border border-border bg-bg/40 p-4">
              <div className="flex items-center justify-between text-textMuted"><TrendingUp size={15} /><span className="text-xs">SLA risk</span></div>
              <p className="mt-3 font-display text-3xl text-text">{liveMetrics.slaRisk}%</p>
            </div>
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-1 xl:grid-cols-[0.95fr_1.05fr] gap-6">
        <Card>
          <div className="flex items-center gap-2 mb-4">
            <ShieldCheck size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Canales en producción</h3>
          </div>
          <div className="space-y-3">
            {channelHealth.map((channel) => (
              <div key={channel.channel} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-text">{channel.channel}</p>
                    <p className="text-xs text-textMuted">Throughput {channel.throughput}/min · backlog {channel.backlog}</p>
                  </div>
                  <div className="flex flex-wrap gap-2 text-xs">
                    <span className={`rounded-full border px-2.5 py-1 ${statusClasses(channel.status)}`}>{channel.status}</span>
                    <span className="rounded-full border border-border px-2.5 py-1 bg-surfaceHover text-text">delivery {channel.delivery}%</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </Card>

        <Card>
          <div className="flex items-center gap-2 mb-4">
            <Sparkles size={16} className="text-primary" />
            <h3 className="text-sm font-semibold text-text">Runbook de incidentes</h3>
          </div>
          <div className="space-y-3">
            {incidents.map((incident) => (
              <div key={incident.id} className="rounded-xl border border-border bg-bg/40 p-4">
                <div className="flex items-center justify-between gap-3">
                  <p className="text-sm font-semibold text-text">{incident.title}</p>
                  <span className={`rounded-full border px-2.5 py-1 text-xs ${toneClasses(incident.severity)}`}>{incident.severity}</span>
                </div>
                <p className="mt-2 text-xs text-textMuted">{incident.detail}</p>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <Activity size={16} className="text-primary" />
            <span className="text-xs text-textMuted">Queue depth</span>
          </div>
          <p className="mt-3 font-display text-3xl text-text">{liveMetrics.queueDepth}</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <Bot size={16} className="text-primary" />
            <span className="text-xs text-textMuted">Failover</span>
          </div>
          <p className="mt-3 font-display text-3xl text-text">{liveMetrics.failoverRate}%</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <AlertTriangle size={16} className="text-warning" />
            <span className="text-xs text-textMuted">CPU simulada</span>
          </div>
          <p className="mt-3 font-display text-3xl text-text">{liveMetrics.cpuLoad}%</p>
        </Card>
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <ShieldCheck size={16} className="text-success" />
            <span className="text-xs text-textMuted">Escenario</span>
          </div>
          <p className="mt-3 text-lg font-semibold text-text">{scenarioMeta.label}</p>
        </Card>
      </div>
    </div>
  );
}
