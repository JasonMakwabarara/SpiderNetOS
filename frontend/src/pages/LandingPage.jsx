import React from 'react';
import { Link } from 'react-router-dom';
import {
  ShieldCheck,
  Network,
  ScanSearch,
  Package,
  Activity,
  Workflow,
  ArrowRight,
  Check,
  Terminal,
  Lock,
  KeyRound,
  GitBranch,
  Database,
  FileSignature,
  CircleDot,
  Server,
  ChevronRight,
  CheckCircle2,
} from 'lucide-react';
import {
  SiSap,
  SiSalesforce,
  SiSnowflake,
  SiOkta,
  SiDatabricks,
  SiHubspot,
  SiAuth0,
  SiGoogle,
  SiPostgresql,
  SiSlack,
} from 'react-icons/si';
import { BarChart3 } from 'lucide-react';
import MarketingHeader from '../components/MarketingHeader';
import NetworkGraph from '../components/NetworkGraph';
import { Pill, SectionEyebrow, Logo } from '../components/Atoms';

const TRUST = [
  { label: '99.9% SLA target', icon: Activity },
  { label: 'OIDC + SAML SSO', icon: KeyRound },
  { label: 'SCIM 2.0 provisioning', icon: GitBranch },
  { label: 'Signed AIOS bundles', icon: FileSignature },
  { label: 'Audit-ready logs', icon: ShieldCheck },
];

const PILLARS = [
  {
    icon: ShieldCheck,
    title: 'Governed AIOS',
    desc: 'AI agents operate inside tenant, role, policy, and audit boundaries — never outside them.',
    outcome: 'Safe enterprise adoption',
  },
  {
    icon: Network,
    title: 'Business-system integration',
    desc: 'Connect ERP, CRM, IAM, BI, data lakes, and internal APIs through governed connectors.',
    outcome: 'AI works where data lives',
  },
  {
    icon: Terminal,
    title: 'Cockpit control plane',
    desc: 'Admins manage tenants, users, RBAC, provisioning, downloads, logs, and health from one console.',
    outcome: 'Centralized operations',
  },
  {
    icon: Package,
    title: 'Secure deployment',
    desc: 'AIOS bundles are signed, checksummed, scoped to a tenant, and lifecycle-managed.',
    outcome: 'Reduced deployment risk',
  },
  {
    icon: ScanSearch,
    title: 'Observability-first',
    desc: 'Every action, connector, agent, and pipeline is logged and monitored with anomaly detection.',
    outcome: 'Debuggable, compliant operations',
  },
  {
    icon: Workflow,
    title: 'Enterprise onboarding',
    desc: 'Guided registration, domain verification, SCIM provisioning, and installer workflow.',
    outcome: 'Faster rollout',
  },
];

const USE_CASES = {
  Finance: {
    title: 'Autonomous finance ops, fully auditable',
    bullets: [
      'Reconcile invoices against ledger entries with zero-touch agents',
      'Flag anomalies before close — every step traced and approvable',
      'Connect SAP, Oracle Fusion, NetSuite via signed connectors',
    ],
    metric: { v: '7×', l: 'faster month-end close' },
  },
  Operations: {
    title: 'Operations that route themselves',
    bullets: [
      'Auto-triage tickets, escalate on SLA risk, summarize for engineers',
      'Multi-region pipeline orchestration with tenant-bounded data',
      'Self-healing playbooks with full rollback history',
    ],
    metric: { v: '38%', l: 'fewer manual escalations' },
  },
  Support: {
    title: 'Compliant customer support automation',
    bullets: [
      'Tier-1 deflection with policy-bound agents',
      'PII redaction enforced at the connector boundary',
      'Audit-ready transcripts and decision provenance',
    ],
    metric: { v: '62%', l: 'tier-1 deflection rate' },
  },
  Sales: {
    title: 'Revenue automation under governance',
    bullets: [
      'Lead qualification with explainable ICP fit scoring',
      'CRM sync via Salesforce/HubSpot connectors with field-level RBAC',
      'Approval workflows for high-value outreach',
    ],
    metric: { v: '2.4×', l: 'qualified lead throughput' },
  },
  Analytics: {
    title: 'Trusted analytics at agent speed',
    bullets: [
      'Snowflake / Databricks / Power BI connectors with data residency tags',
      'Agent-generated reports require human signoff for distribution',
      'Continuous schema drift detection',
    ],
    metric: { v: '99.97%', l: 'pipeline uptime' },
  },
  IT: {
    title: 'IT service management on autopilot',
    bullets: [
      'Identity provider integration with Okta / Entra / Google Workspace',
      'Auto-provisioning via SCIM with role-binding policies',
      'Patch lifecycle with rollback and health checks',
    ],
    metric: { v: '< 24h', l: 'mean time to provision' },
  },
};

const CONNECTORS = [
  { name: 'SAP', icon: SiSap, cat: 'ERP' },
  { name: 'Oracle Fusion', icon: Database, cat: 'ERP' },
  { name: 'Salesforce', icon: SiSalesforce, cat: 'CRM' },
  { name: 'HubSpot', icon: SiHubspot, cat: 'CRM' },
  { name: 'Snowflake', icon: SiSnowflake, cat: 'Data' },
  { name: 'Databricks', icon: SiDatabricks, cat: 'Data' },
  { name: 'Power BI', icon: BarChart3, cat: 'BI' },
  { name: 'Okta', icon: SiOkta, cat: 'IAM' },
  { name: 'Microsoft Entra ID', icon: SiAuth0, cat: 'IAM' },
  { name: 'Google Workspace', icon: SiGoogle, cat: 'IAM' },
  { name: 'PostgreSQL', icon: SiPostgresql, cat: 'Data' },
  { name: 'Slack', icon: SiSlack, cat: 'Messaging' },
];

const ONBOARDING_STEPS = [
  'Business email & org',
  'Domain verification',
  'Tenant creation',
  'SSO (OIDC / SAML)',
  'MFA policy',
  'SCIM provisioning',
  'Cockpit provisioning',
  'Connector wizard',
  'AIOS bundle request',
  'Deployment tracking',
];

const FAILURES = [
  {
    failure: 'Weak SSO / misconfigured IdP',
    detection: 'Pre-flight OIDC discovery + signed test assertion',
    mitigation: 'Block tenant promotion until policy passes',
    hardening: 'Phishing-resistant MFA required for admin step-up',
  },
  {
    failure: 'Data residency violation',
    detection: 'Connector region tag mismatch alert',
    mitigation: 'Auto-quarantine pipeline; admin approval required',
    hardening: 'Region-pinned tenants + immutable audit export',
  },
  {
    failure: 'Bundle integrity compromised',
    detection: 'SHA-256 + signature verification on install',
    mitigation: 'Installer refuses to run; rollback to prior version',
    hardening: 'Bundles signed offline; verifier baked into installer',
  },
  {
    failure: 'API rate limits cascade',
    detection: 'Token bucket overflow + dependency timeout map',
    mitigation: 'Adaptive backoff with circuit breaker',
    hardening: 'Per-connector quotas + tenant-level priority lanes',
  },
  {
    failure: 'Cockpit RBAC drift',
    detection: 'Continuous policy reconciliation vs. role manifest',
    mitigation: 'Auto-revert to last approved policy snapshot',
    hardening: 'Step-up auth required for role grant + audit chain',
  },
  {
    failure: 'Failed AIOS install / version mismatch',
    detection: 'Post-install health probe + version registry diff',
    mitigation: 'Self-healing retry, then rollback playbook',
    hardening: 'Pinned component manifests; canary deploys',
  },
];

export default function LandingPage() {
  const [tab, setTab] = React.useState('Finance');

  return (
    <div className="min-h-screen bg-bg-base text-textc-primary" data-testid="landing-page">
      <MarketingHeader />

      {/* HERO */}
      <section className="relative pt-32 pb-24 overflow-hidden" id="hero">
        <NetworkGraph className="opacity-60" />
        <div className="hero-glow absolute inset-0 pointer-events-none" />
        <div className="grid-bg absolute inset-0 pointer-events-none" />
        <div className="container-x relative z-10">
          <div className="max-w-3xl">
            <Pill tone="cyan" className="mb-6" icon={<CircleDot size={12} />}>
              AI Operating System
            </Pill>
            <h1
              data-testid="hero-headline"
              className="text-4xl sm:text-5xl md:text-6xl lg:text-7xl tracking-tighter font-medium leading-[1.02]"
            >
              The AI{' '}
              <span className="bg-orange-cyan bg-clip-text text-transparent">
                Operating System
              </span>{' '}
              for business automation.
            </h1>
            <p
              data-testid="hero-subcopy"
              className="mt-6 text-lg md:text-xl text-textc-secondary max-w-2xl leading-relaxed"
            >
              Connect your people, data, applications, and AI agents through one operating layer.
              Deploy AIOS components into any environment — startups, teams, or enterprises —
              with signed bundles, identity, RBAC, observability, and lifecycle controls built
              in.
            </p>
            <div className="mt-9 flex flex-col sm:flex-row gap-3">
              <Link
                to="/enterprise/register"
                data-testid="hero-cta-primary"
                className="btn-primary"
              >
                Get started free
                <ArrowRight size={16} />
              </Link>
              <a href="#platform" data-testid="hero-cta-secondary" className="btn-ghost">
                Explore the platform
              </a>
              <Link
                to="/sign-in"
                data-testid="hero-cta-tertiary"
                className="inline-flex items-center justify-center gap-2 rounded-full px-6 py-3 text-textc-secondary hover:text-textc-primary transition-colors"
              >
                Sign in to Cockpit
                <ChevronRight size={16} />
              </Link>
            </div>
          </div>

          {/* Trust bar */}
          <div
            data-testid="trust-bar"
            className="mt-16 glass p-5 flex flex-wrap items-center justify-between gap-y-4 gap-x-8"
          >
            {TRUST.map((t) => (
              <div key={t.label} className="flex items-center gap-2.5 text-sm">
                <t.icon size={16} className="text-accent-cyan" />
                <span className="text-textc-secondary">{t.label}</span>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* PROBLEM */}
      <section className="py-20 border-t border-white/[0.05]">
        <div className="container-x grid md:grid-cols-2 gap-12 items-center">
          <div>
            <SectionEyebrow>The problem</SectionEyebrow>
            <h2 className="section-title mt-3">
              Most AI projects stall when identity, data, and deployment live in different
              systems.
            </h2>
          </div>
          <div className="text-textc-secondary text-lg leading-relaxed space-y-4">
            <p>
              The hardest part of putting AI into a business isn't picking the model. It's
              connecting it to the data, the people, the tools, and the policies that already
              run the company — and keeping that connection observable and reversible.
            </p>
            <p>
              SpiderNetOS collapses that surface into one operating layer that scales from a
              two-person team to a 50,000-seat enterprise.
            </p>
          </div>
        </div>
      </section>

      {/* PLATFORM PILLARS */}
      <section id="platform" className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <SectionEyebrow>Platform</SectionEyebrow>
          <h2 className="section-title mt-3 max-w-3xl">
            One operating layer. Six pillars. Built for real business work.
          </h2>
          <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-5 mt-12">
            {PILLARS.map((p, i) => (
              <article
                key={p.title}
                data-testid={`pillar-${i}`}
                className="glass glass-hover p-7 group animate-fade-up"
                style={{ animationDelay: `${i * 60}ms` }}
              >
                <div className="flex items-center justify-between">
                  <div className="size-11 rounded-xl bg-bg-s3 border border-white/[0.06] flex items-center justify-center group-hover:border-accent-cyan/40 transition-colors">
                    <p.icon size={20} className="text-accent-cyan" />
                  </div>
                  <span className="mono text-[10px] uppercase tracking-wider text-textc-muted">
                    0{i + 1}
                  </span>
                </div>
                <h3 className="mt-5 text-xl tracking-tight font-medium">{p.title}</h3>
                <p className="mt-2 text-textc-secondary text-sm leading-relaxed">{p.desc}</p>
                <div className="mt-5 pt-4 border-t border-white/[0.05] flex items-center gap-2 text-xs text-accent-orange mono uppercase tracking-wider">
                  <Check size={14} /> {p.outcome}
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* ARCHITECTURE DIAGRAM */}
      <section className="py-24 border-t border-white/[0.05]" id="architecture">
        <div className="container-x">
          <div className="grid lg:grid-cols-12 gap-10 items-center">
            <div className="lg:col-span-5">
              <SectionEyebrow>Architecture</SectionEyebrow>
              <h2 className="section-title mt-3">Three planes. One control surface.</h2>
              <p className="text-textc-secondary mt-5 leading-relaxed">
                The AIOS runtime separates the data plane, control plane, and governance plane —
                so identity decisions, policy decisions, and execution decisions never leak into
                each other.
              </p>
              <ul className="mt-6 space-y-3 text-sm">
                <li className="flex items-start gap-3">
                  <CheckCircle2 size={16} className="text-accent-cyan mt-0.5" />
                  <span className="text-textc-secondary">
                    <span className="text-textc-primary font-medium">Governance plane</span> —
                    SSO, RBAC, audit, keys, residency policies.
                  </span>
                </li>
                <li className="flex items-start gap-3">
                  <CheckCircle2 size={16} className="text-accent-cyan mt-0.5" />
                  <span className="text-textc-secondary">
                    <span className="text-textc-primary font-medium">Control plane</span> —
                    Cockpit, agents, flows, approvals, deployments.
                  </span>
                </li>
                <li className="flex items-start gap-3">
                  <CheckCircle2 size={16} className="text-accent-cyan mt-0.5" />
                  <span className="text-textc-secondary">
                    <span className="text-textc-primary font-medium">Data plane</span> —
                    Connectors to ERP/CRM/BI/data lakes, all tenant-bounded.
                  </span>
                </li>
              </ul>
            </div>
            <div className="lg:col-span-7">
              <div className="glass p-8 relative overflow-hidden">
                <ArchitectureSVG />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* USE CASES TABS */}
      <section id="solutions" className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <SectionEyebrow>Solutions</SectionEyebrow>
          <h2 className="section-title mt-3 max-w-2xl">
            Business workflows, instrumented end-to-end.
          </h2>
          <div className="mt-10 flex flex-wrap gap-2" role="tablist" aria-label="Use cases">
            {Object.keys(USE_CASES).map((k) => (
              <button
                key={k}
                role="tab"
                aria-selected={tab === k}
                onClick={() => setTab(k)}
                data-testid={`usecase-tab-${k.toLowerCase()}`}
                className={`px-4 py-2 rounded-full text-sm transition-all ${
                  tab === k
                    ? 'bg-accent-cyan/15 text-accent-cyan border border-accent-cyan/40'
                    : 'text-textc-secondary border border-white/10 hover:border-white/30'
                }`}
              >
                {k}
              </button>
            ))}
          </div>
          <div className="mt-10 grid lg:grid-cols-12 gap-8 items-stretch">
            <div className="glass p-8 lg:col-span-7">
              <h3 className="text-2xl tracking-tight font-medium">{USE_CASES[tab].title}</h3>
              <ul className="mt-6 space-y-3">
                {USE_CASES[tab].bullets.map((b) => (
                  <li key={b} className="flex items-start gap-3 text-textc-secondary">
                    <CheckCircle2 size={16} className="text-accent-cyan mt-0.5 flex-shrink-0" />
                    <span>{b}</span>
                  </li>
                ))}
              </ul>
            </div>
            <div className="glass p-8 lg:col-span-5 flex flex-col justify-between">
              <div>
                <div className="section-eyebrow">Measured outcome</div>
                <div className="mt-4 mono text-6xl tracking-tighter text-accent-orange">
                  {USE_CASES[tab].metric.v}
                </div>
                <div className="mt-2 text-textc-secondary">{USE_CASES[tab].metric.l}</div>
              </div>
              <div className="mt-6 pt-6 border-t border-white/[0.06] text-sm text-textc-secondary">
                Customer-reported aggregate across active enterprise tenants.
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* SECURITY & COMPLIANCE */}
      <section id="security" className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <div className="grid lg:grid-cols-12 gap-10">
            <div className="lg:col-span-5">
              <SectionEyebrow>Security & Compliance</SectionEyebrow>
              <h2 className="section-title mt-3">
                Built for security review — not bolted on after one.
              </h2>
              <p className="text-textc-secondary mt-5 leading-relaxed">
                Every identity, key, and pipeline is governed by policy. Every operation lands in
                an immutable audit log. Every bundle is signed before it leaves the registry.
              </p>
              <div className="mt-8 flex flex-wrap gap-2">
                <Pill tone="cyan">SOC 2 Type II (roadmap Q2)</Pill>
                <Pill tone="cyan">ISO 27001 aligned</Pill>
                <Pill tone="cyan">GDPR / DPA ready</Pill>
                <Pill tone="cyan">HIPAA capable</Pill>
              </div>
            </div>
            <div className="lg:col-span-7 grid sm:grid-cols-2 gap-4">
              {[
                {
                  icon: KeyRound,
                  title: 'Identity',
                  body: 'OIDC + SAML SSO, SCIM 2.0 provisioning, WebAuthn passkeys, TOTP fallback, step-up for sensitive ops.',
                },
                {
                  icon: Lock,
                  title: 'Encryption',
                  body: 'TLS 1.3 in transit, AES-256-GCM at rest, customer-managed keys via KMS, scoped per tenant.',
                },
                {
                  icon: ShieldCheck,
                  title: 'Audit',
                  body: 'Immutable event log, signed exports, anomaly detection, RBAC drift reconciliation.',
                },
                {
                  icon: Server,
                  title: 'Residency',
                  body: 'Region-pinned tenants, connector-level data tags, quarantine on policy mismatch.',
                },
              ].map((c) => (
                <div key={c.title} className="glass glass-hover p-6">
                  <c.icon size={20} className="text-accent-cyan" />
                  <h3 className="mt-4 font-medium">{c.title}</h3>
                  <p className="mt-2 text-sm text-textc-secondary leading-relaxed">{c.body}</p>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* FAILURE HARDENING MATRIX */}
      <section className="py-24 border-t border-white/[0.05]" id="reliability">
        <div className="container-x">
          <SectionEyebrow>Reliability</SectionEyebrow>
          <h2 className="section-title mt-3 max-w-2xl">
            We modeled the failures — and shipped the mitigations.
          </h2>
          <div className="mt-10 glass overflow-hidden">
            <div className="grid grid-cols-12 px-6 py-4 border-b border-white/[0.06] mono text-xs uppercase tracking-wider text-textc-muted">
              <div className="col-span-12 md:col-span-3">Failure mode</div>
              <div className="hidden md:block md:col-span-3">Detection</div>
              <div className="hidden md:block md:col-span-3">Immediate mitigation</div>
              <div className="hidden md:block md:col-span-3">Long-term hardening</div>
            </div>
            {FAILURES.map((f, i) => (
              <div
                key={f.failure}
                data-testid={`failure-row-${i}`}
                className="grid grid-cols-12 px-6 py-5 border-b border-white/[0.04] last:border-0 text-sm hover:bg-accent-cyan/[0.04] transition-colors"
              >
                <div className="col-span-12 md:col-span-3 text-textc-primary font-medium">
                  {f.failure}
                </div>
                <div className="col-span-12 md:col-span-3 text-textc-secondary mt-2 md:mt-0">
                  <span className="md:hidden mono text-xs text-textc-muted">Detection: </span>
                  {f.detection}
                </div>
                <div className="col-span-12 md:col-span-3 text-textc-secondary mt-2 md:mt-0">
                  <span className="md:hidden mono text-xs text-textc-muted">Mitigation: </span>
                  {f.mitigation}
                </div>
                <div className="col-span-12 md:col-span-3 text-textc-secondary mt-2 md:mt-0">
                  <span className="md:hidden mono text-xs text-textc-muted">Hardening: </span>
                  {f.hardening}
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* INTEGRATIONS */}
      <section id="integrations" className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <div className="grid lg:grid-cols-12 gap-10">
            <div className="lg:col-span-4">
              <SectionEyebrow>Integrations</SectionEyebrow>
              <h2 className="section-title mt-3">Where your business already lives.</h2>
              <p className="text-textc-secondary mt-5 leading-relaxed">
                Pre-built signed connectors for the systems your data, identity, and revenue
                already flow through. Custom connectors via SDK in under a day.
              </p>
              <Link to="/enterprise/register" className="btn-ghost mt-6">
                Browse all 80+ connectors
                <ArrowRight size={16} />
              </Link>
            </div>
            <div className="lg:col-span-8">
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                {CONNECTORS.map((c) => (
                  <div
                    key={c.name}
                    className="glass glass-hover p-5 flex flex-col items-center gap-3 text-center"
                    data-testid={`connector-${c.name.toLowerCase().replace(/\s+/g, '-')}`}
                  >
                    <c.icon className="text-textc-primary" size={28} />
                    <div>
                      <div className="text-sm font-medium">{c.name}</div>
                      <div className="mono text-[10px] uppercase tracking-wider text-textc-muted mt-1">
                        {c.cat}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* DEVELOPER PORTAL PREVIEW */}
      <section id="developers" className="py-24 border-t border-white/[0.05]">
        <div className="container-x grid lg:grid-cols-12 gap-10 items-center">
          <div className="lg:col-span-5">
            <SectionEyebrow>Developers</SectionEyebrow>
            <h2 className="section-title mt-3">Ship integrations like you ship code.</h2>
            <p className="text-textc-secondary mt-5 leading-relaxed">
              REST API, webhooks, typed SDKs, sandbox tenants, and an interactive API explorer.
              Every call is RBAC-checked, audited, and rate-limited per tenant.
            </p>
            <div className="mt-6 flex flex-wrap gap-2">
              <Pill tone="cyan">REST + GraphQL</Pill>
              <Pill tone="cyan">Webhooks (signed)</Pill>
              <Pill tone="cyan">TypeScript + Python SDKs</Pill>
              <Pill tone="cyan">Sandbox tenants</Pill>
            </div>
          </div>
          <div className="lg:col-span-7">
            <div className="glass overflow-hidden">
              <div className="flex items-center gap-2 px-5 py-3 border-b border-white/[0.06] bg-bg-s2/60">
                <span className="size-2.5 rounded-full bg-feedback-danger/70" />
                <span className="size-2.5 rounded-full bg-feedback-warning/70" />
                <span className="size-2.5 rounded-full bg-feedback-success/70" />
                <span className="ml-3 mono text-xs text-textc-muted">
                  POST /v1/aios/bundle.create
                </span>
              </div>
              <pre className="p-6 mono text-xs leading-relaxed text-textc-secondary overflow-x-auto">
                <span className="text-accent-cyan">curl</span> -X POST{' '}
                <span className="text-textc-primary">https://api.spidernetos.com/v1/aios/bundle.create</span>{' '}
                \{'\n'}
                {'  '}-H <span className="text-feedback-warning">"Authorization: Bearer $SN_TOKEN"</span> \{'\n'}
                {'  '}-H <span className="text-feedback-warning">"Content-Type: application/json"</span> \{'\n'}
                {'  '}-d <span className="text-feedback-warning">{`'{`}</span>
                {'\n'}    <span className="text-accent-cyan">"tenant_id"</span>: <span className="text-feedback-warning">"tnt_acme"</span>,{'\n'}
                {'    '}
                <span className="text-accent-cyan">"target"</span>: <span className="text-feedback-warning">"linux-x86_64"</span>,{'\n'}
                {'    '}
                <span className="text-accent-cyan">"components"</span>: [<span className="text-feedback-warning">"runtime"</span>, <span className="text-feedback-warning">"connectors"</span>, <span className="text-feedback-warning">"cockpit-agent"</span>]{'\n'}
                {'  '}<span className="text-feedback-warning">{`}'`}</span>
                {'\n\n'}
                <span className="text-textc-muted"># →</span>{'\n'}
                <span className="text-textc-muted">{'{'}</span>{'\n'}
                {'  '}
                <span className="text-accent-cyan">"bundle_id"</span>: <span className="text-feedback-warning">"bdl_8f3a"</span>,{'\n'}
                {'  '}
                <span className="text-accent-cyan">"download_url"</span>: <span className="text-feedback-warning">"https://.../bdl_8f3a.zip"</span>,{'\n'}
                {'  '}
                <span className="text-accent-cyan">"sha256"</span>: <span className="text-feedback-warning">"7d24…c91e"</span>,{'\n'}
                {'  '}
                <span className="text-accent-cyan">"signature"</span>: <span className="text-feedback-warning">"MEUCIQD…"</span>,{'\n'}
                {'  '}
                <span className="text-accent-cyan">"expires_at"</span>: <span className="text-feedback-warning">"2026-02-14T00:00:00Z"</span>{'\n'}
                <span className="text-textc-muted">{'}'}</span>
              </pre>
            </div>
          </div>
        </div>
      </section>

      {/* CUSTOMER STORY */}
      <section id="customers" className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <div className="glass p-10 lg:p-14 relative overflow-hidden">
            <div className="absolute -top-24 -right-24 w-72 h-72 rounded-full bg-accent-cyan/10 blur-3xl pointer-events-none" />
            <div className="absolute -bottom-24 -left-24 w-72 h-72 rounded-full bg-accent-orange/10 blur-3xl pointer-events-none" />
            <div className="relative grid lg:grid-cols-12 gap-10 items-center">
              <div className="lg:col-span-7">
                <SectionEyebrow>Customer Story</SectionEyebrow>
                <h2 className="section-title mt-3">Hannah AI — integrating AIOS in 11 days.</h2>
                <p className="text-textc-secondary mt-5 leading-relaxed text-lg">
                  Hannah AI registered at{' '}
                  <span className="mono text-accent-cyan">hannah-ai.spidernetos.com</span>,
                  verified their domain, federated Okta in 12 minutes, provisioned 240 users via
                  SCIM, and deployed a signed AIOS bundle into their isolated VPC — all before
                  their CISO finished her morning coffee.
                </p>
                <div className="mt-7 grid grid-cols-3 gap-4">
                  {[
                    { v: '11d', l: 'register → production' },
                    { v: '240', l: 'users SCIM-provisioned' },
                    { v: '0', l: 'security review blockers' },
                  ].map((m) => (
                    <div key={m.l} className="border-l border-white/[0.08] pl-4">
                      <div className="mono text-3xl tracking-tight text-accent-orange">
                        {m.v}
                      </div>
                      <div className="text-xs text-textc-secondary mt-1">{m.l}</div>
                    </div>
                  ))}
                </div>
              </div>
              <div className="lg:col-span-5">
                <div className="glass p-6 bg-bg-s2/80">
                  <div className="flex items-center gap-3">
                    <div className="size-10 rounded-full bg-orange-cyan flex items-center justify-center mono text-bg-base font-bold">
                      H
                    </div>
                    <div>
                      <div className="font-medium">Hannah Voss</div>
                      <div className="text-xs text-textc-secondary">VP Platform, Hannah AI</div>
                    </div>
                  </div>
                  <p className="mt-5 text-textc-secondary leading-relaxed">
                    "We've evaluated three AI ops platforms. SpiderNetOS is the only one our CISO
                    didn't ask us to redesign. Signed bundles, SCIM, region-pinned tenants —
                    everything was already where it should be."
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* PRICING / SLA */}
      <section id="pricing" className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <SectionEyebrow>Pricing & SLA</SectionEyebrow>
          <h2 className="section-title mt-3 max-w-2xl">
            Tier the platform to the risk profile of your business.
          </h2>
          <div className="mt-12 grid md:grid-cols-3 gap-5">
            {[
              {
                name: 'Starter',
                price: 'From $1,200/mo',
                tone: 'neutral',
                blurb: 'For pilots and early adoption.',
                feats: [
                  'Up to 25 users',
                  'Magic-link + TOTP auth',
                  '3 connectors',
                  'Standard support, business hours',
                  'Audit retention: 90 days',
                ],
              },
              {
                name: 'Business',
                price: 'From $4,800/mo',
                tone: 'cyan',
                blurb: 'Most chosen by mid-market enterprises.',
                feats: [
                  'Up to 250 users',
                  'OIDC + SAML SSO',
                  'SCIM provisioning',
                  'WebAuthn passkeys + step-up',
                  '12 connectors + sandbox tenant',
                  'Audit retention: 1 year',
                  '99.9% SLA, 24×5 support',
                ],
                highlight: true,
              },
              {
                name: 'Enterprise',
                price: 'Custom',
                tone: 'orange',
                blurb: 'For regulated and global rollouts.',
                feats: [
                  'Unlimited users',
                  'Customer-managed keys (BYOK)',
                  'Region-pinned tenants',
                  'Private AIOS registry',
                  'Dedicated onboarding architect',
                  'Audit retention: 7 years + signed exports',
                  '99.95% SLA, 24×7 support, named CSM',
                ],
              },
            ].map((p) => (
              <div
                key={p.name}
                data-testid={`pricing-${p.name.toLowerCase()}`}
                className={`glass p-7 flex flex-col ${
                  p.highlight ? 'ring-1 ring-accent-cyan/40 shadow-glow-cyan' : ''
                }`}
              >
                <div className="flex items-center justify-between">
                  <h3 className="text-xl font-medium">{p.name}</h3>
                  {p.highlight && <Pill tone="cyan">Most popular</Pill>}
                </div>
                <div className="mt-3 mono text-2xl text-accent-orange">{p.price}</div>
                <p className="text-sm text-textc-secondary mt-2">{p.blurb}</p>
                <ul className="mt-6 space-y-2.5 flex-1">
                  {p.feats.map((f) => (
                    <li key={f} className="flex items-start gap-2.5 text-sm text-textc-secondary">
                      <CheckCircle2 size={15} className="text-accent-cyan mt-0.5 flex-shrink-0" />
                      {f}
                    </li>
                  ))}
                </ul>
                <Link
                  to="/enterprise/register"
                  className={`mt-7 ${p.highlight ? 'btn-primary' : 'btn-ghost'}`}
                >
                  {p.name === 'Enterprise' ? 'Talk to sales' : 'Start free trial'}
                  <ArrowRight size={15} />
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* FINAL CTA */}
      <section className="py-24 border-t border-white/[0.05]">
        <div className="container-x">
          <div className="glass p-12 lg:p-16 text-center relative overflow-hidden">
            <NetworkGraph className="opacity-20" />
            <div className="relative">
              <h2 className="section-title text-4xl md:text-5xl tracking-tighter max-w-3xl mx-auto">
                Start your business onboarding.
              </h2>
              <p className="mt-5 text-textc-secondary max-w-xl mx-auto">
                Register your organization, federate identity, and download a signed AIOS bundle
                — in a single guided flow.
              </p>
              <div className="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
                <Link to="/enterprise/register" className="btn-primary" data-testid="final-cta">
                  Get started free
                  <ArrowRight size={16} />
                </Link>
                <Link to="/sign-in" className="btn-ghost">
                  Sign in to Cockpit
                </Link>
              </div>
              <div className="mt-8 flex justify-center flex-wrap gap-2">
                {ONBOARDING_STEPS.map((s, i) => (
                  <span
                    key={s}
                    className="mono text-[10px] uppercase tracking-wider text-textc-muted px-2.5 py-1 border border-white/[0.06] rounded-full"
                  >
                    {String(i + 1).padStart(2, '0')} · {s}
                  </span>
                ))}
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* FOOTER */}
      <footer className="py-12 border-t border-white/[0.05]" data-testid="footer">
        <div className="container-x grid md:grid-cols-5 gap-8">
          <div className="md:col-span-2">
            <Logo />
            <p className="mt-4 text-sm text-textc-secondary max-w-sm">
              The AI Operating System for business automation. Identity, data, agents, workflows,
              observability — one control plane.
            </p>
            <div className="mt-5 flex gap-2">
              <Pill tone="success">All systems operational</Pill>
            </div>
          </div>
          {[
            { h: 'Platform', l: ['Cockpit', 'AIOS Runtime', 'Connectors', 'Developer Portal'] },
            { h: 'Security', l: ['Trust Center', 'SOC 2', 'GDPR', 'Status'] },
            { h: 'Company', l: ['About', 'Careers', 'Pricing', 'Contact'] },
          ].map((c) => (
            <div key={c.h}>
              <h4 className="text-sm font-medium">{c.h}</h4>
              <ul className="mt-3 space-y-2">
                {c.l.map((x) => {
                  const href =
                    x === 'Trust Center' || x === 'Status'
                      ? '/trust'
                      : `#${x.toLowerCase().replace(/\s+/g, '-')}`;
                  return (
                    <li key={x}>
                      <a
                        href={href}
                        className="text-sm text-textc-secondary hover:text-textc-primary"
                      >
                        {x}
                      </a>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>
        <div className="container-x mt-10 pt-6 border-t border-white/[0.04] flex flex-wrap justify-between gap-3 text-xs text-textc-muted">
          <span>© {new Date().getFullYear()} SpiderNetOS. All rights reserved.</span>
          <span className="mono">v1.0 · build {(Date.now() / 1000) | 0}</span>
        </div>
      </footer>
    </div>
  );
}

/* Architecture SVG */
function ArchitectureSVG() {
  return (
    <svg viewBox="0 0 520 360" className="w-full h-auto" aria-label="AIOS architecture diagram">
      <defs>
        <linearGradient id="a-gov" x1="0" x2="1">
          <stop offset="0" stopColor="#00D6C9" stopOpacity="0.25" />
          <stop offset="1" stopColor="#00D6C9" stopOpacity="0" />
        </linearGradient>
        <linearGradient id="a-ctl" x1="0" x2="1">
          <stop offset="0" stopColor="#FF6B2C" stopOpacity="0.25" />
          <stop offset="1" stopColor="#FF6B2C" stopOpacity="0" />
        </linearGradient>
        <linearGradient id="a-data" x1="0" x2="1">
          <stop offset="0" stopColor="#F4F7FB" stopOpacity="0.1" />
          <stop offset="1" stopColor="#F4F7FB" stopOpacity="0" />
        </linearGradient>
      </defs>

      {/* Governance plane */}
      <rect x="20" y="20" width="480" height="90" rx="14" fill="url(#a-gov)" stroke="#00D6C9" strokeOpacity="0.35" />
      <text x="40" y="50" fill="#00D6C9" fontFamily="Geist Mono, monospace" fontSize="10" letterSpacing="2">GOVERNANCE PLANE</text>
      <g fontFamily="Geist Sans, sans-serif" fill="#F4F7FB" fontSize="11">
        {['SSO / SAML', 'RBAC', 'Audit log', 'Keys (KMS)', 'Residency'].map((t, i) => (
          <g key={t}>
            <rect x={40 + i * 92} y={64} width={84} height={32} rx="8" fill="#172033" stroke="#ffffff14" />
            <text x={40 + i * 92 + 42} y={84} textAnchor="middle">{t}</text>
          </g>
        ))}
      </g>

      {/* Control plane */}
      <rect x="20" y="130" width="480" height="90" rx="14" fill="url(#a-ctl)" stroke="#FF6B2C" strokeOpacity="0.35" />
      <text x="40" y="160" fill="#FF6B2C" fontFamily="Geist Mono, monospace" fontSize="10" letterSpacing="2">CONTROL PLANE</text>
      <g fontFamily="Geist Sans, sans-serif" fill="#F4F7FB" fontSize="11">
        {['Cockpit', 'Agents', 'Flows', 'Approvals', 'Deploys'].map((t, i) => (
          <g key={t}>
            <rect x={40 + i * 92} y={174} width={84} height={32} rx="8" fill="#172033" stroke="#ffffff14" />
            <text x={40 + i * 92 + 42} y={194} textAnchor="middle">{t}</text>
          </g>
        ))}
      </g>

      {/* Data plane */}
      <rect x="20" y="240" width="480" height="90" rx="14" fill="url(#a-data)" stroke="#ffffff20" />
      <text x="40" y="270" fill="#A8B3C7" fontFamily="Geist Mono, monospace" fontSize="10" letterSpacing="2">DATA PLANE</text>
      <g fontFamily="Geist Sans, sans-serif" fill="#F4F7FB" fontSize="11">
        {['ERP', 'CRM', 'BI', 'Lake', 'IdP'].map((t, i) => (
          <g key={t}>
            <rect x={40 + i * 92} y={284} width={84} height={32} rx="8" fill="#172033" stroke="#ffffff14" />
            <text x={40 + i * 92 + 42} y={304} textAnchor="middle">{t}</text>
          </g>
        ))}
      </g>

      {/* Vertical pipes */}
      <g stroke="#00D6C9" strokeOpacity="0.35" strokeDasharray="3 4">
        <line x1="260" y1="110" x2="260" y2="130" />
        <line x1="260" y1="220" x2="260" y2="240" />
      </g>
    </svg>
  );
}
