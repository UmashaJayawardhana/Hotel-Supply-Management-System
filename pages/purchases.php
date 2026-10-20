<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function purchaseFlash(string $type, string $text): void
{
    $_SESSION['purchase_flash'] = ['type' => $type, 'text' => $text];
}

function purchaseRedirect(string $url = 'purchases.php'): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Validate purchase details and calculate line totals.
 */
function validatePurchaseRows(array $itemIds, array $quantities, array $unitCosts): array
{
    if (
        count($itemIds) !== count($quantities) ||
        count($itemIds) !== count($unitCosts)
    ) {
        throw new RuntimeException('Invalid purchase item data.');
    }

    $rows = [];

    foreach ($itemIds as $index => $itemId) {
        $itemId = trim((string)$itemId);
        $quantity = trim((string)$quantities[$index]);
        $unitCost = trim((string)$unitCosts[$index]);

        // Ignore completely empty rows.
        if ($itemId === '' && $quantity === '' && $unitCost === '') {
            continue;
        }

        if ($itemId === '' || $quantity === '' || $unitCost === '') {
            throw new RuntimeException(
                'Select an item and enter its quantity and unit cost for every completed row.'
            );
        }

        if (!ctype_digit($itemId) || (int)$itemId <= 0) {
            throw new RuntimeException('Invalid item selected.');
        }

        if (
            !is_numeric($quantity) ||
            !is_finite((float)$quantity) ||
            (float)$quantity <= 0 ||
            (float)$quantity > 999999999.999
        ) {
            throw new RuntimeException('Purchase quantities must be greater than zero.');
        }

        if (
            !is_numeric($unitCost) ||
            !is_finite((float)$unitCost) ||
            (float)$unitCost < 0 ||
            (float)$unitCost > 99999999.99
        ) {
            throw new RuntimeException('Unit costs must be zero or greater.');
        }

        $quantity = round((float)$quantity, 3);
        $unitCost = round((float)$unitCost, 2);
        $lineTotal = round($quantity * $unitCost, 2);

        if (!is_finite($lineTotal) || $lineTotal > 999999999999.99) {
            throw new RuntimeException('A purchase line total is too large.');
        }

        $rows[] = [
            'ItemID' => (int)$itemId,
            'Quantity' => $quantity,
            'UnitCost' => $unitCost,
            'LineTotal' => $lineTotal
        ];
    }

    if (!$rows) {
        throw new RuntimeException('Add at least one item to the purchase.');
    }

    return $rows;
}

/**
 * Ensure selected items are active.
 */
function validatePurchaseItems(PDO $pdo, array $rows): void
{
    $ids = array_values(array_unique(array_map(
        'intval',
        array_column($rows, 'ItemID')
    )));

    if (!$ids) {
        throw new RuntimeException('No items were selected.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT i.ItemID
        FROM item i
        INNER JOIN unit u ON u.UnitID = i.UnitID
        INNER JOIN item_category c ON c.CategoryID = i.CategoryID
        WHERE i.IsActive = 1
          AND u.IsActive = 1
          AND c.IsActive = 1
          AND i.ItemID IN ($placeholders)
    ");

    $stmt->execute($ids);

    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (count($validIds) !== count($ids)) {
        throw new RuntimeException(
            'One or more selected items, units, or categories are inactive.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST: Save a purchase
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save') {

    try {
        if (!hash_equals(
            $_SESSION['csrf_token'],
            (string)($_POST['csrf_token'] ?? '')
        )) {
            throw new RuntimeException(
                'Invalid session token. Refresh the page and try again.'
            );
        }

        $purchaseId = (int)($_POST['purchase_id'] ?? 0);
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $purchaseDate = trim((string)($_POST['purchase_date'] ?? ''));
        $receiptNo = trim((string)($_POST['receipt_no'] ?? ''));
        $additionalCost = trim((string)($_POST['additional_cost'] ?? '0'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($vendorId <= 0) {
            throw new RuntimeException('Select a market vendor.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $purchaseDate);

        if (!$date || $date->format('Y-m-d') !== $purchaseDate) {
            throw new RuntimeException('Enter a valid purchase date.');
        }

        if (strlen($receiptNo) > 100) {
            throw new RuntimeException('Receipt number cannot exceed 100 characters.');
        }

        if (strlen($notes) > 2000) {
            throw new RuntimeException('Notes cannot exceed 2000 characters.');
        }

        if (
            !is_numeric($additionalCost) ||
            !is_finite((float)$additionalCost) ||
            (float)$additionalCost < 0 ||
            (float)$additionalCost > 999999999999.99
        ) {
            throw new RuntimeException('Additional cost must be zero or greater.');
        }

        $additionalCost = round((float)$additionalCost, 2);

        $rows = validatePurchaseRows(
            $_POST['item_id'] ?? [],
            $_POST['quantity'] ?? [],
            $_POST['unit_cost'] ?? []
        );

        validatePurchaseItems($pdo, $rows);

        // Validate the vendor.
        $vendorStmt = $pdo->prepare("
            SELECT VendorID
            FROM market_vendor
            WHERE VendorID = ?
              AND IsActive = 1
        ");
        $vendorStmt->execute([$vendorId]);

        if (!$vendorStmt->fetchColumn()) {
            throw new RuntimeException('The selected vendor is inactive or does not exist.');
        }

        $lineSubtotal = 0;

        foreach ($rows as $row) {
            $lineSubtotal += $row['LineTotal'];
        }

        $lineSubtotal = round($lineSubtotal, 2);
        $totalAmount = round($lineSubtotal + $additionalCost, 2);

        if ($totalAmount > 999999999999.99) {
            throw new RuntimeException('The total purchase amount is too large.');
        }

        $pdo->beginTransaction();

        if ($purchaseId > 0) {
            $existingStmt = $pdo->prepare("
                SELECT PurchaseID
                FROM market_purchase
                WHERE PurchaseID = ?
                FOR UPDATE
            ");
            $existingStmt->execute([$purchaseId]);

            if (!$existingStmt->fetchColumn()) {
                throw new RuntimeException('Purchase record not found.');
            }

            $updateStmt = $pdo->prepare("
                UPDATE market_purchase
                SET
                    VendorID = ?,
                    PurchaseDate = ?,
                    ReceiptNo = ?,
                    TotalAmount = ?,
                    AdditionalCost = ?,
                    Notes = ?
                WHERE PurchaseID = ?
            ");

            $updateStmt->execute([
                $vendorId,
                $purchaseDate,
                $receiptNo !== '' ? $receiptNo : null,
                $totalAmount,
                $additionalCost,
                $notes !== '' ? $notes : null,
                $purchaseId
            ]);

            $deleteStmt = $pdo->prepare("
                DELETE FROM market_purchase_detail
                WHERE PurchaseID = ?
            ");
            $deleteStmt->execute([$purchaseId]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO market_purchase
                    (
                        VendorID,
                        PurchaseDate,
                        ReceiptNo,
                        TotalAmount,
                        AdditionalCost,
                        Notes
                    )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $vendorId,
                $purchaseDate,
                $receiptNo !== '' ? $receiptNo : null,
                $totalAmount,
                $additionalCost,
                $notes !== '' ? $notes : null
            ]);

            $purchaseId = (int)$pdo->lastInsertId();
        }

        $detailStmt = $pdo->prepare("
            INSERT INTO market_purchase_detail
                (PurchaseID, ItemID, Quantity, UnitCost, LineTotal)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($rows as $row) {
            $detailStmt->execute([
                $purchaseId,
                $row['ItemID'],
                $row['Quantity'],
                $row['UnitCost'],
                $row['LineTotal']
            ]);
        }

        $pdo->commit();

        purchaseFlash(
            'success',
            'Market purchase saved successfully. Total: ' .
            number_format($totalAmount, 2) . ' LKR'
        );

        purchaseRedirect();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        purchaseFlash('error', $ex->getMessage());
        purchaseRedirect($purchaseId > 0 ? 'purchases.php?edit=' . $purchaseId : 'purchases.php');
    }
}

/* -------------------- LOAD ACTIVE VENDORS AND ITEMS -------------------- */
$vendorStmt = $pdo->query("
    SELECT VendorID, VendorName
    FROM market_vendor
    WHERE IsActive = 1
    ORDER BY VendorName
");
$vendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

$itemStmt = $pdo->query("
    SELECT
        i.ItemID,
        i.ItemCode,
        i.ItemName,
        u.UnitSymbol,
        c.CategoryName
    FROM item i
    INNER JOIN unit u ON u.UnitID = i.UnitID
    INNER JOIN item_category c ON c.CategoryID = i.CategoryID
    WHERE i.IsActive = 1
      AND u.IsActive = 1
      AND c.IsActive = 1
    ORDER BY c.CategoryName, i.ItemName
");
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- EDIT PURCHASE -------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editPurchase = null;
$editDetails = [];

if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM market_purchase
        WHERE PurchaseID = ?
    ");
    $stmt->execute([$editId]);
    $editPurchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editPurchase) {
        purchaseFlash('error', 'Purchase record not found.');
        purchaseRedirect();
    }

    $stmt = $pdo->prepare("
        SELECT ItemID, Quantity, UnitCost
        FROM market_purchase_detail
        WHERE PurchaseID = ?
        ORDER BY PurchaseDetailID
    ");
    $stmt->execute([$editId]);
    $editDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$editDetails) {
    $editDetails = [
        ['ItemID' => '', 'Quantity' => '', 'UnitCost' => '']
    ];
}

/* -------------------- VIEW PURCHASE DETAILS -------------------- */
$viewId = (int)($_GET['view'] ?? 0);
$viewPurchase = null;
$viewDetails = [];

if ($viewId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            v.VendorName
        FROM market_purchase p
        INNER JOIN market_vendor v ON v.VendorID = p.VendorID
        WHERE p.PurchaseID = ?
    ");
    $stmt->execute([$viewId]);
    $viewPurchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($viewPurchase) {
        $stmt = $pdo->prepare("
            SELECT
                d.*,
                i.ItemCode,
                i.ItemName,
                u.UnitSymbol,
                c.CategoryName
            FROM market_purchase_detail d
            INNER JOIN item i ON i.ItemID = d.ItemID
            INNER JOIN unit u ON u.UnitID = i.UnitID
            INNER JOIN item_category c ON c.CategoryID = i.CategoryID
            WHERE d.PurchaseID = ?
            ORDER BY c.CategoryName, i.ItemName
        ");
        $stmt->execute([$viewId]);
        $viewDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* -------------------- SEARCH AND FILTERS -------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(v.VendorName LIKE ? OR p.ReceiptNo LIKE ? OR CAST(p.PurchaseID AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'p.PurchaseDate >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'p.PurchaseDate <= ?';
    $params[] = $dateTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        p.*,
        v.VendorName,
        COUNT(d.PurchaseDetailID) AS ItemCount
    FROM market_purchase p
    INNER JOIN market_vendor v ON v.VendorID = p.VendorID
    LEFT JOIN market_purchase_detail d ON d.PurchaseID = p.PurchaseID
    $whereSql
    GROUP BY p.PurchaseID
    ORDER BY p.PurchaseDate DESC, p.PurchaseID DESC
");
$stmt->execute($params);
$purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPurchases = (int)$pdo->query("
    SELECT COUNT(*) FROM market_purchase
")->fetchColumn();

$totalSpend = (float)$pdo->query("
    SELECT COALESCE(SUM(TotalAmount), 0)
    FROM market_purchase
")->fetchColumn();

$currentMonthSpend = (float)$pdo->query("
    SELECT COALESCE(SUM(TotalAmount), 0)
    FROM market_purchase
    WHERE PurchaseDate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND PurchaseDate < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
")->fetchColumn();

$flash = $_SESSION['purchase_flash'] ?? null;
unset($_SESSION['purchase_flash']);

/* -------------------- HOTEL ORDERS DEMAND (PURCHASE REQUIREMENTS) -------------------- */
$orderStatusFilter = trim((string)($_GET['order_status'] ?? 'confirmed'));
$orderWhere = [];
$orderParams = [];

if ($orderStatusFilter === 'confirmed_only') {
    $orderWhere[] = "o.Status = 'Confirmed'";
} elseif ($orderStatusFilter === 'partial_only') {
    $orderWhere[] = "o.Status = 'Partially Delivered'";
} elseif ($orderStatusFilter === 'received') {
    $orderWhere[] = "o.Status = 'Received'";
} else {
    // Default 'confirmed': calculate market purchase requirements ONLY after orders are Confirmed
    // (includes Confirmed and Partially Delivered orders requiring items).
    // 'Received' (unconfirmed) and 'Cancelled' orders are never counted.
    $orderStatusFilter = 'confirmed';
    $orderWhere[] = "o.Status IN ('Confirmed', 'Partially Delivered')";
}

// Always exclude Cancelled orders
$orderWhere[] = "o.Status <> 'Cancelled'";

$orderWhereSql = implode(' AND ', $orderWhere);

// 1. Aggregated Items Demand across active orders with market purchases deducted
$demandSql = "
    SELECT 
        i.ItemID,
        i.ItemCode,
        i.ItemName,
        u.UnitSymbol,
        c.CategoryName,
        COALESCE(act.TotalOrdered, 0) AS TotalOrderedQty,
        COALESCE(act.NetDemand, 0) AS NetOrderDemandQty,
        GREATEST(0, COALESCE(purch.TotalPurchased, 0) - COALESCE(deliv.TotalDeliveredEver, 0)) AS PurchasedStockQty,
        GREATEST(0, COALESCE(act.NetDemand, 0) - GREATEST(0, COALESCE(purch.TotalPurchased, 0) - COALESCE(deliv.TotalDeliveredEver, 0))) AS RemainingNeededQty,
        act.OrderCount,
        act.OrderBreakdown
    FROM item i
    INNER JOIN unit u ON u.UnitID = i.UnitID
    INNER JOIN item_category c ON c.CategoryID = i.CategoryID
    INNER JOIN (
        SELECT 
            od.ItemID,
            SUM(od.OrderedQuantity) AS TotalOrdered,
            SUM(GREATEST(0, od.OrderedQuantity - COALESCE((
                SELECT SUM(dd.SuppliedQuantity)
                FROM delivery_detail dd
                INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                WHERE dd.OrderDetailID = od.OrderDetailID
                  AND d.Status = 'Completed'
            ), 0))) AS NetDemand,
            COUNT(DISTINCT CASE WHEN (od.OrderedQuantity - COALESCE((
                SELECT SUM(dd.SuppliedQuantity)
                FROM delivery_detail dd
                INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                WHERE dd.OrderDetailID = od.OrderDetailID
                  AND d.Status = 'Completed'
            ), 0)) > 0.0001 THEN od.OrderID END) AS OrderCount,
            GROUP_CONCAT(DISTINCT CASE WHEN (od.OrderedQuantity - COALESCE((
                SELECT SUM(dd.SuppliedQuantity)
                FROM delivery_detail dd
                INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                WHERE dd.OrderDetailID = od.OrderDetailID
                  AND d.Status = 'Completed'
            ), 0)) > 0.0001 THEN CONCAT('#', o.OrderID, ' ', h.HotelName) END ORDER BY o.OrderID SEPARATOR ', ') AS OrderBreakdown
        FROM hotel_order_detail od
        INNER JOIN hotel_order o ON o.OrderID = od.OrderID
        INNER JOIN hotel h ON h.HotelID = o.HotelID
        WHERE $orderWhereSql
        GROUP BY od.ItemID
        HAVING NetDemand > 0.0001
    ) act ON act.ItemID = i.ItemID
    LEFT JOIN (
        SELECT 
            mpd.ItemID,
            SUM(mpd.Quantity) AS TotalPurchased
        FROM market_purchase_detail mpd
        GROUP BY mpd.ItemID
    ) purch ON purch.ItemID = i.ItemID
    LEFT JOIN (
        SELECT 
            od_all.ItemID,
            SUM(dd_all.SuppliedQuantity) AS TotalDeliveredEver
        FROM delivery_detail dd_all
        INNER JOIN delivery d_all ON d_all.DeliveryID = dd_all.DeliveryID
        INNER JOIN hotel_order_detail od_all ON od_all.OrderDetailID = dd_all.OrderDetailID
        INNER JOIN hotel_order o_all ON o_all.OrderID = od_all.OrderID
        WHERE d_all.Status = 'Completed'
          AND o_all.Status <> 'Cancelled'
        GROUP BY od_all.ItemID
    ) deliv ON deliv.ItemID = i.ItemID
    ORDER BY c.CategoryName, i.ItemName
";
$demandStmt = $pdo->prepare($demandSql);
$demandStmt->execute($orderParams);
$hotelDemandItems = $demandStmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totalDemandItemsCount = count($hotelDemandItems);
$totalOrderedQuantity = 0.0;
$totalAlreadyPurchasedQty = 0.0;
$totalRemainingQuantity = 0.0;
$itemsPendingPurchaseCount = 0;

foreach ($hotelDemandItems as $demandItem) {
    $totalOrderedQuantity += (float)$demandItem['TotalOrderedQty'];
    $totalAlreadyPurchasedQty += (float)$demandItem['PurchasedStockQty'];
    $totalRemainingQuantity += (float)$demandItem['RemainingNeededQty'];
    if ((float)$demandItem['RemainingNeededQty'] > 0.0001) {
        $itemsPendingPurchaseCount++;
    }
}

// 2. Hotel Orders List
$ordersListSql = "
    SELECT 
        o.OrderID,
        o.HotelID,
        o.OrderDate,
        o.Status,
        h.HotelName,
        (SELECT COUNT(*) FROM hotel_order_detail od WHERE od.OrderID = o.OrderID) AS TotalItemCount,
        (SELECT COUNT(*) 
         FROM hotel_order_detail od 
         WHERE od.OrderID = o.OrderID 
           AND (od.OrderedQuantity - COALESCE((
               SELECT SUM(dd.SuppliedQuantity)
               FROM delivery_detail dd
               INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
               WHERE dd.OrderDetailID = od.OrderDetailID
                 AND d.Status = 'Completed'
           ), 0)) > 0.0001
        ) AS RemainingItemCount,
        (SELECT COALESCE(SUM(od.OrderedQuantity), 0) FROM hotel_order_detail od WHERE od.OrderID = o.OrderID) AS TotalOrderedQty,
        (SELECT COALESCE(SUM(GREATEST(0, od.OrderedQuantity - COALESCE((
            SELECT SUM(dd.SuppliedQuantity)
            FROM delivery_detail dd
            INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
            WHERE dd.OrderDetailID = od.OrderDetailID
              AND d.Status = 'Completed'
        ), 0))), 0) FROM hotel_order_detail od WHERE od.OrderID = o.OrderID) AS TotalNeededQty
    FROM hotel_order o
    INNER JOIN hotel h ON h.HotelID = o.HotelID
    WHERE $orderWhereSql
    ORDER BY o.OrderDate DESC, o.OrderID DESC
";
$ordersListStmt = $pdo->prepare($ordersListSql);
$ordersListStmt->execute($orderParams);
$hotelOrders = $ordersListStmt->fetchAll(PDO::FETCH_ASSOC);

$activeOrdersPendingCount = 0;
$sumHotelOrdersOrderedQty = 0.0;
$sumHotelOrdersNeededQty = 0.0;
foreach ($hotelOrders as $hOrder) {
    $sumHotelOrdersOrderedQty += (float)$hOrder['TotalOrderedQty'];
    $sumHotelOrdersNeededQty += (float)$hOrder['TotalNeededQty'];
    if ((float)$hOrder['TotalNeededQty'] > 0.0001) {
        $activeOrdersPendingCount++;
    }
}

// 3. Details per order for quick viewing
$activeOrderDetails = [];
if ($hotelOrders) {
    $activeOrderIds = array_column($hotelOrders, 'OrderID');
    $placeholders = implode(',', array_fill(0, count($activeOrderIds), '?'));
    $activeDetailStmt = $pdo->prepare("
        SELECT 
            od.OrderID,
            od.ItemID,
            od.OrderedQuantity,
            GREATEST(0, od.OrderedQuantity - COALESCE((
                SELECT SUM(dd.SuppliedQuantity)
                FROM delivery_detail dd
                INNER JOIN delivery d ON d.DeliveryID = dd.DeliveryID
                WHERE dd.OrderDetailID = od.OrderDetailID
                  AND d.Status = 'Completed'
            ), 0)) AS NeededQuantity,
            i.ItemCode,
            i.ItemName,
            u.UnitSymbol,
            c.CategoryName
        FROM hotel_order_detail od
        INNER JOIN item i ON i.ItemID = od.ItemID
        INNER JOIN unit u ON u.UnitID = i.UnitID
        INNER JOIN item_category c ON c.CategoryID = i.CategoryID
        WHERE od.OrderID IN ($placeholders)
        ORDER BY c.CategoryName, i.ItemName
    ");
    $activeDetailStmt->execute($activeOrderIds);
    while ($row = $activeDetailStmt->fetch(PDO::FETCH_ASSOC)) {
        $activeOrderDetails[$row['OrderID']][] = $row;
    }
}

$demandItemsTransferList = [];
foreach ($hotelDemandItems as $di) {
    if ((float)$di['RemainingNeededQty'] > 0.0001) {
        $demandItemsTransferList[] = [
            'id' => (int)$di['ItemID'],
            'name' => $di['ItemName'],
            'qty' => (float)$di['RemainingNeededQty']
        ];
    }
}
$hotelDemandItemsJson = json_encode($demandItemsTransferList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$isEditing = $editPurchase !== null;

$formVendor = $editPurchase['VendorID'] ?? '';
$formDate = !empty($editPurchase['PurchaseDate'])
    ? date('Y-m-d', strtotime($editPurchase['PurchaseDate']))
    : date('Y-m-d');
$formReceipt = $editPurchase['ReceiptNo'] ?? '';
$formAdditional = isset($editPurchase['AdditionalCost'])
    ? number_format((float)$editPurchase['AdditionalCost'], 2, '.', '')
    : '0.00';
$formNotes = $editPurchase['Notes'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Market Purchases | Hotel Supply</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
.page-title{margin-bottom:24px}
.page-title h1{margin:0 0 8px;font-size:26px}
.page-title p,.muted{color:#778198;font-size:13px}
.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:20px}
.card,.panel{background:white;border:1px solid #e8ebf2;border-radius:12px;padding:20px}
.card span{font-size:12px;color:#778198}
.card strong{display:block;font-size:25px;margin-top:8px}
.layout{display:grid;grid-template-columns:minmax(330px,.95fr) minmax(0,1.55fr);gap:20px;align-items:start}
.panel h2{font-size:17px;margin:0 0 18px}
.field{margin-bottom:15px}
.field label{display:block;font-size:12px;font-weight:600;margin-bottom:7px}
.field input,.field select,.field textarea,.filters input,.filters select{
    width:100%;padding:11px;border:1px solid #dfe4ed;border-radius:7px;
    font:inherit;font-size:13px;box-sizing:border-box
}
.field textarea{min-height:80px;resize:vertical}
.btn{display:inline-block;border:0;border-radius:7px;padding:10px 13px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;text-align:center}
.primary{background:#4658a9;color:white}
.secondary{background:#eef1f7;color:#34405a}
.danger{background:#fff0ef;color:#a93226}
.notice{padding:12px 14px;border-radius:8px;margin-bottom:18px;font-size:13px}
.success{background:#e8f8ef;color:#187447}
.error{background:#fff0ef;color:#a93226}
.filters{display:grid;grid-template-columns:minmax(0,1fr) 135px 135px auto;gap:8px;margin-bottom:16px}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:12px}
th,td{text-align:left;padding:12px 9px;border-bottom:1px solid #edf0f5;vertical-align:middle}
th{background:#f7f8fc;color:#778198;font-size:10px;text-transform:uppercase}
td{white-space:nowrap}
td.name{white-space:normal;min-width:140px}
.pill{border-radius:20px;padding:5px 8px;font-size:10px;font-weight:bold}
.actions{display:flex;gap:6px;align-items:center}
.actions form{margin:0}
.empty{text-align:center;color:#778198;padding:25px}

/* Dynamic Rows and Total Box */
.purchase-line{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(75px,.7fr) minmax(85px,.8fr) 36px;gap:7px;align-items:end;margin-bottom:10px}
.purchase-line .field{margin:0}
.remove-row{height:40px;width:36px;border:1px solid #fed7d7;background:#fff5f5;color:#c53030;border-radius:7px;cursor:pointer;font-size:18px;display:inline-flex;align-items:center;justify-content:center;transition:0.15s;margin-bottom:15px}
.remove-row:hover{background:#feb2b2}
.total-box{background:#f8fafc;border:1px solid #edf0f5;border-radius:9px;padding:14px;margin-top:14px}
.total-row{display:flex;justify-content:space-between;gap:12px;margin:6px 0;font-size:13px}
.total-row.final{border-top:1px solid #dce3ed;padding-top:10px;font-size:15px;font-weight:700;color:#202b43}

/* Hotel Orders Demand Panel Styles */
.demand-stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.demand-chip{background:#f8fafc;border:1px solid #e8ebf2;border-radius:9px;padding:9px 12px;display:flex;align-items:center;gap:10px}
.demand-chip strong{display:block;font-size:15px;color:#202b43;line-height:1.2}
.demand-chip span.demand-chip-lbl{font-size:11px;color:#778198;display:block}
.demand-tabs{display:flex;gap:6px;border-bottom:1px solid #edf0f5;padding-bottom:12px;margin-bottom:14px;flex-wrap:wrap}
.demand-tab-btn{padding:7px 13px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid #dfe4ed;background:#f8fafc;color:#5b6782;transition:all 0.15s;font-family:inherit}
.demand-tab-btn.active{background:#4658a9;color:white;border-color:#4658a9}
.demand-tab-btn:hover:not(.active){background:#edf0f7}
.status-pill-confirmed{background:#e8f8ef;color:#187447}
.status-pill-received{background:#eef2ff;color:#3730a3}
.status-pill-partially-delivered{background:#fffbeb;color:#b45309}
.demand-search-input{padding:8px 12px;font-size:12px;border:1px solid #dfe4ed;border-radius:6px;width:100%;font-family:inherit;box-sizing:border-box}

.print-only{display:none !important}
.print-dropdown-item:hover{background:#f1f5f9;color:#1e293b}

@media(max-width:1050px){
    .layout{grid-template-columns:1fr}
}
@media(max-width:850px){
    .demand-stats-row{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:650px){
    .cards{grid-template-columns:1fr}
    .filters{grid-template-columns:1fr}
    .panel{padding:14px}
    .purchase-line{grid-template-columns:minmax(0,1fr) 70px 80px 32px;gap:5px}
    .demand-stats-row{grid-template-columns:1fr}
}

@media print {
    @page {
        size: A4 portrait;
        margin: 10mm 10mm 12mm 10mm;
    }
    body {
        background: #fff !important;
        color: #111827 !important;
        font-size: 11px !important;
        line-height: 1.4 !important;
    }
    .app { display: block !important; }
    .main { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    .layout { display: block !important; }
    .right-column { display: block !important; }
    
    /* Hide all elements that shouldn't be printed */
    .sidebar,
    .page-title,
    .cards,
    .filters,
    .notice,
    #purchaseFormPanel,
    #purchaseHistoryPanel,
    .no-print,
    .demand-tabs,
    .demand-search-input,
    form[action="purchases.php"],
    .actions,
    .btn,
    .remove-row {
        display: none !important;
    }

    #hotelDemandPanel {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        background: transparent !important;
    }

    /* Print-only elements show up */
    .print-only {
        display: block !important;
    }
    tr.print-only-tr, th.print-only, td.print-only {
        display: table-cell !important;
    }

    /* Both demand tab contents show in print (unless filtered by print mode) */
    .demand-tab-content {
        display: block !important;
        margin-bottom: 22px !important;
        page-break-inside: auto;
    }

    #hotelDemandPanel.print-mode-items-only #tab-hotel-orders,
    #hotelDemandPanel.print-mode-items-only .print-section-orders {
        display: none !important;
    }

    #hotelDemandPanel.print-mode-orders-only #tab-total-demand,
    #hotelDemandPanel.print-mode-orders-only .print-section-items {
        display: none !important;
    }

    /* Tables formatting in print */
    .table-wrap {
        overflow: visible !important;
    }
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 10px !important;
        page-break-inside: auto;
    }
    thead {
        display: table-header-group;
    }
    tfoot {
        display: table-footer-group;
    }
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    th, td {
        padding: 5px 6px !important;
        border: 1px solid #cbd5e1 !important;
        white-space: normal !important;
        vertical-align: middle !important;
    }
    th {
        background: #f1f5f9 !important;
        color: #1e293b !important;
        font-weight: 700 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    tfoot td {
        background: #f8fafc !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Stat chips print styling */
    .demand-stats-row {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 8px !important;
        margin-bottom: 14px !important;
        page-break-inside: avoid !important;
    }
    .demand-chip {
        border: 1px solid #cbd5e1 !important;
        background: #f8fafc !important;
        padding: 6px 10px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .demand-chip strong {
        font-size: 13px !important;
    }
    .demand-chip span.demand-chip-lbl {
        font-size: 10px !important;
        color: #475569 !important;
    }

    /* Status pills in print */
    .pill {
        border: 1px solid #cbd5e1 !important;
        padding: 2px 5px !important;
        font-size: 9px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .status-pill-confirmed {
        background: #dcfce7 !important;
        color: #166534 !important;
    }
    .status-pill-partially-delivered {
        background: #fef3c7 !important;
        color: #92400e !important;
    }
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
    <a class="nav-link active" href="purchases.php">🧾 &nbsp; Market Purchases</a>
    <a class="nav-link" href="orders.php">📋 &nbsp; Hotel Orders</a>
    <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
    <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
    <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
    <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
</aside>

<main class="main">
    <div class="page-title no-print">
        <h1>Market Purchases</h1>
        <p>Record goods purchased from market vendors and track purchase costs.</p>
    </div>

    <?php if ($flash): ?>
        <div class="notice <?= e($flash['type']) ?> no-print"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="cards no-print">
        <div class="card">
            <span>Total purchases</span>
            <strong><?= $totalPurchases ?></strong>
        </div>
        <div class="card">
            <span>Total spend (LKR)</span>
            <strong><?= number_format($totalSpend, 2) ?></strong>
        </div>
        <div class="card">
            <span>This month's spend (LKR)</span>
            <strong><?= number_format($currentMonthSpend, 2) ?></strong>
        </div>
    </div>

    <!-- VIEW DETAILS PANEL -->
    <?php if ($viewPurchase): ?>
        <section class="panel no-print" style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:15px;flex-wrap:wrap">
                <div>
                    <h2 style="margin:0 0 5px">Purchase #<?= (int)$viewPurchase['PurchaseID'] ?></h2>
                    <p class="muted" style="margin:0">
                        <?= e($viewPurchase['VendorName']) ?> · <?= e($viewPurchase['PurchaseDate']) ?>
                        <?php if ($viewPurchase['ReceiptNo']): ?>
                            · Receipt: <?= e($viewPurchase['ReceiptNo']) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="actions">
                    <a class="btn secondary" href="purchases.php">Close Details</a>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $viewSubtotal = 0;
                    foreach ($viewDetails as $detail):
                        $viewSubtotal += (float)$detail['LineTotal'];
                    ?>
                        <tr>
                            <td><strong><?= e($detail['ItemCode']) ?></strong></td>
                            <td><?= e($detail['ItemName']) ?></td>
                            <td><?= e($detail['CategoryName']) ?></td>
                            <td><?= number_format((float)$detail['Quantity'], 3) ?> <?= e($detail['UnitSymbol']) ?></td>
                            <td><?= number_format((float)$detail['UnitCost'], 2) ?></td>
                            <td><strong><?= number_format((float)$detail['LineTotal'], 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="total-box" style="max-width:340px;margin-left:auto">
                <div class="total-row"><span>Item Subtotal</span><strong><?= number_format($viewSubtotal, 2) ?></strong></div>
                <div class="total-row"><span>Additional Cost</span><strong><?= number_format((float)$viewPurchase['AdditionalCost'], 2) ?></strong></div>
                <div class="total-row final"><span>Total Purchase</span><strong><?= number_format((float)$viewPurchase['TotalAmount'], 2) ?></strong></div>
            </div>

            <?php if (!empty($viewPurchase['Notes'])): ?>
                <p class="muted" style="margin-top:15px;background:#f8fafc;padding:10px 12px;border-radius:6px;border:1px solid #edf0f5">
                    <strong>Notes:</strong> <?= nl2br(e($viewPurchase['Notes'])) ?>
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="layout">
        <!-- LEFT PANEL: RECORD / EDIT PURCHASE -->
        <section class="panel no-print" id="purchaseFormPanel">
            <h2><?= $isEditing ? 'Edit Purchase' : 'Record Market Purchase' ?></h2>

            <?php if (!$vendors): ?>
                <div class="notice error">No active market vendors found. Add a vendor first.</div>
            <?php endif; ?>

            <?php if (!$items): ?>
                <div class="notice error">No active items found. Add items before recording purchases.</div>
            <?php endif; ?>

            <form method="post" action="purchases.php" id="purchaseForm">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="purchase_id" value="<?= $isEditing ? (int)$editPurchase['PurchaseID'] : 0 ?>">

                <div class="field">
                    <label for="vendor_id">Market Vendor *</label>
                    <select id="vendor_id" name="vendor_id" required>
                        <option value="">Select vendor</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?= (int)$vendor['VendorID'] ?>" <?= (string)$formVendor === (string)$vendor['VendorID'] ? 'selected' : '' ?>>
                                <?= e($vendor['VendorName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="purchase_date">Purchase Date *</label>
                    <input type="date" id="purchase_date" name="purchase_date" required value="<?= e($formDate) ?>">
                </div>

                <div class="field">
                    <label for="receipt_no">Receipt Number</label>
                    <input type="text" id="receipt_no" name="receipt_no" maxlength="100" value="<?= e($formReceipt) ?>" placeholder="Optional market receipt number">
                </div>

                <div style="border-top:1px solid #edf0f5;padding-top:16px;margin-top:14px">
                    <h3 style="font-size:14px;margin:0 0 4px">Purchased Items</h3>
                    <p class="muted" style="margin:0 0 12px;font-size:12px">Enter the item, quantity purchased, and cost per unit.</p>

                    <div id="purchaseRows">
                    <?php foreach ($editDetails as $detail): ?>
                        <div class="purchase-line">
                            <div class="field">
                                <label>Item</label>
                                <select name="item_id[]" required>
                                    <option value="">Select item</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= (int)$item['ItemID'] ?>" <?= (string)$detail['ItemID'] === (string)$item['ItemID'] ? 'selected' : '' ?>>
                                            <?= e($item['ItemCode']) ?> - <?= e($item['ItemName']) ?> (<?= e($item['UnitSymbol']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Quantity</label>
                                <input type="number" name="quantity[]" min="0.001" step="0.001" required value="<?= e($detail['Quantity']) ?>" placeholder="0">
                            </div>
                            <div class="field">
                                <label>Unit Cost</label>
                                <input type="number" name="unit_cost[]" min="0" max="99999999.99" step="0.01" required value="<?= e($detail['UnitCost']) ?>" placeholder="0.00">
                            </div>
                            <button type="button" class="remove-row" aria-label="Remove item">×</button>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn secondary" id="addPurchaseRow" style="margin-top:6px">+ Add Item</button>
                </div>

                <div class="field" style="margin-top:18px">
                    <label for="additional_cost">Additional Cost (LKR)</label>
                    <input type="number" id="additional_cost" name="additional_cost" min="0" step="0.01" value="<?= e($formAdditional) ?>" placeholder="0.00">
                    <span class="muted" style="font-size:11px;display:block;margin-top:4px">For transport or other expenses related to this purchase.</span>
                </div>

                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" maxlength="2000" placeholder="Optional purchase notes"><?= e($formNotes) ?></textarea>
                </div>

                <div class="total-box">
                    <div class="total-row"><span>Items Subtotal</span><strong id="subtotalDisplay">0.00</strong></div>
                    <div class="total-row"><span>Additional Cost</span><strong id="additionalDisplay">0.00</strong></div>
                    <div class="total-row final"><span>Estimated Total (LKR)</span><strong id="grandTotalDisplay">0.00</strong></div>
                </div>

                <div class="actions" style="margin-top:20px">
                    <button type="submit" class="btn primary" <?= (!$vendors || !$items) ? 'disabled' : '' ?>>
                        <?= $isEditing ? 'Update Purchase' : 'Save Purchase' ?>
                    </button>
                    <?php if ($isEditing): ?>
                        <a class="btn secondary" href="purchases.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- RIGHT COLUMN: HOTEL ORDERS DEMAND + PURCHASE HISTORY -->
        <div class="right-column" style="display:flex;flex-direction:column;gap:20px;min-width:0">

            <!-- PANEL 1: HOTEL ORDERS & ITEMS DEMAND -->
            <section class="panel" id="hotelDemandPanel">
                <!-- PRINT ONLY HEADER -->
                <div class="print-only demand-print-header">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e293b;padding-bottom:10px;margin-bottom:14px">
                        <div>
                            <div style="font-size:11px;font-weight:700;color:#4658a9;text-transform:uppercase;letter-spacing:0.5px">Hotel Supply Management System</div>
                            <h1 style="margin:2px 0 0;font-size:18px;color:#0f172a">Hotel Order Requirements (What to Purchase)</h1>
                            <div style="font-size:11px;color:#475569;margin-top:2px">
                                Market procurement requirements from Confirmed &amp; Partially Delivered Hotel Orders (still not delivered)
                            </div>
                        </div>
                        <div style="text-align:right;font-size:10px;color:#475569;line-height:1.4">
                            <div><strong>Printed:</strong> <?= date('Y-m-d H:i') ?></div>
                            <div><strong>Filter:</strong> <?= e(ucwords(str_replace('_', ' ', $orderStatusFilter))) ?></div>
                            <div><strong>Active Orders:</strong> <?= $activeOrdersPendingCount ?> pending delivery</div>
                        </div>
                    </div>
                </div>

                <div class="no-print" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px;flex-wrap:wrap">
                    <div>
                        <h2 style="margin:0 0 4px;font-size:17px">📋 Hotel Order Requirements (What to Purchase)</h2>
                        <p class="muted" style="margin:0">Live demand from confirmed hotel orders ready for market procurement (unconfirmed and cancelled orders are excluded).</p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <!-- PRINT BUTTON GROUP WITH DROPDOWN -->
                        <div class="print-dropdown-container" style="position:relative;display:inline-flex">
                            <button type="button" class="btn secondary" onclick="executeDemandPrint('all')" title="Print complete procurement sheet (Items &amp; Orders)" style="padding:7px 11px;font-size:12px;display:inline-flex;align-items:center;gap:5px;border-top-right-radius:0;border-bottom-right-radius:0;border-right:1px solid #cbd5e1">
                                🖨 Print Requirements
                            </button>
                            <button type="button" class="btn secondary" id="btnPrintMenuToggle" onclick="togglePrintMenu(event)" title="Print options" style="padding:7px 8px;font-size:12px;border-top-left-radius:0;border-bottom-left-radius:0">
                                ▾
                            </button>
                            <div id="printDropdownMenu" style="display:none;position:absolute;right:0;top:100%;margin-top:4px;background:white;border:1px solid #cbd5e1;border-radius:7px;box-shadow:0 6px 16px rgba(0,0,0,0.12);z-index:999;min-width:260px;padding:6px 0">
                                <a class="print-dropdown-item" href="javascript:void(0)" onclick="executeDemandPrint('all')" style="display:block;padding:8px 14px;color:#1e293b;text-decoration:none;font-size:12px;font-weight:600">
                                    📄 Print All (Items &amp; Confirmed Orders)
                                </a>
                                <a class="print-dropdown-item" href="javascript:void(0)" onclick="executeDemandPrint('items')" style="display:block;padding:8px 14px;color:#334155;text-decoration:none;font-size:12px">
                                    📦 Print Required Items Table Only
                                </a>
                                <a class="print-dropdown-item" href="javascript:void(0)" onclick="executeDemandPrint('orders')" style="display:block;padding:8px 14px;color:#334155;text-decoration:none;font-size:12px">
                                    🏨 Print Confirmed Hotel Orders Only
                                </a>
                            </div>
                        </div>

                        <?php if ($hotelDemandItems): ?>
                            <button type="button" class="btn primary" id="btnTransferAllDemand" style="padding:7px 12px;font-size:12px" data-items="<?= e($hotelDemandItemsJson) ?>">
                                ➕ Add All Needed Items to Purchase
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- STAT CHIPS (QUANTITY TOTALS) -->
                <div class="demand-stats-row">
                    <div class="demand-chip">
                        <span style="font-size:16px">📦</span>
                        <div>
                            <strong><?= number_format($totalOrderedQuantity, 2) ?></strong>
                            <span class="demand-chip-lbl">Total Ordered</span>
                        </div>
                    </div>
                    <div class="demand-chip">
                        <span style="font-size:16px">🛒</span>
                        <div>
                            <strong style="color:#16a34a"><?= number_format($totalAlreadyPurchasedQty, 2) ?></strong>
                            <span class="demand-chip-lbl">Already Purchased</span>
                        </div>
                    </div>
                    <div class="demand-chip">
                        <span style="font-size:16px">⏳</span>
                        <div>
                            <strong style="color:<?= $totalRemainingQuantity > 0 ? '#e11d48' : '#16a34a' ?>"><?= number_format($totalRemainingQuantity, 2) ?></strong>
                            <span class="demand-chip-lbl">Still Needed (To Buy)</span>
                        </div>
                    </div>
                    <div class="demand-chip">
                        <span style="font-size:16px">🏨</span>
                        <div>
                            <strong><?= $activeOrdersPendingCount ?></strong>
                            <span class="demand-chip-lbl">Orders Pending Supply</span>
                        </div>
                    </div>
                </div>

                <!-- TABS NAV (SCREEN ONLY) -->
                <div class="demand-tabs no-print">
                    <button type="button" class="demand-tab-btn active" data-tab="tab-total-demand">
                        📦 Items to Purchase (<?= $itemsPendingPurchaseCount ?> needing purchase / <?= $totalDemandItemsCount ?> total)
                    </button>
                    <button type="button" class="demand-tab-btn" data-tab="tab-hotel-orders">
                        🏨 Hotel Orders List (<?= $activeOrdersPendingCount ?> pending / <?= count($hotelOrders) ?> total)
                    </button>
                </div>

                <!-- TAB 1: TOTAL ITEMS TO PURCHASE (AGGREGATED DEMAND) -->
                <div class="demand-tab-content" id="tab-total-demand">
                    <div class="print-only print-section-title print-section-items" style="font-weight:700;font-size:12px;margin:12px 0 6px;padding-bottom:3px;border-bottom:1px solid #cbd5e1;color:#1e293b">
                        📦 SECTION 1: REQUIRED ITEMS TO PURCHASE (AGGREGATED HOTEL DEMAND)
                    </div>

                    <div class="no-print" style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                        <input type="search" id="demandSearchInput" class="demand-search-input" placeholder="Search required item by code, name, or category..." style="max-width:280px">
                        
                        <form method="get" action="purchases.php" style="display:flex;gap:8px;align-items:center;margin:0">
                            <?php if ($search): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
                            <?php if ($dateFrom): ?><input type="hidden" name="date_from" value="<?= e($dateFrom) ?>"><?php endif; ?>
                            <?php if ($dateTo): ?><input type="hidden" name="date_to" value="<?= e($dateTo) ?>"><?php endif; ?>
                            <select name="order_status" onchange="this.form.submit()" style="padding:7px 10px;font-size:12px;border:1px solid #dfe4ed;border-radius:6px;background:white">
                                <option value="confirmed" <?= $orderStatusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed Orders (Confirmed &amp; Partial - Ready to Buy)</option>
                                <option value="confirmed_only" <?= $orderStatusFilter === 'confirmed_only' ? 'selected' : '' ?>>Confirmed Only (New Orders)</option>
                                <option value="partial_only" <?= $orderStatusFilter === 'partial_only' ? 'selected' : '' ?>>Partially Delivered Only</option>
                                <option value="received" <?= $orderStatusFilter === 'received' ? 'selected' : '' ?>>Received Orders (Pending Confirmation - Not Counted)</option>
                            </select>
                        </form>
                    </div>

                    <div class="table-wrap">
                        <table id="demandTable">
                            <thead>
                                <tr>
                                    <th>Item Code</th>
                                    <th>Item &amp; Category</th>
                                    <th style="text-align:right">Ordered Qty</th>
                                    <th style="text-align:right">Purchased / Stock</th>
                                    <th style="text-align:right">Still Needed (To Buy)</th>
                                    <th>From Orders</th>
                                    <th class="no-print" style="text-align:center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($hotelDemandItems): foreach ($hotelDemandItems as $dItem): 
                                $orderedQty = (float)$dItem['TotalOrderedQty'];
                                $purchasedQty = (float)$dItem['PurchasedStockQty'];
                                $remainingQty = (float)$dItem['RemainingNeededQty'];
                                $isFulfilled = $remainingQty <= 0.0001;
                            ?>
                                <tr class="demand-row" data-search="<?= strtolower(e($dItem['ItemCode'] . ' ' . $dItem['ItemName'] . ' ' . $dItem['CategoryName'])) ?>" style="<?= $isFulfilled ? 'opacity:0.75;background:#fbfcfd' : '' ?>">
                                    <td><strong><?= e($dItem['ItemCode']) ?></strong></td>
                                    <td class="name">
                                        <div><strong><?= e($dItem['ItemName']) ?></strong></div>
                                        <span class="muted" style="font-size:11px"><?= e($dItem['CategoryName']) ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <span><?= number_format($orderedQty, 3) ?></span>
                                        <span class="muted" style="font-size:11px"><?= e($dItem['UnitSymbol']) ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <?php if ($purchasedQty > 0): ?>
                                            <span style="font-weight:600;color:#16a34a"><?= number_format($purchasedQty, 3) ?></span>
                                        <?php else: ?>
                                            <span class="muted">0.000</span>
                                        <?php endif; ?>
                                        <span class="muted" style="font-size:11px"><?= e($dItem['UnitSymbol']) ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <?php if (!$isFulfilled): ?>
                                            <span style="font-size:13px;font-weight:700;color:#e11d48">
                                                <?= number_format($remainingQty, 3) ?>
                                            </span>
                                            <span class="muted" style="font-size:11px"><?= e($dItem['UnitSymbol']) ?></span>
                                        <?php else: ?>
                                            <span class="pill" style="background:#e8f8ef;color:#16a34a;font-size:10px">✓ Purchased</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="pill" style="background:#f1f5f9;color:#334155;font-size:11px" title="<?= e($dItem['OrderBreakdown']) ?>">
                                            <?= (int)$dItem['OrderCount'] ?> order(s)
                                        </span>
                                        <div class="muted" style="font-size:10px;margin-top:2px;max-width:180px;white-space:normal;overflow:hidden;text-overflow:ellipsis">
                                            <?= e($dItem['OrderBreakdown']) ?>
                                        </div>
                                    </td>
                                    <td class="no-print" style="text-align:center">
                                        <?php if (!$isFulfilled): ?>
                                            <button type="button" class="btn secondary btn-add-demand-item" 
                                                data-item-id="<?= (int)$dItem['ItemID'] ?>" 
                                                data-item-name="<?= e($dItem['ItemName']) ?>" 
                                                data-qty="<?= $remainingQty ?>" 
                                                title="Add remaining needed quantity (<?= number_format($remainingQty, 3) ?>) to form"
                                                style="padding:5px 9px;font-size:11px">
                                                + Add (<?= number_format($remainingQty, 1) ?>)
                                            </button>
                                        <?php else: ?>
                                            <span class="muted" style="font-size:11px">✓ Fulfilled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="empty">No pending items found from confirmed hotel orders.</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1">
                                    <td colspan="2" style="text-align:right"><strong>TOTALS (<?= $totalDemandItemsCount ?> items):</strong></td>
                                    <td style="text-align:right"><strong><?= number_format($totalOrderedQuantity, 2) ?></strong></td>
                                    <td style="text-align:right"><strong style="color:#16a34a"><?= number_format($totalAlreadyPurchasedQty, 2) ?></strong></td>
                                    <td style="text-align:right"><strong style="color:#e11d48;font-size:13px"><?= number_format($totalRemainingQuantity, 2) ?></strong></td>
                                    <td><small><?= $itemsPendingPurchaseCount ?> item(s) pending purchase</small></td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- TAB 2: HOTEL ORDERS LIST -->
                <div class="demand-tab-content" id="tab-hotel-orders" style="display:none">
                    <div class="print-only print-section-title print-section-orders" style="font-weight:700;font-size:12px;margin:18px 0 6px;padding-bottom:3px;border-bottom:1px solid #cbd5e1;color:#1e293b">
                        🏨 SECTION 2: CONFIRMED HOTEL ORDERS (PENDING DELIVERY &amp; SUPPLY)
                    </div>
                    <div class="table-wrap">
                        <table id="hotelOrdersTable">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Hotel Name</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Items Status</th>
                                    <th style="text-align:right">Ordered Qty</th>
                                    <th style="text-align:right">Still Needed</th>
                                    <th class="print-only">Pending Items Breakdown (Still Not Delivered)</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($hotelOrders): foreach ($hotelOrders as $hOrder): 
                                $orderItemsList = [];
                                $pendingItemsStrings = [];
                                foreach (($activeOrderDetails[$hOrder['OrderID']] ?? []) as $detailRow) {
                                    $neededQty = (float)$detailRow['NeededQuantity'];
                                    if ($neededQty > 0.0001) {
                                        $orderItemsList[] = [
                                            'id' => (int)$detailRow['ItemID'],
                                            'name' => $detailRow['ItemName'],
                                            'qty' => $neededQty
                                        ];
                                        $pendingItemsStrings[] = e($detailRow['ItemName']) . ': ' . number_format($neededQty, 2) . ' ' . e($detailRow['UnitSymbol']);
                                    }
                                }
                                $orderItemsJson = htmlspecialchars(json_encode($orderItemsList), ENT_QUOTES, 'UTF-8');
                                $remainingCount = (int)$hOrder['RemainingItemCount'];
                                $totalCount = (int)$hOrder['TotalItemCount'];
                                $isOrderComplete = $remainingCount === 0 || (float)$hOrder['TotalNeededQty'] <= 0.0001;
                            ?>
                                <tr class="order-main-row <?= $isOrderComplete ? 'order-fulfilled' : 'order-pending' ?>" style="<?= $isOrderComplete ? 'opacity:0.75;background:#fbfcfd' : '' ?>">
                                    <td><strong>#<?= (int)$hOrder['OrderID'] ?></strong></td>
                                    <td class="name"><strong><?= e($hOrder['HotelName']) ?></strong></td>
                                    <td><?= date('Y-m-d', strtotime($hOrder['OrderDate'])) ?></td>
                                    <td>
                                        <span class="pill status-pill-<?= strtolower(str_replace(' ', '-', $hOrder['Status'])) ?>">
                                            <?= e($hOrder['Status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!$isOrderComplete): ?>
                                            <div><strong style="color:#e11d48"><?= $remainingCount ?> item(s) pending</strong></div>
                                            <span class="muted" style="font-size:11px">of <?= $totalCount ?> items</span>
                                        <?php else: ?>
                                            <span class="pill" style="background:#e8f8ef;color:#16a34a;font-size:10px">✓ Fully Delivered</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right">
                                        <span><?= number_format((float)$hOrder['TotalOrderedQty'], 2) ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <?php if (!$isOrderComplete): ?>
                                            <strong style="color:#e11d48"><?= number_format((float)$hOrder['TotalNeededQty'], 2) ?></strong>
                                        <?php else: ?>
                                            <span class="muted">0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="print-only" style="font-size:10px;white-space:normal;max-width:250px">
                                        <?php if ($pendingItemsStrings): ?>
                                            <span style="color:#b91c1c;font-weight:600"><?= implode('; ', $pendingItemsStrings) ?></span>
                                        <?php else: ?>
                                            <span style="color:#16a34a">✓ All items delivered</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print">
                                        <div class="actions">
                                            <button type="button" class="btn secondary btn-toggle-order-items" data-target="order-items-<?= (int)$hOrder['OrderID'] ?>" style="padding:5px 8px;font-size:11px">
                                                ▼ Items
                                            </button>
                                            <?php if (!$isOrderComplete): ?>
                                                <button type="button" class="btn secondary btn-add-order-all" data-items='<?= $orderItemsJson ?>' title="Add remaining unsupplied items of this order to purchase" style="padding:5px 8px;font-size:11px">
                                                    + Add Order Items (<?= count($orderItemsList) ?>)
                                                </button>
                                            <?php endif; ?>
                                            <a class="btn secondary" href="orders.php?view=<?= (int)$hOrder['OrderID'] ?>" target="_blank" title="Open full order in new tab" style="padding:5px 8px;font-size:11px">
                                                ↗
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Collapsible item breakdown for this order on screen -->
                                <tr id="order-items-<?= (int)$hOrder['OrderID'] ?>" class="order-items-dropdown no-print" style="display:none;background:#f8fafc">
                                    <td colspan="7" style="padding:12px 16px">
                                        <div style="font-weight:600;font-size:12px;margin-bottom:8px;color:#334155">
                                            Items for Order #<?= (int)$hOrder['OrderID'] ?> (<?= e($hOrder['HotelName']) ?>):
                                        </div>
                                        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(250px, 1fr));gap:8px">
                                            <?php foreach (($activeOrderDetails[$hOrder['OrderID']] ?? []) as $ordDetail): 
                                                $ordRemaining = (float)$ordDetail['NeededQuantity'];
                                                $isItemDelivered = $ordRemaining <= 0.0001;
                                            ?>
                                                <div style="background:white;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;display:flex;justify-content:space-between;align-items:center;<?= $isItemDelivered ? 'opacity:0.65;background:#f8fafc' : '' ?>">
                                                    <div>
                                                        <div style="font-weight:600;font-size:12px"><?= e($ordDetail['ItemName']) ?></div>
                                                        <div class="muted" style="font-size:11px">
                                                            <?= e($ordDetail['ItemCode']) ?> · 
                                                            Ordered: <?= number_format((float)$ordDetail['OrderedQuantity'], 3) ?> <?= e($ordDetail['UnitSymbol']) ?>
                                                            <?php if ($isItemDelivered): ?>
                                                                · <span style="color:#16a34a;font-weight:600">✓ Delivered</span>
                                                            <?php else: ?>
                                                                · <strong style="color:#e11d48"><?= number_format($ordRemaining, 3) ?> needed</strong>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php if (!$isItemDelivered): ?>
                                                        <button type="button" class="btn secondary btn-add-demand-item" 
                                                            data-item-id="<?= (int)$ordDetail['ItemID'] ?>" 
                                                            data-item-name="<?= e($ordDetail['ItemName']) ?>" 
                                                            data-qty="<?= $ordRemaining ?>"
                                                            style="padding:4px 7px;font-size:10px">
                                                            + Add (<?= number_format($ordRemaining, 1) ?>)
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="pill" style="background:#e8f8ef;color:#16a34a;font-size:10px">✓ Done</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="empty">No confirmed hotel orders found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1">
                                    <td colspan="4"><strong>TOTALS (<?= count($hotelOrders) ?> orders)</strong></td>
                                    <td><span class="pill status-pill-confirmed"><?= $activeOrdersPendingCount ?> pending delivery</span></td>
                                    <td style="text-align:right"><strong><?= number_format($sumHotelOrdersOrderedQty, 2) ?></strong></td>
                                    <td style="text-align:right"><strong style="color:#e11d48;font-size:13px"><?= number_format($sumHotelOrdersNeededQty, 2) ?></strong></td>
                                    <td class="print-only"></td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- PRINT ONLY SIGN-OFF FOOTER -->
                <div class="print-only demand-print-footer" style="margin-top:35px;padding-top:15px;border-top:1px dashed #94a3b8;page-break-inside:avoid">
                    <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:20px;font-size:11px;color:#334155">
                        <div>
                            <div style="margin-bottom:35px"><strong>Prepared By:</strong></div>
                            <div style="border-top:1px solid #64748b;padding-top:4px">Signature / Date</div>
                        </div>
                        <div>
                            <div style="margin-bottom:35px"><strong>Market Purchasing Officer:</strong></div>
                            <div style="border-top:1px solid #64748b;padding-top:4px">Signature / Date</div>
                        </div>
                        <div>
                            <div style="margin-bottom:35px"><strong>Store Receiving &amp; Verification:</strong></div>
                            <div style="border-top:1px solid #64748b;padding-top:4px">Signature / Date</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PANEL 2: PURCHASE HISTORY -->
            <section class="panel no-print" id="purchaseHistoryPanel">
                <h2>Purchase History</h2>

                <form method="get" action="purchases.php" class="filters">
                    <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search vendor, receipt, or ID...">
                    <input type="date" name="date_from" value="<?= e($dateFrom) ?>" title="From date">
                    <input type="date" name="date_to" value="<?= e($dateTo) ?>" title="To date">
                    <button type="submit" class="btn primary">Search</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Purchase</th>
                                <th>Vendor</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th>Total (LKR)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($purchases): foreach ($purchases as $purchase): ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$purchase['PurchaseID'] ?></strong>
                                    <?php if ($purchase['ReceiptNo']): ?>
                                        <br><span class="muted" style="font-size:11px"><?= e($purchase['ReceiptNo']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($purchase['VendorName']) ?></td>
                                <td><?= e($purchase['PurchaseDate']) ?></td>
                                <td><?= (int)$purchase['ItemCount'] ?> item(s)</td>
                                <td><strong><?= number_format((float)$purchase['TotalAmount'], 2) ?></strong></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn secondary" href="purchases.php?view=<?= (int)$purchase['PurchaseID'] ?>">View</a>
                                        <a class="btn secondary" href="purchases.php?edit=<?= (int)$purchase['PurchaseID'] ?>">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="empty">No purchases found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>
</div>

<script>
(function () {
    const rows = document.getElementById('purchaseRows');
    const addButton = document.getElementById('addPurchaseRow');
    const additionalInput = document.getElementById('additional_cost');

    function recalculate() {
        let subtotal = 0;

        rows.querySelectorAll('.purchase-line').forEach(function (row) {
            const quantity = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
            const unitCost = parseFloat(row.querySelector('[name="unit_cost[]"]').value) || 0;
            subtotal += quantity * unitCost;
        });

        const additional = Math.max(0, parseFloat(additionalInput.value) || 0);

        document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
        document.getElementById('additionalDisplay').textContent = additional.toFixed(2);
        document.getElementById('grandTotalDisplay').textContent = (subtotal + additional).toFixed(2);
    }

    function clearRow(row) {
        row.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
        row.querySelectorAll('input').forEach(el => el.value = '');
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            const first = rows.querySelector('.purchase-line');
            if (!first) return;

            const clone = first.cloneNode(true);
            clearRow(clone);
            rows.appendChild(clone);
            recalculate();
        });
    }

    rows.addEventListener('input', recalculate);
    rows.addEventListener('change', recalculate);

    rows.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-row');
        if (!button) return;

        const allRows = rows.querySelectorAll('.purchase-line');

        if (allRows.length === 1) {
            clearRow(allRows[0]);
        } else {
            button.closest('.purchase-line').remove();
        }

        recalculate();
    });

    additionalInput.addEventListener('input', recalculate);

    document.getElementById('purchaseForm').addEventListener('submit', function (event) {
        const seen = new Set();
        let duplicate = false;

        rows.querySelectorAll('select[name="item_id[]"]').forEach(function (select) {
            if (select.value && seen.has(select.value)) duplicate = true;
            if (select.value) seen.add(select.value);
        });

        if (duplicate) {
            event.preventDefault();
            alert('An item can only appear once in a purchase. Combine its quantity into one row.');
        }
    });

    recalculate();

    /* --- DEMAND TABS SWITCHING --- */
    document.querySelectorAll('.demand-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.demand-tab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const targetTab = this.getAttribute('data-tab');
            document.querySelectorAll('.demand-tab-content').forEach(function (content) {
                content.style.display = (content.id === targetTab) ? 'block' : 'none';
            });
        });
    });

    /* --- LIVE SEARCH FILTER FOR DEMAND TABLE --- */
    const demandSearchInput = document.getElementById('demandSearchInput');
    if (demandSearchInput) {
        demandSearchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            document.querySelectorAll('#demandTable tbody tr.demand-row').forEach(function (row) {
                const searchData = row.getAttribute('data-search') || '';
                row.style.display = (query === '' || searchData.includes(query)) ? '' : 'none';
            });
        });
    }

    /* --- TOGGLE ORDER ITEMS DETAILS ACCORDION --- */
    document.querySelectorAll('.btn-toggle-order-items').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const targetRow = document.getElementById(targetId);
            if (targetRow) {
                const isHidden = targetRow.style.display === 'none';
                targetRow.style.display = isHidden ? 'table-row' : 'none';
                this.textContent = isHidden ? '▲ Hide' : '▼ Items';
            }
        });
    });

    /* --- ADD SINGLE ITEM TO PURCHASE FORM --- */
    window.addItemToPurchase = function (itemId, quantity, itemName) {
        itemId = String(itemId);
        quantity = parseFloat(quantity) || 0;
        if (!itemId || quantity <= 0) return;

        let targetRow = null;

        // Check if item already exists in a row
        rows.querySelectorAll('.purchase-line').forEach(function (row) {
            const sel = row.querySelector('[name="item_id[]"]');
            if (sel && sel.value === itemId) {
                targetRow = row;
            }
        });

        if (targetRow) {
            // Update quantity
            const qtyInput = targetRow.querySelector('[name="quantity[]"]');
            qtyInput.value = quantity;
        } else {
            // Check if there is an empty line
            const allLines = rows.querySelectorAll('.purchase-line');
            for (let i = 0; i < allLines.length; i++) {
                const sel = allLines[i].querySelector('[name="item_id[]"]');
                const qty = allLines[i].querySelector('[name="quantity[]"]');
                if (!sel.value && !qty.value) {
                    targetRow = allLines[i];
                    break;
                }
            }

            if (!targetRow) {
                const first = rows.querySelector('.purchase-line');
                if (first) {
                    targetRow = first.cloneNode(true);
                    clearRow(targetRow);
                    rows.appendChild(targetRow);
                }
            }

            if (targetRow) {
                const sel = targetRow.querySelector('[name="item_id[]"]');
                sel.value = itemId;
                const qtyInput = targetRow.querySelector('[name="quantity[]"]');
                qtyInput.value = quantity;
            }
        }

        if (targetRow) {
            recalculate();
            targetRow.style.transition = 'all 0.3s ease';
            targetRow.style.outline = '2px solid #22c55e';
            targetRow.style.backgroundColor = '#f0fdf4';
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function () {
                targetRow.style.outline = 'none';
                targetRow.style.backgroundColor = '';
            }, 1500);
        }
    };

    // Bind all single item add buttons
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-add-demand-item');
        if (!btn) return;
        const itemId = btn.getAttribute('data-item-id');
        const itemName = btn.getAttribute('data-item-name');
        const qty = btn.getAttribute('data-qty');
        window.addItemToPurchase(itemId, qty, itemName);
    });

    /* --- ADD ALL NEEDED ITEMS TO PURCHASE FORM --- */
    const btnTransferAll = document.getElementById('btnTransferAllDemand');
    if (btnTransferAll) {
        btnTransferAll.addEventListener('click', function () {
            let items = [];
            try {
                items = JSON.parse(this.getAttribute('data-items') || '[]');
            } catch (err) {
                console.error(err);
            }

            if (!items.length) {
                alert('No needed items found to add.');
                return;
            }

            if (!confirm(`Are you sure you want to add all ${items.length} required item(s) to the purchase form?`)) {
                return;
            }

            items.forEach(function (item) {
                window.addItemToPurchase(item.id, item.qty, item.name);
            });

            alert(`Successfully loaded ${items.length} item(s) with required quantities into the purchase form.`);
        });
    }

    /* --- ADD ALL ITEMS OF A SINGLE ORDER --- */
    document.querySelectorAll('.btn-add-order-all').forEach(function (btn) {
        btn.addEventListener('click', function () {
            let items = [];
            try {
                items = JSON.parse(this.getAttribute('data-items') || '[]');
            } catch (err) {
                console.error(err);
            }

            if (!items.length) {
                alert('No items found in this order.');
                return;
            }

            items.forEach(function (item) {
                window.addItemToPurchase(item.id, item.qty, item.name);
            });

            alert(`Added ${items.length} item(s) from this order into the purchase form.`);
        });
    });

    /* --- PRINT HOTEL ORDER REQUIREMENTS --- */
    window.executeDemandPrint = function (mode) {
        const panel = document.getElementById('hotelDemandPanel');
        if (!panel) return;

        panel.classList.remove('print-mode-all', 'print-mode-items-only', 'print-mode-orders-only');
        if (mode === 'items') {
            panel.classList.add('print-mode-items-only');
        } else if (mode === 'orders') {
            panel.classList.add('print-mode-orders-only');
        } else {
            panel.classList.add('print-mode-all');
        }

        const menu = document.getElementById('printDropdownMenu');
        if (menu) menu.style.display = 'none';

        setTimeout(function () {
            window.print();
        }, 50);
    };

    window.togglePrintMenu = function (event) {
        if (event) event.stopPropagation();
        const menu = document.getElementById('printDropdownMenu');
        if (menu) {
            menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
        }
    };

    document.addEventListener('click', function (e) {
        const menu = document.getElementById('printDropdownMenu');
        const toggleBtn = document.getElementById('btnPrintMenuToggle');
        if (menu && menu.style.display === 'block') {
            if (!toggleBtn || !toggleBtn.contains(e.target)) {
                menu.style.display = 'none';
            }
        }
    });
})();
</script>
</body>
</html>
