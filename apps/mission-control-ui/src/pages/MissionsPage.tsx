import { Link } from 'react-router-dom';
import { useMissions } from '@/api/hooks';
import { EmptyState, PageHeader, Progress, Status, statusTone } from '@/design-system/ui';

function formatDuration(seconds: unknown) {
  const n = Number(seconds ?? 0);
  if (!Number.isFinite(n) || n <= 0) return '—';
  const m = Math.floor(n / 60);
  const s = n % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export function MissionsPage() {
  const { data, isLoading, isError, error } = useMissions();
  if (isLoading) return <EmptyState title="Loading missions" description="Compiling mission roster…" />;
  if (isError) return <EmptyState title="Missions unavailable" description={error.message} />;

  const items = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Missions"
        description="Status, progress, and execution posture for every controlled engineering mission."
      />
      {items.length === 0 ? (
        <EmptyState
          title="No missions"
          description="Missions created via the API will appear in this roster with live progress."
        />
      ) : (
        <div className="aep-table-wrap">
          <table className="aep-table">
            <thead>
              <tr>
                <th>Mission</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Duration</th>
                <th>Workflow</th>
                <th>Current step</th>
                <th>Agent</th>
              </tr>
            </thead>
            <tbody>
              {items.map((m) => (
                <tr key={String(m.id)}>
                  <td>
                    <Link to={`/missions/${m.id}`}><strong>{String(m.id)}</strong></Link>
                    <div style={{ color: 'var(--aep-ink-muted)', maxWidth: 280 }}>{String(m.objective)}</div>
                  </td>
                  <td><Status label={String(m.state)} tone={statusTone(String(m.state))} /></td>
                  <td style={{ minWidth: 140 }}>
                    <Progress value={Number(m.progress ?? 0)} />
                    <div className="aep-mono" style={{ marginTop: 6 }}>{Number(m.progress ?? 0)}%</div>
                  </td>
                  <td className="aep-mono">{formatDuration(m.durationSeconds)}</td>
                  <td>{m.workflow ? String(m.workflow) : 'default'}</td>
                  <td className="aep-mono">{m.currentStep ? String(m.currentStep) : '—'}</td>
                  <td style={{ color: 'var(--aep-ink-faint)' }}>Unassigned</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
