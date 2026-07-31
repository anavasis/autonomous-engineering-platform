import { FormEvent, useEffect, useState } from 'react';
import { api } from '@/api/client';
import { useExecutionSettings, useSettings } from '@/api/hooks';
import { Button, EmptyState, Field, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

export function SettingsPage() {
  const { data, isLoading, isError, error } = useSettings();
  const execution = useExecutionSettings();
  const [defaultProviderId, setDefaultProviderId] = useState('');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const current = execution.data?.settings?.defaultProviderId;
    setDefaultProviderId(typeof current === 'string' ? current : '');
  }, [execution.data]);

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

  return (
    <div>
      <PageHeader
        title="Settings"
        description="Deployment identity, execution providers, and platform feature flags."
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

        <div className="aep-table-wrap" style={{ marginTop: '1.25rem' }}>
          <table className="aep-table">
            <thead>
              <tr>
                <th>Provider</th>
                <th>Health</th>
                <th>Capabilities</th>
              </tr>
            </thead>
            <tbody>
              {providers.map((p) => {
                const healthRow = (p.health as Record<string, unknown> | undefined) ?? {};
                const caps = (p.capabilities as Record<string, unknown> | undefined) ?? {};
                return (
                  <tr key={String(p.id)}>
                    <td>
                      <div>{String(p.displayName)}</div>
                      <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(p.id)}</div>
                    </td>
                    <td>
                      <Status label={String(healthRow.status ?? 'unknown')} tone={statusTone(String(healthRow.status ?? ''))} />
                      <div style={{ color: 'var(--aep-ink-muted)', fontSize: '0.85rem' }}>{String(healthRow.message ?? '')}</div>
                    </td>
                    <td className="aep-mono" style={{ fontSize: '0.8rem' }}>
                      stream={String(caps.streaming)} · cancel={String(caps.cancel)} · resume={String(caps.resume)} · tokens={String(caps.maxContextTokens)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>
      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
