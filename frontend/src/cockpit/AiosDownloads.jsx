import React, { useEffect, useState } from 'react';
import { api, auth } from '../lib/api';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { Package, Download, Copy, Sparkles, Loader2, Shield, Check, Server } from 'lucide-react';

const BACKEND_URL = process.env.REACT_APP_BACKEND_URL || '';

export default function AiosDownloads() {
  const [list, setList] = useState([]);
  const [target, setTarget] = useState('linux-x86_64');
  const [components, setComponents] = useState(['runtime', 'connectors', 'cockpit-agent']);
  const [busy, setBusy] = useState(false);
  const tenant = auth.getTenant() || { id: 'tnt_demo' };

  const load = () =>
    api
      .get(`/enterprise/aios/bundles?tenant_id=${tenant.id}`)
      .then((r) => setList(r.data.data))
      .catch(() => setList([]));
  useEffect(() => {
    load();
  }, []);

  const create = async () => {
    setBusy(true);
    try {
      await api.post('/enterprise/aios/bundle/create', {
        tenant_id: tenant.id,
        target,
        components,
      });
      await load();
    } finally {
      setBusy(false);
    }
  };

  const toggleComp = (c) => {
    const set = new Set(components);
    set.has(c) ? set.delete(c) : set.add(c);
    setComponents([...set]);
  };

  return (
    <div data-testid="cockpit-downloads">
      <Header title="AIOS Downloads" subtitle="Generate, verify, and lifecycle-manage signed AIOS bundles." />
      <div className="grid lg:grid-cols-3 gap-5">
        <div className="glass p-6 lg:col-span-1">
          <h2 className="font-medium flex items-center gap-2">
            <Sparkles size={16} className="text-accent-cyan" /> New bundle
          </h2>
          <div className="mt-5 space-y-4">
            <label className="block">
              <div className="mono text-xs uppercase tracking-wider text-textc-muted mb-1.5">Target</div>
              <select
                className="input-field"
                value={target}
                onChange={(e) => setTarget(e.target.value)}
                data-testid="downloads-target"
              >
                <option value="linux-x86_64">Linux x86_64</option>
                <option value="linux-arm64">Linux ARM64</option>
                <option value="windows-x86_64">Windows x86_64</option>
                <option value="docker">Docker</option>
              </select>
            </label>
            <div>
              <div className="mono text-xs uppercase tracking-wider text-textc-muted mb-1.5">Components</div>
              <div className="flex flex-wrap gap-2">
                {['runtime', 'connectors', 'cockpit-agent', 'observability'].map((c) => {
                  const on = components.includes(c);
                  return (
                    <button
                      key={c}
                      onClick={() => toggleComp(c)}
                      className={`px-3 py-1.5 rounded-full text-xs border transition-colors ${
                        on
                          ? 'border-accent-cyan/60 bg-accent-cyan/[0.1] text-accent-cyan'
                          : 'border-white/10 text-textc-secondary'
                      }`}
                    >
                      {c}
                    </button>
                  );
                })}
              </div>
            </div>
            <button onClick={create} disabled={busy} data-testid="downloads-create" className="btn-primary w-full">
              {busy ? <Loader2 size={16} className="animate-spin" /> : <Package size={16} />}
              Generate signed bundle
            </button>
          </div>
        </div>

        <div className="lg:col-span-2 space-y-4">
          {list.length === 0 && (
            <div className="glass p-12 text-center text-textc-secondary">
              No bundles yet. Create your first signed bundle on the left.
            </div>
          )}
          {list.map((b) => (
            <div key={b.bundle_id} className="glass p-6" data-testid={`bundle-row-${b.bundle_id}`}>
              <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                  <div className="mono text-xs uppercase tracking-wider text-textc-muted">Bundle ID</div>
                  <div className="mono text-accent-cyan text-lg mt-0.5">{b.bundle_id}</div>
                  <div className="mt-2 flex gap-2 flex-wrap">
                    <Pill tone="success" icon={<Shield size={11} />}>Signed</Pill>
                    <Pill tone="cyan">{b.target}</Pill>
                    {b.components?.map((c) => (
                      <Pill tone="neutral" key={c}>{c}</Pill>
                    ))}
                  </div>
                </div>
                <a
                  className="btn-primary"
                  href={`${BACKEND_URL}${b.download_path}`}
                  target="_blank"
                  rel="noreferrer"
                >
                  <Download size={15} /> Download
                </a>
              </div>
              <div className="mt-5 grid sm:grid-cols-2 gap-4">
                <KV label="SHA-256" v={b.sha256} />
                <KV label="Signature" v={b.signature} />
                <KV label="Size" v={`${(b.size_bytes / 1024).toFixed(1)} KB`} />
                <KV label="Expires" v={new Date(b.expires_at).toLocaleString()} />
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* installer guide */}
      <div className="mt-8 glass p-6">
        <h2 className="font-medium flex items-center gap-2">
          <Server size={16} className="text-accent-cyan" /> Installer guide
        </h2>
        <div className="mt-4 grid md:grid-cols-3 gap-4 mono text-xs">
          {[
            {
              os: 'Linux',
              cmd: `unzip bdl_*.zip\nsha256sum -c bdl_*.sha256\n./install.sh --tenant ${tenant.slug || 'demo'}`,
            },
            {
              os: 'Windows',
              cmd: `Expand-Archive bdl_*.zip\nGet-FileHash bdl_*.zip\n.\\install.ps1 -Tenant ${tenant.slug || 'demo'}`,
            },
            {
              os: 'Docker',
              cmd: `docker load < aios-runtime.tar\ndocker run -d \\\n  -e SN_TENANT=${tenant.slug || 'demo'} \\\n  spidernetos/aios:latest`,
            },
          ].map((c) => (
            <div key={c.os} className="p-4 rounded-lg bg-bg-s2/70 border border-white/[0.05]">
              <div className="text-accent-cyan mb-2 not-italic uppercase tracking-wider text-[10px]">{c.os}</div>
              <pre className="whitespace-pre-wrap text-textc-secondary leading-relaxed">{c.cmd}</pre>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function KV({ label, v }) {
  return (
    <div>
      <div className="mono text-[10px] uppercase tracking-wider text-textc-muted">{label}</div>
      <div className="mt-1 flex items-center gap-2">
        <code className="mono text-xs text-textc-primary truncate flex-1">{v}</code>
        <button
          onClick={() => navigator.clipboard?.writeText(v)}
          aria-label={`Copy ${label}`}
          className="p-1.5 rounded hover:bg-white/[0.06]"
        >
          <Copy size={12} />
        </button>
      </div>
    </div>
  );
}
