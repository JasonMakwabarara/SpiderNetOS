import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  KeyRound,
  Mail,
  ShieldCheck,
  Fingerprint,
  ArrowRight,
  Smartphone,
  AlertCircle,
  Check,
  Building2,
} from 'lucide-react';
import { Logo, Pill } from '../components/Atoms';
import { api, auth } from '../lib/api';
import NetworkGraph from '../components/NetworkGraph';

const METHODS = [
  { id: 'sso', label: 'Enterprise SSO', icon: KeyRound, sub: 'OIDC / SAML' },
  { id: 'magic', label: 'Magic link', icon: Mail, sub: 'Email passwordless' },
  { id: 'webauthn', label: 'Passkey', icon: Fingerprint, sub: 'WebAuthn / U2F' },
  { id: 'totp', label: 'TOTP code', icon: Smartphone, sub: 'Authenticator app' },
];

export default function SignInPage() {
  const [method, setMethod] = useState('sso');
  const [email, setEmail] = useState('');
  const [tenant, setTenant] = useState('');
  const [provider, setProvider] = useState('oidc-demo');
  const [code, setCode] = useState('');
  const [msg, setMsg] = useState(null);
  const [err, setErr] = useState(null);
  const [loading, setLoading] = useState(false);
  const [magicLinkPreview, setMagicLinkPreview] = useState(null);
  const nav = useNavigate();

  const doSSO = async () => {
    setErr(null);
    setLoading(true);
    try {
      const res = await api.post('/enterprise/auth/sso/start', {
        tenant_slug: tenant || 'demo',
        provider,
      });
      // demo mode auto-completes
      if (res.data.completed) {
        auth.saveSession(res.data);
        nav('/cockpit');
      } else {
        window.location.href = res.data.authorization_url;
      }
    } catch (e) {
      setErr(e?.response?.data?.detail || 'SSO failed');
    } finally {
      setLoading(false);
    }
  };

  const doMagic = async () => {
    setErr(null);
    setMsg(null);
    setLoading(true);
    try {
      const res = await api.post('/enterprise/auth/magic-link/request', { email });
      setMsg('Magic link sent. Check your inbox.');
      // In dev/demo mode we surface the link so user can click
      if (res.data.dev_link) setMagicLinkPreview(res.data.dev_link);
    } catch (e) {
      setErr(e?.response?.data?.detail || 'Failed to send magic link');
    } finally {
      setLoading(false);
    }
  };

  const consumeMagic = async (token) => {
    setLoading(true);
    try {
      const res = await api.post('/enterprise/auth/magic-link/verify', { token });
      auth.saveSession(res.data);
      nav('/cockpit');
    } catch (e) {
      setErr(e?.response?.data?.detail || 'Magic link invalid or expired');
    } finally {
      setLoading(false);
    }
  };

  const doTotp = async () => {
    setErr(null);
    setLoading(true);
    try {
      const res = await api.post('/enterprise/auth/totp/login', { email, code });
      auth.saveSession(res.data);
      nav('/cockpit');
    } catch (e) {
      setErr(e?.response?.data?.detail || 'TOTP verification failed');
    } finally {
      setLoading(false);
    }
  };

  const doWebauthn = async () => {
    setErr(null);
    setLoading(true);
    try {
      // Demo flow: backend issues a session for a registered demo passkey
      const res = await api.post('/enterprise/auth/webauthn/login', {
        email: email || 'operator@acme.ops',
      });
      auth.saveSession(res.data);
      nav('/cockpit');
    } catch (e) {
      setErr(e?.response?.data?.detail || 'Passkey not registered for this user');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-bg-base text-textc-primary relative overflow-hidden" data-testid="signin-page">
      <NetworkGraph className="opacity-30" />
      <div className="hero-glow absolute inset-0 pointer-events-none" />

      <div className="relative z-10 min-h-screen flex flex-col">
        <header className="container-x py-5 flex items-center justify-between">
          <Link to="/" data-testid="signin-logo">
            <Logo />
          </Link>
          <Link to="/enterprise/register" className="text-sm text-textc-secondary hover:text-textc-primary">
            New here? Register enterprise →
          </Link>
        </header>

        <main className="flex-1 flex items-center justify-center px-6 py-12">
          <div className="w-full max-w-md">
            <Pill tone="cyan" icon={<ShieldCheck size={12} />}>Cockpit sign-in</Pill>
            <h1 className="mt-4 text-3xl tracking-tight font-medium">
              Welcome back to <span className="text-accent-orange">SpiderNet</span>OS
            </h1>
            <p className="mt-2 text-textc-secondary text-sm">
              Choose your enterprise sign-in method.
            </p>

            <div className="mt-7 grid grid-cols-2 gap-2">
              {METHODS.map((m) => (
                <button
                  key={m.id}
                  onClick={() => {
                    setMethod(m.id);
                    setErr(null);
                    setMsg(null);
                  }}
                  data-testid={`signin-method-${m.id}`}
                  className={`text-left p-3 rounded-xl border transition-all ${
                    method === m.id
                      ? 'border-accent-cyan/60 bg-accent-cyan/[0.08]'
                      : 'border-white/10 hover:border-white/30 bg-bg-s1/50'
                  }`}
                >
                  <m.icon size={16} className={method === m.id ? 'text-accent-cyan' : 'text-textc-secondary'} />
                  <div className="mt-2 text-sm font-medium">{m.label}</div>
                  <div className="mono text-[10px] uppercase tracking-wider text-textc-muted mt-0.5">
                    {m.sub}
                  </div>
                </button>
              ))}
            </div>

            <div className="mt-6 glass p-6">
              {err && (
                <div className="mb-4 flex items-center gap-2 text-sm text-feedback-danger" data-testid="signin-error">
                  <AlertCircle size={15} /> {err}
                </div>
              )}
              {msg && (
                <div className="mb-4 flex items-center gap-2 text-sm text-feedback-success" data-testid="signin-msg">
                  <Check size={15} /> {msg}
                </div>
              )}

              {method === 'sso' && (
                <div className="space-y-4">
                  <div>
                    <label className="mono text-xs uppercase tracking-wider text-textc-muted">Tenant slug</label>
                    <input
                      data-testid="sso-tenant"
                      className="input-field mt-1.5"
                      placeholder="acme"
                      value={tenant}
                      onChange={(e) => setTenant(e.target.value)}
                    />
                  </div>
                  <div>
                    <label className="mono text-xs uppercase tracking-wider text-textc-muted">Identity provider</label>
                    <select
                      data-testid="sso-provider"
                      className="input-field mt-1.5"
                      value={provider}
                      onChange={(e) => setProvider(e.target.value)}
                    >
                      <option value="oidc-demo">Demo IdP (OIDC, simulated)</option>
                      <option value="okta">Okta (OIDC)</option>
                      <option value="entra">Microsoft Entra ID (OIDC)</option>
                      <option value="auth0">Auth0 (OIDC)</option>
                      <option value="google">Google Workspace (OIDC)</option>
                      <option value="saml-demo">Demo IdP (SAML 2.0)</option>
                    </select>
                  </div>
                  <button
                    data-testid="sso-submit"
                    disabled={loading}
                    onClick={doSSO}
                    className="btn-primary w-full"
                  >
                    {loading ? 'Redirecting…' : 'Continue with SSO'} <ArrowRight size={15} />
                  </button>
                  <p className="text-xs text-textc-muted">
                    For real providers, the tenant admin must first configure issuer URL and
                    client credentials in Cockpit → Security.
                  </p>
                </div>
              )}

              {method === 'magic' && (
                <div className="space-y-4">
                  <div>
                    <label className="mono text-xs uppercase tracking-wider text-textc-muted">Work email</label>
                    <input
                      data-testid="magic-email"
                      type="email"
                      className="input-field mt-1.5"
                      placeholder="you@company.com"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                    />
                  </div>
                  <button
                    data-testid="magic-submit"
                    disabled={loading || !email}
                    onClick={doMagic}
                    className="btn-primary w-full"
                  >
                    {loading ? 'Sending…' : 'Send magic link'} <ArrowRight size={15} />
                  </button>
                  {magicLinkPreview && (
                    <div className="mt-3 p-3 rounded-lg bg-bg-s3 border border-accent-cyan/30 text-xs">
                      <div className="text-textc-muted mb-2">
                        Dev mode: click the simulated link below to authenticate.
                      </div>
                      <button
                        data-testid="magic-dev-consume"
                        onClick={() => consumeMagic(magicLinkPreview.token)}
                        className="btn-link mono break-all text-left"
                      >
                        ↗ /verify?token={magicLinkPreview.token.slice(0, 32)}…
                      </button>
                    </div>
                  )}
                </div>
              )}

              {method === 'webauthn' && (
                <div className="space-y-4">
                  <div className="text-sm text-textc-secondary">
                    Sign in with a passkey or hardware security key registered to your account.
                  </div>
                  <div>
                    <label className="mono text-xs uppercase tracking-wider text-textc-muted">Work email</label>
                    <input
                      data-testid="webauthn-email"
                      type="email"
                      className="input-field mt-1.5"
                      placeholder="you@company.com"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                    />
                  </div>
                  <button
                    data-testid="webauthn-submit"
                    disabled={loading}
                    onClick={doWebauthn}
                    className="btn-primary w-full"
                  >
                    <Fingerprint size={16} /> Use passkey
                  </button>
                  <p className="text-xs text-textc-muted">
                    In production this triggers the WebAuthn ceremony via{' '}
                    <span className="mono">navigator.credentials.get()</span>.
                  </p>
                </div>
              )}

              {method === 'totp' && (
                <div className="space-y-4">
                  <div>
                    <label className="mono text-xs uppercase tracking-wider text-textc-muted">Work email</label>
                    <input
                      data-testid="totp-email"
                      type="email"
                      className="input-field mt-1.5"
                      placeholder="you@company.com"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                    />
                  </div>
                  <div>
                    <label className="mono text-xs uppercase tracking-wider text-textc-muted">6-digit code</label>
                    <input
                      data-testid="totp-code"
                      className="input-field mt-1.5 mono tracking-[0.4em] text-center text-xl"
                      placeholder="000000"
                      maxLength={6}
                      value={code}
                      onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                    />
                  </div>
                  <button
                    data-testid="totp-submit"
                    disabled={loading || code.length !== 6 || !email}
                    onClick={doTotp}
                    className="btn-primary w-full"
                  >
                    Verify code
                  </button>
                </div>
              )}
            </div>

            <div className="mt-5 text-center text-xs text-textc-muted">
              By signing in you accept the Acceptable Use & Data Processing terms.
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
