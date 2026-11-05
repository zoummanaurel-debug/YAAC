<?php
/**
 * YAAC — Paiements Wave et Orange Money, validés d'un clic.
 *
 * Le lien Wave est statique, au montant exact de l'adhésion : le visiteur ne
 * peut pas payer un autre montant. Mais ni ce lien ni un transfert Orange
 * Money ne disent au site QUI a payé — il n'y a pas de webhook comme chez
 * Chariow. Envoyer la carte sur un simple « J'ai payé » la donnerait à
 * quiconque clique. D'où le parcours :
 *
 *   1. le visiteur remplit le formulaire → `candidature` (adhesion-init.php)
 *   2. il paie, clique « J'ai payé » en confirmant le numéro qui a payé
 *      → e-mail à l'équipe avec un lien signé (paiement-declare.php)
 *   3. le trésorier voit ce numéro dans Wave Business / OM, clique « Valider »
 *      → numéro YAAC + e-mail de carte (valider.php)
 *
 * `membre.chariow_sale_id` reçoit `wave:<jeton>` ou `om:<jeton>` : l'index
 * unique empêche de valider deux fois la même candidature, sans migration.
 */

declare(strict_types=1);

/** Moyens de paiement validés à la main, et leur libellé. */
const YAAC_PAIEMENTS_MANUELS = ['wave' => 'Wave', 'om' => 'Orange Money'];

/** Numéro de téléphone réduit à ses chiffres, ou '' s'il n'est pas plausible. */
function yaac_numero_propre(string $brut): string
{
    $n = preg_replace('/\D+/', '', $brut) ?? '';
    return (strlen($n) >= 6 && strlen($n) <= 15) ? $n : '';
}

/**
 * Signature du lien de validation envoyé à l'équipe.
 *
 * Le jeton de candidature seul ne suffit pas : il est renvoyé au navigateur
 * du visiteur, qui pourrait sinon valider sa propre adhésion sans payer.
 */
function yaac_signature_validation(string $jeton, string $mode): string
{
    $secret = (string) (yaac_config()['verification_secret'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('verification_secret absent de config.php');
    }
    return hash_hmac('sha256', 'valider|' . $jeton . '|' . $mode, $secret);
}

function yaac_signature_validation_ok(string $jeton, string $mode, string $fournie): bool
{
    if ($fournie === '') {
        return false;
    }
    return hash_equals(yaac_signature_validation($jeton, $mode), $fournie);
}

/** Lien de validation, à cliquer par le trésorier. */
function yaac_lien_validation(string $jeton, string $mode, array $config): string
{
    return $config['liens']['site'] . '/api/valider.php?' . http_build_query([
        'j' => $jeton,
        'm' => $mode,
        's' => yaac_signature_validation($jeton, $mode),
    ]);
}

/** Envoie un e-mail par le transport configuré. Lève une exception en cas d'échec. */
function yaac_envoyer(string $a, string $sujet, string $html, string $texte, array $config): void
{
    if (($config['mail']['transport'] ?? 'mail') === 'smtp') {
        yaac_smtp_envoyer($a, $sujet, $html, $texte, $config);
    } else {
        yaac_mail_envoyer($a, $sujet, $html, $texte, $config);
    }
}

/** Montant de l'adhésion en FCFA, pour l'e-mail et l'enregistrement. */
function yaac_montant_adhesion(array $config): int
{
    return (int) ($config['paiement']['montant'] ?? 6000);
}

/**
 * Crée le membre à partir d'une candidature payée par Wave ou OM.
 *
 * Même écriture que le webhook Chariow (adhesion.php), en transaction, puis
 * envoi de la carte APRÈS le commit : si le SMTP tombe, le membre existe et
 * l'envoi reste rejouable.
 *
 * Lève PDOException code 23000 si l'e-mail ou la candidature sont déjà pris.
 *
 * @return array{numero: string, carte_envoyee: bool, erreur: ?string}
 */
function yaac_creer_membre_manuel(PDO $pdo, array $c, string $mode, array $config): array
{
    $aujourdhui = new DateTimeImmutable('today');
    $expiration = $aujourdhui->modify('+' . (int) $config['adhesion']['validite_annees'] . ' years');

    $membre = [
        'prenom'          => (string) $c['prenom'],
        'nom'             => (string) $c['nom'],
        'email'           => (string) $c['email'],
        'role'            => (string) $config['adhesion']['role_defaut'],
        'date_adhesion'   => $aujourdhui->format('Y-m-d'),
        'date_expiration' => $expiration->format('Y-m-d'),
    ];

    $pdo->beginTransaction();
    try {
        $membre['numero'] = yaac_prochain_numero($pdo);

        $pdo->prepare(
            'INSERT INTO membre
               (numero, prenom, nom, email, telephone, date_naissance,
                pays_origine, pays_residence, motivation, benevolat, source,
                statuts_acceptes, role, date_adhesion, date_expiration, statut,
                chariow_sale_id, chariow_license, montant_centimes, devise)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $membre['numero'], $membre['prenom'], $membre['nom'], $membre['email'],
            $c['telephone'] ?? null, $c['date_naissance'] ?? null,
            $c['pays_origine'] ?? null, $c['pays_residence'] ?? null,
            $c['motivation'] ?? null, $c['benevolat'] ?? null, $c['source'] ?? null,
            (int) ($c['statuts_acceptes'] ?? 0),
            $membre['role'], $membre['date_adhesion'], $membre['date_expiration'], 'actif',
            $mode . ':' . $c['jeton'],
            null,
            yaac_montant_adhesion($config) * 100,
            'XOF',
        ]);

        $pdo->prepare('UPDATE candidature SET membre_id = ? WHERE id = ?')
            ->execute([(int) $pdo->lastInsertId(), (int) $c['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $envoi = yaac_envoyer_carte($membre);

    $pdo->prepare(
        'UPDATE membre SET carte_envoyee_le = ?, carte_erreur = ? WHERE numero = ?'
    )->execute([
        $envoi['ok'] ? gmdate('Y-m-d H:i:s') : null,
        $envoi['ok'] ? null : $envoi['erreur'],
        $membre['numero'],
    ]);

    yaac_log('adhesion_manuelle_creee', [
        'numero'        => $membre['numero'],
        'email'         => $membre['email'],
        'paiement'      => $mode,
        'carte_envoyee' => $envoi['ok'],
    ]);

    return ['numero' => $membre['numero'], 'carte_envoyee' => $envoi['ok'], 'erreur' => $envoi['erreur']];
}
