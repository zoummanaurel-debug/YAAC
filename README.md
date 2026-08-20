# YAAC — refonte du site

Refonte statique et bilingue du site de la **Youth Alliance for Agroecology and Climate**
([yaac.network](https://yaac.network)), construite avec [Astro](https://astro.build).

Toutes les rubriques du site d'origine sont reprises. Le design est retravaillé, mais la
charte graphique de l'organisation est respectée à la lettre : les couleurs et les
typographies proviennent du kit global Elementor du site en production, pas d'une
interprétation.

> Ce dépôt est un site **parallèle**, destiné à l'évaluation. Il ne remplace pas
> yaac.network en production.

## Charte graphique

Valeurs extraites de `--e-global-color-*` et `--e-global-typography-*` sur yaac.network.

| Jeton | Valeur | Usage |
|---|---|---|
| `--yaac-primary` | `#006738` | Couleur de marque, boutons, liens |
| `--yaac-accent` | `#87BD43` | Décoratif **uniquement** (voir ci-dessous) |
| `--yaac-forest` | `#123524` | Fonds de sections immersives |
| Titres | Palanquin | |
| Corps | Roboto | |
| Display | Montserrat Alternates | Chiffres-clés, signature |

**Garde-fou d'accessibilité.** `#87BD43` sur blanc ne donne que **2,24:1**, loin du seuil
WCAG AA de 4,5:1. Il n'est jamais utilisé pour du texte sur fond clair. Pour ce cas, le jeton
`--accent-text` (`#597d2c`) conserve la teinte et la saturation de l'accent officiel avec une
luminosité abaissée : **4,81:1** sur blanc, **4,53:1** sur fond teinté.

## Démarrer

```bash
npm install
npm run dev      # http://localhost:4321/YAAC/
npm run build    # génère dist/
npm run preview  # sert dist/ localement
```

Node.js 18 ou plus est requis.

## Structure

```
src/
├── assets/          logo, photos et logos partenaires (optimisés en WebP au build)
├── components/      composants de section réutilisables
│   └── pages/       corps de page partagés entre FR et EN
├── content/news/    18 articles Markdown (9 × 2 langues)
├── i18n/            dictionnaires fr.json / en.json + utilitaires de route
├── layouts/         Base.astro : lang, hreflang, SEO, JSON-LD
├── pages/           routes ; les slugs sont traduits (actualites / news)
└── styles/          tokens.css (la charte) + global.css
```

Le **slug d'un article est identique dans les deux langues**, ce qui permet au sélecteur de
langue de basculer vers la traduction sans table de correspondance.

## Ajouter un article

Créer le même nom de fichier dans `src/content/news/fr/` **et** `src/content/news/en/` :

```markdown
---
title: "Titre de l'article"
description: "Résumé affiché dans les cartes et les métadonnées."
date: 2026-03-15
category: yaac-talk        # events | informations | public-action | yaac-talk
cover: ../../../assets/photos/hero.jpg
coverAlt: "Description de l'image pour les lecteurs d'écran"
featured: false
---

Le contenu en Markdown.
```

Le filtrage par catégorie sur la page Actualités se met à jour automatiquement : seules les
catégories réellement présentes apparaissent.

## Déploiement

Chaque push sur `main` déclenche `.github/workflows/deploy.yml`, qui construit le site,
vérifie que les 8 pages principales existent, puis publie sur GitHub Pages.

`astro.config.mjs` définit `base: '/YAAC'` — indispensable pour un dépôt de projet
GitHub Pages. En cas de changement de nom de dépôt ou de domaine personnalisé, il faut
ajuster `site` et `base`.

## Écarts assumés par rapport au site d'origine

- **Doublon corrigé** : « Why partner with us? » affichait deux fois « Sustainable practices ».
  Le quatrième bloc est désormais « Ancrage local », un texte rédigé pour l'occasion.
- **Logos partenaires** : présents dans la médiathèque de yaac.network mais non rendus par le
  carrousel. Ils sont ici servis en dur.
- **Balise de langue** : le site d'origine déclare `fr-FR` sur du contenu anglais. Chaque page
  déclare ici sa langue réelle.
- **Dates** : quand l'article documente une date d'événement, c'est elle qui est affichée
  plutôt que la date de publication (souvent une date d'import groupé).
- **Textes descriptifs** : les valeurs et les arguments partenaires n'étaient que des
  intitulés sur le site d'origine. Les descriptions ont été rédigées et demandent une
  relecture par YAAC.
- **Traductions françaises** : produites pour ce projet, à faire valider avant toute mise en
  ligne officielle.
