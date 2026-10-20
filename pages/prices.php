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

function priceFlash(string $type, string $text): void
{
    $_SESSION['price_flash'] = ['type' => $type, 'text' => $text];
}

function priceRedirect(string $url = 'prices.php'): void
{
    header('Location: ' . $url);
    exit;
}

function validDate(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function validatePricePeriod(string $from, string $to): void
{
    if (!validDate($from)) {
        throw new RuntimeException('Please enter a valid effective start date.');
    }

    if ($to !== '' && !validDate($to)) {
        throw new RuntimeException('Please enter a valid effective end date.');
    }

    if ($to !== '' && $to < $from) {
        throw new RuntimeException('The end date cannot be earlier than the start date.');
    }
}

/**
 * Verify that an item has no overlapping period in another active price list.
 */
function checkPriceOverlaps(
    PDO $pdo,
    array $rows,
    string $from,
    ?string $to,
    int $excludeId = 0
): void {
    $stmt = $pdo->prepare("
        SELECT
            d.ItemID,
            i.ItemCode,
            p.PriceListName,
            p.EffectiveFrom,
            p.EffectiveTo
        FROM price_list_detail d
        INNER JOIN price_list p ON p.PriceListID = d.PriceListID
        INNER JOIN item i ON i.ItemID = d.ItemID
        WHERE p.Status = 'Active'
          AND p.PriceListID <> ?
    ");
    $stmt->execute([$excludeId]);

    $selectedIds = array_fill_keys(
        array_map('intval', array_column($rows, 'ItemID')),
        true
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
        if (!isset($selectedIds[(int)$existing['ItemID']])) {
            continue;
        }

        $oldFrom = $existing['EffectiveFrom'];
        $oldTo = $existing['EffectiveTo'];

        $overlaps = ($oldTo === null || $oldTo === '' || $from <= $oldTo)
            && ($to === null || $to === '' || $oldFrom <= $to);

        if ($overlaps) {
            throw new RuntimeException(
                'Overlapping active price period for item ' .
                $existing['ItemCode'] . ' in "' .
                $existing['PriceListName'] . '".'
            );
        }
    }
}

/**
 * Validate rows submitted through the manual form.
 */
function validateManualRows(array $ids, array $prices): array
{
    if (count($ids) !== count($prices)) {
        throw new RuntimeException('Invalid item price data.');
    }

    $rows = [];
    $seen = [];

    foreach ($ids as $index => $id) {
        $id = trim((string)$id);
        $price = trim((string)$prices[$index]);

        if ($id === '' && $price === '') {
            continue;
        }

        if ($id === '' || $price === '') {
            throw new RuntimeException('Select an item and enter its price.');
        }

        if (!ctype_digit($id) || (int)$id <= 0) {
            throw new RuntimeException('Invalid item selected.');
        }

        if (!is_numeric($price) || !is_finite((float)$price) ||
            (float)$price <= 0 || (float)$price > 99999999.99) {
            throw new RuntimeException('Unit prices must be greater than zero and within the supported range.');
        }

        $id = (int)$id;

        if (isset($seen[$id])) {
            throw new RuntimeException('An item cannot appear twice in the same price list.');
        }

        $seen[$id] = true;
        $rows[] = ['ItemID' => $id, 'UnitPrice' => round((float)$price, 2)];
    }

    if (!$rows) {
        throw new RuntimeException('Add at least one item and its price.');
    }

    return $rows;
}

/**
 * Load active items selected by their ItemIDs.
 */
function validateActiveItemIds(PDO $pdo, array $rows): void
{
    $ids = array_map('intval', array_column($rows, 'ItemID'));

    if (!$ids) {
        throw new RuntimeException('No items were supplied.');
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

    if (count($validIds) !== count(array_unique($ids))) {
        throw new RuntimeException('One or more selected items, units, or categories are inactive or unavailable.');
    }
}

/**
 * Insert a new draft price list.
 */
function createDraft(
    PDO $pdo,
    string $name,
    string $from,
    string $to,
    string $notes
): int {
    $stmt = $pdo->prepare("
        INSERT INTO price_list
            (PriceListName, EffectiveFrom, EffectiveTo, Status, Notes)
        VALUES (?, ?, ?, 'Draft', ?)
    ");

    $stmt->execute([
        $name,
        $from,
        $to !== '' ? $to : null,
        $notes !== '' ? $notes : null
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Replace all detail rows for a draft.
 */
function replaceDraftDetails(PDO $pdo, int $priceListId, array $rows): void
{
    validateActiveItemIds($pdo, $rows);

    $delete = $pdo->prepare("
        DELETE FROM price_list_detail
        WHERE PriceListID = ?
    ");
    $delete->execute([$priceListId]);

    $insert = $pdo->prepare("
        INSERT INTO price_list_detail (PriceListID, ItemID, UnitPrice)
        VALUES (?, ?, ?)
    ");

    foreach ($rows as $row) {
        $insert->execute([
            $priceListId,
            (int)$row['ItemID'],
            round((float)$row['UnitPrice'], 2)
        ]);
    }
}

/* -------------------- CSV DOWNLOAD TEMPLATE -------------------- */
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="price_list_template.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ItemCode', 'ItemName', 'UnitPrice']);

    $itemsStmt = $pdo->query("
        SELECT i.ItemCode, i.ItemName,
               (
                   SELECT pld.UnitPrice
                   FROM price_list_detail pld
                   INNER JOIN price_list pl ON pl.PriceListID = pld.PriceListID
                   WHERE pld.ItemID = i.ItemID
                     AND pl.Status = 'Active'
                     AND CURDATE() >= pl.EffectiveFrom
                     AND (pl.EffectiveTo IS NULL OR pl.EffectiveTo >= CURDATE())
                   ORDER BY pl.PriceListID DESC
                   LIMIT 1
               ) AS CurrentPrice
        FROM item i
        INNER JOIN unit u ON u.UnitID = i.UnitID
        INNER JOIN item_category c ON c.CategoryID = i.CategoryID
        WHERE i.IsActive = 1 AND u.IsActive = 1 AND c.IsActive = 1
        ORDER BY c.CategoryName, i.ItemName
    ");
    $activeItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($activeItems)) {
        foreach ($activeItems as $item) {
            $price = ($item['CurrentPrice'] !== null && $item['CurrentPrice'] !== '')
                ? number_format((float)$item['CurrentPrice'], 2, '.', '')
                : '';
            fputcsv($out, [
                $item['ItemCode'],
                $item['ItemName'],
                $price
            ]);
        }
    } else {
        fputcsv($out, ['FRU001', 'Avocado', '250.00']);
        fputcsv($out, ['VEG001', 'Carrot', '180.00']);
    }

    fclose($out);
    exit;
}

/* -------------------- CSV EXPORT -------------------- */
if (isset($_GET['export'])) {
    $exportId = (int)$_GET['export'];

    $listStmt = $pdo->prepare("
        SELECT PriceListName
        FROM price_list
        WHERE PriceListID = ?
    ");
    $listStmt->execute([$exportId]);
    $exportList = $listStmt->fetch(PDO::FETCH_ASSOC);

    if (!$exportList) {
        http_response_code(404);
        exit('Price list not found.');
    }

    $detailStmt = $pdo->prepare("
        SELECT i.ItemCode, i.ItemName, d.UnitPrice
        FROM price_list_detail d
        INNER JOIN item i ON i.ItemID = d.ItemID
        WHERE d.PriceListID = ?
        ORDER BY i.ItemCode
    ");
    $detailStmt->execute([$exportId]);

    $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $exportList['PriceListName']);
    $filename = 'price_list_' . $exportId . ($safeName !== '' ? '_' . $safeName : '') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ItemCode', 'ItemName', 'UnitPrice']);

    while ($row = $detailStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['ItemCode'],
            $row['ItemName'],
            number_format((float)$row['UnitPrice'], 2, '.', '')
        ]);
    }

    fclose($out);
    exit;
}

/* -------------------- CSV IMPORT -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'import_csv') {

    try {
        if (!hash_equals(
            $_SESSION['csrf_token'],
            (string)($_POST['csrf_token'] ?? '')
        )) {
            throw new RuntimeException('Invalid session token. Refresh and try again.');
        }

        if (!isset($_FILES['csv_file']) ||
            $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please select a valid CSV file.');
        }

        $file = $_FILES['csv_file'];

        if ((int)$file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('The CSV file cannot exceed 5 MB.');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            throw new RuntimeException('Only CSV files are supported.');
        }

        $handle = fopen($file['tmp_name'], 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                throw new RuntimeException('The CSV file is empty.');
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

            $normalizedHeader = array_map(
                static fn($value) => strtolower(preg_replace('/[\s_\-]+/', '', (string)$value)),
                $header
            );

            $codeIndex = array_search('itemcode', $normalizedHeader, true);
            if ($codeIndex === false) {
                $codeIndex = array_search('code', $normalizedHeader, true);
            }

            $priceIndex = array_search('unitprice', $normalizedHeader, true);
            if ($priceIndex === false) {
                $priceIndex = array_search('price', $normalizedHeader, true);
            }

            if ($codeIndex === false || $priceIndex === false) {
                throw new RuntimeException(
                    'CSV headers must include ItemCode (or Code) and UnitPrice. Download the template for the correct format.'
                );
            }

            $itemsStmt = $pdo->query("
                SELECT
                    i.ItemID,
                    i.ItemCode,
                    i.ItemName,
                    i.IsActive,
                    u.IsActive AS UnitActive,
                    c.IsActive AS CategoryActive
                FROM item i
                INNER JOIN unit u ON u.UnitID = i.UnitID
                INNER JOIN item_category c ON c.CategoryID = i.CategoryID
            ");

            $itemMap = [];

            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $key = strtoupper(trim($item['ItemCode']));

                $itemMap[$key] = [
                    'ItemID' => (int)$item['ItemID'],
                    'ItemCode' => $item['ItemCode'],
                    'ItemName' => $item['ItemName'],
                    'Active' => (int)$item['IsActive'] === 1
                        && (int)$item['UnitActive'] === 1
                        && (int)$item['CategoryActive'] === 1
                ];
            }

            $rows = [];
            $seenCodes = [];
            $lineNumber = 1;

            while (($csvRow = fgetcsv($handle)) !== false) {
                $lineNumber++;

                if ($csvRow === [null] || $csvRow === [] || $csvRow === ['']) {
                    continue;
                }

                $code = trim((string)($csvRow[$codeIndex] ?? ''));
                $priceValue = trim((string)($csvRow[$priceIndex] ?? ''));

                if ($code === '' && $priceValue === '') {
                    continue;
                }

                // If user leaves UnitPrice blank for items they don't want to include, skip them
                if ($code !== '' && $priceValue === '') {
                    continue;
                }

                if ($code === '') {
                    throw new RuntimeException("CSV row {$lineNumber}: ItemCode is required.");
                }

                $codeKey = strtoupper($code);

                if (isset($seenCodes[$codeKey])) {
                    throw new RuntimeException("CSV row {$lineNumber}: duplicate ItemCode '{$code}'.");
                }

                if (!isset($itemMap[$codeKey])) {
                    throw new RuntimeException("CSV row {$lineNumber}: ItemCode '{$code}' does not exist.");
                }

                if (!$itemMap[$codeKey]['Active']) {
                    throw new RuntimeException("CSV row {$lineNumber}: item '{$code}' or its unit/category is inactive.");
                }

                if (!is_numeric($priceValue) ||
                    !is_finite((float)$priceValue) ||
                    (float)$priceValue <= 0 ||
                    (float)$priceValue > 99999999.99) {
                    throw new RuntimeException("CSV row {$lineNumber}: invalid UnitPrice for '{$code}'.");
                }

                $seenCodes[$codeKey] = true;

                $rows[] = [
                    'ItemID' => $itemMap[$codeKey]['ItemID'],
                    'UnitPrice' => round((float)$priceValue, 2)
                ];
            }
        } finally {
            fclose($handle);
        }

        if (!$rows) {
            throw new RuntimeException('The CSV contains no item prices.');
        }

        $targetId = (int)($_POST['price_list_id'] ?? 0);

        $name = trim((string)($_POST['price_list_name'] ?? ''));
        $from = trim((string)($_POST['effective_from'] ?? ''));
        $to = trim((string)($_POST['effective_to'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $pdo->beginTransaction();

        if ($targetId > 0) {
            $lock = $pdo->prepare("
                SELECT *
                FROM price_list
                WHERE PriceListID = ?
                FOR UPDATE
            ");
            $lock->execute([$targetId]);
            $target = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('The selected price list does not exist.');
            }

            if ($target['Status'] !== 'Draft') {
                throw new RuntimeException('CSV imports can only update draft price lists.');
            }

            if ($name === '') {
                $name = $target['PriceListName'];
            }
            if ($from === '') {
                $from = $target['EffectiveFrom'];
            }
            if ($to === '') {
                $to = $target['EffectiveTo'] ?? '';
            }
            if ($notes === '') {
                $notes = $target['Notes'] ?? '';
            }

            validatePricePeriod($from, $to);

            if (strlen($name) > 100 || $name === '') {
                throw new RuntimeException('Enter a price list name of up to 100 characters.');
            }

            if (strlen($notes) > 2000) {
                throw new RuntimeException('Notes cannot exceed 2000 characters.');
            }

            $update = $pdo->prepare("
                UPDATE price_list
                SET PriceListName = ?, EffectiveFrom = ?, EffectiveTo = ?, Notes = ?
                WHERE PriceListID = ? AND Status = 'Draft'
            ");
            $update->execute([
                $name,
                $from,
                $to !== '' ? $to : null,
                $notes !== '' ? $notes : null,
                $targetId
            ]);

            replaceDraftDetails($pdo, $targetId, $rows);
            $message = 'CSV imported. Draft price list updated with ' . count($rows) . ' item prices.';
        } else {
            validatePricePeriod($from, $to);

            if ($name === '' || strlen($name) > 100) {
                throw new RuntimeException('Enter a price list name of up to 100 characters.');
            }

            if (strlen($notes) > 2000) {
                throw new RuntimeException('Notes cannot exceed 2000 characters.');
            }

            validateActiveItemIds($pdo, $rows);

            $targetId = createDraft($pdo, $name, $from, $to, $notes);
            replaceDraftDetails($pdo, $targetId, $rows);

            $message = 'CSV imported. New draft price list created with ' . count($rows) . ' item prices.';
        }

        $pdo->commit();

        priceFlash('success', $message);
        priceRedirect('prices.php?edit=' . $targetId);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        priceFlash('error', $ex->getMessage());
        priceRedirect();
    }
}

/* -------------------- MANUAL SAVE / UPDATE DRAFT -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save') {

    try {
        if (!hash_equals(
            $_SESSION['csrf_token'],
            (string)($_POST['csrf_token'] ?? '')
        )) {
            throw new RuntimeException('Invalid session token. Refresh and try again.');
        }

        $id = (int)($_POST['price_list_id'] ?? 0);
        $name = trim((string)($_POST['price_list_name'] ?? ''));
        $from = trim((string)($_POST['effective_from'] ?? ''));
        $to = trim((string)($_POST['effective_to'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($name === '' || strlen($name) > 100) {
            throw new RuntimeException('Enter a price list name of up to 100 characters.');
        }

        if (strlen($notes) > 2000) {
            throw new RuntimeException('Notes cannot exceed 2000 characters.');
        }

        validatePricePeriod($from, $to);

        $rows = validateManualRows(
            $_POST['item_id'] ?? [],
            $_POST['unit_price'] ?? []
        );

        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT Status FROM price_list
                WHERE PriceListID = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $status = $stmt->fetchColumn();

            if ($status !== 'Draft') {
                throw new RuntimeException('Only draft price lists can be edited.');
            }

            $update = $pdo->prepare("
                UPDATE price_list
                SET PriceListName = ?, EffectiveFrom = ?, EffectiveTo = ?, Notes = ?
                WHERE PriceListID = ? AND Status = 'Draft'
            ");
            $update->execute([
                $name,
                $from,
                $to !== '' ? $to : null,
                $notes !== '' ? $notes : null,
                $id
            ]);
        } else {
            $id = createDraft($pdo, $name, $from, $to, $notes);
        }

        replaceDraftDetails($pdo, $id, $rows);
        $pdo->commit();

        priceFlash('success', 'Price list saved as a draft.');
        priceRedirect();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        priceFlash('error', $ex->getMessage());
        priceRedirect();
    }
}

/* -------------------- ACTIVATE / DEACTIVATE -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'change_status') {

    try {
        if (!hash_equals(
            $_SESSION['csrf_token'],
            (string)($_POST['csrf_token'] ?? '')
        )) {
            throw new RuntimeException('Invalid session token. Refresh and try again.');
        }

        $id = (int)($_POST['price_list_id'] ?? 0);
        $newStatus = (string)($_POST['new_status'] ?? '');

        if ($id <= 0 || !in_array($newStatus, ['Active', 'Inactive'], true)) {
            throw new RuntimeException('Invalid price list or status.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT * FROM price_list
            WHERE PriceListID = ?
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$list) {
            throw new RuntimeException('Price list not found.');
        }

        if ($newStatus === 'Inactive') {
            if ($list['Status'] !== 'Active') {
                throw new RuntimeException('Only active price lists can be deactivated.');
            }

            $update = $pdo->prepare("
                UPDATE price_list SET Status = 'Inactive'
                WHERE PriceListID = ?
            ");
            $update->execute([$id]);

            $pdo->commit();
            priceFlash('success', 'Price list deactivated.');
            priceRedirect();
        }

        if (!in_array($list['Status'], ['Draft', 'Inactive'], true)) {
            throw new RuntimeException('This price list is already active.');
        }

        $detailStmt = $pdo->prepare("
            SELECT ItemID, UnitPrice
            FROM price_list_detail
            WHERE PriceListID = ?
        ");
        $detailStmt->execute([$id]);
        $rows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            throw new RuntimeException('Add at least one item before activating this price list.');
        }

        validatePricePeriod(
            $list['EffectiveFrom'],
            $list['EffectiveTo'] ?? ''
        );
        validateActiveItemIds($pdo, $rows);

        checkPriceOverlaps(
            $pdo,
            $rows,
            $list['EffectiveFrom'],
            $list['EffectiveTo'],
            $id
        );

        $update = $pdo->prepare("
            UPDATE price_list SET Status = 'Active'
            WHERE PriceListID = ?
        ");
        $update->execute([$id]);

        $pdo->commit();
        priceFlash('success', 'Price list activated successfully.');
        priceRedirect();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        priceFlash('error', $ex->getMessage());
        priceRedirect();
    }
}

/* -------------------- LOAD ACTIVE ITEMS -------------------- */
$itemStmt = $pdo->query("
    SELECT i.ItemID, i.ItemCode, i.ItemName, u.UnitSymbol, c.CategoryName
    FROM item i
    INNER JOIN unit u ON u.UnitID = i.UnitID
    INNER JOIN item_category c ON c.CategoryID = i.CategoryID
    WHERE i.IsActive = 1 AND u.IsActive = 1 AND c.IsActive = 1
    ORDER BY c.CategoryName, i.ItemName
");
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- LOAD SELECTED DRAFT FOR EDITING -------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editList = null;
$editRows = [];

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM price_list WHERE PriceListID = ?");
    $stmt->execute([$editId]);
    $editList = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editList || $editList['Status'] !== 'Draft') {
        priceFlash('error', 'Only draft price lists can be edited.');
        priceRedirect();
    }

    $stmt = $pdo->prepare("
        SELECT ItemID, UnitPrice
        FROM price_list_detail
        WHERE PriceListID = ?
        ORDER BY PriceListDetailID
    ");
    $stmt->execute([$editId]);
    $editRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$editRows) {
    $editRows = [['ItemID' => '', 'UnitPrice' => '']];
}

/* -------------------- VIEW PRICE LIST DETAILS -------------------- */
$viewId = (int)($_GET['view'] ?? 0);
$viewList = null;
$viewRows = [];

if ($viewId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM price_list WHERE PriceListID = ?");
    $stmt->execute([$viewId]);
    $viewList = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($viewList) {
        $stmt = $pdo->prepare("
            SELECT i.ItemCode, i.ItemName, c.CategoryName, u.UnitSymbol, d.UnitPrice
            FROM price_list_detail d
            INNER JOIN item i ON i.ItemID = d.ItemID
            INNER JOIN unit u ON u.UnitID = i.UnitID
            INNER JOIN item_category c ON c.CategoryID = i.CategoryID
            WHERE d.PriceListID = ?
            ORDER BY c.CategoryName, i.ItemName
        ");
        $stmt->execute([$viewId]);
        $viewRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* -------------------- SEARCH AND SUMMARY -------------------- */
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'p.PriceListName LIKE ?';
    $params[] = '%' . $search . '%';
}

if (in_array($statusFilter, ['Draft', 'Active', 'Inactive'], true)) {
    $where[] = 'p.Status = ?';
    $params[] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT p.*, COUNT(d.PriceListDetailID) AS ItemCount
    FROM price_list p
    LEFT JOIN price_list_detail d ON d.PriceListID = p.PriceListID
    $whereSql
    GROUP BY p.PriceListID
    ORDER BY p.CreatedAt DESC, p.PriceListID DESC
");
$stmt->execute($params);
$priceLists = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalLists = (int)$pdo->query("SELECT COUNT(*) FROM price_list")->fetchColumn();
$activeLists = (int)$pdo->query("SELECT COUNT(*) FROM price_list WHERE Status = 'Active'")->fetchColumn();
$draftLists = (int)$pdo->query("SELECT COUNT(*) FROM price_list WHERE Status = 'Draft'")->fetchColumn();
$inactiveLists = (int)$pdo->query("SELECT COUNT(*) FROM price_list WHERE Status = 'Inactive'")->fetchColumn();

$flash = $_SESSION['price_flash'] ?? null;
unset($_SESSION['price_flash']);

$formName = $editList['PriceListName'] ?? '';
$formFrom = $editList['EffectiveFrom'] ?? date('Y-m-d');
$formTo = $editList['EffectiveTo'] ?? '';
$formNotes = $editList['Notes'] ?? '';
$isEditing = $editList !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Price List Management | Hotel Supply</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
.page-title{margin-bottom:24px}
.page-title h1{margin:0 0 8px;font-size:26px}
.page-title p,.muted{color:#778198;font-size:13px}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:20px}
.card,.panel{background:white;border:1px solid #e8ebf2;border-radius:12px;padding:20px}
.card span{font-size:12px;color:#778198}
.card strong{display:block;font-size:25px;margin-top:8px}
.layout{display:grid;grid-template-columns:minmax(330px,.9fr) minmax(0,1.6fr);gap:20px;align-items:start}
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
.filters{display:grid;grid-template-columns:minmax(0,1fr) 140px auto;gap:8px;margin-bottom:16px}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:12px}
th,td{text-align:left;padding:12px 9px;border-bottom:1px solid #edf0f5;vertical-align:middle}
th{background:#f7f8fc;color:#778198;font-size:10px;text-transform:uppercase}
td{white-space:nowrap}
td.name{white-space:normal;min-width:140px}
.pill{border-radius:20px;padding:5px 8px;font-size:10px;font-weight:bold}
.pill.active,.pill.on{background:#e5f8ed;color:#197547}
.pill.inactive,.pill.off{background:#f0f1f4;color:#687184}
.pill.draft{background:#fef3c7;color:#92400e}
.actions{display:flex;gap:6px;align-items:center}
.actions form{margin:0}
.empty{text-align:center;color:#778198;padding:25px}

/* Price Line Editor */
.price-line{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(90px,1fr) 36px;gap:7px;align-items:end;margin-bottom:10px}
.price-line .field{margin:0}
.remove-row{height:40px;width:36px;border:1px solid #fed7d7;background:#fff5f5;color:#c53030;border-radius:7px;cursor:pointer;font-size:18px;display:inline-flex;align-items:center;justify-content:center;transition:0.15s}
.remove-row:hover{background:#feb2b2}

@media(max-width:1050px){
    .layout{grid-template-columns:1fr}
    .cards{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:650px){
    .cards{grid-template-columns:1fr}
    .filters{grid-template-columns:1fr}
    .panel{padding:14px}
    .price-line{grid-template-columns:minmax(0,1fr) minmax(80px,.8fr) 32px;gap:5px}
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
    <a class="nav-link active" href="prices.php">💰 &nbsp; Price Lists</a>
    <a class="nav-link" href="purchases.php">🧾 &nbsp; Market Purchases</a>
    <a class="nav-link" href="orders.php">📋 &nbsp; Hotel Orders</a>
    <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
    <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
    <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
    <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
</aside>

<main class="main">
    <div class="page-title">
        <h1>Price List Management</h1>
        <p>Manage hotel selling prices, effective date periods, and CSV price lists.</p>
    </div>

    <?php if ($flash): ?>
        <div class="notice <?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="cards">
        <div class="card">
            <span>Total price lists</span>
            <strong><?= $totalLists ?></strong>
        </div>
        <div class="card">
            <span>Active lists</span>
            <strong><?= $activeLists ?></strong>
        </div>
        <div class="card">
            <span>Draft lists</span>
            <strong><?= $draftLists ?></strong>
        </div>
        <div class="card">
            <span>Inactive lists</span>
            <strong><?= $inactiveLists ?></strong>
        </div>
    </div>

    <!-- VIEW DETAILS PANEL -->
    <?php if ($viewList): ?>
        <section class="panel" style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:15px;flex-wrap:wrap">
                <div>
                    <h2 style="margin:0 0 5px"><?= e($viewList['PriceListName']) ?></h2>
                    <p class="muted" style="margin:0">
                        Effective period: <?= e($viewList['EffectiveFrom']) ?> to
                        <?= e($viewList['EffectiveTo'] ?: 'No end date') ?>
                    </p>
                </div>
                <div class="actions">
                    <span class="pill <?= strtolower($viewList['Status']) ?>"><?= e($viewList['Status']) ?></span>
                    <a class="btn secondary" href="prices.php?export=<?= (int)$viewList['PriceListID'] ?>">Export CSV</a>
                    <a class="btn secondary" href="prices.php">Close</a>
                </div>
            </div>

            <?php if ($viewList['Notes']): ?>
                <p class="muted" style="margin:0 0 15px;background:#f8fafc;padding:10px 12px;border-radius:6px;border:1px solid #edf0f5"><?= nl2br(e($viewList['Notes'])) ?></p>
            <?php endif; ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th>Unit Price (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($viewRows): foreach ($viewRows as $row): ?>
                        <tr>
                            <td><strong><?= e($row['ItemCode']) ?></strong></td>
                            <td><?= e($row['ItemName']) ?></td>
                            <td><?= e($row['CategoryName']) ?></td>
                            <td><?= e($row['UnitSymbol']) ?></td>
                            <td><strong><?= number_format((float)$row['UnitPrice'], 2) ?></strong></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="empty">No items in this price list.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <div class="layout">
        <!-- LEFT PANEL: CREATE / EDIT DRAFT FORM -->
        <section class="panel">
            <h2><?= $isEditing ? 'Edit Draft Price List' : 'Create Price List' ?></h2>

            <?php if (!$items): ?>
                <div class="notice error">Add active items before creating a price list.</div>
            <?php endif; ?>

            <form method="post" action="prices.php" id="manualForm">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="price_list_id" value="<?= $isEditing ? (int)$editList['PriceListID'] : 0 ?>">

                <div class="field">
                    <label for="price_list_name">Price List Name *</label>
                    <input id="price_list_name" name="price_list_name" maxlength="100" required value="<?= e($formName) ?>" placeholder="e.g. October 2026 Price List">
                </div>

                <div class="field">
                    <label for="effective_from">Effective From *</label>
                    <input type="date" id="effective_from" name="effective_from" required value="<?= e($formFrom) ?>">
                </div>

                <div class="field">
                    <label for="effective_to">Effective To</label>
                    <input type="date" id="effective_to" name="effective_to" value="<?= e($formTo) ?>">
                    <span class="muted" style="font-size:11px;display:block;margin-top:4px">Leave blank for no end date.</span>
                </div>

                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" maxlength="2000" placeholder="Optional notes"><?= e($formNotes) ?></textarea>
                </div>

                <div style="border-top:1px solid #edf0f5;padding-top:16px;margin-top:14px">
                    <h3 style="font-size:14px;margin:0 0 4px">Item Selling Prices</h3>
                    <p class="muted" style="margin:0 0 12px;font-size:12px">Select an item and enter its selling price. Each item can appear only once.</p>

                    <div id="priceRows">
                    <?php foreach ($editRows as $row): ?>
                        <div class="price-line">
                            <div class="field">
                                <label>Item</label>
                                <select name="item_id[]" required>
                                    <option value="">Select item</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= (int)$item['ItemID'] ?>" <?= (string)$row['ItemID'] === (string)$item['ItemID'] ? 'selected' : '' ?>>
                                            <?= e($item['ItemCode']) ?> - <?= e($item['ItemName']) ?> (<?= e($item['UnitSymbol']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>Unit Price</label>
                                <input type="number" name="unit_price[]" min="0.01" max="99999999.99" step="0.01" required value="<?= e($row['UnitPrice']) ?>" placeholder="0.00">
                            </div>
                            <button type="button" class="remove-row" aria-label="Remove item">×</button>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn secondary" id="addPriceRow" style="margin-top:6px">+ Add Item</button>
                </div>

                <div class="actions" style="margin-top:20px">
                    <button type="submit" class="btn primary" <?= !$items ? 'disabled' : '' ?>>
                        <?= $isEditing ? 'Update Draft' : 'Save as Draft' ?>
                    </button>
                    <?php if ($isEditing): ?>
                        <a class="btn secondary" href="prices.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- CSV IMPORT SECTION -->
            <div style="border-top:1px solid #edf0f5;padding-top:20px;margin-top:24px">
                <h3 style="font-size:14px;margin:0 0 6px">Import Prices from CSV</h3>
                <p class="muted" style="margin:0 0 14px;font-size:12px;line-height:1.5">
                    CSV columns: <strong>ItemCode, ItemName, UnitPrice</strong>. For an existing draft, import replaces all item rows with the CSV rows.
                </p>

                <form method="post" action="prices.php" enctype="multipart/form-data" id="csvForm">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="import_csv">

                    <div class="field">
                        <label for="csv_target">Import destination *</label>
                        <select id="csv_target" name="price_list_id" required>
                            <option value="0">Create a new draft price list</option>
                            <?php
                            $draftStmt = $pdo->query("
                                SELECT PriceListID, PriceListName
                                FROM price_list
                                WHERE Status = 'Draft'
                                ORDER BY CreatedAt DESC
                            ");
                            foreach ($draftStmt->fetchAll(PDO::FETCH_ASSOC) as $draft):
                            ?>
                                <option value="<?= (int)$draft['PriceListID'] ?>" <?= $isEditing && (int)$editList['PriceListID'] === (int)$draft['PriceListID'] ? 'selected' : '' ?>>
                                    Update draft: <?= e($draft['PriceListName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="csvNewDetails">
                        <div class="field">
                            <label for="csv_name">New Price List Name *</label>
                            <input type="text" name="price_list_name" id="csv_name" maxlength="100" value="<?= e($formName) ?>" placeholder="e.g. November 2026 Price List">
                        </div>
                        <div class="field">
                            <label for="csv_from">Effective From *</label>
                            <input type="date" name="effective_from" id="csv_from" value="<?= e($formFrom) ?>">
                        </div>
                        <div class="field">
                            <label for="csv_to">Effective To</label>
                            <input type="date" name="effective_to" id="csv_to" value="<?= e($formTo) ?>">
                        </div>
                        <div class="field">
                            <label for="csv_notes">Notes</label>
                            <input type="text" name="notes" id="csv_notes" maxlength="2000" value="<?= e($formNotes) ?>" placeholder="Optional notes">
                        </div>
                    </div>

                    <div class="field">
                        <label for="csv_file">CSV File *</label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                    </div>

                    <div class="actions" style="margin-top:14px">
                        <button type="submit" class="btn primary">Import CSV</button>
                        <a href="prices.php?download_template=1" class="btn secondary">Download Template</a>
                    </div>
                </form>
            </div>
        </section>

        <!-- RIGHT PANEL: ALL PRICE LISTS -->
        <section class="panel">
            <h2>Price Lists</h2>

            <form method="get" action="prices.php" class="filters">
                <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search price lists...">
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="btn primary">Search</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Price List</th>
                            <th>Effective Period</th>
                            <th>Items</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($priceLists): foreach ($priceLists as $list): ?>
                        <tr>
                            <td class="name">
                                <strong><?= e($list['PriceListName']) ?></strong>
                                <br><span class="muted" style="font-size:11px">Created <?= e($list['CreatedAt'] ? date('Y-m-d', strtotime($list['CreatedAt'])) : '') ?></span>
                            </td>
                            <td>
                                <?= e($list['EffectiveFrom']) ?>
                                <br><span class="muted" style="font-size:11px"><?= e($list['EffectiveTo'] ?: 'No end date') ?></span>
                            </td>
                            <td><?= (int)$list['ItemCount'] ?></td>
                            <td>
                                <span class="pill <?= strtolower($list['Status']) ?>">
                                    <?= e($list['Status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="btn secondary" href="prices.php?view=<?= (int)$list['PriceListID'] ?>">View</a>
                                    <a class="btn secondary" href="prices.php?export=<?= (int)$list['PriceListID'] ?>">CSV</a>

                                    <?php if ($list['Status'] === 'Draft'): ?>
                                        <a class="btn secondary" href="prices.php?edit=<?= (int)$list['PriceListID'] ?>">Edit</a>
                                    <?php endif; ?>

                                    <?php if (in_array($list['Status'], ['Draft', 'Inactive'], true)): ?>
                                        <form method="post" action="prices.php" onsubmit="return confirm('Activate this price list?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="price_list_id" value="<?= (int)$list['PriceListID'] ?>">
                                            <input type="hidden" name="new_status" value="Active">
                                            <button type="submit" class="btn primary">Activate</button>
                                        </form>
                                    <?php elseif ($list['Status'] === 'Active'): ?>
                                        <form method="post" action="prices.php" onsubmit="return confirm('Deactivate this price list?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="price_list_id" value="<?= (int)$list['PriceListID'] ?>">
                                            <input type="hidden" name="new_status" value="Inactive">
                                            <button type="submit" class="btn danger">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="empty">No price lists found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted" style="font-size:11px;margin-top:14px;line-height:1.5">
                Only draft lists can be edited or updated through CSV. Active lists must be deactivated before their prices can be changed.
            </p>
        </section>
    </div>
</main>
</div>

<script>
(function () {
    const rows = document.getElementById('priceRows');
    const addButton = document.getElementById('addPriceRow');

    if (rows && addButton) {
        addButton.addEventListener('click', function () {
            const first = rows.querySelector('.price-line');
            if (!first) return;

            const clone = first.cloneNode(true);
            clone.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
            clone.querySelectorAll('input').forEach(el => el.value = '');
            rows.appendChild(clone);
        });

        rows.addEventListener('click', function (event) {
            const button = event.target.closest('.remove-row');
            if (!button) return;

            const allRows = rows.querySelectorAll('.price-line');

            if (allRows.length === 1) {
                allRows[0].querySelectorAll('select').forEach(el => el.selectedIndex = 0);
                allRows[0].querySelectorAll('input').forEach(el => el.value = '');
                return;
            }

            button.closest('.price-line').remove();
        });

        document.getElementById('manualForm').addEventListener('submit', function (event) {
            const seen = new Set();
            let duplicate = false;

            rows.querySelectorAll('select[name="item_id[]"]').forEach(function (select) {
                if (select.value && seen.has(select.value)) duplicate = true;
                if (select.value) seen.add(select.value);
            });

            if (duplicate) {
                event.preventDefault();
                alert('Each item can only appear once in a price list.');
            }
        });
    }

    const target = document.getElementById('csv_target');
    const details = document.getElementById('csvNewDetails');

    function updateCsvFields() {
        if (!target || !details) return;

        const isNew = target.value === '0';
        details.style.display = isNew ? 'block' : 'none';

        ['csv_name', 'csv_from'].forEach(function (id) {
            const field = document.getElementById(id);
            if (field) field.required = isNew;
        });
    }

    if (target) {
        target.addEventListener('change', updateCsvFields);
        updateCsvFields();
    }

    const from = document.getElementById('effective_from');
    const to = document.getElementById('effective_to');
    if (from && to) {
        function updateDateMin() {
            to.min = from.value;
            if (to.value && from.value && to.value < from.value) to.value = '';
        }
        from.addEventListener('change', updateDateMin);
        updateDateMin();
    }

    const csvFrom = document.getElementById('csv_from');
    const csvTo = document.getElementById('csv_to');
    if (csvFrom && csvTo) {
        function updateCsvDateMin() {
            csvTo.min = csvFrom.value;
            if (csvTo.value && csvFrom.value && csvTo.value < csvFrom.value) csvTo.value = '';
        }
        csvFrom.addEventListener('change', updateCsvDateMin);
        updateCsvDateMin();
    }

    const csvForm = document.getElementById('csvForm');
    if (csvForm) {
        csvForm.addEventListener('submit', function (event) {
            const file = document.getElementById('csv_file');
            if (file.files.length && !file.files[0].name.toLowerCase().endsWith('.csv')) {
                event.preventDefault();
                alert('Please select a .csv file.');
            }
        });
    }
})();
</script>
</body>
</html>
