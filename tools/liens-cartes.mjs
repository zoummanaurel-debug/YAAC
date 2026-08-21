/**
 * Génère les liens de carte des membres déjà en base.
 *
 *   node tools/liens-cartes.mjs <secret> [fichier-membres.sql]
 *
 * Les membres importés n'ont jamais reçu l'e-mail d'adhésion : ils n'ont donc
 * pas le lien qui remplit leur carte automatiquement. Sans jeton, le
 * générateur retomberait en saisie manuelle et leur QR code ne renverrait
 * qu'une vérification réduite, sans nom.
 *
 * Le jeton se recalcule — il n'est stocké nulle part — d'où ce script plutôt
 * qu'une requête en base.
 *
 * `<secret>` est soit le `verification_secret` lui-même, soit le chemin d'un
 * fichier qui le contient. Il n'apparaît jamais dans la sortie.
 *
 * La sortie contient des noms et des e-mails : elle va sur le Bureau, hors du
 * dépôt, qui est public.
 */
import { createHmac } from 'node:crypto';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const SITE = 'https://yaac.network';

const [brut, source = 'db/import-membres.local.sql'] = process.argv.slice(2);
if (!brut) {
  console.error('Usage : node tools/liens-cartes.mjs <secret|chemin> [membres.sql]');
  process.exit(1);
}

// Le secret peut arriver en clair ou via un fichier : la seconde forme évite
// de le laisser dans l'historique du terminal.
let secret = brut;
if (existsSync(brut)) {
  const m = readFileSync(brut, 'utf8').match(/([0-9a-f]{64})/);
  if (!m) { console.error('Aucun secret de 64 caracteres hexa trouve dans', brut); process.exit(1); }
  secret = m[1];
}
if (!/^[0-9a-f]{64}$/.test(secret)) {
  console.error('Le secret doit faire 64 caracteres hexadecimaux.');
  process.exit(1);
}

/** Même calcul que `yaac_jeton_carte()` dans public/api/_lib.php. */
const jeton = (numero) =>
  createHmac('sha256', secret).update(numero.toUpperCase().trim()).digest('hex').slice(0, 10);

/** Découpe une ligne VALUES SQL en respectant les apostrophes doublées. */
function champs(ligne) {
  const t = ligne.trim().replace(/^\(/, '').replace(/\),?$/, '');
  const out = [];
  let courant = '', dansChaine = false, i = 0;
  while (i < t.length) {
    const c = t[i];
    if (dansChaine) {
      if (c === "'" && t[i + 1] === "'") { courant += "'"; i += 2; continue; }
      if (c === "'") { dansChaine = false; i++; continue; }
      courant += c; i++; continue;
    }
    if (c === "'") { dansChaine = true; i++; continue; }
    if (c === ',') { out.push(courant.trim()); courant = ''; i++; continue; }
    courant += c; i++;
  }
  out.push(courant.trim());
  return out;
}

const lignes = readFileSync(source, 'utf8')
  .split('\n')
  .filter((l) => l.trim().startsWith("('YAAC"));

if (!lignes.length) { console.error('Aucun membre trouve dans', source); process.exit(1); }

// Colonnes de l'INSERT : numero, prenom, nom, email, telephone, tel_pays, ...
const membres = lignes.map((l) => {
  const f = champs(l);
  return { numero: f[0], prenom: f[1], nom: f[2], email: f[3] };
});

const sansEmail = membres.filter((m) => !m.email || m.email === 'NULL');

// Point-virgule et BOM : Excel en francais attend ce separateur, et sans BOM
// il lit l'UTF-8 comme du Latin-1 — « Bénin » devient « BÃ©nin ».
const csv = [
  'Nom complet;E-mail;Numero;Lien de la carte',
  ...membres.map((m) => {
    const lien = `${SITE}/badge/?id=${m.numero}&c=${jeton(m.numero)}`;
    const nom = `${m.prenom} ${m.nom}`.trim().replace(/;/g, ',');
    return `${nom};${m.email === 'NULL' ? '' : m.email};${m.numero};${lien}`;
  }),
].join('\r\n');

const dest = process.env.USERPROFILE
  ? `${process.env.USERPROFILE}/Desktop/YAAC-liens-cartes.local.csv`
  : 'YAAC-liens-cartes.local.csv';

writeFileSync(dest, '\uFEFF' + csv, 'utf8');

console.log(`${membres.length} liens generes -> ${dest}`);
console.log(`  numeros : ${membres[0].numero} a ${membres[membres.length - 1].numero}`);
if (sansEmail.length) {
  console.log(`  ATTENTION : ${sansEmail.length} membre(s) sans e-mail :`);
  sansEmail.forEach((m) => console.log(`    ${m.numero}  ${m.prenom} ${m.nom}`));
}
