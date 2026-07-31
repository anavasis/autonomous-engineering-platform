import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { useArtifacts, useMission, useTimeline, useValidation } from '@/api/hooks';
import {
  Button,
  Drawer,
  EmptyState,
  PageHeader,
  Progress,
  Status,
  statusTone,
  ToastHost,
} from '@/design-system/ui';

const TABS = [
  'summary',
  'plan',
  'conversation',
  'timeline',
  'artifacts',
  'logs',
  'validation',
  'checkpoints',
  'reports',
] as const;

export function MissionDetailsPage() {
  const { missionId = '' } = useParams();
  const mission = useMission(missionId);
  const timeline = useTimeline(missionId);
  const artifacts = useArtifacts(missionId);
  const validation = useValidation();
  const plan = useQuery({
    queryKey: ['mission-plan', missionId],
    queryFn: () => api<Record<string, unknown>>(`/missions/${missionId}/plan`),
    enabled: Boolean(missionId),
    retry: false,
  });
  const conversation = useQuery({
    queryKey: ['mission-conversation', missionId],
    queryFn: () => api<{ conversation: Record<string, unknown> | null }>(`/missions/${missionId}/conversation`),
    enabled: Boolean(missionId),
  });
  const [tab, setTab] = useState<(typeof TABS)[number]>('summary');
  const [selectedEvent, setSelectedEvent] = useState<Record<string, unknown> | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const validationRow = useMemo(
    () => (validation.data?.items ?? []).find((v) => v.missionId === missionId),
    [validation.data, missionId],
  );

  if (mission.isLoading) {
    return <EmptyState title="Loading mission" description="Fetching mission detail projection…" />;
  }
  if (mission.isError || !mission.data) {
    return <EmptyState title="Mission not found" description={mission.error?.message ?? 'Unknown mission.'} />;
  }

  const m = mission.data;
  const events = timeline.data?.items ?? [];
  const workspaces = artifacts.data?.items ?? [];
  const checkpoint = m.checkpoint as Record<string, unknown> | null;
  const run = m.latestRun as Record<string, unknown> | null;
  const explain = (plan.data?.explainability as Record<string, string> | undefined) ?? {};

  async function retry() {
    setBusy(true);
    try {
      const result = await api<Record<string, unknown>>(`/missions/${missionId}/retry`, { method: 'POST', body: '{}' });
      setToast('Retry started: ' + String(result.runId));
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Retry failed');
    } finally {
      setBusy(false);
    }
  }

  async function resume() {
    if (!run?.runId) return;
    setBusy(true);
    try {
      const result = await api<Record<string, unknown>>(
        `/missions/${missionId}/runs/${String(run.runId)}/resume`,
        { method: 'POST', body: '{}' },
      );
      setToast('Resumed: ' + String(result.engineState));
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Resume failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title={String(m.id)}
        description={String(m.objective)}
        actions={
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <Button variant="ghost" onClick={() => void resume()} disabled={busy || !run?.runId}>Resume</Button>
            <Button variant="ghost" onClick={() => void retry()} disabled={busy}>Retry</Button>
            <Link to="/missions" className="aep-btn aep-btn-ghost">Back</Link>
          </div>
        }
      />

      <div style={{ display: 'grid', gap: '1rem', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center' }}>
          <Status label={String(m.state)} tone={statusTone(String(m.state))} />
          <span className="aep-mono">step: {m.currentStep ? String(m.currentStep) : '—'}</span>
          <span className="aep-mono">workflow: {m.workflow ? String(m.workflow) : plan.data?.workflowId ? String(plan.data.workflowId) : 'default'}</span>
        </div>
        <Progress value={Number(m.progress ?? 0)} />
      </div>

      <div className="aep-tabs">
        {TABS.map((t) => (
          <button
            key={t}
            type="button"
            className="aep-tab"
            data-active={tab === t ? 'true' : 'false'}
            onClick={() => setTab(t)}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === 'summary' && (
        <div className="aep-table-wrap">
          <table className="aep-table" style={{ minWidth: 0 }}>
            <tbody>
              <tr><td>Target</td><td className="aep-mono">{JSON.stringify(m.target)}</td></tr>
              <tr><td>Project</td><td>{m.projectId ? String(m.projectId) : '—'}</td></tr>
              <tr><td>Created</td><td className="aep-mono">{String(m.createdAtUtc)}</td></tr>
              <tr><td>Assigned agent</td><td>Unassigned (future)</td></tr>
              <tr><td>Validation</td><td>{m.validation ? JSON.stringify(m.validation) : '—'}</td></tr>
            </tbody>
          </table>
        </div>
      )}

      {tab === 'plan' && (
        plan.data ? (
          <div>
            <div className="aep-table-wrap" style={{ marginBottom: '1rem' }}>
              <table className="aep-table" style={{ minWidth: 0 }}>
                <tbody>
                  <tr><td>Workflow</td><td>{String(plan.data.workflow)}</td></tr>
                  <tr><td>Affected areas</td><td>{(plan.data.affectedAreas as string[] | undefined)?.join(', ') || '—'}</td></tr>
                  <tr><td>Constraints</td><td>{(plan.data.constraints as string[] | undefined)?.join(' · ') || '—'}</td></tr>
                  <tr><td>Tests</td><td>{(plan.data.tests as string[] | undefined)?.join(' · ') || '—'}</td></tr>
                  <tr><td>Approvals</td><td className="aep-mono">{JSON.stringify(plan.data.approvals)}</td></tr>
                  <tr><td>Estimated duration</td><td>{String(plan.data.estimatedDuration)}</td></tr>
                </tbody>
              </table>
            </div>
            <h3>Explainability</h3>
            <div className="aep-table-wrap">
              <table className="aep-table" style={{ minWidth: 0 }}>
                <tbody>
                  <tr><td>Why this workflow?</td><td>{explain.whyWorkflow ?? '—'}</td></tr>
                  <tr><td>Why these files?</td><td>{explain.whyFiles ?? '—'}</td></tr>
                  <tr><td>Why these tests?</td><td>{explain.whyTests ?? '—'}</td></tr>
                  <tr><td>Why these approvals?</td><td>{explain.whyApprovals ?? '—'}</td></tr>
                </tbody>
              </table>
            </div>
            <h3>Estimated steps</h3>
            <ol>
              {((plan.data.estimatedSteps as Array<Record<string, string>>) ?? []).map((s) => (
                <li key={s.id} className="aep-mono">{s.name}</li>
              ))}
            </ol>
          </div>
        ) : (
          <EmptyState title="No execution plan" description="Plans appear for missions launched via Autonomous Mission Execution." />
        )
      )}

      {tab === 'conversation' && (
        (conversation.data?.conversation?.turns as Array<Record<string, unknown>> | undefined)?.length ? (
          <div className="aep-timeline">
            {(conversation.data?.conversation?.turns as Array<Record<string, unknown>>).map((t, i) => (
              <div key={i} className="aep-timeline-item">
                <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(t.role)} · {String(t.atUtc)}</div>
                <div>{String(t.text)}</div>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState title="No conversation" description="Conversation history is attached for NL-launched missions." />
        )
      )}

      {tab === 'timeline' && (
        events.length === 0 ? (
          <EmptyState title="No timeline events" description="Events appear when a mission run starts." />
        ) : (
          <div className="aep-timeline">
            {events.map((e, idx) => (
              <button
                key={`${e.timestamp}-${idx}`}
                type="button"
                className="aep-timeline-item"
                onClick={() => setSelectedEvent(e)}
                style={{ textAlign: 'left', width: '100%', border: 'none', background: 'transparent', color: 'inherit' }}
              >
                <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(e.timestamp)}</div>
                <strong>{String(e.event)}</strong>
                <div style={{ color: 'var(--aep-ink-muted)' }}>{String(e.message)}</div>
              </button>
            ))}
          </div>
        )
      )}

      {tab === 'artifacts' && (
        workspaces.length === 0 ? (
          <EmptyState title="No artifact workspaces" description="Workspaces are created per mission run." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead>
                <tr><th>Workspace</th><th>Run</th><th>Status</th><th>Updated</th></tr>
              </thead>
              <tbody>
                {workspaces.map((w) => (
                  <tr key={String(w.workspaceId)}>
                    <td><Link to={`/artifacts?workspace=${w.workspaceId}`}>{String(w.workspaceId)}</Link></td>
                    <td className="aep-mono">{String(w.runId)}</td>
                    <td><Status label={String(w.status)} tone={statusTone(String(w.status))} /></td>
                    <td className="aep-mono">{String(w.updatedAtUtc)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'logs' && (
        <EmptyState
          title="Logs via Artifact API"
          description="Open Artifacts and select kind=log items. Content is streamed only through Mission Control API."
        />
      )}

      {tab === 'validation' && (
        validationRow ? (
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                <tr><td>Outcome</td><td><Status label={String(validationRow.outcome)} tone={statusTone(String(validationRow.outcome))} /></td></tr>
                <tr><td>Passed</td><td>{String(validationRow.passed)}</td></tr>
                <tr><td>Failed</td><td>{String(validationRow.failed)}</td></tr>
                <tr><td>Warnings</td><td>{String(validationRow.warnings)}</td></tr>
                <tr><td>Duration</td><td className="aep-mono">{String(validationRow.durationSeconds)}s</td></tr>
                <tr><td>Reason</td><td>{String(validationRow.reason)}</td></tr>
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState title="No validation yet" description="Validation results appear after the validation step runs." />
        )
      )}

      {tab === 'checkpoints' && (
        checkpoint ? (
          <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
            {JSON.stringify(checkpoint, null, 2)}
          </pre>
        ) : (
          <EmptyState title="No checkpoint" description="Checkpoints are written when a mission run exists." />
        )
      )}

      {tab === 'reports' && (
        <EmptyState
          title="Reports"
          description="Browse report artifacts for this mission from the Artifacts view."
        />
      )}

      <Drawer
        open={selectedEvent !== null}
        title="Timeline event"
        onClose={() => setSelectedEvent(null)}
      >
        {selectedEvent ? (
          <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap' }}>
            {JSON.stringify(selectedEvent, null, 2)}
          </pre>
        ) : null}
      </Drawer>
      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
