
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function paymentMoney($amount): string
{
    return number_format((float)$amount, 2);
}

function paymentRedirect(string $url = 'payments.php'): void
{
    header('Location: ' . $url);
    exit;
}

function paymentFlash(string $type, string $message): void
{
    $_SESSION['payment_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['payment_csrf'])) {
    $_SESSION['payment_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['payment_csrf'];

$paymentMethods = [
    'Cash',
    'Bank Transfer',
    'Cheque',
    'Other'
];

/*
|--------------------------------------------------------------------------
| Delete payment
|--------------------------------------------------------------------------
| Allocations are deleted in the same transaction as the payment.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete') {

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        paymentFlash('error', 'Your session token is invalid. Please try again.');
        paymentRedirect();
    }

    $paymentId = (int)($_POST['payment_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare(
            'SELECT PaymentID FROM payment WHERE PaymentID = ? FOR UPDATE'
        );
        $lockStmt->execute([$paymentId]);

        if (!$lockStmt->fetchColumn()) {
            throw new RuntimeException('Payment not found.');
        }

        $deleteAllocations = $pdo->prepare(
            'DELETE FROM payment_allocation WHERE PaymentID = ?'
        );
        $deleteAllocations->execute([$paymentId]);

        $deletePayment = $pdo->prepare(
            'DELETE FROM payment WHERE PaymentID = ?'
        );
        $deletePayment->execute([$paymentId]);

        $pdo->commit();

        paymentFlash('success', 'Payment and its invoice allocations were deleted.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        paymentFlash('error', 'Unable to delete payment: ' . $ex->getMessage());
    }

    paymentRedirect();
}

/*
|--------------------------------------------------------------------------
| Load edit record
|--------------------------------------------------------------------------
*/
$editId = (int)($_GET['edit'] ?? 0);
$editPayment = null;
$existingAllocations = [];

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT * FROM payment WHERE PaymentID = ?'
    );
    $editStmt->execute([$editId]);
    $editPayment = $editStmt->fetch(PDO::FETCH_ASSOC);

    if (!$editPayment) {
        paymentFlash('error', 'Payment record not found.');
        paymentRedirect();
    }

    $allocationStmt = $pdo->prepare("
        SELECT InvoiceID, AllocatedAmount
        FROM payment_allocation
        WHERE PaymentID = ?
        ORDER BY AllocationID
    ");
    $allocationStmt->execute([$editId]);
    $existingAllocations = $allocationStmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Save payment
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save') {

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        paymentFlash('error', 'Your session token is invalid. Please refresh and try again.');
        paymentRedirect();
    }

    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $hotelId = (int)($_POST['hotel_id'] ?? 0);
    $paymentDate = trim((string)($_POST['payment_date'] ?? ''));
    $amountInput = trim((string)($_POST['amount'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $invoiceIds = $_POST['invoice_id'] ?? [];
    $allocationAmounts = $_POST['allocation_amount'] ?? [];

    $errors = [];

    if ($paymentId < 0) {
        $errors[] = 'Invalid payment ID.';
    }

    if ($paymentId > 0 && $paymentId !== $editId) {
        $errors[] = 'Invalid edit request. Please reopen the payment record.';
    }

    if ($hotelId <= 0) {
        $errors[] = 'Please select a hotel.';
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $paymentDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $paymentDate) {
        $errors[] = 'Please enter a valid payment date.';
    }

    if ($amountInput === '' || !is_numeric($amountInput) || (float)$amountInput <= 0) {
        $errors[] = 'Payment amount must be greater than zero.';
    }

    $amount = is_numeric($amountInput) ? round((float)$amountInput, 2) : 0;

    if (!in_array($paymentMethod, $paymentMethods, true)) {
        $errors[] = 'Please select a valid payment method.';
    }

    if (strlen($referenceNo) > 100) {
        $errors[] = 'Reference number cannot exceed 100 characters.';
    }

    if (strlen($notes) > 2000) {
        $errors[] = 'Notes cannot exceed 2000 characters.';
    }

    if (!is_array($invoiceIds) || !is_array($allocationAmounts)) {
        $errors[] = 'Invalid invoice allocation data.';
        $invoiceIds = [];
        $allocationAmounts = [];
    }

    $allocationRows = [];
    $seenInvoiceIds = [];
    $allocatedTotal = 0;

    /*
    |--------------------------------------------------------------------------
    | Validate invoice allocations
    |--------------------------------------------------------------------------
    */
    if (!$errors) {
        try {
            $hotelStmt = $pdo->prepare(
                'SELECT HotelID FROM hotel WHERE HotelID = ? AND IsActive = 1'
            );
            $hotelStmt->execute([$hotelId]);

            if (!$hotelStmt->fetchColumn()) {
                $errors[] = 'Please select an active hotel.';
            }

            $count = max(count($invoiceIds), count($allocationAmounts));

            if ($count > 200) {
                $errors[] = 'Too many invoice allocation rows.';
            }

            for ($i = 0; $i < $count; $i++) {
                $rawInvoiceId = $invoiceIds[$i] ?? '';
                $rawAllocation = trim((string)($allocationAmounts[$i] ?? ''));

                if ($rawInvoiceId === '' && $rawAllocation === '') {
                    continue;
                }

                $invoiceId = filter_var($rawInvoiceId, FILTER_VALIDATE_INT);

                if (!$invoiceId || $invoiceId <= 0) {
                    $errors[] = 'Please select a valid invoice for each allocation.';
                    continue;
                }

                if (isset($seenInvoiceIds[$invoiceId])) {
                    $errors[] = 'The same invoice cannot be allocated more than once.';
                    continue;
                }

                $seenInvoiceIds[$invoiceId] = true;

                if ($rawAllocation === '' || !is_numeric($rawAllocation)) {
                    $errors[] = 'Enter an allocation amount for every selected invoice.';
                    continue;
                }

                $allocationAmount = round((float)$rawAllocation, 2);

                if ($allocationAmount <= 0) {
                    $errors[] = 'Allocation amounts must be greater than zero.';
                    continue;
                }

                $allocationRows[] = [
                    'InvoiceID' => (int)$invoiceId,
                    'AllocatedAmount' => $allocationAmount
                ];

                $allocatedTotal = round($allocatedTotal + $allocationAmount, 2);
            }

            if ($allocatedTotal > $amount + 0.001) {
                $errors[] = 'Total allocated amount cannot exceed the payment amount.';
            }

            /*
             * Lock and validate selected invoices.
             * Only invoices belonging to the selected hotel can be allocated.
             * Current payment allocations are excluded when editing.
             */
            if (!$errors && $allocationRows) {
                $pdo->beginTransaction();

                if ($paymentId > 0) {
                    $lockPayment = $pdo->prepare(
                        'SELECT PaymentID FROM payment WHERE PaymentID = ? FOR UPDATE'
                    );
                    $lockPayment->execute([$paymentId]);

                    if (!$lockPayment->fetchColumn()) {
                        throw new RuntimeException('Payment record no longer exists.');
                    }
                }

                $invoiceCheck = $pdo->prepare("
                    SELECT
                        inv.InvoiceID,
                        inv.TotalAmount,
                        inv.Status,
                        h.HotelID,
                        COALESCE((
                            SELECT SUM(pa.AllocatedAmount)
                            FROM payment_allocation pa
                            WHERE pa.InvoiceID = inv.InvoiceID
                              AND pa.PaymentID <> ?
                        ), 0) AS OtherAllocated
                    FROM invoice inv
                    INNER JOIN delivery d ON d.DeliveryID = inv.DeliveryID
                    INNER JOIN hotel_order o ON o.OrderID = d.OrderID
                    INNER JOIN hotel h ON h.HotelID = o.HotelID
                    WHERE inv.InvoiceID = ?
                    FOR UPDATE
                ");

                foreach ($allocationRows as $row) {
                    $invoiceCheck->execute([
                        $paymentId,
                        $row['InvoiceID']
                    ]);

                    $invoice = $invoiceCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$invoice) {
                        $errors[] = 'Invoice #' . $row['InvoiceID'] . ' was not found.';
                        continue;
                    }

                    if ((int)$invoice['HotelID'] !== $hotelId) {
                        $errors[] = 'Invoice #' . $row['InvoiceID']
                            . ' does not belong to the selected hotel.';
                        continue;
                    }

                    if (in_array($invoice['Status'], ['Draft', 'Cancelled'], true)) {
                        $errors[] = 'Invoice #' . $row['InvoiceID']
                            . ' cannot receive a payment because it is '
                            . strtolower($invoice['Status']) . '.';
                        continue;
                    }

                    $remaining = round(
                        (float)$invoice['TotalAmount'] - (float)$invoice['OtherAllocated'],
                        2
                    );

                    if ($row['AllocatedAmount'] > $remaining + 0.001) {
                        $errors[] = 'Allocation for invoice #'
                            . $row['InvoiceID']
                            . ' exceeds its remaining balance of '
                            . number_format(max(0, $remaining), 2) . '.';
                    }
                }

                if ($errors) {
                    $pdo->rollBack();
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Validation failed: ' . $ex->getMessage();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save payment and allocations
    |--------------------------------------------------------------------------
    */
    if (!$errors) {
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            if ($paymentId > 0) {
                $update = $pdo->prepare("
                    UPDATE payment
                    SET HotelID = ?,
                        PaymentDate = ?,
                        Amount = ?,
                        PaymentMethod = ?,
                        ReferenceNo = ?,
                        Notes = ?
                    WHERE PaymentID = ?
                ");

                $update->execute([
                    $hotelId,
                    $paymentDate,
                    $amount,
                    $paymentMethod,
                    $referenceNo !== '' ? $referenceNo : null,
                    $notes !== '' ? $notes : null,
                    $paymentId
                ]);

                $deleteOld = $pdo->prepare(
                    'DELETE FROM payment_allocation WHERE PaymentID = ?'
                );
                $deleteOld->execute([$paymentId]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO payment
                        (HotelID, PaymentDate, Amount, PaymentMethod, ReferenceNo, Notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $hotelId,
                    $paymentDate,
                    $amount,
                    $paymentMethod,
                    $referenceNo !== '' ? $referenceNo : null,
                    $notes !== '' ? $notes : null
                ]);

                $paymentId = (int)$pdo->lastInsertId();
            }

            if ($allocationRows) {
                $insertAllocation = $pdo->prepare("
                    INSERT INTO payment_allocation
                        (PaymentID, InvoiceID, AllocatedAmount)
                    VALUES (?, ?, ?)
                ");

                foreach ($allocationRows as $row) {
                    $insertAllocation->execute([
                        $paymentId,
                        $row['InvoiceID'],
                        $row['AllocatedAmount']
                    ]);
                }
            }

            $pdo->commit();

            paymentFlash(
                'success',
                $editId > 0
                    ? 'Payment updated successfully.'
                    : 'Payment recorded successfully.'
            );

            paymentRedirect();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Unable to save payment: ' . $ex->getMessage();
        }
    }

    /*
     * Preserve form values after validation errors.
     */
    $editPayment = [
        'PaymentID' => $paymentId,
        'HotelID' => $hotelId,
        'PaymentDate' => $paymentDate,
        'Amount' => $amountInput,
        'PaymentMethod' => $paymentMethod,
        'ReferenceNo' => $referenceNo,
        'Notes' => $notes
    ];

    $existingAllocations = $allocationRows;
    $editId = $paymentId;
}

/*
|--------------------------------------------------------------------------
| Hotels dropdown
|--------------------------------------------------------------------------
*/
$hotels = $pdo->query("
    SELECT HotelID, HotelName
    FROM hotel
    WHERE IsActive = 1
    ORDER BY HotelName
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Invoices for allocation dropdowns
|--------------------------------------------------------------------------
| For edits, exclude this payment's existing allocations when calculating
| the remaining amount available to allocate.
|--------------------------------------------------------------------------
*/
$invoiceStmt = $pdo->prepare("
    SELECT
        inv.InvoiceID,
        inv.InvoiceNo,
        inv.InvoiceDate,
        inv.DueDate,
        inv.TotalAmount,
        inv.Status,
        h.HotelID,
        h.HotelName,
        COALESCE((
            SELECT SUM(pa.AllocatedAmount)
            FROM payment_allocation pa
            WHERE pa.InvoiceID = inv.InvoiceID
              AND pa.PaymentID <> ?
        ), 0) AS OtherAllocated
    FROM invoice inv
    INNER JOIN delivery d ON d.DeliveryID = inv.DeliveryID
    INNER JOIN hotel_order o ON o.OrderID = d.OrderID
    INNER JOIN hotel h ON h.HotelID = o.HotelID
    WHERE inv.Status NOT IN ('Draft', 'Cancelled')
    ORDER BY h.HotelName, inv.InvoiceDate DESC, inv.InvoiceID DESC
");
$invoiceStmt->execute([$editId]);
$invoiceRows = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);

$availableInvoices = [];

foreach ($invoiceRows as $row) {
    $remaining = round(
        (float)$row['TotalAmount'] - (float)$row['OtherAllocated'],
        2
    );

    $availableInvoices[] = [
        'InvoiceID' => (int)$row['InvoiceID'],
        'InvoiceNo' => $row['InvoiceNo'],
        'InvoiceDate' => $row['InvoiceDate'],
        'DueDate' => $row['DueDate'],
        'TotalAmount' => (float)$row['TotalAmount'],
        'HotelID' => (int)$row['HotelID'],
        'HotelName' => $row['HotelName'],
        'Remaining' => max(0, $remaining)
    ];
}

/*
|--------------------------------------------------------------------------
| Payment history search
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        h.HotelName LIKE ?
        OR p.ReferenceNo LIKE ?
        OR p.PaymentMethod LIKE ?
        OR CAST(p.PaymentID AS CHAR) LIKE ?
    )";

    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($dateFrom !== '') {
    $where[] = 'p.PaymentDate >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'p.PaymentDate <= ?';
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$historySql = "
    SELECT
        p.PaymentID,
        p.HotelID,
        p.PaymentDate,
        p.Amount,
        p.PaymentMethod,
        p.ReferenceNo,
        p.Notes,
        h.HotelName,
        COALESCE((
            SELECT SUM(pa.AllocatedAmount)
            FROM payment_allocation pa
            WHERE pa.PaymentID = p.PaymentID
        ), 0) AS AllocatedAmount,
        (
            SELECT COUNT(*)
            FROM payment_allocation pa
            WHERE pa.PaymentID = p.PaymentID
        ) AS AllocationCount
    FROM payment p
    INNER JOIN hotel h ON h.HotelID = p.HotelID
    $whereSql
    ORDER BY p.PaymentDate DESC, p.PaymentID DESC
";

$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($params);
$payments = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/
$summary = $pdo->query("
    SELECT
        COUNT(*) AS PaymentCount,
        COALESCE(SUM(p.Amount), 0) AS TotalReceived,
        COALESCE(SUM((
            SELECT COALESCE(SUM(pa.AllocatedAmount), 0)
            FROM payment_allocation pa
            WHERE pa.PaymentID = p.PaymentID
        )), 0) AS TotalAllocated,
        COALESCE(SUM(
            p.Amount - (
                SELECT COALESCE(SUM(pa.AllocatedAmount), 0)
                FROM payment_allocation pa
                WHERE pa.PaymentID = p.PaymentID
            )
        ), 0) AS UnallocatedAmount
    FROM payment p
")->fetch(PDO::FETCH_ASSOC);

$flash = $_SESSION['payment_flash'] ?? null;
unset($_SESSION['payment_flash']);

$formPayment = $editPayment ?? [];
$formHotelId = (int)($formPayment['HotelID'] ?? 0);
$formDate = $formPayment['PaymentDate'] ?? date('Y-m-d');
$formAmount = $formPayment['Amount'] ?? '';
$formMethod = $formPayment['PaymentMethod'] ?? 'Bank Transfer';
$formReference = $formPayment['ReferenceNo'] ?? '';
$formNotes = $formPayment['Notes'] ?? '';

$formAllocations = [];

foreach ($existingAllocations as $allocation) {
    $formAllocations[] = [
        'InvoiceID' => (int)$allocation['InvoiceID'],
        'AllocatedAmount' => (float)$allocation['AllocatedAmount']
    ];
}

if (!$formAllocations) {
    $formAllocations[] = [
        'InvoiceID' => '',
        'AllocatedAmount' => ''
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | Hotel Supply Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .page-header {
            display:flex; justify-content:space-between; align-items:flex-start;
            flex-wrap:wrap; gap:16px; margin-bottom:24px;
        }
        .page-header h1 { margin:0 0 7px; font-size:27px; }
        .page-header p { margin:0; color:#718096; }

        .payment-stats {
            display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
            gap:16px; margin-bottom:24px;
        }
        .stat-card,.panel {
            background:#fff; border:1px solid #e7ebf0; border-radius:12px;
            box-shadow:0 2px 8px rgba(15,23,42,.025);
        }
        .stat-card { padding:18px; }
        .stat-card span { display:block; color:#718096; font-size:13px; margin-bottom:8px; }
        .stat-card strong { font-size:22px; color:#172b4d; }

        .panel { padding:22px; margin-bottom:22px; }
        .panel-heading {
            display:flex; justify-content:space-between; align-items:center;
            flex-wrap:wrap; gap:12px; margin-bottom:20px;
        }
        .panel-heading h2 { margin:0; font-size:18px; }
        .muted { color:#778399; }

        .form-grid {
            display:grid; grid-template-columns:repeat(2,minmax(0,1fr));
            gap:16px;
        }
        .field { display:flex; flex-direction:column; gap:7px; min-width:0; }
        .field.full { grid-column:1/-1; }
        .field label { font-size:13px; font-weight:600; color:#344054; }
        .field input,.field select,.field textarea {
            width:100%; box-sizing:border-box; padding:10px 11px;
            border:1px solid #d7dde5; border-radius:7px;
            background:#fff; min-height:42px; font:inherit;
        }
        .field textarea { min-height:85px; resize:vertical; }
        .help-text { color:#778399; font-size:12px; line-height:1.5; }

        .btn {
            display:inline-flex; justify-content:center; align-items:center;
            gap:6px; border:0; border-radius:7px; padding:10px 14px;
            cursor:pointer; font:inherit; font-size:13px; font-weight:600;
            text-decoration:none; white-space:nowrap;
        }
        .btn-primary { background:#3157c8; color:#fff; }
        .btn-secondary { background:#eef2f7; color:#344054; }
        .btn-danger { background:#fee4e2; color:#b42318; }
        .btn-small { padding:7px 10px; font-size:12px; }
        .actions { display:flex; flex-wrap:wrap; gap:7px; align-items:center; }
        .form-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:20px; }

        .allocation-table { width:100%; border-collapse:collapse; min-width:650px; }
        .allocation-table th,.allocation-table td {
            padding:10px; border-bottom:1px solid #edf0f4; text-align:left;
        }
        .allocation-table th { background:#f8fafc; color:#667085; font-size:12px; }
        .allocation-table select,.allocation-table input {
            width:100%; box-sizing:border-box; min-height:39px; padding:8px;
            border:1px solid #d7dde5; border-radius:6px; background:#fff;
        }
        .table-wrap { overflow-x:auto; }
        .allocation-total {
            display:flex; justify-content:flex-end; gap:22px; align-items:center;
            flex-wrap:wrap; padding-top:17px; font-size:14px;
        }
        .allocation-total strong { color:#172b4d; font-size:17px; }
        .remaining-label { display:block; margin-top:4px; font-size:11px; color:#667085; }

        .filter-grid {
            display:grid; grid-template-columns:2fr 1fr 1fr auto;
            gap:12px; align-items:end;
        }
        .data-table { width:100%; border-collapse:collapse; min-width:1000px; }
        .data-table th,.data-table td {
            padding:13px 12px; border-bottom:1px solid #edf0f4;
            text-align:left; vertical-align:middle;
        }
        .data-table th { background:#f8fafc; color:#667085; font-size:12px; white-space:nowrap; }
        .data-table td { font-size:13px; }
        .amount { font-weight:700; white-space:nowrap; }
        .badge {
            display:inline-block; padding:5px 9px; border-radius:20px;
            font-size:11px; font-weight:700; white-space:nowrap;
        }
        .badge-allocated { background:#dcfae6; color:#067647; }
        .badge-unallocated { background:#fff4d6; color:#8a5b00; }
        .flash { padding:13px 15px; border-radius:8px; margin-bottom:18px; font-size:13px; }
        .flash-success { background:#ecfdf3; color:#067647; border:1px solid #abefc6; }
        .flash-error { background:#fff1f0; color:#b42318; border:1px solid #fecdca; }
        .empty-state { padding:28px 12px; text-align:center; color:#778399; }
        .allocation-help {
            padding:12px 14px; background:#f8fafc; border:1px solid #e7ebf0;
            border-radius:8px; color:#667085; font-size:12px; line-height:1.6;
            margin-bottom:16px;
        }

        @media(max-width:1000px) {
            .payment-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media(max-width:650px) {
            .panel { padding:15px; }
            .form-grid,.filter-grid { grid-template-columns:1fr; }
            .field.full { grid-column:auto; }
            .payment-stats { gap:10px; }
            .stat-card { padding:13px; }
            .stat-card strong { font-size:18px; }
            .page-header h1 { font-size:23px; }
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
        <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
        <a class="nav-link active" href="payments.php">💳 &nbsp; Payments</a>
        <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
    </aside>

    <main class="main">
        <div class="page-header">
            <div>
                <h1>Payments</h1>
                <p>Record hotel payments and allocate them against invoices.</p>
            </div>
            <a href="invoices.php" class="btn btn-secondary">📄 View Invoices</a>
        </div>

        <?php if ($flash): ?>
            <div class="flash <?= ($flash['type'] ?? '') === 'success' ? 'flash-success' : 'flash-error' ?>">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="flash flash-error">
                <strong>Please correct the following:</strong>
                <ul style="margin:8px 0 0;padding-left:20px">
                    <?php foreach (array_unique($errors) as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="payment-stats">
            <div class="stat-card">
                <span>Payment records</span>
                <strong><?= number_format((int)$summary['PaymentCount']) ?></strong>
            </div>
            <div class="stat-card">
                <span>Total received</span>
                <strong><?= paymentMoney($summary['TotalReceived']) ?></strong>
            </div>
            <div class="stat-card">
                <span>Total allocated</span>
                <strong><?= paymentMoney($summary['TotalAllocated']) ?></strong>
            </div>
            <div class="stat-card">
                <span>Unallocated payments</span>
                <strong><?= paymentMoney($summary['UnallocatedAmount']) ?></strong>
            </div>
        </section>

        <section class="panel" id="payment-form">
            <div class="panel-heading">
                <div>
                    <h2><?= $editId > 0 ? 'Edit Payment' : 'Record New Payment' ?></h2>
                    <p class="muted" style="margin:6px 0 0">
                        Enter the amount received from the hotel and allocate it to invoices.
                    </p>
                </div>

                <?php if ($editId > 0): ?>
                    <a href="payments.php" class="btn btn-secondary">＋ New Payment</a>
                <?php endif; ?>
            </div>

            <form method="POST" action="payments.php<?= $editId > 0 ? '?edit=' . (int)$editId : '' ?>"
                  id="paymentForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="payment_id" value="<?= (int)$editId ?>">

                <div class="form-grid">
                    <div class="field">
                        <label for="hotel_id">Hotel *</label>
                        <select name="hotel_id" id="hotel_id" required>
                            <option value="">Select hotel</option>
                            <?php foreach ($hotels as $hotel): ?>
                                <option value="<?= (int)$hotel['HotelID'] ?>"
                                    <?= $formHotelId === (int)$hotel['HotelID'] ? 'selected' : '' ?>>
                                    <?= e($hotel['HotelName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="payment_date">Payment Date *</label>
                        <input type="date" name="payment_date" id="payment_date"
                               value="<?= e($formDate) ?>" required>
                    </div>

                    <div class="field">
                        <label for="amount">Amount Received *</label>
                        <input type="number" name="amount" id="amount" min="0.01"
                               step="0.01" value="<?= e($formAmount) ?>"
                               placeholder="0.00" required>
                    </div>

                    <div class="field">
                        <label for="payment_method">Payment Method *</label>
                        <select name="payment_method" id="payment_method" required>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= e($method) ?>"
                                    <?= $formMethod === $method ? 'selected' : '' ?>>
                                    <?= e($method) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="reference_no">Reference Number</label>
                        <input type="text" name="reference_no" id="reference_no"
                               maxlength="100" value="<?= e($formReference) ?>"
                               placeholder="Bank transaction / cheque number">
                    </div>

                    <div class="field">
                        <label for="notes">Notes</label>
                        <input type="text" name="notes" id="notes" maxlength="2000"
                               value="<?= e($formNotes) ?>"
                               placeholder="Optional payment notes">
                    </div>
                </div>

                <div style="margin-top:28px">
                    <div class="panel-heading">
                        <div>
                            <h2>Invoice Allocations</h2>
                            <p class="muted" style="margin:6px 0 0">
                                Choose invoices belonging to the selected hotel.
                            </p>
                        </div>
                        <button type="button" class="btn btn-secondary" id="addAllocation">
                            ＋ Add Invoice
                        </button>
                    </div>

                    <div class="allocation-help">
                        Allocations are optional when recording a payment. You can allocate the
                        payment later. The total allocated amount cannot exceed the amount received,
                        and an invoice cannot be overpaid through this page.
                    </div>

                    <div class="table-wrap">
                        <table class="allocation-table">
                            <thead>
                                <tr>
                                    <th style="width:58%">Invoice</th>
                                    <th style="width:27%">Allocate Amount</th>
                                    <th style="width:15%">Action</th>
                                </tr>
                            </thead>
                            <tbody id="allocationRows"></tbody>
                        </table>
                    </div>

                    <div class="allocation-total">
                        <span>Payment amount: <strong id="paymentAmountDisplay">0.00</strong></span>
                        <span>Allocated: <strong id="allocatedDisplay">0.00</strong></span>
                        <span>Unallocated: <strong id="unallocatedDisplay">0.00</strong></span>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= $editId > 0 ? 'Save Changes' : 'Record Payment' ?>
                    </button>
                    <a href="payments.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Payment History</h2>
                    <p class="muted" style="margin:6px 0 0">
                        Search payments and review allocated or unallocated amounts.
                    </p>
                </div>
            </div>

            <form method="GET" action="payments.php" style="margin-bottom:20px">
                <div class="filter-grid">
                    <div class="field">
                        <label for="search">Search</label>
                        <input type="search" name="search" id="search"
                               value="<?= e($search) ?>"
                               placeholder="Hotel, reference, method, payment ID">
                    </div>
                    <div class="field">
                        <label for="date_from">Payment date from</label>
                        <input type="date" name="date_from" id="date_from"
                               value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="field">
                        <label for="date_to">Payment date to</label>
                        <input type="date" name="date_to" id="date_to"
                               value="<?= e($dateTo) ?>">
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="payments.php" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Date</th>
                            <th>Hotel</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Amount Received</th>
                            <th>Allocated</th>
                            <th>Unallocated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $received = (float)$payment['Amount'];
                        $allocated = (float)$payment['AllocatedAmount'];
                        $unallocated = round($received - $allocated, 2);
                        ?>
                        <tr>
                            <td><strong>#<?= (int)$payment['PaymentID'] ?></strong></td>
                            <td><?= e($payment['PaymentDate']) ?></td>
                            <td><strong><?= e($payment['HotelName']) ?></strong></td>
                            <td><?= e($payment['PaymentMethod']) ?></td>
                            <td><?= e($payment['ReferenceNo'] ?: '—') ?></td>
                            <td class="amount"><?= paymentMoney($received) ?></td>
                            <td>
                                <?= paymentMoney($allocated) ?>
                                <div class="muted" style="font-size:11px;margin-top:4px">
                                    <?= (int)$payment['AllocationCount'] ?> invoice(s)
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $unallocated > 0.009 ? 'badge-unallocated' : 'badge-allocated' ?>">
                                    <?= paymentMoney(max(0, $unallocated)) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-secondary btn-small"
                                       href="payments.php?edit=<?= (int)$payment['PaymentID'] ?>#payment-form">
                                        Edit
                                    </a>

                                    <form method="POST" action="payments.php"
                                          onsubmit="return confirm('Delete this payment and all its invoice allocations?');"
                                          style="display:inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="payment_id"
                                               value="<?= (int)$payment['PaymentID'] ?>">
                                        <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$payments): ?>
                        <tr>
                            <td colspan="9" class="empty-state">No payments found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted" style="margin-top:12px"><?= count($payments) ?> payment(s) shown.</p>
        </section>
    </main>
</div>

<script>
const availableInvoices = <?= json_encode(
    $availableInvoices,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const initialAllocations = <?= json_encode(
    $formAllocations,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const hotelSelect = document.getElementById('hotel_id');
const amountInput = document.getElementById('amount');
const allocationRows = document.getElementById('allocationRows');
const addAllocationButton = document.getElementById('addAllocation');

function money(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];
    });
}

function invoiceLabel(invoice) {
    return `${invoice.InvoiceNo || ('Invoice #' + invoice.InvoiceID)}`
        + ` | ${invoice.InvoiceDate}`
        + ` | Total ${money(invoice.TotalAmount)}`
        + ` | Available ${money(invoice.Remaining)}`;
}

function addAllocationRow(invoiceId = '', amount = '') {
    const tr = document.createElement('tr');
    const selectedHotelId = Number(hotelSelect.value || 0);

    let options = '<option value="">Select invoice</option>';

    availableInvoices.forEach(invoice => {
        if (Number(invoice.HotelID) !== selectedHotelId) {
            return;
        }

        const selected = Number(invoice.InvoiceID) === Number(invoiceId)
            && invoiceId !== ''
            ? 'selected'
            : '';

        options += `<option value="${Number(invoice.InvoiceID)}" ${selected}>
            ${escapeHtml(invoiceLabel(invoice))}
        </option>`;
    });

    tr.innerHTML = `
        <td>
            <select name="invoice_id[]" class="allocation-invoice">
                ${options}
            </select>
        </td>
        <td>
            <input type="number" name="allocation_amount[]"
                   class="allocation-amount" min="0.01" step="0.01"
                   value="${amount !== '' ? Number(amount).toFixed(2) : ''}"
                   placeholder="0.00">
            <span class="remaining-label"></span>
        </td>
        <td>
            <button type="button" class="btn btn-danger btn-small remove-allocation">
                Remove
            </button>
        </td>
    `;

    allocationRows.appendChild(tr);

    const invoiceSelect = tr.querySelector('.allocation-invoice');
    const allocationInput = tr.querySelector('.allocation-amount');
    const removeButton = tr.querySelector('.remove-allocation');

    invoiceSelect.addEventListener('change', function () {
        const selectedInvoice = availableInvoices.find(
            item => Number(item.InvoiceID) === Number(this.value)
        );

        if (selectedInvoice) {
            allocationInput.max = selectedInvoice.Remaining.toFixed(2);
            tr.querySelector('.remaining-label').textContent =
                'Available: ' + money(selectedInvoice.Remaining);
        } else {
            allocationInput.removeAttribute('max');
            tr.querySelector('.remaining-label').textContent = '';
        }

        updateTotals();
    });

    allocationInput.addEventListener('input', updateTotals);

    removeButton.addEventListener('click', function () {
        tr.remove();
        updateTotals();
    });

    invoiceSelect.dispatchEvent(new Event('change'));
}

function updateTotals() {
    const paymentAmount = Math.round(Number(amountInput.value || 0) * 100) / 100;
    let allocated = 0;

    document.querySelectorAll('.allocation-amount').forEach(input => {
        allocated += Number(input.value || 0);
    });

    allocated = Math.round(allocated * 100) / 100;

    document.getElementById('paymentAmountDisplay').textContent = money(paymentAmount);
    document.getElementById('allocatedDisplay').textContent = money(allocated);
    document.getElementById('unallocatedDisplay').textContent =
        money(Math.max(0, paymentAmount - allocated));
}

function refreshInvoiceOptions() {
    const currentRows = [];

    allocationRows.querySelectorAll('tr').forEach(tr => {
        currentRows.push({
            invoiceId: tr.querySelector('.allocation-invoice').value,
            amount: tr.querySelector('.allocation-amount').value
        });
    });

    allocationRows.innerHTML = '';

    currentRows.forEach(row => {
        addAllocationRow(row.invoiceId, row.amount);
    });

    updateTotals();
}

hotelSelect.addEventListener('change', refreshInvoiceOptions);
amountInput.addEventListener('input', updateTotals);

addAllocationButton.addEventListener('click', function () {
    addAllocationRow();
    updateTotals();
});

initialAllocations.forEach(row => {
    addAllocationRow(row.InvoiceID, row.AllocatedAmount);
});

updateTotals();

document.getElementById('paymentForm').addEventListener('submit', function (event) {
    const paymentAmount = Math.round(Number(amountInput.value || 0) * 100) / 100;
    let allocated = 0;
    let invalidInvoice = false;

    const selectedInvoiceIds = [];

    allocationRows.querySelectorAll('tr').forEach(tr => {
        const invoiceId = tr.querySelector('.allocation-invoice').value;
        const allocationInput = tr.querySelector('.allocation-amount');
        const value = allocationInput.value;

        if (!invoiceId && !value) {
            return;
        }

        if (!invoiceId || value === '' || Number(value) <= 0) {
            invalidInvoice = true;
            return;
        }

        if (selectedInvoiceIds.includes(invoiceId)) {
            invalidInvoice = true;
            return;
        }

        selectedInvoiceIds.push(invoiceId);
        allocated += Number(value);
    });

    allocated = Math.round(allocated * 100) / 100;

    if (invalidInvoice) {
        event.preventDefault();
        alert('Please select a unique invoice and enter a positive allocation amount for every allocation row.');
        return;
    }

    if (allocated > paymentAmount + 0.001) {
        event.preventDefault();
        alert('The total allocated amount cannot exceed the payment amount.');
    }
});
</script>
</body>
</html>
