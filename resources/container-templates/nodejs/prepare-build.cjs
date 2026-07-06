#!/usr/bin/env node
/**
 * Talksasa container build preparation.
 * Patches common TypeScript / framework settings so hosted Git pulls can build
 * without failing on strict type-check or lint steps in CI-like environments.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = process.cwd();
const TALKSASA_DIR = path.join(ROOT, '.talksasa');
const MARKER = path.join(TALKSASA_DIR, 'build-prepared.json');

function readJsonc(filePath) {
    const text = fs.readFileSync(filePath, 'utf8');
    const stripped = text
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\/\/.*$/gm, '');

    return JSON.parse(stripped);
}

function writeJson(filePath, data) {
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2) + '\n');
}

function patchTsConfig(filePath) {
    if (!fs.existsSync(filePath)) {
        return false;
    }

    let data;
    try {
        data = readJsonc(filePath);
    } catch {
        return false;
    }

    data.compilerOptions = data.compilerOptions || {};
    const lib = new Set(Array.isArray(data.compilerOptions.lib) ? data.compilerOptions.lib : []);
    ['es2022', 'dom', 'dom.iterable'].forEach((entry) => lib.add(entry));
    data.compilerOptions.lib = [...lib];

    if (!data.compilerOptions.target) {
        data.compilerOptions.target = 'ES2022';
    }

    writeJson(filePath, data);

    return true;
}

function patchAllTsConfigs() {
    const patched = [];
    const candidates = ['tsconfig.json', 'tsconfig.app.json'];

    for (const name of candidates) {
        const filePath = path.join(ROOT, name);
        if (patchTsConfig(filePath)) {
            patched.push(name);
        }
    }

    return patched;
}

function packageUsesNext() {
    const pkgPath = path.join(ROOT, 'package.json');
    if (!fs.existsSync(pkgPath)) {
        return false;
    }

    try {
        const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
        const deps = {
            ...(pkg.dependencies || {}),
            ...(pkg.devDependencies || {}),
        };

        return Boolean(deps.next);
    } catch {
        return false;
    }
}

function packageUsesNuxt() {
    const pkgPath = path.join(ROOT, 'package.json');
    if (!fs.existsSync(pkgPath)) {
        return false;
    }

    try {
        const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
        const deps = {
            ...(pkg.dependencies || {}),
            ...(pkg.devDependencies || {}),
        };

        return Boolean(deps.nuxt);
    } catch {
        return false;
    }
}

function ensureTalksasaDir() {
    fs.mkdirSync(TALKSASA_DIR, { recursive: true });
}

function wrapNextConfig() {
    if (!packageUsesNext()) {
        return null;
    }

    const markerData = fs.existsSync(MARKER)
        ? JSON.parse(fs.readFileSync(MARKER, 'utf8'))
        : {};

    if (markerData.nextWrapped) {
        return markerData.nextWrapped;
    }

    const candidates = ['next.config.ts', 'next.config.mjs', 'next.config.js', 'next.config.cjs'];
    let userFile = null;

    for (const name of candidates) {
        if (fs.existsSync(path.join(ROOT, name))) {
            userFile = name;
            break;
        }
    }

    ensureTalksasaDir();

    const overlayConfig = `/** @type {import('next').NextConfig} */
const talksasaOverlay = {
  typescript: { ignoreBuildErrors: true },
  eslint: { ignoreDuringBuilds: true },
};

function mergeConfig(user) {
  const resolved = typeof user === 'function' ? user : user;
  return {
    ...(resolved || {}),
    typescript: { ...(resolved?.typescript || {}), ...talksasaOverlay.typescript },
    eslint: { ...(resolved?.eslint || {}), ...talksasaOverlay.eslint },
  };
}

`;

    if (!userFile) {
        const created = 'next.config.js';
        fs.writeFileSync(
            path.join(ROOT, created),
            overlayConfig + 'module.exports = mergeConfig({});\n'
        );
        markerData.nextWrapped = { created };

        return markerData.nextWrapped;
    }

    const backup = `next.config.user.talksasa${path.extname(userFile)}`;
    fs.renameSync(path.join(ROOT, userFile), path.join(ROOT, backup));

    const ext = path.extname(backup);
    let wrapper;

    if (ext === '.mjs') {
        wrapper = overlayConfig + `const loadUser = async () => {
  const mod = await import('./${backup}');
  const config = mod.default ?? mod;
  return typeof config === 'function' ? await config() : config;
};

module.exports = async (phase, defaultConfig) => {
  const user = await loadUser();
  const resolved = typeof user === 'function' ? await user(phase, defaultConfig) : user;
  return mergeConfig(resolved);
};
`;
    } else if (ext === '.ts') {
        wrapper = overlayConfig + 'module.exports = mergeConfig({});\n';
    } else {
        wrapper = overlayConfig + `const loadUser = () => require('./${backup}');
const config = loadUser();
const resolved = config.default ?? config;
module.exports = mergeConfig(typeof resolved === 'function' ? resolved() : resolved);
`;
    }

    fs.writeFileSync(path.join(ROOT, 'next.config.js'), wrapper);
    markerData.nextWrapped = { wrapped: userFile, backup };

    return markerData.nextWrapped;
}

function wrapNuxtConfig() {
    if (!packageUsesNuxt()) {
        return null;
    }

    // Nuxt apps rely on tsconfig patching; config wrapping is handled per-project.
    return { skipped: 'nuxt-config-wrap' };
}

function main() {
    ensureTalksasaDir();

    const result = {
        preparedAt: new Date().toISOString(),
        tsconfig: patchAllTsConfigs(),
        next: wrapNextConfig(),
        nuxt: wrapNuxtConfig(),
    };

    fs.writeFileSync(MARKER, JSON.stringify(result, null, 2) + '\n');
}

main();
