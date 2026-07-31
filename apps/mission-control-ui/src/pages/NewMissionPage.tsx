import { FormEvent, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '@/api/client';
import { useProjects } from '@/api/hooks';
import { Button, EmptyState, Field, PageHeader, Status, statusTone, ToastHost } from '@/design-system/ui';

type IntakeResponse = {
  intake: Record<string, unknown>;
  questions: Array<Record<string, unknown>>;
  plan: Record<string, unknown> | null;
  conversation: Record<string, unknown> | null;
};

export function NewMissionPage() {
  const navigate = useNavigate();
  const projects = useProjects();
  const [text, setText] = useState(
    'Fix the Client Panel email formatting bug.\nInspection first.\nDo not modify email formatting.',
  );
  const [projectId, setProjectId] = useState('');
  const [intake, setIntake] = useState<IntakeResponse | null>(null);
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [tone, setTone] = useState<'ok' | 'danger'>('ok');

  const status = String(intake?.intake.status ?? '');
  const plan = intake?.plan;
  const explain = (plan?.explainability as Record<string, string> | undefined) ?? {};

  const projectOptions = useMemo(() => projects.data?.items ?? [], [projects.data]);

  async function submitIntake(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const data = await api<IntakeResponse>('/missions/intake', {
        method: 'POST',
        body: JSON.stringify({
          text,
          projectId: projectId || undefined,
          clientRequestId: 'ui_' + crypto.randomUUID().replace(/-/g, '').slice(0, 16),
        }),
      });
      setIntake(data);
      setTone('ok');
      setToast('Intake processed');
    } catch (err) {
      setTone('danger');
      setToast(err instanceof Error ? err.message : 'Intake failed');
    } finally {
      setBusy(false);
    }
  }

  async function submitClarify(e: FormEvent) {
    e.preventDefault();
    if (!intake) return;
    setBusy(true);
    try {
      const id = String(intake.intake.id);
      const data = await api<IntakeResponse>(`/missions/intake/${id}/clarify`, {
        method: 'POST',
        body: JSON.stringify({ answers }),
      });
      setIntake(data);
      setTone('ok');
      setToast('Clarification applied');
    } catch (err) {
      setTone('danger');
      setToast(err instanceof Error ? err.message : 'Clarify failed');
    } finally {
      setBusy(false);
    }
  }

  async function confirmStart() {
    if (!intake) return;
    setBusy(true);
    try {
      const id = String(intake.intake.id);
      const result = await api<Record<string, unknown>>(`/missions/intake/${id}/start`, {
        method: 'POST',
        body: JSON.stringify({ confirmed: true }),
      });
      setTone('ok');
      setToast('Mission launched');
      const missionId = String(result.missionId ?? '');
      if (missionId) {
        navigate(`/missions/${missionId}`);
      }
    } catch (err) {
      setTone('danger');
      setToast(err instanceof Error ? err.message : 'Launch failed');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="New Mission"
        description="Describe the engineering outcome in natural language. AEP plans, validates, and launches through the Mission Engine."
      />

      <form onSubmit={submitIntake} style={{ display: 'grid', gap: '1rem', maxWidth: 820, marginBottom: '1.5rem' }}>
        <Field label="Mission request">
          <textarea
            className="aep-textarea"
            rows={6}
            value={text}
            onChange={(e) => setText(e.target.value)}
            required
          />
        </Field>
        <Field label="Project (optional — skipped when context already resolves it)">
          <select className="aep-select" value={projectId} onChange={(e) => setProjectId(e.target.value)}>
            <option value="">Auto-detect / ask only if needed</option>
            {projectOptions.map((p) => (
              <option key={String(p.id)} value={String(p.id)}>
                {String(p.displayName)} ({String(p.slug)})
              </option>
            ))}
          </select>
        </Field>
        <div>
          <Button type="submit" disabled={busy}>{busy ? 'Understanding…' : 'Analyze mission'}</Button>
        </div>
      </form>

      {!intake ? (
        <EmptyState
          title="Awaiting mission text"
          description="Submit a request to generate intent, adaptive clarifications, and an execution plan preview."
        />
      ) : (
        <div style={{ display: 'grid', gap: '1.25rem' }}>
          <div style={{ display: 'flex', gap: '1rem', alignItems: 'center' }}>
            <Status label={status} tone={statusTone(status)} />
            <span className="aep-mono">{String(intake.intake.id)}</span>
          </div>

          {status === 'clarifying' && (
            <form onSubmit={submitClarify} className="aep-table-wrap" style={{ padding: '1rem' }}>
              <h2 style={{ marginTop: 0 }}>Clarifications</h2>
              <p style={{ color: 'var(--aep-ink-muted)' }}>Only unanswered gaps are requested.</p>
              {(intake.questions ?? []).map((q) => (
                <Field key={String(q.id)} label={String(q.prompt)}>
                  {Array.isArray(q.choices) && q.choices.length > 0 ? (
                    <select
                      className="aep-select"
                      value={answers[String(q.field)] ?? ''}
                      onChange={(e) => setAnswers((a) => ({ ...a, [String(q.field)]: e.target.value }))}
                      required
                    >
                      <option value="">Select…</option>
                      {(q.choices as Array<Record<string, string>>).map((c) => (
                        <option key={c.value} value={c.value}>{c.label}</option>
                      ))}
                    </select>
                  ) : (
                    <input
                      className="aep-input"
                      value={answers[String(q.field)] ?? ''}
                      onChange={(e) => setAnswers((a) => ({ ...a, [String(q.field)]: e.target.value }))}
                      required
                    />
                  )}
                </Field>
              ))}
              <Button type="submit" disabled={busy}>Submit answers</Button>
            </form>
          )}

          {status === 'rejected' && (
            <EmptyState title="Mission rejected" description={String(intake.intake.rejectReason ?? 'Invalid mission')} />
          )}

          {(status === 'ready' || status === 'launched') && plan && (
            <section>
              <h2>Execution plan preview</h2>
              <div className="aep-metric-row">
                <div className="aep-metric"><span>Project</span><strong style={{ fontSize: '1.1rem' }}>{String(plan.project ?? '—')}</strong></div>
                <div className="aep-metric"><span>Workflow</span><strong style={{ fontSize: '1.1rem' }}>{String(plan.workflow)}</strong></div>
                <div className="aep-metric"><span>Duration</span><strong style={{ fontSize: '1.1rem' }}>{String(plan.estimatedDuration)}</strong></div>
                <div className="aep-metric"><span>Approvals</span><strong style={{ fontSize: '1.1rem' }}>Inspection + Commit</strong></div>
              </div>

              <div className="aep-table-wrap" style={{ marginBottom: '1rem' }}>
                <table className="aep-table" style={{ minWidth: 0 }}>
                  <tbody>
                    <tr><td>Affected areas</td><td>{(plan.affectedAreas as string[] | undefined)?.join(', ') || '—'}</td></tr>
                    <tr><td>Constraints</td><td>{(plan.constraints as string[] | undefined)?.join(' · ') || '—'}</td></tr>
                    <tr><td>Tests</td><td>{(plan.tests as string[] | undefined)?.join(' · ') || '—'}</td></tr>
                    <tr><td>Target</td><td className="aep-mono">{JSON.stringify(plan.target)}</td></tr>
                  </tbody>
                </table>
              </div>

              <h3>Estimated steps</h3>
              <ol>
                {((plan.estimatedSteps as Array<Record<string, string>>) ?? []).map((s) => (
                  <li key={s.id} className="aep-mono">{s.name}</li>
                ))}
              </ol>

              <h3>Explainability</h3>
              <div className="aep-table-wrap">
                <table className="aep-table" style={{ minWidth: 0 }}>
                  <tbody>
                    <tr><td>Why this workflow?</td><td>{explain.whyWorkflow ?? '—'}</td></tr>
                    <tr><td>Why these files?</td><td>{explain.whyFiles ?? '—'}</td></tr>
                    <tr><td>Why these tests?</td><td>{explain.whyTests ?? '—'}</td></tr>
                    <tr><td>Why these approvals?</td><td>{explain.whyApprovals ?? '—'}</td></tr>
                  </tbody>
                </table>
              </div>

              {status === 'ready' && (
                <div style={{ marginTop: '1.25rem', display: 'flex', gap: '0.75rem' }}>
                  <Button onClick={() => void confirmStart()} disabled={busy}>
                    {busy ? 'Starting…' : 'Confirm & Start'}
                  </Button>
                  <Button variant="ghost" type="button" onClick={() => setIntake(null)}>Reset</Button>
                </div>
              )}
            </section>
          )}

          {intake.conversation && (
            <section>
              <h3>Conversation</h3>
              <div className="aep-timeline">
                {((intake.conversation.turns as Array<Record<string, unknown>>) ?? []).map((t, i) => (
                  <div key={i} className="aep-timeline-item">
                    <div className="aep-mono" style={{ color: 'var(--aep-ink-faint)' }}>{String(t.role)} · {String(t.atUtc)}</div>
                    <div>{String(t.text)}</div>
                  </div>
                ))}
              </div>
            </section>
          )}
        </div>
      )}

      <ToastHost message={toast} tone={tone} onDismiss={() => setToast(null)} />
    </div>
  );
}
