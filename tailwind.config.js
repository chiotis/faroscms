/** Admin UI build. Run `npm run build:css` after changing classes in admin templates or admin.js. */
module.exports = {
  content: [
    './admin/templates/**/*.twig',
    './public/assets/js/admin.js',
    './public/assets/js/admin-blocks.js',
    './src/**/*.php',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Inter Tight"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  // Toggled from inline scripts in base.twig (tab state), so keep them even if unused in markup.
  safelist: [
    'hidden',
    'block',
    'bg-slate-200',
    'bg-white',
    'border-slate-400',
    'border-slate-300',
    'text-slate-900',
    'text-slate-600',
  ],
};
