<?php
/**
 * YAAC — Réception des Pulses Chariow.
 *
 * URL à déclarer dans Chariow : Automation → Pulses → Add Pulse
 *   https://yaac.network/api/adhesion.php
 *   Événements : successful.sale, license.expired, license.revoked
 *
 * C'est le PAIEMENT qui déclenche l'adhésion, et rien d'autre. Le formulaire
 * ne crée plus de membre : sans vente confirmée, il n'y a ni numéro, ni
 * carte. C'était le défaut central de l'ancien flux Power Automate, où la
 * colonne « Paiement » était écrite « En attente » puis corrigée à la main —
 * pendant que la carte, elle, était déjà partie.
 */

declare(strict_types=1);

require __DIR__ . '/_lib.php';
require __DIR__ . '/_mail.php';

$config = yaac_config();

// ---------------------------------------------------------------------
// 1. Méthode
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    yaac_json(405, ['erreur' => 'methode_non_autorisee']);
}

// ---------------------------------------------------------------------
// 2. Signature
//
// Le corps BRUT est signé. Il doit être lu et vérifié avant tout
// json_decode : re-sérialiser un tableau PHP ne reproduit pas octet pour
// octet ce que Chariow a signé.
// ---------------------------------------------------------------------
$corps_brut  = file_get_contents('php://input') ?: '';
$signature   = yaac_entete('x-chariow-signature');
$delivery_id = yaac_entete('x-pulse-delivery-id');

if (!yaac_signature_valide_multi($corps_brut, $signature, $config['chariow']['pulse_secret'])) {
    // L'URL est publique et devinable ; la signature est la seule chose qui
    // distingue Chariow de n'importe qui. On journalise pour pouvoir
    // diagnostiquer un secret mal recopié, sans rien révéler en réponse.
    yaac_log('pulse_signature_invalide', [
        'delivery_id' => $delivery_id,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '?',
        'signature_recue_longueur' => strlen($signature),
    ]);
    yaac_json(401, ['erreur' => 'signature_invalide']);
}

if ($delivery_id === '') {
    yaac_json(400, ['erreur' => 'delivery_id_absent']);
}

$charge = json_decode($corps_brut, true);
if (!is_array($charge)) {
    yaac_json(400, ['erreur' => 'json_invalide']);
}

$evenement = (string) ($charge['event'] ?? '');
$pdo       = yaac_db();

// ---------------------------------------------------------------------
// 3. Idempotence
//
// Chariow réémet un Pulse tant qu'il n'a pas reçu de 200. L'insertion de
// `delivery_id` sert de verrou : si elle échoue en doublon, l'événement est
// déjà passé.
//
// Nuance importante : on ne bloque une réémission que si le traitement
// précédent a ABOUTI. Un échec laissé en base doit pouvoir être rejoué,
// sinon un incident transitoire ferait perdre un membre pour de bon.
// ---------------------------------------------------------------------
try {
    $pdo->prepare(
        'INSERT INTO webhook_livraison (delivery_id, evenement, resultat, charge_utile)
         VALUES (?, ?, ?, ?)'
    )->execute([$delivery_id, $evenement, 'erreur', $corps_brut]);
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') {
        throw $e;
    }
    $precedent = $pdo->prepare('SELECT resultat FROM webhook_livraison WHERE delivery_id = ?');
    $precedent->execute([$delivery_id]);
    $resultat = (string) $precedent->fetchColumn();

    if (in_array($resultat, ['traite', 'ignore'], true)) {
        yaac_log('pulse_deja_traite', ['delivery_id' => $delivery_id, 'resultat' => $resultat]);
        yaac_json(200, ['statut' => 'deja_traite']);
    }
    // Sinon : on rejoue.
}

/** Clôt la livraison et répond. */
$terminer = static function (string $resultat, string $detail, int $code, array $reponse)
    use ($pdo, $delivery_id): void {
    $pdo->prepare('UPDATE webhook_livraison SET resultat = ?, detail = ? WHERE delivery_id = ?')
        ->execute([$resultat, $detail, $delivery_id]);
    yaac_json($code, $reponse);
};

/** Premier champ non vide parmi plusieurs clés possibles. */
$champ = static function (array $source, array $cles, string $defaut = ''): string {
    foreach ($cles as $cle) {
        if (isset($source[$cle]) && is_scalar($source[$cle]) && (string) $source[$cle] !== '') {
            return trim((string) $source[$cle]);
        }
    }
    return $defaut;
};

// ---------------------------------------------------------------------
// 4. Événements de licence : mise à jour du statut
//
// C'est ce qui fait enfin vivre la colonne `statut`. Dans l'ancien système
// elle proposait « Expiré » et « Suspendu » sans que rien ne les déclenche
// jamais.
// ---------------------------------------------------------------------
if (in_array($evenement, ['license.expired', 'license.revoked'], true)) {
    $licence = $champ($charge['license'] ?? [], ['id', 'key', 'license_key']);
    if ($licence === '') {
        $terminer('ignore', 'licence sans identifiant', 200, ['statut' => 'ignore']);
    }

    $statut = $evenement === 'license.expired' ? 'expire' : 'suspendu';
    $maj = $pdo->prepare('UPDATE membre SET statut = ? WHERE chariow_license = ?');
    $maj->execute([$statut, $licence]);

    yaac_log('licence_statut', [
        'licence' => $licence,
        'statut'  => $statut,
        'lignes'  => $maj->rowCount(),
    ]);
    $terminer('traite', $statut . ' (' . $maj->rowCount() . ' ligne)', 200, ['statut' => 'ok']);
}

// ---------------------------------------------------------------------
// 5. Vente confirmée : création du membre
// ---------------------------------------------------------------------
if ($evenement !== 'successful.sale') {
    $terminer('ignore', 'événement non traité : ' . $evenement, 200, ['statut' => 'ignore']);
}

$vente   = is_array($charge['sale'] ?? null) ? $charge['sale'] : [];
$client  = is_array($charge['customer'] ?? null) ? $charge['customer'] : [];
$produit = is_array($charge['product'] ?? null) ? $charge['product'] : [];

// Filtre produit : si la boutique vend autre chose que l'adhésion, un achat
// sans rapport ne doit pas fabriquer un membre.
$produit_attendu = (string) ($config['chariow']['product_id'] ?? '');
if ($produit_attendu !== '' && $champ($produit, ['id']) !== $produit_attendu) {
    $terminer('ignore', 'produit hors adhésion', 200, ['statut' => 'ignore']);
}

$email = strtolower($champ($client, ['email', 'customer_email']));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    yaac_log('vente_sans_email', ['delivery_id' => $delivery_id]);
    $terminer('erreur', 'e-mail client absent ou invalide', 200, ['statut' => 'erreur']);
}

$prenom = $champ($client, ['first_name', 'firstname', 'prenom', 'given_name']);
$nom    = $champ($client, ['last_name', 'lastname', 'nom', 'family_name', 'surname']);

// Repli : certaines charges ne fournissent qu'un nom complet.
if ($prenom === '' && $nom === '') {
    $complet = $champ($client, ['name', 'full_name', 'fullname']);
    if ($complet !== '') {
        $morceaux = preg_split('/\s+/', $complet, 2) ?: [];
        $prenom   = $morceaux[0] ?? '';
        $nom      = $morceaux[1] ?? '';
    }
}
if ($prenom === '' && $nom === '') {
    $prenom = 'Membre';
    $nom    = 'YAAC';
    yaac_log('vente_sans_nom', ['delivery_id' => $delivery_id, 'email' => $email]);
}

// ---------------------------------------------------------------------
// Rapprochement avec la candidature
//
// Le jeton a été attaché à la vente par adhesion-init.php. Il revient ici
// dans `custom_metadata`, ce qui relie le paiement au formulaire sans
// dépendre du visiteur qui aurait ressaisi le même e-mail.
//
// Repli sur l'e-mail si le jeton manque : une vente créée à la main dans
// Chariow, hors du formulaire, n'a pas de métadonnée.
// ---------------------------------------------------------------------
$metadonnees = is_array($vente['custom_metadata'] ?? null) ? $vente['custom_metadata'] : [];
$jeton       = $champ($metadonnees, ['candidature']);
$candidature = null;

if ($jeton !== '') {
    $req = $pdo->prepare('SELECT * FROM candidature WHERE jeton = ? LIMIT 1');
    $req->execute([$jeton]);
    $candidature = $req->fetch() ?: null;
}
if ($candidature === null) {
    $req = $pdo->prepare(
        'SELECT * FROM candidature WHERE email = ? AND membre_id IS NULL
          ORDER BY cree_le DESC LIMIT 1'
    );
    $req->execute([$email]);
    $candidature = $req->fetch() ?: null;

    if ($candidature !== null) {
        yaac_log('candidature_rapprochee_par_email', ['email' => $email]);
    }
}

$montants = is_array($vente['amounts'] ?? null) ? $vente['amounts'] : [];
$aujourdhui = new DateTimeImmutable('today');
$expiration = $aujourdhui->modify('+' . (int) $config['adhesion']['validite_annees'] . ' years');

// La candidature prime sur la charge Chariow quand elle existe : le visiteur
// y a saisi son nom lui-même, alors que Chariow ne connaît que ce que le
// checkout a bien voulu retenir.
if ($candidature !== null) {
    $prenom = $candidature['prenom'] !== '' ? $candidature['prenom'] : $prenom;
    $nom    = $candidature['nom'] !== ''    ? $candidature['nom']    : $nom;
}

$membre = [
    'prenom'          => $prenom,
    'nom'             => $nom,
    'email'           => $email,
    'telephone'       => $candidature['telephone']
        ?? ($champ($client, ['phone', 'phone_number', 'telephone']) ?: null),
    'pays'            => $candidature['pays_residence']
        ?? ($champ($client, ['country', 'country_code', 'pays']) ?: null),
    'naissance'       => $candidature['date_naissance'] ?? null,
    'pays_origine'    => $candidature['pays_origine'] ?? null,
    'motivation'      => $candidature['motivation'] ?? null,
    'benevolat'       => $candidature['benevolat'] ?? null,
    'source'          => $candidature['source'] ?? null,
    'statuts'         => $candidature !== null ? (int) $candidature['statuts_acceptes'] : 0,
    'role'            => (string) $config['adhesion']['role_defaut'],
    'date_adhesion'   => $aujourdhui->format('Y-m-d'),
    'date_expiration' => $expiration->format('Y-m-d'),
    'sale_id'         => $champ($vente, ['id', 'sale_id']) ?: null,
    'licence'         => $champ(is_array($charge['license'] ?? null) ? $charge['license'] : [], ['id', 'key']) ?: null,
    'montant'         => isset($montants['value']) ? (int) round((float) $montants['value'] * 100) : null,
    'devise'          => $champ($montants, ['currency']) ?: null,
];

// ---------------------------------------------------------------------
// 6. Attribution du numéro et écriture, en transaction
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

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
        $membre['telephone'], $membre['naissance'],
        $membre['pays_origine'], $membre['pays'], $membre['motivation'],
        $membre['benevolat'], $membre['source'], $membre['statuts'],
        $membre['role'], $membre['date_adhesion'], $membre['date_expiration'], 'actif',
        $membre['sale_id'], $membre['licence'], $membre['montant'], $membre['devise'],
    ]);

    // Referme la candidature : sans ce lien, elle resterait comptée comme un
    // abandon avant paiement.
    if ($candidature !== null) {
        $pdo->prepare('UPDATE candidature SET membre_id = ? WHERE id = ?')
            ->execute([(int) $pdo->lastInsertId(), (int) $candidature['id']]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();

    // Doublon : même e-mail ou même vente déjà enregistrée. Ce n'est pas une
    // erreur — c'est précisément la protection voulue contre la personne qui
    // resoumet, et contre une réémission Chariow avec un nouveau delivery_id.
    if ($e->getCode() === '23000') {
        yaac_log('adhesion_doublon', ['email' => $email, 'sale' => $membre['sale_id']]);
        $terminer('ignore', 'membre déjà enregistré', 200, ['statut' => 'deja_membre']);
    }

    yaac_log('adhesion_insertion_echouee', ['message' => $e->getMessage()]);
    // 500 : Chariow réémettra, et le verrou d'idempotence autorise le rejeu.
    $terminer('erreur', 'insertion échouée', 500, ['erreur' => 'insertion_echouee']);
}

// ---------------------------------------------------------------------
// 7. Envoi de la carte
//
// Après le commit, à dessein : si le SMTP tombe, le membre existe quand même
// et l'envoi est rejouable. L'inverse — envoyer puis écrire — perdrait le
// membre et laisserait une carte dans la nature.
// ---------------------------------------------------------------------
$envoi = yaac_envoyer_carte($membre);

$pdo->prepare(
    'UPDATE membre SET carte_envoyee_le = ?, carte_erreur = ? WHERE numero = ?'
)->execute([
    $envoi['ok'] ? gmdate('Y-m-d H:i:s') : null,
    $envoi['ok'] ? null : $envoi['erreur'],
    $membre['numero'],
]);

yaac_log('adhesion_creee', [
    'numero'      => $membre['numero'],
    'email'       => $email,
    'carte_envoyee' => $envoi['ok'],
]);

// 200 même si l'e-mail a échoué : le membre est enregistré, et faire
// réémettre Chariow ne referait pas partir l'e-mail — l'insertion suivante
// serait rejetée en doublon. L'échec est visible dans `carte_erreur`.
$terminer(
    'traite',
    'membre ' . $membre['numero'] . ($envoi['ok'] ? '' : ' — e-mail en échec'),
    200,
    ['statut' => 'ok', 'numero' => $membre['numero'], 'carte_envoyee' => $envoi['ok']]
);
