import { useProjects } from '@/api/hooks';
import { EmptyState, PageHeader, Status, statusTone } from '@/design-system/ui';

export function ProjectsPage() {
  const { data, isLoading, isError, error } = useProjects();
  if (isLoading) return <EmptyState title="Loading projects" description="Reading project registry…" />;
  if (isError) return <EmptyState title="Projects unavailable" description={error.message} />;

  const items = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Projects"
        description="Registered engineering targets, repository bindings, and recent mission activity."
      />
      {items.length === 0 ? (
        <EmptyState
          title="No projects registered"
          description="Projects appear here once created through the Mission Control API."
        />
      ) : (
        <div className="aep-table-wrap">
          <table className="aep-table">
            <thead>
              <tr>
                <th>Project</th>
                <th>Status</th>
                <th>Repository</th>
                <th>Last mission</th>
                <th>Workflow</th>
              </tr>
            </thead>
            <tbody>
              {items.map((p) => {
                const last = p.lastMission as Record<string, unknown> | null;
                const repo = p.repository as Record<string, unknown> | null;
                return (
                  <tr key={String(p.id)}>
                    <td>
                      <strong>{String(p.displayName)}</strong>
                      <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(p.slug)}</div>
                    </td>
                    <td><Status label={String(p.status)} tone={statusTone(String(p.status))} /></td>
                    <td>
                      <Status label={String(p.repositoryStatus)} tone={statusTone(String(p.repositoryStatus))} />
                      {repo ? <div className="aep-mono">{String(repo.provider)}:{String(repo.repository)}</div> : null}
                    </td>
                    <td>{last ? String(last.id) : '—'}</td>
                    <td>{p.activeWorkflow ? String(p.activeWorkflow) : '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
