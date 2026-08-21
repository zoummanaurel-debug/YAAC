# Adhésion, paiement et carte de membre

Le **paiement Chariow déclenche l'adhésion**. Sans vente confirmée, il n'y a ni
numéro de membre, ni carte.

```
/devenir-membre/   (ou /become-a-member/)
   │  Le tarif de 6 000 FCFA est écrit AVANT le premier champ.
   │  POST
   ▼
/api/adhesion-init.php
   ├─ valide, piège à robots, plafond de 5 candidatures/heure/IP
   ├─ écrit une `candidature` porteuse d'un jeton
   ├─ POST https://api.chariow.com/v1/checkout
   │     custom_metadata : { candidature: <jeton> }
   │     redirect_url    : /fr/adhesion-confirmee/ ou /en/membership-confirmed/
   └─ renvoie `checkout_url` ; le navigateur y va
   │
   ▼
Chariow — « YAAC carte de membre » (prd_2tljqv9n, 6 000 FCFA)
   │
   │  Pulse successful.sale — le jeton revient dans custom_metadata
   ▼
/api/adhesion.php
   ├─ vérifie la signature HMAC-SHA256 (x-chariow-signature)
   ├─ dédoublonne sur x-pulse-delivery-id
   ├─ retrouve la candidature par son jeton (repli : par e-mail)
   ├─ attribue le numéro YAAC-AAAA-NNN de façon atomique
   ├─ écrit le membre, referme la candidature
   └─ envoie l'e-mail : carte, /verify/, groupe WhatsApp
   │
   │  Pulses license.expired / license.revoked
   ▼
met à jour `membre.statut` (actif → expire / suspendu)

/verify/?id=YAAC-2026-001  →  /api/verify.php  →  lecture directe en base
```

## Ce que ça corrige

| Défaut de l'ancien système | État |
|---|---|
| La carte partait sans que le paiement soit vérifié | Corrigé — le paiement déclenche |
| E-mail annonçant « 5 000 FCFA + 15 000 FCFA/an » | Corrigé — 6 000 FCFA de frais d'adhésion |
| Le tarif n'apparaissait nulle part avant de quitter le site | Corrigé — annoncé sur `/devenir-membre/` |
| QR vers `/verify/`, e-mail vers `/verifier/`, aucun des deux n'existant | Corrigé — `/verify/` seul, et il existe |
| Vérification limitée à 5 membres codés en dur | Corrigé — lecture en base, sans redéploiement |
| Deux inscriptions simultanées pouvaient partager un numéro | Corrigé — compteur atomique MySQL |
| Resoumettre le formulaire délivrait un second numéro | Corrigé — e-mail unique en base |
| Un formulaire rempli sans paiement était invisible | Corrigé — candidature sans `membre_id` = abandon mesurable |
| Rien ne reliait une réponse de formulaire à un paiement | Corrigé — jeton en `custom_metadata` |
| `Statut` proposait « Expiré / Suspendu » sans déclencheur | Corrigé — événements de licence Chariow |
| Aucun lien WhatsApp dans l'e-mail | Corrigé — bloc dédié |
| Badge hors charte (Segoe UI, `#1B5E30`) | **Non traité** — générateur de carte à reprendre |
| Le badge se fabrique depuis l'URL, sans vérification | **Non traité** — c'est `/verify/` qui fait foi |

## Installation

### 1. Base de données

Hostinger → Bases de données MySQL → créer une base et un utilisateur, puis
importer via phpMyAdmin, dans cet ordre :

```
db/schema.sql              -- tables, aucun membre
db/import-membres.local.sql -- les membres existants (généré, voir §2)
```

`schema.sql` n'insère **aucun** membre, volontairement : deux sources
contradictoires existaient (`MEMBERS_DB` de l'ancienne page de vérification,
5 personnes en `YAAC-2024-000N` ; et `Membres.xlsx`, 18 personnes en
`YAAC-2026-NNN`). Les `YAAC-2024-000N` étaient des **numéros de test** — le
guide Power Automate les listait comme tels. La série réelle est
`YAAC-2026-NNN`, sur trois chiffres.

### 2. Importer les membres existants

```
node db/import-membres.mjs "C:/Users/PC/Downloads/Membres.xlsx" > db/import-membres.local.sql
```

Le fichier produit contient des données personnelles (e-mails, téléphones,
dates de naissance, motivations). Le suffixe `.local.sql` est ignoré par git :
**le dépôt est public**, ce fichier ne doit jamais y entrer.

État au 21/08/2026 : **34 membres, dont 18 numérotés** (`YAAC-2026-001` à
`018`) et **16 « à attribuer »** — des participants à l'AG constitutive qui
n'ont jamais rempli le formulaire en ligne. Ces 16 passeront par
`/devenir-membre/` et recevront `YAAC-2026-019` et suivants.

> Les colonnes `Date_Adhesion` et `Date_Expiration` sont vides dans le
> classeur : l'import retombe sur la date du jour et +5 ans. C'est **faux**
> pour qui a adhéré avant. Si les vraies dates existent ailleurs, les corriger
> avant d'exécuter le fichier.

### 3. Configuration

`config.php` **se crée directement sur le serveur**, jamais dans le dépôt.
`public/` est copié tel quel dans `dist/`, qui est publié ; GitHub Pages
n'exécute pas PHP et servirait donc le fichier en texte brut. Le `.gitignore`
empêche le commit, pas la publication — un garde-fou dans le workflow de
déploiement fait échouer la build si le fichier réapparaît.

```
cp public_html/api/config.example.php public_html/api/config.php
```

Valeurs confirmées le 21/08/2026 :

| Clé | Valeur |
|---|---|
| `chariow.product_id` | `prd_2tljqv9n` — déjà dans l'exemple |
| `chariow.api_key` | clé `sk_live_…` de la boutique, **nécessaire** pour créer une session de paiement |
| `chariow.pulse_secret` | `whsec_…` du Pulse — voir §4 |
| `adhesion.montant_affiche` | `6 000 FCFA` — déjà dans l'exemple |
| `mail.from_email` / `reply_to` / `smtp.user` | `communication@yaac.network` |
| `liens.whatsapp` | le lien `https://chat.whatsapp.com/…` du groupe des membres |
| `verification_secret` | à générer **une seule fois**, voir ci-dessous |

Restent à obtenir sur place : accès MySQL et mot de passe SMTP.

#### Le secret de vérification

```
php -r "echo bin2hex(random_bytes(32));"
```

**Générez-le une fois et ne le changez plus.** Il signe les jetons inscrits
dans les QR codes : le modifier invaliderait la vérification complète de
toutes les cartes déjà imprimées. Elles ne deviendraient pas fausses — elles
retomberaient sur la réponse réduite, sans nom.

## Vérification : public, mais non énumérable

Les numéros sont séquentiels. Sans protection, compter de `001` à `999`
suffirait à récupérer nom, rôle et dates de tous les membres — un annuaire
nominatif public, pour une organisation de jeunes militants du climat.

La parade n'est pas de fermer le portail : un QR code que seul le Bureau peut
lire ne sert à rien, alors que c'est au partenaire en face qu'il doit
répondre. Le QR porte donc, en plus du numéro, un **jeton** = HMAC-SHA256 du
numéro tronqué à 10 caractères hexadécimaux (40 bits). Il n'est pas stocké :
il se recalcule.

| Requête | Réponse |
|---|---|
| `/verify/?id=…&c=<jeton>` — quelqu'un qui **scanne la carte** | Fiche complète : nom, rôle, statut, expiration |
| `/verify/?id=…` — quelqu'un qui **tape un numéro** | Existence et statut seuls, **aucun nom** |
| Énumération de `001` à `999` | Rien d'exploitable |

La **date d'adhésion n'est jamais renvoyée** : elle n'aide pas à authentifier
une carte et n'ajoute que de la donnée personnelle.

> Ce qui mérite un verrou, c'est une future vue d'administration pour le
> Bureau — lister les membres, voir e-mails et téléphones, suspendre une
> adhésion. Elle n'existe pas encore. Vérification publique et administration
> sont deux surfaces distinctes ; les confondre reviendrait à verrouiller la
> mauvaise.

> `info@yaac.network` **n'existe pas** — erreur de l'ancien guide Power
> Automate. Le code du site n'y a jamais fait référence : zéro occurrence
> dans `src/`, vérifié.

### 4. Déclarer le Pulse dans Chariow

Automation → Pulses → Add Pulse :

- **URL : `https://yaac.network/api/adhesion.php`** — l'URL complète du script,
  pas le domaine seul. Un Pulse pointé sur `www.yaac.network` livrerait ses
  événements à la page d'accueil, un fichier HTML statique : rien ne serait
  traité, et **aucune erreur ne serait visible côté Chariow**, puisque la page
  répond 200.
- Choisir entre `yaac.network` et `www.yaac.network`, et s'y tenir : si l'un
  redirige vers l'autre, la redirection peut transformer le POST en GET et
  vider le corps signé, ce qui ferait échouer la vérification de signature.
- Événements : `successful.sale`, `license.expired`, `license.revoked`
- Produit : « YAAC carte de membre »
- Copier le **Signing secret** de l'onglet Overview dans `config.php`

> **Chaque Pulse a son propre secret.** Si un Pulse a été recréé, c'est le
> secret du nouveau qu'il faut, et l'ancien Pulse est à supprimer — sinon il
> continue de poster dans le vide et pollue le journal Chariow avec des
> livraisons « réussies » qui n'ont rien traité.

### 5. Vérifier la syntaxe PHP sur le serveur

Ces fichiers n'ont **pas** pu être vérifiés en local : aucun interpréteur PHP
n'est installé sur la machine de développement. Avant la première vente :

```
for f in adhesion.php adhesion-init.php verify.php _lib.php _mail.php; do
  php -l "public_html/api/$f"
done
```

### 6. Test de bout en bout

1. Ouvrir `/devenir-membre/`, remplir le formulaire, valider.
2. Vérifier la table `candidature` : une ligne, `membre_id` à NULL.
3. Aller au bout du paiement Chariow.
4. Vérifier `webhook_livraison` : une ligne, `resultat = traite`.
5. Vérifier `membre` : une ligne, numéro `YAAC-2026-0NN`, et `candidature.membre_id`
   désormais renseigné.
6. Vérifier l'e-mail : objet, **6 000 FCFA**, lien carte, lien `/verify/`, lien
   WhatsApp.
7. Ouvrir `/verify/?id=<le numéro>` → « Carte valide ».
8. Rejouer le même Pulse depuis Chariow → réponse `deja_traite`, aucun second
   membre.
9. Resoumettre le formulaire avec le même e-mail → `deja_membre`, pas de
   second paiement proposé.

## Forme de la charge utile Chariow

Les noms de champs client (`first_name` / `last_name` / `name`…) sont lus de
façon tolérante, faute d'avoir pu observer une charge réelle.
`webhook_livraison.charge_utile` conserve le JSON brut de chaque livraison :
**le premier événement réel donnera la forme exacte**, et l'extraction pourra
être resserrée dessus.

Le `phone.country_code` est envoyé en ISO alpha-2 (`SN`, `BJ`…), lecture
littérale de « ISO country code » dans la documentation. À confirmer au premier
appel réel : si Chariow attend l'indicatif (`221`), c'est une ligne à changer
dans `adhesion-init.php`.

## Points restants

- **Les 16 membres sans numéro.** S'ils passent par `/devenir-membre/`, ils
  paieront 6 000 FCFA. S'ils en sont dispensés — ce sont des membres
  fondateurs — il faut soit un code de réduction Chariow à 100 %, soit un
  `INSERT` direct en base. Décision à prendre avant de les solliciter.
- **Le générateur de carte** remplit nom, rôle et date depuis les seuls
  paramètres d'URL, sans consultation ni signature : n'importe qui peut
  fabriquer un PDF d'apparence authentique. C'est `/verify/` qui fait foi.
  À reprendre pour qu'il interroge `/api/verify.php`.
- **Le badge est hors charte** : Segoe UI et `#1B5E30` / `#6AB04C` au lieu de
  Palanquin / Roboto et `#006738` / `#87BD43`.
- **`base` dans `astro.config.mjs`** vaut `/YAAC` pour GitHub Pages. À passer
  à `/` lors de la bascule sur Hostinger, sinon les points d'entrée seront
  appelés sous `/YAAC/api/…`.
- **Le numéro de membre n'est pas un secret.** Imprimé sur la carte, encodé
  dans le QR, séquentiel donc énumérable. `/verify/` n'expose délibérément que
  nom, rôle, statut et dates — jamais e-mail, téléphone ni montant. Cesser de
  le présenter comme un « PIN à conserver précieusement », ce que faisait
  l'ancien e-mail.
