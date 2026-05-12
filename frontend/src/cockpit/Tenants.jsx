import React, { useEffect, useState } from 'react';
import { api, auth } from '../lib/api';
import { Building2, Check } from 'lucide-react';
import { Pill } from '../components/Atoms';

export default function Tenants() {
  const [list, setList] = useState([]);
  const tenant = auth.getTenant();
  useEffect(() => {
    api.get('/enterprise/tenants').then((r) => setList(r.data.data)).catch(() => setList([]));
  }, []);
  return (
    <div data-testid="cockpit-tenants">
      <Header title="Tenants & workspaces" subtitle="Manage tenants, domains, regions and ownership." />
      <div className="glass overflow-hidden">
        <div className="grid grid-cols-12 px-6 py-3 border-b border-white/[0.06] mono text-xs uppercase tracking-wider text-textc-muted">
          <div className="col-span-4">Name</div>
          <div className="col-span-3">Slug</div>
          <div className="col-span-2">Region</div>
          <div className="col-span-2">Plan</div>
          <div className="col-span-1 text-right">Status</div>
        </div>
        {(list.length ? list : [tenant].filter(Boolean)).map((t) => (
          <div key={t.id || t.slug} className="grid grid-cols-12 px-6 py-4 border-b border-white/[0.04] last:border-0 text-sm hover:bg-accent-cyan/[0.04]">
            <div className="col-span-4 flex items-center gap-3">
              <div className="size-8 rounded-lg bg-bg-s3 border border-white/10 flex items-center justify-center">
                <Building2 size={14} className="text-accent-cyan" />
              </div>
              <span className="font-medium">{t.name}</span>
            </div>
            <div className="col-span-3 mono text-textc-secondary">{t.slug}</div>
            <div className="col-span-2 mono text-textc-secondary">{t.region}</div>
            <div className="col-span-2 text-textc-secondary">{t.plan || 'Enterprise'}</div>
            <div className="col-span-1 text-right">
              <Pill tone="success" icon={<Check size={11} />}>{t.status || 'live'}</Pill>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export function Header({ title, subtitle, action }) {
  return (
    <div className="mb-8 flex items-end justify-between gap-4 flex-wrap">
      <div>
        <h1 className="text-3xl tracking-tight font-medium">{title}</h1>
        {subtitle && <p className="mt-1 text-textc-secondary text-sm">{subtitle}</p>}
      </div>
      {action}
    </div>
  );
}
