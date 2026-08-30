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

/**
 * Strip hardcoded next start -p/--port and force -H 0.0.0.0.
 * Next then honors the container PORT env (platform proxy target).
 */
function patchPackageJsonNextListen() {
    const pkgPath = path.join(ROOT, 'package.json');
    if (!fs.existsSync(pkgPath)) {
        return null;
    }

    let pkg;
    try {
        pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
    } catch {
        return null;
    }

    if (!pkg.scripts || typeof pkg.scripts.start !== 'string') {
        return null;
    }

    const original = pkg.scripts.start;
    if (!/\bnext\s+start\b/i.test(original)) {
        return null;
    }

    let start = original
        .replace(/(^|\s)(-p|--port)\s+\d+/gi, ' ')
        .replace(/(^|\s)(-H|--hostname)\s+\S+/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    if (!/\bnext\s+start\b/i.test(start)) {
        return null;
    }

    start = start.replace(/\bnext\s+start\b/i, 'next start -H 0.0.0.0');

    if (start === original) {
        return null;
    }

    pkg.scripts.start = start;
    writeJson(pkgPath, pkg);

    return { from: original, to: start };
}

function generatePrismaClient() {
    const schemaRel = ['prisma/schema.prisma', 'schema.prisma'].find((relative) =>
        fs.existsSync(path.join(ROOT, relative))
    );
    if (!schemaRel) {
        return { skipped: 'no-schema' };
    }

    const cli = path.join(ROOT, 'node_modules/prisma/build/index.js');
    if (!fs.existsSync(cli)) {
        return { skipped: 'prisma-cli-missing', schema: schemaRel };
    }

    const { spawnSync } = require('child_process');
    // Do not set PRISMA_CLI_BINARY_TARGETS. That env is for CLI engine
    // downloads and rejects "native" (valid only in schema.prisma).
    // generate already follows schema binaryTargets; Alpine OpenSSL is
    // installed before this script so detection matches openssl-3.0.x.
    const result = spawnSync(process.execPath, [cli, 'generate'], {
        cwd: ROOT,
        encoding: 'utf8',
        env: process.env,
    });

    return {
        schema: schemaRel,
        status: result.status,
        stdout: String(result.stdout || '').slice(-500),
        stderr: String(result.stderr || '').slice(-800),
    };
}

function main() {
    ensureTalksasaDir();

    const prisma = generatePrismaClient();
    const result = {
        preparedAt: new Date().toISOString(),
        tsconfig: patchAllTsConfigs(),
        next: wrapNextConfig(),
        nuxt: wrapNuxtConfig(),
        nextListen: patchPackageJsonNextListen(),
        prisma,
    };

    fs.writeFileSync(MARKER, JSON.stringify(result, null, 2) + '\n');

    if (prisma && Number.isInteger(prisma.status) && prisma.status !== 0) {
        if (prisma.stderr) {
            process.stderr.write(prisma.stderr);
        }
        process.exit(prisma.status);
    }
}

main();
