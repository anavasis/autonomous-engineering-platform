import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useArtifacts, useMission, useTimeline, useValidation } from '@/api/hooks';
import {
  Drawer,
  EmptyState,
  PageHeader,
  Progress,
  Status,
  statusTone,
} from '@/design-system/ui';

const TABS = ['summary', 'timeline', 'artifacts', 'logs', 'validation', 'checkpoints', 'reports'] as const;

export function MissionDetailsPage() {
  const { missionId = '' } = useParams();
  const mission = useMission(missionId);
  const timeline = useTimeline(missionId);
  const artifacts = useArtifacts(missionId);
  const validation = useValidation();
  const [tab, setTab] = useState<(typeof TABS)[number]>('summary');
  const [selectedEvent, setSelectedEvent] = useState<Record<string, unknown> | null>(null);

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

  return (
    <div>
      <PageHeader
        title={String(m.id)}
        description={String(m.objective)}
        actions={<Link to="/missions" className="aep-btn aep-btn-ghost">Back to missions</Link>}
      />

      <div style={{ display: 'grid', gap: '1rem', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center' }}>
          <Status label={String(m.state)} tone={statusTone(String(m.state))} />
          <span className="aep-mono">step: {m.currentStep ? String(m.currentStep) : '—'}</span>
          <span className="aep-mono">workflow: {m.workflow ? String(m.workflow) : 'default'}</span>
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
    </div>
  );
}
