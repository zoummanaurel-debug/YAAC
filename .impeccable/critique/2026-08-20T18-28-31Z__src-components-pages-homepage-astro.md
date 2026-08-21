---
target: page accueil FR
total_score: 19
max_score: 36
na_heuristics: 7
p0_count: 1
p1_count: 2
timestamp: 2026-08-20T18-28-31Z
slug: src-components-pages-homepage-astro
---
Method: dual-agent (A: revue de conception isolée du détecteur · B: détecteur + preuves de build)

# Critique — Page d'accueil FR de YAAC

## Score de santé du design

| # | Heuristique | Note | Problème principal |
|---|---|---|---|
| 1 | Visibilité de l'état du système | 2/4 | 11/11 liens `target=_blank`, 0/11 avec indice externe ; la conversion payante quitte le site sans prévenir. |
| 2 | Correspondance monde réel | 2/4 | « Paroles du terrain » sans paroles ; « Ce sur quoi nous travaillons » titre des billets ; 3 cartes/4 à la même date d'import. |
| 3 | Contrôle et liberté | 3/4 | `card.replaceWith()` détruit la vignette ; iframe `autoplay=1` sur connexion facturée. |
| 4 | Cohérence et standards | 3/4 | « Devenir membre » en deux styles ; « Nous contacter » (valeur moindre) hérite du btn--primary. |
| 5 | Prévention des erreurs | 2/4 | Conversions vers une URL que le code qualifie de provisoire (utils.ts:24) ; ni tarif ni prérequis. |
| 6 | Reconnaissance vs rappel | 2/4 | Les 6000 FCFA absents ; 4 vidéos sans titre, nom ni durée. |
| 7 | Flexibilité et efficacité | n/a | Surface de persuasion à visite unique, aucun flux répété à accélérer. |
| 8 | Esthétique et minimalisme | 3/4 | Retenue réelle, sabordée par deux blocs de persuasion consécutifs. |
| 9 | Récupération d'erreur | 1/4 | i.ytimg.com bloqué => rectangle vert sans signal d'échec. |
| 10 | Aide et documentation | 1/4 | Noté volontairement : « comment devient-on membre » EST la documentation, et elle est absente. |
| **Total** | | **19/36** | **Déficit concentré au seul endroit qui compte** |

9 heuristiques applicables, max 36 (~21/40 renormalisé). H7 n/a.

## Verdict de spécificité

Interchangeable par catégorie, bâtie avec un vrai métier. Six sections, six patrons standards du secteur ONG : héros photo + voile, bandeau de compteurs animés, rangée de 4 vignettes vidéo, grille d'actualités à carte large, bandeau CTA sombre, pied de page en trois colonnes. Le métier réel est au niveau du composant — dérivation mesurée de `--accent-text`, césure française 8/4/4 interdite en menu, burger à 1100px calé sur la longueur du menu FR, titre héros épinglé sur 41 caractères — jamais au niveau de la composition. Le dépôt argumente sur ce qu'il ne faut pas faire, jamais sur ce qu'il faut être.

Scan déterministe : 1 trouvaille (`overused-font` Roboto, Base.astro:73) = faux positif, pinné par la charte dans PRODUCT.md. Trouvailles nettes : 0.

Superpositions visuelles : aucune — pas d'automatisation de navigateur exposée dans la session. Aucune surcouche injectée, aucune capture rendue. Mesures issues d'un build neuf, de curl et des CSS écrits.

## FR ↔ EN

FR 470 jetons de balise / EN 470. 12 diffs, tous des slugs d'URL localisés (`qui-sommes-nous`↔`who-we-are` ×3, `actualites`↔`news` ×6, `partenaires`↔`partners` ×2). Sections, articles, liens, boutons, images, titres, hreflang, canoniques, og:locale, html lang : identiques et corrects. 128/128 clés i18n sans orpheline. 8 espaces insécables FR bien placées. **Aucune dérive comportementale.**

Défauts liés à la langue, partagés et non divergents :

1. `<title>` = chaîne anglaise identique sur les deux accueils. La page FR n'a aucun titre français.
2. `og:image` / `twitter:image` absents alors que `twitter:card=summary_large_image`. Partage LinkedIn/WhatsApp = carte vide.
3. Les 4 vidéos codées en dur (TestimonialCarousel.astro:14) sont toutes en français et servies aux deux locales. Un anglophone lit « Voices from the field » et reçoit de l'audio français sans avertissement.
4. Un seul formulaire d'adhésion pour les deux langues (utils.ts:25), langue non vérifiable (rendu JS).

## Ce qui fonctionne

1. Le français traité comme public dans le CSS, pas seulement dans le JSON : césure interdite en menu, burger calé sur le FR, titre épinglé au compte de caractères FR. Presque aucun site bilingue ne fait le troisième.
2. La façade YouTube : ~700 Ko et zéro cookie évités avant consentement, raisonnement écrit dans le commentaire.
3. L'honnêteté des chiffres appliquée structurellement : auto-fit sur la grille, valeur dans le HTML avant tout script.
4. Contraste : 14 paires mesurées, aucune sous 4,5:1. Le garde-fou #87bd43 (2,24:1 sur blanc) tient partout.

## Problèmes prioritaires

### [P0] Aucune preuve institutionnelle avant le pied de page ; « Devenir partenaire » absent de l'accueil

Enregistrement légal une seule fois, en pied, à `--step--1`. Organigramme seulement sur /qui-sommes-nous/. Les 6 logos (PartnerGrid déjà construit et harmonisé) seulement sur /partenaires/. Ni Bénin ni Sénégal au-dessus du pied. `externalLinks.partnership` sans point d'entrée sur l'accueil. DAAD et BMZ sont déjà partenaires et ne sont pas montrés.

Fix : section insérée à HomePage.astro:65 — enregistrement à `--step-0`, `<PartnerGrid />` réutilisé tel quel, lien gouvernance vers l'organigramme, action « Devenir partenaire ». Ne pas dupliquer le pied de page : déplacer l'emphase.

Commande : /impeccable shape puis /impeccable layout

### [P1] « Paroles du terrain » jette la seule preuve humaine nommée du site

Les 4 vidéos sont la série « La Voix du YAAC » : D8LZC2wvi5I = *Spotlight on our President Alexandre ZOUMMAN* ; RF28Kf2TErI = *Schelumiel Ghiseoth AGBODJIAN*. Personnes réelles, nommées, attribuables — rendues comme 4 boîtes anonymes dont le seul nom accessible est « Témoignage vidéo 1 ».

Mesuré : les 4 en hqdefault 480×360 (4:3) dans une carte `aspect-ratio: 4/3` => affichées sans recadrage. D8LZC2wvi5I et qhW_5tUfGjg ont de vraies bandes noires (luminance 0,3–0,5 sur 45 lignes haut/bas, ~25 % de la hauteur) ; RF28Kf2TErI et i3p7r5nu-y8 sont recadrées latéralement à la place. Deux cartes en boîte-aux-lettres à côté de deux rognées — l'incohérence fait plus de dégâts que l'un ou l'autre défaut seul.

Fix : rapatrier les 4 maxresdefault (1280×720, vrai 16:9, sans bandes, 62–125 Ko) dans src/assets/ et laisser sharp servir en responsive — supprime la dépendance tierce, le risque de rectangle vert et le coût réseau d'un coup. Passer `.testi__card` ET le wrapper ligne 133 en 16/9. Retirer `opacity: 0.85`. Écrire le nom sous chaque vignette.

Commande : /impeccable clarify puis /impeccable polish

### [P1] Les compteurs 34/3/6 rendus à l'amplitude maximale

`--step-5` (2,49–3,82rem) = même pas typographique que le h1, décompte animé 1200ms, sous « Un mouvement qui grandit ». Le gros chiffre est un format comparatif : il ne fonctionne que si le nombre bat l'a priori du lecteur, et 34 invite exactement la comparaison qu'il perd. L'animation met en scène la petitesse comme une révélation. Convertit « jeune et vrai » en « petit et qui se gonfle », à 500px du haut, avant toute preuve.

Fix : `--step-3`, suppression du script de décompte, qualification de chaque nombre par le fait qui le rend crédible plutôt que gros — 34 membres, dans 2 pays, chacun avec un numéro YAAC / 3 projets menés avec les communautés / 6 partenaires dont DAAD et BMZ. Retitrer hors de la croissance.

Commande : /impeccable typeset

### [P2] Deux CTA consécutifs qui se diluent

HomePage.astro:67-79. CtaBand (externe, accent) puis contact-teaser (interne, btn--primary). L'action de moindre valeur porte le bouton le plus lourd. Dilution bidirectionnelle : porte de sortie gratuite offerte au moment de conclure ; et la seule ligne adressée au bailleur est un h2 nu sans chapô — la seule section de la page sans chapô.

Fix : garder le CtaBand, supprimer le contact-teaser, replier l'adresse partenaire dans la bande de crédibilité du P0. Le contact reste joignable 3 autres fois depuis chaque page.

Commande : /impeccable distill

### [P2] Le mobile, condition de référence déclarée, est le contexte le moins bien traité

7 éléments interactifs sous 44×44 : hamburger 40×32, sélecteur de langue ~40px, CTA adhésion ~42px. `.hdr__actions` est dans `.hdr__nav`, masqué sous 1100px => sélecteur de langue ET conversion payante cachés derrière le hamburger. Menu sans piège de focus ni fermeture au tap extérieur (Échap seul). Anneau de focus #006738 : 1,58:1 sur le CtaBand, 1,92:1 sur le pied = invisible aux deux endroits.

Commande : /impeccable adapt puis /impeccable audit

## Personas

**Bailleur DAAD/BMZ** : pas de pays (« Afrique »), aucun nom humain sur toute la page pied compris, ses propres logos sur une page qu'il n'ouvrira pas, son action inexistante, partage = carte vide et titre anglais.

**Jeune mobile** : autoplay sur connexion facturée à une tape, sans annulation possible (la vignette est déjà détruite) ; « 34 membres » à l'échelle h1 en deuxième écran ; `.ncard--wide` inactif sous 900px donc 4 cartes identiques ; apprend les 6000 FCFA seulement dans le formulaire externe.

## Observations mineures

- 8 articles/9 datés 2025-11-13 => 3 cartes/4 à la même date. « Lancement officiel » daté 13 mois après le lancement décrit ; YAAC Talk n°3 daté 28/04/2026 pour un événement du 21/02/2025.
- Rien de moins de 4 mois sur « Ce sur quoi nous travaillons ».
- JSON-LD NGO sans address, identifier/taxID ni sameAs, alors que tout est dans utils.ts:31-69. Gain de crédibilité le moins cher du dépôt.
- Alt de la photo héros = nom de l'organisation ; le lien de marque annonce le nom deux fois (alt + span masqué).
- 5 h2 de pied de page au même rang que les titres de section.
- `.hero__title--home` nowrap épinglé à 41 caractères dans un overflow:hidden => rognage silencieux à toute édition de fr.json:22.
- ArticlePage.astro:50 intègre l'iframe sans façade — contredit la logique défendue sur l'accueil.
- 107 Ko au-dessus de la flottaison @1×, 176 Ko @2×, sans preload LCP, premier rendu bloqué par 3 familles / 10 graisses tierces.

## Questions

1. Si vous supprimiez le bandeau de compteurs, que perdrait réellement un bailleur ?
2. La page ne nomme aucun humain alors que la position est « portée par les jeunes eux-mêmes ». Que coûte de les nommer, comparé à ne pas le faire ?
3. Si la réponse honnête est 3 projets, pourquoi le 3 est-il une tuile plutôt que trois projets nommés avec un lieu, une date et une photo ?
4. Deux vignettes sur quatre ont des bandes noires depuis la mise en ligne. Qu'est-ce d'autre n'a jamais été regardé sur l'appareil visé ?
5. Toutes les conversions passent par une URL que vos propres commentaires appellent temporaire. Que se passe-t-il quand elle change ?
