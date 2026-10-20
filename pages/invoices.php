
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function invoiceMoney($amount): string
{
    return number_format((float)$amount, 2);
}

function invoiceStatusInfo(array $invoice): array
{
    $status = (string)$invoice['Status'];
    $total = (float)$invoice['TotalAmount'];
    $paid = (float)$invoice['PaidAmount'];

    if ($status === 'Cancelled') {
        return ['Cancelled', 'status-cancelled'];
    }

    if ($status === 'Draft') {
        return ['Draft', 'status-draft'];
    }

    if ($total > 0 && $paid >= $total - 0.01) {
        return ['Paid', 'status-paid'];
    }

    if ($paid > 0) {
        return ['Partially Paid', 'status-partial'];
    }

    if (!empty($invoice['DueDate']) && $invoice['DueDate'] < date('Y-m-d')) {
        return ['Overdue', 'status-overdue'];
    }

    return ['Issued', 'status-issued'];
}

/*
|--------------------------------------------------------------------------
| Search and filters
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$allowedFilters = [
    'Draft',
    'Issued',
    'Partially Paid',
    'Paid',
    'Overdue',
    'Cancelled',
];

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        inv.InvoiceNo LIKE ?
        OR h.HotelName LIKE ?
        OR d.DeliveryNo LIKE ?
        OR CAST(inv.InvoiceID AS CHAR) LIKE ?
        OR CAST(d.OrderID AS CHAR) LIKE ?
    )';

    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($dateFrom !== '') {
    $where[] = 'inv.InvoiceDate >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'inv.InvoiceDate <= ?';
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| Invoice query
|--------------------------------------------------------------------------
| PaidAmount is calculated from payment allocations.
| Display status is derived from invoice status, payment balance and due date.
*/
$sql = "
    SELECT
        inv.InvoiceID,
        inv.DeliveryID,
        inv.InvoiceNo,
        inv.InvoiceDate,
        inv.DueDate,
        inv.TotalAmount,
        inv.Status,
        inv.Notes,
        inv.CreatedAt,
        d.DeliveryNo,
        d.DeliveryDate,
        d.OrderID,
        h.HotelID,
        h.HotelName,
        h.ContactPerson,
        h.Email AS HotelEmail,
        h.Phone AS HotelPhone,
        h.Address AS HotelAddress,
        COALESCE((
            SELECT SUM(pa.AllocatedAmount)
            FROM payment_allocation pa
            WHERE pa.InvoiceID = inv.InvoiceID
        ), 0) AS PaidAmount,
        COALESCE((
            SELECT COUNT(*)
            FROM delivery_detail dd
            WHERE dd.DeliveryID = inv.DeliveryID
        ), 0) AS ItemCount
    FROM invoice inv
    INNER JOIN delivery d ON d.DeliveryID = inv.DeliveryID
    INNER JOIN hotel_order o ON o.OrderID = d.OrderID
    INNER JOIN hotel h ON h.HotelID = o.HotelID
    $whereSql
    ORDER BY inv.InvoiceDate DESC, inv.InvoiceID DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Filter by calculated invoice status
|--------------------------------------------------------------------------
*/
if ($statusFilter !== '' && in_array($statusFilter, $allowedFilters, true)) {
    $invoices = array_values(array_filter(
        $invoices,
        function (array $invoice) use ($statusFilter): bool {
            [$displayStatus] = invoiceStatusInfo($invoice);
            return $displayStatus === $statusFilter;
        }
    ));
}

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
| Summaries use all invoices, regardless of the list filters.
*/
$summarySql = "
    SELECT
        COUNT(*) AS TotalInvoices,
        COALESCE(SUM(CASE WHEN inv.Status <> 'Cancelled'
                          THEN inv.TotalAmount ELSE 0 END), 0) AS TotalIssued,
        COALESCE(SUM(CASE WHEN inv.Status <> 'Cancelled'
                          THEN COALESCE((
                              SELECT SUM(pa.AllocatedAmount)
                              FROM payment_allocation pa
                              WHERE pa.InvoiceID = inv.InvoiceID
                          ), 0) ELSE 0 END), 0) AS TotalPaid,
        COALESCE(SUM(
            CASE
                WHEN inv.Status NOT IN ('Cancelled', 'Draft')
                THEN GREATEST(
                    inv.TotalAmount - COALESCE((
                        SELECT SUM(pa.AllocatedAmount)
                        FROM payment_allocation pa
                        WHERE pa.InvoiceID = inv.InvoiceID
                    ), 0),
                    0
                )
                ELSE 0
            END
        ), 0) AS TotalOutstanding
    FROM invoice inv
";

$summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| View one invoice
|--------------------------------------------------------------------------
*/
$viewId = (int)($_GET['view'] ?? 0);
$viewInvoice = null;
$invoiceItems = [];

if ($viewId > 0) {
    $viewStmt = $pdo->prepare("
        SELECT
            inv.InvoiceID,
            inv.DeliveryID,
            inv.InvoiceNo,
            inv.InvoiceDate,
            inv.DueDate,
            inv.TotalAmount,
            inv.Status,
            inv.Notes,
            inv.CreatedAt,
            d.DeliveryNo,
            d.DeliveryDate,
            d.OrderID,
            h.HotelName,
            h.ContactPerson,
            h.Email AS HotelEmail,
            h.Phone AS HotelPhone,
            h.Address AS HotelAddress,
            COALESCE((
                SELECT SUM(pa.AllocatedAmount)
                FROM payment_allocation pa
                WHERE pa.InvoiceID = inv.InvoiceID
            ), 0) AS PaidAmount
        FROM invoice inv
        INNER JOIN delivery d ON d.DeliveryID = inv.DeliveryID
        INNER JOIN hotel_order o ON o.OrderID = d.OrderID
        INNER JOIN hotel h ON h.HotelID = o.HotelID
        WHERE inv.InvoiceID = ?
    ");

    $viewStmt->execute([$viewId]);
    $viewInvoice = $viewStmt->fetch(PDO::FETCH_ASSOC);

    if ($viewInvoice) {
        $itemStmt = $pdo->prepare("
            SELECT
                i.ItemCode,
                i.ItemName,
                u.UnitSymbol,
                dd.SuppliedQuantity,
                dd.UnitPrice,
                dd.LineTotal
            FROM delivery_detail dd
            INNER JOIN item i ON i.ItemID = (
                SELECT od.ItemID
                FROM hotel_order_detail od
                WHERE od.OrderDetailID = dd.OrderDetailID
            )
            LEFT JOIN unit u ON u.UnitID = i.UnitID
            WHERE dd.DeliveryID = ?
            ORDER BY i.ItemName
        ");

        $itemStmt->execute([(int)$viewInvoice['DeliveryID']]);
        $invoiceItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$flash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices | Hotel Supply Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .page-header {
            display:flex; justify-content:space-between; align-items:flex-start;
            gap:16px; flex-wrap:wrap; margin-bottom:24px;
        }
        .page-header h1 { margin:0 0 7px; font-size:27px; }
        .page-header p { margin:0; color:#718096; }

        .invoice-stats {
            display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
            gap:16px; margin-bottom:24px;
        }
        .stat-card,.panel {
            background:#fff; border:1px solid #e7ebf0; border-radius:12px;
            box-shadow:0 2px 8px rgba(15,23,42,.025);
        }
        .stat-card { padding:18px; }
        .stat-card span { display:block; font-size:13px; color:#718096; margin-bottom:8px; }
        .stat-card strong { font-size:23px; color:#172b4d; }
        .panel { padding:22px; margin-bottom:22px; }
        .panel-heading {
            display:flex; align-items:center; justify-content:space-between;
            flex-wrap:wrap; gap:12px; margin-bottom:20px;
        }
        .panel-heading h2 { margin:0; font-size:18px; }
        .muted { color:#778399; }

        .filter-grid {
            display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto;
            gap:12px; align-items:end;
        }
        .field { display:flex; flex-direction:column; gap:7px; min-width:0; }
        .field label { font-size:13px; font-weight:600; color:#344054; }
        .field input,.field select {
            width:100%; box-sizing:border-box; padding:10px;
            border:1px solid #d7dde5; border-radius:7px;
            min-height:41px; background:#fff; font:inherit;
        }

        .btn {
            display:inline-flex; align-items:center; justify-content:center;
            gap:6px; border:0; border-radius:7px; padding:10px 14px;
            cursor:pointer; font:inherit; font-size:13px; font-weight:600;
            text-decoration:none; white-space:nowrap;
        }
        .btn-primary { background:#3157c8; color:#fff; }
        .btn-secondary { background:#eef2f7; color:#344054; }
        .btn-small { padding:7px 10px; font-size:12px; }
        .actions { display:flex; flex-wrap:wrap; gap:6px; }

        .table-wrap { overflow-x:auto; }
        .data-table { width:100%; border-collapse:collapse; min-width:950px; }
        .data-table th,.data-table td {
            padding:13px 12px; border-bottom:1px solid #edf0f4;
            text-align:left; vertical-align:middle;
        }
        .data-table th {
            background:#f8fafc; font-size:12px; color:#667085; white-space:nowrap;
        }
        .data-table td { font-size:13px; }

        .status-badge {
            display:inline-block; border-radius:20px; padding:5px 9px;
            font-size:11px; font-weight:700; white-space:nowrap;
        }
        .status-draft { background:#eef2f7; color:#475467; }
        .status-issued { background:#e6efff; color:#244db0; }
        .status-partial { background:#fff4d6; color:#8a5b00; }
        .status-paid { background:#dcfae6; color:#067647; }
        .status-overdue { background:#fee4e2; color:#b42318; }
        .status-cancelled { background:#f2f4f7; color:#667085; }

        .empty-state { text-align:center; padding:30px 12px; color:#778399; }
        .amount { font-weight:700; white-space:nowrap; }
        .balance { font-weight:700; color:#b54708; white-space:nowrap; }

        .invoice-paper {
            max-width:900px; margin:0 auto; background:#fff;
            border:1px solid #e7ebf0; border-radius:8px; padding:36px;
        }
        .invoice-title {
            display:flex; justify-content:space-between; align-items:flex-start;
            gap:20px; padding-bottom:24px; border-bottom:2px solid #e7ebf0;
        }
        .invoice-title h2 { margin:0 0 7px; font-size:27px; color:#172b4d; }
        .invoice-number { text-align:right; }
        .invoice-number strong { font-size:19px; }
        .invoice-info {
            display:grid; grid-template-columns:repeat(2,minmax(0,1fr));
            gap:24px; margin:24px 0;
        }
        .invoice-info h3 { margin:0 0 10px; font-size:13px; color:#667085; text-transform:uppercase; }
        .invoice-info p { margin:4px 0; font-size:13px; overflow-wrap:anywhere; }
        .invoice-items { width:100%; border-collapse:collapse; margin-top:20px; }
        .invoice-items th,.invoice-items td {
            padding:12px 10px; border-bottom:1px solid #e7ebf0; text-align:left;
        }
        .invoice-items th { background:#f8fafc; font-size:12px; color:#667085; }
        .invoice-items td { font-size:13px; }
        .text-right { text-align:right !important; }
        .invoice-totals { width:340px; max-width:100%; margin:20px 0 0 auto; }
        .total-row {
            display:flex; justify-content:space-between; gap:16px;
            padding:8px 0; font-size:13px;
        }
        .total-row.grand-total {
            font-size:18px; font-weight:700; border-top:2px solid #172b4d;
            margin-top:6px; padding-top:14px;
        }
        .invoice-note {
            margin-top:32px; padding-top:18px; border-top:1px solid #e7ebf0;
            font-size:12px; color:#667085;
        }
        .flash {
            padding:13px 15px; border-radius:8px; margin-bottom:18px; font-size:13px;
        }
        .flash-success { background:#ecfdf3; color:#067647; border:1px solid #abefc6; }
        .flash-error { background:#fff1f0; color:#b42318; border:1px solid #fecdca; }

        @media(max-width:1000px) {
            .invoice-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media(max-width:650px) {
            .panel { padding:15px; }
            .invoice-stats { gap:10px; }
            .stat-card { padding:13px; }
            .stat-card strong { font-size:19px; }
            .filter-grid { grid-template-columns:1fr; }
            .invoice-paper { padding:18px; }
            .invoice-title { flex-direction:column; }
            .invoice-number { text-align:left; }
            .invoice-info { grid-template-columns:1fr; gap:14px; }
            .page-header h1 { font-size:23px; }
        }

        @media print {
            @page { size:A4; margin:14mm; }
            body { background:#fff !important; }
            .sidebar,.page-header,.invoice-stats,.list-panel,.no-print,
            .flash { display:none !important; }
            .app,.main { display:block !important; width:100% !important; margin:0 !important; padding:0 !important; }
            .panel { border:0 !important; box-shadow:none !important; padding:0 !important; margin:0 !important; }
            .invoice-paper { max-width:none; border:0; border-radius:0; padding:0; }
            .invoice-items th { background:#eee !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            a { color:#000 !important; text-decoration:none !important; }
        }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">HS</div>
            <div><h2>Hotel Supply</h2><small>Management System</small></div>
        </div>

        <p class="menu-label">MAIN MENU</p>
        <a class="nav-link" href="../index.php">▦ &nbsp; Dashboard</a>
        <a class="nav-link" href="categories.php">▤ &nbsp; Categories</a>
        <a class="nav-link" href="units.php">⚖ &nbsp; Units</a>
        <a class="nav-link" href="items.php">📦 &nbsp; Items</a>
        <a class="nav-link" href="hotels.php">🏨 &nbsp; Hotels</a>
        <a class="nav-link" href="vendors.php">🛒 &nbsp; Market Vendors</a>

        <p class="menu-label">OPERATIONS</p>
        <a class="nav-link" href="prices.php">💰 &nbsp; Price Lists</a>
        <a class="nav-link" href="purchases.php">🧾 &nbsp; Market Purchases</a>
        <a class="nav-link" href="orders.php">📋 &nbsp; Hotel Orders</a>
        <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
        <a class="nav-link active" href="invoices.php">📄 &nbsp; Invoices</a>
        <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
        <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
    </aside>

    <main class="main">
        <div class="page-header">
            <div>
                <h1>Invoices</h1>
                <p>Manage hotel invoices, payments, and outstanding balances.</p>
            </div>
            <a href="deliveries.php" class="btn btn-primary">＋ Create from Delivery</a>
        </div>

        <?php if ($flash): ?>
            <div class="flash <?= ($flash['type'] ?? '') === 'success' ? 'flash-success' : 'flash-error' ?>">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="invoice-stats">
            <div class="stat-card">
                <span>Total invoices</span>
                <strong><?= number_format((int)$summary['TotalInvoices']) ?></strong>
            </div>
            <div class="stat-card">
                <span>Total issued value</span>
                <strong><?= invoiceMoney($summary['TotalIssued']) ?></strong>
            </div>
            <div class="stat-card">
                <span>Total allocated payments</span>
                <strong><?= invoiceMoney($summary['TotalPaid']) ?></strong>
            </div>
            <div class="stat-card">
                <span>Outstanding balance</span>
                <strong><?= invoiceMoney($summary['TotalOutstanding']) ?></strong>
            </div>
        </section>

        <?php if ($viewInvoice): ?>
            <?php
            [$displayStatus, $displayClass] = invoiceStatusInfo($viewInvoice);
            $invoiceTotal = (float)$viewInvoice['TotalAmount'];
            $invoicePaid = (float)$viewInvoice['PaidAmount'];
            $invoiceBalance = max(0, $invoiceTotal - $invoicePaid);
            ?>
            <section class="panel">
                <div class="panel-heading no-print">
                    <div>
                        <h2>Invoice Details</h2>
                        <p class="muted" style="margin:6px 0 0">Review the invoice before printing.</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-secondary" href="invoices.php">← Back to Invoices</a>
                        <button class="btn btn-primary" type="button" onclick="window.print()">🖨 Print Invoice</button>
                    </div>
                </div>

                <div class="invoice-paper">
                    <div class="invoice-title">
                        <div>
                            <h2>INVOICE</h2>
                            <p style="margin:0;color:#667085">Hotel Supply Management</p>
                            <p style="margin:5px 0 0;font-size:12px;color:#667085">
                                Delivery-based invoice
                            </p>
                        </div>
                        <div class="invoice-number">
                            <strong><?= e($viewInvoice['InvoiceNo']) ?></strong>
                            <p style="margin:8px 0">
                                <span class="status-badge <?= e($displayClass) ?>"><?= e($displayStatus) ?></span>
                            </p>
                            <p style="margin:5px 0;font-size:12px">Invoice date: <?= e($viewInvoice['InvoiceDate']) ?></p>
                            <p style="margin:5px 0;font-size:12px">Due date: <?= e($viewInvoice['DueDate']) ?></p>
                        </div>
                    </div>

                    <div class="invoice-info">
                        <div>
                            <h3>Bill To</h3>
                            <p><strong><?= e($viewInvoice['HotelName']) ?></strong></p>
                            <?php if ($viewInvoice['ContactPerson']): ?>
                                <p><?= e($viewInvoice['ContactPerson']) ?></p>
                            <?php endif; ?>
                            <?php if ($viewInvoice['HotelAddress']): ?>
                                <p><?= nl2br(e($viewInvoice['HotelAddress'])) ?></p>
                            <?php endif; ?>
                            <?php if ($viewInvoice['HotelPhone']): ?>
                                <p>Phone: <?= e($viewInvoice['HotelPhone']) ?></p>
                            <?php endif; ?>
                            <?php if ($viewInvoice['HotelEmail']): ?>
                                <p>Email: <?= e($viewInvoice['HotelEmail']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3>Delivery Information</h3>
                            <p>Delivery ID: <strong>#<?= (int)$viewInvoice['DeliveryID'] ?></strong></p>
                            <p>Delivery reference: <strong><?= e($viewInvoice['DeliveryNo'] ?: '—') ?></strong></p>
                            <p>Delivery date: <?= e($viewInvoice['DeliveryDate']) ?></p>
                            <p>Hotel order: #<?= (int)$viewInvoice['OrderID'] ?></p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="invoice-items">
                            <thead>
                                <tr>
                                    <th style="width:8%">#</th>
                                    <th>Item Description</th>
                                    <th>Quantity</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-right">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($invoiceItems as $index => $item): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <strong><?= e($item['ItemName']) ?></strong>
                                        <?php if ($item['ItemCode']): ?>
                                            <div style="font-size:11px;color:#667085;margin-top:4px">
                                                Code: <?= e($item['ItemCode']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e(number_format((float)$item['SuppliedQuantity'], 3)) ?> <?= e($item['UnitSymbol']) ?></td>
                                    <td class="text-right"><?= invoiceMoney($item['UnitPrice']) ?></td>
                                    <td class="text-right"><?= invoiceMoney($item['LineTotal']) ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$invoiceItems): ?>
                                <tr><td colspan="5" class="empty-state">No delivery items found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="invoice-totals">
                        <div class="total-row">
                            <span>Invoice total</span>
                            <strong><?= invoiceMoney($invoiceTotal) ?></strong>
                        </div>
                        <div class="total-row">
                            <span>Payments allocated</span>
                            <strong><?= invoiceMoney($invoicePaid) ?></strong>
                        </div>
                        <div class="total-row grand-total">
                            <span>Balance due</span>
                            <span><?= invoiceMoney($invoiceBalance) ?></span>
                        </div>
                    </div>

                    <?php if (!empty($viewInvoice['Notes'])): ?>
                        <div class="invoice-note">
                            <strong>Notes</strong>
                            <p><?= nl2br(e($viewInvoice['Notes'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="invoice-note">
                        Thank you for your business.
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel list-panel">
            <div class="panel-heading">
                <div>
                    <h2>Invoice History</h2>
                    <p class="muted" style="margin:6px 0 0">Search invoices and monitor unpaid balances.</p>
                </div>
            </div>

            <form method="GET" action="invoices.php" style="margin-bottom:20px">
                <div class="filter-grid">
                    <div class="field">
                        <label for="search">Search</label>
                        <input type="search" name="search" id="search"
                               placeholder="Invoice, hotel, delivery, order"
                               value="<?= e($search) ?>">
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="">All statuses</option>
                            <?php foreach ($allowedFilters as $s): ?>
                                <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                                    <?= e($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="date_from">Invoice date from</label>
                        <input type="date" name="date_from" id="date_from" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="field">
                        <label for="date_to">Invoice date to</label>
                        <input type="date" name="date_to" id="date_to" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="invoices.php" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Invoice Date</th>
                            <th>Hotel</th>
                            <th>Delivery</th>
                            <th>Due Date</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        [$statusText, $statusClass] = invoiceStatusInfo($invoice);
                        $balance = max(0, (float)$invoice['TotalAmount'] - (float)$invoice['PaidAmount']);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($invoice['InvoiceNo']) ?></strong>
                                <div class="muted" style="font-size:11px;margin-top:4px">
                                    ID #<?= (int)$invoice['InvoiceID'] ?>
                                </div>
                            </td>
                            <td><?= e($invoice['InvoiceDate']) ?></td>
                            <td>
                                <strong><?= e($invoice['HotelName']) ?></strong>
                                <?php if ($invoice['HotelPhone']): ?>
                                    <div class="muted" style="font-size:11px;margin-top:4px">
                                        <?= e($invoice['HotelPhone']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                #<?= (int)$invoice['DeliveryID'] ?>
                                <div class="muted" style="font-size:11px;margin-top:4px">
                                    <?= e($invoice['DeliveryNo'] ?: 'No reference') ?>
                                </div>
                            </td>
                            <td>
                                <?= e($invoice['DueDate']) ?>
                                <?php if ($statusText === 'Overdue'): ?>
                                    <div style="font-size:11px;color:#b42318;margin-top:4px">Past due date</div>
                                <?php endif; ?>
                            </td>
                            <td class="amount"><?= invoiceMoney($invoice['TotalAmount']) ?></td>
                            <td><?= invoiceMoney($invoice['PaidAmount']) ?></td>
                            <td class="balance"><?= invoiceMoney($balance) ?></td>
                            <td><span class="status-badge <?= e($statusClass) ?>"><?= e($statusText) ?></span></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-primary btn-small"
                                       href="invoices.php?view=<?= (int)$invoice['InvoiceID'] ?>">View / Print</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$invoices): ?>
                        <tr><td colspan="10" class="empty-state">No invoices found for the selected filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted" style="margin-top:12px"><?= count($invoices) ?> invoice(s) shown.</p>
        </section>
    </main>
</div>
</body>
</html>
