/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  darkMode: 'class',
  theme: {
    extend: {
      /* ─── Dusk-Charge-Tealime (DCT) Palette ─────────────── */
      colors: {
        /* Brand triad */
        dusk: {
          DEFAULT: '#FFCCF3',  /* Soft pastel pink */
          dark: '#4D003D',     /* Deep aubergine */
          vivid: '#FF6EB4',    /* Charge pink — primary CTA */
        },
        charge: {
          DEFAULT: '#ADFF53',  /* Bright lime green */
          dark: '#1A3300',     /* Dark olive */
          vivid: '#39FF14',    /* Neon lime — accent */
        },
        tealime: {
          DEFAULT: '#2DD4BF',  /* Teal mint */
          dark: '#0D3D35',     /* Dark teal */
          vivid: '#00E5C8',    /* Vivid cyan — secondary */
        },

        /* Surface system (light mode / warm white) */
        surface: {
          DEFAULT: '#FFFAFC',  /* Warm white — page bg */
          subtle: '#FDF5FC',   /* Pink-tint subtle bg */
          card: '#FFFFFF',     /* Card white */
          low: '#FFF7FB',      /* Low surface (pink tint) */
          high: '#F3FFF9',     /* High surface (green tint) */
          cream: '#FEFBFF',    /* Cream bg */
          900: '#0C0A14',      /* Deepest dark surface */
          800: '#140F20',      /* Dark surface */
          700: '#1C1630',      /* Medium dark surface */
          600: '#251E3A',      /* Lighter dark surface */
        },

        /* Ink / text */
        ink: {
          DEFAULT: '#1A0A1E',  /* Deep aubergine — primary text */
          muted: '#6B4D70',    /* Muted plum — secondary text */
          soft: '#9A7FA0',     /* Softer plum — tertiary text */
        },

        /* Semantic */
        primary: {
          DEFAULT: '#FF6EB4',       /* Charge pink */
          foreground: '#1A0A1E',    /* Deep aubergine on pink */
        },
        secondary: {
          DEFAULT: '#00E5C8',       /* Vivid cyan */
          foreground: '#1A0A1E',
        },
        accent: {
          DEFAULT: '#39FF14',       /* Neon lime */
          foreground: '#1A0A1E',
        },
      },

      /* ─── Typography ────────────────────────────────────── */
      fontFamily: {
        heading: ['"Plus Jakarta Sans"', '"Golos Text"', 'sans-serif'],
        body: ['"Manrope"', '"Inter"', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace'],
      },

      /* ─── Shadows (DCT plum-tinted) ─────────────────────── */
      boxShadow: {
        'dct': '0 24px 70px rgba(77, 0, 61, 0.08)',
        'dct-strong': '0 34px 84px rgba(77, 0, 61, 0.12)',
        'dct-sm': '0 4px 20px rgba(77, 0, 61, 0.05)',
      },

      /* ─── Border colors ─────────────────────────────────── */
      borderColor: {
        'dct': 'rgba(26, 10, 30, 0.08)',
        'dct-active': 'rgba(26, 10, 30, 0.18)',
      },

      /* ─── Gradient background utilities ─────────────────── */
      backgroundImage: {
        'gradient-brand': 'linear-gradient(135deg, #FF6EB4, #39FF14, #00E5C8)',
        'gradient-dusk-charge': 'linear-gradient(135deg, #FFCCF3, #ADFF53)',
        'gradient-lime-teal': 'linear-gradient(135deg, #39FF14, #00E5C8)',
        'gradient-hero': 'linear-gradient(180deg, #FEFBFF 0%, #FFF5FC 30%, #F5FFEE 60%, #F0FFFC 100%)',
        'gradient-simulation': 'linear-gradient(135deg, #1A0A1E 0%, #281028 45%, #0D3D35 100%)',
      },
    },
  },
  plugins: [],
}
