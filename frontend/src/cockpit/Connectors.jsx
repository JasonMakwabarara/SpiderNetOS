import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { Plug, Plus, Check, AlertCircle } from 'lucide-react';

export default function Connectors() {
  const [list, setList] = useState([]);
  useEffect(() => {
    api.get('/enterprise/connectors').then((r) => setList(r.data.data)).catch(() => setList([]));
  }, []);
  return (
    <div data-testid="cockpit-connectors">
      <Header title="Connectors" subtitle="Govern your data flows to ERP, CRM, BI, IAM, and data lakes." />
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {list.map((c) => (
          <div key={c.id} className="glass glass-hover p-5" data-testid={`connector-${c.id}`}>
            <div className="flex items-center justify-between">
              <div className="size-10 rounded-lg bg-bg-s3 border border-white/10 flex items-center justify-center">
                <Plug size={16} className="text-accent-cyan" />
              </div>
              {c.status === 'connected' ? (
                <Pill tone="success" icon={<Check size={11} />}>connected</Pill>
              ) : (
                <Pill tone="warning" icon={<AlertCircle size={11} />}>{c.status}</Pill>
              )}
            </div>
            <h3 className="mt-4 font-medium">{c.name}</h3>
            <div className="mono text-xs text-textc-muted mt-1">{c.category} · {c.region}</div>
            <div className="mt-4 pt-4 border-t border-white/[0.05] flex items-center justify-between text-xs text-textc-secondary">
              <span>{c.last_sync_human}</span>
              <button className="btn-link text-xs">Manage →</button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
