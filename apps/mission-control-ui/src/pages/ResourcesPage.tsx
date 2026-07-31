import { useState } from 'react';
import {
  useBudgets,
  useCapacity,
  useCostForecast,
  useCosts,
  useOptimizationDashboard,
  useOptimizationTimeline,
  useProviderRanking,
  useResources,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';
import { api } from '@/api/client';

type Tab = 'dashboard' | 'budgets' | 'capacity' | 'costs' | 'ranking' | 'timeline' | 'forecasts';

export function ResourcesPage() {
  const dashboard = useOptimizationDashboard();
  const resources = useResources();
  const capacity = useCapacity();
  const budgets = useBudgets();
  const costs = useCosts();
  const forecast = useCostForecast();
  const ranking = useProviderRanking();
  const timeline = useOptimizationTimeline();
  const [tab, setTab] = useState<Tab>('dashboard');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dailyLimit, setDailyLimit] = useState('');
  const [monthlyLimit, setMonthlyLimit] = useState('');

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'budgets', label: 'Budgets' },
    { id: 'capacity', label: 'Capacity' },
    { id: 'costs', label: 'Costs' },
    { id: 'ranking', label: 'Provider Ranking' },
    { id: 'timeline', label: 'Timeline' },
    { id: 'forecasts', label: 'Forecasts' },
  ];

  async function saveBudgets() {
    setBusy(true);
    try {
      const body: Record<string, number> = {};
      if (dailyLimit !== '') body.dailyLimit = Number(dailyLimit);
      if (monthlyLimit !== '') body.monthlyLimit = Number(monthlyLimit);
      await api('/budgets', { method: 'PUT', body: JSON.stringify(body) });
      setToast('Budgets updated');
      await budgets.refetch();
      await dashboard.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Update failed');
    } finally {
      setBusy(false);
    }
  }

  async function dryRun() {
    setBusy(true);
    try {
      await api('/optimization/decide', { method: 'POST', body: JSON.stringify({ priority: 'normal' }) });
      setToast('Decision recorded');
      await Promise.all([ranking.refetch(), timeline.refetch(), dashboard.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Decide failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Resource Intelligence"
        description="Cost, capacity, and provider optimization for autonomous engineering."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            {tabs.map((t) => (
              <Button key={t.id} variant="ghost" onClick={() => setTab(t.id)}>{t.label}</Button>
            ))}
            <Button disabled={busy} onClick={() => void dryRun()}>Decide</Button>
          </div>
        }
      />

      {tab === 'dashboard' && (
        <div style={{ display: 'grid', gap: '1rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                <tr><td>Mode</td><td className="aep-mono">{String(dashboard.data?.mode ?? '—')}</td></tr>
                <tr><td>Providers</td><td className="aep-mono">{String(dashboard.data?.providerCount ?? 0)}</td></tr>
                <tr><td>Daily remaining</td><td className="aep-mono">{String(dashboard.data?.dailyRemaining ?? 0)}</td></tr>
                <tr><td>Monthly remaining</td><td className="aep-mono">{String(dashboard.data?.monthlyRemaining ?? 0)}</td></tr>
                <tr><td>Admit rate</td><td className="aep-mono">{String(dashboard.data?.admitRate ?? 0)}</td></tr>
                <tr><td>Forecast</td><td><Status label={String(dashboard.data?.forecastStatus ?? 'unknown')} tone={statusTone(String(dashboard.data?.forecastStatus ?? 'unknown'))} /></td></tr>
                <tr><td>Resources</td><td className="aep-mono">{String((resources.data?.items ?? []).length)}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'budgets' && (
        <div style={{ display: 'grid', gap: '1rem' }}>
          <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
            <input className="aep-mono" style={{ padding: '0.5rem 0.75rem' }} placeholder="Daily limit" value={dailyLimit} onChange={(e) => setDailyLimit(e.target.value)} />
            <input className="aep-mono" style={{ padding: '0.5rem 0.75rem' }} placeholder="Monthly limit" value={monthlyLimit} onChange={(e) => setMonthlyLimit(e.target.value)} />
            <Button disabled={busy} onClick={() => void saveBudgets()}>Save</Button>
          </div>
          <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
            {JSON.stringify(budgets.data ?? {}, null, 2)}
          </pre>
        </div>
      )}

      {tab === 'capacity' && (
        <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
          {JSON.stringify(capacity.data ?? {}, null, 2)}
        </pre>
      )}

      {tab === 'costs' && (
        (costs.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No costs" description="Execution costs appear after optimized provider runs." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Cost</th><th>Provider</th><th>Estimated</th><th>Actual</th><th>Mission</th></tr></thead>
              <tbody>
                {(costs.data?.items ?? []).map((c) => (
                  <tr key={String(c.costId)}>
                    <td className="aep-mono">{String(c.costId)}</td>
                    <td className="aep-mono">{String(c.providerId)}</td>
                    <td className="aep-mono">{String(c.estimated)}</td>
                    <td className="aep-mono">{String(c.actual)}</td>
                    <td className="aep-mono">{String(c.missionId ?? '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'ranking' && (
        (ranking.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No providers" description="Seed providers from deploy/optimization.json." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Provider</th><th>State</th><th>Slots</th><th>Success</th><th>Latency</th><th>Est. cost</th><th>Selected</th></tr></thead>
              <tbody>
                {(ranking.data?.items ?? []).map((p) => (
                  <tr key={String(p.providerId)}>
                    <td className="aep-mono">{String(p.providerId)}</td>
                    <td><Status label={String(p.state)} tone={statusTone(String(p.state))} /></td>
                    <td className="aep-mono">{String(p.availableSlots)}</td>
                    <td className="aep-mono">{String(p.successRate)}</td>
                    <td className="aep-mono">{String(p.avgLatencyMs)}</td>
                    <td className="aep-mono">{String(p.estimatedCost)}</td>
                    <td>{p.selected ? 'yes' : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'timeline' && (
        (timeline.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No timeline" description="OptimizationEvents appear as decisions are made." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>When</th><th>Event</th><th>Provider</th></tr></thead>
              <tbody>
                {(timeline.data?.items ?? []).map((e) => (
                  <tr key={String(e.eventId)}>
                    <td className="aep-mono">{String(e.atUtc)}</td>
                    <td>{String(e.type)}</td>
                    <td className="aep-mono">{String(e.providerId ?? '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'forecasts' && (
        <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
          {JSON.stringify(forecast.data ?? {}, null, 2)}
        </pre>
      )}

      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
