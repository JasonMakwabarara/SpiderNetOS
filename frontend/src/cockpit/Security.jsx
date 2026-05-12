import React from 'react';
import { Header } from './Tenants';
import { Pill } from '../components/Atoms';
import { Lock, KeyRound, ShieldCheck, FileSignature } from 'lucide-react';

export default function Security() {
  return (
    <div data-testid="cockpit-security">
      <Header title="Security & policy" subtitle="Tune identity, MFA, residency, encryption, and key management." />
      <div className="grid md:grid-cols-2 gap-5">
        <Section title="Identity providers" icon={KeyRound}>
          <Item label="OIDC — Demo IdP" pill="active" tone="success" />
          <Item label="SAML 2.0 — Generic" pill="not configured" tone="warning" />
          <Item label="WebAuthn passkeys" pill="enforced" tone="success" />
          <Item label="TOTP fallback" pill="allowed" tone="cyan" />
        </Section>
        <Section title="Encryption & keys" icon={Lock}>
          <Item label="TLS 1.3 in transit" pill="enforced" tone="success" />
          <Item label="AES-256-GCM at rest" pill="enforced" tone="success" />
          <Item label="Customer-managed keys (BYOK)" pill="available — enterprise" tone="cyan" />
          <Item label="Key rotation policy" pill="90 days" tone="cyan" />
        </Section>
        <Section title="Data residency" icon={ShieldCheck}>
          <Item label="Region pinning" pill="us-east-1" tone="cyan" />
          <Item label="Cross-region pipeline policy" pill="blocked" tone="success" />
          <Item label="Connector residency tags" pill="enforced" tone="success" />
        </Section>
        <Section title="Bundle integrity" icon={FileSignature}>
          <Item label="Bundle signing" pill="Ed25519" tone="cyan" />
          <Item label="Checksum verification" pill="SHA-256" tone="cyan" />
          <Item label="Refuse-on-mismatch" pill="enabled" tone="success" />
        </Section>
      </div>
    </div>
  );
}

function Section({ title, icon: Icon, children }) {
  return (
    <div className="glass p-6">
      <h2 className="font-medium flex items-center gap-2 mb-4">
        <Icon size={16} className="text-accent-cyan" />
        {title}
      </h2>
      <ul className="space-y-2.5">{children}</ul>
    </div>
  );
}

function Item({ label, pill, tone }) {
  return (
    <li className="flex items-center justify-between border-b border-white/[0.04] last:border-0 pb-2.5 last:pb-0">
      <span className="text-sm text-textc-secondary">{label}</span>
      <Pill tone={tone}>{pill}</Pill>
    </li>
  );
}
