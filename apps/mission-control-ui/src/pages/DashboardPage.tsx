import { Link } from 'react-router-dom';
import { useDashboard } from '@/api/hooks';
import { EmptyState, PageHeader, Status, statusTone } from '@/design-system/ui';

export function DashboardPage() {
  const { data, isLoading, isError, error } = useDashboard();

  if (isLoading) {
    return <EmptyState title="Synchronizing" description="Loading mission telemetry…" />;
  }
  if (isError) {
    return <EmptyState title="Dashboard unavailable" description={error.message} />;
  }

  const activity = (data?.recentActivity as Array<Record<string, unknown>>) ?? [];
  const health = data?.systemHealth as Record<string, unknown> | undefined;

  return (
    <div>
      <PageHeader
        title="Dashboard"
        description="Live posture across missions, approvals, and platform health."
      />
      <div className="aep-metric-row">
        <div className="aep-metric"><span>Active</span><strong>{String(data?.activeMissions ?? 0)}</strong></div>
        <div className="aep-metric"><span>Completed</span><strong>{String(data?.completedMissions ?? 0)}</strong></div>
        <div className="aep-metric"><span>Failed</span><strong>{String(data?.failedMissions ?? 0)}</strong></div>
        <div className="aep-metric"><span>Approvals</span><strong>{String(data?.waitingApprovals ?? 0)}</strong></div>
      </div>

      <section className="aep-dash-grid">
        <div>
          <h2 style={{ marginTop: 0 }}>Recent activity</h2>
          {activity.length === 0 ? (
            <EmptyState
              title="No missions yet"
              description="Create a project and mission to populate the operations feed."
            />
          ) : (
            <div className="aep-table-wrap">
              <table className="aep-table">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Mission</th>
                    <th>State</th>
                    <th>Message</th>
                  </tr>
                </thead>
                <tbody>
                  {activity.map((row) => (
                    <tr key={`${row.missionId}-${row.at}`}>
                      <td className="aep-mono">{String(row.at)}</td>
                      <td>
                        <Link to={`/missions/${row.missionId}`}>{String(row.missionId)}</Link>
                      </td>
                      <td>
                        <Status label={String(row.state)} tone={statusTone(String(row.state))} />
                      </td>
                      <td>{String(row.message)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
        <div>
          <h2 style={{ marginTop: 0 }}>System health</h2>
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                {Object.entries(health ?? {}).map(([key, value]) => (
                  <tr key={key}>
                    <td>{key}</td>
                    <td>
                      <Status label={String(value)} tone={statusTone(String(value))} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  );
}
