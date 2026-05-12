import React, { useState } from 'react';
import { Link, Outlet, NavLink, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard,
  Building2,
  Users,
  Plug,
  Package,
  ShieldCheck,
  Activity,
  Terminal,
  Lock,
  LifeBuoy,
  ChevronDown,
  LogOut,
  Menu,
  X,
} from 'lucide-react';
import { Logo, Pill } from '../components/Atoms';
import { auth } from '../lib/api';

const NAV = [
  { to: '/cockpit', label: 'Overview', icon: LayoutDashboard, end: true },
  { to: '/cockpit/tenants', label: 'Tenants', icon: Building2 },
  { to: '/cockpit/access', label: 'Access (RBAC)', icon: Users },
  { to: '/cockpit/connectors', label: 'Connectors', icon: Plug },
  { to: '/cockpit/downloads', label: 'AIOS Downloads', icon: Package },
  { to: '/cockpit/audit', label: 'Audit', icon: ShieldCheck },
  { to: '/cockpit/anomaly', label: 'Anomalies', icon: Activity },
  { to: '/cockpit/developers', label: 'Developer Portal', icon: Terminal },
  { to: '/cockpit/security', label: 'Security', icon: Lock },
  { to: '/cockpit/support', label: 'Support', icon: LifeBuoy },
];

export default function CockpitLayout() {
  const [open, setOpen] = useState(false);
  const nav = useNavigate();
  const user = auth.getUser();
  const tenant = auth.getTenant() || { name: 'Demo Tenant', slug: 'demo', region: 'us-east-1' };

  const logout = () => {
    auth.clear();
    nav('/');
  };

  return (
    <div className="min-h-screen flex bg-bg-base text-textc-primary" data-testid="cockpit-layout">
      {/* Sidebar */}
      <aside
        className={`${
          open ? 'translate-x-0' : '-translate-x-full'
        } lg:translate-x-0 fixed lg:static inset-y-0 left-0 z-40 w-64 bg-bg-s1 border-r border-white/[0.06] transition-transform duration-200`}
      >
        <div className="h-16 flex items-center px-5 border-b border-white/[0.06]">
          <Link to="/cockpit" data-testid="cockpit-logo">
            <Logo />
          </Link>
        </div>
        <div className="px-3 py-4 border-b border-white/[0.04]">
          <TenantSwitcher tenant={tenant} />
        </div>
        <nav className="px-2 py-3 space-y-0.5 overflow-y-auto" aria-label="Cockpit">
          {NAV.map((n) => (
            <NavLink
              key={n.to}
              to={n.to}
              end={n.end}
              data-testid={`cockpit-nav-${n.label.toLowerCase().replace(/\s+/g, '-')}`}
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors ${
                  isActive
                    ? 'bg-accent-cyan/[0.08] text-textc-primary'
                    : 'text-textc-secondary hover:bg-white/[0.03] hover:text-textc-primary'
                }`
              }
              onClick={() => setOpen(false)}
            >
              <n.icon size={16} />
              {n.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col min-w-0">
        <header className="h-16 border-b border-white/[0.06] flex items-center justify-between px-5 bg-bg-base/80 backdrop-blur-xl sticky top-0 z-30">
          <button
            className="lg:hidden p-2 -ml-2"
            onClick={() => setOpen((v) => !v)}
            aria-label="Toggle navigation"
          >
            {open ? <X size={20} /> : <Menu size={20} />}
          </button>
          <div className="flex items-center gap-3 ml-auto">
            <Pill tone="success">All systems operational</Pill>
            <div className="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full border border-white/10 text-sm">
              <span className="size-6 rounded-full bg-orange-cyan mono text-bg-base text-xs font-bold flex items-center justify-center">
                {(user?.email || 'U').slice(0, 1).toUpperCase()}
              </span>
              <span className="text-textc-secondary">{user?.email || 'operator@acme.ops'}</span>
            </div>
            <button
              onClick={logout}
              data-testid="cockpit-logout"
              className="p-2 rounded-md text-textc-secondary hover:text-textc-primary hover:bg-white/[0.05]"
              aria-label="Sign out"
            >
              <LogOut size={16} />
            </button>
          </div>
        </header>
        <main className="flex-1 overflow-auto">
          <div className="container-x py-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}

function TenantSwitcher({ tenant }) {
  return (
    <button className="w-full text-left p-2.5 rounded-lg hover:bg-white/[0.03] flex items-center gap-3" data-testid="tenant-switcher">
      <div className="size-9 rounded-lg bg-bg-s3 border border-white/10 flex items-center justify-center">
        <Building2 size={16} className="text-accent-cyan" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium truncate">{tenant.name}</div>
        <div className="mono text-[10px] uppercase tracking-wider text-textc-muted">
          {tenant.slug} · {tenant.region}
        </div>
      </div>
      <ChevronDown size={14} className="text-textc-muted" />
    </button>
  );
}
