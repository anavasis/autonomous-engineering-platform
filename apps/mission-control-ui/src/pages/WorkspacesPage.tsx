import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api/client';
import {
  useWorkspace,
  useWorkspaceMounts,
  useWorkspaceSize,
  useWorkspaces,
  useWorkspaceTimeline,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

export function WorkspacesPage() {
  const list = useWorkspaces();
  const [selectedId, setSelectedId] = useState('');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const items = list.data?.items ?? [];
  const activeId = selectedId || String(items[0]?.workspaceId ?? '');
  const detail = useWorkspace(activeId);
  const timeline = useWorkspaceTimeline(activeId);
  const mounts = useWorkspaceMounts(activeId);
  const size = useWorkspaceSize(activeId);

  const quota = useMemo(
    () => (size.data?.quota as Record<string, unknown> | undefined) ?? (detail.data?.quota as Record<string, unknown> | undefined),
    [size.data, detail.data],
  );

  async function seal() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/workspaces/${activeId}/seal`, { method: 'POST', body: '{}' });
      setToast('Workspace sealed');
      await Promise.all([list.refetch(), detail.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Seal failed');
    } finally {
      setBusy(false);
    }
  }

  async function cleanup() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/workspaces/${activeId}/cleanup`, { method: 'POST', body: '{}' });
      setToast('Workspace cleaned up');
      setSelectedId('');
      await list.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Cleanup failed');
    } finally {
      setBusy(false);
    }
  }

  async function snapshot() {
    if (!activeId) return;
    setBusy(true);
    try {
      const res = await api<{ snapshotId: string }>(`/workspaces/${activeId}/snapshot`, {
        method: 'POST',
        body: '{}',
      });
      setToast('Snapshot: ' + res.snapshotId);
      await detail.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Snapshot failed');
    } finally {
      setBusy(false);
    }
  }

  if (list.isLoading) {
    return <EmptyState title="Loading workspaces" description="Scanning engineering workspace index…" />;
  }

  return (
    <div>
      <PageHeader
        title="Workspace Explorer"
        description="Isolated engineering workspaces: status, mounts, size, timeline, and cleanup."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void snapshot()}>Snapshot</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void seal()}>Seal</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void cleanup()}>Cleanup</Button>
          </div>
        }
      />

      {items.length === 0 ? (
        <EmptyState
          title="No engineering workspaces"
          description="Workspaces appear when a provider-backed execution provisions an isolated environment."
        />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 320px) 1fr', gap: '1.25rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead>
                <tr><th>Workspace</th><th>Status</th></tr>
              </thead>
              <tbody>
                {items.map((w) => (
                  <tr
                    key={String(w.workspaceId)}
                    style={{ cursor: 'pointer', background: activeId === w.workspaceId ? 'rgba(255,255,255,0.04)' : undefined }}
                    onClick={() => setSelectedId(String(w.workspaceId))}
                  >
                    <td>
                      <div className="aep-mono">{String(w.workspaceId)}</div>
                      <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>
                        {String(w.missionId)} · {String(w.runId)}
                      </div>
                    </td>
                    <td><Status label={String(w.status)} tone={statusTone(String(w.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'grid', gap: '1.25rem' }}>
            <section>
              <h3>Workspace Status</h3>
              {detail.data ? (
                <div className="aep-table-wrap">
                  <table className="aep-table" style={{ minWidth: 0 }}>
                    <tbody>
                      <tr><td>ID</td><td className="aep-mono">{String(detail.data.workspaceId)}</td></tr>
                      <tr><td>Status</td><td><Status label={String(detail.data.status)} tone={statusTone(String(detail.data.status))} /></td></tr>
                      <tr><td>Mission</td><td><Link to={`/missions/${String(detail.data.missionId)}`}>{String(detail.data.missionId)}</Link></td></tr>
                      <tr><td>Fingerprint</td><td className="aep-mono">{String(detail.data.reproducibilityFingerprint ?? '—')}</td></tr>
                      <tr><td>Schema</td><td className="aep-mono">{String(detail.data.schemaVersion)}</td></tr>
                      <tr><td>Health</td><td><Status label={String((detail.data.health as Record<string, unknown> | undefined)?.status ?? '—')} tone={statusTone(String((detail.data.health as Record<string, unknown> | undefined)?.status ?? ''))} /></td></tr>
                      <tr><td>Git</td><td className="aep-mono">{JSON.stringify(detail.data.git ?? {})}</td></tr>
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState title="Select a workspace" description="Choose a workspace from the explorer list." />
              )}
            </section>

            <section>
              <h3>Workspace Size</h3>
              {quota ? (
                <div className="aep-table-wrap">
                  <table className="aep-table" style={{ minWidth: 0 }}>
                    <tbody>
                      {Object.entries(quota).map(([k, v]) => (
                        <tr key={k}><td>{k}</td><td className="aep-mono">{String(v)}</td></tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState title="No size data" description="Quota accounting appears after provision." />
              )}
            </section>

            <section>
              <h3>Mounted Artifacts / Context</h3>
              {mounts.data ? (
                <div className="aep-table-wrap">
                  <table className="aep-table" style={{ minWidth: 0 }}>
                    <tbody>
                      <tr><td>Prompt hash</td><td className="aep-mono">{String((mounts.data.mounts as Record<string, unknown> | undefined)?.promptHash ?? '—')}</td></tr>
                      <tr><td>Context files</td><td className="aep-mono">{JSON.stringify((mounts.data.mounts as Record<string, unknown> | undefined)?.contextFiles ?? [])}</td></tr>
                      <tr><td>Artifacts</td><td className="aep-mono">{JSON.stringify((mounts.data.mounts as Record<string, unknown> | undefined)?.artifacts ?? [])}</td></tr>
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState title="No mounts" description="Prompt, context, and artifact mounts appear after provision." />
              )}
            </section>

            <section>
              <h3>Workspace Timeline</h3>
              {(timeline.data?.items ?? []).length === 0 ? (
                <EmptyState title="No timeline events" description="Lifecycle events are recorded during provision and cleanup." />
              ) : (
                <div className="aep-timeline">
                  {(timeline.data?.items ?? []).map((e, idx) => (
                    <div key={idx} className="aep-timeline-item">
                      <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>
                        {String(e.at)} · {String(e.type)}
                      </div>
                      <div className="aep-mono" style={{ fontSize: '0.85rem' }}>{JSON.stringify(e.data ?? {})}</div>
                    </div>
                  ))}
                </div>
              )}
            </section>
          </div>
        </div>
      )}
      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
