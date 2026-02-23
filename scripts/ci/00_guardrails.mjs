import fs from "node:fs";
import path from "node:path";

function fail(msg) {
  console.error(`\n[CI GUARDRAILS] ❌ ${msg}\n`);
  process.exit(1);
}

const major = Number(String(process.versions.node).split(".")[0]);
if (!Number.isFinite(major) || major < 20) {
  fail(`Node >= 20 requerido. Detectado: ${process.versions.node}`);
}

// Bloqueo duro a --force (ambiental)
const forceEnv =
  (process.env.NPM_CONFIG_FORCE ?? process.env.npm_config_force ?? "").toString().toLowerCase();
if (forceEnv === "true" || forceEnv === "1") {
  fail("Prohibido: NPM_CONFIG_FORCE=true (o npm_config_force=true).");
}

// Bloqueo a --force en args (por si se llama directo)
if (process.argv.includes("--force")) {
  fail("Prohibido: Uso de --force en argumentos.");
}

// Lockfiles prohibidos
const forbiddenLockfiles = ["yarn.lock", "pnpm-lock.yaml"];
for (const lf of forbiddenLockfiles) {
  if (fs.existsSync(path.resolve(process.cwd(), lf))) {
    fail(`Lockfile prohibido detectado: ${lf}. (npm-only contract)`);
  }
}

console.log("[CI GUARDRAILS] ✅ Node/npm guardrails OK.");
