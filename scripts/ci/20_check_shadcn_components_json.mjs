import fs from "node:fs";
import path from "node:path";

function fail(msg) {
  console.error(`\n[CI SHADCN] ❌ ${msg}\n`);
  process.exit(1);
}

const SCHEMA_VERSION = "2026-02-22"; // bump SOLO con PR dedicado (workflow operativo)
const COMPONENTS_JSON = path.resolve(process.cwd(), "components.json");
if (!fs.existsSync(COMPONENTS_JSON)) {
  fail("No existe components.json en raíz del repo. (Requerido por el contrato shadcn)");
}

let cfg;
try {
  cfg = JSON.parse(fs.readFileSync(COMPONENTS_JSON, "utf8"));
} catch (e) {
  fail(`components.json inválido (JSON parse error): ${e?.message}`);
}

// Allowlist (schema versionado)
const allowedTop = new Set(["$schema", "style", "tailwind", "rsc", "tsx", "aliases", "registries", "iconLibrary"]);
const allowedTailwind = new Set(["config", "css", "baseColor", "cssVariables", "prefix"]);
const allowedAliases = new Set(["utils", "components", "ui", "lib", "hooks"]);

for (const k of Object.keys(cfg)) {
  if (!allowedTop.has(k)) {
    fail(
      `Key no permitida en components.json: "${k}". ` +
        `Actualiza allowlist (SCHEMA_VERSION=${SCHEMA_VERSION}) solo vía PR dedicado.` 
    );
  }
}

// $schema (opcional, pero si existe debe ser el oficial)
if (cfg.$schema !== undefined) {
  const expected = "https://ui.shadcn.com/schema.json";
  if (cfg.$schema !== expected) {
    fail(`$schema inesperado: ${cfg.$schema}. Esperado: ${expected}`);
  }
}

// tailwind keys
if (cfg.tailwind !== undefined) {
  if (!cfg.tailwind || typeof cfg.tailwind !== "object" || Array.isArray(cfg.tailwind)) {
    fail(`tailwind debe ser objeto.`);
  }
  for (const k of Object.keys(cfg.tailwind)) {
    if (!allowedTailwind.has(k)) {
      fail(
        `tailwind.${k} no permitido. ` +
          `Actualiza allowlist (SCHEMA_VERSION=${SCHEMA_VERSION}) solo vía PR dedicado.` 
      );
    }
  }
}

// aliases keys
if (cfg.aliases !== undefined) {
  if (!cfg.aliases || typeof cfg.aliases !== "object" || Array.isArray(cfg.aliases)) {
    fail(`aliases debe ser objeto.`);
  }
  for (const k of Object.keys(cfg.aliases)) {
    if (!allowedAliases.has(k)) {
      fail(
        `aliases.${k} no permitido. ` +
          `Actualiza allowlist (SCHEMA_VERSION=${SCHEMA_VERSION}) solo vía PR dedicado.` 
      );
    }
  }
}

// registries enforcement: solo default (@shadcn) o ausente
if (cfg.registries !== undefined) {
  if (!cfg.registries || typeof cfg.registries !== "object" || Array.isArray(cfg.registries)) {
    fail(`registries debe ser objeto.`);
  }
  const keys = Object.keys(cfg.registries);
  const allowedRegistryKeys = new Set(["@shadcn"]);
  for (const k of keys) {
    if (!allowedRegistryKeys.has(k)) {
      fail(`Registry no permitido: "${k}". Solo se permite "@shadcn" o ausencia de registries.`);
    }
  }

  if (cfg.registries["@shadcn"] !== undefined) {
    const v = cfg.registries["@shadcn"];
    // forma permitida (string) según docs de shadcn: https://ui.shadcn.com/r/{name}.json
    if (typeof v !== "string") {
      fail(`registries["@shadcn"] debe ser string. (Contrato: sin objetos/auth/headers)`);
    }
    const expected = "https://ui.shadcn.com/r/{name}.json";
    if (v !== expected) {
      fail(`registries["@shadcn"] inválido: ${v}. Esperado: ${expected}`);
    }
  }
}

console.log(`[CI SHADCN] ✅ components.json OK (schema=${SCHEMA_VERSION}, registry default only).`);
