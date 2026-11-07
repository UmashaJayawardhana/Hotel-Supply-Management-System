<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

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

        $rawContent = file_get_contents($file['tmp_name']);
        if ($rawContent === false || trim($rawContent) === '') {
            throw new RuntimeException('The CSV file is empty.');
        }

        // 1. Detect and strip BOM (UTF-8, UTF-16)
        if (str_starts_with($rawContent, "\xEF\xBB\xBF")) {
            $rawContent = substr($rawContent, 3);
        } elseif (str_starts_with($rawContent, "\xFF\xFE") || str_starts_with($rawContent, "\xFE\xFF")) {
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', 'UTF-16');
        }

        // 2. Auto-detect delimiter by scanning lines
        $fileLines = preg_split('/\r\n|\r|\n/', trim($rawContent));
        $delimiters = [',', ';', "\t"];
        $bestDelimiter = ',';
        $maxDelimCount = 0;
        foreach ($fileLines as $fLine) {
            $trimmedLine = trim($fLine);
            if ($trimmedLine === '') continue;
            foreach ($delimiters as $delim) {
                $cnt = substr_count($trimmedLine, $delim);
                if ($cnt > $maxDelimCount) {
                    $maxDelimCount = $cnt;
                    $bestDelimiter = $delim;
                }
            }
            if ($maxDelimCount > 0) break;
        }

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $rawContent);
        rewind($handle);

        try {
            $header = null;
            $isFirstRowData = false;

            // Scan for the header row, skipping blank lines and single-cell title rows
            while (($row = fgetcsv($handle, 0, $bestDelimiter)) !== false) {
                if ($row === [null] || empty($row) || (count($row) === 1 && trim($row[0] ?? '') === '')) {
                    continue; // Skip blank lines
                }
                if (count($row) === 1 && $maxDelimCount > 0) {
                    continue; // Skip title row
                }
                $header = $row;
                break;
            }

            if (!$header) {
                throw new RuntimeException('The CSV file appears to be empty or has no readable lines.');
            }

            // Clean each header: remove quotes, BOM residue, non-alphanumeric chars
            $cleanHeader = array_map(function($col) {
                $col = preg_replace('/^\xEF\xBB\xBF/', '', (string)$col);
                $col = trim($col, " \t\n\r\0\x0B\"'");
                return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $col));
            }, $header);

            $codeIndex = false;
            $priceIndex = false;
            $nameIndex = false;

            foreach ($cleanHeader as $idx => $col) {
                if ($codeIndex === false && (
                    str_contains($col, 'code') || 
                    str_contains($col, 'itemno') || 
                    str_contains($col, 'itemnum') || 
                    $col === 'itemid' || 
                    $col === 'id' || 
                    $col === 'code' || 
                    $col === 'no'
                )) {
                    $codeIndex = $idx;
                    continue;
                }

                if ($priceIndex === false && (
                    str_contains($col, 'price') || 
                    str_contains($col, 'rate') || 
                    str_contains($col, 'cost') || 
                    str_contains($col, 'amount') || 
                    $col === 'unitprice' || 
                    $col === 'lkr' || 
                    $col === 'value'
                )) {
                    $priceIndex = $idx;
                    continue;
                }

                if ($nameIndex === false && (
                    str_contains($col, 'name') || 
                    str_contains($col, 'desc') || 
                    str_contains($col, 'item') || 
                    str_contains($col, 'product') || 
                    $col === 'item' || 
                    $col === 'product'
                )) {
                    $nameIndex = $idx;
                    continue;
                }
            }

            // Either codeIndex OR nameIndex is accepted, AND priceIndex is required
            if (($codeIndex === false && $nameIndex === false) || $priceIndex === false) {
                // Check if row 1 is actually raw data without a header
                $lastCell = trim((string)end($header));
                $cleanLast = preg_replace('/[^\d.]/', '', $lastCell);
                if (is_numeric($cleanLast) && (float)$cleanLast > 0) {
                    $codeIndex = 0;
                    $priceIndex = count($header) - 1;
                    $nameIndex = count($header) >= 3 ? 1 : false;
                    $isFirstRowData = true;
                } else {
                    throw new RuntimeException(
                        'Could not detect Item Code/Name or Price columns. Found columns: [' . implode(', ', array_map('e', $header)) . ']. ' .
                        'Please make sure your CSV includes columns for Item (or Item Code) and Price (or Unit Price).'
                    );
                }
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
            $nameMap = [];

            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $codeKey = strtoupper(trim($item['ItemCode']));
                $nameKey = strtolower(trim($item['ItemName']));
                $data = [
                    'ItemID' => (int)$item['ItemID'],
                    'ItemCode' => $item['ItemCode'],
                    'ItemName' => $item['ItemName'],
                    'Active' => (int)$item['IsActive'] === 1
                        && (int)$item['UnitActive'] === 1
                        && (int)$item['CategoryActive'] === 1
                ];
                $itemMap[$codeKey] = $data;
                $nameMap[$nameKey] = $data;
            }

            $rows = [];
            $seenItemIds = [];
            $lineNumber = 1;

            $processRow = function(array $csvRow, int $lineNum) use (
                $codeIndex, $nameIndex, $priceIndex,
                $itemMap, $nameMap, &$seenItemIds, &$rows
            ) {
                if ($csvRow === [null] || empty($csvRow) || (count($csvRow) === 1 && trim($csvRow[0] ?? '') === '')) {
                    return;
                }

                $code = $codeIndex !== false ? trim((string)($csvRow[$codeIndex] ?? '')) : '';
                $itemName = $nameIndex !== false ? trim((string)($csvRow[$nameIndex] ?? '')) : '';
                $priceRaw = trim((string)($csvRow[$priceIndex] ?? ''));

                if ($code === '' && $itemName === '' && $priceRaw === '') {
                    return;
                }

                // If user leaves price blank, skip (e.g. blank template item)
                if (($code !== '' || $itemName !== '') && $priceRaw === '') {
                    return;
                }

                // Lookup item: by ItemCode first, fallback to ItemName
                $itemInfo = null;
                if ($code !== '') {
                    $itemInfo = $itemMap[strtoupper($code)] ?? null;
                }
                if (!$itemInfo && $itemName !== '') {
                    $itemInfo = $nameMap[strtolower($itemName)] ?? null;
                }

                $identifier = $code !== '' ? $code : $itemName;
                if (!$itemInfo) {
                    throw new RuntimeException("CSV row {$lineNum}: Item '{$identifier}' does not exist in the database.");
                }

                if (!$itemInfo['Active']) {
                    throw new RuntimeException("CSV row {$lineNum}: item '{$itemInfo['ItemCode']}' ({$itemInfo['ItemName']}) or its unit/category is inactive.");
                }

                if (isset($seenItemIds[$itemInfo['ItemID']])) {
                    throw new RuntimeException("CSV row {$lineNum}: duplicate item '{$itemInfo['ItemCode']}' ({$itemInfo['ItemName']}). Each item can appear only once.");
                }

                // Clean price: remove currency symbols (LKR, Rs, $), handle thousand separator commas
                $priceClean = preg_replace('/[^\d.,]/', '', $priceRaw);
                if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $priceClean)) {
                    $priceClean = str_replace(',', '', $priceClean);
                } elseif (preg_match('/^\d+(,\d{1,2})$/', $priceClean)) {
                    $priceClean = str_replace(',', '.', $priceClean);
                } else {
                    $priceClean = str_replace(',', '', $priceClean);
                }

                if (!is_numeric($priceClean) ||
                    !is_finite((float)$priceClean) ||
                    (float)$priceClean <= 0 ||
                    (float)$priceClean > 99999999.99) {
                    throw new RuntimeException("CSV row {$lineNum}: invalid UnitPrice '{$priceRaw}' for item '{$itemInfo['ItemName']}'. Must be a positive number.");
                }

                $seenItemIds[$itemInfo['ItemID']] = true;
                $rows[] = [
                    'ItemID' => $itemInfo['ItemID'],
                    'UnitPrice' => round((float)$priceClean, 2)
                ];
            };

            // If row 1 was data, parse it
            if ($isFirstRowData && $header) {
                $processRow($header, $lineNumber);
            }

            while (($csvRow = fgetcsv($handle, 0, $bestDelimiter)) !== false) {
                $lineNumber++;
                $processRow($csvRow, $lineNumber);
            }
        } finally {
            fclose($handle);
        }

        if (!$rows) {
            throw new RuntimeException('The CSV contains no valid item prices to import.');
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

            if ($target['Status'] === 'Active') {
                checkPriceOverlaps($pdo, $rows, $from, $to, $targetId);
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

            if (strlen($notes) > 500) {
                $notes = substr($notes, 0, 500);
            }

            $update = $pdo->prepare("
                UPDATE price_list
                SET PriceListName = ?, EffectiveFrom = ?, EffectiveTo = ?, Notes = ?
                WHERE PriceListID = ?
            ");
            $update->execute([
                $name,
                $from,
                $to !== '' ? $to : null,
                $notes !== '' ? $notes : null,
                $targetId
            ]);

            replaceDraftDetails($pdo, $targetId, $rows);
            $message = 'CSV imported. Price list updated with ' . count($rows) . ' item prices (' . $target['Status'] . ').';
        } else {
            // If name is blank, generate a readable name from uploaded filename
            if ($name === '') {
                $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
                $cleanName = preg_replace('/^price_list_\d+_?/i', '', $originalName);
                $cleanName = str_replace('_', ' ', $cleanName);
                $cleanName = trim($cleanName);
                $name = $cleanName !== '' ? $cleanName . ' (Imported)' : 'Price List ' . date('Y-m-d');
            }
            if ($from === '') {
                $from = date('Y-m-d');
            }

            validatePricePeriod($from, $to);

            if (strlen($name) > 100) {
                $name = substr($name, 0, 100);
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
        $saveAsNew = isset($_POST['save_as_new']) && (string)$_POST['save_as_new'] === '1';

        if ($name === '' || strlen($name) > 100) {
            throw new RuntimeException('Enter a price list name of up to 100 characters.');
        }

        if (strlen($notes) > 500) {
            $notes = substr($notes, 0, 500);
        }

        validatePricePeriod($from, $to);

        $rows = validateManualRows(
            $_POST['item_id'] ?? [],
            $_POST['unit_price'] ?? []
        );

        $pdo->beginTransaction();

        if ($id > 0 && !$saveAsNew) {
            $stmt = $pdo->prepare("
                SELECT Status FROM price_list
                WHERE PriceListID = ?
                FOR UPDATE
            ");
            $stmt->execute([$id]);
            $status = $stmt->fetchColumn();

            if (!$status) {
                throw new RuntimeException('Price list not found.');
            }

            if ($status === 'Active') {
                checkPriceOverlaps(
                    $pdo,
                    $rows,
                    $from,
                    $to !== '' ? $to : null,
                    $id
                );
            }

            $update = $pdo->prepare("
                UPDATE price_list
                SET PriceListName = ?, EffectiveFrom = ?, EffectiveTo = ?, Notes = ?
                WHERE PriceListID = ?
            ");
            $update->execute([
                $name,
                $from,
                $to !== '' ? $to : null,
                $notes !== '' ? $notes : null,
                $id
            ]);

            replaceDraftDetails($pdo, $id, $rows);
            $flashMsg = 'Price list updated successfully (' . $status . ').';
            $redirectTarget = $id;
        } else {
            // Save as New List OR Create New List
            if ($saveAsNew && $id > 0) {
                $origStmt = $pdo->prepare("SELECT PriceListName FROM price_list WHERE PriceListID = ?");
                $origStmt->execute([$id]);
                $origName = $origStmt->fetchColumn();
                if ($origName && strcasecmp(trim($origName), $name) === 0) {
                    $name = substr($name . ' (New)', 0, 100);
                }
            }

            $newId = createDraft($pdo, $name, $from, $to, $notes);
            replaceDraftDetails($pdo, $newId, $rows);
            $flashMsg = $saveAsNew 
                ? 'Saved as a new draft price list: "' . $name . '". The original price list was not modified.' 
                : 'New price list saved as a draft.';
            $redirectTarget = $newId;
        }

        $pdo->commit();

        priceFlash('success', $flashMsg);
        priceRedirect('prices.php?edit=' . $redirectTarget);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        priceFlash('error', $ex->getMessage());
        priceRedirect($id > 0 ? 'prices.php?edit=' . $id : 'prices.php');
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
    SELECT i.ItemID, i.ItemCode, i.ItemName, u.UnitSymbol, c.CategoryName,
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
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/* -------------------- LOAD SELECTED DRAFT FOR EDITING -------------------- */
$editId = (int)($_GET['edit'] ?? 0);
$editList = null;
$editRows = [];

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM price_list WHERE PriceListID = ?");
    $stmt->execute([$editId]);
    $editList = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editList) {
        priceFlash('error', 'Price list not found.');
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

/* ===== Searchable Select for Items ===== */
.searchable-select-wrapper {
    position: relative;
    width: 100%;
}
.searchable-control {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
}
.searchable-input {
    width: 100%;
    padding: 11px 48px 11px 11px;
    border: 1px solid #dfe4ed;
    border-radius: 7px;
    font: inherit;
    font-size: 13px;
    background: white;
    color: #202b43;
    box-sizing: border-box;
    cursor: text;
    transition: border-color .15s ease, outline .15s ease;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.searchable-input:focus {
    outline: 2px solid #dce2ff;
    border-color: #6679dc;
}
.searchable-actions {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    gap: 2px;
}
.searchable-clear {
    display: none;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: #e2e8f0;
    color: #64748b;
    font-size: 13px;
    cursor: pointer;
    line-height: 1;
    transition: background .15s, color .15s;
}
.searchable-clear:hover {
    background: #cbd5e1;
    color: #1e293b;
}
.searchable-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    padding: 0;
    border: none;
    background: none;
    color: #778198;
    font-size: 11px;
    cursor: pointer;
    transition: transform .2s ease, color .15s;
}
.searchable-arrow:hover {
    color: #4658a9;
}
.searchable-select-wrapper.is-open .searchable-arrow {
    transform: rotate(180deg);
}
.searchable-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    min-width: 300px;
    background: white;
    border: 1px solid #dfe4ed;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(32, 43, 67, 0.15);
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}
.searchable-select-wrapper.is-open .searchable-dropdown {
    display: block;
}
.searchable-dropdown::-webkit-scrollbar {
    width: 6px;
}
.searchable-dropdown::-webkit-scrollbar-track {
    background: #f8fafc;
    border-radius: 4px;
}
.searchable-dropdown::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.searchable-dropdown::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
.searchable-options-list {
    padding: 4px 0;
    list-style: none;
    margin: 0;
}
.searchable-option {
    padding: 8px 12px;
    font-size: 12px;
    color: #202b43;
    cursor: pointer;
    transition: background .1s, color .1s;
    user-select: none;
    border-bottom: 1px solid #f8fafc;
}
.searchable-option:last-child {
    border-bottom: none;
}
.searchable-option:hover,
.searchable-option.is-highlighted {
    background: #f0f4ff;
    color: #4658a9;
}
.searchable-option.is-selected {
    background: #eef2ff;
    color: #4658a9;
    font-weight: 600;
}
.searchable-opt-main {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.searchable-opt-name {
    font-weight: 600;
    color: #1e293b;
}
.searchable-opt-code {
    font-size: 11px;
    color: #4658a9;
    background: #edf0f9;
    padding: 1px 5px;
    border-radius: 4px;
    font-weight: 600;
}
.searchable-opt-sub {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #778198;
    margin-top: 2px;
}
.searchable-opt-price {
    color: #16a34a;
    font-weight: 600;
}
.searchable-highlight {
    background: #dbeafe;
    color: #1e40af;
    padding: 0 2px;
    border-radius: 2px;
    font-weight: 600;
}
.searchable-no-match {
    padding: 12px;
    font-size: 12px;
    color: #778198;
    text-align: center;
}
.searchable-hidden-select {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

/* ===== CSV Upload Zone & Preview ===== */
.csv-dropzone {
    border: 2px dashed #d0d7e3;
    border-radius: 10px;
    padding: 24px 16px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
    transition: .2s;
    margin-bottom: 12px;
}
.csv-dropzone:hover, .csv-dropzone.dragover {
    border-color: #4658a9;
    background: #eef1f9;
}
.csv-dropzone .drop-icon {
    font-size: 28px;
    margin-bottom: 6px;
    display: block;
}
.csv-dropzone .drop-text {
    font-size: 12px;
    color: #556070;
}
.csv-dropzone .drop-text strong {
    color: #4658a9;
}
.csv-dropzone .file-info {
    font-size: 12px;
    color: #4658a9;
    font-weight: 600;
    margin-top: 8px;
}
.csv-dropzone input[type=file] {
    display: none;
}
.csv-preview {
    margin-top: 12px;
}
.csv-preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}
.csv-preview-table th {
    background: #f7f8fc;
    color: #778198;
    font-size: 10px;
    text-transform: uppercase;
    padding: 6px 8px;
    border-bottom: 1px solid #edf0f5;
    text-align: left;
}
.csv-preview-table td {
    padding: 6px 8px;
    border-bottom: 1px solid #edf0f5;
}
.csv-preview-count {
    font-size: 11px;
    color: #187447;
    font-weight: 600;
    margin-bottom: 6px;
}
.csv-preview-scroll {
    max-height: 180px;
    overflow-y: auto;
    border: 1px solid #edf0f5;
    border-radius: 6px;
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
    <a class="nav-link active" href="prices.php">💰 &nbsp; Price Lists</a>
    <a class="nav-link" href="purchases.php">🧾 &nbsp; Market Purchases</a>
    <a class="nav-link" href="orders.php">📋 &nbsp; Hotel Orders</a>
    <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
    <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
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
                    <a class="btn primary" href="prices.php?edit=<?= (int)$viewList['PriceListID'] ?>">✏️ Edit Price List</a>
                    <a class="btn secondary" href="prices.php?export=<?= (int)$viewList['PriceListID'] ?>">Export CSV</a>
                    <a class="btn secondary" href="prices.php">Close</a>
                </div>
            </div>

            <?php if ($viewList['Notes']): ?>
                <p class="muted" style="margin:0 0 15px;background:#f8fafc;padding:10px 12px;border-radius:6px;border:1px solid #edf0f5"><?= nl2br(e($viewList['Notes'])) ?></p>
            <?php endif; ?>

            <?php if ($viewRows): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
                    <input type="search" id="filterViewItemsInput" placeholder="🔍 Quick search items in this list..." style="padding:8px 12px;font-size:12px;border:1px solid #dfe4ed;border-radius:6px;min-width:240px;background:#f8fafc">
                    <span id="viewItemsCountBadge" class="pill" style="background:#eef1f7;color:#34405a;font-size:11px">
                        <?= count($viewRows) ?> item(s)
                    </span>
                </div>
            <?php endif; ?>

            <div class="table-wrap">
                <table id="viewPriceTable">
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
            <h2><?= $isEditing ? 'Edit Price List' : 'Create Price List' ?></h2>
            <?php if ($isEditing): ?>
                <div style="margin:-12px 0 16px;display:flex;align-items:center;gap:8px">
                    <span class="muted" style="font-size:12px">Editing: <strong><?= e($editList['PriceListName']) ?></strong></span>
                    <span class="pill <?= strtolower($editList['Status']) ?>"><?= e($editList['Status']) ?></span>
                </div>
            <?php endif; ?>

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
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px">
                        <h3 style="font-size:14px;margin:0">Item Selling Prices</h3>
                        <span id="priceItemsCountBadge" class="pill" style="background:#eef1f7;color:#34405a;font-size:11px;font-weight:600">
                            <?= count($editRows) ?> item(s) in list
                        </span>
                    </div>
                    <p class="muted" style="margin:0 0 12px;font-size:12px">Select an item and enter its selling price. Each item can appear only once.</p>

                    <!-- SEARCH & FILTER ITEMS IN THIS PRICE LIST -->
                    <div class="price-items-search-container" style="margin-bottom:12px;position:relative">
                        <div style="position:relative;display:flex;align-items:center">
                            <span style="position:absolute;left:11px;color:#778198;font-size:14px;pointer-events:none">🔍</span>
                            <input type="text" id="filterPriceItemsInput" placeholder="Search item in this list (by code, name, category, or price)..." autocomplete="off" style="width:100%;padding:10px 32px 10px 34px;border:1px solid #dfe4ed;border-radius:7px;font:inherit;font-size:13px;background:#f8fafc;color:#202b43;transition:border-color .15s, background .15s">
                            <button type="button" id="clearFilterPriceItemsBtn" style="position:absolute;right:8px;display:none;background:#e2e8f0;border:none;border-radius:50%;width:20px;height:20px;font-size:13px;color:#64748b;cursor:pointer;line-height:1;align-items:center;justify-content:center;padding:0" title="Clear search">&times;</button>
                        </div>
                    </div>

                    <div id="noMatchingPriceItems" style="display:none;padding:14px;text-align:center;background:#fffbeb;border:1px dashed #fcd34d;border-radius:8px;margin-bottom:12px;font-size:12px;color:#92400e">
                        <div style="margin-bottom:8px">No items currently in this price list match your search.</div>
                        <button type="button" class="btn secondary" id="btnAddSearchedItem" style="font-size:11px;padding:5px 10px;background:white;border:1px solid #d1d5db">
                            + Add matching item as new row
                        </button>
                    </div>

                    <div id="priceRows">
                    <?php foreach ($editRows as $row): ?>
                        <div class="price-line">
                            <div class="field">
                                <label>Item</label>
                                <select name="item_id[]" required>
                                    <option value="">Select item</option>
                                    <?php foreach ($items as $item): ?>
                                        <option value="<?= (int)$item['ItemID'] ?>"
                                            data-code="<?= e($item['ItemCode']) ?>"
                                            data-name="<?= e($item['ItemName']) ?>"
                                            data-unit="<?= e($item['UnitSymbol']) ?>"
                                            data-category="<?= e($item['CategoryName']) ?>"
                                            data-current-price="<?= $item['CurrentPrice'] !== null && $item['CurrentPrice'] !== '' ? number_format((float)$item['CurrentPrice'], 2, '.', '') : '' ?>"
                                            <?= (string)$row['ItemID'] === (string)$item['ItemID'] ? 'selected' : '' ?>>
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

                <div class="actions" style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <button type="submit" class="btn primary" <?= !$items ? 'disabled' : '' ?>>
                        <?= $isEditing ? 'Update Price List' : 'Save as Draft' ?>
                    </button>
                    <?php if ($isEditing): ?>
                        <button type="submit" name="save_as_new" value="1" class="btn secondary" style="background:#f0fdf4;border:1px solid #86efac;color:#166534" <?= !$items ? 'disabled' : '' ?> title="Insert these items and prices as a new draft price list without changing the original">
                            📋 Save as New List
                        </button>
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
                            $allListsStmt = $pdo->query("
                                SELECT PriceListID, PriceListName, Status
                                FROM price_list
                                ORDER BY CreatedAt DESC
                            ");
                            foreach ($allListsStmt->fetchAll(PDO::FETCH_ASSOC) as $pList):
                            ?>
                                <option value="<?= (int)$pList['PriceListID'] ?>" <?= $isEditing && (int)$editList['PriceListID'] === (int)$pList['PriceListID'] ? 'selected' : '' ?>>
                                    Update <?= e($pList['Status']) ?>: <?= e($pList['PriceListName']) ?>
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
                        <label>CSV File *</label>
                        <div class="csv-dropzone" id="csvDropzone">
                            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                            <span class="drop-icon">📁</span>
                            <div class="drop-text">
                                <strong>Click to choose file</strong> or drag and drop your CSV here
                            </div>
                            <div class="file-info" id="csvFileInfo" style="display:none"></div>
                        </div>
                    </div>

                    <div class="csv-preview" id="csvPreview" style="display:none">
                        <div class="csv-preview-count" id="csvPreviewCount"></div>
                        <div class="csv-preview-scroll">
                            <table class="csv-preview-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Item Code</th>
                                        <th>Item Name</th>
                                        <th>Unit Price</th>
                                    </tr>
                                </thead>
                                <tbody id="csvPreviewBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="actions" style="margin-top:14px">
                        <button type="submit" class="btn primary" id="csvSubmitBtn">Import CSV</button>
                        <button type="button" class="btn secondary" id="csvClearBtn" style="display:none">Clear File</button>
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
                                    <a class="btn secondary" href="prices.php?edit=<?= (int)$list['PriceListID'] ?>">Edit</a>

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
                Active, inactive, and draft price lists can be edited directly or saved as a new list using "Save as New List".
            </p>
        </section>
    </div>
</main>
</div>

<script>
(function () {
    const rows = document.getElementById('priceRows');
    const addButton = document.getElementById('addPriceRow');

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function highlightMatches(text, query) {
        if (!query || !query.trim()) {
            return escapeHtml(text);
        }
        const words = query.trim().split(/\s+/).filter(Boolean);
        if (words.length === 0) {
            return escapeHtml(text);
        }
        const escapedWords = words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const pattern = new RegExp('(' + escapedWords.join('|') + ')', 'gi');
        const safeText = escapeHtml(text);
        return safeText.replace(pattern, '<mark class="searchable-highlight">$1</mark>');
    }

    function setupItemSearchableSelect(row) {
        const select = row.querySelector('select[name="item_id[]"]');
        if (!select || select.dataset.searchableInitialized === 'true') return;
        select.dataset.searchableInitialized = 'true';

        // Wrap the select element
        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-select-wrapper';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        // Hide native select safely
        select.classList.add('searchable-hidden-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        const wasRequired = select.hasAttribute('required');
        select.removeAttribute('required');

        // Build searchable control
        const control = document.createElement('div');
        control.className = 'searchable-control';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'searchable-input';
        input.placeholder = 'Type code or item name...';
        input.autocomplete = 'off';
        input.spellcheck = false;
        if (wasRequired) {
            input.required = true;
        }

        const actions = document.createElement('div');
        actions.className = 'searchable-actions';

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'searchable-clear';
        clearBtn.innerHTML = '&times;';
        clearBtn.title = 'Clear selection';
        clearBtn.tabIndex = -1;

        const arrowBtn = document.createElement('button');
        arrowBtn.type = 'button';
        arrowBtn.className = 'searchable-arrow';
        arrowBtn.innerHTML = '&#9662;';
        arrowBtn.title = 'Toggle options';
        arrowBtn.tabIndex = -1;

        actions.appendChild(clearBtn);
        actions.appendChild(arrowBtn);
        control.appendChild(input);
        control.appendChild(actions);
        wrapper.appendChild(control);

        // Build dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'searchable-dropdown';
        dropdown.addEventListener('pointerdown', function(e) {
            e.preventDefault();
        });

        const optionsList = document.createElement('div');
        optionsList.className = 'searchable-options-list';

        const noMatch = document.createElement('div');
        noMatch.className = 'searchable-no-match';
        noMatch.textContent = 'No matching items';
        noMatch.style.display = 'none';

        dropdown.appendChild(optionsList);
        dropdown.appendChild(noMatch);
        wrapper.appendChild(dropdown);

        function renderOptionHtml(optEl, code, name, unit, category, price, query) {
            const highlightedName = highlightMatches(name, query);
            const highlightedCode = highlightMatches(code, query);
            const highlightedCat = highlightMatches(category, query);
            const priceLabel = price ? `Current: LKR ${price}` : 'No active price';

            optEl.innerHTML = `
                <div class="searchable-opt-main">
                    <span class="searchable-opt-name">${highlightedName} (${escapeHtml(unit)})</span>
                    <span class="searchable-opt-code">${highlightedCode}</span>
                </div>
                <div class="searchable-opt-sub">
                    <span>${highlightedCat}</span>
                    <span class="searchable-opt-price">${priceLabel}</span>
                </div>
            `;
        }

        function buildOptions() {
            optionsList.innerHTML = '';
            const optionsData = [];

            Array.from(select.options).forEach((opt, idx) => {
                if (opt.value === '' && idx === 0) return;
                const optEl = document.createElement('div');
                optEl.className = 'searchable-option';
                optEl.dataset.value = opt.value;

                const code = opt.getAttribute('data-code') || '';
                const name = opt.getAttribute('data-name') || opt.text.trim();
                const unit = opt.getAttribute('data-unit') || '';
                const category = opt.getAttribute('data-category') || '';
                const price = opt.getAttribute('data-current-price') || '';

                optEl.dataset.label = opt.text.trim();
                optEl.dataset.code = code;
                optEl.dataset.name = name;
                optEl.dataset.unit = unit;
                optEl.dataset.category = category;
                optEl.dataset.price = price;

                renderOptionHtml(optEl, code, name, unit, category, price, '');

                if (opt.value === select.value && opt.value !== '') {
                    optEl.classList.add('is-selected');
                }

                optEl.addEventListener('click', function(e) {
                    e.stopPropagation();
                    chooseOption(opt.value, opt.text.trim(), price);
                });

                optionsList.appendChild(optEl);
                optionsData.push({
                    value: opt.value,
                    label: opt.text.trim(),
                    code: code,
                    name: name,
                    unit: unit,
                    category: category,
                    price: price,
                    el: optEl
                });
            });

            return optionsData;
        }

        let options = buildOptions();

        function syncFromSelect() {
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value !== '') {
                input.value = selectedOpt.text.trim();
                clearBtn.style.display = 'flex';
                input.setCustomValidity('');
                updateSelectedClass(selectedOpt.value);
            } else {
                input.value = '';
                clearBtn.style.display = 'none';
                if (wasRequired) {
                    input.setCustomValidity('Please select an item from the list.');
                }
                updateSelectedClass('');
            }
        }

        function updateSelectedClass(val) {
            options.forEach(item => {
                if (item.value === val && val !== '') {
                    item.el.classList.add('is-selected');
                } else {
                    item.el.classList.remove('is-selected');
                }
            });
        }

        syncFromSelect();

        function filterOptions(query) {
            const q = (query || '').toLowerCase().trim();
            const words = q.split(/\s+/).filter(Boolean);
            let matchCount = 0;
            let firstMatch = null;

            options.forEach(item => {
                const searchableText = `${item.code} ${item.name} ${item.category} ${item.unit}`.toLowerCase();
                const matches = words.length === 0 || words.every(w => searchableText.includes(w));
                if (matches) {
                    item.el.style.display = '';
                    renderOptionHtml(item.el, item.code, item.name, item.unit, item.category, item.price, q);
                    matchCount++;
                    if (!firstMatch) firstMatch = item.el;
                } else {
                    item.el.style.display = 'none';
                    item.el.classList.remove('is-highlighted');
                }
            });

            noMatch.style.display = matchCount === 0 ? 'block' : 'none';

            clearHighlight();
            if (firstMatch) {
                firstMatch.classList.add('is-highlighted');
            }
        }

        function openDropdown() {
            if (wrapper.classList.contains('is-open')) return;
            // Close other open searchable dropdowns
            document.querySelectorAll('.searchable-select-wrapper.is-open').forEach(w => {
                if (w !== wrapper) {
                    w.classList.remove('is-open');
                    const pLine = w.closest('.price-line');
                    if (pLine) { pLine.style.zIndex = ''; pLine.style.position = ''; }
                }
            });

            wrapper.classList.add('is-open');
            const priceLine = wrapper.closest('.price-line');
            if (priceLine) {
                priceLine.style.position = 'relative';
                priceLine.style.zIndex = '100';
            }
            filterOptions('');
            const selected = optionsList.querySelector('.searchable-option.is-selected');
            if (selected && selected.style.display !== 'none') {
                clearHighlight();
                selected.classList.add('is-highlighted');
                selected.scrollIntoView({ block: 'nearest' });
            }
        }

        function closeDropdown() {
            wrapper.classList.remove('is-open');
            const priceLine = wrapper.closest('.price-line');
            if (priceLine) {
                priceLine.style.position = '';
                priceLine.style.zIndex = '';
            }
            clearHighlight();
        }

        function clearHighlight() {
            optionsList.querySelectorAll('.searchable-option.is-highlighted').forEach(el => {
                el.classList.remove('is-highlighted');
            });
        }

        function chooseOption(value, label, price) {
            select.value = value;
            input.value = label;
            clearBtn.style.display = 'flex';
            input.setCustomValidity('');
            updateSelectedClass(value);
            closeDropdown();

            // Auto-load current price if unit_price input is empty or was not modified
            const priceInput = row.querySelector('input[name="unit_price[]"]');
            if (priceInput && price) {
                if (!priceInput.value || parseFloat(priceInput.value) === 0 || priceInput.dataset.autoLoaded === 'true') {
                    priceInput.value = price;
                    priceInput.dataset.autoLoaded = 'true';
                }
            }

            select.dispatchEvent(new Event('change', { bubbles: true }));

            // Focus unit price input for quick editing
            if (priceInput) {
                priceInput.focus();
                priceInput.select();
            }
        }

        function clearSelection() {
            select.value = '';
            input.value = '';
            clearBtn.style.display = 'none';
            updateSelectedClass('');
            if (wasRequired) {
                input.setCustomValidity('Please select an item from the list.');
            }
            select.dispatchEvent(new Event('change', { bubbles: true }));
            filterOptions('');
            input.focus();
            openDropdown();
        }

        select._clearSearchable = clearSelection;

        const priceInput = row.querySelector('input[name="unit_price[]"]');
        if (priceInput) {
            priceInput.addEventListener('input', function() {
                delete this.dataset.autoLoaded;
            });
        }

        input.addEventListener('focus', function() {
            openDropdown();
            this.select();
        });

        input.addEventListener('input', function() {
            if (!wrapper.classList.contains('is-open')) {
                openDropdown();
            }
            filterOptions(this.value);

            const q = this.value.trim().toLowerCase();
            const exact = options.find(o => o.label.toLowerCase() === q || o.code.toLowerCase() === q);
            if (exact) {
                select.value = exact.value;
                input.setCustomValidity('');
                clearBtn.style.display = 'flex';
                updateSelectedClass(exact.value);
                select.dispatchEvent(new Event('change', { bubbles: true }));
                if (priceInput && exact.price && (!priceInput.value || priceInput.dataset.autoLoaded === 'true')) {
                    priceInput.value = exact.price;
                    priceInput.dataset.autoLoaded = 'true';
                }
            } else {
                if (wasRequired) {
                    input.setCustomValidity('Please select an item from the list.');
                }
            }
        });

        input.addEventListener('keydown', function(e) {
            if (!wrapper.classList.contains('is-open')) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter') {
                    openDropdown();
                    e.preventDefault();
                    return;
                }
            }

            const visibleOptions = Array.from(optionsList.querySelectorAll('.searchable-option'))
                .filter(el => el.style.display !== 'none');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (visibleOptions.length === 0) return;
                let currentIndex = visibleOptions.findIndex(el => el.classList.contains('is-highlighted'));
                clearHighlight();
                const nextIndex = (currentIndex + 1) % visibleOptions.length;
                visibleOptions[nextIndex].classList.add('is-highlighted');
                visibleOptions[nextIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (visibleOptions.length === 0) return;
                let currentIndex = visibleOptions.findIndex(el => el.classList.contains('is-highlighted'));
                clearHighlight();
                const prevIndex = currentIndex <= 0 ? visibleOptions.length - 1 : currentIndex - 1;
                visibleOptions[prevIndex].classList.add('is-highlighted');
                visibleOptions[prevIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (wrapper.classList.contains('is-open')) {
                    const highlighted = visibleOptions.find(el => el.classList.contains('is-highlighted')) || visibleOptions[0];
                    if (highlighted) {
                        e.preventDefault();
                        chooseOption(highlighted.dataset.value, highlighted.dataset.label, highlighted.dataset.price);
                    }
                }
            } else if (e.key === 'Tab') {
                if (wrapper.classList.contains('is-open')) {
                    if (!e.shiftKey) {
                        const highlighted = visibleOptions.find(el => el.classList.contains('is-highlighted')) || visibleOptions[0];
                        if (highlighted && input.value.trim() !== '') {
                            chooseOption(highlighted.dataset.value, highlighted.dataset.label, highlighted.dataset.price);
                        } else {
                            closeDropdown();
                        }
                    } else {
                        closeDropdown();
                    }
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown();
                syncFromSelect();
            }
        });

        clearBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            clearSelection();
        });

        arrowBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (wrapper.classList.contains('is-open')) {
                closeDropdown();
            } else {
                input.focus();
                openDropdown();
            }
        });

        document.addEventListener('pointerdown', function(e) {
            if (!wrapper.contains(e.target)) {
                if (wrapper.classList.contains('is-open')) {
                    closeDropdown();
                    const selectedOpt = select.options[select.selectedIndex];
                    if (selectedOpt && selectedOpt.value !== '') {
                        input.value = selectedOpt.text.trim();
                        input.setCustomValidity('');
                        clearBtn.style.display = 'flex';
                    } else {
                        input.value = '';
                        clearBtn.style.display = 'none';
                        if (wasRequired) {
                            input.setCustomValidity('Please select an item from the list.');
                        }
                    }
                }
            }
        });
    }

    // Save pristine template before existing rows are wrapped
    let rowTemplateHtml = '';
    const firstLine = rows ? rows.querySelector('.price-line') : null;
    if (firstLine) {
        const cleanClone = firstLine.cloneNode(true);
        cleanClone.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
        cleanClone.querySelectorAll('input').forEach(el => el.value = '');
        rowTemplateHtml = cleanClone.outerHTML;
    }

    // Item search and filter within Price List form
    const filterInput = document.getElementById('filterPriceItemsInput');
    const clearFilterBtn = document.getElementById('clearFilterPriceItemsBtn');
    const countBadge = document.getElementById('priceItemsCountBadge');
    const noMatchBox = document.getElementById('noMatchingPriceItems');
    const addSearchedBtn = document.getElementById('btnAddSearchedItem');

    function updateItemCountBadge(visibleCount, totalCount) {
        if (!countBadge) return;
        const total = totalCount !== undefined ? totalCount : (rows ? rows.querySelectorAll('.price-line').length : 0);
        const vis = visibleCount !== undefined ? visibleCount : (rows ? Array.from(rows.querySelectorAll('.price-line')).filter(r => r.style.display !== 'none').length : 0);

        if (filterInput && filterInput.value.trim() !== '') {
            countBadge.textContent = `Showing ${vis} of ${total} item(s)`;
            countBadge.style.background = vis > 0 ? '#e0f2fe' : '#fee2e2';
            countBadge.style.color = vis > 0 ? '#0369a1' : '#b91c1c';
        } else {
            countBadge.textContent = `${total} item(s) in list`;
            countBadge.style.background = '#eef1f7';
            countBadge.style.color = '#34405a';
        }
    }

    function filterPriceRows(query) {
        if (!rows) return;
        const allLines = rows.querySelectorAll('.price-line');
        const q = (query || '').toLowerCase().trim();
        const words = q.split(/\s+/).filter(Boolean);
        let visibleCount = 0;

        allLines.forEach(function (line) {
            if (words.length === 0) {
                line.style.display = '';
                visibleCount++;
                return;
            }

            const sInput = line.querySelector('.searchable-input');
            const itemText = sInput ? sInput.value : '';
            const sel = line.querySelector('select[name="item_id[]"]');
            const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
            const code = opt ? (opt.getAttribute('data-code') || '') : '';
            const name = opt ? (opt.getAttribute('data-name') || opt.text) : '';
            const cat = opt ? (opt.getAttribute('data-category') || '') : '';
            const unit = opt ? (opt.getAttribute('data-unit') || '') : '';
            const priceInput = line.querySelector('input[name="unit_price[]"]');
            const priceVal = priceInput ? priceInput.value : '';

            const rowSearchStr = `${code} ${name} ${cat} ${unit} ${itemText} ${priceVal}`.toLowerCase();
            const matches = words.every(w => rowSearchStr.includes(w));

            if (matches) {
                line.style.display = '';
                visibleCount++;
            } else {
                line.style.display = 'none';
            }
        });

        if (clearFilterBtn) {
            clearFilterBtn.style.display = q !== '' ? 'inline-flex' : 'none';
        }
        if (noMatchBox) {
            noMatchBox.style.display = (q !== '' && visibleCount === 0) ? 'block' : 'none';
        }

        updateItemCountBadge(visibleCount, allLines.length);
    }

    if (filterInput) {
        filterInput.addEventListener('input', function () {
            filterPriceRows(this.value);
        });
        filterInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                filterPriceRows('');
            }
        });
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', function () {
            if (filterInput) {
                filterInput.value = '';
                filterInput.focus();
            }
            filterPriceRows('');
        });
    }

    if (addSearchedBtn) {
        addSearchedBtn.addEventListener('click', function () {
            const query = filterInput ? filterInput.value.trim() : '';
            if (filterInput) {
                filterInput.value = '';
                filterPriceRows('');
            }
            if (addButton) {
                addButton.click();
                const lastRow = rows ? rows.querySelector('.price-line:last-child') : null;
                if (lastRow) {
                    const sInput = lastRow.querySelector('.searchable-input');
                    if (sInput && query !== '') {
                        sInput.value = query;
                        sInput.focus();
                        sInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }
        });
    }

    // Quick search for View Details table
    const viewSearchInput = document.getElementById('filterViewItemsInput');
    if (viewSearchInput) {
        viewSearchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            const words = q.split(/\s+/).filter(Boolean);
            const trs = document.querySelectorAll('#viewPriceTable tbody tr');
            let visible = 0;
            trs.forEach(tr => {
                const text = tr.textContent.toLowerCase();
                const show = words.length === 0 || words.every(w => text.includes(w));
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const vBadge = document.getElementById('viewItemsCountBadge');
            if (vBadge) {
                vBadge.textContent = q !== '' ? `Showing ${visible} of ${trs.length} items` : `${trs.length} item(s)`;
            }
        });
    }

    // Initialize all existing rows on load
    if (rows) {
        rows.querySelectorAll('.price-line').forEach(function (row) {
            setupItemSearchableSelect(row);
        });
        updateItemCountBadge();
    }

    if (rows && addButton) {
        addButton.addEventListener('click', function () {
            if (!rowTemplateHtml) return;

            // Clear any active filter before adding so the new row is visible
            if (filterInput && filterInput.value.trim() !== '') {
                filterInput.value = '';
                filterPriceRows('');
            }

            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = rowTemplateHtml.trim();
            const newRow = tempDiv.firstElementChild;
            rows.appendChild(newRow);

            setupItemSearchableSelect(newRow);
            updateItemCountBadge();

            const newInput = newRow.querySelector('.searchable-input');
            if (newInput) {
                newInput.focus();
            }
        });

        rows.addEventListener('click', function (event) {
            const button = event.target.closest('.remove-row');
            if (!button) return;

            const allRows = rows.querySelectorAll('.price-line');

            if (allRows.length === 1) {
                const sel = allRows[0].querySelector('select[name="item_id[]"]');
                const priceInput = allRows[0].querySelector('input[name="unit_price[]"]');
                if (sel && typeof sel._clearSearchable === 'function') {
                    sel._clearSearchable();
                } else if (sel) {
                    sel.selectedIndex = 0;
                }
                if (priceInput) priceInput.value = '';
                updateItemCountBadge();
                return;
            }

            button.closest('.price-line').remove();
            updateItemCountBadge();
        });

        document.getElementById('manualForm').addEventListener('submit', function (event) {
            // Clear filter before submitting so all fields are accessible to validation
            if (filterInput && filterInput.value.trim() !== '') {
                filterInput.value = '';
                filterPriceRows('');
            }

            let hasEmpty = false;
            let firstEmptyInput = null;

            rows.querySelectorAll('.price-line').forEach(function (row) {
                const select = row.querySelector('select[name="item_id[]"]');
                const sInput = row.querySelector('.searchable-input');
                if (select && (!select.value || select.value === '0')) {
                    hasEmpty = true;
                    if (!firstEmptyInput && sInput) firstEmptyInput = sInput;
                }
            });

            if (hasEmpty && firstEmptyInput) {
                event.preventDefault();
                firstEmptyInput.setCustomValidity('Please select an item from the list.');
                firstEmptyInput.reportValidity();
                firstEmptyInput.focus();
                return;
            }

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

    /* ===== CSV Drag & Drop and Preview ===== */
    (function () {
        const dropzone = document.getElementById('csvDropzone');
        const fileInput = document.getElementById('csv_file');
        const fileInfo = document.getElementById('csvFileInfo');
        const previewEl = document.getElementById('csvPreview');
        const previewBody = document.getElementById('csvPreviewBody');
        const previewCount = document.getElementById('csvPreviewCount');
        const clearBtn = document.getElementById('csvClearBtn');
        const nameInput = document.getElementById('csv_name');

        if (!dropzone || !fileInput) return;

        dropzone.addEventListener('click', function () {
            fileInput.click();
        });

        dropzone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', function () {
            dropzone.classList.remove('dragover');
        });

        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleCsvFile(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', function () {
            if (fileInput.files.length) {
                handleCsvFile(fileInput.files[0]);
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                fileInput.value = '';
                if (fileInfo) fileInfo.style.display = 'none';
                if (previewEl) previewEl.style.display = 'none';
                if (previewBody) previewBody.innerHTML = '';
                clearBtn.style.display = 'none';
            });
        }

        function handleCsvFile(file) {
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert('Please select a valid .csv file.');
                return;
            }

            if (fileInfo) {
                fileInfo.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                fileInfo.style.display = 'block';
            }
            if (clearBtn) {
                clearBtn.style.display = 'inline-block';
            }

            // Auto-populate price list name if empty
            if (nameInput && (!nameInput.value || nameInput.value.trim() === '')) {
                let baseName = file.name.replace(/\.[^/.]+$/, '');
                baseName = baseName.replace(/^price_list_\d+_?/i, '');
                baseName = baseName.replace(/_/g, ' ').trim();
                if (baseName.toLowerCase() === 'price list template' || baseName.toLowerCase() === 'template') {
                    nameInput.value = 'Price List ' + new Date().toISOString().slice(0, 10);
                } else if (baseName !== '') {
                    nameInput.value = baseName + ' (Imported)';
                } else {
                    nameInput.value = 'Price List ' + new Date().toISOString().slice(0, 10);
                }
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                let text = e.target.result;
                if (text.charCodeAt(0) === 0xFEFF) {
                    text = text.substring(1);
                }

                const lines = text.split(/\r?\n/).filter(l => l.trim() !== '');
                if (lines.length < 2) {
                    if (previewEl) previewEl.style.display = 'none';
                    alert('The CSV file appears to have no data rows.');
                    return;
                }

                // Detect delimiter
                const firstLine = lines[0];
                let delim = ',';
                const commaCount = (firstLine.match(/,/g) || []).length;
                const semiCount = (firstLine.match(/;/g) || []).length;
                const tabCount = (firstLine.match(/\t/g) || []).length;
                if (semiCount > commaCount && semiCount > tabCount) delim = ';';
                else if (tabCount > commaCount && tabCount > semiCount) delim = '\t';

                function parseCsvLine(line, delimiter) {
                    const result = [];
                    let cur = '';
                    let inQuotes = false;
                    for (let i = 0; i < line.length; i++) {
                        const ch = line[i];
                        if (inQuotes) {
                            if (ch === '"') {
                                if (i + 1 < line.length && line[i + 1] === '"') {
                                    cur += '"';
                                    i++;
                                } else {
                                    inQuotes = false;
                                }
                            } else {
                                cur += ch;
                            }
                        } else {
                            if (ch === '"') {
                                inQuotes = true;
                            } else if (ch === delimiter) {
                                result.push(cur.trim());
                                cur = '';
                            } else {
                                cur += ch;
                            }
                        }
                    }
                    result.push(cur.trim());
                    return result;
                }

                // Find header line skipping empty lines and single-cell titles
                let headerLineIdx = 0;
                while (headerLineIdx < lines.length) {
                    const rowCols = parseCsvLine(lines[headerLineIdx], delim);
                    if (rowCols.length > 1 || (rowCols.length === 1 && lines.length === 1)) {
                        break;
                    }
                    headerLineIdx++;
                }

                if (headerLineIdx >= lines.length) {
                    alert('Could not find any readable rows in the CSV file.');
                    return;
                }

                const rawHeaderRow = parseCsvLine(lines[headerLineIdx], delim);
                const header = rawHeaderRow.map(col =>
                    col.replace(/^[^\w]+|[^\w]+$/g, '').toLowerCase().replace(/[^a-z0-9]/g, '')
                );

                let codeIdx = header.findIndex(h => h.includes('code') || h.includes('itemno') || h.includes('itemnum') || h === 'itemid' || h === 'id' || h === 'code' || h === 'no');
                let priceIdx = header.findIndex(h => h.includes('price') || h.includes('rate') || h.includes('cost') || h.includes('amount') || h === 'unitprice' || h === 'lkr' || h === 'value');
                let nameIdx = header.findIndex(h => h.includes('name') || h.includes('desc') || h.includes('item') || h.includes('product') || h === 'item' || h === 'product');

                let startLine = headerLineIdx + 1;
                if ((codeIdx === -1 && nameIdx === -1) || priceIdx === -1) {
                    const lastVal = (rawHeaderRow[rawHeaderRow.length - 1] || '').replace(/[^\d.]/g, '');
                    if (lastVal && !isNaN(parseFloat(lastVal)) && parseFloat(lastVal) > 0) {
                        codeIdx = 0;
                        nameIdx = rawHeaderRow.length >= 3 ? 1 : -1;
                        priceIdx = rawHeaderRow.length - 1;
                        startLine = headerLineIdx;
                    } else {
                        alert('Could not find Item Code/Name or Price columns. Detected columns: ' + rawHeaderRow.join(', '));
                        return;
                    }
                }

                let html = '';
                let count = 0;
                for (let i = startLine; i < lines.length; i++) {
                    const cols = parseCsvLine(lines[i], delim);
                    const code = codeIdx >= 0 ? (cols[codeIdx] || '').trim() : '';
                    const name = nameIdx >= 0 ? (cols[nameIdx] || '').trim() : '';
                    const price = priceIdx >= 0 ? (cols[priceIdx] || '').trim() : '';

                    if (!code && !name && !price) continue;
                    count++;
                    if (count <= 100) {
                        html += `<tr>
                            <td>${count}</td>
                            <td><strong>${escapeHtml(code || '—')}</strong></td>
                            <td>${escapeHtml(name || '—')}</td>
                            <td><strong>${escapeHtml(price || '0.00')}</strong></td>
                        </tr>`;
                    }
                }

                if (count === 0) {
                    if (previewEl) previewEl.style.display = 'none';
                    alert('No valid items found in this CSV.');
                    return;
                }

                if (previewBody) previewBody.innerHTML = html;
                if (previewCount) {
                    previewCount.textContent = '✓ ' + count + ' item(s) found in CSV file' + (count > 100 ? ' (showing first 100)' : '');
                }
                if (previewEl) previewEl.style.display = 'block';
            };
            reader.readAsText(file, 'UTF-8');
        }
    })();
})();
</script>
</body>
</html>
