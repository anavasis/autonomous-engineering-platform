import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMissions, useTimeline } from '@/api/hooks';
import { Drawer, EmptyState, PageHeader } from '@/design-system/ui';

export function TimelinePage() {
  const missions = useMissions();
  const firstId = missions.data?.items[0]?.id ? String(missions.data.items[0].id) : '';
  const [missionId, setMissionId] = useState('');
  const activeId = missionId || firstId;
  const timeline = useTimeline(activeId);
  const [selected, setSelected] = useState<Record<string, unknown> | null>(null);

  const options = useMemo(() => missions.data?.items ?? [], [missions.data]);

  if (missions.isLoading) {
    return <EmptyState title="Loading timeline" description="Selecting mission context…" />;
  }

  return (
    <div>
      <PageHeader
        title="Timeline"
        description="Interactive chronological view of mission-engine events."
      />
      {options.length === 0 ? (
        <EmptyState title="No timeline sources" description="Create and run a mission to populate events." />
      ) : (
        <>
          <div className="aep-field" style={{ maxWidth: 420 }}>
            <label className="aep-label">Mission</label>
            <select
              className="aep-select"
              value={activeId}
              onChange={(e) => setMissionId(e.target.value)}
            >
              {options.map((m) => (
                <option key={String(m.id)} value={String(m.id)}>
                  {String(m.id)} — {String(m.objective).slice(0, 48)}
                </option>
              ))}
            </select>
          </div>
          <p style={{ color: 'var(--aep-ink-muted)' }}>
            Viewing <Link to={`/missions/${activeId}`}>{activeId}</Link>
          </p>
          {(timeline.data?.items.length ?? 0) === 0 ? (
            <EmptyState title="No events" description="This mission has no run timeline yet." />
          ) : (
            <div className="aep-timeline">
              {(timeline.data?.items ?? []).map((e, idx) => (
                <button
                  key={`${e.timestamp}-${idx}`}
                  type="button"
                  className="aep-timeline-item"
                  onClick={() => setSelected(e)}
                  style={{ textAlign: 'left', width: '100%', border: 'none', background: 'transparent', color: 'inherit' }}
                >
                  <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(e.timestamp)}</div>
                  <strong>{String(e.event)}</strong>
                  <div style={{ color: 'var(--aep-ink-muted)' }}>{String(e.step)} · {String(e.status)}</div>
                  <div>{String(e.message)}</div>
                </button>
              ))}
            </div>
          )}
        </>
      )}
      <Drawer open={selected !== null} title="Event detail" onClose={() => setSelected(null)}>
        {selected ? (
          <pre className="aep-mono" style={{ whiteSpace: 'pre-wrap' }}>
            {JSON.stringify(selected, null, 2)}
          </pre>
        ) : null}
      </Drawer>
    </div>
  );
}
