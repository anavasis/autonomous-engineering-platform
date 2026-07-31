import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api/client';
import {
  usePlanningDashboard,
  useProgram,
  useProgramAllocations,
  useProgramCriticalPath,
  useProgramDependencies,
  useProgramQueue,
  useProgramSchedule,
  useProgramTimeline,
  usePrograms,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

type Tab =
  | 'dashboard'
  | 'explorer'
  | 'graph'
  | 'dependencies'
  | 'critical'
  | 'schedule'
  | 'allocations'
  | 'queue'
  | 'replan'
  | 'timeline';

export function PlanningPage() {
  const dashboard = usePlanningDashboard();
  const list = usePrograms();
  const [tab, setTab] = useState<Tab>('dashboard');
  const [selectedId, setSelectedId] = useState('');
  const [objective, setObjective] = useState('Inspect scope; implement feature; validate and seal');
  const [title, setTitle] = useState('Engineering Program');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [replanPreview, setReplanPreview] = useState<Record<string, unknown> | null>(null);

  const items = list.data?.items ?? [];
  const activeId = selectedId || String(items[0]?.programId ?? '');
  const detail = useProgram(activeId);
  const deps = useProgramDependencies(activeId);
  const critical = useProgramCriticalPath(activeId);
  const schedule = useProgramSchedule(activeId);
  const allocations = useProgramAllocations(activeId);
  const queue = useProgramQueue(activeId);
  const timeline = useProgramTimeline(activeId);

  const graphNodes = useMemo(() => {
    const g = detail.data?.graph as { nodes?: Array<Record<string, unknown>> } | undefined;
    return g?.nodes ?? [];
  }, [detail.data]);

  async function createProgram() {
    setBusy(true);
    try {
      const res = await api<{ program: Record<string, unknown> }>('/programs', {
        method: 'POST',
        body: JSON.stringify({ title, objective, autoPlan: true }),
      });
      setSelectedId(String(res.program.programId));
      setToast('Program created');
      setTab('explorer');
      await list.refetch();
      await dashboard.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Create failed');
    } finally {
      setBusy(false);
    }
  }

  async function startProgram() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/programs/${activeId}/start`, { method: 'POST', body: '{}' });
      setToast('Program started');
      await Promise.all([list.refetch(), detail.refetch(), queue.refetch(), timeline.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Start failed');
    } finally {
      setBusy(false);
    }
  }

  async function tick() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api('/programs/scheduler/tick', { method: 'POST', body: JSON.stringify({ programId: activeId }) });
      setToast('Scheduler tick');
      await Promise.all([detail.refetch(), queue.refetch(), schedule.refetch(), timeline.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Tick failed');
    } finally {
      setBusy(false);
    }
  }

  async function pause() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/programs/${activeId}/pause`, { method: 'POST', body: '{}' });
      setToast('Paused');
      await detail.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Pause failed');
    } finally {
      setBusy(false);
    }
  }

  async function previewReplan() {
    if (!activeId) return;
    setBusy(true);
    try {
      const failed = graphNodes.find((n) => n.status === 'failed');
      const res = await api<Record<string, unknown>>(`/programs/${activeId}/replan/preview`, {
        method: 'POST',
        body: JSON.stringify({ failedNodeId: failed ? String(failed.nodeId) : null }),
      });
      setReplanPreview(res);
      setTab('replan');
      setToast('Replan preview ready');
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Preview failed');
    } finally {
      setBusy(false);
    }
  }

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'explorer', label: 'Explorer' },
    { id: 'graph', label: 'Mission Graph' },
    { id: 'dependencies', label: 'Dependencies' },
    { id: 'critical', label: 'Critical Path' },
    { id: 'schedule', label: 'Scheduler Timeline' },
    { id: 'allocations', label: 'Resources' },
    { id: 'queue', label: 'Queue' },
    { id: 'replan', label: 'Replan Preview' },
    { id: 'timeline', label: 'Timeline' },
  ];

  return (
    <div>
      <PageHeader
        title="Planning & Scheduling"
        description="Coordinate dependent missions as autonomous engineering programs."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            {tabs.map((t) => (
              <Button key={t.id} variant="ghost" onClick={() => setTab(t.id)}>{t.label}</Button>
            ))}
            <Button disabled={busy} onClick={() => void createProgram()}>Create</Button>
            <Button disabled={busy || !activeId} onClick={() => void startProgram()}>Start</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void tick()}>Tick</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void pause()}>Pause</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void previewReplan()}>Replan preview</Button>
          </div>
        }
      />

      <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', marginBottom: '1rem' }}>
        <input className="aep-mono" style={{ minWidth: 180, padding: '0.5rem 0.75rem' }} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Title" />
        <input className="aep-mono" style={{ minWidth: 360, padding: '0.5rem 0.75rem', flex: 1 }} value={objective} onChange={(e) => setObjective(e.target.value)} placeholder="Objective" />
      </div>

      {tab === 'dashboard' && (
        <div style={{ display: 'grid', gap: '1rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                <tr><td>Programs</td><td className="aep-mono">{String(dashboard.data?.programCount ?? 0)}</td></tr>
                <tr><td>Queue depth</td><td className="aep-mono">{String(dashboard.data?.queueDepth ?? 0)}</td></tr>
                <tr><td>Blocked</td><td className="aep-mono">{String(dashboard.data?.blocked ?? 0)}</td></tr>
                <tr><td>By status</td><td className="aep-mono" style={{ fontSize: '0.85rem' }}>{JSON.stringify(dashboard.data?.byStatus ?? {})}</td></tr>
              </tbody>
            </table>
          </div>
          {(dashboard.data?.items?.length ?? 0) === 0 ? (
            <EmptyState title="No programs" description="Create a program to begin multi-mission planning." />
          ) : (
            <div className="aep-table-wrap">
              <table className="aep-table">
                <thead><tr><th>Program</th><th>Status</th><th>Nodes</th></tr></thead>
                <tbody>
                  {(dashboard.data?.items ?? []).map((p) => (
                    <tr key={String(p.programId)} style={{ cursor: 'pointer' }} onClick={() => { setSelectedId(String(p.programId)); setTab('explorer'); }}>
                      <td>
                        <div className="aep-mono">{String(p.programId)}</div>
                        <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(p.title)}</div>
                      </td>
                      <td><Status label={String(p.status)} tone={statusTone(String(p.status))} /></td>
                      <td className="aep-mono">{String(p.nodeCount)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {tab === 'explorer' && (
        items.length === 0 ? (
          <EmptyState title="No programs" description="Create a program from an objective." />
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 320px) 1fr', gap: '1.25rem' }}>
            <div className="aep-table-wrap">
              <table className="aep-table">
                <thead><tr><th>Program</th><th>Status</th></tr></thead>
                <tbody>
                  {items.map((p) => (
                    <tr key={String(p.programId)} style={{ cursor: 'pointer', background: activeId === p.programId ? 'rgba(255,255,255,0.04)' : undefined }} onClick={() => setSelectedId(String(p.programId))}>
                      <td>
                        <div className="aep-mono">{String(p.programId)}</div>
                        <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(p.title)}</div>
                      </td>
                      <td><Status label={String(p.status)} tone={statusTone(String(p.status))} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="aep-table-wrap">
              {detail.data ? (
                <table className="aep-table" style={{ minWidth: 0 }}>
                  <tbody>
                    <tr><td>Objective</td><td>{String(detail.data.objective)}</td></tr>
                    <tr><td>Status</td><td><Status label={String(detail.data.status)} tone={statusTone(String(detail.data.status))} /></td></tr>
                    <tr><td>Fingerprint</td><td className="aep-mono">{String(detail.data.reproducibilityFingerprint)}</td></tr>
                    <tr><td>Integrity</td><td className="aep-mono">{String(detail.data.integrityHash)}</td></tr>
                    <tr><td>Estimates</td><td className="aep-mono" style={{ fontSize: '0.85rem' }}>{JSON.stringify(detail.data.estimates ?? {})}</td></tr>
                    <tr><td>Snapshots</td><td className="aep-mono">{String((detail.data.snapshots as unknown[] | undefined)?.length ?? 0)}</td></tr>
                  </tbody>
                </table>
              ) : <EmptyState title="Select a program" description="Program detail appears here." />}
            </div>
          </div>
        )
      )}

      {tab === 'graph' && (
        graphNodes.length === 0 ? (
          <EmptyState title="No graph" description="Plan a program to materialize the mission DAG." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Node</th><th>Depends</th><th>Status</th><th>Mission</th></tr></thead>
              <tbody>
                {graphNodes.map((n) => (
                  <tr key={String(n.nodeId)}>
                    <td>
                      <div className="aep-mono">{String(n.nodeId)}</div>
                      <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(n.title)}</div>
                    </td>
                    <td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify(n.dependsOn ?? [])}</td>
                    <td><Status label={String(n.status)} tone={statusTone(String(n.status))} /></td>
                    <td>{n.missionId ? <Link className="aep-mono" to={`/missions/${String(n.missionId)}`}>{String(n.missionId)}</Link> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'dependencies' && (
        !(deps.data?.items?.length) ? (
          <EmptyState title="No dependencies" description="Select a planned program." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Node</th><th>Depends on</th><th>Blocked reason</th></tr></thead>
              <tbody>
                {(deps.data?.items ?? []).map((d) => (
                  <tr key={String(d.nodeId)}>
                    <td className="aep-mono">{String(d.nodeId)}</td>
                    <td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify(d.dependsOn ?? [])}</td>
                    <td>{String(d.blockedReason || '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <pre className="aep-mono" style={{ padding: '1rem' }}>waves: {JSON.stringify(deps.data?.waves ?? [])}</pre>
          </div>
        )
      )}

      {tab === 'critical' && (
        <div className="aep-table-wrap">
          <table className="aep-table" style={{ minWidth: 0 }}>
            <tbody>
              <tr><td>Duration (s)</td><td className="aep-mono">{String((critical.data?.criticalPath as Record<string, unknown> | undefined)?.durationSeconds ?? '—')}</td></tr>
              <tr><td>Critical nodes</td><td className="aep-mono">{JSON.stringify((critical.data?.criticalPath as Record<string, unknown> | undefined)?.nodeIds ?? [])}</td></tr>
              <tr><td>Slack</td><td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify((critical.data?.criticalPath as Record<string, unknown> | undefined)?.slack ?? {})}</td></tr>
            </tbody>
          </table>
        </div>
      )}

      {tab === 'schedule' && (
        <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
          {JSON.stringify(schedule.data ?? {}, null, 2)}
        </pre>
      )}

      {tab === 'allocations' && (
        <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
          {JSON.stringify(allocations.data ?? {}, null, 2)}
        </pre>
      )}

      {tab === 'queue' && (
        <div style={{ display: 'grid', gap: '1rem', gridTemplateColumns: '1fr 1fr 1fr' }}>
          {(['ready', 'queued', 'running'] as const).map((k) => (
            <section key={k}>
              <h3>{k}</h3>
              <div className="aep-table-wrap">
                <table className="aep-table">
                  <tbody>
                    {((queue.data?.[k] as Array<Record<string, unknown>> | undefined) ?? []).map((n) => (
                      <tr key={String(n.nodeId)}>
                        <td className="aep-mono">{String(n.nodeId)}</td>
                        <td>{String(n.title)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          ))}
        </div>
      )}

      {tab === 'replan' && (
        replanPreview ? (
          <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
            {JSON.stringify(replanPreview, null, 2)}
          </pre>
        ) : (
          <EmptyState title="No replan preview" description="Run replan preview for the selected program." />
        )
      )}

      {tab === 'timeline' && (
        (timeline.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No timeline" description="PlanningEvents appear as the program advances." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>When</th><th>Event</th><th>Node</th><th>Payload</th></tr></thead>
              <tbody>
                {(timeline.data?.items ?? []).map((e) => (
                  <tr key={String(e.eventId)}>
                    <td className="aep-mono">{String(e.atUtc)}</td>
                    <td>{String(e.type)}</td>
                    <td className="aep-mono">{String(e.nodeId ?? '—')}</td>
                    <td className="aep-mono" style={{ fontSize: '0.75rem' }}>{JSON.stringify(e.payload ?? {})}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
