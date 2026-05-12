import React, { useState, useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  Check,
  ChevronRight,
  ChevronLeft,
  Building2,
  Globe,
  Layers,
  KeyRound,
  ShieldCheck,
  GitBranch,
  Terminal,
  Plug,
  Package,
  Activity,
  Copy,
  RefreshCw,
  AlertCircle,
  Loader2,
  Download,
  CheckCircle2,
  Sparkles,
  ExternalLink,
} from 'lucide-react';
import { Logo, Pill } from '../components/Atoms';
import { api, auth } from '../lib/api';

const STEPS = [
  { id: 1, key: 'org', title: 'Business email & org', icon: Building2 },
  { id: 2, key: 'domain', title: 'Domain verification', icon: Globe },
  { id: 3, key: 'tenant', title: 'Tenant creation', icon: Layers },
  { id: 4, key: 'sso', title: 'SSO (OIDC / SAML)', icon: KeyRound },
  { id: 5, key: 'mfa', title: 'MFA policy', icon: ShieldCheck },
  { id: 6, key: 'scim', title: 'SCIM provisioning', icon: GitBranch },
  { id: 7, key: 'cockpit', title: 'Cockpit provisioning', icon: Terminal },
  { id: 8, key: 'connectors', title: 'Connector wizard', icon: Plug },
  { id: 9, key: 'bundle', title: 'AIOS bundle', icon: Package },
  { id: 10, key: 'deploy', title: 'Deployment tracking', icon: Activity },
];

export default function RegisterWizard() {
  const [step, setStep] = useState(1);
  const [data, setData] = useState({
    org_name: '',
    contact_email: '',
    contact_name: '',
    domain: '',
    region: 'us-east-1',
    sso_provider: 'oidc-demo',
    sso_issuer: '',
    sso_client_id: '',
    sso_client_secret: '',
    mfa_required: true,
    mfa_methods: ['webauthn', 'totp'],
    phishing_resistant: true,
    selected_connectors: [],
    bundle_target: 'linux-x86_64',
    bundle_components: ['runtime', 'connectors', 'cockpit-agent'],
  });
  const [state, setState] = useState({
    enterprise_id: null,
    tenant: null,
    domain_token: null,
    domain_verified: false,
    scim_token: null,
    scim_base_url: null,
    bundle: null,
    deployment_id: null,
  });
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState(null);
  const nav = useNavigate();

  const update = (patch) => setData((d) => ({ ...d, ...patch }));
  const next = () => setStep((s) => Math.min(s + 1, 10));
  const prev = () => setStep((s) => Math.max(s - 1, 1));

  const StepIcon = STEPS[step - 1].icon;

  return (
    <div className="min-h-screen bg-bg-base text-textc-primary" data-testid="register-wizard">
      <header className="border-b border-white/[0.06]">
        <div className="container-x py-4 flex items-center justify-between">
          <Link to="/" data-testid="wizard-logo">
            <Logo />
          </Link>
          <div className="flex items-center gap-3">
            <Pill tone="cyan">Enterprise registration</Pill>
            <Link to="/sign-in" className="text-sm text-textc-secondary hover:text-textc-primary">
              Already have a tenant? Sign in
            </Link>
          </div>
        </div>
      </header>

      <div className="container-x py-10 grid lg:grid-cols-12 gap-8">
        {/* Left rail */}
        <aside className="lg:col-span-4 xl:col-span-3" data-testid="wizard-steps">
          <div className="glass p-6 sticky top-6">
            <div className="mono text-xs uppercase tracking-wider text-textc-muted mb-5">
              {step} of 10 — {Math.round((step / 10) * 100)}% complete
            </div>
            <div className="h-1 bg-bg-s3 rounded-full overflow-hidden mb-7">
              <div
                className="h-full bg-orange-cyan transition-all duration-500"
                style={{ width: `${(step / 10) * 100}%` }}
              />
            </div>
            <ol className="space-y-1">
              {STEPS.map((s) => {
                const status = s.id < step ? 'done' : s.id === step ? 'active' : 'pending';
                return (
                  <li key={s.id}>
                    <button
                      data-testid={`wizard-step-${s.id}`}
                      onClick={() => s.id <= step && setStep(s.id)}
                      className={`w-full text-left flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${
                        status === 'active'
                          ? 'bg-accent-cyan/[0.08] text-textc-primary'
                          : status === 'done'
                          ? 'text-textc-secondary hover:bg-white/[0.03] cursor-pointer'
                          : 'text-textc-muted cursor-not-allowed'
                      }`}
                    >
                      <span
                        className={`size-7 rounded-full border flex items-center justify-center text-xs mono flex-shrink-0 ${
                          status === 'done'
                            ? 'bg-accent-cyan/15 border-accent-cyan/50 text-accent-cyan'
                            : status === 'active'
                            ? 'bg-accent-orange/15 border-accent-orange/60 text-accent-orange'
                            : 'border-white/10'
                        }`}
                      >
                        {status === 'done' ? <Check size={13} /> : s.id}
                      </span>
                      <span className="text-sm">{s.title}</span>
                    </button>
                  </li>
                );
              })}
            </ol>
          </div>
        </aside>

        {/* Right pane */}
        <main className="lg:col-span-8 xl:col-span-9">
          <div className="glass p-8 lg:p-10 min-h-[560px] flex flex-col">
            <div className="flex items-center gap-3">
              <div className="size-11 rounded-xl bg-bg-s3 border border-accent-cyan/30 flex items-center justify-center">
                <StepIcon size={20} className="text-accent-cyan" />
              </div>
              <div>
                <div className="mono text-xs uppercase tracking-wider text-textc-muted">
                  Step {step} / 10
                </div>
                <h2 className="text-2xl tracking-tight font-medium" data-testid="wizard-step-title">
                  {STEPS[step - 1].title}
                </h2>
              </div>
            </div>

            <div className="mt-8 flex-1" data-testid={`wizard-pane-${STEPS[step - 1].key}`}>
              {err && (
                <div className="mb-5 flex items-center gap-2 text-sm text-feedback-danger" data-testid="wizard-error">
                  <AlertCircle size={15} /> {err}
                </div>
              )}

              {step === 1 && <Step1Org data={data} update={update} />}
              {step === 2 && (
                <Step2Domain
                  data={data}
                  update={update}
                  state={state}
                  setState={setState}
                  setErr={setErr}
                  setLoading={setLoading}
                  loading={loading}
                />
              )}
              {step === 3 && (
                <Step3Tenant
                  data={data}
                  update={update}
                  state={state}
                  setState={setState}
                  setErr={setErr}
                  setLoading={setLoading}
                  loading={loading}
                />
              )}
              {step === 4 && <Step4SSO data={data} update={update} />}
              {step === 5 && <Step5MFA data={data} update={update} />}
              {step === 6 && (
                <Step6SCIM
                  state={state}
                  setState={setState}
                  setErr={setErr}
                  setLoading={setLoading}
                  loading={loading}
                />
              )}
              {step === 7 && <Step7Cockpit state={state} />}
              {step === 8 && <Step8Connectors data={data} update={update} />}
              {step === 9 && (
                <Step9Bundle
                  data={data}
                  update={update}
                  state={state}
                  setState={setState}
                  setErr={setErr}
                  setLoading={setLoading}
                  loading={loading}
                />
              )}
              {step === 10 && <Step10Deploy state={state} setState={setState} setLoading={setLoading} loading={loading} />}
            </div>

            <div className="mt-8 pt-6 border-t border-white/[0.06] flex items-center justify-between">
              <button
                onClick={prev}
                disabled={step === 1}
                data-testid="wizard-prev"
                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-full text-textc-secondary hover:text-textc-primary disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
              >
                <ChevronLeft size={16} /> Back
              </button>
              {step < 10 ? (
                <button
                  onClick={async () => {
                    setErr(null);
                    // step gating logic
                    if (step === 1) {
                      if (!data.org_name || !data.contact_email) {
                        setErr('Please provide organization name and contact email.');
                        return;
                      }
                      // create enterprise record
                      try {
                        setLoading(true);
                        const res = await api.post('/enterprise/register/start', {
                          org_name: data.org_name,
                          contact_email: data.contact_email,
                          contact_name: data.contact_name,
                          domain: data.domain || data.contact_email.split('@')[1],
                        });
                        setState((s) => ({
                          ...s,
                          enterprise_id: res.data.enterprise_id,
                          domain_token: res.data.domain_token,
                        }));
                        if (!data.domain) update({ domain: data.contact_email.split('@')[1] });
                      } catch (e) {
                        setErr(e?.response?.data?.detail || 'Failed to start registration');
                        setLoading(false);
                        return;
                      }
                      setLoading(false);
                    }
                    next();
                  }}
                  data-testid="wizard-next"
                  disabled={loading}
                  className="btn-primary"
                >
                  {loading ? <Loader2 size={16} className="animate-spin" /> : <>Continue <ChevronRight size={16} /></>}
                </button>
              ) : (
                <button
                  onClick={() => {
                    // Save session and go to cockpit
                    if (state.tenant && state.enterprise_id) {
                      auth.saveSession({
                        access_token: `ent.${state.enterprise_id}`,
                        user: {
                          email: data.contact_email,
                          name: data.contact_name || data.contact_email.split('@')[0],
                          role: 'tenant_owner',
                        },
                        tenant: state.tenant,
                      });
                    }
                    nav('/cockpit');
                  }}
                  data-testid="wizard-finish"
                  className="btn-primary"
                >
                  Enter Cockpit <ChevronRight size={16} />
                </button>
              )}
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}

// ─── STEP 1: ORG ───
function Step1Org({ data, update }) {
  return (
    <div className="space-y-5 max-w-xl">
      <p className="text-textc-secondary">
        Tell us who's setting up the SpiderNetOS tenant. We'll use this to verify domain
        ownership and create the first admin account.
      </p>
      <Field label="Organization name">
        <input
          data-testid="org-name"
          className="input-field"
          placeholder="Acme Corp"
          value={data.org_name}
          onChange={(e) => update({ org_name: e.target.value })}
        />
      </Field>
      <Field label="Your name">
        <input
          data-testid="org-contact-name"
          className="input-field"
          placeholder="Jane Doe"
          value={data.contact_name}
          onChange={(e) => update({ contact_name: e.target.value })}
        />
      </Field>
      <Field label="Business email" hint="Must use your corporate domain.">
        <input
          data-testid="org-contact-email"
          type="email"
          className="input-field"
          placeholder="jane@acme.com"
          value={data.contact_email}
          onChange={(e) => update({ contact_email: e.target.value })}
        />
      </Field>
      <Field label="Domain" hint="Auto-filled from your email; you can change it.">
        <input
          data-testid="org-domain"
          className="input-field"
          placeholder="acme.com"
          value={data.domain}
          onChange={(e) => update({ domain: e.target.value })}
        />
      </Field>
    </div>
  );
}

// ─── STEP 2: DOMAIN ───
function Step2Domain({ data, state, setState, setErr, setLoading, loading }) {
  const verify = async () => {
    setErr(null);
    setLoading(true);
    try {
      const res = await api.post('/enterprise/register/verify-domain', {
        enterprise_id: state.enterprise_id,
        method: 'auto', // demo: auto-verify
      });
      setState((s) => ({ ...s, domain_verified: res.data.verified }));
    } catch (e) {
      setErr(e?.response?.data?.detail || 'Domain verification failed');
    } finally {
      setLoading(false);
    }
  };
  const txt = `spidernet-verify=${state.domain_token || 'sn_xxxxxxxxxxxx'}`;
  return (
    <div className="space-y-6 max-w-2xl">
      <p className="text-textc-secondary">
        Add a DNS TXT record to <span className="mono text-textc-primary">{data.domain || 'your domain'}</span>, then
        click verify. The token expires in 24 hours.
      </p>
      <div className="glass p-5 bg-bg-s2/60">
        <div className="mono text-xs uppercase tracking-wider text-textc-muted mb-3">DNS TXT record</div>
        <div className="grid sm:grid-cols-[80px_1fr] gap-2 items-start">
          <span className="mono text-xs text-textc-muted pt-1">Host</span>
          <code className="mono text-sm text-textc-primary">_spidernet.{data.domain || 'acme.com'}</code>
          <span className="mono text-xs text-textc-muted pt-1">Value</span>
          <div className="flex items-center gap-2">
            <code className="mono text-sm text-accent-cyan flex-1 break-all" data-testid="domain-txt-value">
              {txt}
            </code>
            <button
              onClick={() => navigator.clipboard?.writeText(txt)}
              className="p-2 rounded-md hover:bg-white/[0.06]"
              aria-label="Copy"
              data-testid="domain-copy-txt"
            >
              <Copy size={14} />
            </button>
          </div>
        </div>
      </div>
      <div className="flex items-center gap-3">
        <button
          onClick={verify}
          disabled={loading || !state.enterprise_id}
          data-testid="domain-verify"
          className="btn-primary"
        >
          {loading ? <Loader2 size={16} className="animate-spin" /> : <RefreshCw size={16} />}
          {loading ? 'Verifying…' : 'Verify domain'}
        </button>
        {state.domain_verified && (
          <Pill tone="success" icon={<Check size={12} />} data-testid="domain-verified-badge">
            Verified
          </Pill>
        )}
      </div>
      <p className="text-xs text-textc-muted">
        Alternative methods: email verification, IdP assertion. In demo mode, verification is
        simulated and resolves instantly.
      </p>
    </div>
  );
}

// ─── STEP 3: TENANT ───
function Step3Tenant({ data, update, state, setState, setErr, setLoading, loading }) {
  useEffect(() => {
    if (state.tenant || !state.enterprise_id) return;
    (async () => {
      setLoading(true);
      try {
        const res = await api.post('/enterprise/register/create-tenant', {
          enterprise_id: state.enterprise_id,
          region: data.region,
        });
        setState((s) => ({ ...s, tenant: res.data.tenant }));
      } catch (e) {
        setErr(e?.response?.data?.detail || 'Tenant creation failed');
      } finally {
        setLoading(false);
      }
    })();
  }, [state.enterprise_id]);

  return (
    <div className="space-y-5 max-w-2xl">
      <p className="text-textc-secondary">
        Choose your tenant's data residency region. This decision is permanent and applies to all
        AIOS components, connectors, and audit logs.
      </p>
      <Field label="Data residency region">
        <select
          data-testid="tenant-region"
          className="input-field"
          value={data.region}
          onChange={(e) => update({ region: e.target.value })}
        >
          <option value="us-east-1">US East — N. Virginia</option>
          <option value="us-west-2">US West — Oregon</option>
          <option value="eu-central-1">EU Central — Frankfurt</option>
          <option value="eu-west-1">EU West — Dublin</option>
          <option value="ap-southeast-1">Asia Pacific — Singapore</option>
          <option value="ap-northeast-1">Asia Pacific — Tokyo</option>
        </select>
      </Field>
      {state.tenant && (
        <div className="glass p-5 bg-bg-s2/60 space-y-3" data-testid="tenant-created-card">
          <div className="flex items-center justify-between">
            <div className="mono text-xs uppercase tracking-wider text-textc-muted">Tenant created</div>
            <Pill tone="success" icon={<Check size={12} />}>Live</Pill>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <KV label="ID" v={state.tenant.id} mono />
            <KV label="Slug" v={state.tenant.slug} mono />
            <KV label="Region" v={state.tenant.region} mono />
            <KV label="Plan" v={state.tenant.plan} />
          </div>
        </div>
      )}
    </div>
  );
}

// ─── STEP 4: SSO ───
function Step4SSO({ data, update }) {
  return (
    <div className="space-y-5 max-w-2xl">
      <p className="text-textc-secondary">
        Federate identity with your existing provider. For demo mode, pick "Demo IdP" — we
        simulate the full ceremony.
      </p>
      <Field label="Protocol & provider">
        <select
          data-testid="sso-protocol"
          className="input-field"
          value={data.sso_provider}
          onChange={(e) => update({ sso_provider: e.target.value })}
        >
          <option value="oidc-demo">OIDC — Demo IdP (simulated)</option>
          <option value="okta">OIDC — Okta</option>
          <option value="entra">OIDC — Microsoft Entra ID</option>
          <option value="auth0">OIDC — Auth0</option>
          <option value="google">OIDC — Google Workspace</option>
          <option value="saml-generic">SAML 2.0 — Generic IdP</option>
        </select>
      </Field>
      {!data.sso_provider.includes('demo') && (
        <>
          <Field label="Issuer URL" hint="Your IdP's OIDC discovery URL (without /.well-known)">
            <input
              data-testid="sso-issuer"
              className="input-field"
              placeholder="https://acme.okta.com"
              value={data.sso_issuer}
              onChange={(e) => update({ sso_issuer: e.target.value })}
            />
          </Field>
          <Field label="Client ID">
            <input
              data-testid="sso-client-id"
              className="input-field mono"
              placeholder="0oa1bcdef…"
              value={data.sso_client_id}
              onChange={(e) => update({ sso_client_id: e.target.value })}
            />
          </Field>
          <Field label="Client secret">
            <input
              data-testid="sso-client-secret"
              type="password"
              className="input-field mono"
              placeholder="••••••••••••••"
              value={data.sso_client_secret}
              onChange={(e) => update({ sso_client_secret: e.target.value })}
            />
          </Field>
        </>
      )}
      <div className="glass p-4 bg-bg-s2/50 text-sm text-textc-secondary">
        <div className="flex items-center gap-2 text-accent-cyan mb-2">
          <ShieldCheck size={14} /> <span className="mono text-xs uppercase tracking-wider">SP redirect URI</span>
        </div>
        <code className="mono text-xs break-all">
          {`${(typeof window !== 'undefined' && window.location.origin) || 'https://app.spidernetos.com'}/api/enterprise/auth/sso/callback`}
        </code>
        <div className="mt-2 text-xs text-textc-muted">
          Add this URL to your IdP application's allowed redirect URIs.
        </div>
      </div>
    </div>
  );
}

// ─── STEP 5: MFA ───
function Step5MFA({ data, update }) {
  const toggle = (m) => {
    const set = new Set(data.mfa_methods);
    set.has(m) ? set.delete(m) : set.add(m);
    update({ mfa_methods: [...set] });
  };
  return (
    <div className="space-y-6 max-w-2xl">
      <p className="text-textc-secondary">
        Enforce phishing-resistant MFA across your tenant. We recommend WebAuthn passkeys with
        TOTP as fallback.
      </p>
      <label className="flex items-center gap-3 cursor-pointer">
        <input
          type="checkbox"
          data-testid="mfa-required"
          checked={data.mfa_required}
          onChange={(e) => update({ mfa_required: e.target.checked })}
          className="size-5 accent-accent-cyan"
        />
        <span>Require MFA for all users</span>
      </label>
      <label className="flex items-center gap-3 cursor-pointer">
        <input
          type="checkbox"
          data-testid="mfa-phishing"
          checked={data.phishing_resistant}
          onChange={(e) => update({ phishing_resistant: e.target.checked })}
          className="size-5 accent-accent-cyan"
        />
        <span>Require phishing-resistant MFA for admin step-up</span>
      </label>
      <div>
        <div className="mono text-xs uppercase tracking-wider text-textc-muted mb-3">
          Allowed methods
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
          {[
            { id: 'webauthn', l: 'WebAuthn' },
            { id: 'totp', l: 'TOTP' },
            { id: 'sms', l: 'SMS' },
            { id: 'recovery', l: 'Recovery codes' },
          ].map((m) => {
            const on = data.mfa_methods.includes(m.id);
            return (
              <button
                key={m.id}
                data-testid={`mfa-method-${m.id}`}
                onClick={() => toggle(m.id)}
                className={`px-4 py-3 rounded-lg border text-sm transition-all ${
                  on
                    ? 'border-accent-cyan/60 bg-accent-cyan/[0.08] text-textc-primary'
                    : 'border-white/10 text-textc-secondary hover:border-white/30'
                }`}
              >
                {m.l}
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}

// ─── STEP 6: SCIM ───
function Step6SCIM({ state, setState, setErr, setLoading, loading }) {
  const provision = async () => {
    setErr(null);
    setLoading(true);
    try {
      const res = await api.post('/enterprise/register/scim/generate', {
        enterprise_id: state.enterprise_id,
        tenant_id: state.tenant?.id,
      });
      setState((s) => ({
        ...s,
        scim_token: res.data.scim_token,
        scim_base_url: res.data.scim_base_url,
      }));
    } catch (e) {
      setErr(e?.response?.data?.detail || 'SCIM provisioning failed');
    } finally {
      setLoading(false);
    }
  };
  return (
    <div className="space-y-5 max-w-2xl">
      <p className="text-textc-secondary">
        Generate a SCIM 2.0 token to enable inbound user and group provisioning from your IdP.
        Save the token — it is shown only once.
      </p>
      {!state.scim_token ? (
        <button onClick={provision} disabled={loading} data-testid="scim-generate" className="btn-primary">
          {loading ? <Loader2 size={16} className="animate-spin" /> : <GitBranch size={16} />}
          Generate SCIM token
        </button>
      ) : (
        <div className="glass p-5 bg-bg-s2/60 space-y-3" data-testid="scim-card">
          <KV label="Base URL" v={state.scim_base_url} mono copy />
          <KV label="Bearer token" v={state.scim_token} mono copy mask />
          <Pill tone="warning" icon={<AlertCircle size={12} />}>
            Save now — not shown again
          </Pill>
        </div>
      )}
      <div className="text-xs text-textc-muted">
        Endpoints: <span className="mono text-textc-secondary">/Users</span>,{' '}
        <span className="mono text-textc-secondary">/Groups</span>,{' '}
        <span className="mono text-textc-secondary">/ServiceProviderConfig</span>. PATCH supported for incremental updates.
      </div>
    </div>
  );
}

// ─── STEP 7: COCKPIT ───
function Step7Cockpit({ state }) {
  return (
    <div className="space-y-5 max-w-2xl">
      <p className="text-textc-secondary">
        Your Cockpit is provisioning. The first admin account is bound to your verified email and
        granted the <span className="mono text-textc-primary">tenant_owner</span> role.
      </p>
      <div className="glass p-5 bg-bg-s2/60">
        <ProvisioningChecklist
          items={[
            { l: 'Allocate isolated tenant namespace', done: true },
            { l: 'Bootstrap RBAC capability matrix', done: true },
            { l: 'Seed audit log with onboarding events', done: true },
            { l: 'Provision Cockpit admin account', done: true },
            { l: 'Mount default policy templates', done: true },
          ]}
        />
      </div>
      {state.tenant && (
        <div className="grid grid-cols-2 gap-4">
          <KV label="Cockpit URL" v={`${state.tenant.slug}.spidernetos.com`} mono />
          <KV label="First admin" v={state.tenant.first_admin_email} mono />
        </div>
      )}
    </div>
  );
}

const CONN_OPTIONS = [
  { id: 'okta', name: 'Okta', cat: 'IAM' },
  { id: 'entra', name: 'Microsoft Entra ID', cat: 'IAM' },
  { id: 'sap', name: 'SAP S/4HANA', cat: 'ERP' },
  { id: 'salesforce', name: 'Salesforce', cat: 'CRM' },
  { id: 'snowflake', name: 'Snowflake', cat: 'Data' },
  { id: 'databricks', name: 'Databricks', cat: 'Data' },
  { id: 'powerbi', name: 'Power BI', cat: 'BI' },
  { id: 'slack', name: 'Slack', cat: 'Messaging' },
];

// ─── STEP 8: CONNECTORS ───
function Step8Connectors({ data, update }) {
  const toggle = (id) => {
    const set = new Set(data.selected_connectors);
    set.has(id) ? set.delete(id) : set.add(id);
    update({ selected_connectors: [...set] });
  };
  return (
    <div className="space-y-5">
      <p className="text-textc-secondary max-w-2xl">
        Pre-select the business systems you want AIOS to integrate with. You can add or remove
        connectors anytime from Cockpit → Connectors.
      </p>
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        {CONN_OPTIONS.map((c) => {
          const on = data.selected_connectors.includes(c.id);
          return (
            <button
              key={c.id}
              onClick={() => toggle(c.id)}
              data-testid={`connector-pick-${c.id}`}
              className={`p-4 rounded-xl border text-left transition-all ${
                on
                  ? 'border-accent-cyan/60 bg-accent-cyan/[0.08]'
                  : 'border-white/10 hover:border-white/30 bg-bg-s1/50'
              }`}
            >
              <div className="flex items-center justify-between">
                <div className="font-medium">{c.name}</div>
                {on && <CheckCircle2 size={16} className="text-accent-cyan" />}
              </div>
              <div className="mono text-[10px] uppercase tracking-wider text-textc-muted mt-1">
                {c.cat}
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ─── STEP 9: BUNDLE ───
function Step9Bundle({ data, update, state, setState, setErr, setLoading, loading }) {
  const request = async () => {
    setErr(null);
    setLoading(true);
    try {
      const res = await api.post('/enterprise/register/bundle/create', {
        enterprise_id: state.enterprise_id,
        tenant_id: state.tenant?.id,
        target: data.bundle_target,
        components: data.bundle_components,
      });
      setState((s) => ({ ...s, bundle: res.data }));
    } catch (e) {
      setErr(e?.response?.data?.detail || 'Bundle generation failed');
    } finally {
      setLoading(false);
    }
  };
  const BACKEND_URL = process.env.REACT_APP_BACKEND_URL || '';
  return (
    <div className="space-y-6 max-w-3xl">
      <p className="text-textc-secondary">
        Request a signed AIOS bundle scoped to this tenant. Bundles include the runtime,
        connectors, and the cockpit agent — all signed with the platform key.
      </p>
      <div className="grid sm:grid-cols-2 gap-4">
        <Field label="Target platform">
          <select
            data-testid="bundle-target"
            className="input-field"
            value={data.bundle_target}
            onChange={(e) => update({ bundle_target: e.target.value })}
          >
            <option value="linux-x86_64">Linux x86_64</option>
            <option value="linux-arm64">Linux ARM64</option>
            <option value="windows-x86_64">Windows x86_64</option>
            <option value="docker">Docker (containerized)</option>
          </select>
        </Field>
        <Field label="Components">
          <div className="flex flex-wrap gap-2 pt-2">
            {['runtime', 'connectors', 'cockpit-agent', 'observability'].map((c) => {
              const on = data.bundle_components.includes(c);
              return (
                <button
                  key={c}
                  data-testid={`bundle-comp-${c}`}
                  onClick={() => {
                    const set = new Set(data.bundle_components);
                    set.has(c) ? set.delete(c) : set.add(c);
                    update({ bundle_components: [...set] });
                  }}
                  className={`px-3 py-1.5 rounded-full text-xs border transition-colors ${
                    on
                      ? 'border-accent-cyan/60 bg-accent-cyan/[0.1] text-accent-cyan'
                      : 'border-white/10 text-textc-secondary hover:border-white/30'
                  }`}
                >
                  {c}
                </button>
              );
            })}
          </div>
        </Field>
      </div>
      {!state.bundle ? (
        <button onClick={request} disabled={loading} data-testid="bundle-create" className="btn-primary">
          {loading ? <Loader2 size={16} className="animate-spin" /> : <Sparkles size={16} />}
          Generate signed bundle
        </button>
      ) : (
        <div className="glass p-6 space-y-5" data-testid="bundle-card">
          <div className="flex items-center justify-between">
            <div>
              <div className="mono text-xs uppercase tracking-wider text-textc-muted">
                Bundle ready
              </div>
              <div className="mt-1 mono text-lg text-accent-cyan">{state.bundle.bundle_id}</div>
            </div>
            <Pill tone="success" icon={<Check size={12} />}>Signed</Pill>
          </div>
          <div className="space-y-2">
            <KV label="Size" v={`${(state.bundle.size_bytes / 1024).toFixed(1)} KB`} mono />
            <KV label="Target" v={state.bundle.target} mono />
            <KV label="SHA-256" v={state.bundle.sha256} mono copy long />
            <KV label="Signature" v={state.bundle.signature} mono copy long />
            <KV label="Expires" v={new Date(state.bundle.expires_at).toLocaleString()} mono />
          </div>
          <a
            href={`${BACKEND_URL}${state.bundle.download_path}`}
            target="_blank"
            rel="noreferrer"
            data-testid="bundle-download"
            className="btn-primary"
          >
            <Download size={16} /> Download bundle (.zip)
          </a>
          <div className="text-xs text-textc-muted">
            Verify locally before install:{' '}
            <code className="mono text-textc-secondary">
              sha256sum {state.bundle.bundle_id}.zip
            </code>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── STEP 10: DEPLOY ───
function Step10Deploy({ state, setState, setLoading, loading }) {
  const [progress, setProgress] = useState(0);
  const startedRef = useRef(false);

  useEffect(() => {
    if (startedRef.current || !state.bundle) return;
    startedRef.current = true;
    (async () => {
      try {
        const res = await api.post('/enterprise/register/deploy/start', {
          bundle_id: state.bundle.bundle_id,
        });
        setState((s) => ({ ...s, deployment_id: res.data.deployment_id }));
      } catch {}
    })();
    let p = 0;
    const t = setInterval(() => {
      p += 6 + Math.random() * 8;
      if (p >= 100) {
        p = 100;
        clearInterval(t);
      }
      setProgress(Math.floor(p));
    }, 350);
    return () => clearInterval(t);
  }, [state.bundle]);

  const steps = [
    { l: 'Transfer signed bundle', t: 22 },
    { l: 'Verify SHA-256 + signature', t: 38 },
    { l: 'Bootstrap runtime', t: 55 },
    { l: 'Register with Cockpit', t: 72 },
    { l: 'Health probe + handshake', t: 90 },
    { l: 'Mark deployment healthy', t: 100 },
  ];

  return (
    <div className="space-y-6 max-w-2xl">
      <p className="text-textc-secondary">
        Your AIOS instance is initializing. Once healthy, it will appear in Cockpit → Deployments
        and start emitting telemetry.
      </p>
      <div>
        <div className="flex items-center justify-between mb-2 text-sm">
          <span className="mono text-xs uppercase tracking-wider text-textc-muted">Deployment progress</span>
          <span className="mono text-accent-cyan">{progress}%</span>
        </div>
        <div className="h-1.5 bg-bg-s3 rounded-full overflow-hidden">
          <div
            data-testid="deploy-progress-bar"
            className="h-full bg-orange-cyan transition-all duration-300"
            style={{ width: `${progress}%` }}
          />
        </div>
      </div>
      <ol className="space-y-3">
        {steps.map((s, i) => {
          const done = progress >= s.t;
          return (
            <li key={i} className="flex items-center gap-3">
              <span
                className={`size-6 rounded-full border flex items-center justify-center text-xs flex-shrink-0 ${
                  done
                    ? 'border-accent-cyan/60 bg-accent-cyan/15 text-accent-cyan'
                    : 'border-white/10 text-textc-muted'
                }`}
              >
                {done ? <Check size={12} /> : i + 1}
              </span>
              <span className={done ? 'text-textc-primary' : 'text-textc-muted'}>{s.l}</span>
            </li>
          );
        })}
      </ol>
      {progress === 100 && (
        <Pill tone="success" icon={<Check size={12} />}>Deployment healthy — ready to use</Pill>
      )}
    </div>
  );
}

// ─── helpers ───
function Field({ label, hint, children }) {
  return (
    <label className="block">
      <div className="mono text-xs uppercase tracking-wider text-textc-muted mb-1.5">{label}</div>
      {children}
      {hint && <div className="text-xs text-textc-muted mt-1.5">{hint}</div>}
    </label>
  );
}

function KV({ label, v, mono, copy, mask, long }) {
  const [hidden, setHidden] = useState(mask);
  const display = mask && hidden ? '•'.repeat(Math.min((v || '').length, 24)) : v;
  return (
    <div>
      <div className="mono text-[10px] uppercase tracking-wider text-textc-muted">{label}</div>
      <div className="mt-1 flex items-center gap-2">
        <code
          className={`flex-1 ${mono ? 'mono' : ''} text-sm text-textc-primary ${long ? 'break-all' : 'truncate'}`}
        >
          {display}
        </code>
        {mask && (
          <button
            onClick={() => setHidden((h) => !h)}
            className="text-xs text-textc-secondary hover:text-textc-primary mono px-2 py-0.5 border border-white/10 rounded"
          >
            {hidden ? 'show' : 'hide'}
          </button>
        )}
        {copy && (
          <button
            aria-label={`Copy ${label}`}
            onClick={() => navigator.clipboard?.writeText(v)}
            className="p-1.5 rounded-md hover:bg-white/[0.06]"
          >
            <Copy size={13} />
          </button>
        )}
      </div>
    </div>
  );
}

function ProvisioningChecklist({ items }) {
  const [done, setDone] = useState(items.map(() => false));
  useEffect(() => {
    items.forEach((_, i) => {
      setTimeout(() => setDone((d) => d.map((v, j) => (j === i ? true : v))), 380 * (i + 1));
    });
    // eslint-disable-next-line
  }, []);
  return (
    <ul className="space-y-2.5">
      {items.map((it, i) => (
        <li key={i} className="flex items-center gap-3 text-sm">
          {done[i] ? (
            <Check size={15} className="text-accent-cyan flex-shrink-0" />
          ) : (
            <Loader2 size={15} className="text-textc-muted animate-spin flex-shrink-0" />
          )}
          <span className={done[i] ? 'text-textc-primary' : 'text-textc-secondary'}>{it.l}</span>
        </li>
      ))}
    </ul>
  );
}
