/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}', './public/index.html'],
  theme: {
    extend: {
      colors: {
        bg: {
          base: '#070A12',
          s1: '#0E1422',
          s2: '#121A2B',
          s3: '#172033',
        },
        accent: {
          orange: '#FF6B2C',
          cyan: '#00D6C9',
        },
        feedback: {
          success: '#31D67B',
          warning: '#F5B84B',
          danger: '#F05D5E',
        },
        textc: {
          primary: '#F4F7FB',
          secondary: '#A8B3C7',
          muted: '#6B7693',
        },
      },
      fontFamily: {
        sans: ['Geist Sans', 'Satoshi', '-apple-system', 'system-ui', 'sans-serif'],
        mono: ['Geist Mono', 'JetBrains Mono', 'ui-monospace', 'monospace'],
      },
      backgroundImage: {
        'grid-faint':
          'linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px)',
        'orange-cyan': 'linear-gradient(90deg, #FF6B2C 0%, #00D6C9 100%)',
      },
      backgroundSize: {
        'grid-32': '32px 32px',
      },
      boxShadow: {
        'glow-orange': '0 0 40px -10px rgba(255,107,44,0.45)',
        'glow-cyan': '0 0 40px -10px rgba(0,214,201,0.45)',
        'inner-hl': 'inset 0 1px 0 rgba(255,255,255,0.08)',
      },
      animation: {
        'fade-up': 'fadeUp 0.6s ease-out both',
        'pulse-soft': 'pulseSoft 2.4s ease-in-out infinite',
      },
      keyframes: {
        fadeUp: {
          '0%': { opacity: '0', transform: 'translateY(12px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        pulseSoft: {
          '0%, 100%': { opacity: '0.5' },
          '50%': { opacity: '1' },
        },
      },
    },
  },
  plugins: [],
};
