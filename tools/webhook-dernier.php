<?php
/**
 * YAAC — Que reçoit réellement le webhook ?
 *
 * CE FICHIER N'EST PAS DÉPLOYÉ AUTOMATIQUEMENT. Il vit dans `tools/`, qui
 * n'est pas copié dans la build.
 *
 * `adhesion.php` lit `product.id`, `customer.email`, `sale.custom_metadata`
 * et `license.key` — des noms choisis comme les plus probables, sans qu'une
 * charge réelle ait jamais été observée. Si Chariow les nomme autrement, la
 * vente est rejetée en silence et aucun membre n'est créé.
 *
 * Cet outil affiche la charge brute conservée dans `webhook_livraison`, la
 * compare aux champs attendus, et dit lequel manque.
 *
 * MODE D'EMPLOI
 *   1. Remplacez CLE par une valeur à vous.
 *   2. Téléversez dans public_html/.
 *   3. Ouvrez https://yaac.network/webhook-dernier.php?k=VOTRE_CLE
 *   4. SUPPRIMEZ LE FICHIER.
 *
 * Lecture seule : aucune écriture, aucun envoi.
 */

declare(strict_types=1);

const CLE = 'changez-moi-avant-de-televerser';

if (($_GET['k'] ?? '') !== CLE || CLE === 'changez-moi-avant-de-televerser') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/api/_lib.php';
$pdo = yaac_db();

echo "=== Dernieres livraisons recues ===\n";
$lignes = $pdo->query(
    'SELECT recu_le, evenement, resultat, detail
       FROM webhook_livraison ORDER BY recu_le DESC LIMIT 8'
)->fetchAll();

if (!$lignes) {
    echo "  AUCUNE LIGNE.\n";
    echo "  Le Pulse n'est jamais arrive jusqu'ici. Verifiez dans Chariow que\n";
    echo "  l'onglet Livraisons du Pulse montre bien une tentative, et que son\n";
    echo "  URL est https://yaac.network/api/adhesion.php\n";
} else {
    foreach ($lignes as $l) {
        printf("  %s  %-18s %-8s %s\n",
            $l['recu_le'], $l['evenement'] ?? '?', $l['resultat'], (string) ($l['detail'] ?? ''));
    }
}

echo "\n=== Etat du registre ===\n";
printf("  membres en base : %d\n", (int) $pdo->query('SELECT COUNT(*) FROM membre')->fetchColumn());
printf("  compteur        : %d\n", (int) $pdo->query('SELECT dernier FROM compteur_membre WHERE id = 1')->fetchColumn());
printf("  candidatures sans membre : %d\n",
    (int) $pdo->query('SELECT COUNT(*) FROM candidature WHERE membre_id IS NULL')->fetchColumn());

// ---------------------------------------------------------------------
// La charge brute de la derniere vente, et les champs que le code y cherche.
// ---------------------------------------------------------------------
$brut = (string) $pdo->query(
    "SELECT charge_utile FROM webhook_livraison
      WHERE evenement = 'successful.sale' ORDER BY recu_le DESC LIMIT 1"
)->fetchColumn();

if ($brut === '') {
    echo "\n=== Aucune vente recue ===\n";
    echo "  Rien a analyser tant qu'un `successful.sale` n'est pas arrive.\n";
    exit;
}

$charge = json_decode($brut, true) ?: [];
$config = yaac_config();

echo "\n=== Ce que le code cherche, et ce qui est present ===\n";

$sonde = static function (string $libelle, array $source, array $cles) {
    foreach ($cles as $c) {
        if (isset($source[$c]) && is_scalar($source[$c]) && (string) $source[$c] !== '') {
            printf("  OK      %-26s trouve dans « %s »\n", $libelle, $c);
            return (string) $source[$c];
        }
    }
    printf("  ABSENT  %-26s aucune des cles : %s\n", $libelle, implode(', ', $cles));
    printf("          cles reellement presentes : %s\n",
        $source ? implode(', ', array_keys($source)) : '(objet vide ou absent)');
    return '';
};

printf("  evenement : %s\n\n", (string) ($charge['event'] ?? '(absent)'));
printf("  cles a la racine : %s\n\n", implode(', ', array_keys($charge)));

$vente   = is_array($charge['sale'] ?? null) ? $charge['sale'] : [];
$client  = is_array($charge['customer'] ?? null) ? $charge['customer'] : [];
$produit = is_array($charge['product'] ?? null) ? $charge['product'] : [];

$id_produit = $sonde('product.id', $produit, ['id']);
$attendu    = (string) ($config['chariow']['product_id'] ?? '');
printf("          attendu par config.php : %s\n", $attendu);
printf("          %s\n", $id_produit === $attendu
    ? 'CORRESPOND'
    : '*** NE CORRESPOND PAS : la vente est rejetee ici ***');

$sonde('customer.email', $client, ['email', 'customer_email']);
$sonde('customer.first_name', $client, ['first_name', 'firstname', 'prenom', 'given_name']);
$sonde('customer.last_name', $client, ['last_name', 'lastname', 'nom', 'family_name', 'surname']);

$meta = is_array($vente['custom_metadata'] ?? null) ? $vente['custom_metadata'] : [];
$sonde('sale.custom_metadata', $meta, ['candidature']);

$licence = is_array($charge['license'] ?? null) ? $charge['license'] : [];
$sonde('license.key', $licence, ['id', 'key', 'license_key']);

echo "\n=== Charge brute ===\n";
echo preg_replace('/^/m', '  ',
    json_encode($charge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";

echo "\n=== Dernieres lignes du journal ===\n";
$log = (string) ($config['log'] ?? '');
if (is_file($log)) {
    foreach (array_slice(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -20) as $l) {
        echo '  ' . $l . "\n";
    }
} else {
    echo "  journal introuvable : $log\n";
}

echo "\nSUPPRIMEZ CE FICHIER une fois le diagnostic fait.\n";
