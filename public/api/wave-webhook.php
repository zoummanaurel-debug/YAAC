<?php
/**
 * YAAC — Réception des webhooks Wave.
 *
 * À déclarer dans Wave Business → Développeurs → Webhooks :
 *   URL        https://yaac.network/api/wave-webhook.php
 *   Sécurité   Signing Secret  (à recopier dans config.php : wave.webhook_secret)
 *   Événement  checkout.session.completed
 *
 * Même règle que pour Chariow : c'est le PAIEMENT qui déclenche l'adhésion.
 * Le jeton de candidature revient dans `client_reference`, ce qui relie le
 * paiement au formulaire avec certitude. Le membre reçoit ensuite le même
 * e-mail de bienvenue et de carte que par Chariow.
 */

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_mail.php';
require __DIR__ . '/_membre.php';
require __DIR__ . '/_wave.php';

$config = yaac_config();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    yaac_json(405, ['erreur' => 'methode_non_autorisee']);
}

// Signature d'abord, sur le corps brut, avant tout json_decode.
$corps = file_get_contents('php://input') ?: '';
if (!yaac_signature_wave_valide($corps, yaac_entete('Wave-Signature'), (string) ($config['wave']['webhook_secret'] ?? ''))) {
    yaac_log('wave_signature_invalide', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    yaac_json(401, ['erreur' => 'signature_invalide']);
}

$evt = json_decode($corps, true);
if (!is_array($evt) || !is_string($evt['id'] ?? null) || $evt['id'] === '') {
    yaac_json(400, ['erreur' => 'json_invalide']);
}

$type   = (string) ($evt['type'] ?? '');
$data   = is_array($evt['data'] ?? null) ? $evt['data'] : [];
$pdo    = yaac_db();
// Préfixé pour ne jamais entrer en collision avec un identifiant Chariow
// dans le même journal.
$livraison = 'wave:' . $evt['id'];

// Idempotence : Wave peut livrer deux fois le même événement. Un échec
// précédent reste rejouable.
try {
    $pdo->prepare(
        'INSERT INTO webhook_livraison (delivery_id, evenement, resultat, charge_utile) VALUES (?, ?, ?, ?)'
    )->execute([$livraison, $type, 'erreur', $corps]);
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') {
        throw $e;
    }
    $prec = $pdo->prepare('SELECT resultat FROM webhook_livraison WHERE delivery_id = ?');
    $prec->execute([$livraison]);
    if (in_array((string) $prec->fetchColumn(), ['traite', 'ignore'], true)) {
        yaac_json(200, ['statut' => 'deja_traite']);
    }
}

$terminer = static function (string $resultat, string $detail, int $code, array $reponse) use ($pdo, $livraison): void {
    $pdo->prepare('UPDATE webhook_livraison SET resultat = ?, detail = ? WHERE delivery_id = ?')
        ->execute([$resultat, $detail, $livraison]);
    yaac_json($code, $reponse);
};

if ($type !== 'checkout.session.completed') {
    $terminer('ignore', 'événement non traité : ' . $type, 200, ['statut' => 'ignore']);
}

if (($data['payment_status'] ?? '') !== 'succeeded') {
    $terminer('ignore', 'paiement non abouti', 200, ['statut' => 'ignore']);
}

// Contrôle du montant : la session est créée par le serveur, mais une
// session d'un autre usage sur le même compte ne doit pas créer de membre.
$montant = (int) round((float) ($data['amount'] ?? 0));
if (($data['currency'] ?? '') !== 'XOF' || $montant < yaac_montant_adhesion($config)) {
    yaac_log('wave_montant_inattendu', ['montant' => $data['amount'] ?? null, 'devise' => $data['currency'] ?? null]);
    $terminer('ignore', 'montant ou devise hors adhésion', 200, ['statut' => 'ignore']);
}

$jeton = (string) ($data['client_reference'] ?? '');
$req = $pdo->prepare('SELECT * FROM candidature WHERE jeton = ? LIMIT 1');
$req->execute([$jeton]);
$c = $req->fetch();

if (!$c) {
    yaac_log('wave_candidature_introuvable', ['client_reference' => $jeton, 'session' => $data['id'] ?? null]);
    $terminer('ignore', 'candidature introuvable', 200, ['statut' => 'ignore']);
}
if ($c['membre_id'] !== null) {
    $terminer('ignore', 'déjà membre', 200, ['statut' => 'deja_membre']);
}

$transaction = (string) ($data['transaction_id'] ?? $data['id'] ?? $jeton);

try {
    $res = yaac_creer_membre_manuel($pdo, $c, 'wave', $config, $transaction);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        yaac_log('wave_doublon', ['email' => $c['email'], 'transaction' => $transaction]);
        $terminer('ignore', 'membre déjà enregistré', 200, ['statut' => 'deja_membre']);
    }
    yaac_log('wave_insertion_echouee', ['message' => $e->getMessage()]);
    // 500 : Wave réessaiera, et le verrou d'idempotence autorise le rejeu.
    $terminer('erreur', 'insertion échouée', 500, ['erreur' => 'insertion_echouee']);
}

$terminer(
    'traite',
    'membre ' . $res['numero'] . ($res['carte_envoyee'] ? '' : ' — e-mail en échec'),
    200,
    ['statut' => 'ok', 'numero' => $res['numero'], 'carte_envoyee' => $res['carte_envoyee']]
);
