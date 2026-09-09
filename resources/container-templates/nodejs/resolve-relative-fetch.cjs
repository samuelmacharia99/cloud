'use strict';

const base = String(
    process.env.TALKSASA_FETCH_BASE
    || process.env.INTERNAL_API_URL
    || process.env.BACKEND_URL
    || process.env.API_URL
    || ''
).replace(/\/+$/, '');

if (base === '' || typeof globalThis.fetch !== 'function') {
    return;
}

const originalFetch = globalThis.fetch.bind(globalThis);

function resolveInput(input) {
    if (typeof input === 'string' && input.startsWith('/')) {
        return base + input;
    }

    if (input instanceof URL && input.pathname && !input.protocol) {
        return base + input.pathname + input.search + input.hash;
    }

    if (typeof Request === 'function' && input instanceof Request) {
        const href = String(input.url || '');
        if (href.startsWith('/')) {
            return new Request(base + href, input);
        }
    }

    return input;
}

globalThis.fetch = function talksasaResolveRelativeFetch(input, init) {
    return originalFetch(resolveInput(input), init);
};
