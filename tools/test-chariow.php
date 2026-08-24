<?php
/**
 * YAAC — Sonde de l'appel Chariow.
 *
 * CE FICHIER N'EST PAS DÉPLOYÉ AUTOMATIQUEMENT. Il vit dans `tools/`, qui
 * n'est pas copié dans la build.
 *
 * Il rejoue exactement l'appel que fait `adhesion-init.php` et affiche la
 * réponse brute de Chariow — le seul moyen de distinguer une clé invalide
 * (401), un refus de validation (422) et un blocage réseau sortant.
 *
 * MODE D'EMPLOI
 *   1. Remplacez CLE ci-dessous par une valeur à vous.
 *   2. Téléversez dans public_html/.
 *   3. Ouvrez https://yaac.network/test-chariow.php?k=VOTRE_CLE
 *   4. SUPPRIMEZ LE FICHIER.
 *
 * Il ne crée pas d'adhésion : aucune écriture en base, aucun e-mail. Si
 * l'appel réussit, Chariow ouvre une session de paiement jamais réglée, qui
 * expirera d'elle-même.
 */

declare(strict_types=1);

const CLE = 'changez-moi-avant-de-televerser';

if (($_GET['k'] ?? '') !== CLE || CLE === 'changez-moi-avant-de-televerser') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/plain; charset=utf-8');

$chemin = __DIR__ . '/api/config.php';
if (!is_file($chemin)) {
    exit("ECHEC : api/config.php introuvable.\n");
}
$config = require $chemin;

$cle     = (string) ($config['chariow']['api_key'] ?? '');
$produit = (string) ($config['chariow']['product_id'] ?? '');

echo "=== Configuration lue ===\n";
printf("  api_key    : %s (%d caracteres)\n",
    $cle === '' ? 'VIDE' : substr($cle, 0, 8) . str_repeat('*', max(0, strlen($cle) - 8)),
    strlen($cle));
printf("  prefixe    : %s\n", str_starts_with($cle, 'sk_live_') ? 'sk_live_ (correct)' : 'INATTENDU — la cle API commence par sk_live_');
printf("  product_id : %s\n", $produit === '' ? 'VIDE' : $produit);
echo "\n";

// ---------------------------------------------------------------------
// 1. Le serveur peut-il seulement joindre l'API ?
// ---------------------------------------------------------------------
echo "=== Joignabilite de api.chariow.com ===\n";
$ip = gethostbyname('api.chariow.com');
printf("  resolution DNS : %s\n", $ip === 'api.chariow.com' ? 'ECHEC' : $ip);

$sock = @fsockopen('ssl://api.chariow.com', 443, $errno, $errstr, 8);
printf("  port 443       : %s\n", $sock ? 'ouvert' : "FERME ($errno $errstr)");
if ($sock) {
    fclose($sock);
}
echo "\n";

// ---------------------------------------------------------------------
// 2. Le meme appel que adhesion-init.php, avec des donnees de test.
// ---------------------------------------------------------------------
echo "=== Appel POST /v1/checkout ===\n";

$corps = json_encode([
    'product_id' => $produit,
    'email'      => 'sonde-technique@yaac.network',
    'first_name' => 'Sonde',
    'last_name'  => 'Technique',
    'phone'      => ['number' => '710322244', 'country_code' => 'SN'],
    'custom_metadata' => ['candidature' => 'sonde'],
    'redirect_url'    => 'https://yaac.network/fr/adhesion-confirmee/',
], JSON_UNESCAPED_UNICODE);

echo "  corps envoye :\n";
echo '    ' . str_replace("\n", "\n    ", json_encode(json_decode($corps, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n\n";

$ch = curl_init('https://api.chariow.com/v1/checkout');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $corps,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $cle,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);
$reponse = curl_exec($ch);
$http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err     = curl_error($ch);
curl_close($ch);

printf("  code HTTP  : %d\n", $http);
printf("  erreur cURL: %s\n", $err === '' ? 'aucune' : $err);
echo "  reponse    :\n";
$joli = json_decode((string) $reponse, true);
echo '    ' . str_replace("\n", "\n    ",
    $joli !== null
        ? json_encode($joli, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : substr((string) $reponse, 0, 2000)
) . "\n\n";

echo "=== Lecture ===\n";
echo match (true) {
    $err !== ''             => "  Le serveur ne joint pas Chariow. Blocage sortant chez Hostinger.\n",
    $http === 401           => "  Cle API refusee. Regenerez-la dans Chariow et remettez-la dans config.php.\n",
    $http === 404           => "  Produit introuvable pour cette cle. La cle appartient-elle a la bonne boutique ?\n",
    $http === 422           => "  Chariow refuse la charge utile. Le detail est dans la reponse ci-dessus.\n",
    $http >= 200 && $http < 300 => "  L'appel FONCTIONNE. Le probleme est donc ailleurs dans adhesion-init.php.\n",
    default                 => "  Reponse inattendue. Voir le corps ci-dessus.\n",
};

// ---------------------------------------------------------------------
// 3. Les dernieres lignes du journal applicatif.
// ---------------------------------------------------------------------
echo "\n=== Dernieres lignes du journal ===\n";
$log = (string) ($config['log'] ?? '');
if ($log === '' || !is_file($log)) {
    echo "  journal introuvable : $log\n";
} else {
    $lignes = array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -25);
    foreach ($lignes as $l) {
        echo '  ' . $l . "\n";
    }
}

echo "\nSUPPRIMEZ CE FICHIER une fois le diagnostic fait.\n";
