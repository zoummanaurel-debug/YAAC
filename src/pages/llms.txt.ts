import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import { registrations, contactInfo, socialLinks } from '../i18n/utils';

/**
 * `/llms.txt` — résumé du site à l'intention des assistants et moteurs
 * conversationnels (convention llmstxt.org).
 *
 * Généré depuis la collection d'articles plutôt qu'écrit à la main : l'ancien
 * fichier, produit par une extension WordPress, listait encore les URL de
 * 2025 et ignorait le bilinguisme, le Contact et l'adhésion. Un résumé rédigé
 * une fois se périme à la première publication ; celui-ci se reconstruit à
 * chaque build.
 *
 * Rien ici n'est inventé : chiffres, références légales et articles viennent
 * du contenu réel du dépôt.
 */
export const GET: APIRoute = async () => {
  const site = (import.meta.env.SITE ?? 'https://yaac.network').replace(/\/+$/, '');
  const base = import.meta.env.BASE_URL.replace(/\/+$/, '');
  const url = (chemin: string) => `${site}${base}/${chemin.replace(/^\/+/, '')}`;

  const articles = await getCollection('news');
  const parDate = (a: typeof articles[number], b: typeof articles[number]) =>
    b.data.date.getTime() - a.data.date.getTime();

  const ligne = (lang: 'fr' | 'en') =>
    articles
      .filter((a) => a.id.startsWith(`${lang}/`))
      .sort(parDate)
      .map((a) => {
        const slug = a.id.split('/').pop()!;
        const chemin = lang === 'fr' ? `fr/actualites/${slug}/` : `en/news/${slug}/`;
        return `- [${a.data.title}](${url(chemin)}) : ${a.data.description}`;
      })
      .join('\n');

  const legal = registrations
    .map((r) => `- ${r.country.fr} : ${r.reference} — ${r.taxLabel} ${r.taxId}`)
    .join('\n');

  const reseaux = socialLinks.map((r) => `- ${r.name} : ${r.url}`).join('\n');

  const texte = `# YAAC — Youth Alliance for Agroecology and Climate

> Alliance panafricaine portée par des jeunes, qui fait avancer l'agroécologie
> et l'action climatique en Afrique par des projets communautaires, la
> formation et le plaidoyer. Enregistrée au Bénin et au Sénégal.
> Site bilingue français / anglais, le français étant la langue par défaut.

YAAC est portée par les jeunes eux-mêmes, et non par une organisation qui
agirait pour eux. L'agroécologie y est traitée autant comme un levier de
revenus dignes que comme un outil de conservation.

Signature : « La durabilité commence ici et maintenant ! » /
« Sustainability starts here and now! »

Chiffres au 21 août 2026 : 34 membres, 3 projets, 6 partenaires.

## Enregistrement légal

${legal}

## Pages principales — français

- [Accueil](${url('fr/')})
- [Qui sommes-nous](${url('fr/qui-sommes-nous/')}) : mission, vision, valeurs, gouvernance
- [Actualités](${url('fr/actualites/')})
- [Partenaires & Donateurs](${url('fr/partenaires/')})
- [Devenir membre](${url('fr/devenir-membre/')}) : adhésion à 6 000 FCFA, valable 5 ans
- [Contact](${url('fr/contact/')})

## Main pages — English

- [Home](${url('en/')})
- [Who we are](${url('en/who-we-are/')})
- [News](${url('en/news/')})
- [Partners & Donors](${url('en/partners/')})
- [Become a member](${url('en/become-a-member/')})
- [Contact](${url('en/contact/')})

## Articles — français

${ligne('fr')}

## Articles — English

${ligne('en')}

## Adhésion

Les frais d'adhésion sont de 6 000 FCFA, à régler une seule fois, auxquels
s'ajoute une cotisation annuelle de 15 000 FCFA. La carte de membre est valable
5 ans. Le membre reçoit un numéro YAAC, une carte de membre téléchargeable
et l'accès au groupe WhatsApp des membres. Chaque carte est vérifiable en
ligne : ${url('verify/')}

## Contact

- E-mail : ${contactInfo.email}
- Site : ${contactInfo.website}
- Téléphone (Sénégal) : ${contactInfo.phoneSenegal}
- Téléphone (Bénin) : ${contactInfo.phoneBenin}

## Réseaux sociaux

${reseaux}
`;

  return new Response(texte, {
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'public, max-age=3600',
    },
  });
};
