import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api/client';
import {
  useAgent,
  useAgentCapabilities,
  useAgentDashboard,
  useAgentMetrics,
  useAgents,
  useAgentTimeline,
  useAssignments,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

type Tab = 'dashboard' | 'explorer' | 'capabilities' | 'assignments' | 'sessions' | 'timeline' | 'metrics' | 'health';

export function AgentsPage() {
  const dashboard = useAgentDashboard();
  const list = useAgents();
  const caps = useAgentCapabilities();
  const assignments = useAssignments();
  const [tab, setTab] = useState<Tab>('dashboard');
  const [selectedId, setSelectedId] = useState('');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const items = list.data?.items ?? [];
  const activeId = selectedId || String(items[0]?.agentId ?? '');
  const detail = useAgent(activeId);
  const timeline = useAgentTimeline(activeId);
  const metrics = useAgentMetrics(activeId);

  async function healthCheck() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/agents/${activeId}/health/check`, { method: 'POST', body: '{}' });
      setToast('Health check complete');
      await Promise.all([list.refetch(), detail.refetch(), dashboard.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Health check failed');
    } finally {
      setBusy(false);
    }
  }

  async function retire() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/agents/${activeId}/retire`, { method: 'POST', body: '{}' });
      setToast('Agent retired');
      await list.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Retire failed');
    } finally {
      setBusy(false);
    }
  }

  async function reassign(assignmentId: string) {
    setBusy(true);
    try {
      await api(`/assignments/${assignmentId}/reassign`, { method: 'POST', body: '{}' });
      setToast('Reassigned');
      await assignments.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Reassign failed');
    } finally {
      setBusy(false);
    }
  }

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'explorer', label: 'Explorer' },
    { id: 'capabilities', label: 'Capabilities' },
    { id: 'assignments', label: 'Assignments' },
    { id: 'sessions', label: 'Sessions' },
    { id: 'timeline', label: 'Timeline' },
    { id: 'metrics', label: 'Metrics' },
    { id: 'health', label: 'Health' },
  ];

  return (
    <div>
      <PageHeader
        title="Multi-Agent Collaboration"
        description="Specialized agents assigned to planning nodes via policy-driven routing."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            {tabs.map((t) => (
              <Button key={t.id} variant="ghost" onClick={() => setTab(t.id)}>{t.label}</Button>
            ))}
            <Button disabled={busy || !activeId} onClick={() => void healthCheck()}>Health check</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void retire()}>Retire</Button>
          </div>
        }
      />

      {tab === 'dashboard' && (
        <div style={{ display: 'grid', gap: '1rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                <tr><td>Agents</td><td className="aep-mono">{String(dashboard.data?.agentCount ?? 0)}</td></tr>
                <tr><td>Unhealthy</td><td className="aep-mono">{String(dashboard.data?.unhealthy ?? 0)}</td></tr>
                <tr><td>Active assignments</td><td className="aep-mono">{String(dashboard.data?.activeAssignments ?? 0)}</td></tr>
                <tr><td>Waiting</td><td className="aep-mono">{String(dashboard.data?.waitingAssignments ?? 0)}</td></tr>
                <tr><td>By status</td><td className="aep-mono" style={{ fontSize: '0.85rem' }}>{JSON.stringify(dashboard.data?.byStatus ?? {})}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'explorer' && (
        items.length === 0 ? (
          <EmptyState title="No agents" description="Seed agents from deploy/agents.json or register via API." />
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 320px) 1fr', gap: '1.25rem' }}>
            <div className="aep-table-wrap">
              <table className="aep-table">
                <thead><tr><th>Agent</th><th>Status</th></tr></thead>
                <tbody>
                  {items.map((a) => (
                    <tr key={String(a.agentId)} style={{ cursor: 'pointer', background: activeId === a.agentId ? 'rgba(255,255,255,0.04)' : undefined }} onClick={() => setSelectedId(String(a.agentId))}>
                      <td>
                        <div className="aep-mono">{String(a.agentId)}</div>
                        <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(a.name)} · {String(a.role)}</div>
                      </td>
                      <td><Status label={String(a.status)} tone={statusTone(String(a.status))} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {detail.data ? (
              <div className="aep-table-wrap">
                <table className="aep-table" style={{ minWidth: 0 }}>
                  <tbody>
                    <tr><td>Role</td><td>{String(detail.data.role)}</td></tr>
                    <tr><td>Status</td><td><Status label={String(detail.data.status)} tone={statusTone(String(detail.data.status))} /></td></tr>
                    <tr><td>Capabilities</td><td className="aep-mono" style={{ fontSize: '0.85rem' }}>{JSON.stringify((detail.data.profile as Record<string, unknown> | undefined)?.capabilities ?? [])}</td></tr>
                    <tr><td>Health</td><td className="aep-mono">{JSON.stringify(detail.data.health ?? {})}</td></tr>
                    <tr><td>Slots</td><td className="aep-mono">{String(detail.data.availableSlots)}</td></tr>
                    <tr><td>Fingerprint</td><td className="aep-mono">{String(detail.data.reproducibilityFingerprint)}</td></tr>
                    <tr><td>Integrity</td><td className="aep-mono">{String(detail.data.integrityHash)}</td></tr>
                  </tbody>
                </table>
              </div>
            ) : <EmptyState title="Select an agent" description="Details appear here." />}
          </div>
        )
      )}

      {tab === 'capabilities' && (
        <div className="aep-table-wrap">
          <table className="aep-table">
            <thead><tr><th>Capability</th></tr></thead>
            <tbody>
              {(caps.data?.items ?? []).map((c) => (
                <tr key={c}><td className="aep-mono">{c}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'assignments' && (
        (assignments.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No assignments" description="Assignments appear when Planning launches nodes with agent collaboration enabled." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Assignment</th><th>Agent</th><th>Role</th><th>Status</th><th>Mission</th><th></th></tr></thead>
              <tbody>
                {(assignments.data?.items ?? []).map((a) => (
                  <tr key={String(a.assignmentId)}>
                    <td className="aep-mono">{String(a.assignmentId)}</td>
                    <td className="aep-mono">{String(a.agentId)}</td>
                    <td>{String(a.role)}</td>
                    <td><Status label={String(a.status)} tone={statusTone(String(a.status))} /></td>
                    <td>{a.missionId ? <Link className="aep-mono" to={`/missions/${String(a.missionId)}`}>{String(a.missionId)}</Link> : '—'}</td>
                    <td><Button variant="ghost" disabled={busy} onClick={() => void reassign(String(a.assignmentId))}>Reassign</Button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'sessions' && (
        ((detail.data?.sessions as Array<Record<string, unknown>> | undefined)?.length ?? 0) === 0 ? (
          <EmptyState title="No sessions" description="Select an agent with started assignments." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Session</th><th>Assignment</th><th>Mission</th><th>Run</th></tr></thead>
              <tbody>
                {((detail.data?.sessions as Array<Record<string, unknown>> | undefined) ?? []).map((s) => (
                  <tr key={String(s.sessionId)}>
                    <td className="aep-mono">{String(s.sessionId)}</td>
                    <td className="aep-mono">{String(s.assignmentId)}</td>
                    <td className="aep-mono">{String(s.missionId ?? '—')}</td>
                    <td className="aep-mono">{String(s.runId ?? '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'timeline' && (
        (timeline.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No timeline" description="AgentEvents appear as assignments progress." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>When</th><th>Event</th><th>Assignment</th></tr></thead>
              <tbody>
                {(timeline.data?.items ?? []).map((e) => (
                  <tr key={String(e.eventId)}>
                    <td className="aep-mono">{String(e.atUtc)}</td>
                    <td>{String(e.type)}</td>
                    <td className="aep-mono">{String(e.assignmentId ?? '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'metrics' && (
        <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
          {JSON.stringify(metrics.data ?? {}, null, 2)}
        </pre>
      )}

      {tab === 'health' && (
        <div className="aep-table-wrap">
          <table className="aep-table">
            <thead><tr><th>Agent</th><th>Status</th><th>Health</th><th>Failures</th></tr></thead>
            <tbody>
              {items.map((a) => (
                <tr key={String(a.agentId)} style={{ cursor: 'pointer' }} onClick={() => setSelectedId(String(a.agentId))}>
                  <td className="aep-mono">{String(a.name)}</td>
                  <td><Status label={String(a.status)} tone={statusTone(String(a.status))} /></td>
                  <td>{String(a.health)}</td>
                  <td className="aep-mono">{String((detail.data?.health as Record<string, unknown> | undefined)?.consecutiveFailures ?? '—')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
