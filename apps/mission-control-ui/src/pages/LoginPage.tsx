import { FormEvent, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { LogoMark } from '@/branding/Logo';
import { useLogin, useMe } from '@/api/hooks';
import { Button, Field } from '@/design-system/ui';

export function LoginPage() {
  const me = useMe();
  const login = useLogin();
  const [username, setUsername] = useState('admin');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  if (me.data?.user) {
    return <Navigate to="/" replace />;
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      await login.mutateAsync({ username, password });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed');
    }
  }

  return (
    <div className="aep-login">
      <form className="aep-login-panel" onSubmit={onSubmit}>
        <LogoMark size={48} />
        <h1>AEP Mission Control</h1>
        <p>Sign in to operate missions, approvals, and artifacts.</p>
        <Field label="Username">
          <input
            className="aep-input"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            autoComplete="username"
            required
          />
        </Field>
        <Field label="Password">
          <input
            className="aep-input"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </Field>
        {error ? <p style={{ color: 'var(--aep-danger)', marginTop: 0 }}>{error}</p> : null}
        <Button type="submit" disabled={login.isPending} style={{ width: '100%' }}>
          {login.isPending ? 'Signing in…' : 'Enter Mission Control'}
        </Button>
      </form>
    </div>
  );
}
