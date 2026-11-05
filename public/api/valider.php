<?php
/**
 * YAAC — Validation d'un paiement Wave ou Orange Money par le trésorier.
 *
 *   GET  /api/valider.php?j=…&m=…&r=…&s=…   → page de confirmation
 *   POST /api/valider.php (mêmes champs)     → crée le membre, envoie la carte
 *
 * Le lien arrive par e-mail (paiement-declare.php). Le GET ne fait RIEN :
 * les messageries ouvrent souvent les liens d'un e-mail pour les analyser,
 * et un GET qui créerait le membre validerait des paiements jamais vérifiés.
 */

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_mail.php';
require __DIR__ . '/_membre.php';

$config = yaac_config();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Affiche une page minimale et s'arrête. */
$page = static function (int $code, string $titre, string $corps) use ($h): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . $h($titre) . ' — YAAC</title>'
        . '<style>body{font-family:system-ui,Arial,sans-serif;color:#123524;background:#f6f8f5;margin:0;padding:24px 16px}'
        . 'main{max-width:560px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;box-shadow:0 1px 3px #0002}'
        . 'h1{font-size:1.4rem;margin:0 0 16px}dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px}'
        . 'dt{color:#555}dd{margin:0;font-weight:600;overflow-wrap:anywhere}'
        . 'button{background:#006738;color:#fff;border:0;border-radius:6px;padding:14px 20px;font:inherit;font-weight:700;cursor:pointer;width:100%}'
        . '.note{color:#555;font-size:.9rem}.ok{color:#006738}.ko{color:#8f2b23}</style>'
        . '</head><body><main><h1>' . $h($titre) . '</h1>' . $corps . '</main></body></html>';
    exit;
};

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$src     = $methode === 'POST' ? $_POST : $_GET;

$jeton     = is_string($src['j'] ?? null) ? $src['j'] : '';
$mode      = is_string($src['m'] ?? null) ? $src['m'] : '';
$reference = yaac_reference_propre(is_string($src['r'] ?? null) ? $src['r'] : '');
$signature = is_string($src['s'] ?? null) ? $src['s'] : '';

if (
    !preg_match('/^[0-9a-f]{32}$/', $jeton)
    || !isset(YAAC_PAIEMENTS_MANUELS[$mode])
    || $reference === ''
    || !yaac_signature_validation_ok($jeton, $mode, $reference, $signature)
) {
    yaac_log('validation_lien_invalide', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    $page(403, 'Lien invalide', '<p class="ko">Ce lien de validation est incomplet ou a été modifié.</p>');
}

$pdo = yaac_db();
$req = $pdo->prepare('SELECT * FROM candidature WHERE jeton = ? LIMIT 1');
$req->execute([$jeton]);
$c = $req->fetch();

if (!$c) {
    $page(404, 'Demande introuvable', '<p class="ko">Cette demande d\'adhésion n\'existe plus.</p>');
}

if ($c['membre_id'] !== null) {
    $num = $pdo->prepare('SELECT numero FROM membre WHERE id = ?');
    $num->execute([(int) $c['membre_id']]);
    $page(200, 'Déjà validée', '<p class="ok">Cette adhésion est déjà enregistrée sous le numéro <strong>'
        . $h((string) $num->fetchColumn()) . '</strong>. Rien à faire.</p>');
}

$libelle = YAAC_PAIEMENTS_MANUELS[$mode];
$montant = number_format(yaac_montant_adhesion($config), 0, ',', ' ') . ' FCFA';
$nom     = trim($c['prenom'] . ' ' . $c['nom']);

if ($methode !== 'POST') {
    $champs = '';
    foreach (['j' => $jeton, 'm' => $mode, 'r' => $reference, 's' => $signature] as $k => $v) {
        $champs .= '<input type="hidden" name="' . $k . '" value="' . $h($v) . '">';
    }
    $page(200, 'Valider un paiement ' . $libelle,
        '<dl>'
        . '<dt>Nom</dt><dd>' . $h($nom) . '</dd>'
        . '<dt>E-mail</dt><dd>' . $h($c['email']) . '</dd>'
        . '<dt>Téléphone</dt><dd>' . $h(trim(($c['tel_pays'] ?? '') . ' ' . ($c['telephone'] ?? ''))) . '</dd>'
        . '<dt>Montant</dt><dd>' . $h($montant) . '</dd>'
        . '<dt>Référence</dt><dd>' . $h($reference) . '</dd>'
        . '</dl>'
        . '<p><strong>Vérifiez sur le compte ' . $h($libelle) . '</strong> que ' . $h($montant)
        . ' sont bien arrivés avec cette référence avant de valider.</p>'
        . '<form method="post" action="">' . $champs
        . '<button type="submit">Le paiement est arrivé — valider et envoyer la carte</button></form>'
        . '<p class="note">La validation attribue un numéro YAAC et envoie la carte de membre par e-mail. '
        . 'Si le paiement n\'est pas arrivé, fermez simplement cette page.</p>'
    );
}

// POST : création du membre.
try {
    $res = yaac_creer_membre_manuel($pdo, $c, $mode, $reference, $config);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        yaac_log('validation_doublon', ['email' => $c['email'], 'reference' => $reference]);
        $page(409, 'Validation impossible',
            '<p class="ko">Un membre existe déjà avec cet e-mail, ou cette référence de transaction '
            . 'a déjà servi à valider une autre adhésion. Vérifiez dans la base avant d\'aller plus loin.</p>');
    }
    yaac_log('validation_echec', ['message' => $e->getMessage()]);
    $page(500, 'Erreur', '<p class="ko">L\'enregistrement a échoué. Rien n\'a été créé ; réessayez plus tard.</p>');
}

$page(200, 'Adhésion validée',
    '<p class="ok">Membre enregistré sous le numéro <strong>' . $h($res['numero']) . '</strong>.</p>'
    . ($res['carte_envoyee']
        ? '<p>La carte de membre est partie à ' . $h($c['email']) . '.</p>'
        : '<p class="ko">Le membre est bien enregistré, mais l\'e-mail de carte n\'est pas parti : '
          . $h((string) $res['erreur']) . '. Il faudra le renvoyer à la main.</p>')
);
