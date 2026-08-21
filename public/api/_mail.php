<?php
/**
 * YAAC — Envoi de l'e-mail de carte de membre.
 *
 * Client SMTP minimal écrit à la main. PHPMailer serait le choix normal,
 * mais il suppose composer, donc une chaîne de build que l'association
 * devrait maintenir pour un seul e-mail transactionnel. Ce fichier fait
 * strictement ce qu'il faut : AUTH LOGIN, un destinataire, un corps
 * alternatif texte + HTML.
 */

declare(strict_types=1);

/**
 * Compose et envoie l'e-mail de bienvenue.
 *
 * @return array{ok: bool, erreur: ?string}
 */
function yaac_envoyer_carte(array $membre): array
{
    $config = yaac_config();

    $sujet = sprintf(
        'Bienvenue dans YAAC — votre carte de membre %s',
        $membre['numero']
    );

    $html  = yaac_corps_html($membre, $config);
    $texte = yaac_corps_texte($membre, $config);

    try {
        if (($config['mail']['transport'] ?? 'mail') === 'smtp') {
            yaac_smtp_envoyer($membre['email'], $sujet, $html, $texte, $config);
        } else {
            yaac_mail_envoyer($membre['email'], $sujet, $html, $texte, $config);
        }
        return ['ok' => true, 'erreur' => null];
    } catch (Throwable $e) {
        yaac_log('mail_echec', [
            'numero'  => $membre['numero'],
            'message' => $e->getMessage(),
        ]);
        return ['ok' => false, 'erreur' => $e->getMessage()];
    }
}

/**
 * Lien vers le générateur de carte.
 *
 * Le numéro suffit : la page lit nom, rôle et dates dans le registre via
 * /api/verify.php. L'ancien lien transportait aussi `prenom`, `nom`, `role` et
 * `date`, que le générateur recopiait tels quels sur la carte — n'importe qui
 * pouvait donc se fabriquer un badge en changeant l'URL.
 */
function yaac_lien_badge(array $membre, array $config): string
{
    return $config['liens']['badge'] . '?' . http_build_query([
        'id' => $membre['numero'],
        // Le jeton voyage jusqu'au generateur, qui le replace dans le QR code.
        'c'  => yaac_jeton_carte($membre['numero']),
    ]);
}

/** Lien vers la page de vérification. */
function yaac_lien_verify(array $membre, array $config): string
{
    return $config['liens']['verify'] . '?' . http_build_query([
        'id' => $membre['numero'],
        'c'  => yaac_jeton_carte($membre['numero']),
    ]);
}

function yaac_date_fr(string $iso): string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $iso);
    if ($d === false) {
        return $iso;
    }
    $mois = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];
    return sprintf('%d %s %d', (int) $d->format('j'), $mois[(int) $d->format('n')], (int) $d->format('Y'));
}

function yaac_corps_html(array $membre, array $config): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $badge    = $e(yaac_lien_badge($membre, $config));
    $verify   = $e(yaac_lien_verify($membre, $config));
    $whatsapp = $config['liens']['whatsapp'] ?? '';

    // Charte officielle : #006738 vert profond, #87BD43 vert feuille
    // décoratif. L'ancien modèle utilisait #1B5E30 et #6AB04C, hors charte.
    $bloc_whatsapp = '';
    if ($whatsapp !== '') {
        $bloc_whatsapp = '
      <tr><td style="padding:0 28px 20px">
        <p style="margin:0 0 8px;font-size:15px;color:#2b332d">
          Dernière étape : rejoignez le groupe WhatsApp des membres.
        </p>
        <a href="' . $e($whatsapp) . '"
           style="display:inline-block;background:#87BD43;color:#08180f;padding:11px 22px;
                  border-radius:999px;text-decoration:none;font-weight:700;font-size:15px">
          Rejoindre le groupe WhatsApp
        </a>
      </td></tr>';
    }

    return '<!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#f5faf0;
             font-family:Roboto,\'Segoe UI\',Arial,sans-serif;color:#2b332d">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"
       style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden">

  <tr><td style="background:#006738;padding:24px 28px">
    <p style="margin:0;color:#ffffff;font-size:19px;font-weight:700">Bienvenue dans YAAC</p>
    <p style="margin:5px 0 0;color:#c2dd9e;font-size:12px">
      Youth Alliance for Agroecology and Climate
    </p>
  </td></tr>

  <tr><td style="padding:26px 28px 8px">
    <p style="margin:0 0 14px;font-size:16px">Bonjour ' . $e($membre['prenom']) . ',</p>
    <p style="margin:0;font-size:15px;line-height:1.6">
      Votre paiement est confirmé et votre adhésion est enregistrée.
      Vous êtes désormais membre de l\'alliance.
    </p>
  </td></tr>

  <tr><td style="padding:18px 28px 0">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="background:#f5faf0;border-radius:12px">
      <tr><td style="padding:16px 20px">
        <p style="margin:0;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#597d2c">
          Votre numéro YAAC
        </p>
        <p style="margin:6px 0 0;font-size:24px;font-weight:700;color:#006738;letter-spacing:.04em">
          ' . $e($membre['numero']) . '
        </p>
        <p style="margin:8px 0 0;font-size:13px;color:#5a6560">
          Adhésion le ' . $e(yaac_date_fr($membre['date_adhesion'])) . ' ·
          valable jusqu\'au ' . $e(yaac_date_fr($membre['date_expiration'])) . '
        </p>
      </td></tr>
    </table>
  </td></tr>

  <tr><td style="padding:22px 28px 8px">
    <a href="' . $badge . '"
       style="display:inline-block;background:#006738;color:#ffffff;padding:12px 26px;
              border-radius:999px;text-decoration:none;font-weight:700;font-size:15px">
      Générer ma carte de membre
    </a>
    <p style="margin:10px 0 0;font-size:12px;color:#5a6560;word-break:break-all">
      Si le bouton ne fonctionne pas : ' . $badge . '
    </p>
  </td></tr>

  <tr><td style="padding:14px 28px 20px">
    <p style="margin:0;font-size:14px;line-height:1.6">
      Votre carte peut être vérifiée à tout moment, par vous ou par un tiers, sur
      <a href="' . $verify . '" style="color:#006738">' . $verify . '</a>.
      C\'est aussi l\'adresse vers laquelle pointe le QR code de votre carte.
    </p>
  </td></tr>
' . $bloc_whatsapp . '
  <tr><td style="padding:0 28px 24px">
    <p style="margin:0;font-size:13px;color:#5a6560;line-height:1.6">
      Montant réglé : ' . $e($config['adhesion']['montant_affiche']) . ' (frais d\'adhésion).
      Une question ? Répondez simplement à ce message.
    </p>
  </td></tr>

  <tr><td style="background:#123524;padding:16px 28px;text-align:center">
    <p style="margin:0;color:#c2dd9e;font-size:12px">
      YAAC — enregistrée au Bénin et au Sénégal<br>
      yaac.network · ' . $e($config['mail']['from_email']) . '
    </p>
  </td></tr>

</table></body></html>';
}

function yaac_corps_texte(array $membre, array $config): string
{
    $lignes = [
        'Bonjour ' . $membre['prenom'] . ',',
        '',
        'Votre paiement est confirmé et votre adhésion est enregistrée.',
        'Vous êtes désormais membre de YAAC.',
        '',
        'Votre numéro YAAC : ' . $membre['numero'],
        'Adhésion le ' . yaac_date_fr($membre['date_adhesion'])
            . ', valable jusqu\'au ' . yaac_date_fr($membre['date_expiration']) . '.',
        '',
        'Générer votre carte de membre :',
        yaac_lien_badge($membre, $config),
        '',
        'Vérifier votre carte (adresse du QR code) :',
        yaac_lien_verify($membre, $config),
    ];

    if (($config['liens']['whatsapp'] ?? '') !== '') {
        $lignes[] = '';
        $lignes[] = 'Rejoindre le groupe WhatsApp des membres :';
        $lignes[] = $config['liens']['whatsapp'];
    }

    $lignes[] = '';
    $lignes[] = 'Montant réglé : ' . $config['adhesion']['montant_affiche'] . ' (frais d\'adhésion).';
    $lignes[] = '';
    $lignes[] = 'YAAC — Youth Alliance for Agroecology and Climate';
    $lignes[] = 'yaac.network';

    return implode("\r\n", $lignes);
}

/** Assemble un corps MIME multipart/alternative. */
function yaac_corps_mime(string $html, string $texte, string $frontiere): string
{
    return implode("\r\n", [
        '--' . $frontiere,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($texte)),
        '--' . $frontiere,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html)),
        '--' . $frontiere . '--',
        '',
    ]);
}

function yaac_entetes_communs(array $config, string $frontiere): array
{
    $m = $config['mail'];
    $entetes = [
        'From: ' . yaac_encoder_entete($m['from_nom']) . ' <' . $m['from_email'] . '>',
        'Reply-To: ' . $m['reply_to'],
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $frontiere . '"',
    ];
    if (!empty($m['bcc'])) {
        $entetes[] = 'Bcc: ' . $m['bcc'];
    }
    return $entetes;
}

/** Encodage RFC 2047 pour les en-têtes contenant des accents. */
function yaac_encoder_entete(string $valeur): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $valeur)) {
        return $valeur;
    }
    return '=?UTF-8?B?' . base64_encode($valeur) . '?=';
}

function yaac_mail_envoyer(string $a, string $sujet, string $html, string $texte, array $config): void
{
    $frontiere = 'yaac' . bin2hex(random_bytes(12));
    $entetes   = yaac_entetes_communs($config, $frontiere);
    $corps     = yaac_corps_mime($html, $texte, $frontiere);

    $ok = mail($a, yaac_encoder_entete($sujet), $corps, implode("\r\n", $entetes));
    if (!$ok) {
        throw new RuntimeException('mail() a renvoyé false');
    }
}

/** Client SMTP minimal : connexion, AUTH LOGIN, MAIL FROM, RCPT TO, DATA. */
function yaac_smtp_envoyer(string $a, string $sujet, string $html, string $texte, array $config): void
{
    $s   = $config['mail']['smtp'];
    $hote = ($s['chiffrement'] === 'ssl' ? 'ssl://' : '') . $s['host'] . ':' . $s['port'];

    $flux = @stream_socket_client($hote, $errno, $errstr, $s['timeout'],
        STREAM_CLIENT_CONNECT, stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]));

    if ($flux === false) {
        throw new RuntimeException("connexion SMTP impossible ($errno $errstr)");
    }
    stream_set_timeout($flux, $s['timeout']);

    $lire = static function () use ($flux): string {
        $reponse = '';
        while (($ligne = fgets($flux, 515)) !== false) {
            $reponse .= $ligne;
            // Dernière ligne d'une réponse SMTP : « 250 texte », pas « 250-texte ».
            if (strlen($ligne) < 4 || $ligne[3] === ' ') {
                break;
            }
        }
        return $reponse;
    };

    $ecrire = static function (string $commande, array $attendus) use ($flux, $lire): string {
        if ($commande !== '') {
            fwrite($flux, $commande . "\r\n");
        }
        $reponse = $lire();
        $code    = (int) substr($reponse, 0, 3);
        if (!in_array($code, $attendus, true)) {
            // Ne jamais journaliser $commande : elle contient le mot de passe
            // sur les étapes AUTH.
            throw new RuntimeException('SMTP a répondu ' . $code . ' : ' . trim($reponse));
        }
        return $reponse;
    };

    try {
        $ecrire('', [220]);
        $ecrire('EHLO yaac.network', [250]);

        if ($s['chiffrement'] === 'tls') {
            $ecrire('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($flux, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('négociation STARTTLS échouée');
            }
            $ecrire('EHLO yaac.network', [250]);
        }

        $ecrire('AUTH LOGIN', [334]);
        $ecrire(base64_encode($s['user']), [334]);
        $ecrire(base64_encode($s['password']), [235]);

        $ecrire('MAIL FROM:<' . $config['mail']['from_email'] . '>', [250]);
        $ecrire('RCPT TO:<' . $a . '>', [250, 251]);
        if (!empty($config['mail']['bcc'])) {
            $ecrire('RCPT TO:<' . $config['mail']['bcc'] . '>', [250, 251]);
        }
        $ecrire('DATA', [354]);

        $frontiere = 'yaac' . bin2hex(random_bytes(12));
        // Le Bcc est passé par RCPT TO, jamais écrit dans les en-têtes :
        // sinon la copie cachée apparaît chez le destinataire.
        $entetes = array_filter(
            yaac_entetes_communs($config, $frontiere),
            static fn (string $h): bool => stripos($h, 'Bcc:') !== 0
        );
        $entetes[] = 'To: ' . $a;
        $entetes[] = 'Subject: ' . yaac_encoder_entete($sujet);
        $entetes[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $entetes[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@yaac.network>';

        $message = implode("\r\n", $entetes) . "\r\n\r\n"
                 . yaac_corps_mime($html, $texte, $frontiere);

        // Transparence du point : une ligne réduite à « . » terminerait DATA.
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($flux, $message . "\r\n.\r\n");
        $ecrire('', [250]);
        $ecrire('QUIT', [221]);
    } finally {
        fclose($flux);
    }
}
