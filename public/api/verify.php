<?php
/**
 * YAAC — Vérification d'une carte de membre.
 *
 *   GET /api/verify.php?id=YAAC-2026-0042
 *
 * Remplace le tableau MEMBERS_DB codé en dur dans l'ancienne page, qui ne
 * connaissait que 5 membres sur 34 et demandait une modification du code
 * à chaque adhésion.
 *
 * Ce que cette réponse expose est DÉLIBÉRÉMENT limité : nom, rôle, statut et
 * dates. Jamais l'e-mail, le téléphone, le pays ni le montant payé. Les
 * numéros sont séquentiels donc énumérables : tout ce qui sort d'ici doit
 * pouvoir être lu par un inconnu qui compte de 1 à 34.
 */

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$config = yaac_config();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    yaac_json(405, ['erreur' => 'methode_non_autorisee']);
}

$origine = yaac_entete('Origin');
if ($origine !== '' && in_array($origine, $config['cors_origines'], true)) {
    header('Access-Control-Allow-Origin: ' . $origine);
    header('Vary: Origin');
}

// La page est publique et la réponse change quand un statut change :
// un cache court soulage la base sans jamais servir un badge périmé
// pendant plus d'une minute.
header('Cache-Control: public, max-age=60');

$numero = strtoupper(trim((string) ($_GET['id'] ?? '')));

if ($numero === '') {
    yaac_json(400, ['trouve' => false, 'erreur' => 'numero_absent']);
}

// Format avant toute requête : bloque le bruit et rend l'énumération aveugle
// moins commode.
//
// Deux longueurs sont acceptées à dessein. Les numéros historiques du fichier
// « Membres.xlsx » ont trois chiffres (YAAC-2026-001) tandis que le guide
// Power Automate et l'ancienne page de vérification en produisaient quatre
// (YAAC-2024-0001). Les deux formes existent donc potentiellement sur des
// cartes déjà imprimées : refuser l'une reviendrait à casser des QR codes
// physiques.
if (!preg_match('/^YAAC-\d{4}-\d{3,4}$/', $numero)) {
    yaac_json(200, ['trouve' => false, 'erreur' => 'format_invalide']);
}

$pdo = yaac_db();

// ---------------------------------------------------------------------
// Limitation de débit
//
// Deux plafonds distincts, et l'écart entre eux est le cœur de la mesure :
//
//   avec jeton  — 300/h : quelqu'un qui scanne de vraies cartes. Un stand à
//                 un événement peut en passer beaucoup, et le brider serait
//                 casser l'usage même du QR code.
//   sans jeton  —  20/h : quelqu'un qui tape des numéros. Vingt suffisent
//                 largement à lever un doute ; mille servent à cartographier.
//
// Le compteur est incrémenté AVANT la lecture : un rejet ne doit rien coûter
// de plus qu'une requête d'écriture.
// ---------------------------------------------------------------------
$jeton_fourni = (string) ($_GET['c'] ?? '');
$ip = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null;

if ($ip !== null) {
    $colonne = $jeton_fourni !== '' ? 'avec_jeton' : 'sans_jeton';
    $plafond = $jeton_fourni !== '' ? 300 : 20;

    // La limitation ne doit jamais empêcher une vérification d'aboutir. Si sa
    // table manque — schéma pas encore importé — on journalise et on continue,
    // plutôt que de renvoyer un 500 muet sur une page publique.
    try {
        $pdo->prepare(
            "INSERT INTO verif_debit (ip, fenetre, $colonne)
             VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), 1)
             ON DUPLICATE KEY UPDATE $colonne = $colonne + 1"
        )->execute([$ip]);

        $compte = $pdo->prepare(
            "SELECT $colonne FROM verif_debit
              WHERE ip = ? AND fenetre = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00')"
        );
        $compte->execute([$ip]);

        if ((int) $compte->fetchColumn() > $plafond) {
            header('Retry-After: 3600');
            yaac_json(429, ['trouve' => false, 'erreur' => 'trop_de_requetes']);
        }

        // Purge opportuniste, une fois sur cent : pas de tâche planifiée à
        // maintenir sur un hébergement mutualisé.
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM verif_debit WHERE fenetre < (NOW() - INTERVAL 2 DAY)');
        }
    } catch (PDOException $e) {
        yaac_log('debit_indisponible', ['message' => $e->getMessage()]);
    }
}

$requete = $pdo->prepare(
    'SELECT numero, prenom, nom, role, date_adhesion, date_expiration, statut
       FROM membre
      WHERE numero = ?
      LIMIT 1'
);
try {
    $requete->execute([$numero]);
} catch (PDOException $e) {
    // Table `membre` absente : le schéma n'a pas été importé. Le message est
    // explicite dans le journal, neutre dans la réponse.
    yaac_log('verify_lecture_impossible', ['message' => $e->getMessage()]);
    yaac_json(503, ['trouve' => false, 'erreur' => 'registre_indisponible']);
}
$membre = $requete->fetch();

// Deuxième essai avec l'autre remplissage : une carte portant YAAC-2026-001
// doit trouver un enregistrement stocké YAAC-2026-0001, et réciproquement.
if ($membre === false && preg_match('/^(YAAC-\d{4})-(\d{3,4})$/', $numero, $m)) {
    $variante = strlen($m[2]) === 3
        ? $m[1] . '-0' . $m[2]
        : $m[1] . '-' . ltrim($m[2], '0');

    $requete->execute([$variante]);
    $membre = $requete->fetch();
}

if ($membre === false) {
    yaac_json(200, ['trouve' => false]);
}

// ---------------------------------------------------------------------
// Divulgation graduée
//
// Avec le jeton du QR code : la fiche complète — c'est quelqu'un qui a la
// carte en main, exactement le cas d'usage.
// Sans le jeton : l'existence et le statut, sans aucune donnée nominative.
// Assez pour lever un doute, inutile pour constituer un annuaire.
// ---------------------------------------------------------------------
$complet = yaac_jeton_valide($membre['numero'], $jeton_fourni);

// Le statut stocké fait foi (piloté par les événements de licence Chariow),
// mais une date d'expiration dépassée prime : si un webhook `license.expired`
// s'est perdu, la carte ne doit pas rester « valide » indéfiniment.
$expire  = new DateTimeImmutable($membre['date_expiration']) < new DateTimeImmutable('today');
$statut  = ($membre['statut'] === 'actif' && $expire) ? 'expire' : $membre['statut'];
$valide  = $statut === 'actif';

$reponse = [
    'trouve'  => true,
    'valide'  => $valide,
    'statut'  => $statut,
    'complet' => $complet,
];

if ($complet) {
    // La date d'adhésion n'est volontairement PAS renvoyée : elle n'aide en
    // rien à authentifier une carte et n'ajoute que de la donnée personnelle.
    $reponse += [
        'numero'          => $membre['numero'],
        'prenom'          => $membre['prenom'],
        'nom'             => $membre['nom'],
        'role'            => $membre['role'],
        'date_expiration' => $membre['date_expiration'],
    ];
}

yaac_json(200, $reponse);
