import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class', // we'll toggle with a class
  content: [
    './resources/views/**/*.blade.php',
    './app/View/Components/**/*.php',
    './resources/js/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
      },
      colors: {
        // AIO brand — tweak the hexes if you prefer
        aio: {
          bg:   '#0b0f1a',   // page background (almost black-blue)
          card: '#111827',  // surface (dark slate)
          ink:  '#e5e7eb',  // primary text (gray-200)
          sub:  '#9ca3af',  // secondary text (gray-400)
          neon: '#3DFF7F',  // neon green (eyes)
          mag:  '#FF2FB9',  // hot magenta
          pup:  '#7C4DFF',  // electric purple
          cya:  '#3BA7F0',  // cyan/blue
        },
      },
      boxShadow: {
        glow: '0 0 0.5rem rgba(61,255,127,.35), 0 0 1.25rem rgba(124,77,255,.25)',
      },
      backgroundImage: {
        'aio-gradient': 'linear-gradient(135deg, rgba(124,77,255,.25), rgba(255,47,185,.25))',
      },
    },
  },
  plugins: [forms],
};