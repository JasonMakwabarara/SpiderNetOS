/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // ── SpiderNetOS Flight-Deck palette ─────────────────────────────
        // Bases (near-black, graphite, deep navy)
        ink: {
          950: '#05070A', // page bg
          900: '#0A0D12', // base surface
          800: '#0F141C', // card surface
          700: '#151B26', // elevated
          600: '#1D2532', // hover / inset
          500: '#2A3342', // border-active
          400: '#3B4658', // muted line
        },
        // Cyan accent (single source of truth)
        cyan: {
          50:  '#E6FEFB',
          100: '#B8FBF2',
          200: '#7DF5E6',
          300: '#3FECD6',
          400: '#17DFC3',
          500: '#00E5C8', // PRIMARY accent
          600: '#03B8A1',
          700: '#087D6E',
          800: '#0A4B42',
          900: '#08261F',
        },
        // Subtle secondary tone for warnings / amber highlights
        amber: {
          400: '#FFC857',
          500: '#F5A524',
        },
        // Text ink
        fg: {
          primary:   '#ECF2F5',
          secondary: '#96A1B2',
          muted:     '#5E6A7D',
          inverted:  '#05070A',
        },
        // Override Tailwind's gray scale to map to dark surfaces so
        // legacy screens using bg-gray-* stay dark without rewrites.
        gray: {
          50:  '#0F141C',
          100: '#151B26',
          200: '#1D2532',
          300: '#2A3342',
          400: '#3B4658',
          500: '#5E6A7D',
          600: '#96A1B2',
          700: '#B4BDCC',
          800: '#D3DAE3',
          900: '#ECF2F5',
        },
        // Legacy-shim: indigo → cyan accent ramp (so bg-indigo-*/text-indigo-* stay on-brand)
        indigo: {
          50:  'rgba(0,229,200,0.08)',
          100: 'rgba(0,229,200,0.14)',
          200: 'rgba(0,229,200,0.22)',
          300: '#3FECD6',
          400: '#17DFC3',
          500: '#00E5C8',
          600: '#00E5C8',
          700: '#03B8A1',
          800: '#087D6E',
          900: '#0A4B42',
        },
        // Status ramps → our semantic palette
        red: {
          50:  'rgba(255,90,122,0.08)',
          100: 'rgba(255,90,122,0.14)',
          200: 'rgba(255,90,122,0.22)',
          400: '#FF7D96',
          500: '#FF5A7A',
          600: '#FF5A7A',
          700: '#C44660',
          800: '#A03A50',
        },
        green: {
          50:  'rgba(34,211,155,0.08)',
          100: 'rgba(34,211,155,0.14)',
          200: 'rgba(34,211,155,0.22)',
          400: '#4ADFB4',
          500: '#22D39B',
          600: '#22D39B',
          700: '#17A67A',
          800: '#12825F',
        },
        yellow: {
          50:  'rgba(245,165,36,0.08)',
          100: 'rgba(245,165,36,0.14)',
          200: 'rgba(245,165,36,0.22)',
          400: '#FFC857',
          500: '#F5A524',
          600: '#F5A524',
          700: '#C8851C',
          800: '#9B6814',
        },
        blue: {
          50:  'rgba(0,229,200,0.06)',
          100: 'rgba(0,229,200,0.10)',
          400: '#17DFC3',
          500: '#00E5C8',
          600: '#03B8A1',
          700: '#087D6E',
        },
        purple: {
          50:  'rgba(255,255,255,0.04)',
          100: 'rgba(255,255,255,0.08)',
          500: '#5E6A7D',
          600: '#3B4658',
        },
        // Status colors
        success: '#22D39B',
        warn:    '#F5A524',
        danger:  '#FF5A7A',
      },
      fontFamily: {
        // Modern grotesk (Geist via Google) + JetBrains Mono
        sans:    ['"Geist"', '"Inter"', 'system-ui', 'sans-serif'],
        heading: ['"Geist"', '"Inter"', 'system-ui', 'sans-serif'],
        body:    ['"Geist"', '"Inter"', 'system-ui', 'sans-serif'],
        mono:    ['"JetBrains Mono"', '"IBM Plex Mono"', 'monospace'],
      },
      boxShadow: {
        // Cool-tinted flight-deck shadows
        panel:      '0 1px 0 rgba(255,255,255,0.04) inset, 0 12px 40px rgba(0,0,0,0.45)',
        'panel-hi': '0 1px 0 rgba(255,255,255,0.06) inset, 0 24px 60px rgba(0,0,0,0.55)',
        glow:       '0 0 0 1px rgba(0,229,200,0.35), 0 0 24px -4px rgba(0,229,200,0.45)',
      },
      borderRadius: {
        xs: '3px',
        sm: '5px',
        md: '8px',
        lg: '12px',
        xl: '16px',
      },
      spacing: {
        '4.5': '1.125rem',
        '18': '4.5rem',
      },
      backgroundImage: {
        // Kept gradient names (used in legacy views) but recolored to flight-deck
        'gradient-brand': 'linear-gradient(135deg, #00E5C8 0%, #03B8A1 100%)',
        'gradient-hero':  'radial-gradient(1200px 600px at 20% -10%, rgba(0,229,200,0.12), transparent 60%), radial-gradient(800px 400px at 100% 100%, rgba(0,229,200,0.08), transparent 55%), linear-gradient(180deg, #05070A, #0A0D12)',
        'grid-subtle': 'linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px)',
      },
      backgroundSize: {
        grid: '32px 32px',
      },
    },
  },
  plugins: [],
}
