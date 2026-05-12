import React from 'react';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { Users, Shield } from 'lucide-react';

const ROLES = [
  { name: 'Tenant Owner', caps: ['tenant.*', 'billing.*', 'keys.*', 'domains.*'], color: 'orange' },
  { name: 'Organization Admin', caps: ['users.manage', 'connectors.manage', 'aios.request'], color: 'cyan' },
  { name: 'Security Admin', caps: ['sso.configure', 'mfa.policy', 'audit.export'], color: 'cyan' },
  { name: 'Integration Admin', caps: ['connectors.*', 'api_keys.manage'], color: 'cyan' },
  { name: 'Developer', caps: ['api.explorer', 'sandbox.*'], color: 'neutral' },
  { name: 'Workflow Builder', caps: ['flows.create', 'flows.edit', 'agents.*'], color: 'neutral' },
  { name: 'Auditor', caps: ['audit.view', 'logs.read'], color: 'neutral' },
  { name: 'Support Delegate', caps: ['scoped.read'], color: 'neutral' },
];

const USERS = [
  { e: 'jane@acme.com', r: 'Tenant Owner', mfa: 'WebAuthn', s: 'active' },
  { e: 'lukas@acme.com', r: 'Organization Admin', mfa: 'TOTP', s: 'active' },
  { e: 'priya@acme.com', r: 'Security Admin', mfa: 'WebAuthn', s: 'active' },
  { e: 'devops@acme.com', r: 'Integration Admin', mfa: 'TOTP', s: 'active' },
  { e: 'audit@acme.com', r: 'Auditor', mfa: 'WebAuthn', s: 'invited' },
];

export default function AccessControl() {
  return (
    <div data-testid="cockpit-access">
      <Header title="Access (RBAC)" subtitle="Roles, capabilities, and member assignments." />
      <div className="grid lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 glass overflow-hidden">
          <div className="px-6 py-4 border-b border-white/[0.06] flex items-center justify-between">
            <h2 className="font-medium flex items-center gap-2">
              <Users size={16} className="text-accent-cyan" /> Members
            </h2>
            <Pill tone="cyan">SCIM enabled</Pill>
          </div>
          <div className="grid grid-cols-12 px-6 py-3 border-b border-white/[0.06] mono text-xs uppercase tracking-wider text-textc-muted">
            <div className="col-span-5">Email</div>
            <div className="col-span-4">Role</div>
            <div className="col-span-2">MFA</div>
            <div className="col-span-1 text-right">Status</div>
          </div>
          {USERS.map((u, i) => (
            <div key={i} className="grid grid-cols-12 px-6 py-3 border-b border-white/[0.04] last:border-0 text-sm hover:bg-accent-cyan/[0.04]">
              <div className="col-span-5 mono text-textc-secondary">{u.e}</div>
              <div className="col-span-4">{u.r}</div>
              <div className="col-span-2 mono text-xs text-textc-secondary">{u.mfa}</div>
              <div className="col-span-1 text-right">
                <Pill tone={u.s === 'active' ? 'success' : 'warning'}>{u.s}</Pill>
              </div>
            </div>
          ))}
        </div>
        <div className="glass">
          <div className="px-6 py-4 border-b border-white/[0.06] flex items-center gap-2">
            <Shield size={16} className="text-accent-cyan" />
            <h2 className="font-medium">Roles & capabilities</h2>
          </div>
          <ul className="p-4 space-y-3">
            {ROLES.map((r) => (
              <li key={r.name} className="p-3 rounded-lg border border-white/[0.05] bg-bg-s2/40">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium">{r.name}</span>
                  <Pill tone={r.color}>{r.caps.length}</Pill>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {r.caps.map((c) => (
                    <code key={c} className="mono text-[10px] px-2 py-0.5 rounded-md border border-white/[0.06] text-textc-secondary">
                      {c}
                    </code>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
