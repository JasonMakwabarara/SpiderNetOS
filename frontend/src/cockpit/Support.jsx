import React from 'react';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { LifeBuoy, MessageSquare, Phone, ShieldAlert } from 'lucide-react';

export default function Support() {
  return (
    <div data-testid="cockpit-support">
      <Header title="Support & SLA" subtitle="Open tickets, request a P0 escalation, and review your SLA targets." />
      <div className="grid md:grid-cols-3 gap-5 mb-6">
        {[
          { l: 'Plan', v: 'Enterprise' },
          { l: 'SLA target', v: '99.95% uptime' },
          { l: 'P0 response', v: '< 30 minutes' },
        ].map((m) => (
          <div key={m.l} className="glass p-5">
            <div className="text-xs text-textc-muted">{m.l}</div>
            <div className="mono text-2xl mt-1">{m.v}</div>
          </div>
        ))}
      </div>
      <div className="grid md:grid-cols-2 gap-5">
        <div className="glass p-6">
          <h2 className="font-medium flex items-center gap-2"><MessageSquare size={16} className="text-accent-cyan" /> Open a ticket</h2>
          <div className="mt-4 space-y-3">
            <select className="input-field">
              <option>Severity — Low (general question)</option>
              <option>Severity — Medium (degraded feature)</option>
              <option>Severity — High (production impact)</option>
              <option>Severity — P0 (outage)</option>
            </select>
            <input className="input-field" placeholder="Short summary…" />
            <textarea className="input-field min-h-[120px]" placeholder="Reproduction steps, logs, links…" />
            <button className="btn-primary">Submit ticket</button>
          </div>
        </div>
        <div className="glass p-6">
          <h2 className="font-medium flex items-center gap-2"><ShieldAlert size={16} className="text-feedback-warning" /> Emergency escalation</h2>
          <p className="mt-3 text-sm text-textc-secondary">
            For production-impacting incidents, the on-call engineer is paged immediately. Step-up
            authentication is required before initiating an escalation.
          </p>
          <button className="btn-ghost mt-5"><Phone size={15} /> Page on-call</button>
          <div className="mt-6 pt-5 border-t border-white/[0.06] text-sm">
            <div className="text-textc-muted">Named CSM</div>
            <div className="mt-1">Priya Anand · <span className="mono text-accent-cyan">priya@spidernetos.com</span></div>
          </div>
        </div>
      </div>
    </div>
  );
}
