# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

**Public prioritaire — le jeune qui veut adhérer.** Un jeune Africain découvre YAAC
(souvent via Instagram, WhatsApp ou le bouche-à-oreille d'un membre), doit comprendre
ce qu'est l'alliance, se convaincre qu'elle est sérieuse, puis franchir une barrière
payante : **6000 FCFA** de frais d'adhésion. Il navigue majoritairement au mobile, sur
des connexions ouest-africaines où le poids des pages se paie en données réelles.
C'est lui qui arbitre la hiérarchie de chaque page.

Deux publics secondaires, confirmés par les rubriques existantes :

- **Bailleurs et institutions partenaires** (DAAD, BMZ, SESAM+, GBEDJIGBON, RJBD sont
  déjà des partenaires réels). Ils cherchent des preuves : gouvernance, enregistrement
  légal, impact vérifiable. La page Partenaires & Donateurs et les mentions
  d'enregistrement existent pour eux.
- **Les 34 membres déjà inscrits.** Ils possèdent un numéro YAAC et attendent une carte
  de membre.

## Product Purpose

YAAC — *Youth Alliance for Agroecology and Climate* — est une plateforme inclusive et
interculturelle qui lutte contre le changement climatique, forme les jeunes et combat la
faim en Afrique, par des projets communautaires, la formation et le plaidoyer.

Le site est l'entrée principale de l'alliance. Il réussit quand un jeune passe de
« je ne connais pas » à « je suis membre, j'ai payé, j'ai ma carte » sans intervention
humaine intermédiaire.

**Vision** : un continent africain résilient, où les jeunes disposent des capacités
nécessaires pour faire progresser la conservation de l'environnement et le développement
agroécologique durable.

## Positioning

Une alliance **portée par les jeunes eux-mêmes**, pas une ONG qui agit pour eux —
et **enregistrée dans deux pays** (Bénin et Sénégal), ce qu'un collectif informel ne
peut pas revendiquer. L'agroécologie y est traitée comme un levier de revenus dignes
autant que comme un outil de conservation ; c'est ce double registre, économique et
environnemental, qu'un acteur purement écologiste ne pourrait pas copier honnêtement.

## Operating Context

- **Bilingue FR/EN**, français par défaut (`defaultLocale: 'fr'`). Le site d'origine
  était intégralement anglophone ; le français est un ajout délibéré vers les publics
  béninois et sénégalais.
- **Adhésion** : formulaire Microsoft Forms → paiement Chariow → carte de membre par
  e-mail → invitation au groupe WhatsApp.
  - Formulaire d'adhésion : `https://forms.office.com/r/cEwz5H4Bvh` *(marqué « lien
    historique, en attente du formulaire définitif » dans le code)*
  - Formulaire partenaire : `https://forms.cloud.microsoft/r/gSpXzNcFrD`
  - Formulaire bénévole : `https://forms.cloud.microsoft/r/70kwMWMh3P`
  - Lien de paiement : `https://sdmudmoy.mychariow.online/prd_2tljqv9n/checkout`
  - E-mail expéditeur des cartes : `communication@yaac.network`
- **Contrainte externe dure** : ajouter quelqu'un à un groupe WhatsApp par API est
  interdit par Meta. Aucune automatisation ne peut le faire. La parade retenue est de
  joindre le lien d'invitation du groupe à l'e-mail de la carte.
- **Réseaux sociaux actifs** : Facebook, Instagram (`@yaac_network`), YouTube
  (`@YAACNetwork`), LinkedIn, WhatsApp `+221 71 032 22 44`.
- Le contenu vidéo vit sur YouTube et est intégré aux articles (5 vidéos sur 9 articles).

## Capabilities and Constraints

**Ce dépôt remplacera `yaac.network`.** Ce n'est plus un site parallèle d'évaluation —
le WordPress + Elementor actuel sera abandonné. Quatre conséquences à traiter, dont
aucune ne l'est aujourd'hui :

**Hébergement cible : Hostinger**, décidé, mais volontairement repoussé — la
qualité du site passe avant la bascule. GitHub Pages reste l'environnement de
travail et de relecture jusque-là.

| À traiter | État |
|---|---|
| Migration vers Hostinger sur `yaac.network` (aujourd'hui `base: '/YAAC'` sur GitHub Pages) | décidé, reporté après la phase qualité |
| Redirections des URL WordPress vers les nouvelles routes bilingues | non commencé |
| `yaac.network/verify/` renvoie **404** alors que c'est l'URL du QR code de tous les badges membres déjà imprimés | **cassé aujourd'hui**, à reconstruire dans le nouveau site |
| README affirmant encore que le dépôt ne remplace pas la production | à corriger |

**L'équipe communication doit pouvoir publier sans toucher au code.** Les 18 articles
sont aujourd'hui des fichiers Markdown versionnés ; publier demande git. C'est une
contrainte structurante : un CMS est requis avant la mise en production.
*Décision non prise* : lequel (Decap, Keystatic, Sanity ou autre) — à trancher avec
l'utilisateur, en tenant compte du budget d'une association et de l'hébergement statique.

Technique, confirmé par le code : Astro 7 statique, Node ≥ 22.12, `@astrojs/sitemap`,
images optimisées par `sharp`, i18n natif Astro avec slugs traduits
(`/fr/actualites/` ↔ `/en/news/`) et slug d'article identique dans les deux langues.
Aucun JavaScript de framework côté client. Déploiement par GitHub Actions.

**Terminologie** : « membre » (pas « utilisateur » ni « adhérent »), « YAAC Talk » pour
la série d'entretiens, « numéro YAAC » pour l'identifiant d'un membre.

## Brand Commitments

La charte graphique est **officielle et non négociable** — les valeurs proviennent du kit
global Elementor de `yaac.network` (`--e-global-color-*` / `--e-global-typography-*`),
elles ne sont pas une interprétation.

| Jeton | Valeur | Usage |
|---|---|---|
| `--yaac-primary` | `#006738` | vert profond — couleur de marque |
| `--yaac-accent` | `#87BD43` | vert feuille — **décoratif uniquement** |
| `--yaac-forest` | `#123524` | vert très foncé — fonds immersifs |
| Titres | Palanquin | |
| Corps | Roboto | |
| Display | Montserrat Alternates | chiffres-clés, signature |

**Garde-fou d'accessibilité, à ne jamais lever** : `#87BD43` sur blanc ne donne que
2,24:1, très en dessous du seuil WCAG AA. Il n'est jamais utilisé pour du texte sur fond
clair ; le jeton `--accent-text` (`#597d2c`, 4,81:1 sur blanc) sert à ce cas.

Roboto et l'accent vert sont pinnés par la charte : un avertissement générique sur la
banalité de Roboto ne les rouvre pas. Une association n'est pas rebrandée par un linter.

Signature : « La durabilité commence ici et maintenant ! » / *« Sustainability starts
here and now! »*

Cinq valeurs déclarées : Inclusion · Protection de l'environnement · Lutte contre la
pauvreté · Intégrité · Adaptation locale.

## Evidence on Hand

Réel et vérifié, à ne pas étendre par invention :

- **Chiffres** : 34 membres · 3 projets · 5 partenaires. *(Un 4ᵉ compteur a été
  volontairement supprimé faute de donnée réelle — ne pas le réintroduire.)*
- **9 articles** en FR et EN (`src/content/news/`), dates et vignettes récupérées via
  l'API REST de yaac.network, donc exactes. Un seul article, « Insights into clean
  energy », n'a **pas** de vignette d'origine ; l'image de sa vidéo YouTube a été
  utilisée à la place.
- **5 logos partenaires réels** (`src/assets/partners/`) : DAAD, BMZ, SESAM+,
  GBEDJIGBON, RJBD. Sur yaac.network ils existent en médiathèque mais le carrousel
  n'affiche que des placeholders.
- **Organigramme** officiel (`src/assets/brand/organigram.png`) — c'est un schéma :
  lisible en entier, jamais recadré.
- **Enregistrement légal** :
  - Bénin — `2025 N°5/31/PDC/SGD/SAG-ASSSOC`, IFU `6 2026 3013 2351`, `+229 01 67 37 54 33`
  - Sénégal — `Arrêté n° 01.12.2025*043403`, NINEA `013305447`, `+221 71 032 22 44`
- **Photos** : 6 photos de terrain (`src/assets/photos/`).
- **Carte de membre existante** : `C:\Users\PC\Downloads\YAAC Badge\` — badge
  476×756 px, générateur HTML, page de vérification et guide Power Automate. Le badge
  actuel utilise **Segoe UI** et des verts hors charte (`#1b5e30`, `#6ab04c`).

**Absences à ne jamais combler par invention** : aucun témoignage nominatif vérifié,
aucun chiffre d'impact au-delà des trois compteurs, aucun budget ni rapport financier
public, aucune couverture presse.

## Product Principles

1. **La barrière payante est le moment de vérité.** Tout ce qui précède les 6000 FCFA
   doit construire assez de confiance pour qu'un jeune sorte son argent. La preuve prime
   sur la promesse.
2. **Le mobile ouest-africain est la condition de référence**, pas le cas dégradé. Le
   poids d'une page se paie en données réelles ; c'est ce qui a motivé le passage d'un
   WordPress à 1,33 Mo de CSS vers un statique.
3. **La charte se respecte, l'accessibilité ne se sacrifie pas.** Quand les deux
   semblent s'opposer, on dérive une variante mesurée — on ne rend pas du texte
   illisible et on ne rebrande pas l'association.
4. **Le français est un public, pas une traduction.** Les deux langues sont de premier
   rang ; ce qui est bancal en français est un défaut, pas un détail.
5. **Le réel, ou rien.** Chiffres, dates, vignettes, logos et références légales sont
   vérifiables. Une donnée manquante se retire, elle ne s'invente pas.

## Accessibility & Inclusion

WCAG AA est le seuil de travail, appliqué de façon vérifiée et non déclarative (ratios
mesurés, jamais estimés). Le site d'origine servait `<html lang="fr-FR">` sur du contenu
entièrement anglais — l'attribut de langue correct par page est un acquis à préserver.
Contexte réseau contraint et navigation majoritairement mobile font partie des besoins
d'inclusion, pas seulement de la performance.
