import fs from "node:fs";
import path from "node:path";

function fail(msg) {
  console.error(`\n[CI SSO LOGS] ❌ ${msg}\n`);
  process.exit(1);
}

const root = process.cwd();
const scanRoots = [
  path.join(root, "app"),
  path.join(root, "bootstrap"),
  path.join(root, "config"),
  path.join(root, "routes"),
];

const includeExtensions = new Set([".php", ".mjs", ".js", ".ts", ".yml", ".yaml", ".conf"]);
const violations = [];

const suspiciousPatterns = [
  /sso[\s\S]{0,120}\$request->all\(/gi,
  /sso[\s\S]{0,120}\$request->input\(/gi,
  /sso[\s\S]{0,120}\$request->getContent\(/gi,
  /sso[\s\S]{0,120}json_encode\(\$request/gi,
  /sso[\s\S]{0,120}Log::(debug|info|warning|error)\([\s\S]{0,120}\$request/gi,
  /idp[\s\S]{0,120}\$request->all\(/gi,
  /idp[\s\S]{0,120}\$request->getContent\(/gi,
];

function walk(dir) {
  if (!fs.existsSync(dir)) return;

  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const abs = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      walk(abs);
      continue;
    }

    const ext = path.extname(entry.name);
    if (!includeExtensions.has(ext)) continue;

    const rel = path.relative(root, abs);
    if (rel.startsWith("tests")) continue;

    const source = fs.readFileSync(abs, "utf8");

    for (const pattern of suspiciousPatterns) {
      pattern.lastIndex = 0;
      if (pattern.test(source)) {
        violations.push(`${rel} :: ${pattern}`);
        break;
      }
    }
  }
}

for (const scanRoot of scanRoots) {
  walk(scanRoot);
}

if (violations.length > 0) {
  fail(`Posibles request-body logs en endpoints SSO/IdP:\n${violations.join("\n")}`);
}

console.log("[CI SSO LOGS] ✅ No se detectaron request-body logs en superficies SSO/IdP.");
