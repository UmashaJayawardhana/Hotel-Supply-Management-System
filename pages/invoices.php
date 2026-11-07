
<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('invoiceMoney')) {
    function invoiceMoney($amount): string
    {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('invoiceStatusInfo')) {
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

/*
|--------------------------------------------------------------------------
| Standalone 8cm Thermal Receipt Mode
|--------------------------------------------------------------------------
*/
if ($viewInvoice && (($_GET['mode'] ?? '') === 'receipt')) {
    [$displayStatus, $displayClass] = invoiceStatusInfo($viewInvoice);
    $invoiceTotal = (float)$viewInvoice['TotalAmount'];
    $invoicePaid = (float)$viewInvoice['PaidAmount'];
    $invoiceBalance = max(0, $invoiceTotal - $invoicePaid);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Receipt - <?= e($viewInvoice['InvoiceNo']) ?> (8cm Thermal)</title>
        <style>
            * { box-sizing: border-box; }
            body {
                margin: 0; padding: 20px 10px;
                background: #e2e8f0; color: #000;
                font-family: 'Consolas', 'Courier New', 'Courier', monospace;
                display: flex; flex-direction: column; align-items: center;
            }
            .no-print { margin-bottom: 16px; display: flex; gap: 8px; }
            .btn {
                padding: 8px 14px; font-size: 13px; font-weight: 600;
                border-radius: 6px; cursor: pointer; text-decoration: none; border: 0;
            }
            .btn-primary { background: #3157c8; color: #fff; }
            .btn-secondary { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
            .invoice-paper {
                width: 80mm; max-width: 340px; background: #fff;
                box-shadow: 0 4px 20px rgba(0,0,0,0.12); border: 1px solid #cbd5e1;
                border-radius: 4px; padding: 16px 14px 28px; font-size: 12px; line-height: 1.35;
            }
            .thermal-header { text-align: center; margin-bottom: 8px; }
            .thermal-header .brand-name { font-size: 15px; font-weight: 800; color: #000; }
            .thermal-header .brand-sub { font-size: 11px; color: #4b5563; margin-top: 2px; }
            .receipt-divider-double { border-top: 2px solid #000; margin: 8px 0; }
            .receipt-divider-dash { border-top: 1px dashed #4b5563; margin: 8px 0; }
            .receipt-title { font-size: 14px; font-weight: 800; text-align: center; margin: 4px 0; }
            .thermal-meta, .thermal-customer { font-size: 11.5px; }
            .meta-row { display: flex; justify-content: space-between; margin: 3px 0; gap: 8px; }
            .meta-row span { color: #4b5563; }
            .meta-row strong { color: #000; }
            .section-label { font-size: 10px; font-weight: 700; color: #4b5563; text-transform: uppercase; margin-bottom: 3px; }
            .customer-name { font-size: 13px; font-weight: 800; color: #000; }
            .customer-sub { font-size: 11px; color: #374151; margin-top: 2px; }
            .thermal-items-table { width: 100%; border-collapse: collapse; margin: 6px 0; }
            .thermal-items-table th {
                font-size: 10.5px; font-weight: 700; text-transform: uppercase;
                padding: 4px 2px; border-top: 1px dashed #000; border-bottom: 1px dashed #000;
                background: transparent; color: #000;
            }
            .th-item { text-align: left; }
            .th-qty { text-align: right; width: 45px; }
            .th-price { text-align: right; width: 62px; }
            .th-total { text-align: right; width: 70px; }
            .item-main-row td { padding-top: 5px; padding-bottom: 1px; font-weight: 700; color: #000; font-size: 11.5px; }
            .item-details-row td { padding-bottom: 5px; font-size: 11px; color: #374151; border-bottom: 1px dotted #e2e8f0; }
            .item-code-unit { font-size: 10px; color: #6b7280; }
            .item-qty, .item-price, .item-total { text-align: right; white-space: nowrap; }
            .item-total { font-weight: 700; color: #000; }
            .thermal-totals { margin: 6px 0; }
            .totals-row { display: flex; justify-content: space-between; font-size: 11.5px; margin: 3px 0; }
            .grand-total-row { font-size: 14px; font-weight: 800; color: #000; padding: 4px 0; }
            .thermal-notes { font-size: 11px; color: #374151; margin: 6px 0; word-break: break-word; }
            .thermal-footer { text-align: center; margin-top: 10px; font-size: 11px; }
            .footer-msg { font-weight: 700; color: #000; }
            .footer-sub { font-size: 10px; color: #4b5563; margin-top: 2px; }
            .signature-block { margin: 16px 0 10px; text-align: left; font-size: 11px; }
            .sig-line, .sig-date { margin: 6px 0; color: #374151; }
            .print-time { font-size: 9.5px; color: #9ca3af; margin-top: 10px; }
            .cut-line { font-size: 9px; color: #cbd5e1; letter-spacing: 2px; margin-top: 8px; }
            @media print {
                @page { size: 80mm auto; margin: 0; }
                body { background: #fff !important; padding: 0 !important; width: 80mm !important; }
                .no-print { display: none !important; }
                .invoice-paper {
                    width: 76mm !important; max-width: 76mm !important;
                    margin: 0 auto !important; padding: 2mm 1mm 16mm 1mm !important;
                    border: none !important; box-shadow: none !important;
                }
                .thermal-header .brand-sub, .meta-row span, .section-label, .customer-sub, .item-details-row td, .item-code-unit, .thermal-notes, .footer-sub, .sig-line, .sig-date {
                    color: #000 !important;
                }
                .receipt-divider-dash { border-top: 1px dashed #000 !important; }
                .receipt-divider-double { border-top: 2px solid #000 !important; }
                .thermal-items-table th { border-top: 1px dashed #000 !important; border-bottom: 1px dashed #000 !important; color: #000 !important; }
                .item-details-row td { border-bottom: 1px dotted #000 !important; }
            }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Print 8cm Thermal</button>
            <button type="button" class="btn btn-secondary" onclick="window.close()">✕ Close</button>
        </div>
        
                <div class="invoice-paper">
                    <div class="thermal-header">
                        <div class="brand-name">HOTEL SUPPLY MANAGEMENT</div>
                        <div class="brand-sub">Wholesale Hotel & Food Supplies</div>
                        <div class="brand-sub">Colombo, Sri Lanka · Tel: +94 77 123 4567</div>
                        <div class="receipt-divider-double"></div>
                        <div class="receipt-title">DELIVERY INVOICE</div>
                        <div class="receipt-divider-dash"></div>
                    </div>

                    <div class="thermal-meta">
                        <div class="meta-row"><span>Invoice No:</span><strong><?= e($viewInvoice['InvoiceNo']) ?></strong></div>
                        <div class="meta-row"><span>Invoice Date:</span><span><?= e($viewInvoice['InvoiceDate']) ?></span></div>
                        <div class="meta-row"><span>Due Date:</span><span><?= e($viewInvoice['DueDate']) ?></span></div>
                        <div class="meta-row"><span>Order Ref:</span><span>#<?= (int)$viewInvoice['OrderID'] ?></span></div>
                        <div class="meta-row"><span>Delivery Ref:</span><span><?= e($viewInvoice['DeliveryNo'] ?: '#' . (int)$viewInvoice['DeliveryID']) ?></span></div>
                        <div class="meta-row"><span>Delivery Date:</span><span><?= e($viewInvoice['DeliveryDate']) ?></span></div>
                        <div class="meta-row"><span>Status:</span><strong class="receipt-status <?= e($displayClass) ?>"><?= strtoupper(e($displayStatus)) ?></strong></div>
                    </div>

                    <div class="receipt-divider-dash"></div>

                    <div class="thermal-customer">
                        <div class="section-label">BILL TO (CUSTOMER):</div>
                        <div class="customer-name"><?= e($viewInvoice['HotelName']) ?></div>
                        <?php if ($viewInvoice['ContactPerson']): ?>
                            <div class="customer-sub">Attn: <?= e($viewInvoice['ContactPerson']) ?></div>
                        <?php endif; ?>
                        <?php if ($viewInvoice['HotelPhone']): ?>
                            <div class="customer-sub">Tel: <?= e($viewInvoice['HotelPhone']) ?></div>
                        <?php endif; ?>
                        <?php if ($viewInvoice['HotelAddress']): ?>
                            <div class="customer-sub"><?= nl2br(e($viewInvoice['HotelAddress'])) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="receipt-divider-double"></div>

                    <table class="thermal-items-table">
                        <thead>
                            <tr>
                                <th class="th-item">ITEM / CODE</th>
                                <th class="th-qty">QTY</th>
                                <th class="th-price">PRICE</th>
                                <th class="th-total">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $totalItemsCount = count($invoiceItems);
                        $totalQtySum = 0;
                        foreach ($invoiceItems as $item):
                            $totalQtySum += (float)$item['SuppliedQuantity'];
                            $isNos = strtoupper((string)($item['UnitSymbol'] ?? '')) === 'NOS';
                        ?>
                            <tr class="item-main-row">
                                <td colspan="4" class="item-name"><?= e($item['ItemName']) ?></td>
                            </tr>
                            <tr class="item-details-row">
                                <td class="item-code-unit">
                                    <?= e($item['ItemCode'] ?: '—') ?> (<?= e($item['UnitSymbol'] ?: 'NOS') ?>)
                                </td>
                                <td class="item-qty">
                                    <?= e(number_format((float)$item['SuppliedQuantity'], $isNos ? 0 : 3)) ?>
                                </td>
                                <td class="item-price">
                                    <?= invoiceMoney($item['UnitPrice']) ?>
                                </td>
                                <td class="item-total">
                                    <?= invoiceMoney($item['LineTotal']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$invoiceItems): ?>
                            <tr><td colspan="4" style="text-align:center;padding:10px;font-style:italic">No items on this invoice.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="receipt-divider-dash"></div>

                    <div class="thermal-totals">
                        <div class="totals-row">
                            <span>Item Lines:</span>
                            <span><?= $totalItemsCount ?> item(s)</span>
                        </div>
                        <div class="totals-row">
                            <span>Total Quantity:</span>
                            <span><?= number_format($totalQtySum, 3) ?></span>
                        </div>
                        <div class="receipt-divider-dash"></div>
                        <div class="totals-row">
                            <span>SUBTOTAL:</span>
                            <strong>Rs. <?= invoiceMoney($invoiceTotal) ?></strong>
                        </div>
                        <div class="totals-row">
                            <span>PAID AMOUNT:</span>
                            <span>Rs. <?= invoiceMoney($invoicePaid) ?></span>
                        </div>
                        <div class="receipt-divider-double"></div>
                        <div class="totals-row grand-total-row">
                            <span>BALANCE DUE:</span>
                            <strong>Rs. <?= invoiceMoney($invoiceBalance) ?></strong>
                        </div>
                        <div class="receipt-divider-double"></div>
                    </div>

                    <?php if (!empty($viewInvoice['Notes'])): ?>
                        <div class="thermal-notes">
                            <strong>Notes:</strong> <?= nl2br(e($viewInvoice['Notes'])) ?>
                        </div>
                        <div class="receipt-divider-dash"></div>
                    <?php endif; ?>

                    <div class="thermal-footer">
                        <div class="footer-msg">Thank you for your business!</div>
                        <div class="footer-sub">Goods received in good condition.</div>
                        <div class="signature-block">
                            <div class="sig-line">Customer Signature: _______________________</div>
                            <div class="sig-date">Date: ___________________________________</div>
                        </div>
                        <div class="print-time">Printed: <?= date('Y-m-d H:i:s') ?></div>
                        <div class="cut-line">· · · · · · · · · · · · · · · · · · · · · · · ·</div>
                    </div>
                </div>
        <?php if (!empty($_GET['print'])): ?>
            <script>window.addEventListener('DOMContentLoaded', () => window.print());</script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

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

        /* 8cm Thermal Printer Styles */
        .invoice-paper-wrapper {
            background:#f1f5f9; padding:24px 12px; border-radius:10px;
            display:flex; justify-content:center;
        }
        .invoice-paper {
            width:80mm; max-width:340px; background:#fff;
            box-shadow:0 4px 20px rgba(0,0,0,0.12); border:1px solid #cbd5e1;
            border-radius:4px; padding:16px 14px 28px;
            font-family:'Consolas','Courier New','Courier',monospace;
            font-size:12px; color:#111827; line-height:1.35; box-sizing:border-box;
        }
        .thermal-header { text-align:center; margin-bottom:8px; }
        .thermal-header .brand-name { font-size:15px; font-weight:800; color:#000; letter-spacing:0.5px; }
        .thermal-header .brand-sub { font-size:11px; color:#4b5563; margin-top:2px; }
        .receipt-divider-double { border-top:2px solid #000; margin:8px 0; }
        .receipt-divider-dash { border-top:1px dashed #4b5563; margin:8px 0; }
        .receipt-title { font-size:14px; font-weight:800; text-align:center; margin:4px 0; letter-spacing:1px; }
        .thermal-meta, .thermal-customer { font-size:11.5px; }
        .meta-row { display:flex; justify-content:space-between; margin:3px 0; gap:8px; }
        .meta-row span { color:#4b5563; }
        .meta-row strong { color:#000; }
        .section-label { font-size:10px; font-weight:700; color:#4b5563; text-transform:uppercase; margin-bottom:3px; }
        .customer-name { font-size:13px; font-weight:800; color:#000; }
        .customer-sub { font-size:11px; color:#374151; margin-top:2px; }
        .thermal-items-table { width:100%; border-collapse:collapse; margin:6px 0; }
        .thermal-items-table th {
            font-size:10.5px; font-weight:700; text-transform:uppercase;
            padding:4px 2px; border-top:1px dashed #000; border-bottom:1px dashed #000;
            background:transparent; color:#000;
        }
        .th-item { text-align:left; }
        .th-qty { text-align:right; width:45px; }
        .th-price { text-align:right; width:62px; }
        .th-total { text-align:right; width:70px; }
        .item-main-row td { padding-top:5px; padding-bottom:1px; font-weight:700; color:#000; font-size:11.5px; }
        .item-details-row td { padding-bottom:5px; font-size:11px; color:#374151; border-bottom:1px dotted #e2e8f0; }
        .item-code-unit { font-size:10px; color:#6b7280; }
        .item-qty, .item-price, .item-total { text-align:right; white-space:nowrap; }
        .item-total { font-weight:700; color:#000; }
        .thermal-totals { margin:6px 0; }
        .totals-row { display:flex; justify-content:space-between; font-size:11.5px; margin:3px 0; }
        .grand-total-row { font-size:14px; font-weight:800; color:#000; padding:4px 0; }
        .thermal-notes { font-size:11px; color:#374151; margin:6px 0; word-break:break-word; }
        .thermal-footer { text-align:center; margin-top:10px; font-size:11px; }
        .footer-msg { font-weight:700; color:#000; }
        .footer-sub { font-size:10px; color:#4b5563; margin-top:2px; }
        .signature-block { margin:16px 0 10px; text-align:left; font-size:11px; }
        .sig-line, .sig-date { margin:6px 0; color:#374151; }
        .print-time { font-size:9.5px; color:#9ca3af; margin-top:10px; }
        .cut-line { font-size:9px; color:#cbd5e1; letter-spacing:2px; margin-top:8px; }
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
            @page {
                size: 80mm auto;
                margin: 0;
            }
            html, body {
                width: 80mm !important;
                min-width: 80mm !important;
                max-width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                color: #000 !important;
                font-family: 'Consolas', 'Courier New', 'Courier', monospace !important;
                font-size: 11px !important;
                line-height: 1.3 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .sidebar, .page-header, .invoice-stats, .list-panel, .no-print, .flash, .panel-heading {
                display: none !important;
            }
            .app, .main, .panel, .invoice-paper-wrapper {
                display: block !important;
                width: 80mm !important;
                max-width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }
            .invoice-paper {
                width: 76mm !important;
                max-width: 76mm !important;
                margin: 0 auto !important;
                padding: 2mm 1mm 16mm 1mm !important;
                border: none !important;
                box-shadow: none !important;
                background: #fff !important;
                font-size: 11px !important;
                color: #000 !important;
            }
            .thermal-header .brand-sub, .meta-row span, .section-label, .customer-sub, .item-details-row td, .item-code-unit, .thermal-notes, .footer-sub, .sig-line, .sig-date {
                color: #000 !important;
            }
            .receipt-divider-dash { border-top: 1px dashed #000 !important; }
            .receipt-divider-double { border-top: 2px solid #000 !important; }
            .thermal-items-table th { border-top: 1px dashed #000 !important; border-bottom: 1px dashed #000 !important; color: #000 !important; }
            .item-details-row td { border-bottom: 1px dotted #000 !important; }
            a { color: #000 !important; text-decoration: none !important; }
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
<?php
if (empty($_SESSION['logout_csrf_token'])) {
    $_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
}
?>
<div class="sidebar-user">
    <strong>
        <?= htmlspecialchars($_SESSION['user_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>
    </strong>
    <small>
        <?= htmlspecialchars($_SESSION['user_role'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </small>
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
        <?php if (isAdmin()): ?>
    <a class="nav-link" href="pages/users.php">
        👥 &nbsp; User Management
    </a>
<?php endif; ?>
<form action="logout.php" method="POST" class="sidebar-logout-form">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['logout_csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
    >

    <button type="submit" class="sidebar-logout-button">
        <span class="logout-icon">↪</span>
        <span>Logout</span>
        <span class="logout-arrow">→</span>
    </button>
</form>
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
                        <h2>Invoice Details — Standard 8cm Thermal Format</h2>
                        <p class="muted" style="margin:6px 0 0">Standard 80mm (8 cm) POS receipt printer layout for all deliveries.</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-secondary" href="invoices.php">← Back to Invoices</a>
                        <button class="btn btn-primary" type="button" onclick="window.print()">🖨 Print 8cm Invoice</button>
                        <a class="btn btn-secondary" href="invoices.php?view=<?= $viewId ?>&mode=receipt" target="_blank" title="Open Standalone 8cm Receipt">📄 Standalone Receipt</a>
                    </div>
                </div>

                <div class="invoice-paper-wrapper">
                    
                <div class="invoice-paper">
                    <div class="thermal-header">
                        <div class="brand-name">HOTEL SUPPLY MANAGEMENT</div>
                        <div class="brand-sub">Wholesale Hotel & Food Supplies</div>
                        <div class="brand-sub">Colombo, Sri Lanka · Tel: +94 77 123 4567</div>
                        <div class="receipt-divider-double"></div>
                        <div class="receipt-title">DELIVERY INVOICE</div>
                        <div class="receipt-divider-dash"></div>
                    </div>

                    <div class="thermal-meta">
                        <div class="meta-row"><span>Invoice No:</span><strong><?= e($viewInvoice['InvoiceNo']) ?></strong></div>
                        <div class="meta-row"><span>Invoice Date:</span><span><?= e($viewInvoice['InvoiceDate']) ?></span></div>
                        <div class="meta-row"><span>Due Date:</span><span><?= e($viewInvoice['DueDate']) ?></span></div>
                        <div class="meta-row"><span>Order Ref:</span><span>#<?= (int)$viewInvoice['OrderID'] ?></span></div>
                        <div class="meta-row"><span>Delivery Ref:</span><span><?= e($viewInvoice['DeliveryNo'] ?: '#' . (int)$viewInvoice['DeliveryID']) ?></span></div>
                        <div class="meta-row"><span>Delivery Date:</span><span><?= e($viewInvoice['DeliveryDate']) ?></span></div>
                        <div class="meta-row"><span>Status:</span><strong class="receipt-status <?= e($displayClass) ?>"><?= strtoupper(e($displayStatus)) ?></strong></div>
                    </div>

                    <div class="receipt-divider-dash"></div>

                    <div class="thermal-customer">
                        <div class="section-label">BILL TO (CUSTOMER):</div>
                        <div class="customer-name"><?= e($viewInvoice['HotelName']) ?></div>
                        <?php if ($viewInvoice['ContactPerson']): ?>
                            <div class="customer-sub">Attn: <?= e($viewInvoice['ContactPerson']) ?></div>
                        <?php endif; ?>
                        <?php if ($viewInvoice['HotelPhone']): ?>
                            <div class="customer-sub">Tel: <?= e($viewInvoice['HotelPhone']) ?></div>
                        <?php endif; ?>
                        <?php if ($viewInvoice['HotelAddress']): ?>
                            <div class="customer-sub"><?= nl2br(e($viewInvoice['HotelAddress'])) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="receipt-divider-double"></div>

                    <table class="thermal-items-table">
                        <thead>
                            <tr>
                                <th class="th-item">ITEM / CODE</th>
                                <th class="th-qty">QTY</th>
                                <th class="th-price">PRICE</th>
                                <th class="th-total">TOTAL</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $totalItemsCount = count($invoiceItems);
                        $totalQtySum = 0;
                        foreach ($invoiceItems as $item):
                            $totalQtySum += (float)$item['SuppliedQuantity'];
                            $isNos = strtoupper((string)($item['UnitSymbol'] ?? '')) === 'NOS';
                        ?>
                            <tr class="item-main-row">
                                <td colspan="4" class="item-name"><?= e($item['ItemName']) ?></td>
                            </tr>
                            <tr class="item-details-row">
                                <td class="item-code-unit">
                                    <?= e($item['ItemCode'] ?: '—') ?> (<?= e($item['UnitSymbol'] ?: 'NOS') ?>)
                                </td>
                                <td class="item-qty">
                                    <?= e(number_format((float)$item['SuppliedQuantity'], $isNos ? 0 : 3)) ?>
                                </td>
                                <td class="item-price">
                                    <?= invoiceMoney($item['UnitPrice']) ?>
                                </td>
                                <td class="item-total">
                                    <?= invoiceMoney($item['LineTotal']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$invoiceItems): ?>
                            <tr><td colspan="4" style="text-align:center;padding:10px;font-style:italic">No items on this invoice.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="receipt-divider-dash"></div>

                    <div class="thermal-totals">
                        <div class="totals-row">
                            <span>Item Lines:</span>
                            <span><?= $totalItemsCount ?> item(s)</span>
                        </div>
                        <div class="totals-row">
                            <span>Total Quantity:</span>
                            <span><?= number_format($totalQtySum, 3) ?></span>
                        </div>
                        <div class="receipt-divider-dash"></div>
                        <div class="totals-row">
                            <span>SUBTOTAL:</span>
                            <strong>Rs. <?= invoiceMoney($invoiceTotal) ?></strong>
                        </div>
                        <div class="totals-row">
                            <span>PAID AMOUNT:</span>
                            <span>Rs. <?= invoiceMoney($invoicePaid) ?></span>
                        </div>
                        <div class="receipt-divider-double"></div>
                        <div class="totals-row grand-total-row">
                            <span>BALANCE DUE:</span>
                            <strong>Rs. <?= invoiceMoney($invoiceBalance) ?></strong>
                        </div>
                        <div class="receipt-divider-double"></div>
                    </div>

                    <?php if (!empty($viewInvoice['Notes'])): ?>
                        <div class="thermal-notes">
                            <strong>Notes:</strong> <?= nl2br(e($viewInvoice['Notes'])) ?>
                        </div>
                        <div class="receipt-divider-dash"></div>
                    <?php endif; ?>

                    <div class="thermal-footer">
                        <div class="footer-msg">Thank you for your business!</div>
                        <div class="footer-sub">Goods received in good condition.</div>
                        <div class="signature-block">
                            <div class="sig-line">Customer Signature: _______________________</div>
                            <div class="sig-date">Date: ___________________________________</div>
                        </div>
                        <div class="print-time">Printed: <?= date('Y-m-d H:i:s') ?></div>
                        <div class="cut-line">· · · · · · · · · · · · · · · · · · · · · · · ·</div>
                    </div>
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
                                       href="invoices.php?view=<?= (int)$invoice['InvoiceID'] ?>">View (8cm)</a>
                                    <a class="btn btn-secondary btn-small"
                                       href="invoices.php?view=<?= (int)$invoice['InvoiceID'] ?>&mode=receipt&print=1" target="_blank"
                                       title="Quick 8cm Thermal Print">🖨 Print</a>
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
