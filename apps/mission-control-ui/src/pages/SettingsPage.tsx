import { FormEvent, useEffect, useState } from 'react';
import { api } from '@/api/client';
import { useExecutionSettings, useSettings, useWorkspaceSettings } from '@/api/hooks';
import { Button, EmptyState, Field, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

export function SettingsPage() {
  const { data, isLoading, isError, error } = useSettings();
  const execution = useExecutionSettings();
  const workspaces = useWorkspaceSettings();
  const [defaultProviderId, setDefaultProviderId] = useState('');
  const [maxBytes, setMaxBytes] = useState('2147483648');
  const [keepLatestN, setKeepLatestN] = useState('20');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const current = execution.data?.settings?.defaultProviderId;
    setDefaultProviderId(typeof current === 'string' ? current : '');
  }, [execution.data]);

  useEffect(() => {
    const s = workspaces.data?.settings;
    if (!s) return;
    if (typeof s.maxBytes === 'number') setMaxBytes(String(s.maxBytes));
    if (typeof s.keepLatestN === 'number') setKeepLatestN(String(s.keepLatestN));
  }, [workspaces.data]);

  if (isLoading) return <EmptyState title="Loading settings" description="Reading operator configuration…" />;
  if (isError) return <EmptyState title="Settings unavailable" description={error.message} />;

  const health = data?.health as Record<string, unknown> | undefined;
  const user = data?.user as Record<string, unknown> | undefined;
  const features = data?.features as Record<string, unknown> | undefined;
  const providers = execution.data?.providers ?? [];

  async function saveExecution(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await api('/settings/execution', {
        method: 'PUT',
        body: JSON.stringify({
          defaultProviderId: defaultProviderId || null,
        }),
      });
      setToast('Execution settings saved');
      await execution.refetch();
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setBusy(false);
    }
  }

  async function saveWorkspaces(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await api('/settings/workspaces', {
        method: 'PUT',
        body: JSON.stringify({
          maxBytes: Number(maxBytes),
          keepLatestN: Number(keepLatestN),
        }),
      });
      setToast('Workspace settings saved');
      await workspaces.refetch();
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Save failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Settings"
        description="Deployment identity, execution providers, workspace quotas, and platform feature flags."
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

      <section style={{ marginTop: '1.75rem' }}>
        <PageHeader
          title="Execution providers"
          description="Select the default engineering execution provider. Leave empty to keep legacy Local/SSH behavior."
        />
        <form onSubmit={(e) => void saveExecution(e)} style={{ display: 'grid', gap: '1rem', maxWidth: 560 }}>
          <Field label="Default provider">
            <select
              className="aep-input"
              value={defaultProviderId}
              onChange={(e) => setDefaultProviderId(e.target.value)}
            >
              <option value="">Legacy (no provider)</option>
              {providers.map((p) => (
                <option key={String(p.id)} value={String(p.id)}>
                  {String(p.displayName)} ({String(p.id)})
                </option>
              ))}
            </select>
          </Field>
          <div>
            <Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save provider selection'}</Button>
          </div>
        </form>
      </section>

      <section style={{ marginTop: '1.75rem' }}>
        <PageHeader
          title="Workspace settings"
          description="Quotas and retention for autonomous engineering workspaces."
        />
        <form onSubmit={(e) => void saveWorkspaces(e)} style={{ display: 'grid', gap: '1rem', maxWidth: 560 }}>
          <Field label="Max bytes">
            <input className="aep-input" value={maxBytes} onChange={(e) => setMaxBytes(e.target.value)} />
          </Field>
          <Field label="Keep latest N per mission">
            <input className="aep-input" value={keepLatestN} onChange={(e) => setKeepLatestN(e.target.value)} />
          </Field>
          <div>
            <Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save workspace settings'}</Button>
          </div>
        </form>
      </section>
      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
