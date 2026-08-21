<?php
require __DIR__ . '/_lib.php';
require __DIR__ . '/_mail.php';

$ok = 0; $ko = 0;
function t(string $nom, bool $cond, string $detail = '') {
    global $ok, $ko;
    if ($cond) { $ok++; printf("  OK    %s\n", $nom); }
    else { $ko++; printf("  ECHEC %s %s\n", $nom, $detail); }
}

echo "== Signature des Pulses Chariow ==\n";
$secret = 'whsec_secret_de_test';
$corps  = '{"event":"successful.sale","sale":{"id":"sal_123"}}';
$bonne  = 'sha256=' . hash_hmac('sha256', $corps, $secret);

t('signature valide acceptee',            yaac_signature_valide($corps, $bonne, $secret));
t('corps altere refuse',                  !yaac_signature_valide($corps . ' ', $bonne, $secret));
t('mauvais secret refuse',                !yaac_signature_valide($corps, $bonne, 'whsec_autre'));
t('signature vide refusee',               !yaac_signature_valide($corps, '', $secret));
t('secret vide refuse',                   !yaac_signature_valide($corps, $bonne, ''));
t('signature tronquee refusee',           !yaac_signature_valide($corps, substr($bonne, 0, 40), $secret));
t('prefixe absent refuse',                !yaac_signature_valide($corps, hash_hmac('sha256', $corps, $secret), $secret));

echo "\n== Plusieurs Pulses (un secret par Pulse) ==\n";
$s2   = 'whsec_second_pulse';
$sig2 = 'sha256=' . hash_hmac('sha256', $corps, $s2);
t('secret unique en chaine',              yaac_signature_valide_multi($corps, $bonne, $secret));
t('premier secret du tableau',            yaac_signature_valide_multi($corps, $bonne, [$secret, $s2]));
t('second secret du tableau',             yaac_signature_valide_multi($corps, $sig2, [$secret, $s2]));
t('aucun secret ne correspond',           !yaac_signature_valide_multi($corps, $bonne, ['whsec_a', 'whsec_b']));
t('tableau vide refuse',                  !yaac_signature_valide_multi($corps, $bonne, []));
t('corps altere refuse malgre 2 secrets', !yaac_signature_valide_multi($corps . 'x', $bonne, [$secret, $s2]));
t('valeur non chaine ignoree',            yaac_signature_valide_multi($corps, $bonne, [null, 42, $secret]));

echo "\n== Jetons de verification ==\n";
$j1 = yaac_jeton_carte('YAAC-2026-019');
$j2 = yaac_jeton_carte('YAAC-2026-020');
t('jeton de 10 caracteres hexa',          (bool) preg_match('/^[0-9a-f]{10}$/', $j1), $j1);
t('deterministe',                         $j1 === yaac_jeton_carte('YAAC-2026-019'));
t('different par numero',                 $j1 !== $j2);
t('insensible a la casse et aux espaces', $j1 === yaac_jeton_carte('  yaac-2026-019 '));
t('jeton correct accepte',                yaac_jeton_valide('YAAC-2026-019', $j1));
t('jeton du voisin refuse',               !yaac_jeton_valide('YAAC-2026-019', $j2));
t('jeton vide refuse',                    !yaac_jeton_valide('YAAC-2026-019', ''));
t('jeton tronque refuse',                 !yaac_jeton_valide('YAAC-2026-019', substr($j1, 0, 9)));
t('un caractere modifie refuse',          !yaac_jeton_valide('YAAC-2026-019', ($j1[0] === 'a' ? 'b' : 'a') . substr($j1, 1)));

echo "\n== Liens envoyes au membre ==\n";
$m = [
  'numero' => 'YAAC-2026-019', 'prenom' => 'Aminata', 'nom' => 'BALDÉ',
  'email' => 'aminata@example.org', 'role' => 'Membre',
  'date_adhesion' => '2026-08-21', 'date_expiration' => '2031-08-21',
];
$cfg = yaac_config();
$badge  = yaac_lien_badge($m, $cfg);
$verify = yaac_lien_verify($m, $cfg);
t('lien badge porte numero et jeton',     $badge === 'https://yaac.network/badge/?id=YAAC-2026-019&c=' . $j1, $badge);
t('lien badge sans nom ni role',          !str_contains($badge, 'prenom') && !str_contains($badge, 'role'));
t('lien verify porte le jeton',           $verify === 'https://yaac.network/verify/?id=YAAC-2026-019&c=' . $j1, $verify);

echo "\n== Dates en francais ==\n";
t('21 aout 2026',                         yaac_date_fr('2026-08-21') === '21 août 2026', yaac_date_fr('2026-08-21'));
t('1er janvier',                          yaac_date_fr('2031-01-01') === '1 janvier 2031', yaac_date_fr('2031-01-01'));
t('date invalide renvoyee telle quelle',  yaac_date_fr('bidon') === 'bidon');

echo "\n== E-mail de carte ==\n";
$html  = yaac_corps_html($m, $cfg);
$texte = yaac_corps_texte($m, $cfg);
t('montant 6 000 FCFA present',           str_contains($html, '6 000 FCFA') && str_contains($texte, '6 000 FCFA'));
t('ancien tarif 5 000 absent',            !str_contains($html, '5 000') && !str_contains($html, '15 000'));
t('info@yaac.network absent',             !str_contains($html, 'info@yaac.network') && !str_contains($texte, 'info@yaac.network'));
t('communication@ present',               str_contains($html, 'communication@yaac.network'));
t('numero affiche',                       str_contains($html, 'YAAC-2026-019') && str_contains($texte, 'YAAC-2026-019'));
t('lien badge dans le HTML',              str_contains($html, 'badge/?id=YAAC-2026-019'));
t('lien verify dans le HTML',             str_contains($html, 'verify/?id=YAAC-2026-019'));
t('bloc WhatsApp present',                str_contains($html, 'chat.whatsapp.com') && str_contains($texte, 'chat.whatsapp.com'));
t('dates lisibles',                       str_contains($html, '21 août 2026') && str_contains($html, '21 août 2031'));
t('nom accentue non casse',               str_contains($html, 'Aminata'));
t('couleurs de la charte',                str_contains($html, '#006738') && str_contains($html, '#123524'));
t('verts hors charte absents',            !str_contains($html, '#1B5E30') && !str_contains($html, '#6AB04C'));
t('pas de PIN a conserver',               !str_contains($html, 'PIN'));

echo "\n== E-mail sans WhatsApp configure ==\n";
$sans = $cfg; $sans['liens']['whatsapp'] = '';
t('bloc WhatsApp omis',                   !str_contains(yaac_corps_html($m, $sans), 'chat.whatsapp.com'));

echo "\n== Encodage des en-tetes ==\n";
t('ASCII inchange',                       yaac_encoder_entete('Bienvenue') === 'Bienvenue');
t('accents encodes RFC 2047',             str_starts_with(yaac_encoder_entete('Adhésion'), '=?UTF-8?B?'));

echo "\n== Corps MIME ==\n";
$mime = yaac_corps_mime('<p>x</p>', 'x', 'FRONTIERE');
t('deux parties alternatives',            substr_count($mime, '--FRONTIERE') === 3);
t('partie texte declaree',                str_contains($mime, 'text/plain; charset=UTF-8'));
t('partie html declaree',                 str_contains($mime, 'text/html; charset=UTF-8'));

printf("\n---- %d reussis, %d echecs ----\n", $ok, $ko);
exit($ko === 0 ? 0 : 1);
