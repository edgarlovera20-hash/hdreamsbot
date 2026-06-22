import { Bar } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Tooltip,
} from 'chart.js';
import { Card } from '../ui/Card';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

export const HoursChart = ({ data = [] }) => {
  const labels   = Array.from({ length: 24 }, (_, i) => `${i}h`);
  const horaMap  = Object.fromEntries(data.map((h) => [Number(h.hora), Number(h.leads ?? h.mensajes ?? 0)]));
  const values   = labels.map((_, i) => horaMap[i] ?? 0);
  const maxVal   = Math.max(...values, 1);

  const chartData = {
    labels,
    datasets: [
      {
        label:                'Leads',
        data:                 values,
        backgroundColor:      values.map((v) =>
          v >= maxVal * 0.8 ? '#0EA5E9' : v >= maxVal * 0.5 ? '#0369A1' : '#1E3A5F'
        ),
        hoverBackgroundColor: '#38BDF8',
        borderRadius:         4,
        borderSkipped:        false,
      },
    ],
  };

  const options = {
    responsive:          true,
    maintainAspectRatio: false,
    plugins:             { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
    scales: {
      x: {
        grid:  { color: '#334155' },
        ticks: { color: '#94A3B8', font: { family: 'Inter', size: 10 } },
      },
      y: {
        grid:       { color: '#334155' },
        ticks:      { color: '#94A3B8', font: { family: 'Inter', size: 10 }, stepSize: 1 },
        beginAtZero: true,
      },
    },
  };

  return (
    <Card delay={0.2}>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="font-display text-base font-semibold text-text">Horas pico</h3>
          <p className="text-xs text-textMuted">Últimos 7 días</p>
        </div>
        <div className="flex items-center gap-3 text-xs text-textMuted">
          <span className="flex items-center gap-1.5">
            <span className="w-2 h-2 rounded-sm bg-primary inline-block" /> Alto
          </span>
          <span className="flex items-center gap-1.5">
            <span className="w-2 h-2 rounded-sm bg-[#0369A1] inline-block" /> Medio
          </span>
          <span className="flex items-center gap-1.5">
            <span className="w-2 h-2 rounded-sm bg-[#1E3A5F] inline-block" /> Bajo
          </span>
        </div>
      </div>
      <div style={{ height: 180 }}>
        <Bar data={chartData} options={options} />
      </div>
    </Card>
  );
};
