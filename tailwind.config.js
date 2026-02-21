/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './*.php',
    './account/**/*.php',
    './admin_portal/**/*.php',
    './api/**/*.php',
    './auth/**/*.php',
    './includes/**/*.php',
    './mother_portal/**/*.php',
    './nurse_portal/**/*.php',
    './reports/**/*.php',
    './sql/**/*.sql',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
