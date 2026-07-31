import { NavLink, Outlet } from 'react-router-dom';
import { useState } from 'react';
import { LogoMark } from '@/branding/Logo';
import { useApprovals, useDashboard, useLogout, useMe } from '@/api/hooks';
import { Button, Status, statusTone } from '@/design-system/ui';

const NAV = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/missions/new', label: 'New Mission' },
  { to: '/projects', label: 'Projects' },
  { to: '/missions', label: 'Missions', end: true },
  { to: '/timeline', label: 'Timeline' },
  { to: '/artifacts', label: 'Artifacts' },
  { to: '/workspaces', label: 'Workspaces' },
  { to: '/patches', label: 'Patches' },
  { to: '/validation', label: 'Validation' },
  { to: '/approvals', label: 'Approvals' },
  { to: '/settings', label: 'Settings' },
];

export function AppShell() {
  const [open, setOpen] = useState(false);
  const me = useMe();
  const dashboard = useDashboard();
  const approvals = useApprovals();
  const logout = useLogout();

  const health = (dashboard.data?.systemHealth as { status?: string } | undefined)?.status ?? '…';
  const waiting = approvals.data?.items.length ?? 0;

  return (
    <div className="aep-shell" data-nav-open={open ? 'true' : 'false'}>
      <aside className="aep-sidebar">
        <div className="aep-brand">
          <LogoMark />
          <div className="aep-brand-text">
            <strong>AEP Mission Control</strong>
            <span>Operations Console</span>
          </div>
        </div>
        <nav className="aep-nav">
          {NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              onClick={() => setOpen(false)}
              className={({ isActive }) => (isActive ? 'active' : undefined)}
            >
              <span>{item.label}</span>
              {item.to === '/approvals' && waiting > 0 ? (
                <span className="aep-nav-badge">{waiting}</span>
              ) : (
                <span />
              )}
            </NavLink>
          ))}
        </nav>
        <div className="aep-sidebar-foot">
          v0.5.0 · aep.anavasis.tech
        </div>
      </aside>

      <div className="aep-main">
        <header className="aep-topbar">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <button className="aep-mobile-toggle" onClick={() => setOpen((v) => !v)} type="button">
              Menu
            </button>
            <div className="aep-topbar-status">
              <Status label={`system ${health}`} tone={statusTone(health)} />
              <span className="aep-mono">{me.data?.user.displayName}</span>
            </div>
          </div>
          <Button
            variant="ghost"
            onClick={() => logout.mutate()}
            disabled={logout.isPending}
          >
            Sign out
          </Button>
        </header>
        <main className="aep-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
