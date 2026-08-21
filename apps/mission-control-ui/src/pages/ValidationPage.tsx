import { Link } from 'react-router-dom';
import { useValidation } from '@/api/hooks';
import { EmptyState, PageHeader, Status, statusTone } from '@/design-system/ui';

export function ValidationPage() {
  const { data, isLoading, isError, error } = useValidation();
  if (isLoading) return <EmptyState title="Loading validation" description="Aggregating validation reports…" />;
  if (isError) return <EmptyState title="Validation unavailable" description={error.message} />;

  const items = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Validation"
        description="Passed tests, failures, warnings, and execution duration across missions."
      />
      {items.length === 0 ? (
        <EmptyState title="No validation data" description="Validation rows appear after missions record results." />
      ) : (
        <div className="aep-table-wrap">
          <table className="aep-table">
            <thead>
              <tr>
                <th>Mission</th>
                <th>Outcome</th>
                <th>Passed</th>
                <th>Failed</th>
                <th>Warnings</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={String(row.missionId)}>
                  <td>
                    <Link to={`/missions/${row.missionId}`}>{String(row.missionId)}</Link>
                    <div style={{ color: 'var(--aep-ink-muted)' }}>{String(row.objective ?? '')}</div>
                  </td>
                  <td><Status label={String(row.outcome)} tone={statusTone(String(row.outcome))} /></td>
                  <td>{String(row.passed)}</td>
                  <td>{String(row.failed)}</td>
                  <td>{String(row.warnings)}</td>
                  <td className="aep-mono">{String(row.durationSeconds)}s</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
