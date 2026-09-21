<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Exporta pagos correctos de Shopify con tarjeta y PayPal en formato CSV.">

    <title>Shopify Payment CSV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="src/css/styles.css">
</head>
<body>
    <main class="container py-5">
        <div class="export-panel mx-auto">
            <div class="mb-4">
                <p class="text-uppercase text-primary fw-semibold small mb-2">Informe de pagos</p>
                <h1 class="h2 mb-2">Shopify Payment CSV</h1>
                <p class="text-body-secondary mb-0">Exporta las transacciones correctas con tarjeta y PayPal de un periodo de hasta 92 días.</p>
            </div>

            <form id="export-form" action="export.php" method="post" novalidate>
                <div class="mb-3">
                    <label for="shop" class="form-label">Tienda Shopify</label>
                    <select class="form-select" id="shop" name="shop" required>
                        <option value="" selected disabled>Selecciona una tienda</option>
                        <option value="ohyeah">OHYEAH</option>
                        <option value="horeca">HORECA</option>
                    </select>
                    <div class="invalid-feedback">Selecciona una tienda Shopify.</div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="date-from" class="form-label">Fecha desde</label>
                        <input class="form-control" type="date" id="date-from" name="date_from" required>
                        <div class="invalid-feedback">Selecciona el primer día del informe.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="date-to" class="form-label">Fecha hasta</label>
                        <input class="form-control" type="date" id="date-to" name="date_to" required>
                        <div class="invalid-feedback">Selecciona el último día del informe.</div>
                    </div>
                </div>

                <div id="form-error" class="alert alert-danger d-none" role="alert"></div>

                <button id="submit-button" class="btn btn-primary w-100" type="submit">
                    <span id="submit-spinner" class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true"></span>
                    <span id="submit-label">Generar CSV</span>
                </button>

                <p id="export-status" class="status-message text-body-secondary small mb-0 mt-3" role="status" aria-live="polite"></p>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="src/js/main.js" defer></script>
</body>
</html>
