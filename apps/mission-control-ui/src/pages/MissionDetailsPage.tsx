import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import {
  useArtifacts,
  useExecutionEvents,
  useExecutionMetrics,
  useExecutionPrompt,
  useExecutionProviders,
  useMission,
  useMissionExecution,
  useMissionWorkspace,
  usePatches,
  useMissionMemory,
  useMissionAssignments,
  useSettings,
  useTimeline,
  useValidation,
} from '@/api/hooks';
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
  'execution',
  'workspace',
  'patches',
  'memory',
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
  const providers = useExecutionProviders();
  const settings = useSettings();
  const sseEnabled = Boolean((settings.data?.features as Record<string, unknown> | undefined)?.sse);
  const missionExecution = useMissionExecution(missionId);
  const sessionId = String(missionExecution.data?.session?.sessionId ?? '');
  const execEvents = useExecutionEvents(sessionId, sseEnabled);
  const execPrompt = useExecutionPrompt(sessionId);
  const execMetrics = useExecutionMetrics(sessionId);
  const missionWorkspace = useMissionWorkspace(missionId);
  const missionPatches = usePatches(missionId);
  const missionMemory = useMissionMemory(missionId);
  const missionAssignments = useMissionAssignments(missionId);
  const [tab, setTab] = useState<(typeof TABS)[number]>('summary');
  const [selectedEvent, setSelectedEvent] = useState<Record<string, unknown> | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [providerId, setProviderId] = useState('');

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

  async function cancelExecution() {
    if (!sessionId) return;
    setBusy(true);
    try {
      await api(`/execution/sessions/${sessionId}/cancel`, {
        method: 'POST',
        body: JSON.stringify({ reason: 'Cancelled from Mission Control' }),
      });
      setToast('Execution cancel requested');
      await missionExecution.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Cancel failed');
    } finally {
      setBusy(false);
    }
  }

  async function resumeExecution() {
    if (!sessionId) return;
    setBusy(true);
    try {
      const result = await api<Record<string, unknown>>(`/execution/sessions/${sessionId}/resume`, {
        method: 'POST',
        body: '{}',
      });
      setToast('Provider resume: ' + String(result.status));
      await missionExecution.refetch();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Provider resume failed');
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
              <tr>
                <td>Assigned agent</td>
                <td>
                  {(missionAssignments.data?.items ?? []).length === 0 ? (
                    'Unassigned'
                  ) : (
                    <span className="aep-mono">
                      {(missionAssignments.data?.items ?? [])
                        .map((a) => `${String(a.agentId)} (${String(a.role)} · ${String(a.status)})`)
                        .join('; ')}
                    </span>
                  )}
                </td>
              </tr>
              <tr><td>Execution provider</td><td className="aep-mono">{missionExecution.data?.session ? String(missionExecution.data.session.providerId) : 'legacy / none'}</td></tr>
              <tr><td>Validation</td><td>{m.validation ? JSON.stringify(m.validation) : '—'}</td></tr>
            </tbody>
          </table>
        </div>
      )}

      {tab === 'execution' && (
        <div style={{ display: 'grid', gap: '1.25rem' }}>
          <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', alignItems: 'end' }}>
            <label style={{ display: 'grid', gap: '0.35rem', minWidth: 220 }}>
              <span>Execution provider</span>
              <select
                className="aep-input"
                value={providerId || String(missionExecution.data?.session?.providerId ?? '')}
                onChange={(e) => setProviderId(e.target.value)}
              >
                <option value="">Legacy (no provider)</option>
                {(providers.data?.items ?? []).map((p) => (
                  <option key={String(p.id)} value={String(p.id)}>
                    {String(p.displayName)}
                  </option>
                ))}
              </select>
            </label>
            <Button variant="ghost" disabled={busy || !sessionId} onClick={() => void cancelExecution()}>Cancel execution</Button>
            <Button variant="ghost" disabled={busy || !sessionId} onClick={() => void resumeExecution()}>Resume provider</Button>
          </div>

          {missionExecution.data?.session ? (
            <>
              <div className="aep-table-wrap">
                <table className="aep-table" style={{ minWidth: 0 }}>
                  <tbody>
                    <tr><td>Session</td><td className="aep-mono">{String(missionExecution.data.session.sessionId)}</td></tr>
                    <tr><td>Status</td><td><Status label={String(missionExecution.data.session.status)} tone={statusTone(String(missionExecution.data.session.status))} /></td></tr>
                    <tr><td>Provider</td><td className="aep-mono">{String(missionExecution.data.session.providerId)}</td></tr>
                    <tr><td>Message</td><td>{String(missionExecution.data.session.message ?? '')}</td></tr>
                  </tbody>
                </table>
              </div>

              <div>
                <h3>Provider timeline</h3>
                {(execEvents.data?.items ?? []).length === 0 ? (
                  <EmptyState title="No provider events" description="Events appear while a provider session runs." />
                ) : (
                  <div className="aep-timeline">
                    {(execEvents.data?.items ?? []).map((e) => (
                      <div key={String(e.seq)} className="aep-timeline-item">
                        <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>
                          #{String(e.seq)} · {String(e.atUtc)} · {String(e.type)}
                        </div>
                        <div>{String(e.message)}</div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <h3>Prompt preview</h3>
                {execPrompt.data?.preview ? (
                  <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
                    {String(execPrompt.data.preview)}
                  </pre>
                ) : (
                  <EmptyState title="No prompt" description="Prompt bundles are captured when a provider session starts." />
                )}
              </div>

              <div>
                <h3>Execution metrics</h3>
                {execMetrics.data?.usage ? (
                  <div className="aep-table-wrap">
                    <table className="aep-table" style={{ minWidth: 0 }}>
                      <tbody>
                        {Object.entries(execMetrics.data.usage as Record<string, unknown>).map(([k, v]) => (
                          <tr key={k}><td>{k}</td><td className="aep-mono">{String(v)}</td></tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <EmptyState title="No metrics" description="Token and cost accounting appear after provider completion." />
                )}
              </div>
            </>
          ) : (
            <EmptyState
              title="No provider session"
              description="Provider sessions appear when a mission run executes with a selected engineering execution provider. Configure a default provider in Settings, or pass providerId in run attributes."
            />
          )}
        </div>
      )}

      {tab === 'workspace' && (
        missionWorkspace.data?.workspace ? (
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                <tr><td>Workspace</td><td className="aep-mono"><Link to="/workspaces">{String(missionWorkspace.data.workspace.workspaceId)}</Link></td></tr>
                <tr><td>Status</td><td><Status label={String(missionWorkspace.data.workspace.status)} tone={statusTone(String(missionWorkspace.data.workspace.status))} /></td></tr>
                <tr><td>Fingerprint</td><td className="aep-mono">{String(missionWorkspace.data.workspace.reproducibilityFingerprint ?? '—')}</td></tr>
                <tr><td>Quota</td><td className="aep-mono">{JSON.stringify(missionWorkspace.data.workspace.quota ?? {})}</td></tr>
                <tr><td>Health</td><td><Status label={String((missionWorkspace.data.workspace.health as Record<string, unknown> | undefined)?.status ?? '—')} tone={statusTone(String((missionWorkspace.data.workspace.health as Record<string, unknown> | undefined)?.status ?? ''))} /></td></tr>
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState title="No engineering workspace" description="A workspace is created when provider-backed execution provisions isolation for this mission." />
        )
      )}

      {tab === 'patches' && (
        (missionPatches.data?.items ?? []).length ? (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Patch</th><th>Status</th><th>Score</th><th>Ready</th></tr></thead>
              <tbody>
                {(missionPatches.data?.items ?? []).map((p) => (
                  <tr key={String(p.patchId)}>
                    <td><Link to="/patches" className="aep-mono">{String(p.patchId)}</Link></td>
                    <td><Status label={String(p.status)} tone={statusTone(String(p.status))} /></td>
                    <td className="aep-mono">{String(p.score)} ({String(p.grade)})</td>
                    <td>{String(p.mergeReady)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState title="No patches" description="Patches appear after provider-backed execution creates a reviewable change set." />
        )
      )}

      {tab === 'memory' && (
        (missionMemory.data?.items ?? []).length ? (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Knowledge</th><th>Kind</th><th>Status</th><th>Summary</th></tr></thead>
              <tbody>
                {(missionMemory.data?.items ?? []).map((k) => (
                  <tr key={String(k.knowledgeId)}>
                    <td><Link to="/knowledge" className="aep-mono">{String(k.knowledgeId)}</Link></td>
                    <td>{String(k.kind)}</td>
                    <td><Status label={String(k.status)} tone={statusTone(String(k.status))} /></td>
                    <td>{String(k.summary)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <EmptyState title="No mission memory" description="Knowledge records are captured from executions, patches, and workspace seals." />
        )
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
        (execEvents.data?.items ?? []).length ? (
          <div className="aep-timeline">
            {(execEvents.data?.items ?? []).map((e) => (
              <div key={`log-${String(e.seq)}`} className="aep-timeline-item">
                <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>
                  {String(e.atUtc)} · {String(e.type)}
                </div>
                <div>{String(e.message)}</div>
              </div>
            ))}
          </div>
        ) : (
          <EmptyState
            title="Provider logs"
            description="Provider log events appear here during engineering execution. Artifact log files remain available under Artifacts."
          />
        )
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
