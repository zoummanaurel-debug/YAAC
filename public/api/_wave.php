<?php
/**
 * YAAC — Wave Checkout API : la même chaîne automatique que Chariow.
 *
 *   formulaire → session Wave (client_reference = jeton de candidature)
 *              → paiement → webhook `checkout.session.completed`
 *              → numéro YAAC + e-mail de carte
 *
 * Le lien marchand statique ne convient pas à l'automatisation : il ne porte
 * aucune référence de candidature, et Wave ne documente pas de webhook pour
 * lui. La session, elle, fixe le montant côté serveur et renvoie le jeton.
 *
 * Documentation : https://docs.wave.com/checkout, https://docs.wave.com/webhook
 */

declare(strict_types=1);

/** L'API Wave est-elle configurée (clé API ET secret de webhook) ? */
function yaac_wave_actif(array $config): bool
{
    return trim((string) ($config['wave']['api_key'] ?? '')) !== ''
        && trim((string) ($config['wave']['webhook_secret'] ?? '')) !== '';
}

/**
 * Vérifie l'en-tête `Wave-Signature: t=<horodatage>,v1=<hex>[,v1=<hex>…]`.
 *
 * Signé : horodatage concaténé au corps BRUT, HMAC-SHA256, hexadécimal
 * minuscule. Plusieurs `v1` coexistent pendant une rotation de secret. Un
 * horodatage de plus de 5 minutes est refusé (rejeu).
 */
function yaac_signature_wave_valide(string $corps, string $entete, string $secret, ?int $maintenant = null): bool
{
    if ($entete === '' || $secret === '') {
        return false;
    }

    $t = null;
    $signatures = [];
    foreach (explode(',', $entete) as $morceau) {
        $paire = explode('=', trim($morceau), 2);
        if (count($paire) !== 2) {
            continue;
        }
        if ($paire[0] === 't') {
            $t = $paire[1];
        } elseif ($paire[0] === 'v1') {
            $signatures[] = $paire[1];
        }
    }

    if ($t === null || !ctype_digit($t) || !$signatures) {
        return false;
    }
    if (abs(($maintenant ?? time()) - (int) $t) > 300) {
        return false;
    }

    $attendu = hash_hmac('sha256', $t . $corps, $secret);
    $valide  = false;
    foreach ($signatures as $s) {
        if (strlen($s) === strlen($attendu) && hash_equals($attendu, $s)) {
            $valide = true;
        }
    }
    return $valide;
}

/**
 * Crée une session de paiement Wave pour une candidature.
 *
 * @return string URL `wave_launch_url` vers laquelle envoyer le visiteur.
 * @throws RuntimeException si Wave refuse ou ne répond pas.
 */
function yaac_wave_session(string $jeton, string $lang, array $config): string
{
    $site = $config['liens']['site'];
    $charge = [
        // Wave attend une chaîne ; le XOF n'a pas de décimales.
        'amount'           => (string) yaac_montant_adhesion($config),
        'currency'         => 'XOF',
        'client_reference' => $jeton,
        'success_url'      => $site . ($lang === 'en' ? '/en/membership-confirmed/' : '/fr/adhesion-confirmee/'),
        'error_url'        => $site . ($lang === 'en' ? '/en/become-a-member/' : '/fr/devenir-membre/'),
    ];

    $ch = curl_init('https://api.wave.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($charge),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $config['wave']['api_key'],
            'Content-Type: application/json',
        ],
    ]);
    $reponse = curl_exec($ch);
    $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    $url = json_decode((string) $reponse, true)['wave_launch_url'] ?? null;
    if ($reponse === false || $http < 200 || $http >= 300 || !is_string($url) || $url === '') {
        yaac_log('wave_session_echec', ['http' => $http, 'curl' => $err, 'reponse' => (string) $reponse]);
        throw new RuntimeException('session Wave refusée');
    }
    return $url;
}
