import { useState } from 'react';
import {
  useDeployments,
  useEnvironments,
  useGovernanceApprovals,
  useGovernanceAudit,
  useGovernanceCompliance,
  useGovernanceDashboard,
  useGovernanceTimeline,
  useQualityGates,
  useReleases,
  useRollbacks,
} from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';
import { api } from '@/api/client';

type Tab =
  | 'dashboard'
  | 'releases'
  | 'approvals'
  | 'deployments'
  | 'audit'
  | 'compliance'
  | 'gates'
  | 'rollbacks';

export function GovernancePage() {
  const dashboard = useGovernanceDashboard();
  const releases = useReleases();
  const approvals = useGovernanceApprovals();
  const deployments = useDeployments();
  const audit = useGovernanceAudit();
  const compliance = useGovernanceCompliance();
  const gates = useQualityGates();
  const rollbacks = useRollbacks();
  const environments = useEnvironments();
  const timeline = useGovernanceTimeline();
  const [tab, setTab] = useState<Tab>('dashboard');
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [title, setTitle] = useState('Release candidate');
  const [version, setVersion] = useState('1.0.0-rc.1');

  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'releases', label: 'Release Center' },
    { id: 'approvals', label: 'Approvals' },
    { id: 'deployments', label: 'Deployments' },
    { id: 'audit', label: 'Audit' },
    { id: 'compliance', label: 'Compliance' },
    { id: 'gates', label: 'Quality Gates' },
    { id: 'rollbacks', label: 'Rollback History' },
  ];

  async function refreshAll() {
    await Promise.all([
      dashboard.refetch(),
      releases.refetch(),
      approvals.refetch(),
      deployments.refetch(),
      audit.refetch(),
      compliance.refetch(),
      rollbacks.refetch(),
      timeline.refetch(),
    ]);
  }

  async function createRelease() {
    setBusy(true);
    try {
      const created = await api<{ item: Record<string, unknown> }>('/releases', {
        method: 'POST',
        body: JSON.stringify({ title, version, patchIds: ['manual'] }),
      });
      const id = String(created.item.releaseId ?? '');
      if (id) {
        await api(`/releases/${id}/submit`, {
          method: 'POST',
          body: JSON.stringify({ reviewed: true, tests: 'passed', securityScan: 'passed' }),
        });
      }
      setToast('Release candidate created');
      await refreshAll();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Create failed');
    } finally {
      setBusy(false);
    }
  }

  async function approveStage(releaseId: string, stageId: string) {
    setBusy(true);
    try {
      await api(`/releases/${releaseId}/approve`, {
        method: 'POST',
        body: JSON.stringify({ stageId }),
      });
      setToast(`Approved ${stageId}`);
      await refreshAll();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Approve failed');
    } finally {
      setBusy(false);
    }
  }

  async function deployRelease(releaseId: string) {
    setBusy(true);
    try {
      const envId = String(environments.data?.items?.[0]?.environmentId ?? 'env_development');
      await api(`/releases/${releaseId}/deploy`, {
        method: 'POST',
        body: JSON.stringify({ environmentId: envId }),
      });
      setToast('Deployment recorded');
      await refreshAll();
    } catch (e) {
      setToast(e instanceof Error ? e.message : 'Deploy failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Engineering Governance"
        description="Release management, approvals, deployments, audit, and compliance."
        actions={
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
            {tabs.map((t) => (
              <Button key={t.id} variant="ghost" onClick={() => setTab(t.id)}>{t.label}</Button>
            ))}
          </div>
        }
      />

      {tab === 'dashboard' && (
        <div style={{ display: 'grid', gap: '1rem' }}>
          <div className="aep-table-wrap">
            <table className="aep-table" style={{ minWidth: 0 }}>
              <tbody>
                <tr><td>Enabled</td><td className="aep-mono">{String(dashboard.data?.engineeringGovernance ?? false)}</td></tr>
                <tr><td>Releases</td><td className="aep-mono">{String(dashboard.data?.releaseCount ?? 0)}</td></tr>
                <tr><td>Waiting approvals</td><td className="aep-mono">{String(dashboard.data?.waitingApprovals ?? 0)}</td></tr>
                <tr><td>Open change requests</td><td className="aep-mono">{String(dashboard.data?.openChangeRequests ?? 0)}</td></tr>
                <tr><td>Deployments</td><td className="aep-mono">{String(dashboard.data?.deployments ?? 0)}</td></tr>
                <tr><td>Rollbacks</td><td className="aep-mono">{String(dashboard.data?.rollbacks ?? 0)}</td></tr>
                <tr><td>Compliance</td><td><Status label={String((dashboard.data?.compliance as { pass?: boolean } | undefined)?.pass ? 'pass' : 'review')} tone={statusTone(String((dashboard.data?.compliance as { pass?: boolean } | undefined)?.pass ? 'ok' : 'warn'))} /></td></tr>
              </tbody>
            </table>
          </div>
          <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
            {JSON.stringify(timeline.data?.items?.slice(0, 12) ?? [], null, 2)}
          </pre>
        </div>
      )}

      {tab === 'releases' && (
        <div style={{ display: 'grid', gap: '1rem' }}>
          <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap' }}>
            <input className="aep-mono" style={{ padding: '0.5rem 0.75rem' }} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Title" />
            <input className="aep-mono" style={{ padding: '0.5rem 0.75rem' }} value={version} onChange={(e) => setVersion(e.target.value)} placeholder="Version" />
            <Button disabled={busy} onClick={() => void createRelease()}>Create candidate</Button>
          </div>
          {(releases.data?.items?.length ?? 0) === 0 ? (
            <EmptyState title="No releases" description="Create a release candidate to start the pipeline." />
          ) : (
            <div className="aep-table-wrap">
              <table className="aep-table">
                <thead><tr><th>Release</th><th>Version</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                  {(releases.data?.items ?? []).map((r) => (
                    <tr key={String(r.releaseId)}>
                      <td className="aep-mono">{String(r.releaseId)}</td>
                      <td className="aep-mono">{String(r.version)}</td>
                      <td><Status label={String(r.status)} tone={statusTone(String(r.status))} /></td>
                      <td>
                        <Button disabled={busy} variant="ghost" onClick={() => void deployRelease(String(r.releaseId))}>Deploy</Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {tab === 'approvals' && (
        (approvals.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No pending approvals" description="Submitted release candidates appear here." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Release</th><th>Stage</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                {(approvals.data?.items ?? []).map((a) => (
                  <tr key={`${String(a.releaseId)}-${String(a.stageId)}`}>
                    <td className="aep-mono">{String(a.releaseId)}</td>
                    <td>{String(a.stageName ?? a.stageId)}</td>
                    <td><Status label={String(a.status)} tone={statusTone(String(a.status))} /></td>
                    <td>
                      <Button disabled={busy} onClick={() => void approveStage(String(a.releaseId), String(a.stageId))}>Approve</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'deployments' && (
        (deployments.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No deployments" description="Promote or deploy an approved release to record history." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Deployment</th><th>Release</th><th>Environment</th><th>Status</th></tr></thead>
              <tbody>
                {(deployments.data?.items ?? []).map((d) => (
                  <tr key={String(d.deploymentId)}>
                    <td className="aep-mono">{String(d.deploymentId)}</td>
                    <td className="aep-mono">{String(d.releaseId)}</td>
                    <td className="aep-mono">{String(d.environmentId)}</td>
                    <td><Status label={String(d.status)} tone={statusTone(String(d.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'audit' && (
        (audit.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No audit entries" description="Governance actions append to the audit trail." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Subject</th></tr></thead>
              <tbody>
                {(audit.data?.items ?? []).map((e) => (
                  <tr key={String(e.entryId ?? `${e.atUtc}-${e.action}`)}>
                    <td className="aep-mono">{String(e.atUtc)}</td>
                    <td className="aep-mono">{String(e.actorId)}</td>
                    <td>{String(e.action)}</td>
                    <td className="aep-mono">{String(e.subjectId ?? e.subjectType ?? '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'compliance' && (
        <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap', background: 'rgba(0,0,0,0.25)', padding: '1rem', borderRadius: 12 }}>
          {JSON.stringify(compliance.data ?? {}, null, 2)}
        </pre>
      )}

      {tab === 'gates' && (
        (gates.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No gates" description="Default quality gate catalog loads from GovernanceManager." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Gate</th><th>Kind</th><th>Status</th></tr></thead>
              <tbody>
                {(gates.data?.items ?? []).map((g) => (
                  <tr key={String(g.gateId)}>
                    <td>{String(g.name)}</td>
                    <td className="aep-mono">{String(g.kind)}</td>
                    <td><Status label={String(g.status)} tone={statusTone(String(g.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {tab === 'rollbacks' && (
        (rollbacks.data?.items?.length ?? 0) === 0 ? (
          <EmptyState title="No rollbacks" description="Rollback plans appear after a prior release is targeted." />
        ) : (
          <div className="aep-table-wrap">
            <table className="aep-table">
              <thead><tr><th>Rollback</th><th>From</th><th>To</th><th>Status</th></tr></thead>
              <tbody>
                {(rollbacks.data?.items ?? []).map((r) => (
                  <tr key={String(r.rollbackId)}>
                    <td className="aep-mono">{String(r.rollbackId)}</td>
                    <td className="aep-mono">{String(r.releaseId)}</td>
                    <td className="aep-mono">{String(r.toReleaseId)}</td>
                    <td><Status label={String(r.status)} tone={statusTone(String(r.status))} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      <ToastHost message={toast} onDismiss={() => setToast(null)} />
    </div>
  );
}
