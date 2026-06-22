import { useQuery } from '@tanstack/react-query';
import { Doughnut, Bar } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  ArcElement,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
  Legend,
} from 'chart.js';
import { fetchAbTests, fetchKPIs } from '../lib/api';

ChartJS.register(ArcElement, CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const EMPRESA = 1;

const DOUGHNUT_OPTS = {
  responsive: true,
  cutout: '68%',
  plugins: { legend: { position: 'right', labels: { color: '#94A3B8', font: { size: 11 } } } },
};

const BAR_OPTS = {
  responsive: true,
  plugins: { legend: { display: false } },
  scales: {
    x: { ticks: { color: '#94A3B8', font: { size: 11 } }, grid: { color: '#1E293B' } },
    y: { ticks: { color: '#94A3B8' }, grid: { color: '#1E293B' } },
  },
};

export default function Stats() {
  const { data: kpis } = useQuery({
    queryKey:  ['kpis-stats', EMPRESA],
    queryFn:   () => fetchKPIs({ empresa_id: EMPRESA }),
    staleTime: 120_000,
  });

  const { data: abData } = useQuery({
    queryKey:  ['ab-tests', EMPRESA],
    queryFn:   () => fetchAbTests({ empresa_id: EMPRESA }),
    staleTime: 120_000,
  });

  const porCanal = kpis?.por_canal ?? [];
  const porPrioridad = kpis?.por_prioridad ?? [];

  const canalChart = {
    labels: porCanal.map((c) => c.canal),
    datasets: [{
      data: porCanal.map((c) => c.total),
      backgroundColor: ['#0EA5E9','#6366F1','#EC4899','#F59E0B','#10B981','#8B5CF6','#EF4444','#64748B'],
      borderWidth: 0,
    }],
  };

  const prioridadColors = { urgente: '#DC2626', alta: '#EA580C', media: '#2563EB', baja: '#64748B' };
  const prioridadChart = {
    labels: porPrioridad.map((p) => p.prioridad),
    datasets: [{
      data: porPrioridad.map((p) => p.total),
      backgroundColor: porPrioridad.map((p) => prioridadColors[p.prioridad] ?? '#64748B'),
      borderWidth: 0,
    }],
  };

  const tests = abData?.tests ?? [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="font-display text-2xl font-bold text-text">Estadísticas</h1>
        <p className="text-sm text-textMuted">Distribución de leads y resultados A/B del mes actual</p>
      </div>

      {/* Gráficas distribución */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="card">
          <h3 className="text-sm font-semibold text-text mb-4">Leads por canal</h3>
          {porCanal.length ? (
            <Doughnut data={canalChart} options={DOUGHNUT_OPTS} />
          ) : (
            <p className="text-textMuted text-sm text-center py-8">Sin datos</p>
          )}
        </div>

        <div className="card">
          <h3 className="text-sm font-semibold text-text mb-4">Leads por prioridad</h3>
          {porPrioridad.length ? (
            <Doughnut data={prioridadChart} options={DOUGHNUT_OPTS} />
          ) : (
            <p className="text-textMuted text-sm text-center py-8">Sin datos</p>
          )}
        </div>
      </div>

      {/* A/B Tests */}
      <div className="card">
        <h3 className="text-sm font-semibold text-text mb-4">A/B Tests activos</h3>
        {tests.length === 0 ? (
          <p className="text-textMuted text-sm text-center py-6">Sin tests activos</p>
        ) : (
          <div className="space-y-8">
            {tests.map((test) => {
              const chartData = {
                labels: test.variantes.map((v) => v.nombre),
                datasets: [
                  {
                    label: 'Tasa conversión %',
                    data: test.variantes.map((v) => Number(v.tasa_conversion ?? 0)),
                    backgroundColor: '#0EA5E9',
                    borderRadius: 4,
                  },
                  {
                    label: 'Tasa respuesta %',
                    data: test.variantes.map((v) => Number(v.tasa_respuesta ?? 0)),
                    backgroundColor: '#6366F1',
                    borderRadius: 4,
                  },
                ],
              };

              return (
                <div key={test.id}>
                  <div className="flex items-center justify-between mb-3">
                    <h4 className="font-medium text-text">{test.nombre}</h4>
                    <span className="text-xs text-textMuted bg-surfaceHover px-2 py-0.5 rounded">{test.tipo}</span>
                  </div>

                  <Bar data={chartData} options={{ ...BAR_OPTS, responsive: true }} height={80} />

                  {/* Tabla variantes */}
                  <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-xs text-textMuted">
                      <thead>
                        <tr className="border-b border-border">
                          <th className="py-1 text-left">Variante</th>
                          <th className="py-1 text-right">Impresiones</th>
                          <th className="py-1 text-right">Leads</th>
                          <th className="py-1 text-right">Contratados</th>
                          <th className="py-1 text-right">Conversión</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-border">
                        {test.variantes.map((v) => (
                          <tr key={v.id}>
                            <td className="py-1 text-text font-medium">{v.nombre}</td>
                            <td className="py-1 text-right">{v.impresiones}</td>
                            <td className="py-1 text-right">{v.leads_generados}</td>
                            <td className="py-1 text-right">{v.contratados}</td>
                            <td className="py-1 text-right text-primary font-mono">{Number(v.tasa_conversion ?? 0).toFixed(1)}%</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
