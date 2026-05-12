import React from 'react';

const SignInPage: React.FC = () => {
  return (
    <div className="min-h-screen bg-gray-900 text-white flex items-center justify-center">
      <div className="bg-gray-800 p-8 rounded">
        <h2 className="text-2xl mb-4">Sign In</h2>
        <form>
          <input type="email" placeholder="Email" className="w-full p-2 mb-4 bg-gray-700" />
          <input type="password" placeholder="Password" className="w-full p-2 mb-4 bg-gray-700" />
          <button type="submit" className="w-full bg-orange-500 p-2 rounded">Sign In</button>
        </form>
      </div>
    </div>
  );
};

export default SignInPage;