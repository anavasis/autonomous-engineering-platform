import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApprovalDecision, useApprovals } from '@/api/hooks';
import { Button, EmptyState, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

export function ApprovalsPage() {
  const { data, isLoading, isError, error } = useApprovals();
  const decide = useApprovalDecision();
  const [toast, setToast] = useState<string | null>(null);
  const [tone, setTone] = useState<'ok' | 'danger'>('ok');

  if (isLoading) return <EmptyState title="Loading approvals" description="Building approval queue…" />;
  if (isError) return <EmptyState title="Approvals unavailable" description={error.message} />;

  const items = data?.items ?? [];

  async function act(item: Record<string, unknown>, decision: 'approved' | 'rejected') {
    try {
      await decide.mutateAsync({
        missionId: String(item.missionId),
        kind: String(item.kind),
        decision,
        runId: item.runId ? String(item.runId) : undefined,
        gateId: item.gateId ? String(item.gateId) : undefined,
        rationale: decision === 'approved' ? 'Approved from Mission Control' : 'Rejected from Mission Control',
      });
      setTone('ok');
      setToast(`${decision} · ${String(item.missionId)}`);
    } catch (e) {
      setTone('danger');
      setToast(e instanceof Error ? e.message : 'Decision failed');
    }
  }

  return (
    <div>
      <PageHeader
        title="Approvals"
        description="Inspection, implementation gates, and merge (commit) decisions awaiting operators."
      />
      {items.length === 0 ? (
        <EmptyState
          title="Queue clear"
          description="No inspection, merge, or gate approvals are waiting."
        />
      ) : (
        <div className="aep-table-wrap">
          <table className="aep-table">
            <thead>
              <tr>
                <th>Mission</th>
                <th>Kind</th>
                <th>Message</th>
                <th>Updated</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, idx) => (
                <tr key={`${item.missionId}-${item.kind}-${idx}`}>
                  <td>
                    <Link to={`/missions/${item.missionId}`}>{String(item.missionId)}</Link>
                    <div style={{ color: 'var(--aep-ink-muted)' }}>{String(item.objective)}</div>
                  </td>
                  <td><Status label={String(item.kind)} tone={statusTone(String(item.kind))} /></td>
                  <td>{String(item.message)}</td>
                  <td className="aep-mono">{String(item.updatedAtUtc)}</td>
                  <td style={{ display: 'flex', gap: '0.5rem' }}>
                    <Button
                      onClick={() => void act(item, 'approved')}
                      disabled={decide.isPending}
                    >
                      Approve
                    </Button>
                    <Button
                      variant="danger"
                      onClick={() => void act(item, 'rejected')}
                      disabled={decide.isPending}
                    >
                      Reject
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <ToastHost message={toast} tone={tone} onDismiss={() => setToast(null)} />
    </div>
  );
}
