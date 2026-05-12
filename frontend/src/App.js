import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LandingPage from './pages/LandingPage';
import SignInPage from './pages/SignInPage';
import RegisterWizard from './pages/RegisterWizard';
import TrustCenter from './pages/TrustCenter';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="/trust" element={<TrustCenter />} />
        <Route path="/sign-in" element={<SignInPage />} />
        <Route path="/enterprise/register" element={<RegisterWizard />} />
        {/*
          /cockpit/* is served as STATIC assets by CRA from /public/cockpit/.
          The built Vue cockpit (hash-router, base /cockpit/) lives there.
          We never want React Router to claim that path, so we render a tiny
          gate that hard-redirects to the static index. Browser will then
          load the Vue app, and the hash router takes over.
        */}
        <Route path="/cockpit/*" element={<CockpitRedirect />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

function CockpitRedirect() {
  React.useEffect(() => {
    // Replace so the back button doesn't ping-pong.
    const dest = '/cockpit/' + (window.location.hash || '#/');
    window.location.replace(dest);
  }, []);
  return (
    <div style={{ minHeight: '100vh', background: '#070A12', color: '#A8B3C7',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontFamily: 'Geist Sans, system-ui, sans-serif' }}>
      Opening Cockpit…
    </div>
  );
}
