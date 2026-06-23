import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

const STORAGE_KEY = 'hdreams.production_simulator';

const SCENARIOS = {
  steady: {
    label: 'Operacion estable',
    trafficMultiplier: 1.15,
    latencyBias: 120,
    errorRate: 0.4,
    queuePressure: 1.1,
    statusMix: ['connected', 'connected', 'warning'],
    incidentLabel: 'Sin incidentes mayores. Auto healing activo.',
  },
  peak: {
    label: 'Pico operativo',
    trafficMultiplier: 1.8,
    latencyBias: 260,
    errorRate: 1.2,
    queuePressure: 1.7,
    statusMix: ['connected', 'warning', 'warning'],
    incidentLabel: 'Backlog elevado por campañas y respuestas concurrentes.',
  },
  degraded: {
    label: 'Degradacion controlada',
    trafficMultiplier: 1.35,
    latencyBias: 480,
    errorRate: 3.6,
    queuePressure: 2.2,
    statusMix: ['warning', 'warning', 'disconnected'],
    incidentLabel: 'Latencia alta en webhooks y failover parcial de canales.',
  },
  launch: {
    label: 'Lanzamiento nacional',
    trafficMultiplier: 2.4,
    latencyBias: 340,
    errorRate: 1.8,
    queuePressure: 2.8,
    statusMix: ['connected', 'warning', 'connected'],
    incidentLabel: 'Volumen excepcional por activacion multi-sede y nuevas vacantes.',
  },
};

const DEFAULT_STATE = {
  enabled: false,
  scenario: 'steady',
  latencyMs: 420,
  autoPulse: true,
  panelOpen: false,
};

const ProductionSimulatorContext = createContext(null);

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function round(value) {
  return Math.round(Number(value) || 0);
}

function deepClone(data) {
  if (data == null) return data;
  return JSON.parse(JSON.stringify(data));
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function scenarioFromState(state) {
  return SCENARIOS[state.scenario] ?? SCENARIOS.steady;
}

function buildLiveMetrics(enabled, scenarioKey, latencyMs, tick) {
  const scenario = SCENARIOS[scenarioKey] ?? SCENARIOS.steady;
  const wave = Math.sin(tick / 2.5);
  const wave2 = Math.cos(tick / 3.1);
  const requestsPerMinute = round((enabled ? 820 : 230) * scenario.trafficMultiplier + wave * 55 + wave2 * 30);
  const queueDepth = round((enabled ? 18 : 6) * scenario.queuePressure + Math.abs(wave) * 12);
  const errorRate = Number(clamp((enabled ? scenario.errorRate : 0.1) + Math.abs(wave2) * 0.3, 0.1, 9.9).toFixed(1));
  const cpuLoad = round(clamp((enabled ? 44 : 18) * scenario.queuePressure + Math.abs(wave) * 11, 12, 96));
  const webhookLagSec = round(clamp((enabled ? latencyMs / 65 : 2) + Math.abs(wave2) * 3, 1, 45));
  const slaRisk = round(clamp(queueDepth * 3.4 + errorRate * 7, 5, 98));
  const failoverRate = round(clamp(errorRate * 4 + Math.abs(wave) * 5, 0, 42));

  return {
    requestsPerMinute,
    queueDepth,
    errorRate,
    cpuLoad,
    webhookLagSec,
    slaRisk,
    failoverRate,
  };
}

function buildIncidents(enabled, scenarioKey, metrics) {
  if (!enabled) {
    return [
      { id: 'baseline', title: 'Baseline local', severity: 'info', detail: 'La simulacion esta apagada. La app opera con datos reales del entorno local.' },
    ];
  }

  const items = [
    {
      id: 'traffic',
      title: 'Presion de trafico',
      severity: metrics.queueDepth > 28 ? 'high' : 'medium',
      detail: `${metrics.requestsPerMinute} rpm, backlog ${metrics.queueDepth} y riesgo SLA ${metrics.slaRisk}%`,
    },
    {
      id: 'webhooks',
      title: 'Salud de webhooks',
      severity: metrics.webhookLagSec > 10 ? 'high' : 'medium',
      detail: `Lag promedio ${metrics.webhookLagSec}s y failover ${metrics.failoverRate}%`,
    },
  ];

  if (scenarioKey === 'degraded') {
    items.unshift({
      id: 'incident-main',
      title: 'Incidente controlado en Meta Graph',
      severity: 'critical',
      detail: 'Respuestas de WhatsApp y Messenger con reintentos, colas drenando por lotes.',
    });
  }

  if (scenarioKey === 'launch') {
    items.unshift({
      id: 'incident-launch',
      title: 'Carga de lanzamiento multi-sede',
      severity: 'high',
      detail: 'Se habilita autoscaling operativo, se priorizan vacantes urgentes y recruiters Tier-1.',
    });
  }

  return items;
}

function buildChannelHealth(enabled, scenarioKey, metrics) {
  const scenario = SCENARIOS[scenarioKey] ?? SCENARIOS.steady;
  const base = [
    { channel: 'WhatsApp', baseDelivery: 98, baseBacklog: 14 },
    { channel: 'Messenger', baseDelivery: 96, baseBacklog: 9 },
    { channel: 'Instagram', baseDelivery: 95, baseBacklog: 7 },
    { channel: 'Telegram', baseDelivery: 99, baseBacklog: 4 },
  ];

  return base.map((item, index) => {
    const mixStatus = scenario.statusMix[index % scenario.statusMix.length];
    const delivery = clamp(item.baseDelivery - (enabled ? metrics.errorRate * (index + 1) : 0), 71, 100);
    const backlog = round(item.baseBacklog * (enabled ? scenario.queuePressure : 1) + index * 3 + metrics.queueDepth / 6);
    return {
      channel: item.channel,
      status: enabled ? mixStatus : 'connected',
      delivery: Number(delivery.toFixed(1)),
      backlog,
      throughput: round((metrics.requestsPerMinute / (index + 2)) * 0.38),
    };
  });
}

function amplifyNumber(value, multiplier, offset = 0) {
  return round((Number(value) || 0) * multiplier + offset);
}

export function ProductionSimulatorProvider({ children }) {
  const [state, setState] = useState(() => {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return DEFAULT_STATE;
    try {
      return { ...DEFAULT_STATE, ...JSON.parse(raw) };
    } catch {
      return DEFAULT_STATE;
    }
  });
  const [tick, setTick] = useState(1);

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }, [state]);

  useEffect(() => {
    if (!state.enabled || !state.autoPulse) return undefined;
    const timer = window.setInterval(() => setTick((current) => current + 1), 4500);
    return () => window.clearInterval(timer);
  }, [state.autoPulse, state.enabled]);

  const scenario = scenarioFromState(state);
  const liveMetrics = useMemo(
    () => buildLiveMetrics(state.enabled, state.scenario, state.latencyMs, tick),
    [state.enabled, state.scenario, state.latencyMs, tick]
  );
  const incidents = useMemo(
    () => buildIncidents(state.enabled, state.scenario, liveMetrics),
    [state.enabled, state.scenario, liveMetrics]
  );
  const channelHealth = useMemo(
    () => buildChannelHealth(state.enabled, state.scenario, liveMetrics),
    [state.enabled, state.scenario, liveMetrics]
  );

  const setEnabled = useCallback((enabled) => setState((current) => ({ ...current, enabled })), []);
  const setScenario = useCallback((scenarioKey) => setState((current) => ({ ...current, scenario: scenarioKey })), []);
  const setLatencyMs = useCallback((latencyMs) => setState((current) => ({ ...current, latencyMs })), []);
  const setAutoPulse = useCallback((autoPulse) => setState((current) => ({ ...current, autoPulse })), []);
  const setPanelOpen = useCallback((panelOpen) => setState((current) => ({ ...current, panelOpen })), []);
  const togglePanel = useCallback(() => setState((current) => ({ ...current, panelOpen: !current.panelOpen })), []);

  const simulateRequest = useCallback(async (requestFn) => {
    if (!state.enabled) {
      return requestFn();
    }

    const variance = (tick % 5) * 35;
    await delay(state.latencyMs + scenario.latencyBias + variance);
    return requestFn();
  }, [scenario.latencyBias, state.enabled, state.latencyMs, tick]);

  const simulateKpis = useCallback((data) => {
    if (!state.enabled || !data) return data;
    const cloned = deepClone(data);
    const multiplier = scenario.trafficMultiplier;
    const queuePressure = scenario.queuePressure;

    cloned.totales = cloned.totales ?? {};
    cloned.por_hora = (cloned.por_hora ?? []).map((row, index) => ({
      ...row,
      mensajes: amplifyNumber(row.mensajes ?? row.leads ?? 0, multiplier, index % 3),
      leads: amplifyNumber(row.leads ?? row.mensajes ?? 0, multiplier * 0.92, index % 2),
    }));

    cloned.totales.total = amplifyNumber(cloned.totales.total, multiplier, 12);
    cloned.totales.nuevos = amplifyNumber(cloned.totales.nuevos, multiplier, 5);
    cloned.totales.calificados = amplifyNumber(cloned.totales.calificados, multiplier * 0.84, 3);
    cloned.totales.contratados = amplifyNumber(cloned.totales.contratados, multiplier * 0.66, 1);
    cloned.totales.tiempo_respuesta_avg = round((Number(cloned.totales.tiempo_respuesta_avg) || 18) + state.latencyMs / 55 + queuePressure * 7);
    cloned.totales.score_candidato_avg = Number(clamp((Number(cloned.totales.score_candidato_avg) || 74) - scenario.errorRate * 1.2 + 3, 52, 98).toFixed(1));
    return cloned;
  }, [scenario.errorRate, scenario.queuePressure, scenario.trafficMultiplier, state.enabled, state.latencyMs]);

  const simulateHoursPico = useCallback((data) => {
    if (!state.enabled || !Array.isArray(data)) return data;
    return data.map((row, index) => {
      const spike = index >= 9 && index <= 12 ? 1.35 : index >= 18 && index <= 21 ? 1.6 : 1.08;
      return {
        ...row,
        mensajes: amplifyNumber(row.mensajes ?? row.leads ?? 0, scenario.trafficMultiplier * spike),
        leads: amplifyNumber(row.leads ?? row.mensajes ?? 0, scenario.trafficMultiplier * spike * 0.93),
      };
    });
  }, [scenario.trafficMultiplier, state.enabled]);

  const simulateQueue = useCallback((leads) => {
    if (!state.enabled || !Array.isArray(leads)) return leads;
    const cloned = deepClone(leads).map((lead, index) => ({
      ...lead,
      prioridad: index < 2 ? 'urgente' : lead.prioridad,
      score_prioridad: clamp(round((Number(lead.score_prioridad) || 56) + scenario.queuePressure * 12 - index * 3), 40, 99),
    }));

    cloned.unshift({
      id: `sim-${state.scenario}-1`,
      nombre: 'Simulacion RecruitOps CDMX',
      edad: 28,
      canal: 'whatsapp',
      prioridad: 'urgente',
      score_prioridad: clamp(round(88 + scenario.queuePressure * 3), 85, 99),
    });

    return cloned.slice(0, 20);
  }, [scenario.queuePressure, state.enabled, state.scenario]);

  const simulateAccountsPanel = useCallback((data) => {
    if (!state.enabled || !data) return data;
    const cloned = deepClone(data);
    const mix = scenario.statusMix;

    if (cloned.summary) {
      cloned.summary.active_apps = amplifyNumber(cloned.summary.active_apps, 1, mix.filter((status) => status === 'connected').length - 1);
      cloned.summary.connected_channels = amplifyNumber(cloned.summary.connected_channels, 1.1, 1);
      cloned.summary.urgent_leads = amplifyNumber(cloned.summary.urgent_leads, scenario.queuePressure, 4);
      cloned.summary.pending_interviews = amplifyNumber(cloned.summary.pending_interviews, scenario.trafficMultiplier * 0.8, 2);
      cloned.summary.active_leads = amplifyNumber(cloned.summary.active_leads, scenario.trafficMultiplier, 8);
    }

    cloned.accounts = (cloned.accounts ?? []).map((account, index) => ({
      ...account,
      apps_activas: amplifyNumber(account.apps_activas, 1, mix[index % mix.length] === 'disconnected' ? -1 : 1),
      leads_activos: amplifyNumber(account.leads_activos, scenario.trafficMultiplier, index + 1),
      leads_urgentes: amplifyNumber(account.leads_urgentes, scenario.queuePressure, index + 1),
      entrevistas_pendientes: amplifyNumber(account.entrevistas_pendientes, scenario.trafficMultiplier * 0.72, 1),
    }));

    cloned.apps = (cloned.apps ?? []).map((app, index) => ({
      ...app,
      status: mix[index % mix.length],
      urgentes: amplifyNumber(app.urgentes, scenario.queuePressure, index % 2),
      leads_activos: amplifyNumber(app.leads_activos, scenario.trafficMultiplier, 1),
      last_sync_at: new Date(Date.now() - (state.latencyMs + index * 1800) * 10).toISOString(),
    }));

    return cloned;
  }, [scenario.queuePressure, scenario.statusMix, scenario.trafficMultiplier, state.enabled, state.latencyMs]);

  const simulateExecutive = useCallback((data) => {
    if (!state.enabled || !data) return data;
    const cloned = deepClone(data);
    const multiplier = scenario.trafficMultiplier;
    const pressure = scenario.queuePressure;

    if (cloned.summary) {
      cloned.summary.active_leads = amplifyNumber(cloned.summary.active_leads, multiplier, 10);
      cloned.summary.sla_overdue = amplifyNumber(cloned.summary.sla_overdue, pressure, 3);
      cloned.summary.pending_reply = amplifyNumber(cloned.summary.pending_reply, pressure, 4);
      cloned.summary.interviews_today = amplifyNumber(cloned.summary.interviews_today, multiplier * 0.7, 2);
      cloned.summary.confirmed_today = amplifyNumber(cloned.summary.confirmed_today, multiplier * 0.58, 1);
      cloned.summary.hires_month = amplifyNumber(cloned.summary.hires_month, multiplier * 0.42, 1);
    }

    if (cloned.forecast) {
      cloned.forecast.projected_hires_next_30d = amplifyNumber(cloned.forecast.projected_hires_next_30d, multiplier * 0.9, 2);
    }

    cloned.channels = (cloned.channels ?? []).map((channel, index) => ({
      ...channel,
      total: amplifyNumber(channel.total, multiplier * (1 + index * 0.08), 1),
    }));

    cloned.recruiters = (cloned.recruiters ?? []).map((recruiter, index) => ({
      ...recruiter,
      active_leads: amplifyNumber(recruiter.active_leads, multiplier, index + 1),
      interviews_today: amplifyNumber(recruiter.interviews_today, multiplier * 0.7),
      sla_overdue: amplifyNumber(recruiter.sla_overdue, pressure, index % 2),
      upcoming_followups: amplifyNumber(recruiter.upcoming_followups, pressure * 0.9, 1),
      sla_compliance_pct: clamp(round((Number(recruiter.sla_compliance_pct) || 88) - scenario.errorRate * 2.4), 58, 99),
    }));

    if (cloned.predictive?.summary) {
      cloned.predictive.summary.high_risk_no_show = amplifyNumber(cloned.predictive.summary.high_risk_no_show, pressure, 1);
      cloned.predictive.summary.hot_reactivation = amplifyNumber(cloned.predictive.summary.hot_reactivation, multiplier * 0.9, 1);
      cloned.predictive.summary.priority_candidates = amplifyNumber(cloned.predictive.summary.priority_candidates, multiplier * 0.84, 1);
      cloned.predictive.summary.bottleneck_vacancies = amplifyNumber(cloned.predictive.summary.bottleneck_vacancies, pressure, 1);
    }

    if (Array.isArray(cloned.predictive?.coach_actions)) {
      cloned.predictive.coach_actions.unshift({
        type: 'production_mode',
        title: 'Simulacion de produccion activa',
        detail: `${scenario.label}. ${scenario.incidentLabel}`,
      });
      cloned.predictive.coach_actions = cloned.predictive.coach_actions.slice(0, 6);
    }

    cloned.live_queue = (cloned.live_queue ?? []).slice(0, 8).map((lead, index) => ({
      ...lead,
      prioridad: index < 2 ? 'urgente' : lead.prioridad,
    }));

    return cloned;
  }, [scenario.errorRate, scenario.incidentLabel, scenario.label, scenario.queuePressure, scenario.trafficMultiplier, state.enabled]);

  const value = useMemo(() => ({
    ...state,
    scenarioMeta: scenario,
    scenarios: SCENARIOS,
    liveMetrics,
    incidents,
    channelHealth,
    setEnabled,
    setScenario,
    setLatencyMs,
    setAutoPulse,
    setPanelOpen,
    togglePanel,
    simulateRequest,
    simulateKpis,
    simulateHoursPico,
    simulateQueue,
    simulateAccountsPanel,
    simulateExecutive,
  }), [
    channelHealth,
    incidents,
    liveMetrics,
    scenario,
    setAutoPulse,
    setEnabled,
    setLatencyMs,
    setPanelOpen,
    setScenario,
    simulateAccountsPanel,
    simulateExecutive,
    simulateHoursPico,
    simulateKpis,
    simulateQueue,
    simulateRequest,
    state,
    togglePanel,
  ]);

  return (
    <ProductionSimulatorContext.Provider value={value}>
      {children}
    </ProductionSimulatorContext.Provider>
  );
}

export function useProductionSimulator() {
  const context = useContext(ProductionSimulatorContext);
  if (!context) {
    throw new Error('useProductionSimulator must be used within ProductionSimulatorProvider');
  }
  return context;
}
