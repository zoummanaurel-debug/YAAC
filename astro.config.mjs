// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Le dépôt est publié sur GitHub Pages sous https://<user>.github.io/YAAC/
// `base` est donc obligatoire : sans lui tous les liens et assets pointent à la racine du domaine.
export default defineConfig({
  site: 'https://zoummanaurel-debug.github.io',
  base: '/YAAC',
  trailingSlash: 'always',
  integrations: [sitemap()],
  i18n: {
    locales: ['fr', 'en'],
    defaultLocale: 'fr',
    routing: { prefixDefaultLocale: true },
  },
});
