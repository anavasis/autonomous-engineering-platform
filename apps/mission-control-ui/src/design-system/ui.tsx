import { ReactNode, useEffect } from 'react';

export function Button({
  children,
  variant = 'primary',
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'ghost' | 'danger';
}) {
  return (
    <button className={`aep-btn aep-btn-${variant}`} {...props}>
      {children}
    </button>
  );
}

export function Field({
  label,
  children,
}: {
  label: string;
  children: ReactNode;
}) {
  return (
    <div className="aep-field">
      <label className="aep-label">{label}</label>
      {children}
    </div>
  );
}

export function Status({
  label,
  tone = 'info',
}: {
  label: string;
  tone?: 'ok' | 'warn' | 'danger' | 'info' | 'accent';
}) {
  return (
    <span className="aep-status" data-tone={tone}>
      {label}
    </span>
  );
}

export function EmptyState({
  title,
  description,
}: {
  title: string;
  description: string;
}) {
  return (
    <div className="aep-empty">
      <h3>{title}</h3>
      <p>{description}</p>
    </div>
  );
}

export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description: string;
  actions?: ReactNode;
}) {
  return (
    <header className="aep-page-header" style={{ display: 'flex', justifyContent: 'space-between', gap: '1rem', alignItems: 'flex-start' }}>
      <div>
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      {actions}
    </header>
  );
}

export function Progress({ value }: { value: number }) {
  const clamped = Math.max(0, Math.min(100, value));
  return (
    <div className="aep-progress" aria-valuenow={clamped} aria-valuemin={0} aria-valuemax={100} role="progressbar">
      <span style={{ width: `${clamped}%` }} />
    </div>
  );
}

export function Drawer({
  open,
  title,
  onClose,
  children,
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <>
      <div className="aep-drawer-backdrop" onClick={onClose} />
      <aside className="aep-drawer" role="dialog" aria-label={title}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
          <h2 style={{ margin: 0, fontSize: '1.15rem' }}>{title}</h2>
          <Button variant="ghost" onClick={onClose}>Close</Button>
        </div>
        {children}
      </aside>
    </>
  );
}

export function ToastHost({
  message,
  tone = 'ok',
  onDismiss,
}: {
  message: string | null;
  tone?: 'ok' | 'danger';
  onDismiss: () => void;
}) {
  useEffect(() => {
    if (!message) return;
    const t = window.setTimeout(onDismiss, 3200);
    return () => window.clearTimeout(t);
  }, [message, onDismiss]);

  if (!message) return null;
  return (
    <div className="aep-toast-host">
      <div className="aep-toast" data-tone={tone}>{message}</div>
    </div>
  );
}

export function statusTone(state: string): 'ok' | 'warn' | 'danger' | 'info' | 'accent' {
  if (['completed', 'passed', 'ok', 'active', 'bound'].includes(state)) return 'ok';
  if (['failed', 'timed_out', 'rejected', 'danger'].includes(state)) return 'danger';
  if (state.includes('awaiting') || ['waiting', 'suspended', 'warning'].includes(state)) return 'warn';
  if (['running', 'implementing', 'validating', 'inspecting'].includes(state)) return 'accent';
  return 'info';
}
