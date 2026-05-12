import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LandingPage from './pages/LandingPage';
import SignInPage from './pages/SignInPage';
import RegisterWizard from './pages/RegisterWizard';
import TrustCenter from './pages/TrustCenter';
import CockpitLayout from './cockpit/CockpitLayout';
import Overview from './cockpit/Overview';
import Tenants from './cockpit/Tenants';
import AccessControl from './cockpit/AccessControl';
import Connectors from './cockpit/Connectors';
import AiosDownloads from './cockpit/AiosDownloads';
import Audit from './cockpit/Audit';
import Anomaly from './cockpit/Anomaly';
import DeveloperPortal from './cockpit/DeveloperPortal';
import Security from './cockpit/Security';
import Support from './cockpit/Support';
import { auth } from './lib/api';

function RequireAuth({ children }) {
  if (!auth.isAuthed()) return <Navigate to="/sign-in" replace />;
  return children;
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="/trust" element={<TrustCenter />} />
        <Route path="/sign-in" element={<SignInPage />} />
        <Route path="/enterprise/register" element={<RegisterWizard />} />
        <Route
          path="/cockpit"
          element={
            <RequireAuth>
              <CockpitLayout />
            </RequireAuth>
          }
        >
          <Route index element={<Overview />} />
          <Route path="tenants" element={<Tenants />} />
          <Route path="access" element={<AccessControl />} />
          <Route path="connectors" element={<Connectors />} />
          <Route path="downloads" element={<AiosDownloads />} />
          <Route path="audit" element={<Audit />} />
          <Route path="anomaly" element={<Anomaly />} />
          <Route path="developers" element={<DeveloperPortal />} />
          <Route path="security" element={<Security />} />
          <Route path="support" element={<Support />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
