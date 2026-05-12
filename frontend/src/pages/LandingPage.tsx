import React from 'react';

const LandingPage: React.FC = () => {
  return (
    <div className="min-h-screen bg-gray-900 text-white">
      <header className="p-4">
        <nav className="flex justify-between items-center">
          <div className="text-2xl font-bold">SpiderNetOS</div>
          <div className="space-x-4">
            <a href="/sign-in" className="text-white">Sign In</a>
            <a href="/enterprise/register" className="bg-orange-500 px-4 py-2 rounded">Register Enterprise</a>
          </div>
        </nav>
      </header>
      <main className="text-center py-20">
        <h1 className="text-5xl font-bold mb-4">The Enterprise AI Operating System</h1>
        <p className="text-xl mb-8">Govern AI operations across your enterprise with secure, scalable automation.</p>
        <a href="/enterprise/register" className="bg-orange-500 px-8 py-4 rounded text-lg">Get Started</a>
      </main>
    </div>
  );
};

export default LandingPage;