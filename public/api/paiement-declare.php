<?php
/**
 * YAAC — Déclaration d'un paiement Wave ou Orange Money.
 *
 *   POST /api/paiement-declare.php   (depuis /devenir-membre/)
 *   { "jeton": "…", "mode": "wave"|"om", "reference": "…" }
 *
 * Le visiteur a payé hors du site et saisit la référence de sa transaction.
 * On ne crée PAS de membre ici : rien ne prouve encore que l'argent est
 * arrivé. On prévient l'équipe par e-mail, avec un lien signé qui, après
 * vérification sur le compte Wave Business ou Orange Money, crée le membre.
 * Voir _membre.php.
 */

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_mail.php';
require __DIR__ . '/_membre.php';

$config = yaac_config();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    yaac_json(405, ['erreur' => 'methode_non_autorisee']);
}

$origine = yaac_entete('Origin');
if ($origine !== '' && !in_array($origine, $config['cors_origines'], true)) {
    yaac_json(403, ['erreur' => 'origine_refusee']);
}

$entree = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($entree)) {
    yaac_json(400, ['erreur' => 'json_invalide']);
}

$jeton     = is_string($entree['jeton'] ?? null) ? $entree['jeton'] : '';
$mode      = is_string($entree['mode'] ?? null) ? $entree['mode'] : '';
$reference = yaac_reference_propre(is_string($entree['reference'] ?? null) ? $entree['reference'] : '');

if (!preg_match('/^[0-9a-f]{32}$/', $jeton) || !isset(YAAC_PAIEMENTS_MANUELS[$mode])) {
    yaac_json(400, ['erreur' => 'requete_invalide']);
}
if ($reference === '') {
    yaac_json(422, ['erreur' => 'validation', 'champs' => ['reference' => 'invalide']]);
}

$pdo = yaac_db();

// Fenêtre de 30 jours : au-delà, un jeton qui traîne dans un historique de
// navigateur ne doit plus permettre d'écrire à l'équipe.
$req = $pdo->prepare(
    'SELECT * FROM candidature
      WHERE jeton = ? AND cree_le > (NOW() - INTERVAL 30 DAY) LIMIT 1'
);
$req->execute([$jeton]);
$c = $req->fetch();

if (!$c) {
    yaac_json(404, ['erreur' => 'candidature_introuvable']);
}
if ($c['membre_id'] !== null) {
    yaac_json(409, ['erreur' => 'deja_membre']);
}

$libelle = YAAC_PAIEMENTS_MANUELS[$mode];
$montant = number_format(yaac_montant_adhesion($config), 0, ',', ' ') . ' FCFA';
$lien    = yaac_lien_validation($jeton, $mode, $reference, $config);
$nom     = trim($c['prenom'] . ' ' . $c['nom']);
$tel     = trim(($c['tel_pays'] ?? '') . ' ' . ($c['telephone'] ?? ''));

// Tout est journalisé AVANT l'envoi : si le SMTP tombe, la déclaration n'est
// pas perdue et le lien peut être retrouvé dans le journal.
yaac_log('paiement_declare', [
    'email' => $c['email'], 'mode' => $mode, 'reference' => $reference, 'lien' => $lien,
]);

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$sujet = sprintf('[YAAC] Paiement %s à vérifier — %s (%s)', $libelle, $nom, $reference);

$html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#123524;max-width:560px">'
    . '<p>Une demande d\'adhésion a été réglée par <strong>' . $h($libelle) . '</strong>.</p>'
    . '<table style="border-collapse:collapse;margin:16px 0">'
    . '<tr><td style="padding:4px 12px 4px 0;color:#555">Nom</td><td><strong>' . $h($nom) . '</strong></td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#555">E-mail</td><td>' . $h($c['email']) . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#555">Téléphone</td><td>' . $h($tel) . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#555">Montant attendu</td><td>' . $h($montant) . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0;color:#555">Référence</td><td><strong>' . $h($reference) . '</strong></td></tr>'
    . '</table>'
    . '<p><strong>Avant de valider</strong>, vérifiez sur le compte ' . $h($libelle)
    . ' que ' . $h($montant) . ' sont bien arrivés avec cette référence.</p>'
    . '<p style="margin:24px 0"><a href="' . $h($lien) . '" style="background:#006738;color:#fff;'
    . 'padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Ouvrir la validation</a></p>'
    . '<p style="color:#555;font-size:13px">Le lien ouvre une page de confirmation : rien n\'est créé tant que '
    . 'vous n\'avez pas cliqué sur « Valider » sur cette page. Si le paiement n\'est pas arrivé, ne faites rien.</p>'
    . '</div>';

$texte = "Une demande d'adhésion a été réglée par $libelle.\n\n"
    . "Nom : $nom\nE-mail : {$c['email']}\nTéléphone : $tel\n"
    . "Montant attendu : $montant\nRéférence : $reference\n\n"
    . "Avant de valider, vérifiez sur le compte $libelle que le montant est bien arrivé.\n\n"
    . "Validation : $lien\n\n"
    . "Le lien ouvre une page de confirmation ; rien n'est créé avant le clic sur « Valider ».\n";

$destinataire = (string) ($config['paiement']['notifier'] ?? $config['mail']['reply_to']);

try {
    yaac_envoyer($destinataire, $sujet, $html, $texte, $config);
} catch (Throwable $e) {
    yaac_log('paiement_declare_mail_echec', ['message' => $e->getMessage(), 'reference' => $reference]);
    yaac_json(502, ['erreur' => 'notification_echec']);
}

yaac_json(200, ['statut' => 'ok']);
