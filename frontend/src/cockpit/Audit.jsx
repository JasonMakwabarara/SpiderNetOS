import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { ShieldCheck, Download, Filter } from 'lucide-react';

export default function Audit() {
  const [events, setEvents] = useState([]);
  const [filter, setFilter] = useState('');
  useEffect(() => {
    api.get('/enterprise/audit').then((r) => setEvents(r.data.data)).catch(() => setEvents([]));
  }, []);

  const filtered = events.filter((e) =>
    !filter ||
    (e.action + e.actor + e.target).toLowerCase().includes(filter.toLowerCase()),
  );

  return (
    <div data-testid="cockpit-audit">
      <Header
        title="Audit trail"
        subtitle="Immutable, signed event log of every governance-relevant action."
        action={
          <button className="btn-ghost">
            <Download size={15} /> Export
          </button>
        }
      />
      <div className="glass p-4 mb-5 flex items-center gap-3">
        <Filter size={15} className="text-textc-muted" />
        <input
          data-testid="audit-filter"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          placeholder="Filter by action, actor, or target…"
          className="bg-transparent border-0 outline-none flex-1 text-sm placeholder-textc-muted"
        />
        <Pill tone="cyan">{filtered.length} events</Pill>
      </div>

      <div className="glass">
        <ol className="relative">
          {filtered.map((e, i) => (
            <li
              key={e.id}
              data-testid={`audit-event-${e.id}`}
              className="flex gap-4 px-6 py-4 border-b border-white/[0.04] last:border-0 hover:bg-accent-cyan/[0.04]"
            >
              <div className="relative w-3 flex-shrink-0 mt-1.5">
                <span
                  className={`absolute size-2.5 rounded-full ${
                    e.severity === 'high'
                      ? 'bg-feedback-danger'
                      : e.severity === 'warn'
                      ? 'bg-feedback-warning'
                      : 'bg-accent-cyan'
                  }`}
                />
                {i !== filtered.length - 1 && (
                  <span className="absolute top-3 left-1 w-px h-full bg-white/[0.06]" />
                )}
              </div>
              <div className="flex-1 grid md:grid-cols-12 gap-2 text-sm">
                <div className="md:col-span-3 mono text-xs text-textc-muted">
                  {new Date(e.ts).toLocaleString()}
                </div>
                <div className="md:col-span-3">
                  <span className="mono text-textc-primary">{e.action}</span>
                </div>
                <div className="md:col-span-3 text-textc-secondary truncate">{e.actor}</div>
                <div className="md:col-span-3 text-textc-secondary truncate">{e.target}</div>
              </div>
            </li>
          ))}
          {filtered.length === 0 && (
            <div className="p-10 text-center text-textc-muted text-sm">No events match this filter.</div>
          )}
        </ol>
      </div>
    </div>
  );
}
