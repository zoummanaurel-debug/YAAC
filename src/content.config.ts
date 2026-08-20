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
      /** Date de publication relevée sur yaac.network via l'API REST
       *  WordPress (champ `date`), pour rester fidèle au site d'origine. */
      date: z.coerce.date(),
      category: z.enum(['events', 'informations', 'public-action', 'yaac-talk']),
      cover: image(),
      coverAlt: z.string(),
      featured: z.boolean().default(false),
      /** Identifiant YouTube de la vidéo associée à l'article sur le site
       *  d'origine, quand il y en a une. */
      video: z.string().optional(),
    }),
});

export const collections = { news };
