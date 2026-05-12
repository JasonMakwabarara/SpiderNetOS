import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Shield,
  ShieldCheck,
  Activity,
  Lock,
  KeyRound,
  FileSignature,
  Copy,
  CheckCircle2,
  Clock,
  Mail,
  Server,
  ArrowRight,
} from 'lucide-react';
import MarketingHeader from '../components/MarketingHeader';
import NetworkGraph from '../components/NetworkGraph';
import { Logo, Pill, SectionEyebrow } from '../components/Atoms';
import { api } from '../lib/api';

const STATUS_TONE = {
  compliant: 'success',
  aligned: 'cyan',
  in_audit: 'warning',
  capable: 'cyan',
  self_assessed: 'cyan',
};

const STATUS_LABEL = {
  compliant: 'Compliant',
  aligned: 'Aligned',
  in_audit: 'In audit',
  capable: 'Capable (BAA available)',
  self_assessed: 'Self-assessed',
};

export default function TrustCenter() {
  const [summary, setSummary] = useState(null);
  const [sample, setSample] = useState(null);
  const [status, setStatus] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    Promise.all([
      api.get('/enterprise/trust/summary'),
      api.get('/enterprise/trust/status'),
    ])
      .then(([s, st]) => {
        setSummary(s.data);
        setStatus(st.data);
      })
      .catch(() => {});
  }, []);

  const fetchSample = async () => {
    setBusy(true);
    try {
      const r = await api.get('/enterprise/trust/audit-sample');
      setSample(r.data);
    } finally {
      setBusy(false);
    }
  };

  const u = summary?.uptime;
  const ops = summary?.operations || {};
  const sec = summary?.security || {};
  const ir = summary?.incident_response || {};

  return (
    <div className="min-h-screen bg-bg-base text-textc-primary" data-testid="trust-center">
      <MarketingHeader />

      {/* HERO */}
      <section className="relative pt-32 pb-20 overflow-hidden">
        <NetworkGraph className="opacity-50" />
        <div className="hero-glow absolute inset-0 pointer-events-none" />
        <div className="grid-bg absolute inset-0 pointer-events-none" />
        <div className="container-x relative z-10">
          <div className="max-w-3xl">
            <Pill tone="cyan" icon={<Shield size={12} />}>Trust Center</Pill>
            <h1 className="mt-6 text-4xl md:text-5xl lg:text-6xl tracking-tighter font-medium leading-[1.05]">
              Continuous proof that{' '}
              <span className="bg-orange-cyan bg-clip-text text-transparent">
                SpiderNetOS is operating safely
              </span>
              .
            </h1>
            <p className="mt-6 text-lg text-textc-secondary max-w-2xl leading-relaxed">
              Live uptime, signed audit samples, compliance progress, sub-processors, and
              incident response — wired to the same telemetry that powers Cockpit, so what you
              see here is what we operate against.
            </p>
            <div className="mt-7 flex gap-3 flex-wrap">
              <Pill tone="success" icon={<CheckCircle2 size={11} />}>
                {status?.status === 'operational' ? 'All systems operational' : 'Checking…'}
              </Pill>
              {summary && (
                <Pill tone="cyan">
                  {u.current_30d}% uptime · 30d (SLA target {u.sla_target}%)
                </Pill>
              )}
              <Pill tone="cyan">{ops.audit_events_24h ?? '—'} audit events · 24h</Pill>
            </div>
          </div>
        </div>
      </section>

      {/* LIVE STATUS */}
      <section className="py-16 border-t border-white/[0.05]">
        <div className="container-x">
          <SectionEyebrow>Live status</SectionEyebrow>
          <h2 className="section-title mt-3">Subsystem health, refreshed in real time.</h2>
          <div className="mt-8 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {(status?.components || []).map((c) => (
              <div
                key={c.name}
                data-testid={`status-${c.name.toLowerCase().replace(/\s+/g, '-')}`}
                className="glass p-5 flex items-center justify-between"
              >
                <div>
                  <div className="font-medium">{c.name}</div>
                  <div className="mono text-xs text-textc-muted mt-1 uppercase tracking-wider">
                    {c.status}
                  </div>
                </div>
                <span className="size-2.5 rounded-full bg-feedback-success animate-pulse-soft" />
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* UPTIME SERIES */}
      {summary && (
        <section className="py-16 border-t border-white/[0.05]">
          <div className="container-x">
            <div className="grid lg:grid-cols-12 gap-8">
              <div className="lg:col-span-4">
                <SectionEyebrow>Uptime · 30 days</SectionEyebrow>
                <h2 className="section-title mt-3">{u.current_30d}%</h2>
                <p className="text-textc-secondary mt-3 text-sm">
                  SLA target {u.sla_target}% on Business and Enterprise tiers. Numbers below
                  reflect the rolling daily average across all production tenants.
                </p>
                <div className="mt-6 grid grid-cols-2 gap-4">
                  <Stat label="Audit events · all time" v={ops.audit_events_total} />
                  <Stat label="Bundles signed" v={ops.bundles_signed_total} />
                  <Stat label="Live tenants" v={ops.tenants_live} />
                  <Stat label="Anomalies high" v={ops.anomalies_high_alltime} />
                </div>
              </div>
              <div className="lg:col-span-8 glass p-6">
                <UptimeChart series={u.series} target={u.sla_target} />
              </div>
            </div>
          </div>
        </section>
      )}

      {/* COMPLIANCE TIMELINE */}
      {summary && (
        <section className="py-16 border-t border-white/[0.05]">
          <div className="container-x">
            <SectionEyebrow>Compliance roadmap</SectionEyebrow>
            <h2 className="section-title mt-3 max-w-2xl">Where each framework currently stands.</h2>
            <div className="mt-10 glass overflow-hidden">
              <div className="grid grid-cols-12 px-6 py-3 border-b border-white/[0.06] mono text-xs uppercase tracking-wider text-textc-muted">
                <div className="col-span-3">Framework</div>
                <div className="hidden md:block md:col-span-2">Status</div>
                <div className="hidden md:block md:col-span-3">Progress</div>
                <div className="hidden md:block md:col-span-2">Target</div>
                <div className="hidden md:block md:col-span-2">Auditor</div>
              </div>
              {summary.compliance.map((c) => (
                <div
                  key={c.framework}
                  data-testid={`compliance-${c.framework.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`}
                  className="grid grid-cols-12 px-6 py-4 border-b border-white/[0.04] last:border-0 items-center gap-y-3"
                >
                  <div className="col-span-12 md:col-span-3 font-medium">{c.framework}</div>
                  <div className="col-span-6 md:col-span-2">
                    <Pill tone={STATUS_TONE[c.status] || 'cyan'}>
                      {STATUS_LABEL[c.status] || c.status}
                    </Pill>
                  </div>
                  <div className="col-span-12 md:col-span-3 flex items-center gap-3">
                    <div className="flex-1 h-1.5 bg-bg-s3 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-orange-cyan"
                        style={{ width: `${c.progress}%` }}
                      />
                    </div>
                    <span className="mono text-xs text-textc-secondary w-10 text-right">
                      {c.progress}%
                    </span>
                  </div>
                  <div className="col-span-6 md:col-span-2 mono text-xs text-textc-secondary">
                    {c.target}
                  </div>
                  <div className="col-span-6 md:col-span-2 text-xs text-textc-secondary">
                    {c.auditor}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* SIGNED AUDIT EXPORT SAMPLE */}
      <section className="py-16 border-t border-white/[0.05]">
        <div className="container-x">
          <div className="grid lg:grid-cols-12 gap-8">
            <div className="lg:col-span-5">
              <SectionEyebrow>Signed audit export</SectionEyebrow>
              <h2 className="section-title mt-3">Verifiable, tamper-evident, redacted.</h2>
              <p className="text-textc-secondary mt-4 leading-relaxed">
                Every audit export ships with a SHA-256 digest and an Ed25519 signature over the
                exact bytes. Below is a public, redacted sample drawn from live audit data — the
                same envelope your security team can verify on production exports.
              </p>
              <button
                onClick={fetchSample}
                disabled={busy}
                data-testid="trust-fetch-sample"
                className="btn-primary mt-6"
              >
                <FileSignature size={16} />
                {busy ? 'Generating…' : sample ? 'Refresh sample' : 'Generate signed sample'}
              </button>
              {sample && (
                <div className="mt-5 space-y-2 text-sm">
                  <CopyRow label="SHA-256" value={sample.signed_envelope.sha256} />
                  <CopyRow label="Signature" value={sample.signed_envelope.signature} truncate />
                  <CopyRow label="Algorithm" value={sample.signed_envelope.algorithm} />
                </div>
              )}
            </div>
            <div className="lg:col-span-7 glass overflow-hidden">
              <div className="flex items-center gap-2 px-5 py-3 border-b border-white/[0.06] bg-bg-s2/60">
                <span className="size-2.5 rounded-full bg-feedback-danger/70" />
                <span className="size-2.5 rounded-full bg-feedback-warning/70" />
                <span className="size-2.5 rounded-full bg-feedback-success/70" />
                <span className="ml-3 mono text-xs text-textc-muted">
                  audit-export.signed.json
                </span>
              </div>
              <pre
                data-testid="trust-sample-json"
                className="p-5 mono text-xs leading-relaxed text-textc-secondary overflow-x-auto max-h-[420px]"
              >
                {sample
                  ? JSON.stringify(
                      {
                        export_version: '1.0',
                        events: sample.sample,
                        signed_envelope: {
                          sha256: sample.signed_envelope.sha256,
                          signature:
                            sample.signed_envelope.signature.slice(0, 64) + '…',
                          algorithm: sample.signed_envelope.algorithm,
                        },
                      },
                      null,
                      2,
                    )
                  : '// Click "Generate signed sample" to verify the export envelope live.'}
              </pre>
            </div>
          </div>
        </div>
      </section>

      {/* SECURITY POSTURE GRID */}
      <section className="py-16 border-t border-white/[0.05]">
        <div className="container-x">
          <SectionEyebrow>Security posture</SectionEyebrow>
          <h2 className="section-title mt-3">The controls running underneath every tenant.</h2>
          <div className="mt-10 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <PostureCard icon={Lock} title="Transport" v={`TLS ${sec.tls || '1.3'}`} sub="HSTS enforced" />
            <PostureCard icon={Server} title="At rest" v={sec.encryption_at_rest || 'AES-256-GCM'} sub="Tenant-scoped keys" />
            <PostureCard icon={KeyRound} title="Key rotation" v={`${sec.key_rotation_days || 90} days`} sub="Customer-managed available" />
            <PostureCard icon={FileSignature} title="Bundle signing" v={sec.bundle_signing || 'Ed25519'} sub={`Checksum ${sec.checksum || 'SHA-256'}`} />
            <PostureCard icon={ShieldCheck} title="MFA" v={sec.phishing_resistant_mfa ? 'Phishing-resistant' : 'TOTP'} sub="WebAuthn passkeys" />
            <PostureCard icon={Clock} title="P0 response" v={`${ir.p0_response_minutes || 30}m`} sub={`P1 ${ir.p1_response_minutes || 240}m`} />
            <PostureCard icon={Activity} title="Incidents · 30d" v={ir.incidents_30d ?? 0} sub="Post-mortem within 72h" />
            <PostureCard icon={Mail} title="Disclosure" v="security@" sub={sec.vuln_disclosure_email || 'security@spidernetos.com'} mono />
          </div>
        </div>
      </section>

      {/* SUB-PROCESSORS */}
      {summary && (
        <section className="py-16 border-t border-white/[0.05]">
          <div className="container-x">
            <SectionEyebrow>Sub-processors</SectionEyebrow>
            <h2 className="section-title mt-3 max-w-2xl">
              The third parties involved in operating SpiderNetOS.
            </h2>
            <div className="mt-8 glass overflow-hidden">
              <div className="grid grid-cols-12 px-6 py-3 border-b border-white/[0.06] mono text-xs uppercase tracking-wider text-textc-muted">
                <div className="col-span-4">Provider</div>
                <div className="col-span-4">Purpose</div>
                <div className="col-span-4">Region</div>
              </div>
              {summary.subprocessors.map((s) => (
                <div
                  key={s.name}
                  className="grid grid-cols-12 px-6 py-3 border-b border-white/[0.04] last:border-0 text-sm hover:bg-accent-cyan/[0.04]"
                >
                  <div className="col-span-4 font-medium">{s.name}</div>
                  <div className="col-span-4 text-textc-secondary">{s.purpose}</div>
                  <div className="col-span-4 mono text-xs text-textc-secondary">{s.region}</div>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* CTA */}
      <section className="py-20 border-t border-white/[0.05]">
        <div className="container-x">
          <div className="glass p-10 lg:p-14 text-center relative overflow-hidden">
            <NetworkGraph className="opacity-20" />
            <div className="relative">
              <h2 className="section-title text-3xl md:text-4xl tracking-tighter max-w-2xl mx-auto">
                Need the full security packet?
              </h2>
              <p className="mt-4 text-textc-secondary max-w-xl mx-auto">
                We share SOC 2 reports, pen-test summaries, DPA templates, and architecture
                diagrams under NDA. Most security reviews close in under 48 hours.
              </p>
              <div className="mt-7 flex flex-col sm:flex-row gap-3 justify-center">
                <Link to="/enterprise/register" className="btn-primary" data-testid="trust-cta-primary">
                  Register your enterprise <ArrowRight size={15} />
                </Link>
                <a
                  href="mailto:security@spidernetos.com"
                  className="btn-ghost"
                  data-testid="trust-cta-contact"
                >
                  <Mail size={15} /> Contact security
                </a>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* FOOTER */}
      <footer className="py-10 border-t border-white/[0.05]">
        <div className="container-x flex flex-wrap items-center justify-between gap-3 text-xs text-textc-muted">
          <div className="flex items-center gap-4">
            <Logo />
          </div>
          <span className="mono">
            Last refreshed{' '}
            {summary?.as_of ? new Date(summary.as_of).toLocaleString() : '—'}
          </span>
        </div>
      </footer>
    </div>
  );
}

/* ─── small atoms ─── */
function Stat({ label, v }) {
  return (
    <div>
      <div className="mono text-2xl tracking-tight text-textc-primary">{v ?? '—'}</div>
      <div className="text-xs text-textc-muted mt-1">{label}</div>
    </div>
  );
}

function PostureCard({ icon: Icon, title, v, sub, mono }) {
  return (
    <div className="glass glass-hover p-5">
      <div className="flex items-center justify-between">
        <Icon size={16} className="text-accent-cyan" />
        <span className="mono text-[10px] uppercase tracking-wider text-textc-muted">{title}</span>
      </div>
      <div className={`mt-4 ${mono ? 'mono text-sm' : 'text-lg'} text-textc-primary`}>{v}</div>
      <div className="mt-1 text-xs text-textc-secondary">{sub}</div>
    </div>
  );
}

function CopyRow({ label, value, truncate }) {
  return (
    <div className="flex items-center gap-2">
      <span className="mono text-[10px] uppercase tracking-wider text-textc-muted w-20 flex-shrink-0">
        {label}
      </span>
      <code className={`mono text-xs text-textc-primary flex-1 ${truncate ? 'truncate' : 'break-all'}`}>
        {value}
      </code>
      <button
        onClick={() => navigator.clipboard?.writeText(value)}
        className="p-1.5 rounded hover:bg-white/[0.06]"
        aria-label={`Copy ${label}`}
      >
        <Copy size={13} />
      </button>
    </div>
  );
}

function UptimeChart({ series, target }) {
  const w = 600;
  const h = 180;
  const min = 99.4;
  const max = 100;
  const stepX = w / (series.length - 1);
  const pts = series.map((p, i) => [
    i * stepX,
    h - ((p.uptime - min) / (max - min)) * (h - 24) - 12,
  ]);
  const path = pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
  const targetY = h - ((target - min) / (max - min)) * (h - 24) - 12;

  return (
    <div>
      <div className="flex items-center justify-between mb-3">
        <div className="mono text-xs uppercase tracking-wider text-textc-muted">
          Daily uptime · {series.length} days
        </div>
        <div className="flex items-center gap-4 mono text-[10px] text-textc-muted">
          <span className="flex items-center gap-1.5">
            <span className="size-2 rounded-full bg-accent-cyan" /> Actual
          </span>
          <span className="flex items-center gap-1.5">
            <span className="block w-3 h-px bg-accent-orange" /> SLA {target}%
          </span>
        </div>
      </div>
      <svg viewBox={`0 0 ${w} ${h}`} className="w-full h-auto" aria-label="Uptime chart">
        <defs>
          <linearGradient id="u-fill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stopColor="#00D6C9" stopOpacity="0.35" />
            <stop offset="1" stopColor="#00D6C9" stopOpacity="0" />
          </linearGradient>
        </defs>
        {/* gridlines */}
        {[0.25, 0.5, 0.75].map((g) => (
          <line key={g} x1="0" x2={w} y1={h * g} y2={h * g} stroke="rgba(255,255,255,0.05)" />
        ))}
        {/* fill */}
        <path d={`${path} L ${w} ${h} L 0 ${h} Z`} fill="url(#u-fill)" />
        {/* SLA target line */}
        <line
          x1="0"
          x2={w}
          y1={targetY}
          y2={targetY}
          stroke="#FF6B2C"
          strokeOpacity="0.6"
          strokeDasharray="3 4"
        />
        {/* main path */}
        <path d={path} stroke="#00D6C9" strokeWidth="1.6" fill="none" />
        {/* dots */}
        {pts.map(([x, y], i) => (
          <circle
            key={series[i].date}
            cx={x}
            cy={y}
            r="2"
            fill="#00D6C9"
          />
        ))}
      </svg>
      <div className="mt-3 flex justify-between mono text-[10px] text-textc-muted">
        <span>{series[0]?.date}</span>
        <span>{series[Math.floor(series.length / 2)]?.date}</span>
        <span>{series[series.length - 1]?.date}</span>
      </div>
    </div>
  );
}
