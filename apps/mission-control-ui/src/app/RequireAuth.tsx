import { Navigate, Outlet } from 'react-router-dom';
import { useMe } from '@/api/hooks';
import { EmptyState } from '@/design-system/ui';

export function RequireAuth() {
  const me = useMe();

  if (me.isLoading) {
    return <EmptyState title="AEP Mission Control" description="Establishing secure session…" />;
  }

  if (me.isError || !me.data?.user) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
}
