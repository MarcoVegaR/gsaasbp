import fs from "node:fs";
import path from "node:path";

function fail(msg) {
  console.error(`\n[CI SSO CSP] ❌ ${msg}\n`);
  process.exit(1);
}

const target = path.resolve(process.cwd(), "app/Support/Sso/SsoAutoSubmitPage.php");

if (!fs.existsSync(target)) {
  fail(`No se encontró ${target}.`);
}

const source = fs.readFileSync(target, "utf8");

if (!source.includes("script-src 'sha256-")) {
  fail("La página auto-submit no define CSP con hash sha256 para script-src.");
}

if (source.includes("unsafe-inline")) {
  fail("CSP insegura detectada: contiene unsafe-inline.");
}

if (!source.includes("frame-ancestors 'none'")) {
  fail("Falta frame-ancestors 'none' en la CSP de auto-submit.");
}

if (!source.includes("X-Frame-Options")) {
  fail("Falta encabezado X-Frame-Options en respuesta auto-submit.");
}

console.log("[CI SSO CSP] ✅ Contrato CSP hash + clickjacking OK.");
