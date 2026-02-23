import { spawnSync } from "node:child_process";

function fail(msg) {
  console.error(`\n[CI REACT] ❌ ${msg}\n`);
  process.exit(1);
}

function collectVersions(tree, pkgName, acc) {
  if (!tree || typeof tree !== "object") return;
  const deps = tree.dependencies;
  if (!deps || typeof deps !== "object") return;

  for (const [name, dep] of Object.entries(deps)) {
    if (name === pkgName) {
      const v = dep?.version;
      if (typeof v !== "string" || v.trim() === "") {
        fail(`No se pudo resolver versión de ${pkgName} en algún nodo (version missing).`);
      }
      acc.add(v);
    }
    collectVersions(dep, pkgName, acc);
  }
}

const res = spawnSync("npm", ["ls", "react", "react-dom", "--json"], {
  encoding: "utf8",
  shell: process.platform === "win32",
});

// npm ls puede devolver exit code != 0 por "problems"; intentamos parsear igual.
const stdout = (res.stdout || "").trim();
if (!stdout) {
  fail(`npm ls no retornó JSON. stderr:\n${res.stderr || "(vacío)"}`);
}

let tree;
try {
  tree = JSON.parse(stdout);
} catch (e) {
  fail(`No se pudo parsear JSON de npm ls. Error: ${e?.message}\nSalida:\n${stdout.slice(0, 800)}...`);
}

const reactVersions = new Set();
const reactDomVersions = new Set();
collectVersions(tree, "react", reactVersions);
collectVersions(tree, "react-dom", reactDomVersions);

if (reactVersions.size === 0) fail("No se encontró react en el árbol (¿npm ci corrió?).");
if (reactDomVersions.size === 0) fail("No se encontró react-dom en el árbol (¿npm ci corrió?).");

const reactUnique = [...reactVersions];
const reactDomUnique = [...reactDomVersions];

function ensureMajor19(setName, versions) {
  for (const v of versions) {
    if (!/^19\./.test(v)) {
      fail(`${setName}: versión no permitida detectada: ${v}. (Se requiere 19.x en todos los nodos)`);
    }
  }
}

ensureMajor19("react", reactUnique);
ensureMajor19("react-dom", reactDomUnique);

// Criterio de duplicado simétrico: fallar si hay más de una versión distinta
if (reactVersions.size > 1) {
  fail(`React drift detectado. Versiones distintas: ${reactUnique.join(", ")}`);
}
if (reactDomVersions.size > 1) {
  fail(`ReactDOM drift detectado. Versiones distintas: ${reactDomUnique.join(", ")}`);
}

console.log(`[CI REACT] ✅ react=${reactUnique[0]} react-dom=${reactDomUnique[0]} (sin drift).`);
