
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Database connection is not available. Check config/database.php.');
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function reportMoney($value): string
{
    return number_format((float)($value ?? 0), 2);
}

function reportDate($value): string
{
    return $value ? date('d M Y', strtotime($value)) : '-';
}

function reportStatusClass($status): string
{
    $status = strtolower((string)$status);

    if (in_array($status, ['paid', 'completed', 'delivered', 'active'], true)) {
        return 'status-success';
    }

    if (in_array($status, ['cancelled', 'inactive'], true)) {
        return 'status-danger';
    }

    if (in_array($status, ['partially paid', 'partially delivered'], true)) {
        return 'status-warning';
    }

    return 'status-neutral';
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$from = $_GET['from'] ?? $monthStart;
$to = $_GET['to'] ?? $today;
$hotelId = filter_var($_GET['hotel_id'] ?? '', FILTER_VALIDATE_INT);
$vendorId = filter_var($_GET['vendor_id'] ?? '', FILTER_VALIDATE_INT);
$itemId = filter_var($_GET['item_id'] ?? '', FILTER_VALIDATE_INT);

$hotelId = $hotelId && $hotelId > 0 ? $hotelId : '';
$vendorId = $vendorId && $vendorId > 0 ? $vendorId : '';
$itemId = $itemId && $itemId > 0 ? $itemId : '';

$errors = [];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ||
    !strtotime($from) || !strtotime($to)) {
    $errors[] = 'Please select valid dates.';
    $from = $monthStart;
    $to = $today;
}

if ($from > $to) {
    $errors[] = 'The start date must be before or equal to the end date.';
    $from = $monthStart;
    $to = $today;
}

$startDateTime = $from . ' 00:00:00';
$endDateTime = $to . ' 23:59:59';

$moneySymbol = 'Rs.';

try {
    // Filter dropdowns
    $hotels = $pdo->query("
        SELECT HotelID, HotelName
        FROM hotel
        WHERE IsActive = 1
        ORDER BY HotelName
    ")->fetchAll(PDO::FETCH_ASSOC);

    $vendors = $pdo->query("
        SELECT VendorID, VendorName
        FROM market_vendor
        WHERE IsActive = 1
        ORDER BY VendorName
    ")->fetchAll(PDO::FETCH_ASSOC);

    $items = $pdo->query("
        SELECT ItemID, ItemName, ItemCode
        FROM item
        WHERE IsActive = 1
        ORDER BY ItemName
    ")->fetchAll(PDO::FETCH_ASSOC);

    /*
     * SALES AND DELIVERY REPORT
     * Only completed deliveries count as supplied sales.
     */
    $salesSql = "
        SELECT
            d.DeliveryID,
            d.DeliveryNo,
            d.DeliveryDate,
            d.Status AS DeliveryStatus,
            ho.OrderID,
            h.HotelName,
            i.ItemCode,
            i.ItemName,
            u.UnitSymbol,
            dd.SuppliedQuantity,
            dd.UnitPrice,
            dd.LineTotal
        FROM delivery_detail dd
        INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
        INNER JOIN hotel_order ho ON ho.OrderID = d.OrderID
        INNER JOIN hotel h ON h.HotelID = ho.HotelID
        INNER JOIN hotel_order_detail hod
            ON hod.OrderDetailID = dd.OrderDetailID
        INNER JOIN item i ON i.ItemID = hod.ItemID
        LEFT JOIN unit u ON u.UnitID = i.UnitID
        WHERE d.Status = 'Completed'
          AND d.DeliveryDate BETWEEN :sales_from AND :sales_to
    ";

    $salesParams = [
        ':sales_from' => $from,
        ':sales_to' => $to
    ];

    if ($hotelId !== '') {
        $salesSql .= " AND h.HotelID = :sales_hotel";
        $salesParams[':sales_hotel'] = $hotelId;
    }

    if ($itemId !== '') {
        $salesSql .= " AND i.ItemID = :sales_item";
        $salesParams[':sales_item'] = $itemId;
    }

    $salesSql .= " ORDER BY d.DeliveryDate DESC, d.DeliveryID DESC";

    $stmt = $pdo->prepare($salesSql);
    $stmt->execute($salesParams);
    $salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $salesTotal = 0;
    $salesQuantity = 0;

    foreach ($salesRows as $row) {
        $salesTotal += (float)$row['LineTotal'];
        $salesQuantity += (float)$row['SuppliedQuantity'];
    }

    /*
     * MARKET PURCHASE REPORT
     */
    $purchaseSql = "
        SELECT
            mp.PurchaseID,
            mp.PurchaseDate,
            mp.ReceiptNo,
            mp.TotalAmount,
            mp.AdditionalCost,
            mp.Notes,
            mv.VendorName,
            COALESCE(details.DetailCount, 0) AS DetailCount,
            COALESCE(details.ItemQuantity, 0) AS ItemQuantity,
            COALESCE(details.DetailTotal, 0) AS DetailTotal
        FROM market_purchase mp
        INNER JOIN market_vendor mv ON mv.VendorID = mp.VendorID
        LEFT JOIN (
            SELECT
                PurchaseID,
                COUNT(*) AS DetailCount,
                SUM(Quantity) AS ItemQuantity,
                SUM(LineTotal) AS DetailTotal
            FROM market_purchase_detail
            GROUP BY PurchaseID
        ) details ON details.PurchaseID = mp.PurchaseID
        WHERE mp.PurchaseDate BETWEEN :purchase_from AND :purchase_to
    ";

    $purchaseParams = [
        ':purchase_from' => $from,
        ':purchase_to' => $to
    ];

    if ($vendorId !== '') {
        $purchaseSql .= " AND mp.VendorID = :purchase_vendor";
        $purchaseParams[':purchase_vendor'] = $vendorId;
    }

    if ($itemId !== '') {
        $purchaseSql .= "
            AND EXISTS (
                SELECT 1
                FROM market_purchase_detail mpd_filter
                WHERE mpd_filter.PurchaseID = mp.PurchaseID
                  AND mpd_filter.ItemID = :purchase_item
            )
        ";
        $purchaseParams[':purchase_item'] = $itemId;
    }

    $purchaseSql .= " ORDER BY mp.PurchaseDate DESC, mp.PurchaseID DESC";

    $stmt = $pdo->prepare($purchaseSql);
    $stmt->execute($purchaseParams);
    $purchaseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $purchaseTotal = 0;
    foreach ($purchaseRows as $row) {
        $purchaseTotal +=
            (float)$row['TotalAmount'] +
            (float)$row['AdditionalCost'];
    }

    /*
     * INVOICE REPORT
     * Payments are summarized through payment_allocation.
     */
    $invoiceSql = "
        SELECT
            inv.InvoiceID,
            inv.InvoiceNo,
            inv.InvoiceDate,
            inv.DueDate,
            inv.TotalAmount,
            inv.Status,
            h.HotelName,
            COALESCE(alloc.PaidAmount, 0) AS PaidAmount
        FROM invoice inv
        INNER JOIN delivery d ON d.DeliveryID = inv.DeliveryID
        INNER JOIN hotel_order ho ON ho.OrderID = d.OrderID
        INNER JOIN hotel h ON h.HotelID = ho.HotelID
        LEFT JOIN (
            SELECT
                InvoiceID,
                SUM(AllocatedAmount) AS PaidAmount
            FROM payment_allocation
            GROUP BY InvoiceID
        ) alloc ON alloc.InvoiceID = inv.InvoiceID
        WHERE inv.InvoiceDate BETWEEN :invoice_from AND :invoice_to
          AND inv.Status <> 'Cancelled'
    ";

    $invoiceParams = [
        ':invoice_from' => $from,
        ':invoice_to' => $to
    ];

    if ($hotelId !== '') {
        $invoiceSql .= " AND h.HotelID = :invoice_hotel";
        $invoiceParams[':invoice_hotel'] = $hotelId;
    }

    if ($itemId !== '') {
        $invoiceSql .= "
            AND EXISTS (
                SELECT 1
                FROM delivery_detail dd_filter
                INNER JOIN hotel_order_detail hod_filter
                    ON hod_filter.OrderDetailID = dd_filter.OrderDetailID
                INNER JOIN delivery d_filter
                    ON d_filter.DeliveryID = dd_filter.DeliveryID
                WHERE d_filter.DeliveryID = inv.DeliveryID
                  AND hod_filter.ItemID = :invoice_item
            )
        ";
        $invoiceParams[':invoice_item'] = $itemId;
    }

    $invoiceSql .= " ORDER BY inv.InvoiceDate DESC, inv.InvoiceID DESC";

    $stmt = $pdo->prepare($invoiceSql);
    $stmt->execute($invoiceParams);
    $invoiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $invoiceTotal = 0;
    $invoicePaid = 0;
    $invoiceOutstanding = 0;

    foreach ($invoiceRows as $row) {
        $total = (float)$row['TotalAmount'];
        $paid = (float)$row['PaidAmount'];
        $balance = max(0, $total - $paid);

        $invoiceTotal += $total;
        $invoicePaid += $paid;
        $invoiceOutstanding += $balance;
    }

    /*
     * PAYMENT REPORT
     */
    $paymentSql = "
        SELECT
            p.PaymentID,
            p.PaymentDate,
            p.Amount,
            p.PaymentMethod,
            p.ReferenceNo,
            p.Notes,
            h.HotelName,
            COALESCE(alloc.AllocatedAmount, 0) AS AllocatedAmount
        FROM payment p
        INNER JOIN hotel h ON h.HotelID = p.HotelID
        LEFT JOIN (
            SELECT
                PaymentID,
                SUM(AllocatedAmount) AS AllocatedAmount
            FROM payment_allocation
            GROUP BY PaymentID
        ) alloc ON alloc.PaymentID = p.PaymentID
        WHERE p.PaymentDate BETWEEN :payment_from AND :payment_to
    ";

    $paymentParams = [
        ':payment_from' => $from,
        ':payment_to' => $to
    ];

    if ($hotelId !== '') {
        $paymentSql .= " AND p.HotelID = :payment_hotel";
        $paymentParams[':payment_hotel'] = $hotelId;
    }

    $paymentSql .= " ORDER BY p.PaymentDate DESC, p.PaymentID DESC";

    $stmt = $pdo->prepare($paymentSql);
    $stmt->execute($paymentParams);
    $paymentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentsTotal = 0;
    foreach ($paymentRows as $row) {
        $paymentsTotal += (float)$row['Amount'];
    }

    /*
     * ESTIMATED PROFIT
     *
     * Uses the weighted average recorded purchase UnitCost per item.
     * Additional purchase costs, stock movements, wastage and returns
     * are not allocated here, so this is an estimate only.
     */
    $profitSql = "
        SELECT
            COALESCE(SUM(dd.LineTotal), 0) AS SalesAmount,
            COALESCE(SUM(
                dd.SuppliedQuantity * COALESCE(costs.AverageUnitCost, 0)
            ), 0) AS EstimatedCost,
            COALESCE(SUM(
                CASE
                    WHEN costs.AverageUnitCost IS NULL
                    THEN dd.SuppliedQuantity
                    ELSE 0
                END
            ), 0) AS QuantityWithoutCost
        FROM delivery_detail dd
        INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
        INNER JOIN hotel_order ho ON ho.OrderID = d.OrderID
        INNER JOIN hotel_order_detail hod
            ON hod.OrderDetailID = dd.OrderDetailID
        INNER JOIN item i ON i.ItemID = hod.ItemID
        LEFT JOIN (
            SELECT
                ItemID,
                SUM(Quantity * UnitCost) / NULLIF(SUM(Quantity), 0)
                    AS AverageUnitCost
            FROM market_purchase_detail
            GROUP BY ItemID
        ) costs ON costs.ItemID = i.ItemID
        WHERE d.Status = 'Completed'
          AND d.DeliveryDate BETWEEN :profit_from AND :profit_to
    ";

    $profitParams = [
        ':profit_from' => $from,
        ':profit_to' => $to
    ];

    if ($hotelId !== '') {
        $profitSql .= " AND ho.HotelID = :profit_hotel";
        $profitParams[':profit_hotel'] = $hotelId;
    }

    if ($itemId !== '') {
        $profitSql .= " AND i.ItemID = :profit_item";
        $profitParams[':profit_item'] = $itemId;
    }

    $stmt = $pdo->prepare($profitSql);
    $stmt->execute($profitParams);
    $profitData = $stmt->fetch(PDO::FETCH_ASSOC);

    $estimatedCost = (float)($profitData['EstimatedCost'] ?? 0);
    $estimatedSales = (float)($profitData['SalesAmount'] ?? 0);
    $estimatedProfit = $estimatedSales - $estimatedCost;
    $quantityWithoutCost = (float)($profitData['QuantityWithoutCost'] ?? 0);

} catch (PDOException $ex) {
    error_log('Reports page database error: ' . $ex->getMessage());
    http_response_code(500);
    die(
        '<h2>Unable to load reports</h2>' .
        '<p>Please check the database table and column names in reports.php, ' .
        'then review the PHP error log.</p>'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Hotel Supply Management System</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .reports-page { max-width: 1500px; margin: 0 auto; }
        .report-heading {
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; flex-wrap: wrap; margin-bottom: 22px;
        }
        .report-heading h1 { margin: 0 0 6px; font-size: 27px; }
        .report-heading p { margin: 0; color: #6b7280; }
        .report-actions { display: flex; gap: 9px; flex-wrap: wrap; }
        .report-btn {
            border: 1px solid #d9dee8; border-radius: 8px; padding: 10px 14px;
            background: #fff; color: #263247; cursor: pointer;
            text-decoration: none; font: inherit; font-size: 13px;
        }
        .report-btn.primary { background: #2457d6; color: white; border-color: #2457d6; }
        .filter-card, .report-card {
            background: #fff; border: 1px solid #e7eaf0; border-radius: 12px;
            padding: 20px; margin-bottom: 22px;
        }
        .filter-grid {
            display: grid; grid-template-columns: repeat(5, minmax(130px, 1fr));
            gap: 14px; align-items: end;
        }
        .filter-field label {
            display: block; margin-bottom: 7px; font-size: 12px;
            font-weight: 600; color: #4b5563;
        }
        .filter-field input, .filter-field select {
            width: 100%; min-width: 0; box-sizing: border-box;
            padding: 10px; border: 1px solid #d9dee8; border-radius: 7px;
            background: #fff; font: inherit; font-size: 13px;
        }
        .summary-grid {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px; margin-bottom: 22px;
        }
        .summary-card {
            background: #fff; border: 1px solid #e7eaf0; border-radius: 12px;
            padding: 19px; min-width: 0;
        }
        .summary-label { color: #6b7280; font-size: 12px; margin-bottom: 10px; }
        .summary-value {
            font-size: 23px; font-weight: 700; color: #18243b;
            overflow-wrap: anywhere;
        }
        .summary-note { font-size: 11px; color: #778196; margin-top: 8px; }
        .report-card h2 { font-size: 17px; margin: 0 0 5px; }
        .report-subtitle { color: #788195; font-size: 12px; margin-bottom: 17px; }
        .table-wrap { width: 100%; overflow-x: auto; }
        .report-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .report-table th {
            text-align: left; padding: 12px 10px; background: #f7f8fb;
            color: #596579; font-size: 11px; white-space: nowrap;
            border-bottom: 1px solid #e7eaf0;
        }
        .report-table td {
            padding: 12px 10px; border-bottom: 1px solid #eef0f4;
            color: #303b4e; white-space: nowrap;
        }
        .report-table tbody tr:hover { background: #fafbfe; }
        .report-table tfoot td {
            font-weight: 700; background: #f7f8fb;
        }
        .text-right { text-align: right !important; }
        .empty-row { padding: 25px !important; text-align: center; color: #8a93a3 !important; }
        .status-pill {
            display: inline-block; padding: 4px 8px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .status-success { color: #147d4a; background: #e7f7ee; }
        .status-danger { color: #b42318; background: #fff0ef; }
        .status-warning { color: #946200; background: #fff5d8; }
        .status-neutral { color: #596579; background: #edf0f5; }
        .profit-panel {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px; margin-bottom: 12px;
        }
        .profit-metric { background: #f7f9fc; padding: 15px; border-radius: 9px; }
        .profit-metric span { display: block; color: #697386; font-size: 12px; margin-bottom: 7px; }
        .profit-metric strong { font-size: 19px; }
        .report-warning {
            background: #fff8e6; border: 1px solid #f1dfaa; color: #795b13;
            border-radius: 8px; padding: 12px 14px; font-size: 12px; margin-top: 14px;
        }
        .report-section { scroll-margin-top: 18px; }
        @media (max-width: 1100px) {
            .filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 650px) {
            .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .summary-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .summary-card { padding: 14px; }
            .summary-value { font-size: 18px; }
            .filter-card, .report-card { padding: 14px; }
            .profit-panel { grid-template-columns: 1fr; }
        }
        @media print {
            .sidebar, .filter-card, .report-actions, .menu-label, .nav-link { display: none !important; }
            .app { display: block !important; }
            .main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            body { background: #fff !important; }
            .report-card, .summary-card { break-inside: avoid; border: 1px solid #ddd; }
            .table-wrap { overflow: visible; }
            .report-table { font-size: 9px; }
            .report-table th, .report-table td { padding: 6px 4px; }
        }
    </style>
</head>
<body>

<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">HS</div>
            <div>
                <h2>Hotel Supply</h2>
                <small>Management System</small>
            </div>
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
        <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
        <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
        <a class="nav-link active" href="reports.php">📊 &nbsp; Reports</a>
    </aside>

    <main class="main">
        <div class="reports-page">

            <div class="report-heading">
                <div>
                    <h1>Reports & Analytics</h1>
                    <p>Review sales, purchases, invoices, payments and estimated profit.</p>
                </div>
                <div class="report-actions">
                    <button type="button" class="report-btn" onclick="window.print()">
                        🖨 Print Report
                    </button>
                    <button type="button" class="report-btn primary" onclick="exportVisibleTables()">
                        ↓ Export CSV
                    </button>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="report-warning">
                    <?= e(implode(' ', $errors)) ?>
                </div>
            <?php endif; ?>

            <form method="GET" class="filter-card">
                <div class="filter-grid">
                    <div class="filter-field">
                        <label for="from">From Date</label>
                        <input type="date" id="from" name="from"
                               value="<?= e($from) ?>" required>
                    </div>

                    <div class="filter-field">
                        <label for="to">To Date</label>
                        <input type="date" id="to" name="to"
                               value="<?= e($to) ?>" required>
                    </div>

                    <div class="filter-field">
                        <label for="hotel_id">Hotel</label>
                        <select name="hotel_id" id="hotel_id">
                            <option value="">All Hotels</option>
                            <?php foreach ($hotels as $hotel): ?>
                                <option value="<?= (int)$hotel['HotelID'] ?>"
                                    <?= (string)$hotelId === (string)$hotel['HotelID'] ? 'selected' : '' ?>>
                                    <?= e($hotel['HotelName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="vendor_id">Market Vendor</label>
                        <select name="vendor_id" id="vendor_id">
                            <option value="">All Vendors</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?= (int)$vendor['VendorID'] ?>"
                                    <?= (string)$vendorId === (string)$vendor['VendorID'] ? 'selected' : '' ?>>
                                    <?= e($vendor['VendorName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="item_id">Item</label>
                        <select name="item_id" id="item_id">
                            <option value="">All Items</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int)$item['ItemID'] ?>"
                                    <?= (string)$itemId === (string)$item['ItemID'] ? 'selected' : '' ?>>
                                    <?= e($item['ItemCode'] . ' - ' . $item['ItemName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="report-actions" style="margin-top:16px">
                    <button type="submit" class="report-btn primary">Apply Filters</button>
                    <a href="reports.php" class="report-btn">Reset Filters</a>
                </div>
            </form>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Completed Delivery Sales</div>
                    <div class="summary-value"><?= e($moneySymbol) ?> <?= reportMoney($salesTotal) ?></div>
                    <div class="summary-note"><?= count($salesRows) ?> delivery line(s)</div>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Market Purchase Costs</div>
                    <div class="summary-value"><?= e($moneySymbol) ?> <?= reportMoney($purchaseTotal) ?></div>
                    <div class="summary-note"><?= count($purchaseRows) ?> purchase(s), including additional costs</div>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Payments Received</div>
                    <div class="summary-value"><?= e($moneySymbol) ?> <?= reportMoney($paymentsTotal) ?></div>
                    <div class="summary-note"><?= count($paymentRows) ?> payment(s) recorded in this period</div>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Invoice Outstanding Balance</div>
                    <div class="summary-value"><?= e($moneySymbol) ?> <?= reportMoney($invoiceOutstanding) ?></div>
                    <div class="summary-note">For invoices dated within the selected period</div>
                </div>
            </div>

            <section class="report-card report-section" id="profit-report">
                <h2>Estimated Profit Summary</h2>
                <div class="report-subtitle">
                    Selected delivery period: <?= e(reportDate($from)) ?> – <?= e(reportDate($to)) ?>
                </div>

                <div class="profit-panel">
                    <div class="profit-metric">
                        <span>Completed Delivery Sales</span>
                        <strong><?= e($moneySymbol) ?> <?= reportMoney($estimatedSales) ?></strong>
                    </div>
                    <div class="profit-metric">
                        <span>Estimated Item Purchase Cost</span>
                        <strong><?= e($moneySymbol) ?> <?= reportMoney($estimatedCost) ?></strong>
                    </div>
                    <div class="profit-metric">
                        <span>Estimated Gross Profit</span>
                        <strong><?= e($moneySymbol) ?> <?= reportMoney($estimatedProfit) ?></strong>
                    </div>
                </div>

                <?php if ($quantityWithoutCost > 0): ?>
                    <div class="report-warning">
                        Some supplied quantities have no recorded market purchase cost.
                        Estimated profit may therefore be overstated.
                    </div>
                <?php endif; ?>

                <div class="report-warning">
                    This is an estimate based on each item's weighted average recorded purchase cost.
                    It does not allocate additional purchase costs, delivery expenses, damaged stock,
                    returns, or stock adjustments. It is not a finalized accounting profit figure.
                </div>
            </section>

            <section class="report-card report-section" id="sales-report">
                <h2>Sales & Delivery Details</h2>
                <div class="report-subtitle">
                    Completed deliveries only. Sales are based on the saved delivery line totals.
                </div>

                <div class="table-wrap">
                    <table class="report-table export-table" data-filename="delivery-sales">
                        <thead>
                            <tr>
                                <th>Delivery Date</th>
                                <th>Delivery No.</th>
                                <th>Hotel</th>
                                <th>Item Code</th>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Line Total (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$salesRows): ?>
                                <tr><td colspan="9" class="empty-row">No completed delivery records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($salesRows as $row): ?>
                                    <tr>
                                        <td><?= e(reportDate($row['DeliveryDate'])) ?></td>
                                        <td><?= e($row['DeliveryNo'] ?: ('DEL-' . $row['DeliveryID'])) ?></td>
                                        <td><?= e($row['HotelName']) ?></td>
                                        <td><?= e($row['ItemCode']) ?></td>
                                        <td><?= e($row['ItemName']) ?></td>
                                        <td><?= e($row['SuppliedQuantity']) ?></td>
                                        <td><?= e($row['UnitSymbol'] ?: '-') ?></td>
                                        <td class="text-right"><?= reportMoney($row['UnitPrice']) ?></td>
                                        <td class="text-right"><?= reportMoney($row['LineTotal']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5">Total</td>
                                <td><?= e($salesQuantity) ?></td>
                                <td colspan="2"></td>
                                <td class="text-right"><?= reportMoney($salesTotal) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="report-card report-section" id="purchase-report">
                <h2>Market Purchase Details</h2>
                <div class="report-subtitle">
                    Purchase totals include additional costs saved on each purchase.
                </div>

                <div class="table-wrap">
                    <table class="report-table export-table" data-filename="market-purchases">
                        <thead>
                            <tr>
                                <th>Purchase Date</th>
                                <th>Receipt No.</th>
                                <th>Vendor</th>
                                <th>Item Lines</th>
                                <th>Quantity Total</th>
                                <th class="text-right">Items Total (Rs.)</th>
                                <th class="text-right">Additional Cost (Rs.)</th>
                                <th class="text-right">Purchase Total (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$purchaseRows): ?>
                                <tr><td colspan="8" class="empty-row">No market purchases found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($purchaseRows as $row): ?>
                                    <tr>
                                        <td><?= e(reportDate($row['PurchaseDate'])) ?></td>
                                        <td><?= e($row['ReceiptNo'] ?: ('PUR-' . $row['PurchaseID'])) ?></td>
                                        <td><?= e($row['VendorName']) ?></td>
                                        <td><?= (int)$row['DetailCount'] ?></td>
                                        <td><?= e($row['ItemQuantity']) ?></td>
                                        <td class="text-right"><?= reportMoney($row['DetailTotal']) ?></td>
                                        <td class="text-right"><?= reportMoney($row['AdditionalCost']) ?></td>
                                        <td class="text-right">
                                            <?= reportMoney((float)$row['TotalAmount'] + (float)$row['AdditionalCost']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7">Total Purchase Costs</td>
                                <td class="text-right"><?= reportMoney($purchaseTotal) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="report-card report-section" id="invoice-report">
                <h2>Invoice & Outstanding Balance Report</h2>
                <div class="report-subtitle">
                    Invoice dates are used for this report. Allocated payments are included regardless of payment date.
                </div>

                <div class="table-wrap">
                    <table class="report-table export-table" data-filename="invoice-balances">
                        <thead>
                            <tr>
                                <th>Invoice No.</th>
                                <th>Hotel</th>
                                <th>Invoice Date</th>
                                <th>Due Date</th>
                                <th class="text-right">Invoice Total (Rs.)</th>
                                <th class="text-right">Allocated Payments (Rs.)</th>
                                <th class="text-right">Balance (Rs.)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$invoiceRows): ?>
                                <tr><td colspan="8" class="empty-row">No invoices found for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($invoiceRows as $row): ?>
                                    <?php $balance = max(0, (float)$row['TotalAmount'] - (float)$row['PaidAmount']); ?>
                                    <tr>
                                        <td><?= e($row['InvoiceNo']) ?></td>
                                        <td><?= e($row['HotelName']) ?></td>
                                        <td><?= e(reportDate($row['InvoiceDate'])) ?></td>
                                        <td><?= e(reportDate($row['DueDate'])) ?></td>
                                        <td class="text-right"><?= reportMoney($row['TotalAmount']) ?></td>
                                        <td class="text-right"><?= reportMoney($row['PaidAmount']) ?></td>
                                        <td class="text-right"><?= reportMoney($balance) ?></td>
                                        <td>
                                            <span class="status-pill <?= e(reportStatusClass($row['Status'])) ?>">
                                                <?= e($row['Status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">Totals</td>
                                <td class="text-right"><?= reportMoney($invoiceTotal) ?></td>
                                <td class="text-right"><?= reportMoney($invoicePaid) ?></td>
                                <td class="text-right"><?= reportMoney($invoiceOutstanding) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="report-card report-section" id="payment-report">
                <h2>Payments Received Report</h2>
                <div class="report-subtitle">
                    Payment records are filtered by payment date and selected hotel.
                </div>

                <div class="table-wrap">
                    <table class="report-table export-table" data-filename="payments-received">
                        <thead>
                            <tr>
                                <th>Payment Date</th>
                                <th>Hotel</th>
                                <th>Payment Method</th>
                                <th>Reference No.</th>
                                <th class="text-right">Payment Amount (Rs.)</th>
                                <th class="text-right">Allocated Amount (Rs.)</th>
                                <th class="text-right">Unallocated (Rs.)</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$paymentRows): ?>
                                <tr><td colspan="8" class="empty-row">No payments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($paymentRows as $row): ?>
                                    <tr>
                                        <td><?= e(reportDate($row['PaymentDate'])) ?></td>
                                        <td><?= e($row['HotelName']) ?></td>
                                        <td><?= e($row['PaymentMethod']) ?></td>
                                        <td><?= e($row['ReferenceNo'] ?: '-') ?></td>
                                        <td class="text-right"><?= reportMoney($row['Amount']) ?></td>
                                        <td class="text-right"><?= reportMoney($row['AllocatedAmount']) ?></td>
                                        <td class="text-right">
                                            <?= reportMoney(max(0, (float)$row['Amount'] - (float)$row['AllocatedAmount'])) ?>
                                        </td>
                                        <td><?= e($row['Notes'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">Total Payments Received</td>
                                <td class="text-right"><?= reportMoney($paymentsTotal) ?></td>
                                <td class="text-right">
                                    <?php
                                    $allocatedTotal = 0;
                                    foreach ($paymentRows as $paymentRow) {
                                        $allocatedTotal += (float)$paymentRow['AllocatedAmount'];
                                    }
                                    echo reportMoney($allocatedTotal);
                                    ?>
                                </td>
                                <td class="text-right"><?= reportMoney(max(0, $paymentsTotal - $allocatedTotal)) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <p style="color:#7b8495;font-size:12px;margin:8px 0 20px">
                Report period: <?= e(reportDate($from)) ?> to <?= e(reportDate($to)) ?>.
                All amounts are displayed in Rs. (Sri Lankan rupees).
            </p>

        </div>
    </main>
</div>

<script>
function exportVisibleTables() {
    const tables = Array.from(document.querySelectorAll('.export-table'));
    const visibleTables = tables.filter(table =>
        table.getClientRects().length > 0
    );

    if (!visibleTables.length) {
        alert('There are no report tables to export.');
        return;
    }

    // Export all report sections into one CSV file, with section headings.
    const lines = [];

    visibleTables.forEach((table, index) => {
        const heading = table.closest('.report-card')?.querySelector('h2');
        if (index > 0) lines.push([]);
        lines.push([heading ? heading.innerText : table.dataset.filename]);

        table.querySelectorAll('tr').forEach(row => {
            const cells = Array.from(row.querySelectorAll('th, td'));
            lines.push(cells.map(cell => cell.innerText.trim()));
        });
    });

    const csv = lines.map(row =>
        row.map(value => {
            // Neutralize spreadsheet formulas in text fields.
            let text = String(value ?? '');
            if (/^[=+\-@]/.test(text)) text = "'" + text;
            return '"' + text.replace(/"/g, '""') + '"';
        }).join(',')
    ).join('\r\n');

    const blob = new Blob(['\uFEFF' + csv], {
        type: 'text/csv;charset=utf-8;'
    });

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'hotel-supply-reports-<?= e($from) ?>-to-<?= e($to) ?>.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
</script>

</body>
</html>
