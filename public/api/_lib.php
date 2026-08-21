<?php
/**
 * YAAC — Fonctions partagées par les points d'entrée PHP.
 *
 * Aucun composer, aucune dépendance : l'hébergement mutualisé Hostinger
 * n'offre pas toujours de ligne de commande, et une association n'a pas à
 * maintenir une chaîne de build PHP pour deux fichiers.
 */

declare(strict_types=1);

/** Charge config.php, ou s'arrête net s'il manque. */
function yaac_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $chemin = __DIR__ . '/config.php';
    if (!is_file($chemin)) {
        // Pas de détail dans la réponse : le message irait à un inconnu.
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erreur' => 'configuration_absente'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $config = require $chemin;
    return $config;
}

/** Journalise dans le fichier configuré. Silencieux en cas d'échec. */
function yaac_log(string $message, array $contexte = []): void
{
    $config = yaac_config();
    $ligne  = sprintf(
        "[%s] %s%s\n",
        gmdate('Y-m-d\TH:i:s\Z'),
        $message,
        $contexte ? ' ' . json_encode($contexte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents($config['log'], $ligne, FILE_APPEND | LOCK_EX);
}

/** Connexion PDO en utf8mb4, exceptions activées. */
function yaac_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $c   = yaac_config()['db'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['name']);

    try {
        $pdo = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Vraies requêtes préparées côté serveur, pas d'émulation :
            // l'émulation réintroduit des chemins d'échappement subtils.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        yaac_log('db_connexion_echouee', ['message' => $e->getMessage()]);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erreur' => 'base_indisponible'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $pdo;
}

/** Réponse JSON puis arrêt. */
function yaac_json(int $code, array $donnees): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Vérifie la signature d'un Pulse Chariow.
 *
 * En-tête `x-chariow-signature`, valeur `sha256=<64 hexa minuscules>`,
 * HMAC-SHA256 du CORPS BRUT avec le secret `whsec_…`.
 *
 * Le corps doit être celui lu avant tout json_decode : re-sérialiser un
 * tableau PHP ne redonne pas octet pour octet ce que Chariow a signé
 * (ordre des clés, échappement des barres obliques, espaces).
 */
function yaac_signature_valide(string $corps_brut, string $entete, string $secret): bool
{
    if ($entete === '' || $secret === '') {
        return false;
    }

    $attendu = 'sha256=' . hash_hmac('sha256', $corps_brut, $secret);

    // hash_equals exige des chaînes de même longueur pour être utile ;
    // la comparaison de longueur d'abord évite un faux négatif bruyant.
    if (strlen($attendu) !== strlen($entete)) {
        return false;
    }

    return hash_equals($attendu, $entete);
}

/**
 * Vérifie une signature de Pulse contre un OU plusieurs secrets.
 *
 * Chariow attribue un secret de signature par Pulse. Déclarer un deuxième
 * Pulse — par exemple pour ajouter `license.expired` et `license.revoked`
 * à côté d'un Pulse existant qui ne porte que `successful.sale` — impose
 * donc d'accepter deux secrets sur le même point d'entrée.
 *
 * `pulse_secret` accepte pour cela une chaîne ou un tableau de chaînes.
 *
 * @param string|array<string> $secrets
 */
function yaac_signature_valide_multi(string $corps, string $signature, $secrets): bool
{
    $valide = false;
    foreach ((array) $secrets as $secret) {
        // Pas de court-circuit : tous les secrets sont testés même après un
        // succès, pour que la durée ne trahisse pas lequel a fonctionné.
        if (is_string($secret) && yaac_signature_valide($corps, $signature, $secret)) {
            $valide = true;
        }
    }
    return $valide;
}

/**
 * Jeton de vérification d'une carte.
 *
 * Les numéros de membre sont séquentiels : sans ce jeton, compter de 001 à 999
 * suffirait à récupérer le nom, le rôle et les dates de tout le monde. Pour une
 * organisation de jeunes militants du climat, un annuaire nominatif public est
 * une liste de cibles, pas un détail de confidentialité.
 *
 * Le jeton est un HMAC tronqué du numéro. Il n'est PAS stocké : il se recalcule
 * à la demande, donc aucune colonne à migrer et aucune fuite possible par la
 * base. 10 caractères hexadécimaux = 40 bits, hors de portée d'un devinage
 * même sans limitation de débit.
 *
 * Il n'est pas secret vis-à-vis du porteur : il est imprimé dans son QR code.
 * Il empêche seulement de vérifier une carte qu'on n'a pas en main.
 */
function yaac_jeton_carte(string $numero): string
{
    $secret = (string) (yaac_config()['verification_secret'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('verification_secret absent de config.php');
    }
    return substr(hash_hmac('sha256', strtoupper(trim($numero)), $secret), 0, 10);
}

/** Compare un jeton fourni au jeton attendu, sans fuite temporelle. */
function yaac_jeton_valide(string $numero, string $fourni): bool
{
    if ($fourni === '') {
        return false;
    }
    $attendu = yaac_jeton_carte($numero);
    return strlen($attendu) === strlen($fourni) && hash_equals($attendu, $fourni);
}

/** Récupère un en-tête HTTP, quelle que soit la casse ou le SAPI. */
function yaac_entete(string $nom): string
{
    $cle = 'HTTP_' . strtoupper(str_replace('-', '_', $nom));
    if (isset($_SERVER[$cle])) {
        return (string) $_SERVER[$cle];
    }

    // Certains SAPI n'exposent les en-têtes que par getallheaders().
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $nom) === 0) {
                return (string) $v;
            }
        }
    }

    return '';
}

/**
 * Attribue le prochain numéro de membre, de façon atomique.
 *
 * `LAST_INSERT_ID(dernier + 1)` fait d'une pierre deux coups : l'UPDATE
 * verrouille la ligne du compteur jusqu'à la fin de la transaction, et la
 * valeur incrémentée devient récupérable par LAST_INSERT_ID() sur la même
 * connexion. Deux webhooks simultanés se sérialisent donc naturellement,
 * là où l'ancien flux « lire la dernière ligne Excel, ajouter 1 » leur
 * donnait le même numéro.
 *
 * À appeler DANS une transaction déjà ouverte.
 */
function yaac_prochain_numero(PDO $pdo): string
{
    $pdo->exec('UPDATE compteur_membre SET dernier = LAST_INSERT_ID(dernier + 1) WHERE id = 1');
    $sequence = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    if ($sequence <= 0) {
        throw new RuntimeException('compteur_membre non initialisé (exécutez db/schema.sql)');
    }

    return sprintf('YAAC-%s-%03d', gmdate('Y'), $sequence);
}
