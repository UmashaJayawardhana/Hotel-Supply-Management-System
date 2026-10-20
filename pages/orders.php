
<?php
//declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function orderFlash(string $type, string $message): void
{
    $_SESSION['order_flash'] = ['type' => $type, 'message' => $message];
}

function orderRedirect(string $url = 'orders.php'): never
{
    header('Location: ' . $url);
    exit;
}

function orderStatusLabel(string $status): string
{
    return match ($status) {
        'Received' => 'Received',
        'Confirmed' => 'Confirmed',
        'Partially Delivered' => 'Partially Delivered',
        'Delivered' => 'Delivered',
        'Cancelled' => 'Cancelled',
        default => $status,
    };
}

$allowedStatuses = [
    'Received',
    'Confirmed',
    'Partially Delivered',
    'Delivered',
    'Cancelled',
];

$errors = [];
$editOrder = null;
$editDetails = [];
$viewOrder = null;
$viewDetails = [];

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        orderFlash('error', 'Your session token expired. Please try again.');
        orderRedirect();
    }

    $action = (string)($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Save / update order
    |--------------------------------------------------------------------------
    */
    if ($action === 'save') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $hotelId = (int)($_POST['hotel_id'] ?? 0);
        $orderDate = trim((string)($_POST['order_date'] ?? ''));
        $emailReceivedAt = trim((string)($_POST['email_received_at'] ?? ''));
        $emailReference = trim((string)($_POST['email_reference'] ?? ''));
        $emailSubject = trim((string)($_POST['email_subject'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'Received'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $itemIds = $_POST['item_id'] ?? [];
        $quantities = $_POST['ordered_quantity'] ?? [];
        $itemNotes = $_POST['item_notes'] ?? [];

        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Select a valid order status.';
        }

        if ($hotelId <= 0) {
            $errors[] = 'Please select a hotel.';
        }

        if ($orderDate === '') {
            $errors[] = 'Order date is required.';
        } else {
            $date = DateTime::createFromFormat('!Y-m-d', $orderDate);
            if (!$date || $date->format('Y-m-d') !== $orderDate) {
                $errors[] = 'Enter a valid order date.';
            }
        }

        if ($emailReceivedAt !== '') {
            $receivedDate = DateTime::createFromFormat('Y-m-d\TH:i', $emailReceivedAt);
            if (!$receivedDate) {
                $errors[] = 'Enter a valid email received date and time.';
            }
        }

        if (strlen($emailReference) > 100) {
            $errors[] = 'Email reference must not exceed 100 characters.';
        }

        if (strlen($emailSubject) > 255) {
            $errors[] = 'Email subject must not exceed 255 characters.';
        }

        if (!is_array($itemIds) || !is_array($quantities) || !is_array($itemNotes)) {
            $errors[] = 'Invalid order item data.';
            $itemIds = [];
            $quantities = [];
            $itemNotes = [];
        }

        $rows = [];
        $seenItems = [];

        foreach ($itemIds as $index => $rawItemId) {
            $rawItemId = trim((string)$rawItemId);
            $rawQuantity = trim((string)($quantities[$index] ?? ''));
            $rawNote = trim((string)($itemNotes[$index] ?? ''));

            // Ignore a completely empty row.
            if ($rawItemId === '' && $rawQuantity === '' && $rawNote === '') {
                continue;
            }

            if ($rawItemId === '' || !ctype_digit($rawItemId) || (int)$rawItemId <= 0) {
                $errors[] = 'Select an item for every populated row.';
                continue;
            }

            $itemId = (int)$rawItemId;

            if (isset($seenItems[$itemId])) {
                $errors[] = 'An item can only appear once in an order. Combine its quantities into one row.';
                continue;
            }
            $seenItems[$itemId] = true;

            if ($rawQuantity === '' || !is_numeric($rawQuantity) || (float)$rawQuantity <= 0) {
                $errors[] = 'Every order item must have a quantity greater than zero.';
                continue;
            }

            if (!is_finite((float)$rawQuantity) || (float)$rawQuantity > 999999999) {
                $errors[] = 'One or more quantities are outside the allowed range.';
                continue;
            }

            $rows[] = [
                'item_id' => $itemId,
                'quantity' => round((float)$rawQuantity, 3),
                'notes' => $rawNote,
            ];
        }

        if (count($rows) === 0) {
            $errors[] = 'Add at least one item to the order.';
        }

        try {
            // Ensure the hotel is valid and active.
            $hotelStmt = $pdo->prepare(
                'SELECT HotelID FROM hotel WHERE HotelID = ? AND IsActive = 1'
            );
            $hotelStmt->execute([$hotelId]);

            if (!$hotelStmt->fetchColumn()) {
                $errors[] = 'The selected hotel is unavailable or inactive.';
            }

            // Ensure all selected items are valid and active.
            if ($rows) {
                $checkItem = $pdo->prepare(
                    'SELECT ItemID FROM item WHERE ItemID = ? AND IsActive = 1'
                );

                foreach ($rows as $row) {
                    $checkItem->execute([$row['item_id']]);

                    if (!$checkItem->fetchColumn()) {
                        $errors[] = 'One or more selected items are unavailable or inactive.';
                        break;
                    }
                }
            }

            if ($orderId > 0) {
                $existingStmt = $pdo->prepare(
                    'SELECT OrderID, HotelID, Status
                     FROM hotel_order
                     WHERE OrderID = ?'
                );
                $existingStmt->execute([$orderId]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $errors[] = 'The order you are trying to edit was not found.';
                } else {
                    // Delivery records refer to the order's detail rows.
                    // Do not delete/recreate those rows once deliveries exist.
                    $deliveryStmt = $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM delivery
                         WHERE OrderID = ?
                           AND Status <> "Cancelled"'
                    );
                    $deliveryStmt->execute([$orderId]);
                    $hasDeliveries = (int)$deliveryStmt->fetchColumn() > 0;

                    if ($hasDeliveries) {
                        $errors[] =
                            'This order has delivery records. Its hotel and item quantities cannot be edited here. ' .
                            'Use the Deliveries page to continue processing it.';
                    }

                    if ($existing['Status'] === 'Cancelled' && $status !== 'Cancelled') {
                        $errors[] = 'A cancelled order cannot be reopened from this page.';
                    }

                    if ($status === 'Delivered' || $status === 'Partially Delivered') {
                        $errors[] =
                            'Delivery status is calculated from completed deliveries. ' .
                            'Use the Deliveries page to update delivery progress.';
                    }
                }
            }

            if (!$errors) {
                $pdo->beginTransaction();

                if ($orderId > 0) {
                    $updateStmt = $pdo->prepare(
                        'UPDATE hotel_order
                         SET HotelID = ?,
                             OrderDate = ?,
                             EmailReceivedAt = ?,
                             EmailReference = ?,
                             EmailSubject = ?,
                             Status = ?,
                             Notes = ?
                         WHERE OrderID = ?'
                    );

                    $updateStmt->execute([
                        $hotelId,
                        $orderDate,
                        $emailReceivedAt !== '' ? str_replace('T', ' ', $emailReceivedAt) . ':00' : null,
                        $emailReference !== '' ? $emailReference : null,
                        $emailSubject !== '' ? $emailSubject : null,
                        $status,
                        $notes !== '' ? $notes : null,
                        $orderId,
                    ]);

                    $deleteStmt = $pdo->prepare(
                        'DELETE FROM hotel_order_detail WHERE OrderID = ?'
                    );
                    $deleteStmt->execute([$orderId]);
                } else {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO hotel_order
                         (HotelID, OrderDate, EmailReceivedAt, EmailReference,
                          EmailSubject, Status, Notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );

                    $insertStmt->execute([
                        $hotelId,
                        $orderDate,
                        $emailReceivedAt !== '' ? str_replace('T', ' ', $emailReceivedAt) . ':00' : null,
                        $emailReference !== '' ? $emailReference : null,
                        $emailSubject !== '' ? $emailSubject : null,
                        $status,
                        $notes !== '' ? $notes : null,
                    ]);

                    $orderId = (int)$pdo->lastInsertId();
                }

                $detailStmt = $pdo->prepare(
                    'INSERT INTO hotel_order_detail
                     (OrderID, ItemID, OrderedQuantity, ItemNotes)
                     VALUES (?, ?, ?, ?)'
                );

                foreach ($rows as $row) {
                    $detailStmt->execute([
                        $orderId,
                        $row['item_id'],
                        $row['quantity'],
                        $row['notes'] !== '' ? $row['notes'] : null,
                    ]);
                }

                $pdo->commit();

                orderFlash(
                    'success',
                    $action === 'save'
                        ? ($existing ?? null ? 'Order updated successfully.' : 'Hotel order recorded successfully.')
                        : 'Order saved successfully.'
                );
                orderRedirect();
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Hotel order save error: ' . $exception->getMessage());
            $errors[] = 'The order could not be saved. Check the database structure and try again.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm order (ready for procurement & delivery)
    |--------------------------------------------------------------------------
    */
    if ($action === 'confirm') {
        $orderId = (int)($_POST['order_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT OrderID, Status
                 FROM hotel_order
                 WHERE OrderID = ?
                 FOR UPDATE'
            );
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            if ($order['Status'] !== 'Received') {
                throw new RuntimeException('Only orders awaiting confirmation ("Received") can be confirmed.');
            }

            $confirmStmt = $pdo->prepare(
                'UPDATE hotel_order SET Status = "Confirmed" WHERE OrderID = ?'
            );
            $confirmStmt->execute([$orderId]);

            $pdo->commit();
            orderFlash('success', "Order #{$orderId} confirmed successfully. It is now ready for market purchases and deliveries.");
            orderRedirect($_POST['return_to'] ?? 'orders.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            orderFlash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The order could not be confirmed.');

            orderRedirect();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel order
    |--------------------------------------------------------------------------
    */
    if ($action === 'cancel') {
        $orderId = (int)($_POST['order_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT OrderID, Status
                 FROM hotel_order
                 WHERE OrderID = ?
                 FOR UPDATE'
            );
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new RuntimeException('Order not found.');
            }

            if ($order['Status'] === 'Cancelled') {
                throw new RuntimeException('This order is already cancelled.');
            }

            $deliveryStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM delivery
                 WHERE OrderID = ?
                   AND Status <> "Cancelled"'
            );
            $deliveryStmt->execute([$orderId]);

            if ((int)$deliveryStmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'This order has delivery records. Resolve those records before cancelling the order.'
                );
            }

            $cancelStmt = $pdo->prepare(
                'UPDATE hotel_order SET Status = "Cancelled" WHERE OrderID = ?'
            );
            $cancelStmt->execute([$orderId]);

            $pdo->commit();
            orderFlash('success', 'Order cancelled successfully.');
            orderRedirect();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            orderFlash('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The order could not be cancelled.');

            orderRedirect();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load hotels and items for the form
|--------------------------------------------------------------------------
*/
$hotels = $pdo->query(
    'SELECT HotelID, HotelName
     FROM hotel
     WHERE IsActive = 1
     ORDER BY HotelName'
)->fetchAll(PDO::FETCH_ASSOC);

$items = $pdo->query(
    'SELECT i.ItemID, i.ItemCode, i.ItemName, u.UnitSymbol
     FROM item i
     LEFT JOIN unit u ON u.UnitID = i.UnitID
     WHERE i.IsActive = 1
     ORDER BY i.ItemName'
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Edit order
|--------------------------------------------------------------------------
*/
$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare(
        'SELECT o.*, h.HotelName
         FROM hotel_order o
         INNER JOIN hotel h ON h.HotelID = o.HotelID
         WHERE o.OrderID = ?'
    );
    $stmt->execute([$editId]);
    $editOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editOrder) {
        $deliveryStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM delivery
             WHERE OrderID = ?
               AND Status <> "Cancelled"'
        );
        $deliveryStmt->execute([$editId]);

        if ((int)$deliveryStmt->fetchColumn() > 0) {
            orderFlash('error', 'This order has delivery records and cannot be edited here.');
            orderRedirect();
        }

        $detailStmt = $pdo->prepare(
            'SELECT OrderDetailID, ItemID, OrderedQuantity, ItemNotes
             FROM hotel_order_detail
             WHERE OrderID = ?
             ORDER BY OrderDetailID'
        );
        $detailStmt->execute([$editId]);
        $editDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        orderFlash('error', 'Order not found.');
        orderRedirect();
    }
}

/*
|--------------------------------------------------------------------------
| View order
|--------------------------------------------------------------------------
*/
$viewId = (int)($_GET['view'] ?? 0);

if ($viewId > 0) {
    $stmt = $pdo->prepare(
        'SELECT o.*, h.HotelName, h.ContactPerson, h.Email AS HotelEmail,
                h.Phone AS HotelPhone
         FROM hotel_order o
         INNER JOIN hotel h ON h.HotelID = o.HotelID
         WHERE o.OrderID = ?'
    );
    $stmt->execute([$viewId]);
    $viewOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($viewOrder) {
        $detailStmt = $pdo->prepare(
            'SELECT d.OrderDetailID, d.ItemID, d.OrderedQuantity, d.ItemNotes,
                    i.ItemCode, i.ItemName, u.UnitSymbol,
                    COALESCE((
                        SELECT SUM(dd.SuppliedQuantity)
                        FROM delivery_detail dd
                        INNER JOIN delivery del ON del.DeliveryID = dd.DeliveryID
                        WHERE dd.OrderDetailID = d.OrderDetailID
                          AND del.Status = "Completed"
                    ), 0) AS SuppliedQuantity
             FROM hotel_order_detail d
             INNER JOIN item i ON i.ItemID = d.ItemID
             LEFT JOIN unit u ON u.UnitID = i.UnitID
             WHERE d.OrderID = ?
             ORDER BY i.ItemName'
        );
        $detailStmt->execute([$viewId]);
        $viewDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/*
|--------------------------------------------------------------------------
| Filters and order list
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['search'] ?? ''));
$filterStatus = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        CAST(o.OrderID AS CHAR) LIKE ?
        OR h.HotelName LIKE ?
        OR o.EmailReference LIKE ?
        OR o.EmailSubject LIKE ?
    )';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses, true)) {
    $where[] = 'o.Status = ?';
    $params[] = $filterStatus;
}

if ($dateFrom !== '') {
    $where[] = 'o.OrderDate >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'o.OrderDate <= ?';
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$listSql = "
    SELECT o.OrderID, o.HotelID, o.OrderDate, o.EmailReceivedAt,
           o.EmailReference, o.EmailSubject, o.Status, o.CreatedAt,
           h.HotelName,
           (SELECT COUNT(*)
            FROM hotel_order_detail od
            WHERE od.OrderID = o.OrderID) AS ItemCount,
           (SELECT COALESCE(SUM(od.OrderedQuantity), 0)
            FROM hotel_order_detail od
            WHERE od.OrderID = o.OrderID) AS TotalQuantity
    FROM hotel_order o
    INNER JOIN hotel h ON h.HotelID = o.HotelID
    $whereSql
    ORDER BY o.OrderDate DESC, o.OrderID DESC
";

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Summary cards
|--------------------------------------------------------------------------
*/
$summary = $pdo->query(
    "SELECT
        COUNT(*) AS TotalOrders,
        SUM(Status = 'Received') AS ReceivedOrders,
        SUM(Status = 'Confirmed') AS ConfirmedOrders,
        SUM(Status = 'Partially Delivered') AS PartialOrders
     FROM hotel_order"
)->fetch(PDO::FETCH_ASSOC);

$flash = $_SESSION['order_flash'] ?? null;
unset($_SESSION['order_flash']);

$formOrder = $editOrder ?? [
    'OrderID' => 0,
    'HotelID' => '',
    'OrderDate' => date('Y-m-d'),
    'EmailReceivedAt' => '',
    'EmailReference' => '',
    'EmailSubject' => '',
    'Status' => 'Received',
    'Notes' => '',
];

$formDetails = $editDetails ?: [
    ['ItemID' => '', 'OrderedQuantity' => '', 'ItemNotes' => '']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save' && $errors) {
    $formOrder = [
        'OrderID' => (int)($_POST['order_id'] ?? 0),
        'HotelID' => (int)($_POST['hotel_id'] ?? 0),
        'OrderDate' => $_POST['order_date'] ?? date('Y-m-d'),
        'EmailReceivedAt' => $_POST['email_received_at'] ?? '',
        'EmailReference' => $_POST['email_reference'] ?? '',
        'EmailSubject' => $_POST['email_subject'] ?? '',
        'Status' => $_POST['status'] ?? 'Received',
        'Notes' => $_POST['notes'] ?? '',
    ];

    $formDetails = [];
    foreach (($_POST['item_id'] ?? []) as $index => $itemId) {
        $formDetails[] = [
            'ItemID' => $itemId,
            'OrderedQuantity' => $_POST['ordered_quantity'][$index] ?? '',
            'ItemNotes' => $_POST['item_notes'][$index] ?? '',
        ];
    }

    if (!$formDetails) {
        $formDetails = [['ItemID' => '', 'OrderedQuantity' => '', 'ItemNotes' => '']];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Orders | Hotel Supply Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-header {
            display:flex; justify-content:space-between; align-items:flex-start;
            gap:16px; margin-bottom:24px; flex-wrap:wrap;
        }
        .page-header h1 { margin:0 0 7px; font-size:27px; }
        .page-header p { margin:0; color:var(--muted,#718096); }
        .order-stats {
            display:grid; grid-template-columns:repeat(4,minmax(0,1fr));
            gap:16px; margin-bottom:24px;
        }
        .order-stat {
            background:#fff; border:1px solid #e7ebf0; border-radius:12px;
            padding:18px; box-shadow:0 2px 8px rgba(15,23,42,.025);
        }
        .order-stat span { color:#718096; font-size:13px; display:block; margin-bottom:8px; }
        .order-stat strong { font-size:25px; color:#172b4d; }
        .panel {
            background:#fff; border:1px solid #e7ebf0; border-radius:12px;
            padding:22px; margin-bottom:22px;
        }
        .panel-heading {
            display:flex; align-items:center; justify-content:space-between;
            flex-wrap:wrap; gap:12px; margin-bottom:20px;
        }
        .panel-heading h2 { font-size:18px; margin:0; }
        .form-grid {
            display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px;
        }
        .field { display:flex; flex-direction:column; gap:7px; min-width:0; }
        .field.full { grid-column:1/-1; }
        .field label { font-size:13px; font-weight:600; color:#344054; }
        .field input,.field select,.field textarea {
            width:100%; box-sizing:border-box; padding:11px 12px;
            border:1px solid #d7dde5; border-radius:7px; background:#fff;
            color:#263449; font:inherit; min-height:42px;
        }
        .field textarea { min-height:85px; resize:vertical; }
        .help-text { font-size:12px; color:#778399; }
        .order-items { width:100%; border-collapse:collapse; }
        .order-items th,.order-items td {
            padding:10px 8px; border-bottom:1px solid #edf0f4; text-align:left;
        }
        .order-items th { font-size:12px; color:#667085; background:#f8fafc; }
        .order-items input,.order-items select {
            width:100%; min-width:80px; box-sizing:border-box;
            padding:10px; border:1px solid #d7dde5; border-radius:6px;
        }
        .order-items .item-select { min-width:210px; }
        .order-items .note-input { min-width:150px; }
        .btn {
            display:inline-flex; align-items:center; justify-content:center; gap:6px;
            border:0; border-radius:7px; padding:10px 14px; cursor:pointer;
            font:inherit; font-size:13px; font-weight:600; text-decoration:none;
        }
        .btn-primary { background:#3157c8; color:#fff; }
        .btn-secondary { background:#eef2f7; color:#344054; }
        .btn-danger { background:#fff0f0; color:#b42318; }
        .btn-small { padding:7px 10px; font-size:12px; }
        .form-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; flex-wrap:wrap; }
        .table-wrap { overflow-x:auto; }
        .data-table { width:100%; border-collapse:collapse; min-width:850px; }
        .data-table th,.data-table td {
            padding:13px 12px; border-bottom:1px solid #edf0f4; text-align:left;
            vertical-align:middle;
        }
        .data-table th { background:#f8fafc; font-size:12px; color:#667085; white-space:nowrap; }
        .data-table td { font-size:13px; }
        .muted { color:#778399; }
        .status-badge {
            display:inline-block; border-radius:20px; padding:5px 9px;
            font-size:11px; font-weight:700; white-space:nowrap;
        }
        .status-received { background:#fff4d6; color:#8a5b00; }
        .status-confirmed { background:#e6efff; color:#244db0; }
        .status-partial { background:#f0e8ff; color:#6941c6; }
        .status-delivered { background:#dcfae6; color:#067647; }
        .status-cancelled { background:#fee4e2; color:#b42318; }
        .alert { padding:13px 15px; border-radius:8px; margin-bottom:18px; font-size:13px; }
        .alert-success { color:#067647; background:#ecfdf3; border:1px solid #abefc6; }
        .alert-error { color:#b42318; background:#fff1f0; border:1px solid #fecdca; }
        .filter-grid {
            display:grid; grid-template-columns:2fr 1.2fr 1fr 1fr auto;
            gap:12px; align-items:end;
        }
        .filter-grid .field input,.filter-grid .field select {
            min-width:0; width:100%; box-sizing:border-box; padding:10px;
            border:1px solid #d7dde5; border-radius:7px; min-height:41px;
        }
        .action-buttons { display:flex; gap:6px; flex-wrap:wrap; }
        .detail-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        .detail-box { background:#f8fafc; border-radius:8px; padding:13px; }
        .detail-box span { display:block; font-size:12px; color:#667085; margin-bottom:6px; }
        .detail-box strong { overflow-wrap:anywhere; }
        .empty-state { text-align:center; padding:32px 12px; color:#778399; }
        .inline-form { display:inline; }
        @media(max-width:1000px) {
            .order-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .detail-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media(max-width:650px) {
            .order-stats { grid-template-columns:1fr 1fr; gap:10px; }
            .order-stat { padding:13px; }
            .order-stat strong { font-size:21px; }
            .panel { padding:15px; }
            .form-grid,.filter-grid,.detail-grid { grid-template-columns:1fr; }
            .page-header h1 { font-size:23px; }
            .form-actions .btn { flex:1; }
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
        <a class="nav-link active" href="orders.php">📋 &nbsp; Hotel Orders</a>
        <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
        <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
        <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
        <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
    </aside>

    <main class="main">
        <div class="page-header">
            <div>
                <h1>Hotel Orders</h1>
                <p>Record and manage orders received from hotels by email.</p>
            </div>
            <a href="orders.php#order-form" class="btn btn-primary">＋ Record New Order</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <strong>Please correct the following:</strong>
                <ul style="margin:8px 0 0;padding-left:20px">
                    <?php foreach (array_unique($errors) as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="order-stats">
            <div class="order-stat">
                <span>Total orders</span>
                <strong><?= number_format((int)($summary['TotalOrders'] ?? 0)) ?></strong>
            </div>
            <div class="order-stat">
                <span>Awaiting confirmation</span>
                <strong><?= number_format((int)($summary['ReceivedOrders'] ?? 0)) ?></strong>
            </div>
            <div class="order-stat">
                <span>Confirmed</span>
                <strong><?= number_format((int)($summary['ConfirmedOrders'] ?? 0)) ?></strong>
            </div>
            <div class="order-stat">
                <span>Partially delivered</span>
                <strong><?= number_format((int)($summary['PartialOrders'] ?? 0)) ?></strong>
            </div>
        </section>

        <?php if ($viewOrder): ?>
            <section class="panel">
                <div class="panel-heading">
                    <div>
                        <h2>Order #<?= (int)$viewOrder['OrderID'] ?> — <?= e($viewOrder['HotelName']) ?></h2>
                        <p class="muted" style="margin:6px 0 0">Order details and delivery progress</p>
                    </div>
                    <div class="action-buttons">
                        <a class="btn btn-secondary" href="orders.php">← Back to Orders</a>
                        <?php if ($viewOrder['Status'] === 'Received'): ?>
                            <form class="inline-form" method="POST" action="orders.php" onsubmit="return confirm('Confirm Order #<?= (int)$viewOrder['OrderID'] ?>? This marks it ready for procurement and delivery.');" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="confirm">
                                <input type="hidden" name="order_id" value="<?= (int)$viewOrder['OrderID'] ?>">
                                <input type="hidden" name="return_to" value="orders.php?view=<?= (int)$viewOrder['OrderID'] ?>">
                                <button type="submit" class="btn btn-primary">✓ Confirm Order</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($viewOrder['Status'], ['Confirmed', 'Partially Delivered'], true)): ?>
                            <a class="btn btn-primary" href="deliveries.php?order_id=<?= (int)$viewOrder['OrderID'] ?>">🚚 Create Delivery</a>
                        <?php endif; ?>
                        <?php if (!in_array($viewOrder['Status'], ['Cancelled', 'Delivered', 'Partially Delivered'], true)): ?>
                            <a class="btn btn-secondary" href="orders.php?edit=<?= (int)$viewOrder['OrderID'] ?>">Edit Order</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-grid">
                    <div class="detail-box"><span>Hotel</span><strong><?= e($viewOrder['HotelName']) ?></strong></div>
                    <div class="detail-box"><span>Order date</span><strong><?= e($viewOrder['OrderDate']) ?></strong></div>
                    <div class="detail-box"><span>Status</span><strong><?= e(orderStatusLabel($viewOrder['Status'])) ?></strong></div>
                    <div class="detail-box"><span>Email received</span><strong><?= e($viewOrder['EmailReceivedAt'] ?: 'Not recorded') ?></strong></div>
                    <div class="detail-box"><span>Email reference</span><strong><?= e($viewOrder['EmailReference'] ?: '—') ?></strong></div>
                    <div class="detail-box"><span>Email subject</span><strong><?= e($viewOrder['EmailSubject'] ?: '—') ?></strong></div>
                </div>

                <?php if (!empty($viewOrder['Notes'])): ?>
                    <p><strong>Notes:</strong> <?= nl2br(e($viewOrder['Notes'])) ?></p>
                <?php endif; ?>

                <h3 style="margin:24px 0 12px">Ordered Items</h3>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Item Code</th><th>Item</th><th>Ordered</th>
                                <th>Supplied</th><th>Remaining</th><th>Item Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($viewDetails as $detail): ?>
                            <?php $remaining = max(0, (float)$detail['OrderedQuantity'] - (float)$detail['SuppliedQuantity']); ?>
                            <tr>
                                <td><?= e($detail['ItemCode']) ?></td>
                                <td><?= e($detail['ItemName']) ?></td>
                                <td><?= e(number_format((float)$detail['OrderedQuantity'], 3)) ?> <?= e($detail['UnitSymbol']) ?></td>
                                <td><?= e(number_format((float)$detail['SuppliedQuantity'], 3)) ?> <?= e($detail['UnitSymbol']) ?></td>
                                <td><?= e(number_format($remaining, 3)) ?> <?= e($detail['UnitSymbol']) ?></td>
                                <td><?= e($detail['ItemNotes'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$viewDetails): ?>
                            <tr><td colspan="6" class="empty-state">No order items found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="help-text" style="margin-top:12px">
                    Supplied quantities include only completed deliveries. Use the Deliveries page to record new deliveries.
                </p>
            </section>
        <?php endif; ?>

        <section class="panel" id="order-form">
            <div class="panel-heading">
                <div>
                    <h2><?= !empty($formOrder['OrderID']) ? 'Edit Hotel Order #' . (int)$formOrder['OrderID'] : 'Record a Hotel Order' ?></h2>
                    <p class="muted" style="margin:6px 0 0">Enter the order details from the hotel's email.</p>
                </div>
                <?php if (!empty($formOrder['OrderID'])): ?>
                    <a class="btn btn-secondary" href="orders.php">Cancel Editing</a>
                <?php endif; ?>
            </div>

            <?php if (!$hotels): ?>
                <div class="alert alert-error">No active hotels found. Add a hotel before recording an order.</div>
            <?php elseif (!$items): ?>
                <div class="alert alert-error">No active items found. Add items before recording an order.</div>
            <?php else: ?>
                <form method="POST" action="orders.php" id="hotelOrderForm">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="order_id" value="<?= (int)$formOrder['OrderID'] ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="hotel_id">Hotel *</label>
                            <select name="hotel_id" id="hotel_id" required>
                                <option value="">Select hotel</option>
                                <?php foreach ($hotels as $hotel): ?>
                                    <option value="<?= (int)$hotel['HotelID'] ?>"
                                        <?= (string)$formOrder['HotelID'] === (string)$hotel['HotelID'] ? 'selected' : '' ?>>
                                        <?= e($hotel['HotelName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="order_date">Order Date *</label>
                            <input type="date" name="order_date" id="order_date"
                                   value="<?= e(substr((string)$formOrder['OrderDate'], 0, 10)) ?>" required>
                        </div>

                        <div class="field">
                            <label for="email_received_at">Email Received Date & Time</label>
                            <input type="datetime-local" name="email_received_at" id="email_received_at"
                                   value="<?php
                                   $emailValue = (string)($formOrder['EmailReceivedAt'] ?? '');
                                   echo e($emailValue ? date('Y-m-d\TH:i', strtotime($emailValue)) : '');
                                   ?>">
                        </div>

                        <div class="field">
                            <label for="email_reference">Email Reference</label>
                            <input type="text" name="email_reference" id="email_reference" maxlength="100"
                                   placeholder="e.g. Gmail message ID or reference"
                                   value="<?= e($formOrder['EmailReference']) ?>">
                            <span class="help-text">Use a reference that helps you locate the original email.</span>
                        </div>

                        <div class="field full">
                            <label for="email_subject">Email Subject</label>
                            <input type="text" name="email_subject" id="email_subject" maxlength="255"
                                   placeholder="Paste the subject line from the hotel's email"
                                   value="<?= e($formOrder['EmailSubject']) ?>">
                        </div>

                        <div class="field">
                            <label for="status">Order Status</label>
                            <select name="status" id="status">
                                <?php foreach ($allowedStatuses as $statusOption): ?>
                                    <?php
                                    // Delivery progress is managed by the Deliveries page.
                                    if (in_array($statusOption, ['Partially Delivered', 'Delivered'], true)) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?= e($statusOption) ?>"
                                        <?= $formOrder['Status'] === $statusOption ? 'selected' : '' ?>>
                                        <?= e($statusOption) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="notes">Order Notes</label>
                            <textarea name="notes" id="notes" placeholder="Special instructions or notes"><?= e($formOrder['Notes']) ?></textarea>
                        </div>
                    </div>

                    <div class="panel-heading" style="margin-top:28px;margin-bottom:12px">
                        <div>
                            <h2>Order Items</h2>
                            <p class="muted" style="margin:6px 0 0">Enter the item and quantity requested by the hotel.</p>
                        </div>
                        <button type="button" class="btn btn-secondary" id="addItemRow">＋ Add Item</button>
                    </div>

                    <div class="table-wrap">
                        <table class="order-items">
                            <thead>
                                <tr>
                                    <th style="width:38%">Item *</th>
                                    <th style="width:20%">Quantity *</th>
                                    <th style="width:30%">Item Notes</th>
                                    <th style="width:12%">Action</th>
                                </tr>
                            </thead>
                            <tbody id="orderItemsBody">
                            <?php foreach ($formDetails as $detail): ?>
                                <tr>
                                    <td>
                                        <select name="item_id[]" class="item-select" required>
                                            <option value="">Select item</option>
                                            <?php foreach ($items as $item): ?>
                                                <option value="<?= (int)$item['ItemID'] ?>"
                                                    <?= (string)$detail['ItemID'] === (string)$item['ItemID'] ? 'selected' : '' ?>>
                                                    <?= e($item['ItemCode'] . ' — ' . $item['ItemName'] . ' (' . $item['UnitSymbol'] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="ordered_quantity[]" min="0.001" step="0.001"
                                               placeholder="0.000" value="<?= e($detail['OrderedQuantity']) ?>" required>
                                    </td>
                                    <td>
                                        <input type="text" name="item_notes[]" class="note-input"
                                               placeholder="Optional note" value="<?= e($detail['ItemNotes']) ?>">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-small remove-item">Remove</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="help-text" style="margin-top:10px">
                        Quantities use the unit configured for each item. Prices are selected later according to the delivery date.
                    </p>

                    <div class="form-actions">
                        <a href="orders.php" class="btn btn-secondary">Reset</a>
                        <button type="submit" class="btn btn-primary">
                            <?= !empty($formOrder['OrderID']) ? 'Save Changes' : 'Save Hotel Order' ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Order History</h2>
                    <p class="muted" style="margin:6px 0 0">Search orders by hotel, order number, or email reference.</p>
                </div>
            </div>

            <form method="GET" action="orders.php" style="margin-bottom:20px">
                <div class="filter-grid">
                    <div class="field">
                        <label for="search">Search</label>
                        <input type="search" id="search" name="search"
                               placeholder="Order number, hotel, email subject..."
                               value="<?= e($search) ?>">
                    </div>
                    <div class="field">
                        <label for="status_filter">Status</label>
                        <select id="status_filter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                <option value="<?= e($statusOption) ?>" <?= $filterStatus === $statusOption ? 'selected' : '' ?>>
                                    <?= e($statusOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="date_from">Order date from</label>
                        <input type="date" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="field">
                        <label for="date_to">Order date to</label>
                        <input type="date" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="action-buttons">
                        <button class="btn btn-primary" type="submit">Search</button>
                        <a class="btn btn-secondary" href="orders.php">Clear</a>
                    </div>
                </div>
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Order Date</th>
                            <th>Hotel</th>
                            <th>Email Subject / Reference</th>
                            <th>Items</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $statusClass = match ($order['Status']) {
                            'Received' => 'status-received',
                            'Confirmed' => 'status-confirmed',
                            'Partially Delivered' => 'status-partial',
                            'Delivered' => 'status-delivered',
                            'Cancelled' => 'status-cancelled',
                            default => '',
                        };
                        ?>
                        <tr>
                            <td><strong>#<?= (int)$order['OrderID'] ?></strong></td>
                            <td><?= e($order['OrderDate']) ?></td>
                            <td>
                                <strong><?= e($order['HotelName']) ?></strong>
                                <?php if ($order['EmailReceivedAt']): ?>
                                    <div class="muted" style="font-size:11px;margin-top:4px">
                                        Email: <?= e($order['EmailReceivedAt']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= e($order['EmailSubject'] ?: 'No subject saved') ?></div>
                                <div class="muted" style="font-size:11px;margin-top:4px">
                                    <?= e($order['EmailReference'] ?: 'No reference') ?>
                                </div>
                            </td>
                            <td>
                                <?= (int)$order['ItemCount'] ?> item(s)
                                <div class="muted" style="font-size:11px;margin-top:4px">
                                    Total qty: <?= e(number_format((float)$order['TotalQuantity'], 3)) ?>
                                </div>
                            </td>
                            <td><span class="status-badge <?= e($statusClass) ?>"><?= e(orderStatusLabel($order['Status'])) ?></span></td>
                            <td>
                                <div class="action-buttons">
                                    <a class="btn btn-secondary btn-small" href="orders.php?view=<?= (int)$order['OrderID'] ?>">View</a>
                                    <?php if ($order['Status'] === 'Received'): ?>
                                        <form class="inline-form" method="POST" action="orders.php"
                                              onsubmit="return confirm('Confirm Order #<?= (int)$order['OrderID'] ?>? This marks it ready for procurement and delivery.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="confirm">
                                            <input type="hidden" name="order_id" value="<?= (int)$order['OrderID'] ?>">
                                            <button type="submit" class="btn btn-primary btn-small">✓ Confirm</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($order['Status'], ['Confirmed', 'Partially Delivered'], true)): ?>
                                        <a class="btn btn-primary btn-small" href="deliveries.php?order_id=<?= (int)$order['OrderID'] ?>">🚚 Deliver</a>
                                    <?php endif; ?>
                                    <?php
                                    $deliveryCheck = $pdo->prepare(
                                        'SELECT COUNT(*) FROM delivery WHERE OrderID = ? AND Status <> "Cancelled"'
                                    );
                                    $deliveryCheck->execute([(int)$order['OrderID']]);
                                    $canEdit = (int)$deliveryCheck->fetchColumn() === 0
                                        && !in_array($order['Status'], ['Delivered', 'Partially Delivered', 'Cancelled'], true);
                                    ?>
                                    <?php if ($canEdit): ?>
                                        <a class="btn btn-secondary btn-small" href="orders.php?edit=<?= (int)$order['OrderID'] ?>">Edit</a>
                                        <form class="inline-form" method="POST" action="orders.php"
                                              onsubmit="return confirm('Cancel this order? This cannot be undone here.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="order_id" value="<?= (int)$order['OrderID'] ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orders): ?>
                        <tr><td colspan="7" class="empty-state">No hotel orders found. Record your first order above.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="help-text" style="margin-top:12px"><?= count($orders) ?> order(s) shown.</p>
        </section>
    </main>
</div>

<script>
(function () {
    const body = document.getElementById('orderItemsBody');
    const addButton = document.getElementById('addItemRow');

    if (!body || !addButton) return;

    function addRow() {
        const firstRow = body.querySelector('tr');
        if (!firstRow) return;

        const newRow = firstRow.cloneNode(true);
        newRow.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
        newRow.querySelectorAll('input').forEach(input => input.value = '');
        body.appendChild(newRow);
    }

    addButton.addEventListener('click', addRow);

    body.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-item');
        if (!button) return;

        const rows = body.querySelectorAll('tr');

        if (rows.length === 1) {
            rows[0].querySelectorAll('select').forEach(select => select.selectedIndex = 0);
            rows[0].querySelectorAll('input').forEach(input => input.value = '');
            return;
        }

        button.closest('tr').remove();
    });
})();
</script>
</body>
</html>
