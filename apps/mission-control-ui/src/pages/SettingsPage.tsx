import { useSettings } from '@/api/hooks';
import { EmptyState, PageHeader, Status, statusTone } from '@/design-system/ui';

export function SettingsPage() {
  const { data, isLoading, isError, error } = useSettings();
  if (isLoading) return <EmptyState title="Loading settings" description="Reading operator configuration…" />;
  if (isError) return <EmptyState title="Settings unavailable" description={error.message} />;

  const health = data?.health as Record<string, unknown> | undefined;
  const user = data?.user as Record<string, unknown> | undefined;
  const features = data?.features as Record<string, unknown> | undefined;

  return (
    <div>
      <PageHeader
        title="Settings"
        description="Deployment identity, operator profile, and platform feature flags."
      />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1.25rem' }}>
        <div className="aep-table-wrap">
          <table className="aep-table" style={{ minWidth: 0 }}>
            <tbody>
              <tr><td>Product</td><td>{String(data?.product)}</td></tr>
              <tr><td>Version</td><td className="aep-mono">{String(data?.version)}</td></tr>
              <tr><td>Deployment</td><td className="aep-mono">{String(data?.deployment)}</td></tr>
              <tr><td>Poll interval</td><td className="aep-mono">{String(data?.pollIntervalSeconds)}s</td></tr>
              <tr><td>Operator</td><td>{String(user?.displayName)} ({String(user?.role)})</td></tr>
            </tbody>
          </table>
        </div>
        <div className="aep-table-wrap">
          <table className="aep-table" style={{ minWidth: 0 }}>
            <thead><tr><th colSpan={2}>Features / Health</th></tr></thead>
            <tbody>
              {Object.entries(features ?? {}).map(([k, v]) => (
                <tr key={k}><td>{k}</td><td>{String(v)}</td></tr>
              ))}
              {Object.entries(health ?? {}).map(([k, v]) => (
                <tr key={k}>
                  <td>{k}</td>
                  <td><Status label={String(v)} tone={statusTone(String(v))} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
