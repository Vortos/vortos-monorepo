/**
 * InsightsChart island — Recharts-powered evaluation metrics visualisation.
 * Mount point: <div data-island="insights-chart">
 *
 * Fetches from the management API endpoint (same origin, CSRF-credentialed),
 * renders a dual-axis line+bar chart of evaluation throughput and latency p99.
 * No CDN dependency; Recharts is bundled by Vite.
 */
import React, { useEffect, useState } from 'react';
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import type { InsightsChartProps, MetricPoint } from './types';

type LoadState = 'idle' | 'loading' | 'error' | 'ok';

function shortTs(iso: string): string {
  // Return HH:MM for same-day or MM-DD for older
  try {
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } catch {
    return iso.slice(11, 16);
  }
}

function getCsrfToken(): string {
  return (
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  );
}

export function InsightsChart({ flagName, dataEndpoint, allowedLabels }: InsightsChartProps) {
  const [state, setState] = useState<LoadState>('idle');
  const [points, setPoints] = useState<MetricPoint[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!flagName) return;

    setState('loading');

    fetch(`${dataEndpoint}?flag=${encodeURIComponent(flagName)}`, {
      credentials: 'same-origin',
      headers: {
        'X-CSRF-Token': getCsrfToken(),
        Accept: 'application/json',
      },
    })
      .then(async (res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json() as Promise<{ points: MetricPoint[] }>;
      })
      .then((data) => {
        setPoints(data.points ?? []);
        setState('ok');
      })
      .catch((e: unknown) => {
        setError(String(e));
        setState('error');
      });
  }, [flagName, dataEndpoint]);

  if (!flagName) {
    return (
      <p style={{ color: '#71717a', fontSize: '0.8125rem' }}>
        Enter a flag name above to see evaluation metrics.
      </p>
    );
  }

  if (state === 'loading') {
    return <p style={{ color: '#71717a', fontSize: '0.8125rem' }}>Loading metrics…</p>;
  }

  if (state === 'error') {
    return (
      <p style={{ color: '#ef4444', fontSize: '0.8125rem' }}>
        Failed to load metrics: {error}
      </p>
    );
  }

  if (state === 'ok' && points.length === 0) {
    return (
      <p style={{ color: '#71717a', fontSize: '0.8125rem' }}>
        No metric data for <code>{flagName}</code> yet.
      </p>
    );
  }

  const chartData = points.map((p) => ({
    ...p,
    ts: shortTs(p.timestamp),
    trueRatio: p.evaluations > 0 ? Math.round((p.trueCount / p.evaluations) * 100) : 0,
  }));

  return (
    <div>
      <p style={{ color: '#a1a1aa', fontSize: '0.75rem', marginBottom: '0.75rem' }}>
        Showing {points.length} data points for <strong style={{ color: '#e4e4e7' }}>{flagName}</strong>
      </p>

      <ResponsiveContainer width="100%" height={260}>
        <ComposedChart data={chartData} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#27272a" />
          <XAxis dataKey="ts" tick={{ fill: '#71717a', fontSize: 11 }} />
          <YAxis yAxisId="left" tick={{ fill: '#71717a', fontSize: 11 }} />
          <YAxis yAxisId="right" orientation="right" tick={{ fill: '#71717a', fontSize: 11 }} unit="ms" />
          <Tooltip
            contentStyle={{ background: '#18181b', border: '1px solid #3f3f46', borderRadius: 6 }}
            labelStyle={{ color: '#a1a1aa' }}
            itemStyle={{ color: '#e4e4e7' }}
          />
          <Legend wrapperStyle={{ fontSize: 11, color: '#a1a1aa' }} />
          <Bar yAxisId="left" dataKey="evaluations" fill="#6366f1" opacity={0.7} name="Evaluations" />
          <Line yAxisId="left" type="monotone" dataKey="trueRatio" stroke="#22c55e" dot={false} name="True %" />
          <Line yAxisId="right" type="monotone" dataKey="p99Ms" stroke="#f59e0b" dot={false} strokeDasharray="4 2" name="p99 (ms)" />
        </ComposedChart>
      </ResponsiveContainer>

      {allowedLabels.length > 0 && (
        <p style={{ color: '#71717a', fontSize: '0.75rem', marginTop: '0.5rem' }}>
          Cardinality-bounded labels: {allowedLabels.join(', ')}
        </p>
      )}
    </div>
  );
}
