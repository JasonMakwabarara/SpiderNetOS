import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Menu, X } from 'lucide-react';
import { Logo } from './Atoms';

const NAV = [
  { label: 'Platform', href: '/#platform' },
  { label: 'Solutions', href: '/#solutions' },
  { label: 'Security', href: '/#security' },
  { label: 'Integrations', href: '/#integrations' },
  { label: 'Developers', href: '/#developers' },
  { label: 'Customers', href: '/#customers' },
  { label: 'Trust', href: '/trust' },
  { label: 'Pricing', href: '/#pricing' },
];

export default function MarketingHeader() {
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    window.addEventListener('scroll', onScroll);
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <header
      data-testid="marketing-header"
      className={`fixed top-0 inset-x-0 z-50 transition-all duration-300 ${
        scrolled
          ? 'bg-bg-base/80 backdrop-blur-xl border-b border-white/[0.06]'
          : 'bg-transparent'
      }`}
    >
      <div className="container-x flex h-16 items-center justify-between">
        <Link to="/" data-testid="header-logo">
          <Logo />
        </Link>
        <nav className="hidden lg:flex items-center gap-1" aria-label="Primary">
          {NAV.map((n) => (
            <a
              key={n.label}
              href={n.href}
              data-testid={`nav-${n.label.toLowerCase()}`}
              className="px-3 py-2 text-sm text-textc-secondary hover:text-textc-primary transition-colors rounded-md"
            >
              {n.label}
            </a>
          ))}
        </nav>
        <div className="hidden lg:flex items-center gap-2">
          <Link
            to="/sign-in"
            data-testid="header-signin"
            className="px-4 py-2 text-sm text-textc-secondary hover:text-textc-primary transition-colors"
          >
            Sign in
          </Link>
          <Link
            to="/enterprise/register"
            data-testid="header-register"
            className="btn-primary text-sm py-2 px-4"
          >
            Get started free
          </Link>
        </div>
        <button
          aria-label={open ? 'Close menu' : 'Open menu'}
          aria-expanded={open}
          data-testid="header-mobile-toggle"
          onClick={() => setOpen((v) => !v)}
          className="lg:hidden p-2 -mr-2 text-textc-primary"
        >
          {open ? <X size={22} /> : <Menu size={22} />}
        </button>
      </div>
      {open && (
        <div className="lg:hidden border-t border-white/[0.06] bg-bg-s1/95 backdrop-blur-xl">
          <div className="container-x py-4 flex flex-col gap-1">
            {NAV.map((n) => (
              <a
                key={n.label}
                href={n.href}
                onClick={() => setOpen(false)}
                className="px-3 py-3 text-textc-secondary hover:text-textc-primary border-b border-white/5"
              >
                {n.label}
              </a>
            ))}
            <Link
              to="/sign-in"
              onClick={() => setOpen(false)}
              className="px-3 py-3 text-textc-secondary"
            >
              Sign in
            </Link>
            <Link
              to="/enterprise/register"
              onClick={() => setOpen(false)}
              className="btn-primary mt-2 w-full"
            >
              Get started free
            </Link>
          </div>
        </div>
      )}
    </header>
  );
}
