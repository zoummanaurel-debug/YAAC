<?php
/**
 * YAAC — Diagnostic d'installation Hostinger.
 *
 * CE FICHIER N'EST PAS DÉPLOYÉ AUTOMATIQUEMENT. Il vit dans `tools/`, qui
 * n'est pas copié dans la build : impossible de le publier par accident.
 *
 * MODE D'EMPLOI
 *   1. Téléversez-le dans public_html/ via le Gestionnaire de fichiers.
 *   2. Ouvrez https://yaac.network/diagnostic.php?k=VOTRE_CLE
 *      (la clé est celle définie ci-dessous, à changer avant l'envoi).
 *   3. Lisez le rapport, corrigez ce qui est en ROUGE.
 *   4. SUPPRIMEZ LE FICHIER. Il révèle la configuration du serveur.
 *
 * Il ne modifie rien : il lit, il teste, il rapporte.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Changez cette valeur avant de téléverser. Sans elle, la page reste muette
// même si quelqu'un tombe dessus.
// ---------------------------------------------------------------------
const CLE = 'changez-moi-avant-de-televerser';

if (($_GET['k'] ?? '') !== CLE || CLE === 'changez-moi-avant-de-televerser') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/html; charset=utf-8');

$lignes = [];
function verdict(string $quoi, bool $ok, string $detail = '', bool $bloquant = true): void
{
    global $lignes;
    $lignes[] = [$quoi, $ok, $detail, $bloquant];
}

// ---------------------------------------------------------------------
// 1. PHP
// ---------------------------------------------------------------------
verdict('PHP 8.1 ou plus', PHP_VERSION_ID >= 80100, 'version détectée : ' . PHP_VERSION);

foreach (['pdo_mysql' => true, 'curl' => true, 'mbstring' => true, 'openssl' => true, 'json' => true] as $ext => $obligatoire) {
    verdict("Extension $ext", extension_loaded($ext), '', $obligatoire);
}

verdict(
    'HTTPS actif',
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    'Chariow refuse les webhooks en HTTP'
);

// ---------------------------------------------------------------------
// 2. Fichiers du site
// ---------------------------------------------------------------------
$racine = __DIR__;
foreach ([
    'api/config.php'        => 'la configuration (à créer depuis config.example.php)',
    'api/adhesion.php'      => 'le récepteur de paiement',
    'api/adhesion-init.php' => 'le formulaire d’adhésion',
    'api/verify.php'        => 'la vérification de carte',
    'api/_lib.php'          => 'les fonctions partagées',
    'api/_mail.php'         => 'l’envoi d’e-mail',
    'fr/index.html'         => 'la page d’accueil française',
    'verify/index.html'     => 'la page de vérification',
    'badge/index.html'      => 'le générateur de carte',
] as $f => $quoi) {
    verdict("Présence de $f", is_file("$racine/$f"), $quoi);
}

verdict(
    'api/config.php protégé',
    !is_file("$racine/api/config.example.php") || is_file("$racine/api/.htaccess"),
    'le .htaccess de api/ doit être présent'
);

// ---------------------------------------------------------------------
// 3. Configuration et base de données
// ---------------------------------------------------------------------
$config = is_file("$racine/api/config.php") ? require "$racine/api/config.php" : null;

if (!is_array($config)) {
    verdict('Lecture de config.php', false, 'fichier absent ou invalide');
} else {
    foreach ([
        ['db.name', $config['db']['name'] ?? ''],
        ['db.user', $config['db']['user'] ?? ''],
        ['db.password', $config['db']['password'] ?? ''],
        ['chariow.pulse_secret', $config['chariow']['pulse_secret'] ?? ''],
        ['chariow.api_key', $config['chariow']['api_key'] ?? ''],
        ['verification_secret', $config['verification_secret'] ?? ''],
        ['mail.smtp.password', $config['mail']['smtp']['password'] ?? ''],
        ['liens.whatsapp', $config['liens']['whatsapp'] ?? ''],
    ] as [$cle, $valeur]) {
        $rempli = $valeur !== '' && stripos((string) $valeur, 'CHANGEZ_MOI') === false;
        verdict("Clé $cle renseignée", $rempli, $rempli ? '' : 'encore à sa valeur d’exemple');
    }

    // Connexion
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db']['host'], $config['db']['name']),
            $config['db']['user'],
            $config['db']['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        verdict('Connexion à MySQL', true, $pdo->query('SELECT VERSION()')->fetchColumn());

        foreach (['membre', 'candidature', 'compteur_membre', 'webhook_livraison', 'verif_debit'] as $table) {
            $existe = (bool) $pdo->query(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table)
            )->fetchColumn();
            verdict("Table $table", $existe, $existe ? '' : 'importez db/schema.sql');
        }

        $n = (int) $pdo->query('SELECT COUNT(*) FROM membre')->fetchColumn();
        verdict('Membres importés', $n > 0, "$n en base — 18 attendus", false);

        $c = (int) $pdo->query('SELECT dernier FROM compteur_membre WHERE id = 1')->fetchColumn();
        verdict('Compteur calé au-dessus des numéros existants', $c >= $n, "compteur = $c, membres = $n");

        // Journal hors public_html : sinon il est téléchargeable.
        $log = (string) ($config['log'] ?? '');
        verdict(
            'Journal hors de public_html',
            $log !== '' && !str_starts_with(realpath(dirname($log)) ?: '', realpath($racine) ?: '@'),
            $log
        );
    } catch (Throwable $e) {
        verdict('Connexion à MySQL', false, $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Rapport
// ---------------------------------------------------------------------
$echecs = array_filter($lignes, static fn ($l) => !$l[1] && $l[3]);
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnostic YAAC</title>
<style>
 body{font-family:system-ui,sans-serif;max-width:820px;margin:0 auto;padding:2rem 1rem;
      background:#f5faf0;color:#0b0f0c;line-height:1.6}
 h1{font-size:1.5rem;margin-bottom:.25rem}
 .bilan{padding:1rem 1.25rem;border-radius:12px;margin:1.5rem 0;font-weight:600}
 .bilan.ok{background:#e6f1d8;border:1px solid #87bd43;color:#0b4527}
 .bilan.ko{background:#fdeceb;border:1px solid #d16b62;color:#8f2b23}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;
       box-shadow:0 8px 20px -14px rgb(11 15 12/.3)}
 td{padding:.6rem .9rem;border-bottom:1px solid #edf1ee;vertical-align:top}
 tr:last-child td{border-bottom:none}
 .e{width:2.2rem;font-weight:700}
 .e.o{color:#0b4527}.e.n{color:#8f2b23}.e.a{color:#8a5200}
 .d{color:#5a6560;font-size:.88rem}
 .avert{margin-top:2rem;padding:1rem 1.25rem;background:#fff4e0;border:1px solid #e6a23c;
        border-radius:12px;color:#8a5200;font-weight:600}
</style></head><body>
<h1>Diagnostic d'installation YAAC</h1>
<p class="d"><?= date('d/m/Y H:i') ?> · <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '?') ?></p>

<div class="bilan <?= $echecs ? 'ko' : 'ok' ?>">
  <?= $echecs
      ? count($echecs) . ' point(s) bloquant(s) à corriger avant la mise en service.'
      : 'Tout est en place. Le site peut recevoir un paiement réel.' ?>
</div>

<table>
<?php foreach ($lignes as [$quoi, $ok, $detail, $bloquant]): ?>
  <tr>
    <td class="e <?= $ok ? 'o' : ($bloquant ? 'n' : 'a') ?>"><?= $ok ? '✓' : ($bloquant ? '✗' : '!') ?></td>
    <td><?= htmlspecialchars($quoi) ?><?php if ($detail): ?><br><span class="d"><?= htmlspecialchars($detail) ?></span><?php endif ?></td>
  </tr>
<?php endforeach ?>
</table>

<p class="avert">Supprimez ce fichier dès que le diagnostic est vert. Il révèle la configuration du serveur.</p>
</body></html>
