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
    let submitting = false;
    let slowExportTimer = null;

    class ExportError extends Error {}

    const inclusiveDays = (from, to) => {
        const [fromYear, fromMonth, fromDay] = from.split('-').map(Number);
        const [toYear, toMonth, toDay] = to.split('-').map(Number);
        const fromUtc = Date.UTC(fromYear, fromMonth - 1, fromDay);
        const toUtc = Date.UTC(toYear, toMonth - 1, toDay);

        return Math.floor((toUtc - fromUtc) / 86400000) + 1;
    };

    const setBusy = (busy) => {
        submitting = busy;
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
        window.setTimeout(() => URL.revokeObjectURL(url), 0);
    };

    const errorFromResponse = async (response) => {
        const contentType = response.headers.get('Content-Type') || '';

        if (!contentType.toLowerCase().includes('application/json')) {
            return 'No se ha podido generar el CSV.';
        }

        try {
            const payload = await response.json();

            return typeof payload.error === 'string' && payload.error !== ''
                ? payload.error
                : 'No se ha podido generar el CSV.';
        } catch {
            return 'No se ha podido generar el CSV.';
        }
    };

    dateFrom.addEventListener('change', validateDateRange);
    dateTo.addEventListener('change', validateDateRange);
    form.addEventListener('input', () => {
        if (!submitting) {
            clearError();
            status.textContent = '';
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (submitting) {
            return;
        }

        clearError();
        validateDateRange();
        form.classList.add('was-validated');

        if (!form.checkValidity()) {
            showError('Revisa los campos resaltados antes de generar el CSV.');
            return;
        }

        setBusy(true);
        status.textContent = 'Consultando los pedidos en Shopify...';
        slowExportTimer = window.setTimeout(() => {
            status.textContent = 'La exportación está tardando más de lo habitual. Puedes mantener esta página abierta.';
        }, 12000);

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
                throw new ExportError(await errorFromResponse(response));
            }

            const contentType = response.headers.get('Content-Type') || '';

            if (!contentType.toLowerCase().includes('text/csv')) {
                throw new ExportError('El servidor no ha devuelto un archivo CSV válido.');
            }

            const blob = await response.blob();
            downloadBlob(blob, filenameFromResponse(response));
            status.textContent = response.headers.get('X-Export-Row-Count') === '0'
                ? 'No se encontraron pedidos coincidentes. Se ha descargado un CSV solo con las cabeceras.'
                : 'El CSV se ha generado correctamente.';
        } catch (error) {
            showError(error instanceof ExportError
                ? error.message
                : 'No se ha podido conectar con el servidor. Comprueba tu conexión e inténtalo de nuevo.');
        } finally {
            window.clearTimeout(slowExportTimer);
            slowExportTimer = null;
            setBusy(false);
        }
    });
})();
