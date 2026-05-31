/**
 * Dialog UI is rendered inline via <x-app-dialog /> (no Vite build required).
 * This module only re-exports helpers for ES modules that import app-dialog.js.
 */

export function appDialog() {
    return {};
}

export function showAppDialog(options = {}) {
    if (typeof window.appConfirm === 'function' && options.mode === 'confirm') {
        return window.appConfirm(options.message ?? '', options.title ?? 'Please confirm', options.confirmLabel ?? 'Continue');
    }

    if (typeof window.appAlert === 'function') {
        return window.appAlert(options.message ?? '', options.title ?? 'Notice');
    }

    return Promise.resolve(false);
}
