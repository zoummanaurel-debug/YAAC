import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

/**
 * Actualités bilingues.
 * Les fichiers vivent dans src/content/news/<lang>/<slug>.md — le slug est
 * volontairement IDENTIQUE dans les deux langues, ce qui permet au sélecteur
 * de langue de basculer d'un article vers sa traduction sans table de
 * correspondance.
 */
const news = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/news' }),
  schema: ({ image }) =>
    z.object({
      title: z.string(),
      description: z.string(),
      /** Date de l'événement quand elle est documentée dans l'article,
       *  sinon date de publication relevée sur yaac.network. */
      date: z.coerce.date(),
      category: z.enum(['events', 'informations', 'public-action', 'yaac-talk']),
      cover: image(),
      coverAlt: z.string(),
      featured: z.boolean().default(false),
    }),
});

export const collections = { news };
