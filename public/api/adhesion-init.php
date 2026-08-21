<?php
/**
 * YAAC — Formulaire d'adhésion : enregistrement puis ouverture du paiement.
 *
 *   POST /api/adhesion-init.php   (depuis /devenir-membre/)
 *
 * Enchaînement : le formulaire écrit une candidature, puis demande à Chariow
 * une session de paiement portant le jeton de cette candidature en
 * `custom_metadata`. Chariow renvoie ce jeton dans le Pulse `successful.sale`,
 * ce qui relie le paiement au formulaire avec certitude.
 *
 * Le checkout hébergé n'accepte aucun paramètre d'URL : c'est la seule façon
 * d'attacher des données à une vente, et donc de ne pas dépendre du visiteur
 * qui ressaisirait par hasard le même e-mail des deux côtés.
 *
 * Une candidature sans `membre_id` est un abandon avant paiement. C'est
 * exactement ce que l'ancien tunnel rendait invisible.
 */

declare(strict_types=1);

require __DIR__ . '/_lib.php';

$config = yaac_config();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    yaac_json(405, ['erreur' => 'methode_non_autorisee']);
}

// Le formulaire est servi depuis le même domaine. Refuser une origine
// étrangère coûte une ligne et bloque la soumission automatisée la plus bête.
$origine = yaac_entete('Origin');
if ($origine !== '' && !in_array($origine, $config['cors_origines'], true)) {
    yaac_json(403, ['erreur' => 'origine_refusee']);
}

$entree = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($entree)) {
    yaac_json(400, ['erreur' => 'json_invalide']);
}

/** Champ texte nettoyé et borné. */
$texte = static function (string $cle, int $max) use ($entree): string {
    $v = $entree[$cle] ?? '';
    if (!is_scalar($v)) {
        return '';
    }
    // Neutralise les caractères de contrôle, sauf le saut de ligne des
    // champs libres : ils servent à injecter des en-têtes ailleurs.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string) $v)) ?? '';
    return mb_substr($v, 0, $max);
};

// Piège à robots : un champ masqué en CSS, que seul un automate remplit.
// On répond 200 pour ne pas lui apprendre qu'il a été repéré.
if ($texte('site_web', 200) !== '') {
    yaac_log('candidature_piege', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    yaac_json(200, ['statut' => 'ok']);
}

$prenom    = $texte('prenom', 120);
$nom       = $texte('nom', 120);
$email     = mb_strtolower($texte('email', 190));
$telephone = preg_replace('/\D+/', '', $texte('telephone', 40)) ?? '';
$tel_pays  = strtoupper($texte('tel_pays', 2));

$erreurs = [];
if ($prenom === '')                                   $erreurs['prenom']    = 'requis';
if ($nom === '')                                      $erreurs['nom']       = 'requis';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $erreurs['email'] = 'invalide';
if (strlen($telephone) < 6)                           $erreurs['telephone'] = 'invalide';
if (!preg_match('/^[A-Z]{2}$/', $tel_pays))           $erreurs['tel_pays']  = 'requis';
if (empty($entree['statuts']))                        $erreurs['statuts']   = 'requis';

$naissance = $texte('date_naissance', 10);
if ($naissance !== '') {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $naissance);
    if ($d === false || $d->format('Y-m-d') !== $naissance) {
        $erreurs['date_naissance'] = 'invalide';
    } elseif ($d > new DateTimeImmutable('-13 years')) {
        // Garde-fou : une adhésion payante engage juridiquement.
        $erreurs['date_naissance'] = 'trop_jeune';
    }
}

if ($erreurs) {
    yaac_json(422, ['erreur' => 'validation', 'champs' => $erreurs]);
}

$pdo = yaac_db();

// Déjà membre ? Inutile de le faire payer une deuxième fois.
$deja = $pdo->prepare('SELECT numero FROM membre WHERE email = ? LIMIT 1');
$deja->execute([$email]);
if (($numero = $deja->fetchColumn()) !== false) {
    yaac_json(409, ['erreur' => 'deja_membre', 'numero' => $numero]);
}

// Garde-fou de débit : au-delà de 5 candidatures par heure et par adresse IP,
// on arrête. Sans ça, le formulaire devient un robinet à appels facturés vers
// l'API Chariow.
$ip_binaire = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null;
if ($ip_binaire !== null) {
    $debit = $pdo->prepare(
        'SELECT COUNT(*) FROM candidature WHERE ip = ? AND cree_le > (NOW() - INTERVAL 1 HOUR)'
    );
    $debit->execute([$ip_binaire]);
    if ((int) $debit->fetchColumn() >= 5) {
        yaac_json(429, ['erreur' => 'trop_de_tentatives']);
    }
}

$jeton = bin2hex(random_bytes(16));

$pdo->prepare(
    'INSERT INTO candidature
       (jeton, prenom, nom, email, telephone, tel_pays, date_naissance,
        pays_origine, pays_residence, motivation, benevolat, source,
        statuts_acceptes, ip)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
)->execute([
    $jeton, $prenom, $nom, $email, $telephone, $tel_pays,
    $naissance !== '' ? $naissance : null,
    $texte('pays_origine', 80)   ?: null,
    $texte('pays_residence', 80) ?: null,
    $texte('motivation', 2000)   ?: null,
    $texte('benevolat', 255)     ?: null,
    $texte('source', 120)        ?: null,
    $ip_binaire,
]);

// ---------------------------------------------------------------------
// Session de paiement Chariow
// ---------------------------------------------------------------------
$corps = json_encode([
    'product_id' => $config['chariow']['product_id'],
    'email'      => $email,
    'first_name' => mb_substr($prenom, 0, 50),
    'last_name'  => mb_substr($nom, 0, 50),
    'phone'      => ['number' => $telephone, 'country_code' => $tel_pays],
    // Le jeton est la clé de rapprochement. 255 caractères max par valeur,
    // 10 clés max : on n'en utilise qu'une.
    'custom_metadata' => ['candidature' => $jeton],
    // Le visiteur revient dans la langue où il est parti.
    'redirect_url'    => $config['liens']['site']
        . ($texte('lang', 2) === 'en' ? '/en/membership-confirmed/' : '/fr/adhesion-confirmee/'),
    'customer_ip'     => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.chariow.com/v1/checkout');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $corps,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $config['chariow']['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);
$reponse = curl_exec($ch);
$http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err     = curl_error($ch);
curl_close($ch);

if ($reponse === false || $http < 200 || $http >= 300) {
    // La candidature reste en base : la personne n'est pas perdue, et l'appel
    // peut être rejoué. On ne renvoie pas le détail de Chariow au visiteur.
    yaac_log('checkout_echec', ['http' => $http, 'curl' => $err, 'reponse' => (string) $reponse]);
    yaac_json(502, ['erreur' => 'paiement_indisponible']);
}

$data = json_decode((string) $reponse, true)['data'] ?? [];
$url  = $data['payment']['checkout_url'] ?? null;

// `already_purchased` : Chariow connaît déjà cet e-mail sur ce produit, et ne
// renvoie donc pas d'URL. Le membre n'existe pourtant pas chez nous — soit le
// Pulse s'est perdu, soit il a payé avant la mise en service. À traiter à la
// main plutôt qu'à faire payer deux fois.
if (($data['step'] ?? '') === 'already_purchased') {
    yaac_log('checkout_deja_achete', ['email' => $email, 'jeton' => $jeton]);
    yaac_json(409, ['erreur' => 'deja_paye']);
}

if (!is_string($url) || $url === '') {
    yaac_log('checkout_sans_url', ['reponse' => (string) $reponse]);
    yaac_json(502, ['erreur' => 'paiement_indisponible']);
}

yaac_json(200, ['statut' => 'ok', 'checkout_url' => $url]);
