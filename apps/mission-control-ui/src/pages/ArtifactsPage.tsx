import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '@/api/client';
import { useArtifactWorkspace, useArtifacts } from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone } from '@/design-system/ui';

export function ArtifactsPage() {
  const [params] = useSearchParams();
  const initial = params.get('workspace') ?? '';
  const list = useArtifacts();
  const [workspaceId, setWorkspaceId] = useState(initial);
  const active = workspaceId || (list.data?.items[0] ? String(list.data.items[0].workspaceId) : '');
  const workspace = useArtifactWorkspace(active);
  const [preview, setPreview] = useState<string | null>(null);
  const [loadingId, setLoadingId] = useState<string | null>(null);

  const artifacts = useMemo(() => {
    return (workspace.data?.artifacts as Array<Record<string, unknown>> | undefined) ?? [];
  }, [workspace.data]);

  async function openContent(artifactId: string) {
    setLoadingId(artifactId);
    try {
      const data = await api<{ contents: string; artifact: Record<string, unknown> }>(
        `/artifacts/${active}/items/${artifactId}/content`,
      );
      setPreview(data.contents);
    } catch (e) {
      setPreview(e instanceof Error ? e.message : 'Failed to load content');
    } finally {
      setLoadingId(null);
    }
  }

  if (list.isLoading) {
    return <EmptyState title="Loading artifacts" description="Listing workspaces through the API…" />;
  }

  const workspaces = list.data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Artifacts"
        description="Browse reports, logs, validation outputs, generated files, and archives."
      />
      {workspaces.length === 0 ? (
        <EmptyState
          title="No artifact workspaces"
          description="Artifact workspaces are created per mission run and exposed only via the API."
        />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: '280px 1fr', gap: '1.25rem' }}>
          <div className="aep-table-wrap" style={{ maxHeight: 560, overflow: 'auto' }}>
            <table className="aep-table" style={{ minWidth: 0 }}>
              <thead><tr><th>Workspaces</th></tr></thead>
              <tbody>
                {workspaces.map((w) => (
                  <tr key={String(w.workspaceId)}>
                    <td>
                      <button
                        type="button"
                        className="aep-btn aep-btn-ghost"
                        style={{ width: '100%', justifyContent: 'flex-start' }}
                        onClick={() => { setWorkspaceId(String(w.workspaceId)); setPreview(null); }}
                      >
                        <span>
                          <div className="aep-mono">{String(w.workspaceId)}</div>
                          <div style={{ color: 'var(--aep-ink-faint)' }}>{String(w.missionId)}</div>
                        </span>
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div>
            {workspace.isLoading ? (
              <EmptyState title="Loading workspace" description="Reading manifest…" />
            ) : (
              <>
                <div style={{ marginBottom: '1rem', display: 'flex', gap: '1rem', alignItems: 'center' }}>
                  <Status label={String(workspace.data?.status ?? 'unknown')} tone={statusTone(String(workspace.data?.status ?? ''))} />
                  <span className="aep-mono">{active}</span>
                </div>
                {artifacts.length === 0 ? (
                  <EmptyState title="Empty workspace" description="No artifacts registered in the manifest." />
                ) : (
                  <div className="aep-table-wrap">
                    <table className="aep-table">
                      <thead>
                        <tr>
                          <th>Kind</th>
                          <th>Name</th>
                          <th>Size</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {artifacts.map((a) => (
                          <tr key={String(a.artifactId)}>
                            <td><Status label={String(a.kind)} tone="accent" /></td>
                            <td>
                              <strong>{String(a.name)}</strong>
                              <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(a.artifactId)}</div>
                            </td>
                            <td className="aep-mono">{String(a.byteSize)} B</td>
                            <td>
                              <Button
                                variant="ghost"
                                onClick={() => void openContent(String(a.artifactId))}
                                disabled={loadingId === a.artifactId}
                              >
                                {loadingId === a.artifactId ? 'Opening…' : 'Open'}
                              </Button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {preview !== null ? (
                  <pre className="aep-mono" style={{ marginTop: '1rem', whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.28)', padding: '1rem', borderRadius: 12, maxHeight: 360, overflow: 'auto' }}>
                    {preview}
                  </pre>
                ) : null}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
