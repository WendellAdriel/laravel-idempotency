import { defineConfig } from 'vitepress'

const configuredBase = process.env.VITEPRESS_BASE ?? '/'
const base = configuredBase.endsWith('/') ? configuredBase : `${configuredBase}/`

export default defineConfig({
  title: 'Laravel Idempotency',
  description: 'HTTP idempotency middleware for Laravel applications',
  base,
  head: [
    ['link', { rel: 'preconnect', href: 'https://fonts.googleapis.com' }],
    ['link', { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' }],
    ['link', { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Cascadia+Code:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Laravel Idempotency' }],
    ['meta', { property: 'og:description', content: 'HTTP idempotency middleware for Laravel applications' }],
    ['meta', { property: 'og:image', content: 'https://laravel-idempotency.wendelladriel.com/banner.png' }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['meta', { name: 'twitter:title', content: 'Laravel Idempotency' }],
    ['meta', { name: 'twitter:description', content: 'HTTP idempotency middleware for Laravel applications' }],
    ['meta', { name: 'twitter:image', content: 'https://laravel-idempotency.wendelladriel.com/banner.png' }],
  ],
  markdown: {
    theme: {
      light: 'catppuccin-latte',
      dark: 'catppuccin-mocha',
    },
  },
  themeConfig: {
    sidebar: [
      { text: 'Overview', link: '/' },
      { text: 'Installation', link: '/installation' },
      { text: 'Configuration', link: '/configuration' },
      { text: 'Usage', link: '/usage' },
      { text: 'Scopes', link: '/scopes' },
      { text: 'Generating Keys', link: '/generating-keys' },
      { text: 'Maintenance Commands', link: '/maintenance-commands' },
      { text: 'Changelog', link: '/changelog' },
    ],
    search: {
      provider: 'local',
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/wendelladriel/laravel-idempotency' },
    ],
    editLink: {
      pattern: 'https://github.com/wendelladriel/laravel-idempotency/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
  },
})
