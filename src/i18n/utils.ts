import fr from './fr.json';
import en from './en.json';

export const languages = { fr: 'Français', en: 'English' } as const;
export type Lang = keyof typeof languages;
export const defaultLang: Lang = 'fr';

const dictionaries = { fr, en } as const;
export type Dict = (typeof dictionaries)[Lang];

/** Clés de page dont le slug diffère selon la langue. */
export const routes = {
  home:     { fr: '',                 en: '' },
  about:    { fr: 'qui-sommes-nous',  en: 'who-we-are' },
  news:     { fr: 'actualites',       en: 'news' },
  partners: { fr: 'partenaires',      en: 'partners' },
  contact:  { fr: 'contact',          en: 'contact' },
} as const;

export type RouteKey = keyof typeof routes;

/** Formulaires Microsoft Forms de YAAC, partagés par les deux langues. */
export const externalLinks = {
  /** Adhésion : lien historique, en attente du formulaire définitif. */
  membership: 'https://forms.office.com/r/cEwz5H4Bvh',
  partnership: 'https://forms.cloud.microsoft/r/gSpXzNcFrD',
  volunteer: 'https://forms.cloud.microsoft/r/70kwMWMh3P',
} as const;

/** Coordonnées de contact. */
export const contactInfo = {
  email: 'communication@yaac.network',
  website: 'www.yaac.network',
  phoneBenin: '+229 01 67 37 54 33',
  phoneSenegal: '+221 71 032 22 44',
  /** Format international sans espaces ni +, requis par wa.me */
  whatsapp: '221710322244',
} as const;

/** Réseaux sociaux officiels. */
export const socialLinks = [
  { name: 'Facebook', url: 'https://www.facebook.com/profile.php?id=61569023037197' },
  { name: 'Instagram', url: 'https://www.instagram.com/yaac_network/' },
  { name: 'YouTube', url: 'https://www.youtube.com/@YAACNetwork' },
  { name: 'LinkedIn', url: 'https://www.linkedin.com/company/yaac-network/' },
  { name: 'WhatsApp', url: `https://wa.me/${contactInfo.whatsapp}` },
] as const;

/**
 * Enregistrement légal. YAAC est officiellement enregistrée dans deux pays ;
 * afficher ces références est un signal de sérieux attendu d'une ONG,
 * en particulier par les bailleurs de fonds.
 */
export const registrations = [
  {
    country: { fr: 'Bénin', en: 'Benin' },
    reference: '2025 N°5/31/PDC/SGD/SAG-ASSSOC',
    taxLabel: 'IFU',
    taxId: '6 2026 3013 2351',
    phone: contactInfo.phoneBenin,
  },
  {
    country: { fr: 'Sénégal', en: 'Senegal' },
    reference: 'Arrêté n° 01.12.2025*043403',
    taxLabel: 'NINEA',
    taxId: '013305447',
    phone: contactInfo.phoneSenegal,
  },
] as const;

export function getLangFromUrl(url: URL): Lang {
  const [, maybeLang] = url.pathname.replace(import.meta.env.BASE_URL, '/').split('/');
  return maybeLang in languages ? (maybeLang as Lang) : defaultLang;
}

export function useTranslations(lang: Lang): Dict {
  return dictionaries[lang];
}

/** Concatène des segments en garantissant une seule barre oblique entre chacun. */
function joinPath(...parts: string[]): string {
  const joined = parts
    .filter(Boolean)
    .map((p) => p.replace(/^\/+|\/+$/g, ''))
    .filter(Boolean)
    .join('/');
  return `/${joined}${joined ? '/' : ''}`;
}

/** URL absolue (préfixée par `base`) d'une page, dans la langue demandée. */
export function localePath(lang: Lang, key: RouteKey = 'home'): string {
  return joinPath(import.meta.env.BASE_URL, lang, routes[key][lang]);
}

/** URL d'un article d'actualité. */
export function articlePath(lang: Lang, slug: string): string {
  return joinPath(import.meta.env.BASE_URL, lang, routes.news[lang], slug);
}

/** URL d'une ressource de `public/`. */
export function assetPath(path: string): string {
  return `${joinPath(import.meta.env.BASE_URL)}${path.replace(/^\/+/, '')}`;
}

/** Formate une date selon la locale. */
export function formatDate(date: Date, lang: Lang): string {
  return new Intl.DateTimeFormat(lang === 'fr' ? 'fr-FR' : 'en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date);
}

/** Langue opposée, pour le sélecteur. */
export function otherLang(lang: Lang): Lang {
  return lang === 'fr' ? 'en' : 'fr';
}
