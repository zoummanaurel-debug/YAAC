<?php
/**
 * YAAC — Configuration des points d'entrée PHP.
 *
 * COPIEZ ce fichier en `config.php` sur le serveur et remplissez-le.
 * `config.php` n'est PAS versionné (voir .gitignore) : il contient des
 * secrets. Ne le collez jamais dans un ticket, un chat ou un commit.
 *
 * Sur Hostinger, ce dossier se retrouve dans public_html/api/ après
 * déploiement du dossier `dist/`.
 */

return [

    // ---------------------------------------------------------------------
    // Base de données (Hostinger → Bases de données MySQL)
    // ---------------------------------------------------------------------
    'db' => [
        'host'     => 'localhost',
        'name'     => 'CHANGEZ_MOI',
        'user'     => 'CHANGEZ_MOI',
        'password' => 'CHANGEZ_MOI',
    ],

    // ---------------------------------------------------------------------
    // Chariow
    //
    // `pulse_secret` est le secret de signature du Pulse, préfixé `whsec_`.
    // Il se trouve dans Automation → Pulses → votre Pulse → onglet Overview.
    // Ce n'est PAS la clé API : ne mettez pas la clé API ici, elle n'est pas
    // nécessaire pour recevoir les webhooks.
    // ---------------------------------------------------------------------
    'chariow' => [
        'pulse_secret' => 'whsec_CHANGEZ_MOI',

        // Cle API de la boutique, pour CREER une session de paiement
        // (POST https://api.chariow.com/v1/checkout). Prefixee sk_live_.
        // Distincte du secret de signature ci-dessus.
        'api_key'      => 'sk_live_CHANGEZ_MOI',

        // Identifiant du produit « Adhésion YAAC ». Laissez vide pour
        // accepter toutes les ventes de la boutique ; renseignez-le si la
        // boutique vend autre chose que l'adhésion, sinon un achat sans
        // rapport créerait un membre.
        'product_id'   => 'prd_2tljqv9n',
    ],

    // ---------------------------------------------------------------------
    // Adhésion
    // ---------------------------------------------------------------------
    'adhesion' => [
        // Affiché dans l'e-mail. Décidé le 21/08/2026 : 6 000 FCFA, sans
        // cotisation annuelle. L'ancien modèle d'e-mail annonçait à tort
        // « 5 000 FCFA + 15 000 FCFA/an ».
        'montant_affiche' => '6 000 FCFA',

        // Durée de validité de la carte, en années.
        'validite_annees' => 5,

        // Rôle par défaut des nouveaux membres.
        'role_defaut'     => 'Membre',
    ],

    // ---------------------------------------------------------------------
    // Liens envoyés au membre
    // ---------------------------------------------------------------------
    'liens' => [
        // Racine publique du site, SANS barre oblique finale.
        'site'     => 'https://yaac.network',

        // Générateur de carte. Le membre y arrive pré-rempli.
        'badge'    => 'https://yaac.network/badge/',

        // Page de vérification. ATTENTION : cette valeur est gravée dans les
        // QR codes des cartes DÉJÀ IMPRIMÉES. Ne la changez pas sans
        // réimprimer les cartes.
        'verify'   => 'https://yaac.network/verify/',

        // Lien d'invitation au groupe WhatsApp. Meta interdit d'ajouter
        // quelqu'un à un groupe par API : joindre le lien à l'e-mail est la
        // seule parade. Laissez vide pour omettre le bloc de l'e-mail.
        'whatsapp' => '',
    ],

    // ---------------------------------------------------------------------
    // Envoi de l'e-mail
    //
    // 'transport' => 'smtp' (recommandé) ou 'mail' (fonction mail() de PHP,
    // dépend du MTA local de l'hébergeur et finit souvent en indésirables).
    // ---------------------------------------------------------------------
    'mail' => [
        'transport'   => 'smtp',

        'from_email'  => 'communication@yaac.network',
        'from_nom'    => 'YAAC — Youth Alliance for Agroecology and Climate',
        'reply_to'    => 'communication@yaac.network',

        // Copie cachée vers l'équipe, pour garder une trace des envois.
        // Laissez vide pour désactiver.
        'bcc'         => '',

        'smtp' => [
            'host'       => 'smtp.hostinger.com',
            'port'       => 465,
            // 'ssl' pour le port 465, 'tls' pour le port 587.
            'chiffrement'=> 'ssl',
            'user'       => 'communication@yaac.network',
            'password'   => 'CHANGEZ_MOI',
            'timeout'    => 20,
        ],
    ],

    // ---------------------------------------------------------------------
    // Divers
    // ---------------------------------------------------------------------
    // Origines autorisées à appeler verify.php depuis un navigateur.
    // La page /verify/ du site est servie depuis le même domaine, donc cette
    // liste ne sert qu'aux outils tiers éventuels.
    // Secret de signature des jetons de verification (voir yaac_jeton_carte).
    // Genererz-le UNE fois et ne le changez plus : le modifier invaliderait le
    // QR code de toutes les cartes deja imprimees.
    //   php -r "echo bin2hex(random_bytes(32));"
    'verification_secret' => 'CHANGEZ_MOI',

    'cors_origines' => ['https://yaac.network'],

    // Fichier journal. Doit être hors de public_html/ : sinon il est
    // téléchargeable par n'importe qui et expose les données des membres.
    'log' => __DIR__ . '/../../yaac-api.log',
];
