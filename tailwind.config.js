import defaultTheme from 'tailwindcss/defaultTheme'
import forms from '@tailwindcss/forms'
import flowbite from 'flowbite/plugin'

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',

  content: [
    './resources/views/**/*.blade.php',
    './app/View/Components/**/*.php',
    './resources/js/**/*.js',

    // ✅ Flowbite
    './node_modules/flowbite/**/*.js',
  ],

  theme: {
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
      },
      colors: {
        aio: {
          bg: '#0b0f1a',
          card: '#111827',
          ink: '#e5e7eb',
          sub: '#9ca3af',
          neon: '#3DFF7F',
          mag: '#FF2FB9',
          pup: '#7C4DFF',
          cya: '#3BA7F0',
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

  plugins: [
    forms,
    flowbite, // ✅ Flowbite plugin
  ],
}
