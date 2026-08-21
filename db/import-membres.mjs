/**
 * Génère le SQL d'import des membres existants à partir de « Membres.xlsx ».
 *
 *   node db/import-membres.mjs "C:/chemin/vers/Membres.xlsx" > db/import-membres.local.sql
 *
 * Le fichier produit contient des données personnelles (e-mails, téléphones,
 * dates de naissance, motivations). Il porte le suffixe `.local.sql`, qui est
 * ignoré par git : le dépôt est PUBLIC, ce fichier ne doit jamais y entrer.
 *
 * Aucune dépendance : un .xlsx est une archive ZIP, et les deux entrées utiles
 * (sharedStrings.xml et sheet1.xml) se lisent avec le zlib de Node.
 */

import { readFileSync } from 'node:fs';
import { inflateRawSync } from 'node:zlib';

const source = process.argv[2];
if (!source) {
  console.error('Usage : node db/import-membres.mjs <chemin/vers/Membres.xlsx>');
  process.exit(1);
}

/** Extrait une entrée d'une archive ZIP, sans dépendance externe. */
function lireEntreeZip(buffer, nom) {
  // On parcourt les en-têtes locaux (signature PK\x03\x04) plutôt que le
  // catalogue central : suffisant ici, et ça évite d'implémenter les deux.
  for (let i = 0; i < buffer.length - 4; i++) {
    if (buffer.readUInt32LE(i) !== 0x04034b50) continue;

    const compression = buffer.readUInt16LE(i + 8);
    const tailleComp = buffer.readUInt32LE(i + 18);
    const tailleNom = buffer.readUInt16LE(i + 26);
    const tailleExtra = buffer.readUInt16LE(i + 28);
    const debutNom = i + 30;
    const nomEntree = buffer.toString('utf8', debutNom, debutNom + tailleNom);

    if (nomEntree !== nom) continue;

    const debutDonnees = debutNom + tailleNom + tailleExtra;
    const donnees = buffer.subarray(debutDonnees, debutDonnees + tailleComp);
    return compression === 0 ? donnees.toString('utf8') : inflateRawSync(donnees).toString('utf8');
  }
  throw new Error(`entrée introuvable dans l'archive : ${nom}`);
}

function decoderXml(s) {
  return s
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
    .replace(/&apos;/g, "'").replace(/&amp;/g, '&');
}

/** Date série Excel → ISO. Époque 1899-12-30 (le bug bissextile de 1900 inclus). */
function dateExcel(serie) {
  const n = Number(serie);
  if (!Number.isFinite(n) || n <= 0) return null;
  const ms = Date.UTC(1899, 11, 30) + n * 86400000;
  const d = new Date(ms);
  return Number.isNaN(d.getTime()) ? null : d.toISOString().slice(0, 10);
}

function sql(valeur) {
  if (valeur === null || valeur === undefined || valeur === '') return 'NULL';
  // Les réponses libres du formulaire (motivation, bénévolat) contiennent des
  // retours à la ligne. Un saut littéral dans une chaîne MySQL est valide,
  // mais il éclate l'enregistrement sur plusieurs lignes du fichier, ce qui
  // rend l'import illisible et toute relecture par ligne fausse. On les
  // échappe pour garder un enregistrement par ligne.
  return (
    "'" +
    String(valeur)
      .replace(/\\/g, '\\\\')
      .replace(/'/g, "''")
      .replace(/\r/g, '\\r')
      .replace(/\n/g, '\\n') +
    "'"
  );
}

const archive = readFileSync(source);
const chaines = [...lireEntreeZip(archive, 'xl/sharedStrings.xml').matchAll(/<si>([\s\S]*?)<\/si>/g)]
  .map((m) => decoderXml([...m[1].matchAll(/<t[^>]*>([\s\S]*?)<\/t>/g)].map((t) => t[1]).join('')));

const feuille = lireEntreeZip(archive, 'xl/worksheets/sheet1.xml');
const lignes = [];

for (const [, corps] of feuille.matchAll(/<row[^>]*>([\s\S]*?)<\/row>/g)) {
  const cellules = {};
  for (const c of corps.matchAll(/<c r="([A-Z]+)\d+"([^>]*)>([\s\S]*?)<\/c>/g)) {
    const [, colonne, attributs, contenu] = c;
    const v = (contenu.match(/<v>([\s\S]*?)<\/v>/) || [])[1];
    const inline = (contenu.match(/<is>[\s\S]*?<t[^>]*>([\s\S]*?)<\/t>/) || [])[1];
    let valeur = inline !== undefined ? decoderXml(inline) : v;
    if (/t="s"/.test(attributs) && v !== undefined) valeur = chaines[+v];
    cellules[colonne] = valeur === undefined ? '' : String(valeur).trim();
  }
  lignes.push(cellules);
}

const membres = lignes.slice(1).filter((c) => (c.A || '').startsWith('YAAC-'));

console.log('-- Import des membres existants — généré par db/import-membres.mjs');
console.log(`-- Source : ${source}`);
console.log(`-- ${membres.length} membre(s). NE PAS VERSIONNER : données personnelles.`);
console.log('');
console.log('SET NAMES utf8mb4;');
console.log('');
console.log(
  'INSERT INTO membre\n' +
  '  (numero, prenom, nom, email, telephone, date_naissance, pays_origine,\n' +
  '   pays_residence, motivation, benevolat, source, statuts_acceptes,\n' +
  '   role, date_adhesion, date_expiration, statut)\n' +
  'VALUES'
);

const valeurs = membres.map((c) => {
  // Les colonnes M..N (Date_Adhesion, Date_Expiration) sont vides dans le
  // fichier actuel. On retombe sur la date du jour, et l'expiration à +5 ans.
  const adhesion = dateExcel(c.M) || new Date().toISOString().slice(0, 10);
  const expiration =
    dateExcel(c.N) ||
    (() => {
      const d = new Date(adhesion + 'T00:00:00Z');
      d.setUTCFullYear(d.getUTCFullYear() + 5);
      return d.toISOString().slice(0, 10);
    })();

  const accepte = /^(yes|oui)/i.test(c.L || '') ? 1 : 0;

  return (
    '  (' +
    [
      sql(c.A), sql(c.B), sql(c.C), sql((c.D || '').toLowerCase()), sql(c.E),
      sql(dateExcel(c.F)), sql(c.G), sql(c.H), sql(c.I), sql(c.J), sql(c.K),
      accepte, sql('Membre'), sql(adhesion), sql(expiration), sql('actif'),
    ].join(', ') +
    ')'
  );
});

console.log(valeurs.join(',\n'));
// Rejouable sans casse : un second passage ne duplique rien.
console.log('ON DUPLICATE KEY UPDATE numero = numero;');
console.log('');
console.log('-- Le compteur doit repartir au-dessus du plus grand numéro attribué.');
console.log('UPDATE compteur_membre');
console.log("   SET dernier = GREATEST(dernier, COALESCE(");
console.log("     (SELECT MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)) FROM membre), 0))");
console.log(' WHERE id = 1;');
