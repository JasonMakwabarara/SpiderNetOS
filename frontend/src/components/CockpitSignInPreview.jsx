import React from 'react';
import { Link } from 'react-router-dom';
import { KeyRound, Mail, ShieldCheck, Fingerprint, Smartphone, Lock } from 'lucide-react';
import { Pill } from './Atoms';

const METHODS = [
  { id: 'password', label: 'Email & password', icon: Lock, sub: 'Platform admin' },
  { id: 'sso', label: 'Enterprise SSO', icon: KeyRound, sub: 'OIDC / SAML' },
  { id: 'magic', label: 'Magic link', icon: Mail, sub: 'Email passwordless' },
  { id: 'webauthn', label: 'Passkey', icon: Fingerprint, sub: 'WebAuthn / U2F' },
  { id: 'totp', label: 'TOTP code', icon: Smartphone, sub: 'Authenticator app' },
];

/** Static hero inset — mirrors /sign-in without interactive auth. */
export default function CockpitSignInPreview() {
  return (
    <div
      className="glass p-6 md:p-7 relative overflow-hidden"
      data-testid="hero-cockpit-preview"
      aria-hidden="true"
    >
      <div className="absolute -top-20 -right-20 w-56 h-56 rounded-full pointer-events-none opacity-40"
        style={{ background: 'radial-gradient(circle, rgba(255,107,44,0.35), transparent 70%)' }}
      />
      <Pill tone="cyan" icon={<ShieldCheck size={12} />}>Cockpit sign-in</Pill>
      <h3 className="mt-4 text-xl tracking-tight font-medium">
        Welcome back to <span className="text-accent-orange">SpiderNet</span>OS
      </h3>
      <p className="mt-2 text-sm text-textc-secondary leading-relaxed">
        The operator console for your autonomous business. SpiderNetOS runs thousands of
        decisions a day — agents, flows, governance, approvals — and hands you a 5-minute
        weekly review. This is the cockpit.
      </p>
      <div className="mt-5 grid grid-cols-2 gap-2">
        {METHODS.map((m, i) => (
          <div
            key={m.id}
            className={`text-left p-3 rounded-xl border ${
              i === 0
                ? 'border-accent-cyan/60 bg-accent-cyan/[0.08]'
                : 'border-white/10 bg-bg-s1/50'
            }`}
          >
            <m.icon size={15} className={i === 0 ? 'text-accent-cyan' : 'text-textc-secondary'} />
            <div className="mt-2 text-xs font-medium">{m.label}</div>
            <div className="mono text-[9px] uppercase tracking-wider text-textc-muted mt-0.5">
              {m.sub}
            </div>
          </div>
        ))}
      </div>
      <Link
        to="/sign-in"
        className="mt-6 btn-primary w-full text-sm"
        data-testid="hero-preview-signin"
      >
        Sign in to Cockpit
      </Link>
    </div>
  );
}
