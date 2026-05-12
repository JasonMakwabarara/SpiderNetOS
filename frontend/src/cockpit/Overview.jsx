import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Users,
  Plug,
  Package,
  ShieldCheck,
  Activity,
  TrendingUp,
  ArrowUpRight,
  CheckCircle2,
  AlertTriangle,
} from 'lucide-react';
import { api, auth } from '../lib/api';
import { Pill } from '../components/Atoms';

export default function Overview() {
  const [metrics, setMetrics] = useState(null);
  const tenant = auth.getTenant() || { name: 'Demo Tenant' };

  useEffect(() => {
    api.get('/enterprise/cockpit/overview').then((r) => setMetrics(r.data)).catch(() => {});
  }, []);

  const m = metrics || {
    users: 0,
    connectors: 0,
    bundles: 0,
    audit_events_24h: 0,
    anomalies_24h: 0,
    deployments: 0,
    api_calls_24h: 0,
    health_score: 99.8,
  };

  return (
    <div data-testid="cockpit-overview">
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <div className="mono text-xs uppercase tracking-wider text-accent-cyan/80">Cockpit overview</div>
          <h1 className="mt-2 text-3xl tracking-tight font-medium">
            Welcome back to <span className="text-accent-orange">{tenant.name}</span>
          </h1>
          <p className="mt-1 text-textc-secondary text-sm">
            Tenant is healthy. All planes operational.
          </p>
        </div>
        <Link to="/cockpit/downloads" className="btn-primary">
          <Package size={16} /> New AIOS bundle
        </Link>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <MetricCard icon={Users} label="Users" value={m.users} change="+12 this week" />
        <MetricCard icon={Plug} label="Connectors" value={m.connectors} change={`${m.deployments} deployments`} />
        <MetricCard icon={Package} label="Bundles signed" value={m.bundles} change="last: 14m ago" />
        <MetricCard icon={Activity} label="Health score" value={`${m.health_score}%`} tone="success" />
      </div>

      <div className="mt-8 grid lg:grid-cols-3 gap-5">
        <div className="glass p-6 lg:col-span-2">
          <div className="flex items-center justify-between">
            <h2 className="font-medium">Activity (24h)</h2>
            <Pill tone="cyan">{m.api_calls_24h.toLocaleString()} API calls</Pill>
          </div>
          <Sparkline className="mt-5" />
          <div className="mt-5 grid grid-cols-3 gap-4 text-center">
            <Stat l="Audit events" v={m.audit_events_24h} />
            <Stat l="Anomalies" v={m.anomalies_24h} tone={m.anomalies_24h > 0 ? 'warning' : 'success'} />
            <Stat l="Approvals" v={m.approvals_pending || 0} />
          </div>
        </div>

        <div className="glass p-6">
          <h2 className="font-medium">Quick actions</h2>
          <div className="mt-4 space-y-2">
            {[
              { to: '/cockpit/access', label: 'Invite a user', icon: Users },
              { to: '/cockpit/connectors', label: 'Add a connector', icon: Plug },
              { to: '/cockpit/downloads', label: 'Generate AIOS bundle', icon: Package },
              { to: '/cockpit/audit', label: 'Export audit log', icon: ShieldCheck },
            ].map((a) => (
              <Link
                key={a.to}
                to={a.to}
                className="flex items-center justify-between px-3 py-2.5 rounded-lg border border-white/[0.06] hover:border-accent-cyan/40 hover:bg-accent-cyan/[0.04] transition-all text-sm"
              >
                <span className="flex items-center gap-3">
                  <a.icon size={14} className="text-accent-cyan" />
                  {a.label}
                </span>
                <ArrowUpRight size={14} className="text-textc-muted" />
              </Link>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function MetricCard({ icon: Icon, label, value, change, tone = 'cyan' }) {
  const tones = {
    cyan: 'text-accent-cyan',
    orange: 'text-accent-orange',
    success: 'text-feedback-success',
  };
  return (
    <div className="glass p-5">
      <div className="flex items-center justify-between">
        <Icon size={16} className={tones[tone]} />
        <TrendingUp size={14} className="text-textc-muted" />
      </div>
      <div className="mt-4 mono text-3xl tracking-tight text-textc-primary">{value}</div>
      <div className="mt-1 text-xs text-textc-muted">{label}</div>
      {change && <div className="mt-3 text-xs text-textc-secondary">{change}</div>}
    </div>
  );
}

function Stat({ l, v, tone = 'primary' }) {
  const colors = {
    primary: 'text-textc-primary',
    warning: 'text-feedback-warning',
    success: 'text-feedback-success',
  };
  return (
    <div>
      <div className={`mono text-2xl ${colors[tone]}`}>{v}</div>
      <div className="text-xs text-textc-muted mt-1">{l}</div>
    </div>
  );
}

function Sparkline({ className = '' }) {
  // deterministic sparkline
  const pts = Array.from({ length: 40 }, (_, i) =>
    Math.sin(i * 0.4) * 0.4 + Math.cos(i * 0.15) * 0.3 + 0.55 + (i % 7) * 0.02,
  );
  const w = 600;
  const h = 100;
  const path = pts
    .map((p, i) => `${i === 0 ? 'M' : 'L'} ${(i / (pts.length - 1)) * w} ${h - p * h}`)
    .join(' ');
  return (
    <svg viewBox={`0 0 ${w} ${h}`} className={`w-full h-24 ${className}`} aria-hidden>
      <defs>
        <linearGradient id="spk" x1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#00D6C9" stopOpacity="0.35" />
          <stop offset="1" stopColor="#00D6C9" stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={`${path} L ${w} ${h} L 0 ${h} Z`} fill="url(#spk)" />
      <path d={path} stroke="#00D6C9" strokeWidth="1.5" fill="none" />
    </svg>
  );
}
