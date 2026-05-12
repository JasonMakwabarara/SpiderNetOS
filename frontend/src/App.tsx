import React from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import LandingPage from './pages/LandingPage';
import SignInPage from './pages/SignInPage';
import RegisterWizard from './pages/RegisterWizard';
import './App.css';

function App() {
  return (
    <Router>
      <div className="App">
        <Routes>
          <Route path="/" element={<LandingPage />} />
          <Route path="/sign-in" element={<SignInPage />} />
          <Route path="/enterprise/register" element={<RegisterWizard />} />
          <Route path="/cockpit/*" element={<div>Loading Cockpit...</div>} />
        </Routes>
      </div>
    </Router>
  );
}

export default App;