import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api/client';
import {
  useKnowledge,
  useKnowledgeDetail,
  useKnowledgeLessons,
  useKnowledgeTimeline,
  useMissionMemory,
  useSimilarMissions,
  useSimilarPatches,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

type Tab = 'explorer' | 'lessons' | 'similar' | 'preview' | 'timeline' | 'mission';

export function KnowledgePage() {
  const [tab, setTab] = useState<Tab>('explorer');
  const [selectedId, setSelectedId] = useState('');
  const [objective, setObjective] = useState('engineering workspace patch validation');
  const [projectId, setProjectId] = useState('');
  const [missionId, setMissionId] = useState('');
  const [preview, setPreview] = useState<Record<string, unknown> | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const list = useKnowledge(projectId || undefined);
  const lessons = useKnowledgeLessons(projectId || undefined);
  const timeline = useKnowledgeTimeline();
  const similarMissions = useSimilarMissions(objective, projectId || undefined);
  const similarPatches = useSimilarPatches(objective, projectId || undefined);
  const missionMemory = useMissionMemory(missionId);
  const items = tab === 'lessons' ? (lessons.data?.items ?? []) : (list.data?.items ?? []);
  const activeId = selectedId || String(items[0]?.knowledgeId ?? '');
  const detail = useKnowledgeDetail(activeId);

  const policyTrace = useMemo(() => {
    const pack = preview?.pack as { policyTrace?: Array<Record<string, unknown>> } | undefined;
    return pack?.policyTrace ?? [];
  }, [preview]);

  async function runPreview() {
    setBusy(true);
    try {
      const res = await api<{ pack: Record<string, unknown> }>('/knowledge/retrieve', {
        method: 'POST',
        body: JSON.stringify({
          objective,
          projectId: projectId || null,
          missionId: missionId || null,
          mode: 'preview',
          limit: 12,
        }),
      });
      setPreview(res);
      setToast('Retrieval preview ready');
      setTab('preview');
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Preview failed');
    } finally {
      setBusy(false);
    }
  }

  async function archiveSelected() {
    if (!activeId) return;
    setBusy(true);
    try {
      await api(`/knowledge/${activeId}/archive`, { method: 'POST', body: '{}' });
      setToast('Archived');
      await list.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Archive failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title={
          tab === 'lessons' ? 'Engineering Lessons'
            : tab === 'similar' ? 'Similar Missions & Patches'
              : tab === 'preview' ? 'Retrieval Preview'
                : tab === 'timeline' ? 'Knowledge Timeline'
                  : tab === 'mission' ? 'Mission Memory'
                    : 'Knowledge Explorer'
        }
        description="Engineering knowledge captured from missions, workspaces, patches, reviews, and validations."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            <Button variant="ghost" onClick={() => setTab('explorer')}>Explorer</Button>
            <Button variant="ghost" onClick={() => setTab('lessons')}>Lessons</Button>
            <Button variant="ghost" onClick={() => setTab('similar')}>Similar</Button>
            <Button variant="ghost" onClick={() => setTab('preview')}>Preview</Button>
            <Button variant="ghost" onClick={() => setTab('timeline')}>Timeline</Button>
            <Button variant="ghost" onClick={() => setTab('mission')}>Mission Memory</Button>
            <Button disabled={busy} onClick={() => void runPreview()}>Run retrieval</Button>
            <Button variant="ghost" disabled={busy || !activeId} onClick={() => void archiveSelected()}>Archive</Button>
          </div>
        }
      />

      <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', marginBottom: '1rem' }}>
        <input
          className="aep-mono"
          style={{ minWidth: 280, padding: '0.5rem 0.75rem' }}
          value={objective}
          onChange={(e) => setObjective(e.target.value)}
          placeholder="Objective / query"
        />
        <input
          className="aep-mono"
          style={{ minWidth: 140, padding: '0.5rem 0.75rem' }}
          value={projectId}
          onChange={(e) => setProjectId(e.target.value)}
          placeholder="projectId"
        />
        <input
          className="aep-mono"
          style={{ minWidth: 140, padding: '0.5rem 0.75rem' }}
          value={missionId}
          onChange={(e) => setMissionId(e.target.value)}
          placeholder="missionId"
        />
      </div>

      {tab === 'timeline' ? (
        (timeline.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No timeline events" description="Capture and retrieval events appear here." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>When</th><th>Type</th><th>Knowledge</th><th>Message</th></tr></thead>
              <tbody>
                {(timeline.data?.items ?? []).map((e, i) => (
                  <tr key={`${String(e.atUtc)}-${i}`}>
                    <td className="aep-mono">{String(e.atUtc ?? '')}</td>
                    <td>{String(e.type ?? '')}</td>
                    <td className="aep-mono">{String(e.knowledgeId ?? '—')}</td>
                    <td>{String(e.message ?? '')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      ) : tab === 'similar' ? (
        <div style={{ display: 'grid', gap: '1.25rem', gridTemplateColumns: '1fr 1fr' }}>
          <section>
            <h3>Similar Missions</h3>
            {(similarMissions.data?.items?.length ?? 0) === 0 ? (
              <EmptyState title="No similar missions" description="Capture mission memories first." />
            ) : (
              <div className="aep-table-wrap">
                <table className="aep-table">
                  <thead><tr><th>Mission</th><th>Score</th></tr></thead>
                  <tbody>
                    {(similarMissions.data?.items ?? []).map((m) => (
                      <tr key={String(m.knowledgeId)}>
                        <td>
                          <Link to={`/missions/${String(m.missionId)}`} className="aep-mono">{String(m.missionId)}</Link>
                          <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(m.title)}</div>
                        </td>
                        <td className="aep-mono">{String(m.score)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
          <section>
            <h3>Similar Patches</h3>
            {(similarPatches.data?.items?.length ?? 0) === 0 ? (
              <EmptyState title="No similar patches" description="Capture patch memories first." />
            ) : (
              <div className="aep-table-wrap">
                <table className="aep-table">
                  <thead><tr><th>Patch</th><th>Score</th></tr></thead>
                  <tbody>
                    {(similarPatches.data?.items ?? []).map((p) => (
                      <tr key={String(p.knowledgeId)}>
                        <td>
                          <Link to="/patches" className="aep-mono">{String(p.patchId)}</Link>
                          <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(p.title)}</div>
                        </td>
                        <td className="aep-mono">{String(p.score)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      ) : tab === 'preview' ? (
        preview ? (
          <div style={{ display: 'grid', gap: '1.25rem' }}>
            <section>
              <h3>Pack notes</h3>
              <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
                {JSON.stringify((preview.pack as Record<string, unknown>)?.notes ?? [], null, 2)}
              </pre>
            </section>
            <section>
              <h3>Policy trace</h3>
              <div className="aep-table-wrap">
                <table className="aep-table">
                  <thead><tr><th>Policy</th><th>Include</th><th>Δ</th><th>Reason</th></tr></thead>
                  <tbody>
                    {policyTrace.slice(0, 40).map((t, i) => (
                      <tr key={i}>
                        <td className="aep-mono">{String(t.policy)}</td>
                        <td>{String(t.include)}</td>
                        <td className="aep-mono">{String(t.scoreDelta)}</td>
                        <td>{String(t.reason)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        ) : (
          <EmptyState title="No preview" description="Run retrieval to evaluate configured KnowledgeRetrievalPolicy rules." />
        )
      ) : tab === 'mission' ? (
        !missionId ? (
          <EmptyState title="Enter a missionId" description="Mission Memory lists linked knowledge records." />
        ) : (missionMemory.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No mission memory" description="Knowledge appears after capture adapters run." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Knowledge</th><th>Kind</th><th>Status</th></tr></thead>
              <tbody>
                {(missionMemory.data?.items ?? []).map((k) => (
                  <tr key={String(k.knowledgeId)} style={{ cursor: 'pointer' }} onClick={() => { setSelectedId(String(k.knowledgeId)); setTab('explorer'); }}>
                    <td>
                      <div className="aep-mono">{String(k.knowledgeId)}</div>
                      <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(k.title)}</div>
                    </td>
                    <td>{String(k.kind)}</td>
                    <td><Status label={String(k.status)} tone={statusTone(String(k.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      ) : items.length === 0 ? (
        <EmptyState title="No knowledge yet" description="Records are captured from executions, seals, reviews, and manual capture." />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(240px, 320px) 1fr', gap: '1.25rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Knowledge</th><th>Status</th></tr></thead>
              <tbody>
                {items.map((k) => (
                  <tr
                    key={String(k.knowledgeId)}
                    style={{ cursor: 'pointer', background: activeId === k.knowledgeId ? 'rgba(255,255,255,0.04)' : undefined }}
                    onClick={() => setSelectedId(String(k.knowledgeId))}
                  >
                    <td>
                      <div className="aep-mono">{String(k.knowledgeId)}</div>
                      <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>
                        {String(k.kind)} · {String(k.title)}
                      </div>
                    </td>
                    <td><Status label={String(k.status)} tone={statusTone(String(k.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div style={{ display: 'grid', gap: '1rem' }}>
            {detail.data ? (
              <>
                <section>
                  <h3>{String(detail.data.title)}</h3>
                  <div className="aep-table-wrap">
                    <table className="aep-table" style={{ minWidth: 0 }}>
                      <tbody>
                        <tr><td>Kind</td><td>{String(detail.data.kind)}</td></tr>
                        <tr><td>Status</td><td><Status label={String(detail.data.status)} tone={statusTone(String(detail.data.status))} /></td></tr>
                        <tr><td>Summary</td><td>{String(detail.data.summary)}</td></tr>
                        <tr><td>Captured by</td><td className="aep-mono">{String(detail.data.capturedBy)} @ {String(detail.data.captureVersion)}</td></tr>
                        <tr><td>Fingerprint</td><td className="aep-mono">{String(detail.data.reproducibilityFingerprint)}</td></tr>
                        <tr><td>Integrity</td><td className="aep-mono">{String(detail.data.integrityHash)}</td></tr>
                        <tr><td>Provenance</td><td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify(detail.data.provenance ?? {})}</td></tr>
                        <tr><td>Source events</td><td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify(detail.data.sourceEvents ?? [])}</td></tr>
                        <tr><td>Source artifacts</td><td className="aep-mono" style={{ fontSize: '0.8rem' }}>{JSON.stringify(detail.data.sourceArtifacts ?? [])}</td></tr>
                      </tbody>
                    </table>
                  </div>
                </section>
                <section>
                  <h3>Body</h3>
                  <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
                    {String(detail.data.body ?? '')}
                  </pre>
                </section>
              </>
            ) : (
              <EmptyState title="Select a record" description="Details appear in the explorer pane." />
            )}
          </div>
        </div>
      )}

      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
