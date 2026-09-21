(() => {
    'use strict';

    const form = document.querySelector('#export-form');

    if (!form) {
        return;
    }

    const submitButton = document.querySelector('#submit-button');
    const submitSpinner = document.querySelector('#submit-spinner');
    const submitLabel = document.querySelector('#submit-label');
    const status = document.querySelector('#export-status');
    const errorBox = document.querySelector('#form-error');
    const dateFrom = document.querySelector('#date-from');
    const dateTo = document.querySelector('#date-to');

    const inclusiveDays = (from, to) => {
        const [fromYear, fromMonth, fromDay] = from.split('-').map(Number);
        const [toYear, toMonth, toDay] = to.split('-').map(Number);
        const fromUtc = Date.UTC(fromYear, fromMonth - 1, fromDay);
        const toUtc = Date.UTC(toYear, toMonth - 1, toDay);

        return Math.floor((toUtc - fromUtc) / 86400000) + 1;
    };

    const setBusy = (busy) => {
        submitButton.disabled = busy;
        submitSpinner.classList.toggle('d-none', !busy);
        submitLabel.textContent = busy ? 'Generando CSV...' : 'Generar CSV';
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
    };

    const showError = (message) => {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
        status.textContent = '';
    };

    const clearError = () => {
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    };

    const validateDateRange = () => {
        dateFrom.setCustomValidity('');
        dateTo.setCustomValidity('');

        if (!dateFrom.value || !dateTo.value) {
            return;
        }

        const days = inclusiveDays(dateFrom.value, dateTo.value);

        if (days < 1) {
            dateTo.setCustomValidity('La fecha final debe ser igual o posterior a la fecha inicial.');
            return;
        }

        if (days > 92) {
            dateTo.setCustomValidity('El periodo seleccionado no puede superar los 92 días.');
        }
    };

    const filenameFromResponse = (response) => {
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="([^"]+)"/i);

        return match ? match[1] : 'pagos-shopify.csv';
    };

    const downloadBlob = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    };

    dateFrom.addEventListener('change', validateDateRange);
    dateTo.addEventListener('change', validateDateRange);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        validateDateRange();
        form.classList.add('was-validated');

        if (!form.checkValidity()) {
            showError('Revisa los campos resaltados antes de generar el CSV.');
            return;
        }

        setBusy(true);
        status.textContent = 'Consultando los pedidos en Shopify...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'text/csv, application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const contentType = response.headers.get('Content-Type') || '';
                const payload = contentType.includes('application/json')
                    ? await response.json()
                    : { error: 'No se ha podido generar el CSV.' };

                throw new Error(payload.error || 'No se ha podido generar el CSV.');
            }

            const blob = await response.blob();
            downloadBlob(blob, filenameFromResponse(response));
            status.textContent = 'El CSV se ha generado correctamente.';
        } catch (error) {
            showError(error instanceof Error ? error.message : 'No se ha podido generar el CSV.');
        } finally {
            setBusy(false);
        }
    });
})();
