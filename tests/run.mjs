/**
 * Lance les tests PHP sans jamais écrire de config.php dans public/api/.
 *
 * `yaac_config()` lit `__DIR__ . '/config.php'` : les fichiers sont donc
 * recopiés dans un dossier temporaire, avec la config factice, et c'est là
 * que les tests tournent. Un config.php laissé dans public/api/ finirait
 * publié par la build — voir le garde-fou du workflow de déploiement.
 *
 *   node tests/run.mjs
 */
import { copyFileSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ici = dirname(fileURLToPath(import.meta.url));
const api = join(ici, '..', 'public', 'api');

const php = spawnSync(process.platform === 'win32' ? 'where' : 'which', ['php'], { encoding: 'utf8' });
if (php.status !== 0) {
  console.error("php introuvable dans le PATH. Installez-le : winget install --id PHP.PHP.8.4 --scope user");
  process.exit(127);
}
const bin = php.stdout.trim().split(/\r?\n/)[0];

const box = mkdtempSync(join(tmpdir(), 'yaac-tests-'));
try {
  for (const f of ['_lib.php', '_mail.php']) copyFileSync(join(api, f), join(box, f));
  copyFileSync(join(ici, 'config.fixture.php'), join(box, 'config.php'));
  copyFileSync(join(ici, 'api.test.php'), join(box, 'test.php'));

  // Contrôle de syntaxe sur tous les points d'entrée, puis tests fonctionnels.
  let souci = 0;
  for (const f of ['adhesion.php', 'adhesion-init.php', 'verify.php', '_lib.php', '_mail.php']) {
    const r = spawnSync(bin, ['-l', join(api, f)], { encoding: 'utf8' });
    if (r.status !== 0) { console.error(r.stdout || r.stderr); souci++; }
  }
  console.log(souci === 0 ? '5 fichiers PHP : aucune erreur de syntaxe\n' : `${souci} fichier(s) en erreur\n`);

  const r = spawnSync(bin, [join(box, 'test.php')], { encoding: 'utf8', stdio: 'inherit' });
  process.exit(souci === 0 ? (r.status ?? 1) : 1);
} finally {
  rmSync(box, { recursive: true, force: true });
}
