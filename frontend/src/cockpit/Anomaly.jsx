import React from 'react';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { Activity, AlertTriangle } from 'lucide-react';

const ANOMS = [
  {
    title: 'Connector latency spike — Salesforce',
    sev: 'warn',
    desc: 'P95 latency 2.4× baseline for 7 minutes. Auto-throttle engaged.',
    ts: '14m ago',
    action: 'Auto-mitigated',
  },
  {
    title: 'RBAC drift — role.editor.capabilities',
    sev: 'high',
    desc: 'Editor role gained users.manage outside approved policy. Reverted.',
    ts: '1h ago',
    action: 'Reverted',
  },
  {
    title: 'Unusual token volume — api_key kt_3f',
    sev: 'warn',
    desc: 'Token issued 3,210 reqs in 60s — 7× baseline. Soft-throttled.',
    ts: '3h ago',
    action: 'Throttled',
  },
  {
    title: 'Failed bundle verification attempt',
    sev: 'high',
    desc: 'Installer reported checksum mismatch on bdl_771a. Refused install.',
    ts: '6h ago',
    action: 'Blocked',
  },
];

export default function Anomaly() {
  return (
    <div data-testid="cockpit-anomaly">
      <Header title="Anomaly detection" subtitle="Continuous baseline drift, RBAC drift, and bundle integrity checks." />
      <div className="grid md:grid-cols-4 gap-4 mb-6">
        {[
          { l: 'Open anomalies', v: '2', t: 'warning' },
          { l: 'Mitigated 24h', v: '11', t: 'success' },
          { l: 'False positives', v: '3.1%', t: 'cyan' },
          { l: 'Mean time to mitigate', v: '47s', t: 'cyan' },
        ].map((m) => (
          <div key={m.l} className="glass p-5">
            <div className="mono text-3xl tracking-tight">
              {m.v}
            </div>
            <div className="mt-1 text-xs text-textc-muted">{m.l}</div>
          </div>
        ))}
      </div>
      <div className="glass overflow-hidden">
        <div className="px-6 py-4 border-b border-white/[0.06] flex items-center gap-2">
          <Activity size={16} className="text-accent-cyan" />
          <h2 className="font-medium">Recent anomalies</h2>
        </div>
        {ANOMS.map((a) => (
          <div key={a.title} className="px-6 py-4 border-b border-white/[0.04] last:border-0 grid grid-cols-12 gap-4 items-start hover:bg-accent-cyan/[0.04]">
            <div className="col-span-12 md:col-span-7">
              <div className="flex items-center gap-2">
                <AlertTriangle
                  size={14}
                  className={a.sev === 'high' ? 'text-feedback-danger' : 'text-feedback-warning'}
                />
                <span className="font-medium">{a.title}</span>
              </div>
              <p className="text-sm text-textc-secondary mt-1.5">{a.desc}</p>
            </div>
            <div className="col-span-6 md:col-span-2 mono text-xs text-textc-muted">{a.ts}</div>
            <div className="col-span-6 md:col-span-3 text-right">
              <Pill tone={a.action === 'Reverted' || a.action === 'Blocked' ? 'danger' : 'success'}>
                {a.action}
              </Pill>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
