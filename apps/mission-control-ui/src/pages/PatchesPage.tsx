import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api/client';
import {
  usePatch,
  usePatchChecks,
  usePatchDiff,
  usePatchReadiness,
  usePatches,
  usePatchReviews,
  usePatchTimeline,
  useReviewQueue,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

export function PatchesPage() {
  const list = usePatches();
  const queue = useReviewQueue();
  const [selectedId, setSelectedId] = useState('');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<'explorer' | 'queue'>('explorer');

  const items = tab === 'queue' ? (queue.data?.items ?? []) : (list.data?.items ?? []);
  const activeId = selectedId || String(items[0]?.patchId ?? '');
  const detail = usePatch(activeId);
  const diff = usePatchDiff(activeId);
  const checks = usePatchChecks(activeId);
  const reviews = usePatchReviews(activeId);
  const timeline = usePatchTimeline(activeId);
  const readiness = usePatchReadiness(activeId);

  const validationChecks = useMemo(
    () => (checks.data?.items ?? []).filter((c) => c.kind === 'diff' || c.kind === 'static'),
    [checks.data],
  );
  const testChecks = useMemo(
    () => (checks.data?.items ?? []).filter((c) => c.kind === 'tests'),
    [checks.data],
  );

  async function approve() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/patches/${activeId}/approve`, { method: 'POST', body: JSON.stringify({ summary: 'Approved from Mission Control' }) });
      setToast('Patch approved');
      await Promise.all([list.refetch(), queue.refetch(), detail.refetch(), readiness.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Approve failed');
    } finally {
      setBusy(false);
    }
  }

  async function reject(requestChanges = false) {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/patches/${activeId}/reject`, {
        method: 'POST',
        body: JSON.stringify({ summary: requestChanges ? 'Changes requested' : 'Rejected', requestChanges }),
      });
      setToast(requestChanges ? 'Changes requested' : 'Patch rejected');
      await Promise.all([list.refetch(), queue.refetch(), detail.refetch()]);
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Reject failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title={tab === 'queue' ? 'Review Queue' : 'Patch Explorer'}
        description="Reviewable patches with diff, validation, tests, merge readiness, and approval controls."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <Button variant="ghost" onClick={() => setTab('explorer')}>Explorer</Button>
            <Button variant="ghost" onClick={() => setTab('queue')}>Queue</Button>
            <Button disabled={busy || !activeId} onClick={() => void approve()}>Approve</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void reject(true)}>Request changes</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void reject(false)}>Reject</Button>
          </div>
        }
      />

      {items.length === 0 ? (
        <EmptyState
          title={tab === 'queue' ? 'Review queue empty' : 'No patches'}
          description="Patches are created after provider-backed execution when auto-create is enabled."
        />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 300px) 1fr', gap: '1.25rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Patch</th><th>Status</th></tr></thead>
              <tbody>
                {items.map((p) => (
                  <tr
                    key={String(p.patchId)}
                    style={{ cursor: 'pointer', background: activeId === p.patchId ? 'rgba(255,255,255,0.04)' : undefined }}
                    onClick={() => setSelectedId(String(p.patchId))}
                  >
                    <td>
                      <div className="aep-mono">{String(p.patchId)}</div>
                      <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>
                        {String(p.missionId)} · {String(p.score ?? '')}{p.grade ? ` (${String(p.grade)})` : ''}
                      </div>
                    </td>
                    <td><Status label={String(p.status)} tone={statusTone(String(p.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'grid', gap: '1.25rem' }}>
            <section>
              <h3>Merge Readiness</h3>
              {readiness.data ? (
                <div className="aep-table-wrap">
                  <table className="aep-table" style={{ minWidth: 0 }}>
                    <tbody>
                      <tr><td>Ready</td><td><Status label={String((readiness.data.mergeReadiness as Record<string, unknown> | undefined)?.ready)} tone={statusTone(String((readiness.data.mergeReadiness as Record<string, unknown> | undefined)?.ready))} /></td></tr>
                      <tr><td>Score</td><td className="aep-mono">{String(readiness.data.score)} ({String(readiness.data.grade)})</td></tr>
                      <tr><td>Blockers</td><td className="aep-mono">{JSON.stringify((readiness.data.mergeReadiness as Record<string, unknown> | undefined)?.blockers ?? [])}</td></tr>
                      <tr><td>Checklist</td><td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify((readiness.data.mergeReadiness as Record<string, unknown> | undefined)?.checklist ?? [])}</td></tr>
                      <tr><td>Fingerprint</td><td className="aep-mono">{String(detail.data?.reproducibilityFingerprint ?? '—')}</td></tr>
                      <tr><td>Integrity</td><td className="aep-mono">{String(detail.data?.integrityHash ?? '—')}</td></tr>
                    </tbody>
                  </table>
                </div>
              ) : <EmptyState title="No readiness" description="Select a patch." />}
            </section>

            <section>
              <h3>Diff Viewer</h3>
              {diff.data?.diff ? (
                <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12, maxHeight: 280, overflow: 'auto' }}>
                  {diff.data.diff}
                </pre>
              ) : <EmptyState title="No diff" description="Unified diff appears after patch creation." />}
            </section>

            <section>
              <h3>Validation Results</h3>
              {validationChecks.length === 0 ? (
                <EmptyState title="No validation checks" description="Diff/static checks run during the patch pipeline." />
              ) : (
                <div className="aep-table-wrap">
                  <table className="aep-table">
                    <thead><tr><th>Kind</th><th>Status</th><th>Message</th></tr></thead>
                    <tbody>
                      {validationChecks.map((c) => (
                        <tr key={String(c.checkId)}>
                          <td>{String(c.kind)}</td>
                          <td><Status label={String(c.status)} tone={statusTone(String(c.status))} /></td>
                          <td>{String(c.message)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>

            <section>
              <h3>Test Results</h3>
              {testChecks.length === 0 ? (
                <EmptyState title="No test checks" description="Test execution results appear here." />
              ) : (
                <div className="aep-table-wrap">
                  <table className="aep-table">
                    <thead><tr><th>Status</th><th>Message</th><th>Duration</th></tr></thead>
                    <tbody>
                      {testChecks.map((c) => (
                        <tr key={String(c.checkId)}>
                          <td><Status label={String(c.status)} tone={statusTone(String(c.status))} /></td>
                          <td>{String(c.message)}</td>
                          <td className="aep-mono">{String(c.durationSeconds)}s</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>

            <section>
              <h3>Reviews</h3>
              {(reviews.data?.items ?? []).length === 0 ? (
                <EmptyState title="No reviews" description="Provider and human reviews appear here." />
              ) : (
                <div className="aep-timeline">
                  {(reviews.data?.items ?? []).map((r) => (
                    <div key={String(r.reviewId)} className="aep-timeline-item">
                      <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>
                        {String(r.providerId)} · {String(r.verdict)} · {String(r.atUtc)}
                      </div>
                      <div>{String(r.summary)}</div>
                    </div>
                  ))}
                </div>
              )}
            </section>

            <section>
              <h3>Review Timeline</h3>
              {(timeline.data?.items ?? []).length === 0 ? (
                <EmptyState title="No events" description="Patch lifecycle events are recorded here." />
              ) : (
                <div className="aep-timeline">
                  {(timeline.data?.items ?? []).map((e, idx) => (
                    <div key={idx} className="aep-timeline-item">
                      <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(e.at)} · {String(e.type)}</div>
                      <div className="aep-mono" style={{ fontSize: '0.85rem' }}>{JSON.stringify(e.data ?? {})}</div>
                    </div>
                  ))}
                </div>
              )}
            </section>

            {detail.data?.missionId ? (
              <div>
                <Link to={`/missions/${String(detail.data.missionId)}`}>Open mission {String(detail.data.missionId)}</Link>
              </div>
            ) : null}
          </div>
        </div>
      )}
      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
