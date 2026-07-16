import React from 'react';

// Reusable atoms

export const Logo = ({ className = '' }) => (
  <div className={`flex items-center gap-2.5 ${className}`}>
    <svg width="28" height="28" viewBox="0 0 32 32" fill="none" aria-hidden>
      <defs>
        <linearGradient id="lg1" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#FF6B2C" />
          <stop offset="1" stopColor="#00D6C9" />
        </linearGradient>
      </defs>
      <circle cx="16" cy="16" r="3" fill="url(#lg1)" />
      <g stroke="url(#lg1)" strokeWidth="1.4" strokeLinecap="round" opacity="0.9">
        <line x1="16" y1="16" x2="6" y2="6" />
        <line x1="16" y1="16" x2="26" y2="6" />
        <line x1="16" y1="16" x2="6" y2="26" />
        <line x1="16" y1="16" x2="26" y2="26" />
        <line x1="16" y1="16" x2="16" y2="2" />
        <line x1="16" y1="16" x2="16" y2="30" />
        <line x1="16" y1="16" x2="2" y2="16" />
        <line x1="16" y1="16" x2="30" y2="16" />
      </g>
      <g fill="#F4F7FB">
        <circle cx="6" cy="6" r="1.4" />
        <circle cx="26" cy="6" r="1.4" />
        <circle cx="6" cy="26" r="1.4" />
        <circle cx="26" cy="26" r="1.4" />
        <circle cx="16" cy="2" r="1.2" />
        <circle cx="16" cy="30" r="1.2" />
        <circle cx="2" cy="16" r="1.2" />
        <circle cx="30" cy="16" r="1.2" />
      </g>
    </svg>
    <span className="text-textc-primary font-semibold tracking-tight">
      Spider<span className="text-accent-orange">Net</span>OS
    </span>
  </div>
);

export const Pill = ({ children, tone = 'cyan', icon = null, className = '' }) => {
  const tones = {
    cyan: 'text-accent-cyan border-accent-cyan/30 bg-accent-cyan/[0.06]',
    orange: 'text-accent-orange border-accent-orange/30 bg-accent-orange/[0.06]',
    success: 'text-feedback-success border-feedback-success/30 bg-feedback-success/[0.06]',
    warning: 'text-feedback-warning border-feedback-warning/30 bg-feedback-warning/[0.06]',
    danger: 'text-feedback-danger border-feedback-danger/30 bg-feedback-danger/[0.06]',
    neutral: 'text-textc-secondary border-white/10 bg-bg-s2/60',
  };
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs mono uppercase tracking-wider ${tones[tone] || tones.cyan} ${className}`}
    >
      {icon}
      {children}
    </span>
  );
};

export const SectionEyebrow = ({ children }) => (
  <div className="section-eyebrow flex items-center gap-3">
    <span className="h-px w-8 bg-accent-cyan/40" />
    {children}
  </div>
);
