
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function deliveryFlash(string $type, string $message): void
{
    $_SESSION['delivery_flash'] = ['type' => $type, 'message' => $message];
}

function deliveryRedirect(string $url = 'deliveries.php'): never
{
    header('Location: ' . $url);
    exit;
}

function deliveryStatusClass(string $status): string
{
    return match ($status) {
        'Draft' => 'status-draft',
        'Completed' => 'status-completed',
        'Cancelled' => 'status-cancelled',
        default => '',
    };
}

/**
 * Find the active selling price for an item on the delivery date.
 * The database must not contain overlapping effective price periods
 * for the same item.
 */
function getDeliveryPrice(PDO $pdo, int $itemId, string $deliveryDate): ?float
{
    $stmt = $pdo->prepare(
        "SELECT pld.UnitPrice
         FROM price_list_detail pld
         INNER JOIN price_list pl ON pl.PriceListID = pld.PriceListID
         WHERE pld.ItemID = ?
           AND pl.Status = 'Active'
           AND pl.EffectiveFrom <= ?
           AND (pl.EffectiveTo IS NULL OR pl.EffectiveTo >= ?)
         ORDER BY pl.EffectiveFrom DESC, pl.PriceListID DESC
         LIMIT 1"
    );

    $stmt->execute([$itemId, $deliveryDate, $deliveryDate]);
    $price = $stmt->fetchColumn();

    return $price === false ? null : (float)$price;
}

/**
 * Update the order status using quantities from completed deliveries.
 * Cancelled deliveries do not count.
 */
function syncHotelOrderStatus(PDO $pdo, int $orderId): void
{
    $orderStmt = $pdo->prepare(
        'SELECT Status FROM hotel_order WHERE OrderID = ? FOR UPDATE'
    );
    $orderStmt->execute([$orderId]);
    $currentStatus = $orderStmt->fetchColumn();

    if ($currentStatus === false || $currentStatus === 'Cancelled') {
        return;
    }

    $quantityStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(od.OrderedQuantity), 0) AS OrderedQty,
            COALESCE(SUM(
                COALESCE((
                    SELECT SUM(dd.SuppliedQuantity)
                    FROM delivery_detail dd
                    INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                    WHERE dd.OrderDetailID = od.OrderDetailID
                      AND d.Status = 'Completed'
                ), 0)
            ), 0) AS SuppliedQty
         FROM hotel_order_detail od
         WHERE od.OrderID = ?"
    );

    $quantityStmt->execute([$orderId]);
    $totals = $quantityStmt->fetch(PDO::FETCH_ASSOC);

    $ordered = (float)$totals['OrderedQty'];
    $supplied = (float)$totals['SuppliedQty'];

    if ($ordered > 0 && $supplied >= $ordered - 0.000001) {
        $newStatus = 'Delivered';
    } elseif ($supplied > 0) {
        $newStatus = 'Partially Delivered';
    } else {
        // Do not automatically reopen an order that was manually confirmed.
        $newStatus = $currentStatus === 'Confirmed' ? 'Confirmed' : 'Received';
    }

    $updateStmt = $pdo->prepare(
        'UPDATE hotel_order SET Status = ? WHERE OrderID = ?'
    );
    $updateStmt->execute([$newStatus, $orderId]);
}

/**
 * Create an invoice for a completed delivery.
 * The unique DeliveryID constraint prevents duplicate invoices.
 */
function createDeliveryInvoice(PDO $pdo, int $deliveryId, string $deliveryDate): void
{
    $existingStmt = $pdo->prepare(
        'SELECT InvoiceID FROM invoice WHERE DeliveryID = ?'
    );
    $existingStmt->execute([$deliveryId]);

    if ($existingStmt->fetchColumn()) {
        return;
    }

    $totalStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(LineTotal), 0)
         FROM delivery_detail
         WHERE DeliveryID = ?'
    );
    $totalStmt->execute([$deliveryId]);
    $total = round((float)$totalStmt->fetchColumn(), 2);

    $dueDate = date('Y-m-d', strtotime($deliveryDate . ' +30 days'));

    $invoiceNo = 'INV-' . date('Ymd', strtotime($deliveryDate))
        . '-' . str_pad((string)$deliveryId, 5, '0', STR_PAD_LEFT);

    $insertStmt = $pdo->prepare(
        "INSERT INTO invoice
         (DeliveryID, InvoiceNo, InvoiceDate, DueDate, TotalAmount, Status, Notes)
         VALUES (?, ?, ?, ?, ?, 'Issued', ?)"
    );

    $insertStmt->execute([
        $deliveryId,
        $invoiceNo,
        $deliveryDate,
        $dueDate,
        $total,
        'Automatically created from completed delivery.',
    ]);
}

$errors = [];
$flash = null;
$editDelivery = null;
$editDetails = [];
$selectedOrder = null;
$orderLines = [];

$allowedDeliveryStatuses = ['Draft', 'Completed'];

/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        deliveryFlash('error', 'Your session token expired. Please try again.');
        deliveryRedirect();
    }

    $action = (string)($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Save a delivery draft or complete a delivery
    |--------------------------------------------------------------------------
    */
    if ($action === 'save') {
        $deliveryId = (int)($_POST['delivery_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);
        $deliveryDate = trim((string)($_POST['delivery_date'] ?? ''));
        $deliveryNo = trim((string)($_POST['delivery_no'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'Draft'));

        $postedDetailIds = $_POST['order_detail_id'] ?? [];
        $postedQuantities = $_POST['supplied_quantity'] ?? [];
        $postedNotes = $_POST['item_notes'] ?? [];

        if (!in_array($status, $allowedDeliveryStatuses, true)) {
            $errors[] = 'Select Draft or Completed.';
        }

        if ($orderId <= 0) {
            $errors[] = 'Select a hotel order.';
        }

        $dateObject = DateTime::createFromFormat('!Y-m-d', $deliveryDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $deliveryDate) {
            $errors[] = 'Enter a valid delivery date.';
        }

        if (strlen($deliveryNo) > 100) {
            $errors[] = 'Delivery number must not exceed 100 characters.';
        }

        if (!is_array($postedDetailIds) ||
            !is_array($postedQuantities) ||
            !is_array($postedNotes)) {
            $errors[] = 'Invalid delivery item data.';
            $postedDetailIds = [];
            $postedQuantities = [];
            $postedNotes = [];
        }

        $submittedRows = [];
        $seenDetailIds = [];

        foreach ($postedDetailIds as $index => $rawDetailId) {
            $rawDetailId = trim((string)$rawDetailId);
            $rawQuantity = trim((string)($postedQuantities[$index] ?? ''));
            $rawNote = trim((string)($postedNotes[$index] ?? ''));

            if ($rawDetailId === '' && $rawQuantity === '' && $rawNote === '') {
                continue;
            }

            if (!ctype_digit($rawDetailId) || (int)$rawDetailId <= 0) {
                $errors[] = 'Invalid order item selected.';
                continue;
            }

            $detailId = (int)$rawDetailId;

            if (isset($seenDetailIds[$detailId])) {
                $errors[] = 'An order item appears more than once in the delivery.';
                continue;
            }
            $seenDetailIds[$detailId] = true;

            if ($rawQuantity === '' ||
                !is_numeric($rawQuantity) ||
                !is_finite((float)$rawQuantity) ||
                (float)$rawQuantity <= 0 ||
                (float)$rawQuantity > 999999999) {
                $errors[] = 'Each included delivery item must have a valid quantity greater than zero.';
                continue;
            }

            $submittedRows[] = [
                'order_detail_id' => $detailId,
                'quantity' => round((float)$rawQuantity, 3),
                'notes' => $rawNote,
            ];
        }

        if (!$submittedRows) {
            $errors[] = 'Enter at least one quantity to deliver.';
        }

        try {
            if (!$errors) {
                $pdo->beginTransaction();

                // Lock the order while validating and saving its delivery.
                $orderStmt = $pdo->prepare(
                    'SELECT OrderID, HotelID, Status
                     FROM hotel_order
                     WHERE OrderID = ?
                     FOR UPDATE'
                );
                $orderStmt->execute([$orderId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

                if (!$order) {
                    throw new RuntimeException('The selected hotel order was not found.');
                }

                if ($order['Status'] === 'Cancelled') {
                    throw new RuntimeException('A cancelled hotel order cannot receive a delivery.');
                }

                $existingDelivery = null;

                if ($deliveryId > 0) {
                    $deliveryStmt = $pdo->prepare(
                        'SELECT DeliveryID, OrderID, Status
                         FROM delivery
                         WHERE DeliveryID = ?
                         FOR UPDATE'
                    );
                    $deliveryStmt->execute([$deliveryId]);
                    $existingDelivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$existingDelivery) {
                        throw new RuntimeException('Delivery not found.');
                    }

                    if ((int)$existingDelivery['OrderID'] !== $orderId) {
                        throw new RuntimeException('The delivery does not belong to the selected order.');
                    }

                    if ($existingDelivery['Status'] !== 'Draft') {
                        throw new RuntimeException(
                            'Only draft deliveries can be edited. Completed or cancelled deliveries are locked.'
                        );
                    }
                }

                // Fetch all detail rows belonging to the selected order.
                $detailStmt = $pdo->prepare(
                    'SELECT OrderDetailID, ItemID, OrderedQuantity
                     FROM hotel_order_detail
                     WHERE OrderID = ?'
                );
                $detailStmt->execute([$orderId]);

                $validDetails = [];
                foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
                    $validDetails[(int)$detail['OrderDetailID']] = $detail;
                }

                if (!$validDetails) {
                    throw new RuntimeException('This hotel order has no order items.');
                }

                // Sum quantities from completed deliveries, excluding the draft being edited.
                $completedStmt = $pdo->prepare(
                    "SELECT dd.OrderDetailID, SUM(dd.SuppliedQuantity) AS SuppliedQty
                     FROM delivery_detail dd
                     INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                     WHERE d.Status = 'Completed'
                       AND d.OrderID = ?
                     GROUP BY dd.OrderDetailID"
                );
                $completedStmt->execute([$orderId]);

                $completedQty = [];
                foreach ($completedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $completedQty[(int)$row['OrderDetailID']] = (float)$row['SuppliedQty'];
                }

                // Validate submitted detail rows, remaining quantities and date-specific prices.
                $preparedRows = [];

                foreach ($submittedRows as $row) {
                    $detailId = $row['order_detail_id'];

                    if (!isset($validDetails[$detailId])) {
                        throw new RuntimeException('One of the selected items does not belong to this hotel order.');
                    }

                    $detail = $validDetails[$detailId];
                    $orderedQty = (float)$detail['OrderedQuantity'];
                    $alreadySupplied = $completedQty[$detailId] ?? 0.0;
                    $remainingQty = $orderedQty - $alreadySupplied;

                    if ($row['quantity'] > $remainingQty + 0.000001) {
                        throw new RuntimeException(
                            'A supplied quantity exceeds the remaining ordered quantity. Please refresh and check the order.'
                        );
                    }

                    $price = getDeliveryPrice($pdo, (int)$detail['ItemID'], $deliveryDate);

                    if ($price === null) {
                        throw new RuntimeException(
                            'No active selling price was found for one or more items on ' .
                            $deliveryDate . '. Check Price Lists first.'
                        );
                    }

                    $preparedRows[] = [
                        'order_detail_id' => $detailId,
                        'quantity' => $row['quantity'],
                        'unit_price' => $price,
                        'line_total' => round($row['quantity'] * $price, 2),
                        'notes' => $row['notes'],
                    ];
                }

                if ($deliveryId > 0) {
                    $updateStmt = $pdo->prepare(
                        'UPDATE delivery
                         SET DeliveryDate = ?, DeliveryNo = ?, Notes = ?
                         WHERE DeliveryID = ?'
                    );

                    $updateStmt->execute([
                        $deliveryDate,
                        $deliveryNo !== '' ? $deliveryNo : null,
                        $notes !== '' ? $notes : null,
                        $deliveryId,
                    ]);

                    $deleteStmt = $pdo->prepare(
                        'DELETE FROM delivery_detail WHERE DeliveryID = ?'
                    );
                    $deleteStmt->execute([$deliveryId]);
                } else {
                    $insertStmt = $pdo->prepare(
                        "INSERT INTO delivery
                         (OrderID, DeliveryDate, DeliveryNo, Status, Notes)
                         VALUES (?, ?, ?, 'Draft', ?)"
                    );

                    $insertStmt->execute([
                        $orderId,
                        $deliveryDate,
                        $deliveryNo !== '' ? $deliveryNo : null,
                        $notes !== '' ? $notes : null,
                    ]);

                    $deliveryId = (int)$pdo->lastInsertId();
                }

                $insertDetail = $pdo->prepare(
                    'INSERT INTO delivery_detail
                     (DeliveryID, OrderDetailID, SuppliedQuantity, UnitPrice, LineTotal)
                     VALUES (?, ?, ?, ?, ?)'
                );

                foreach ($preparedRows as $row) {
                    $insertDetail->execute([
                        $deliveryId,
                        $row['order_detail_id'],
                        $row['quantity'],
                        $row['unit_price'],
                        $row['line_total'],
                    ]);
                }

                if ($status === 'Completed') {
                    /*
                     * Re-check the order's total supplied quantities including
                     * the delivery being completed.
                     */
                    $checkStmt = $pdo->prepare(
                        "SELECT dd.OrderDetailID, SUM(dd.SuppliedQuantity) AS SuppliedQty
                         FROM delivery_detail dd
                         INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                         WHERE d.OrderID = ?
                           AND d.Status = 'Completed'
                           AND d.DeliveryID <> ?
                         GROUP BY dd.OrderDetailID"
                    );
                    $checkStmt->execute([$orderId, $deliveryId]);

                    $otherCompleted = [];
                    foreach ($checkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $otherCompleted[(int)$row['OrderDetailID']] = (float)$row['SuppliedQty'];
                    }

                    foreach ($preparedRows as $row) {
                        $detailId = $row['order_detail_id'];
                        $orderedQty = (float)$validDetails[$detailId]['OrderedQuantity'];
                        $previousQty = $otherCompleted[$detailId] ?? 0.0;

                        if ($previousQty + $row['quantity'] > $orderedQty + 0.000001) {
                            throw new RuntimeException(
                                'Completing this delivery would exceed the ordered quantity. Review the order and other deliveries.'
                            );
                        }
                    }

                    $completeStmt = $pdo->prepare(
                        "UPDATE delivery SET Status = 'Completed' WHERE DeliveryID = ?"
                    );
                    $completeStmt->execute([$deliveryId]);

                    // Invoice creation and delivery completion happen together.
                    createDeliveryInvoice($pdo, $deliveryId, $deliveryDate);
                }

                syncHotelOrderStatus($pdo, $orderId);

                $pdo->commit();

                deliveryFlash(
                    'success',
                    $status === 'Completed'
                        ? 'Delivery completed successfully. An invoice was created if one did not already exist.'
                        : 'Delivery draft saved successfully.'
                );

                deliveryRedirect();
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('Delivery save error: ' . $exception->getMessage());
                $errors[] = 'The delivery could not be saved. Check the database schema and try again.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel a draft delivery
    |--------------------------------------------------------------------------
    */
    if ($action === 'cancel') {
        $deliveryId = (int)($_POST['delivery_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT DeliveryID, OrderID, Status
                 FROM delivery
                 WHERE DeliveryID = ?
                 FOR UPDATE'
            );
            $stmt->execute([$deliveryId]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$delivery) {
                throw new RuntimeException('Delivery not found.');
            }

            if ($delivery['Status'] !== 'Draft') {
                throw new RuntimeException(
                    'Only draft deliveries can be cancelled from this page.'
                );
            }

            $cancelStmt = $pdo->prepare(
                "UPDATE delivery SET Status = 'Cancelled' WHERE DeliveryID = ?"
            );
            $cancelStmt->execute([$deliveryId]);

            syncHotelOrderStatus($pdo, (int)$delivery['OrderID']);

            $pdo->commit();

            deliveryFlash('success', 'Draft delivery cancelled.');
            deliveryRedirect();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            deliveryFlash(
                'error',
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The delivery could not be cancelled.'
            );

            deliveryRedirect();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load hotels, items and eligible orders
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

$eligibleOrders = $pdo->query(
    "SELECT o.OrderID, o.OrderDate, o.Status, h.HotelName
     FROM hotel_order o
     INNER JOIN hotel h ON h.HotelID = o.HotelID
     WHERE o.Status <> 'Cancelled'
       AND EXISTS (
           SELECT 1
           FROM hotel_order_detail od
           WHERE od.OrderID = o.OrderID
       )
     ORDER BY o.OrderDate DESC, o.OrderID DESC"
)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Edit an existing draft
|--------------------------------------------------------------------------
*/
$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare(
        "SELECT d.*, o.HotelID, h.HotelName
         FROM delivery d
         INNER JOIN hotel_order o ON o.OrderID = d.OrderID
         INNER JOIN hotel h ON h.HotelID = o.HotelID
         WHERE d.DeliveryID = ?"
    );
    $stmt->execute([$editId]);
    $editDelivery = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editDelivery || $editDelivery['Status'] !== 'Draft') {
        deliveryFlash('error', 'Only existing draft deliveries can be edited.');
        deliveryRedirect();
    }

    $detailStmt = $pdo->prepare(
        'SELECT OrderDetailID, SuppliedQuantity, UnitPrice
         FROM delivery_detail
         WHERE DeliveryID = ?'
    );
    $detailStmt->execute([$editId]);
    $editDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Build form data
|--------------------------------------------------------------------------
*/
$selectedOrderId = (int)($_GET['order_id'] ?? 0);

if ($editDelivery) {
    $selectedOrderId = (int)$editDelivery['OrderID'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save'
    && $errors) {
    $selectedOrderId = (int)($_POST['order_id'] ?? 0);
}

if ($selectedOrderId > 0) {
    $stmt = $pdo->prepare(
        'SELECT o.OrderID, o.HotelID, o.OrderDate, o.Status, h.HotelName
         FROM hotel_order o
         INNER JOIN hotel h ON h.HotelID = o.HotelID
         WHERE o.OrderID = ?'
    );
    $stmt->execute([$selectedOrderId]);
    $selectedOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedOrder) {
        $lineStmt = $pdo->prepare(
            "SELECT od.OrderDetailID, od.ItemID, od.OrderedQuantity, od.ItemNotes,
                    i.ItemCode, i.ItemName, u.UnitSymbol,
                    COALESCE((
                        SELECT SUM(dd.SuppliedQuantity)
                        FROM delivery_detail dd
                        INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                        WHERE dd.OrderDetailID = od.OrderDetailID
                          AND d.Status = 'Completed'
                    ), 0) AS SuppliedQuantity
             FROM hotel_order_detail od
             INNER JOIN item i ON i.ItemID = od.ItemID
             LEFT JOIN unit u ON u.UnitID = i.UnitID
             WHERE od.OrderID = ?
             ORDER BY i.ItemName"
        );
        $lineStmt->execute([$selectedOrderId]);
        $orderLines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/*
|--------------------------------------------------------------------------
| Delivery list and search
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        CAST(d.DeliveryID AS CHAR) LIKE ?
        OR CAST(o.OrderID AS CHAR) LIKE ?
        OR h.HotelName LIKE ?
        OR d.DeliveryNo LIKE ?
    )';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

if (in_array($statusFilter, ['Draft', 'Completed', 'Cancelled'], true)) {
    $where[] = 'd.Status = ?';
    $params[] = $statusFilter;
}

if ($dateFrom !== '') {
    $where[] = 'd.DeliveryDate >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'd.DeliveryDate <= ?';
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$listStmt = $pdo->prepare(
    "SELECT d.DeliveryID, d.OrderID, d.DeliveryDate, d.DeliveryNo,
            d.Status, d.Notes, h.HotelName, o.Status AS OrderStatus,
            COALESCE((
                SELECT SUM(dd.SuppliedQuantity)
                FROM delivery_detail dd
                WHERE dd.DeliveryID = d.DeliveryID
            ), 0) AS TotalQuantity,
            COALESCE((
                SELECT SUM(dd.LineTotal)
                FROM delivery_detail dd
                WHERE dd.DeliveryID = d.DeliveryID
            ), 0) AS TotalAmount,
            (SELECT COUNT(*) FROM invoice inv
             WHERE inv.DeliveryID = d.DeliveryID) AS InvoiceCount,
            (SELECT inv.InvoiceID FROM invoice inv
             WHERE inv.DeliveryID = d.DeliveryID LIMIT 1) AS InvoiceID
     FROM delivery d
     INNER JOIN hotel_order o ON o.OrderID = d.OrderID
     INNER JOIN hotel h ON h.HotelID = o.HotelID
     $whereSql
     ORDER BY d.DeliveryDate DESC, d.DeliveryID DESC"
);
$listStmt->execute($params);
$deliveries = $listStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/
$summary = $pdo->query(
    "SELECT
        COUNT(*) AS TotalDeliveries,
        COALESCE(SUM(Status = 'Draft'), 0) AS DraftDeliveries,
        COALESCE(SUM(Status = 'Completed'), 0) AS CompletedDeliveries,
        COALESCE(SUM(CASE WHEN Status = 'Completed' THEN 1 ELSE 0 END), 0) AS InvoicedCandidates
     FROM delivery"
)->fetch(PDO::FETCH_ASSOC);

$completedValueStmt = $pdo->query(
    "SELECT COALESCE(SUM(dd.LineTotal), 0)
     FROM delivery_detail dd
     INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
     WHERE d.Status = 'Completed'"
);
$completedValue = (float)$completedValueStmt->fetchColumn();

$flash = $_SESSION['delivery_flash'] ?? null;
unset($_SESSION['delivery_flash']);

/*
|--------------------------------------------------------------------------
| Form defaults / preserve submitted values after validation errors
|--------------------------------------------------------------------------
*/
$formDelivery = $editDelivery ?? [
    'DeliveryID' => 0,
    'OrderID' => $selectedOrderId,
    'DeliveryDate' => date('Y-m-d'),
    'DeliveryNo' => '',
    'Notes' => '',
];

if (!empty($formDelivery['DeliveryDate'])) {
    $formDelivery['DeliveryDate'] = date('Y-m-d', strtotime((string)$formDelivery['DeliveryDate']));
}

$formDetails = [];

if ($editDelivery) {
    foreach ($editDetails as $detail) {
        $formDetails[(int)$detail['OrderDetailID']] = [
            'quantity' => $detail['SuppliedQuantity'],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save'
    && $errors) {
    $formDelivery = [
        'DeliveryID' => (int)($_POST['delivery_id'] ?? 0),
        'OrderID' => $selectedOrderId,
        'DeliveryDate' => $_POST['delivery_date'] ?? date('Y-m-d'),
        'DeliveryNo' => $_POST['delivery_no'] ?? '',
        'Notes' => $_POST['notes'] ?? '',
    ];

    foreach (($_POST['order_detail_id'] ?? []) as $index => $detailId) {
        $formDetails[(int)$detailId] = [
            'quantity' => $_POST['supplied_quantity'][$index] ?? '',
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries | Hotel Supply Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px}
        .page-header h1{font-size:27px;margin:0 0 7px}
        .page-header p{margin:0;color:#718096}
        .delivery-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px}
        .stat-card,.panel{background:#fff;border:1px solid #e7ebf0;border-radius:12px;box-shadow:0 2px 8px rgba(15,23,42,.025)}
        .stat-card{padding:18px}
        .stat-card span{display:block;color:#718096;font-size:13px;margin-bottom:8px}
        .stat-card strong{font-size:24px;color:#172b4d}
        .panel{padding:22px;margin-bottom:22px}
        .panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:20px}
        .panel-heading h2{font-size:18px;margin:0}
        .muted{color:#778399}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .field{display:flex;flex-direction:column;gap:7px;min-width:0}
        .field.full{grid-column:1/-1}
        .field label{font-size:13px;font-weight:600;color:#344054}
        .field input,.field select,.field textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #d7dde5;border-radius:7px;background:#fff;color:#263449;font:inherit;min-height:42px}
        .field textarea{min-height:80px;resize:vertical}
        .help{font-size:12px;color:#778399}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:7px;padding:10px 14px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;text-decoration:none}
        .btn-primary{background:#3157c8;color:#fff}
        .btn-secondary{background:#eef2f7;color:#344054}
        .btn-danger{background:#fff0f0;color:#b42318}
        .btn-small{padding:7px 10px;font-size:12px}
        .btn:disabled{opacity:.5;cursor:not-allowed}
        .table-wrap{overflow-x:auto}
        .data-table{width:100%;border-collapse:collapse;min-width:820px}
        .data-table th,.data-table td{padding:12px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:middle}
        .data-table th{background:#f8fafc;font-size:12px;color:#667085;white-space:nowrap}
        .data-table td{font-size:13px}
        .delivery-items{width:100%;border-collapse:collapse;min-width:760px}
        .delivery-items th,.delivery-items td{padding:10px 8px;border-bottom:1px solid #edf0f4;text-align:left}
        .delivery-items th{font-size:12px;color:#667085;background:#f8fafc}
        .delivery-items input[type=number]{width:115px;box-sizing:border-box;padding:9px;border:1px solid #d7dde5;border-radius:6px}
        .status-badge{display:inline-block;border-radius:20px;padding:5px 9px;font-size:11px;font-weight:700;white-space:nowrap}
        .status-draft{background:#fff4d6;color:#8a5b00}
        .status-completed{background:#dcfae6;color:#067647}
        .status-cancelled{background:#fee4e2;color:#b42318}
        .alert{padding:13px 15px;border-radius:8px;margin-bottom:18px;font-size:13px}
        .alert-success{color:#067647;background:#ecfdf3;border:1px solid #abefc6}
        .alert-error{color:#b42318;background:#fff1f0;border:1px solid #fecdca}
        .filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:end}
        .filter-grid input,.filter-grid select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d7dde5;border-radius:7px;min-height:41px}
        .actions{display:flex;gap:6px;flex-wrap:wrap}
        .form-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:20px}
        .empty-state{text-align:center;padding:30px 12px;color:#778399}
        .inline-form{display:inline}
        .order-summary{background:#f8fafc;border:1px solid #e7ebf0;padding:14px;border-radius:8px;margin-bottom:18px}
        .amount{font-weight:700;color:#172b4d;white-space:nowrap}
        @media(max-width:1000px){.delivery-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:650px){.panel{padding:15px}.form-grid,.filter-grid{grid-template-columns:1fr}.page-header h1{font-size:23px}.stat-card{padding:13px}.stat-card strong{font-size:21px}.form-actions .btn{flex:1}}
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
        <a class="nav-link active" href="deliveries.php">🚚 &nbsp; Deliveries</a>
        <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
        <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
        <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
    </aside>

    <main class="main">
        <div class="page-header">
            <div>
                <h1>Deliveries</h1>
                <p>Prepare deliveries, track supplied quantities, and create delivery invoices.</p>
            </div>
            <a href="deliveries.php" class="btn btn-primary">＋ New Delivery</a>
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

        <section class="delivery-stats">
            <div class="stat-card"><span>Total deliveries</span><strong><?= number_format((int)$summary['TotalDeliveries']) ?></strong></div>
            <div class="stat-card"><span>Draft deliveries</span><strong><?= number_format((int)$summary['DraftDeliveries']) ?></strong></div>
            <div class="stat-card"><span>Completed deliveries</span><strong><?= number_format((int)$summary['CompletedDeliveries']) ?></strong></div>
            <div class="stat-card"><span>Completed delivery value</span><strong><?= number_format($completedValue, 2) ?></strong></div>
        </section>

        <section class="panel" id="delivery-form">
            <div class="panel-heading">
                <div>
                    <h2><?= !empty($formDelivery['DeliveryID']) ? 'Edit Draft Delivery #' . (int)$formDelivery['DeliveryID'] : 'Create a Delivery' ?></h2>
                    <p class="muted" style="margin:6px 0 0">Choose an order to see the remaining quantities available for delivery.</p>
                </div>
                <?php if (!empty($formDelivery['DeliveryID'])): ?>
                    <a class="btn btn-secondary" href="deliveries.php">Cancel Editing</a>
                <?php endif; ?>
            </div>

            <?php if (!$eligibleOrders): ?>
                <div class="alert alert-error">No eligible hotel orders found. Record an order with at least one item first.</div>
            <?php else: ?>
                <?php if (!$selectedOrder): ?>
                    <form method="GET" action="deliveries.php" class="form-grid" style="margin-bottom:20px">
                        <div class="field">
                            <label for="order_id">Hotel Order *</label>
                            <select name="order_id" id="order_id" required>
                                <option value="">Select a hotel order</option>
                                <?php foreach ($eligibleOrders as $order): ?>
                                    <option value="<?= (int)$order['OrderID'] ?>">
                                        #<?= (int)$order['OrderID'] ?> — <?= e($order['HotelName']) ?>
                                        (<?= e($order['OrderDate']) ?>, <?= e($order['Status']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" style="justify-content:flex-end">
                            <label>&nbsp;</label>
                            <button class="btn btn-primary" type="submit">Load Order Items</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="order-summary">
                        <strong>Order #<?= (int)$selectedOrder['OrderID'] ?> — <?= e($selectedOrder['HotelName']) ?></strong>
                        <div class="muted" style="margin-top:6px">
                            Order date: <?= e($selectedOrder['OrderDate']) ?>
                            &nbsp; | &nbsp; Order status: <?= e($selectedOrder['Status']) ?>
                        </div>
                        <div style="margin-top:10px">
                            <a href="deliveries.php" class="btn btn-secondary btn-small">Choose Another Order</a>
                        </div>
                    </div>

                    <?php
                    $hasRemaining = false;
                    foreach ($orderLines as $line) {
                        if ((float)$line['OrderedQuantity'] - (float)$line['SuppliedQuantity'] > 0.000001) {
                            $hasRemaining = true;
                            break;
                        }
                    }
                    ?>

                    <?php if (!$hasRemaining && empty($formDelivery['DeliveryID'])): ?>
                        <div class="alert alert-success">All ordered quantities have already been supplied through completed deliveries.</div>
                    <?php else: ?>
                        <form method="POST" action="deliveries.php" id="deliveryForm">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="delivery_id" value="<?= (int)$formDelivery['DeliveryID'] ?>">
                            <input type="hidden" name="order_id" value="<?= (int)$selectedOrder['OrderID'] ?>">

                            <div class="form-grid">
                                <div class="field">
                                    <label for="delivery_date">Delivery Date *</label>
                                    <input type="date" name="delivery_date" id="delivery_date"
                                           value="<?= !empty($formDelivery['DeliveryDate']) ? e(date('Y-m-d', strtotime((string)$formDelivery['DeliveryDate']))) : date('Y-m-d') ?>" required>
                                    <span class="help">Selling prices are selected for this date.</span>
                                </div>
                                <div class="field">
                                    <label for="delivery_no">Delivery Number / Reference</label>
                                    <input type="text" name="delivery_no" id="delivery_no" maxlength="100"
                                           placeholder="e.g. DEL-0001"
                                           value="<?= e($formDelivery['DeliveryNo']) ?>">
                                </div>
                                <div class="field full">
                                    <label for="notes">Delivery Notes</label>
                                    <textarea name="notes" id="notes" placeholder="Driver, delivery instructions, or notes"><?= e($formDelivery['Notes']) ?></textarea>
                                </div>
                            </div>

                            <h3 style="margin:26px 0 12px">Items for This Delivery</h3>
                            <div class="table-wrap">
                                <table class="delivery-items">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Ordered</th>
                                            <th>Already Supplied</th>
                                            <th>Remaining</th>
                                            <th>Deliver Now</th>
                                            <th>Item Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($orderLines as $line): ?>
                                        <?php
                                        $detailId = (int)$line['OrderDetailID'];
                                        $remaining = max(0, (float)$line['OrderedQuantity'] - (float)$line['SuppliedQuantity']);
                                        $savedQty = $formDetails[$detailId]['quantity'] ?? '';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($line['ItemName']) ?></strong>
                                                <div class="muted" style="font-size:11px;margin-top:4px">
                                                    <?= e($line['ItemCode']) ?> · <?= e($line['UnitSymbol']) ?>
                                                </div>
                                                <?php if ($line['ItemNotes']): ?>
                                                    <div class="help"><?= e($line['ItemNotes']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e(number_format((float)$line['OrderedQuantity'], 3)) ?> <?= e($line['UnitSymbol']) ?></td>
                                            <td><?= e(number_format((float)$line['SuppliedQuantity'], 3)) ?> <?= e($line['UnitSymbol']) ?></td>
                                            <td><strong><?= e(number_format($remaining, 3)) ?> <?= e($line['UnitSymbol']) ?></strong></td>
                                            <td>
                                                <input type="hidden" name="order_detail_id[]" value="<?= $detailId ?>">
                                                <input type="number"
                                                       name="supplied_quantity[]"
                                                       min="0.001"
                                                       max="<?= e(number_format($remaining, 3, '.', '')) ?>"
                                                       step="0.001"
                                                       value="<?= e($savedQty) ?>"
                                                       placeholder="0.000"
                                                       <?= $remaining <= 0 && $savedQty === '' ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <input type="hidden" name="item_notes[]" value="">
                                                <span class="muted">From order</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="help" style="margin-top:10px">
                                Leave the quantity blank for items not included in this delivery. Quantities cannot exceed the outstanding order quantity.
                            </p>

                            <div class="form-actions">
                                <button type="submit" name="status" value="Draft" class="btn btn-secondary">
                                    Save as Draft
                                </button>
                                <button type="submit" name="status" value="Completed" class="btn btn-primary"
                                        onclick="return confirm('Complete this delivery? The quantities will be recorded and an invoice will be created.');">
                                    Complete Delivery &amp; Create Invoice
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Delivery History</h2>
                    <p class="muted" style="margin:6px 0 0">View completed deliveries, edit drafts, or open the related invoice.</p>
                </div>
            </div>

            <form method="GET" action="deliveries.php" style="margin-bottom:20px">
                <div class="filter-grid">
                    <div class="field">
                        <label for="search">Search</label>
                        <input type="search" name="search" id="search"
                               placeholder="Delivery no., order no., hotel"
                               value="<?= e($search) ?>">
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="">All statuses</option>
                            <?php foreach (['Draft', 'Completed', 'Cancelled'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                                    <?= e($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="date_from">Date from</label>
                        <input type="date" name="date_from" id="date_from" value="<?= e($dateFrom) ?>">
                    </div>
                    <div class="field">
                        <label for="date_to">Date to</label>
                        <input type="date" name="date_to" id="date_to" value="<?= e($dateTo) ?>">
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="deliveries.php" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Delivery #</th>
                            <th>Date</th>
                            <th>Hotel / Order</th>
                            <th>Reference</th>
                            <th>Quantity</th>
                            <th>Delivery Value</th>
                            <th>Status</th>
                            <th>Invoice</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deliveries as $delivery): ?>
                        <tr>
                            <td><strong>#<?= (int)$delivery['DeliveryID'] ?></strong></td>
                            <td><?= !empty($delivery['DeliveryDate']) ? e(date('Y-m-d', strtotime((string)$delivery['DeliveryDate']))) : '—' ?></td>
                            <td>
                                <strong><?= e($delivery['HotelName']) ?></strong>
                                <div class="muted" style="font-size:11px;margin-top:4px">
                                    Order #<?= (int)$delivery['OrderID'] ?>
                                </div>
                            </td>
                            <td><?= e($delivery['DeliveryNo'] ?: '—') ?></td>
                            <td><?= e(number_format((float)$delivery['TotalQuantity'], 3)) ?></td>
                            <td class="amount"><?= number_format((float)$delivery['TotalAmount'], 2) ?></td>
                            <td>
                                <span class="status-badge <?= e(deliveryStatusClass($delivery['Status'])) ?>">
                                    <?= e($delivery['Status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ((int)$delivery['InvoiceCount'] > 0): ?>
                                    <a href="invoices.php?view=<?= (int)$delivery['InvoiceID'] ?>">View Invoice</a>
                                <?php else: ?>
                                    <span class="muted">Not invoiced</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($delivery['Status'] === 'Draft'): ?>
                                        <a class="btn btn-secondary btn-small"
                                           href="deliveries.php?edit=<?= (int)$delivery['DeliveryID'] ?>">Edit</a>
                                        <form method="POST" action="deliveries.php" class="inline-form"
                                              onsubmit="return confirm('Cancel this draft delivery?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="delivery_id" value="<?= (int)$delivery['DeliveryID'] ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Locked</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$deliveries): ?>
                        <tr><td colspan="9" class="empty-state">No deliveries found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="help" style="margin-top:12px"><?= count($deliveries) ?> delivery record(s) shown.</p>
        </section>
    </main>
</div>
</body>
</html>
