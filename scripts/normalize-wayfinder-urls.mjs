import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { extname } from 'node:path';

const PROJECT_ROOT = process.cwd();
const TARGET_DIRS = [
    `${PROJECT_ROOT}/resources/js/routes`,
    `${PROJECT_ROOT}/resources/js/actions`,
];

const HOST_PREFIX_PATTERN = /(["'])\/\/(localhost|127\.0\.0\.1)(\/[^"']*)\1/g;

const collectTsFiles = (dir) => {
    const entries = readdirSync(dir, { withFileTypes: true });

    return entries.flatMap((entry) => {
        const absolutePath = `${dir}/${entry.name}`;

        if (entry.isDirectory()) {
            return collectTsFiles(absolutePath);
        }

        if (entry.isFile() && extname(absolutePath) === '.ts') {
            return [absolutePath];
        }

        return [];
    });
};

for (const dir of TARGET_DIRS) {
    if (!statSync(dir, { throwIfNoEntry: false })?.isDirectory()) {
        continue;
    }

    const files = collectTsFiles(dir);

    for (const file of files) {
        const current = readFileSync(file, 'utf8');
        const normalized = current.replace(
            HOST_PREFIX_PATTERN,
            (_fullMatch, quote, _host, pathWithQuery) => `${quote}${pathWithQuery}${quote}`,
        );

        if (normalized !== current) {
            writeFileSync(file, normalized);
        }
    }
}
