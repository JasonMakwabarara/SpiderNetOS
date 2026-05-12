import React, { useState } from 'react';
import { api } from '../lib/api';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { Terminal, Play, Loader2, Copy } from 'lucide-react';

const ENDPOINTS = [
  { m: 'GET', p: '/api/enterprise/tenants', d: 'List tenants in your enterprise' },
  { m: 'POST', p: '/api/enterprise/aios/bundle/create', d: 'Generate a signed AIOS bundle' },
  { m: 'GET', p: '/api/enterprise/audit', d: 'Read tenant audit log' },
  { m: 'GET', p: '/api/enterprise/connectors', d: 'List configured connectors' },
  { m: 'POST', p: '/api/enterprise/auth/magic-link/request', d: 'Issue passwordless magic link' },
];

export default function DeveloperPortal() {
  const [selected, setSelected] = useState(ENDPOINTS[0]);
  const [resp, setResp] = useState('');
  const [busy, setBusy] = useState(false);
  const [body, setBody] = useState('{\n  \n}');

  const send = async () => {
    setBusy(true);
    try {
      let res;
      const path = selected.p.replace('/api', '');
      if (selected.m === 'GET') {
        res = await api.get(path);
      } else {
        const parsed = body && body.trim() ? JSON.parse(body) : {};
        res = await api.post(path, parsed);
      }
      setResp(JSON.stringify(res.data, null, 2));
    } catch (e) {
      setResp(JSON.stringify(e?.response?.data || { error: String(e) }, null, 2));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div data-testid="cockpit-developers">
      <Header title="Developer Portal" subtitle="Explore the SpiderNetOS REST API. RBAC-checked, audited, rate-limited." />
      <div className="grid lg:grid-cols-12 gap-5">
        <div className="lg:col-span-4 glass p-5">
          <h2 className="font-medium flex items-center gap-2 mb-4">
            <Terminal size={15} className="text-accent-cyan" /> Endpoints
          </h2>
          <ul className="space-y-1">
            {ENDPOINTS.map((e) => (
              <li key={e.p}>
                <button
                  onClick={() => setSelected(e)}
                  data-testid={`api-endpoint-${e.m.toLowerCase()}-${e.p.replace(/\W+/g, '-')}`}
                  className={`w-full text-left p-3 rounded-lg border transition-colors ${
                    selected.p === e.p
                      ? 'border-accent-cyan/40 bg-accent-cyan/[0.05]'
                      : 'border-white/[0.04] hover:border-white/[0.12]'
                  }`}
                >
                  <div className="flex items-center gap-2">
                    <span
                      className={`mono text-[10px] px-1.5 py-0.5 rounded ${
                        e.m === 'GET' ? 'bg-accent-cyan/15 text-accent-cyan' : 'bg-accent-orange/15 text-accent-orange'
                      }`}
                    >
                      {e.m}
                    </span>
                    <code className="mono text-xs text-textc-primary">{e.p.replace('/api', '')}</code>
                  </div>
                  <div className="text-xs text-textc-secondary mt-1.5">{e.d}</div>
                </button>
              </li>
            ))}
          </ul>
        </div>

        <div className="lg:col-span-8 glass overflow-hidden">
          <div className="px-5 py-3 border-b border-white/[0.06] flex items-center gap-2 bg-bg-s2/60">
            <span
              className={`mono text-[10px] px-1.5 py-0.5 rounded ${
                selected.m === 'GET' ? 'bg-accent-cyan/15 text-accent-cyan' : 'bg-accent-orange/15 text-accent-orange'
              }`}
            >
              {selected.m}
            </span>
            <code className="mono text-sm flex-1 truncate">{selected.p}</code>
            <button onClick={send} disabled={busy} data-testid="api-send" className="btn-primary py-1.5 px-4 text-sm">
              {busy ? <Loader2 size={14} className="animate-spin" /> : <Play size={14} />}
              Send
            </button>
          </div>
          {selected.m !== 'GET' && (
            <div className="p-4 border-b border-white/[0.06]">
              <div className="mono text-[10px] uppercase tracking-wider text-textc-muted mb-1.5">Request body (JSON)</div>
              <textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                className="w-full mono text-xs bg-bg-s2/60 border border-white/10 rounded-lg p-3 text-textc-primary min-h-[100px] outline-none focus:border-accent-cyan/60"
              />
            </div>
          )}
          <div className="p-4">
            <div className="mono text-[10px] uppercase tracking-wider text-textc-muted mb-1.5">Response</div>
            <pre
              data-testid="api-response"
              className="mono text-xs bg-bg-s2/60 border border-white/10 rounded-lg p-4 text-textc-secondary overflow-x-auto min-h-[260px] max-h-[440px] overflow-y-auto"
            >
              {resp || '// Click "Send" to run a request'}
            </pre>
          </div>
        </div>
      </div>
    </div>
  );
}
