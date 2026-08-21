/**
 * Genere le jeu d icones du site depuis le logo aux deux feuilles.
 *
 *   node tools/favicons.mjs [chemin/vers/logo.png]
 *
 * Utilise sharp, deja present comme dependance d Astro pour l optimisation
 * des images : aucune installation supplementaire.
 */
import sharp from 'sharp';
import { writeFileSync } from 'node:fs';

const SRC = process.argv[2] ?? 'C:/Users/PC/Desktop/yaac-feuilles.png';
const OUT = 'public';

// Le PNG d'origine laisse une large marge transparente autour des feuilles.
// A 16 px, cette marge mange la moitie de l'icone et le motif devient une
// tache. On recadre sur le contenu reel, puis on ajoute une marge maitrisee.
const source = sharp(SRC).trim({ threshold: 10 });
const { width, height } = await source.toBuffer({ resolveWithObject: true })
  .then(({ info }) => info);
console.log(`  recadre sur le contenu : ${width}x${height}`);

const cote = Math.max(width, height);
const marge = Math.round(cote * 0.06);
const carre = Math.round(cote + marge * 2);

/** Motif recadre, centre dans un carre transparent. */
async function base() {
  const contenu = await sharp(SRC).trim({ threshold: 10 }).toBuffer();
  return sharp({
    create: {
      width: carre, height: carre, channels: 4,
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    },
  })
    .composite([{ input: contenu, gravity: 'center' }])
    .png()
    .toBuffer();
}

const motif = await base();

async function png(taille) {
  return sharp(motif)
    .resize(taille, taille, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ compressionLevel: 9 })
    .toBuffer();
}

// --- PNG autonomes -----------------------------------------------------
for (const [nom, taille] of [
  ['icon-192.png', 192],
  ['icon-512.png', 512],
]) {
  const b = await png(taille);
  writeFileSync(`${OUT}/${nom}`, b);
  console.log(`  ${nom.padEnd(18)} ${taille}x${taille}  ${Math.round(b.length / 1024)} Ko`);
}

// L'icone Apple ne gere pas la transparence : elle serait rendue en noir sur
// l'ecran d'accueil. On aplatit sur blanc.
const apple = await sharp(motif)
  .resize(180, 180, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 1 } })
  .flatten({ background: '#ffffff' })
  .png({ compressionLevel: 9 })
  .toBuffer();
writeFileSync(`${OUT}/apple-touch-icon.png`, apple);
console.log(`  apple-touch-icon   180x180  ${Math.round(apple.length / 1024)} Ko  (aplati sur blanc)`);

// --- favicon.ico -------------------------------------------------------
// Un .ico est un conteneur : depuis Vista, chaque image peut etre un PNG
// complet. On empile 16/32/48 pour que Windows et les onglets prennent la
// taille la plus nette sans re-echantillonner.
const tailles = [16, 32, 48];
const images = await Promise.all(tailles.map(png));

const ENTETE = 6, ENTREE = 16;
const ico = Buffer.alloc(ENTETE + ENTREE * images.length);
ico.writeUInt16LE(0, 0);                 // reserve
ico.writeUInt16LE(1, 2);                 // type 1 = icone
ico.writeUInt16LE(images.length, 4);

let offset = ENTETE + ENTREE * images.length;
images.forEach((img, i) => {
  const p = ENTETE + ENTREE * i;
  ico.writeUInt8(tailles[i] === 256 ? 0 : tailles[i], p);     // largeur
  ico.writeUInt8(tailles[i] === 256 ? 0 : tailles[i], p + 1); // hauteur
  ico.writeUInt8(0, p + 2);              // palette
  ico.writeUInt8(0, p + 3);              // reserve
  ico.writeUInt16LE(1, p + 4);           // plans
  ico.writeUInt16LE(32, p + 6);          // bits par pixel
  ico.writeUInt32LE(img.length, p + 8);
  ico.writeUInt32LE(offset, p + 12);
  offset += img.length;
});

const final = Buffer.concat([ico, ...images]);
writeFileSync(`${OUT}/favicon.ico`, final);
console.log(`  favicon.ico        ${tailles.join('/')}  ${Math.round(final.length / 1024)} Ko`);
