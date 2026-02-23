import fs from "node:fs";
import path from "node:path";
import zlib from "node:zlib";

function fail(msg) {
  console.error(`\n[CI VITE BUDGET] ❌ ${msg}\n`);
  process.exit(1);
}

const BUDGET_BYTES = 300 * 1024; // 300KB gzip
const buildDir = process.env.VITE_BUILD_DIR
  ? path.resolve(process.cwd(), process.env.VITE_BUILD_DIR)
  : path.resolve(process.cwd(), "public", "build");

const manifestCandidates = [
  path.join(buildDir, "manifest.json"),
  path.join(buildDir, ".vite", "manifest.json"),
];

const manifestPath = manifestCandidates.find((p) => fs.existsSync(p));
if (!manifestPath) {
  fail(`No se encontró manifest.json en ${manifestCandidates.join(" o ")}.`);
}

let manifest;
try {
  manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));
} catch (e) {
  fail(`No se pudo parsear manifest.json: ${e?.message}`);
}

if (!manifest || typeof manifest !== "object") {
  fail("manifest shape changed: root no es objeto Record<key, chunk>.");
}

// Localiza entry
const entryKeys = Object.entries(manifest)
  .filter(([, chunk]) => chunk && typeof chunk === "object" && chunk.isEntry === true)
  .map(([k]) => k);

if (entryKeys.length === 0) {
  fail('manifest shape changed: no se encontró ningún chunk con "isEntry: true".');
}

const forcedEntryKey = process.env.VITE_ENTRY_KEY;
let entryKey = forcedEntryKey ?? null;

if (!entryKey) {
  // si hay múltiples entries, exige VITE_ENTRY_KEY para evitar ambigüedad
  if (entryKeys.length > 1) {
    fail(
      `Múltiples entries detectados (${entryKeys.join(", ")}). ` +
        `Define VITE_ENTRY_KEY en CI para seleccionar el entry correcto.` 
    );
  }
  entryKey = entryKeys[0];
}

const entry = manifest[entryKey];
if (!entry || typeof entry !== "object") {
  fail(`manifest shape changed: entryKey "${entryKey}" no existe o no es objeto.`);
}

if (typeof entry.file !== "string" || !entry.file.endsWith(".js")) {
  fail(`manifest shape changed: entry.file inválido o no es .js (entryKey=${entryKey}).`);
}

function assertImportsShape(chunk, keyName) {
  if (chunk.imports === undefined) {
    // Contrato: queremos array (aunque sea vacío); si falta, consideramos shape cambiado
    fail(`manifest shape changed: falta "imports" en chunk ${keyName}.`);
  }
  if (!Array.isArray(chunk.imports)) {
    fail(`manifest shape changed: "imports" no es array en chunk ${keyName}.`);
  }
}

assertImportsShape(entry, entryKey);

// DFS por imports transitivos (ignora dynamicImports)
const visitedKeys = new Set();
const jsFiles = new Set();

function resolveChunkFile(chunkKey) {
  const c = manifest[chunkKey];
  if (!c || typeof c !== "object") {
    fail(`manifest shape changed: import key "${chunkKey}" no existe en manifest.`);
  }
  if (typeof c.file !== "string") {
    fail(`manifest shape changed: chunk "${chunkKey}" no tiene "file" string.`);
  }
  return c;
}

function walk(chunkKey) {
  if (visitedKeys.has(chunkKey)) return;
  visitedKeys.add(chunkKey);

  const chunk = resolveChunkFile(chunkKey);

  // Solo JS
  if (chunk.file.endsWith(".js")) {
    jsFiles.add(chunk.file);
  }

  assertImportsShape(chunk, chunkKey);

  for (const impKey of chunk.imports) {
    walk(impKey);
  }

  // Ignoramos dynamicImports por contrato.
}

walk(entryKey);

function gzipSize(fileRel) {
  const abs = path.join(buildDir, fileRel);
  if (!fs.existsSync(abs)) {
    fail(`Archivo referenciado por manifest no existe: ${abs}`);
  }
  const buf = fs.readFileSync(abs);
  return zlib.gzipSync(buf).byteLength;
}

let total = 0;
const breakdown = [];

for (const f of jsFiles) {
  const sz = gzipSize(f);
  total += sz;
  breakdown.push([f, sz]);
}

breakdown.sort((a, b) => b[1] - a[1]);

if (total > BUDGET_BYTES) {
  const lines = breakdown
    .slice(0, 20)
    .map(([f, sz]) => `  - ${f}: ${(sz / 1024).toFixed(1)} KB`)
    .join("\n");
  fail(
    `JS inicial excede presupuesto: ${(total / 1024).toFixed(1)} KB > ${(BUDGET_BYTES / 1024).toFixed(
      1
    )} KB.\nTop chunks:\n${lines}\n\n(entryKey=${entryKey}, manifest=${manifestPath})`
  );
}

console.log(
  `[CI VITE BUDGET] ✅ JS inicial ${(total / 1024).toFixed(1)} KB gzip (≤ ${(BUDGET_BYTES / 1024).toFixed(
    1
  )} KB). entryKey=${entryKey}`
);
