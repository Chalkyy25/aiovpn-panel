// tailwind.config.js
import defaultTheme from 'tailwindcss/defaultTheme'
import forms from '@tailwindcss/forms'

export default {
  darkMode: 'class',
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
  ],
  safelist: [
    // SVG fills/strokes we use on the coin
    'fill-yellow-400',
    'fill-yellow-300',
    'stroke-gray-900',
    'dark:fill-yellow-500',
    'dark:fill-yellow-400',
    'dark:stroke-gray-900',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
      },
    },
  },
  plugins: [forms],
}