@once
<style>
    #app-dialog-root:not(.is-open) { display: none; }
    #app-dialog-root.is-open { display: flex; }
</style>
@endonce

<div
    id="app-dialog-root"
    class="fixed inset-0 z-[9999] items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="app-dialog-title"
    aria-describedby="app-dialog-message"
>
    <div id="app-dialog-backdrop" class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>

    <div class="relative w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-2xl">
        <div class="px-6 pt-6 pb-4">
            <h3 id="app-dialog-title" class="text-lg font-semibold text-slate-900 dark:text-white"></h3>
            <p id="app-dialog-message" class="mt-3 text-sm text-slate-600 dark:text-slate-300 whitespace-pre-line"></p>
        </div>

        <div class="px-6 pb-6 flex items-center justify-end gap-3">
            <button
                type="button"
                id="app-dialog-cancel"
                class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 text-sm font-medium transition-colors"
            >
                Cancel
            </button>
            <button
                type="button"
                id="app-dialog-accept"
                class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium transition-colors"
            >
                Continue
            </button>
        </div>
    </div>
</div>

@once
<script>
(function () {
    if (window.__appDialogReady) {
        return;
    }
    window.__appDialogReady = true;

    const root = document.getElementById('app-dialog-root');
    const backdrop = document.getElementById('app-dialog-backdrop');
    const titleEl = document.getElementById('app-dialog-title');
    const messageEl = document.getElementById('app-dialog-message');
    const cancelBtn = document.getElementById('app-dialog-cancel');
    const acceptBtn = document.getElementById('app-dialog-accept');

    let resolver = null;

    function closeDialog(result) {
        root.classList.remove('is-open');
        document.body.classList.remove('overflow-hidden');
        const resolve = resolver;
        resolver = null;
        resolve?.(result);
    }

    function openDialog({ mode = 'alert', title = 'Notice', message = '', confirmLabel = 'Continue' }) {
        titleEl.textContent = title;
        messageEl.textContent = message;
        acceptBtn.textContent = mode === 'confirm' ? confirmLabel : 'OK';
        cancelBtn.style.display = mode === 'confirm' ? '' : 'none';
        root.classList.add('is-open');
        document.body.classList.add('overflow-hidden');
        acceptBtn.focus();

        return new Promise((resolve) => {
            resolver = resolve;
        });
    }

    acceptBtn.addEventListener('click', () => closeDialog(true));
    cancelBtn.addEventListener('click', () => closeDialog(false));
    backdrop.addEventListener('click', () => closeDialog(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && root.classList.contains('is-open')) {
            closeDialog(false);
        }
    });

    window.appAlert = (message, title = 'Notice') =>
        openDialog({ mode: 'alert', title, message, confirmLabel: 'OK' });

    window.appConfirm = (message, title = 'Please confirm', confirmLabel = 'Continue') =>
        openDialog({ mode: 'confirm', title, message, confirmLabel });

    window.alert = (message) => {
        window.appAlert(String(message));
    };

    function migrateLegacyConfirmAttributes() {
        document.querySelectorAll('form[onsubmit]').forEach((form) => {
            const attr = form.getAttribute('onsubmit') || '';
            const match = attr.match(/return\s+confirm\((['"`])((?:\\.|(?!\1)[^\\])*)\1\)/);
            if (!match) {
                return;
            }

            form.removeAttribute('onsubmit');
            form.dataset.confirm = match[2].replace(/\\'/g, "'").replace(/\\"/g, '"').replace(/\\n/g, '\n');
        });

        document.querySelectorAll('[onclick]').forEach((element) => {
            const attr = element.getAttribute('onclick') || '';
            const match = attr.match(/return\s+confirm\((['"`])((?:\\.|(?!\1)[^\\])*)\1\)/);
            if (!match) {
                return;
            }

            element.removeAttribute('onclick');
            element.dataset.confirm = match[2].replace(/\\'/g, "'").replace(/\\"/g, '"').replace(/\\n/g, '\n');
        });
    }

    function bindConfirmForms() {
        document.addEventListener('submit', (event) => {
            const form = event.target.closest('form[data-confirm]');
            if (!form) {
                return;
            }

            if (form.dataset.confirmed === '1') {
                delete form.dataset.confirmed;
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            const message = form.dataset.confirm || 'Are you sure?';
            const title = form.dataset.confirmTitle || 'Please confirm';
            const confirmLabel = form.dataset.confirmLabel || 'Continue';

            window.appConfirm(message, title, confirmLabel).then((accepted) => {
                if (!accepted) {
                    return;
                }

                form.dataset.confirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        }, true);

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-confirm]:not(form)');
            if (!trigger || trigger.closest('form[data-confirm]')) {
                return;
            }

            if (trigger.dataset.confirmed === '1') {
                delete trigger.dataset.confirmed;
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            const message = trigger.dataset.confirm || 'Are you sure?';
            const title = trigger.dataset.confirmTitle || 'Please confirm';
            const confirmLabel = trigger.dataset.confirmLabel || 'Continue';

            window.appConfirm(message, title, confirmLabel).then((accepted) => {
                if (!accepted) {
                    return;
                }

                const form = trigger.closest('form');
                if (form) {
                    form.dataset.confirmed = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                    return;
                }

                if (trigger.tagName === 'A' && trigger.href) {
                    window.location.href = trigger.href;
                    return;
                }

                trigger.dataset.confirmed = '1';
                trigger.click();
            });
        }, true);
    }

    function initAppDialog() {
        migrateLegacyConfirmAttributes();
        bindConfirmForms();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAppDialog);
    } else {
        initAppDialog();
    }
})();
</script>
@endonce
