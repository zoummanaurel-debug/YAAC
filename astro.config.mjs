// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Deux cibles de déploiement, une seule build.
//
//   npm run build                      → GitHub Pages, sous /YAAC/
//   YAAC_CIBLE=production npm run build → yaac.network, à la racine
//
// Piloter ça par variable d'environnement plutôt qu'en éditant ce fichier
// évite l'accident classique : publier sur GitHub Pages une build calée sur
// la racine, ou l'inverse — dans les deux cas tous les liens et les assets
// cassent, et `/api/verify.php` n'est plus appelé au bon endroit.
const production = process.env.YAAC_CIBLE === 'production';

export default defineConfig({
  site: production ? 'https://yaac.network' : 'https://zoummanaurel-debug.github.io',
  base: production ? '/' : '/YAAC',
  trailingSlash: 'always',
  // `/verify/` est une page utilitaire en `noindex`, atteinte par le QR code
  // d'une carte physique. La lister dans le sitemap enverrait à Google le
  // signal inverse de sa balise robots.
  integrations: [
    sitemap({
      filter: (page) =>
        !page.includes('/verify') &&
        !page.includes('/404') &&
        !page.includes('/badge') &&
        !page.includes('/adhesion-confirmee') &&
        !page.includes('/membership-confirmed'),
    }),
  ],
  i18n: {
    locales: ['fr', 'en'],
    defaultLocale: 'fr',
    routing: { prefixDefaultLocale: true },
  },
});
