/**
 * Référentiel des pays — codes ISO 3166-1 alpha-2 et indicatifs téléphoniques.
 *
 * Seuls les codes sont stockés : les NOMS viennent d'`Intl.DisplayNames`, qui
 * les fournit en français et en anglais depuis les données CLDR du système.
 * Écrire les 250 noms à la main dans deux langues, ce serait 500 chaînes à
 * maintenir et à corriger à chaque changement de dénomination — l'Eswatini et
 * la Macédoine du Nord ont tous deux changé de nom récemment.
 */

/**
 * Les 54 États africains membres de l'ONU.
 *
 * Le Sahara occidental (EH) n'y figure pas : territoire disputé, il n'est pas
 * un État membre, et une liste de « pays d'origine » n'est pas l'endroit pour
 * trancher une question de souveraineté.
 */
const AFRIQUE =
  'DZ:213 AO:244 BJ:229 BW:267 BF:226 BI:257 CV:238 CM:237 CF:236 TD:235 ' +
  'KM:269 CG:242 CD:243 CI:225 DJ:253 EG:20 GQ:240 ER:291 SZ:268 ET:251 ' +
  'GA:241 GM:220 GH:233 GN:224 GW:245 KE:254 LS:266 LR:231 LY:218 MG:261 ' +
  'MW:265 ML:223 MR:222 MU:230 MA:212 MZ:258 NA:264 NE:227 NG:234 RW:250 ' +
  'ST:239 SN:221 SC:248 SL:232 SO:252 ZA:27 SS:211 SD:249 TZ:255 TG:228 ' +
  'TN:216 UG:256 ZM:260 ZW:263';

/** Le reste du monde. */
const AILLEURS =
  // Europe
  'AL:355 AD:376 AT:43 AX:358 BY:375 BE:32 BA:387 BG:359 HR:385 CY:357 ' +
  'CZ:420 DK:45 EE:372 FO:298 FI:358 FR:33 DE:49 GI:350 GR:30 GG:44 HU:36 ' +
  'IS:354 IE:353 IM:44 IT:39 JE:44 XK:383 LV:371 LI:423 LT:370 LU:352 ' +
  'MT:356 MD:373 MC:377 ME:382 NL:31 MK:389 NO:47 PL:48 PT:351 RO:40 RU:7 ' +
  'SM:378 RS:381 SK:421 SI:386 ES:34 SE:46 CH:41 UA:380 GB:44 VA:39 ' +
  // Amériques
  'AI:1 AG:1 AR:54 AW:297 BS:1 BB:1 BZ:501 BM:1 BO:591 BQ:599 BR:55 VG:1 ' +
  'CA:1 KY:1 CL:56 CO:57 CR:506 CU:53 CW:599 DM:1 DO:1 EC:593 SV:503 ' +
  'FK:500 GF:594 GL:299 GD:1 GP:590 GT:502 GY:592 HT:509 HN:504 JM:1 ' +
  'MQ:596 MX:52 MS:1 NI:505 PA:507 PY:595 PE:51 PR:1 BL:590 KN:1 LC:1 ' +
  'MF:590 PM:508 VC:1 SX:599 SR:597 TT:1 TC:1 US:1 UY:598 VE:58 VI:1 ' +
  // Asie
  'AF:93 AM:374 AZ:994 BH:973 BD:880 BT:975 BN:673 KH:855 CN:86 GE:995 ' +
  'HK:852 IN:91 ID:62 IR:98 IQ:964 IL:972 JP:81 JO:962 KZ:7 KW:965 KG:996 ' +
  'LA:856 LB:961 MO:853 MY:60 MV:960 MN:976 MM:95 NP:977 KP:850 OM:968 ' +
  'PK:92 PS:970 PH:63 QA:974 SA:966 SG:65 KR:82 LK:94 SY:963 TW:886 ' +
  'TJ:992 TH:66 TL:670 TR:90 TM:993 AE:971 UZ:998 VN:84 YE:967 ' +
  // Océanie
  'AS:1 AU:61 CK:682 FJ:679 PF:689 GU:1 KI:686 MH:692 FM:691 NR:674 ' +
  'NC:687 NZ:64 NU:683 NF:672 MP:1 PW:680 PG:675 WS:685 SB:677 TK:690 ' +
  'TO:676 TV:688 VU:678 WF:681';

export interface Pays {
  /** ISO 3166-1 alpha-2. */
  code: string;
  /** Indicatif téléphonique, sans le « + ». */
  indicatif: string;
  africain: boolean;
}

const lire = (source: string, africain: boolean): Pays[] =>
  source
    .split(' ')
    .filter(Boolean)
    .map((entree) => {
      const [code, indicatif] = entree.split(':');
      return { code, indicatif, africain };
    });

export const PAYS: readonly Pays[] = [...lire(AFRIQUE, true), ...lire(AILLEURS, false)];

/**
 * Quelques dénominations CLDR sont ambiguës ou inhabituelles hors contexte
 * technique. On ne corrige que celles-là, plutôt que de tout réécrire.
 */
const EXCEPTIONS: Record<string, { fr: string; en: string }> = {
  CD: { fr: 'République démocratique du Congo', en: 'Democratic Republic of the Congo' },
  CG: { fr: 'République du Congo', en: 'Republic of the Congo' },
  CF: { fr: 'République centrafricaine', en: 'Central African Republic' },
  ST: { fr: 'Sao Tomé-et-Principe', en: 'São Tomé and Príncipe' },
  KP: { fr: 'Corée du Nord', en: 'North Korea' },
  KR: { fr: 'Corée du Sud', en: 'South Korea' },
};

/**
 * Liste triée alphabétiquement dans la langue demandée.
 *
 * Le tri utilise `localeCompare` avec la locale : sans lui, « Égypte » et
 * « Éthiopie » se retrouveraient après « Zimbabwe », les caractères accentués
 * ayant un code supérieur en ordre binaire.
 */
export function paysTries(lang: 'fr' | 'en', filtre?: (p: Pays) => boolean): Array<Pays & { nom: string }> {
  const noms = new Intl.DisplayNames([lang], { type: 'region' });
  return PAYS.filter(filtre ?? (() => true))
    .map((p) => ({ ...p, nom: EXCEPTIONS[p.code]?.[lang] ?? noms.of(p.code) ?? p.code }))
    .sort((a, b) => a.nom.localeCompare(b.nom, lang));
}
