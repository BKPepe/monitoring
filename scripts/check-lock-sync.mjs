import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import semver from 'semver';

/**
 * Overuje, ze package-lock.json odpovida tomu, co pozaduji package.json.
 *
 * Proc to existuje: v CI se instaluje pres `npm ci`, ktery pri nesouladu
 * odmitne nainstalovat cokoli. Lokalne se ale bezne pousti `npm install`,
 * ktery rozdil sam dorovna - takze rozejity lock se v pracovni kopii nijak
 * neprojevi a spadne az v CI, po pushi, na vsech workflow najednou.
 *
 * Presne to se stalo s override `undici: ^7.29.0` (bezpecnostni zaplata pro
 * miniflare): override se pridal do package.json, lock se neprogeneroval a
 * tri deploye i Quality Gate spadly na "Missing: undici@7.29.0 from lock
 * file". Navic to lokalne neslo reprodukovat - npm 11 nesoulad toleruje,
 * npm 10 v CI ne.
 *
 * Kontroluje se to, co lock skutecne obsahuje, ne verze npm: pro kazdy
 * pozadavek musi v locku existovat balik, ktery ho splnuje.
 */

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

const readJson = (rel) => JSON.parse(readFileSync(join(ROOT, rel), 'utf8'));

const lock = readJson('package-lock.json');
const rootPkg = readJson('package.json');

/**
 * Verze, kterou by Node z daneho mista skutecne nacetl.
 *
 * Hleda se stejne jako pri resolvu: nejdriv `<dir>/node_modules/<name>`,
 * pak smerem ke koreni. Pouhe "existuje nekde v locku" nestaci - kdyz ma
 * root vitest 4 a apps/monitor si drzi vlastni 3.2.7, testy pobezi na trojce,
 * i kdyz manifest workspacu rika neco jineho.
 */
const resolveFrom = (dir, name) => {
  const segments = dir === '' ? [] : dir.split('/');
  for (let i = segments.length; i >= 0; i--) {
    const path = [...segments.slice(0, i), 'node_modules', name].join('/');
    const entry = lock.packages?.[path];
    if (entry?.version) return { version: entry.version, path };
  }
  return null;
};

/** Vsechny verze balicku ve strome - jen pro srozumitelnou hlasku. */
const versionsOf = (name) =>
  Object.entries(lock.packages ?? {})
    .filter(
      ([path, entry]) => entry?.version && (path === `node_modules/${name}` || path.endsWith(`/node_modules/${name}`))
    )
    .map(([, entry]) => entry.version);

const problems = [];

/**
 * Pozadavek musi splnovat ta verze, kterou by Node z `dir` opravdu nacetl.
 * `dir === null` znamena "kdekoli ve strome" - to je pripad overrides, ktere
 * plati globalne a nemaji jedno konkretni misto pouziti.
 */
const requireSatisfied = (name, spec, where, dir) => {
  // Aliasy (npm:pkg@1.2.3), git a file: URL semver neresi - lock je u nich
  // zdrojem pravdy a porovnani by jen falesne kricelo.
  if (!semver.validRange(spec)) return;

  const ok = (v) => semver.satisfies(v, spec, { includePrerelease: true });

  if (dir === null) {
    const versions = versionsOf(name);
    if (versions.length === 0) problems.push(`${where}: ${name}@${spec} chybi v locku uplne`);
    else if (!versions.some(ok)) problems.push(`${where}: ${name}@${spec} - lock ma jen ${versions.join(', ')}`);
    return;
  }

  const resolved = resolveFrom(dir, name);
  if (!resolved) {
    problems.push(`${where}: ${name}@${spec} chybi v locku uplne`);
  } else if (!ok(resolved.version)) {
    problems.push(`${where}: ${name}@${spec} - resolvuje se na ${resolved.version} (${resolved.path})`);
  }
};

// 1. Zavislosti korene a vsech workspacu.
const manifests = [['', rootPkg]];
for (const ws of rootPkg.workspaces ?? []) {
  manifests.push([ws, readJson(`${ws}/package.json`)]);
}
for (const [dir, pkg] of manifests) {
  const where = dir === '' ? 'package.json' : `${dir}/package.json`;
  for (const field of ['dependencies', 'devDependencies']) {
    for (const [name, spec] of Object.entries(pkg[field] ?? {})) {
      requireSatisfied(name, spec, `${where} (${field})`, dir);
    }
  }
}

// 2. Overrides. Prave tady vznikl puvodni pad: override je bezpecnostni
//    zaplata, takze kdyz se do locku nepromitne, tise se dal instaluje
//    zranitelna verze - i kdyby `npm ci` prosel.
const walkOverrides = (node, trail) => {
  for (const [name, value] of Object.entries(node ?? {})) {
    if (name.startsWith('//')) continue; // komentarove klice
    if (typeof value === 'string') {
      requireSatisfied(name, value, `package.json (overrides${trail})`, null);
    } else if (value && typeof value === 'object') {
      if (typeof value['.'] === 'string') requireSatisfied(name, value['.'], `package.json (overrides${trail})`, null);
      walkOverrides(value, `${trail} > ${name}`);
    }
  }
};
walkOverrides(rootPkg.overrides, '');

// 3. Nativni binarky pro cizi platformy.
//
//    Balicky jako @astrojs/compiler-binding, esbuild nebo lightningcss maji
//    pro kazdou platformu vlastni npm balik a vybiraji ho az za behu.
//    Lock musi obsahovat vsechny, protoze se generuje na macOS, ale instaluje
//    taky na linuxovem runneru.
//
//    Kdyz se lock smaze a vygeneruje znovu, npm do nej zapise uz jen platformu
//    stroje, na kterem bezi. Lokalne se nestane nic - chybi jen binarky, ktere
//    tady nikdo nepotrebuje. V CI pak build spadne na "Cannot find native
//    binding", coz na rozejity lock vubec nevypada.
//
//    Presne tak spadl build apps/site: v locku zbylo darwin-arm64 z devíti
//    platforem a linux-x64-gnu mezi nimi nebyl.
const PLATFORM_SUFFIX = /-(linux|darwin|win32|android|freebsd|openbsd|sunos|wasm32)[-_]?/i;
const missingBinaries = new Map();

for (const [ownerPath, entry] of Object.entries(lock.packages ?? {})) {
  for (const name of Object.keys(entry?.optionalDependencies ?? {})) {
    if (!PLATFORM_SUFFIX.test(name)) continue;
    if (lock.packages?.[`node_modules/${name}`]) continue;
    const owner = ownerPath.split('node_modules/').pop() || ownerPath;
    if (!missingBinaries.has(owner)) missingBinaries.set(owner, new Set());
    missingBinaries.get(owner).add(name);
  }
}

for (const [owner, nameSet] of missingBinaries) {
  const names = [...nameSet];
  // Vypisuji se jen prvni ctyri - u sharp jich chybi pres dvacet a cely
  // seznam by zpravu utopil.
  const shown = names.slice(0, 4).join(', ');
  const rest = names.length > 4 ? `, a dalsich ${names.length - 4}` : '';
  problems.push(`${owner}: v locku chybi nativni binarky pro ${names.length} platforem (${shown}${rest})`);
}

if (problems.length > 0) {
  console.error('package-lock.json neodpovida package.json:\n');
  for (const p of problems) console.error(`  ${p}`);
  if (missingBinaries.size > 0) {
    console.error('\nChybejici binarky `npm install` nedoplni - zapise jen platformu tohoto stroje.');
    console.error('Vratte lock z gitu (`git checkout package-lock.json`) a upravte ho prirustkove.');
    console.error('Lock se nikdy nemaze a negeneruje znovu: prijde tim o vsechny ostatni platformy.');
  }
  console.error('\nU ostatnich rozdilu staci `npm install` a vysledek commitnout.');
  console.error('Bez toho `npm ci` v CI odmitne instalaci a spadne kazdy deploy.');
  process.exit(1);
}

console.log(`Lock odpovida package.json (${manifests.length} manifestu).`);
