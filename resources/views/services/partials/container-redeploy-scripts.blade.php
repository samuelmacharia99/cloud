async function confirmRedeploy(form) {
    const resetDb = form.querySelector('input[name="reset_database"]')?.checked
        || form.querySelector('input[type="hidden"][name="reset_database"]');
    const submitLabel = (form.querySelector('button[type="submit"]')?.textContent || 'Redeploy').trim();
    let message = submitLabel + ' now? This recreates the application runtime and keeps /app files.';
    if (resetDb) {
        message += '\n\nThe database volume will be wiped (all tables and data deleted).';
        message += '\nIf Laravel is installed, /app/.env will be refreshed and migrations will run.';
    } else {
        message += '\n\nDatabase data is kept unless you tick Reset database.';
    }

    const accepted = await window.appConfirm(message, submitLabel, submitLabel);
    if (accepted) {
        form.submit();
    }
}

function redeployStackPanel(stackOptions) {
    const options = stackOptions || {};
    const current = options.current || {};
    const initialDatabaseId = current.database_id != null ? String(current.database_id) : '';

    return {
        open: false,
        options,
        selectedFramework: current.framework || options.framework?.value || '',
        selectedFrontend: current.frontend || options.frontend?.value || 'none',
        backendRoot: current.backend_root || '',
        frontendRoot: current.frontend_root || '',
        selectedVersion: current.node_version_source === 'auto'
            ? ''
            : (current.selected_version || options.version_picker?.value || ''),
        selectedDatabaseId: initialDatabaseId,
        initialDatabaseId,
        resetDatabase: {{ config('containers.redeploy.reset_database_default', false) ? 'true' : 'false' }},
        get databaseChanged() {
            return String(this.selectedDatabaseId || '') !== String(this.initialDatabaseId || '');
        },
        async onFrameworkChange() {
            if (! this.options?.language?.id) {
                return;
            }
            try {
                const url = new URL(`{{ url('/api/languages') }}/${this.options.language.id}/stack-options`, window.location.origin);
                if (this.selectedFramework) {
                    url.searchParams.set('framework', this.selectedFramework);
                }
                const response = await fetch(url.toString(), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (! response.ok) {
                    return;
                }
                const data = await response.json();
                this.options = { ...this.options, ...data, current: this.options.current };
                if (data.frontend?.value) {
                    this.selectedFrontend = data.frontend.value;
                } else if (data.frontend?.options?.length === 1) {
                    this.selectedFrontend = data.frontend.options[0].value;
                }
            } catch (e) {
                console.error(e);
            }
        },
        async submitRedeploy(form) {
            if (this.options?.framework?.show && this.options.framework.required && ! this.selectedFramework) {
                await window.appConfirm('Select a framework before redeploying.', 'Missing framework', 'OK');
                return;
            }
            if (this.options?.frontend?.show && this.options.frontend.required && ! this.selectedFrontend) {
                await window.appConfirm('Select a frontend before redeploying.', 'Missing frontend', 'OK');
                return;
            }
            if (this.options?.database?.show && this.options.database.required && ! this.selectedDatabaseId && ! this.options.database.allow_none) {
                await window.appConfirm('Select a database before redeploying.', 'Missing database', 'OK');
                return;
            }
            await confirmRedeploy(form);
        },
    };
}
