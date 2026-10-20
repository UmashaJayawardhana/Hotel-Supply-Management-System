<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/* DOWNLOAD CSV TEMPLATE */
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="items_template.csv"');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM helps Excel display text correctly.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'ItemName',
        'CategoryName',
        'UnitSymbol',
        'Description'
    ]);

    fputcsv($output, [
        'Carrot',
        'Vegetables',
        'KG',
        'Fresh carrots'
    ]);

    fclose($output);
    exit;
}

/* Generate the next item code for the selected category */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'next_code') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $categoryId = (int)($_GET['category_id'] ?? 0);

        $stmt = $pdo->prepare(
            'SELECT CategoryName
             FROM item_category
             WHERE CategoryID = ? AND IsActive = 1'
        );
        $stmt->execute([$categoryId]);
        $categoryName = $stmt->fetchColumn();

        if (!$categoryName) {
            http_response_code(400);
            echo json_encode(['error' => 'Select a valid active category.']);
            exit;
        }

        // Define readable prefixes for your categories.
        $prefixes = [
            'Fruits'          => 'FRU',
            'Vegetables'      => 'VEG',
            'Dry Items'       => 'DRY',
            'Meat and Fish'   => 'MEA',
            'Dairy Products'  => 'DAI',
            'Beverages'       => 'BEV',
            'Other'           => 'OTH'
        ];

        // Use a fallback prefix for newly added categories.
        $prefix = $prefixes[$categoryName] ??
            strtoupper(substr(
                preg_replace('/[^A-Za-z0-9]/', '', $categoryName),
                0,
                3
            ));

        if ($prefix === '') {
            $prefix = 'ITM';
        }

        // Find existing codes for this prefix.
        $stmt = $pdo->prepare(
            'SELECT ItemCode FROM item WHERE ItemCode LIKE ?'
        );
        $stmt->execute([$prefix . '%']);

        $maxNumber = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingCode) {
            if (preg_match(
                '/^' . preg_quote($prefix, '/') . '([0-9]+)$/i',
                $existingCode,
                $matches
            )) {
                $maxNumber = max($maxNumber, (int)$matches[1]);
            }
        }

        $nextCode = $prefix . str_pad(
            (string)($maxNumber + 1),
            3,
            '0',
            STR_PAD_LEFT
        );

        echo json_encode(['code' => $nextCode]);
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not generate the item code.']);
    }

    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';
$editItem = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['item_id'] ?? 0);
            $code = trim($_POST['item_code'] ?? '');
            $name = trim($_POST['item_name'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $description = trim($_POST['description'] ?? '');

            if (
                $code === '' || $name === '' ||
                $categoryId < 1 || $unitId < 1
            ) {
                throw new RuntimeException(
                    'Item code, item name, category and unit are required.'
                );
            }

            // Ensure selected category and unit are active.
            $check = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM item_category
                     WHERE CategoryID = ? AND IsActive = 1) AS category_ok,
                    (SELECT COUNT(*) FROM unit
                     WHERE UnitID = ? AND IsActive = 1) AS unit_ok'
            );
            $check->execute([$categoryId, $unitId]);
            $valid = $check->fetch();

            if (!$valid || !$valid['category_ok'] || !$valid['unit_ok']) {
                throw new RuntimeException(
                    'Please select an active category and unit.'
                );
            }

            // Check duplicate item codes.
            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM item
                 WHERE ItemCode = ? AND ItemID <> ?'
            );
            $check->execute([$code, $id]);

            if ((int)$check->fetchColumn() > 0) {
                throw new RuntimeException(
                    'This item code already exists. Please use another code.'
                );
            }

            if ($id > 0) {
                $check = $pdo->prepare(
                    'SELECT COUNT(*) FROM item WHERE ItemID = ?'
                );
                $check->execute([$id]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Item not found.');
                }

                $sql = 'UPDATE item
                        SET ItemCode = ?, ItemName = ?,
                            CategoryID = ?, UnitID = ?,
                            Description = ?
                        WHERE ItemID = ?';

                $pdo->prepare($sql)->execute([
                    $code,
                    $name,
                    $categoryId,
                    $unitId,
                    $description !== '' ? $description : null,
                    $id
                ]);

                $_SESSION['item_flash'] = [
                    'type' => 'success',
                    'text' => 'Item updated successfully.'
                ];
            } else {
                $sql = 'INSERT INTO item
                        (ItemCode, ItemName, CategoryID, UnitID, Description)
                        VALUES (?, ?, ?, ?, ?)';

                $pdo->prepare($sql)->execute([
                    $code,
                    $name,
                    $categoryId,
                    $unitId,
                    $description !== '' ? $description : null
                ]);

                $_SESSION['item_flash'] = [
                    'type' => 'success',
                    'text' => 'New item added successfully.'
                ];
            }

            header('Location: items.php');
            exit;
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['item_id'] ?? 0);
            $newStatus = (int)($_POST['new_status'] ?? -1);

            if ($id < 1 || !in_array($newStatus, [0, 1], true)) {
                throw new RuntimeException('Invalid item status request.');
            }

            $stmt = $pdo->prepare(
                'UPDATE item SET IsActive = ? WHERE ItemID = ?'
            );
            $stmt->execute([$newStatus, $id]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare(
                    'SELECT COUNT(*) FROM item WHERE ItemID = ?'
                );
                $check->execute([$id]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Item not found.');
                }
            }

            $_SESSION['item_flash'] = [
                'type' => 'success',
                'text' => $newStatus
                    ? 'Item activated successfully.'
                    : 'Item deactivated successfully.'
            ];

            header('Location: items.php');
            exit;
        }

        /* ---- CSV IMPORT ---- */
        if ($action === 'csv_import') {
            if (empty($_FILES['csv_file']['tmp_name'])) {
                throw new RuntimeException('Please select a CSV file to upload.');
            }

            $file = $_FILES['csv_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                throw new RuntimeException('Only .csv files are accepted.');
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('CSV file must be smaller than 2 MB.');
            }

            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                throw new RuntimeException('Unable to read the uploaded file.');
            }

            // Read BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                throw new RuntimeException('The CSV file appears to be empty.');
            }

            $header = array_map('trim', $header);
            $required = ['ItemName', 'CategoryName', 'UnitSymbol'];
            foreach ($required as $col) {
                if (!in_array($col, $header, true)) {
                    fclose($handle);
                    throw new RuntimeException(
                        'Missing required column: ' . $col .
                        '. Required columns: ItemName, CategoryName, UnitSymbol. Optional: Description'
                    );
                }
            }

            // Build lookup maps
            $catMap = [];
            foreach ($pdo->query('SELECT CategoryID, CategoryName FROM item_category WHERE IsActive = 1')->fetchAll() as $c) {
                $catMap[strtolower(trim($c['CategoryName']))] = (int)$c['CategoryID'];
            }

            $unitMap = [];
            foreach ($pdo->query('SELECT UnitID, UnitSymbol FROM unit WHERE IsActive = 1')->fetchAll() as $u) {
                $unitMap[strtolower(trim($u['UnitSymbol']))] = (int)$u['UnitID'];
            }

            // Prefix map for code generation
            $prefixes = [
                'Fruits' => 'FRU', 'Vegetables' => 'VEG', 'Dry Items' => 'DRY',
                'Meat and Fish' => 'MEA', 'Dairy Products' => 'DAI',
                'Beverages' => 'BEV', 'Other' => 'OTH'
            ];

            $catNameMap = [];
            foreach ($pdo->query('SELECT CategoryID, CategoryName FROM item_category WHERE IsActive = 1')->fetchAll() as $c) {
                $catNameMap[(int)$c['CategoryID']] = $c['CategoryName'];
            }

            $rows = [];
            $lineNum = 1;
            while (($data = fgetcsv($handle)) !== false) {
                $lineNum++;
                if (count($data) < count($required)) continue;
                $row = array_combine($header, array_pad(array_map('trim', $data), count($header), ''));

                $itemName = $row['ItemName'] ?? '';
                $catName = $row['CategoryName'] ?? '';
                $unitSym = $row['UnitSymbol'] ?? '';
                $desc = $row['Description'] ?? '';

                if ($itemName === '') continue; // skip blank rows

                if ($catName === '' || $unitSym === '') {
                    throw new RuntimeException("Row $lineNum: CategoryName and UnitSymbol are required.");
                }

                $catId = $catMap[strtolower($catName)] ?? null;
                if (!$catId) {
                    throw new RuntimeException("Row $lineNum: Unknown category '$catName'. Make sure it exists and is active.");
                }

                $unitId = $unitMap[strtolower($unitSym)] ?? null;
                if (!$unitId) {
                    throw new RuntimeException("Row $lineNum: Unknown unit symbol '$unitSym'. Make sure it exists and is active.");
                }

                $rows[] = [
                    'name' => $itemName,
                    'catId' => $catId,
                    'unitId' => $unitId,
                    'desc' => $desc !== '' ? $desc : null
                ];
            }
            fclose($handle);

            if (empty($rows)) {
                throw new RuntimeException('The CSV file contains no valid data rows.');
            }

            // Generate codes and insert
            $pdo->beginTransaction();
            $inserted = 0;
            $skipped = 0;

            $insertStmt = $pdo->prepare(
                'INSERT INTO item (ItemCode, ItemName, CategoryID, UnitID, Description) VALUES (?, ?, ?, ?, ?)'
            );
            $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM item WHERE ItemName = ? AND CategoryID = ?');

            foreach ($rows as $row) {
                // Skip duplicates
                $dupCheck->execute([$row['name'], $row['catId']]);
                if ((int)$dupCheck->fetchColumn() > 0) {
                    $skipped++;
                    continue;
                }

                // Generate item code
                $catFullName = $catNameMap[$row['catId']] ?? 'Other';
                $prefix = $prefixes[$catFullName] ??
                    strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $catFullName), 0, 3));
                if ($prefix === '') $prefix = 'ITM';

                $codeStmt = $pdo->prepare('SELECT ItemCode FROM item WHERE ItemCode LIKE ?');
                $codeStmt->execute([$prefix . '%']);
                $maxNum = 0;
                foreach ($codeStmt->fetchAll(PDO::FETCH_COLUMN) as $existing) {
                    if (preg_match('/^' . preg_quote($prefix, '/') . '([0-9]+)$/i', $existing, $m)) {
                        $maxNum = max($maxNum, (int)$m[1]);
                    }
                }
                $code = $prefix . str_pad((string)($maxNum + 1), 3, '0', STR_PAD_LEFT);

                $insertStmt->execute([$code, $row['name'], $row['catId'], $row['unitId'], $row['desc']]);
                $inserted++;
            }

            $pdo->commit();

            $msg = "CSV import complete: $inserted item(s) added.";
            if ($skipped > 0) {
                $msg .= " $skipped duplicate(s) skipped.";
            }

            $_SESSION['item_flash'] = ['type' => 'success', 'text' => $msg];
            header('Location: items.php');
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (PDOException $ex) {
        error_log($ex->getMessage());

        $message = 'Database operation failed. Check the database structure and item code.';
        $messageType = 'error';
    } catch (RuntimeException $ex) {
        $message = $ex->getMessage();
        $messageType = 'error';
    }
}

// Show messages after successful actions.
if (!empty($_SESSION['item_flash'])) {
    $message = $_SESSION['item_flash']['text'];
    $messageType = $_SESSION['item_flash']['type'];
    unset($_SESSION['item_flash']);
}

// Load item for editing.
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM item WHERE ItemID = ?'
    );
    $stmt->execute([(int)$_GET['edit']]);
    $editItem = $stmt->fetch();

    if (!$editItem) {
        $message = 'The requested item was not found.';
        $messageType = 'error';
    }
}

// Load active categories and units.
$categories = $pdo->query(
    'SELECT CategoryID, CategoryName
     FROM item_category
     WHERE IsActive = 1
     ORDER BY CategoryName'
)->fetchAll();

$units = $pdo->query(
    'SELECT UnitID, UnitName, UnitSymbol
     FROM unit
     WHERE IsActive = 1
     ORDER BY UnitName'
)->fetchAll();

// Search items.
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(i.ItemCode LIKE ? OR i.ItemName LIKE ?
                 OR c.CategoryName LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $where[] = 'i.IsActive = ?';
    $params[] = $statusFilter === 'active' ? 1 : 0;
}

$sql = 'SELECT i.ItemID, i.ItemCode, i.ItemName, i.Description,
               i.IsActive, c.CategoryName, u.UnitName, u.UnitSymbol
        FROM item i
        INNER JOIN item_category c ON c.CategoryID = i.CategoryID
        INNER JOIN unit u ON u.UnitID = i.UnitID';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY i.ItemID DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$totalItems = (int)$pdo->query(
    'SELECT COUNT(*) FROM item'
)->fetchColumn();

$activeItems = (int)$pdo->query(
    'SELECT COUNT(*) FROM item WHERE IsActive = 1'
)->fetchColumn();

$inactiveItems = $totalItems - $activeItems;
$isEditing = $editItem !== null;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Management | Hotel Supply</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .page-heading { display:flex; justify-content:space-between; align-items:center; gap:15px; margin-bottom:24px; }
        .page-heading h1 { margin:0 0 7px; font-size:26px; }
        .page-heading p { color:#778198; font-size:13px; margin:0; }

        .item-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:15px; margin-bottom:22px; }
        .item-stat { background:white; border:1px solid #e8ebf2; border-radius:12px; padding:18px; }
        .item-stat span { color:#778198; font-size:12px; }
        .item-stat strong { display:block; font-size:25px; margin-top:8px; }

        .item-layout { display:grid; grid-template-columns:minmax(280px,0.8fr) minmax(0,1.7fr); gap:20px; align-items:start; }
        .item-panel { background:white; border:1px solid #e8ebf2; border-radius:12px; padding:22px; min-width:0; }
        .item-panel h2 { margin:0 0 20px; font-size:17px; }

        .field { margin-bottom:16px; }
        .field label { display:block; font-size:12px; font-weight:600; margin-bottom:7px; }
        .field input,.field select,.field textarea,.filter-form input,.filter-form select {
            width:100%; padding:11px 12px; border:1px solid #dfe4ed; border-radius:7px;
            font:inherit; font-size:13px; background:white; color:#202b43; box-sizing:border-box;
        }
        .field textarea { resize:vertical; min-height:75px; }
        .field input:focus,.field select:focus,.field textarea:focus { outline:2px solid #dce2ff; border-color:#6679dc; }

        .form-actions { display:flex; gap:9px; flex-wrap:wrap; }

        .btn { display:inline-block; border:0; border-radius:7px; padding:11px 15px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; text-align:center; }
        .btn-primary { background:#4658a9; color:white; }
        .btn-secondary { background:#eef1f7; color:#34405a; }
        .btn-small { padding:7px 9px; font-size:11px; }
        .btn-danger { background:#fff0ef; color:#a93226; }

        .notice { padding:13px 15px; border-radius:8px; margin-bottom:18px; font-size:13px; }
        .notice.success { background:#e8f8ef; color:#187447; }
        .notice.error { background:#fff0ef; color:#a93226; }

        .filter-form { display:grid; grid-template-columns:minmax(0,1fr) 130px auto; gap:9px; margin-bottom:17px; }
        .table-wrap { overflow-x:auto; }

        .items-table { width:100%; border-collapse:collapse; font-size:12px; }
        .items-table th { text-align:left; background:#f7f8fc; color:#778198; font-size:10px; text-transform:uppercase; letter-spacing:.4px; }
        .items-table th,.items-table td { padding:13px 10px; border-bottom:1px solid #edf0f5; vertical-align:middle; }
        .items-table td { white-space:nowrap; }
        .items-table td.name-cell { white-space:normal; min-width:130px; }

        .item-code { font-weight:700; color:#4658a9; }
        .status { display:inline-block; border-radius:20px; padding:5px 8px; font-size:10px; font-weight:700; }
        .status.active { background:#e5f8ed; color:#197547; }
        .status.inactive { background:#f0f1f4; color:#687184; }

        .row-actions { display:flex; gap:6px; align-items:center; }
        .row-actions form { margin:0; }
        .empty { padding:30px 10px; text-align:center; color:#778198; }
        .table-caption { color:#778198; font-size:11px; margin-bottom:12px; }

        /* ===== Tabs ===== */
        .tab-bar { display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid #edf0f5; }
        .tab-btn { padding:10px 18px; font-size:13px; font-weight:600; cursor:pointer; border:none; background:none;
                   color:#778198; border-bottom:2px solid transparent; margin-bottom:-2px; transition:.15s; }
        .tab-btn:hover { color:#4658a9; }
        .tab-btn.active { color:#4658a9; border-bottom-color:#4658a9; }
        .tab-content { display:none; }
        .tab-content.active { display:block; }

        /* ===== CSV Upload Zone ===== */
        .csv-dropzone {
            border:2px dashed #d0d7e3; border-radius:10px; padding:32px 20px; text-align:center;
            background:#f8fafc; cursor:pointer; transition:.2s; margin-bottom:16px;
        }
        .csv-dropzone:hover,.csv-dropzone.dragover { border-color:#4658a9; background:#eef1f9; }
        .csv-dropzone .drop-icon { font-size:32px; margin-bottom:8px; display:block; }
        .csv-dropzone .drop-text { font-size:13px; color:#556070; }
        .csv-dropzone .drop-text strong { color:#4658a9; }
        .csv-dropzone .file-info { font-size:12px; color:#4658a9; font-weight:600; margin-top:10px; }
        .csv-dropzone input[type=file] { display:none; }
        .csv-helper { font-size:12px; color:#778198; margin-bottom:18px; line-height:1.6; }
        .csv-helper code { background:#eef1f7; padding:2px 6px; border-radius:4px; font-size:11px; }
        .template-link { display:inline-flex; align-items:center; gap:6px; }

        /* ===== CSV Preview ===== */
        .csv-preview { margin-top:14px; }
        .csv-preview-table { width:100%; border-collapse:collapse; font-size:11px; }
        .csv-preview-table th { background:#f7f8fc; color:#778198; font-size:10px; text-transform:uppercase; padding:8px 7px; border-bottom:1px solid #edf0f5; text-align:left; }
        .csv-preview-table td { padding:8px 7px; border-bottom:1px solid #edf0f5; }
        .csv-preview-count { font-size:12px; color:#556070; margin-bottom:8px; }
        .csv-preview-scroll { max-height:240px; overflow-y:auto; border:1px solid #edf0f5; border-radius:7px; }

        @media(max-width:1100px) { .item-layout { grid-template-columns:1fr; } }
        @media(max-width:700px) {
            .item-stats { grid-template-columns:1fr; }
            .page-heading { align-items:flex-start; flex-direction:column; }
            .filter-form { grid-template-columns:1fr; }
            .item-panel { padding:15px; }
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
            <a class="nav-link active" href="items.php">📦 &nbsp; Items</a>
            <a class="nav-link" href="hotels.php">🏨 &nbsp; Hotels</a>
            <a class="nav-link" href="vendors.php">🛒 &nbsp; Market Vendors</a>

            <p class="menu-label">OPERATIONS</p>
            <a class="nav-link" href="prices.php">💰 &nbsp; Price Lists</a>
            <a class="nav-link" href="purchases.php">🧾 &nbsp; Market Purchases</a>
            <a class="nav-link" href="orders.php">📋 &nbsp; Hotel Orders</a>
            <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
            <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
            <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
            <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>

            <div class="sidebar-footer">
                <span class="status-dot"></span> Local system running
            </div>
        </aside>

        <main class="main">
            <div class="page-heading">
                <div>
                    <h1>Item Management</h1>
                    <p>Manage the products you purchase and supply to hotels.</p>
                </div>
                <a href="items.php" class="btn btn-secondary">+ New item</a>
            </div>

            <?php if ($message !== ''): ?>
                <div class="notice <?= e($messageType) ?>">
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <section class="item-stats">
                <div class="item-stat">
                    <span>Total items</span>
                    <strong><?= $totalItems ?></strong>
                </div>
                <div class="item-stat">
                    <span>Active items</span>
                    <strong><?= $activeItems ?></strong>
                </div>
                <div class="item-stat">
                    <span>Inactive items</span>
                    <strong><?= $inactiveItems ?></strong>
                </div>
            </section>

            <section class="item-layout">
                <div class="item-panel">
                    <?php if ($isEditing): ?>
                        <h2>Edit item</h2>
                    <?php else: ?>
                        <!-- Tab bar -->
                        <div class="tab-bar">
                            <button class="tab-btn active" data-tab="manual" type="button">✏️ Manual Entry</button>
                            <button class="tab-btn" data-tab="csv" type="button">📄 CSV Import</button>
                        </div>
                    <?php endif; ?>

                    <?php if (!$categories || !$units): ?>
                        <div class="notice error">
                            You need at least one active category and one active
                            unit before adding items. Add them to the database first.
                        </div>
                    <?php endif; ?>

                    <!-- TAB 1: Manual Entry -->
                    <div class="tab-content active" id="tab-manual">
                        <form method="post" action="items.php">
                            <input type="hidden" name="csrf_token"
                                value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="item_id"
                                value="<?= e($editItem['ItemID'] ?? 0) ?>">
                            <div class="field">
                                <label for="category_id">Category *</label>
                                <select id="category_id" name="category_id" required onchange="generateItemCode()">
                                    <option value="">Select category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['CategoryID'] ?>"
                                            <?= (string)($editItem['CategoryID'] ?? '')
                                                === (string)$category['CategoryID']
                                                ? 'selected' : '' ?>>
                                            <?= e($category['CategoryName']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="item_name">Item name *</label>
                                <input id="item_name" name="item_name"
                                    maxlength="100" required
                                    placeholder="e.g. Carrot"
                                    value="<?= e($editItem['ItemName'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label for="item_code">Item code *</label>
                                <input id="item_code" name="item_code"
                                    maxlength="50" required
                                    placeholder="Select a category first"
                                    value="<?= e($editItem['ItemCode'] ?? '') ?>"
                                    <?= $isEditing ? 'readonly' : '' ?>>
                            </div>

                            <div class="field">
                                <label for="unit_id">Measurement unit *</label>
                                <select id="unit_id" name="unit_id" required>
                                    <option value="">Select unit</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?= $unit['UnitID'] ?>"
                                            <?= (string)($editItem['UnitID'] ?? '')
                                                === (string)$unit['UnitID']
                                                ? 'selected' : '' ?>>
                                            <?= e($unit['UnitName']) ?>
                                            (<?= e($unit['UnitSymbol']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="description">Description</label>
                                <textarea id="description" name="description"
                                    maxlength="500"
                                    placeholder="Optional item details"><?= e($editItem['Description'] ?? '') ?></textarea>
                            </div>

                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit"
                                    <?= (!$categories || !$units) ? 'disabled' : '' ?>>
                                    <?= $isEditing ? 'Save changes' : 'Add item' ?>
                                </button>

                                <?php if ($isEditing): ?>
                                    <a href="items.php" class="btn btn-secondary">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 2: CSV Import -->
                    <?php if (!$isEditing): ?>
                    <div class="tab-content" id="tab-csv">
                        <p class="csv-helper">
                            Upload a <code>.csv</code> file with columns:
                            <code>ItemName</code>, <code>CategoryName</code>, <code>UnitSymbol</code>,
                            and optionally <code>Description</code>.<br>
                            Item codes will be generated automatically. Duplicate items (same name + category) are skipped.
                        </p>

                        <a href="items.php?download_template=1" class="btn btn-secondary template-link" style="margin-bottom:16px">
                            ⬇️ Download CSV Template
                        </a>

                        <form method="post" action="items.php" enctype="multipart/form-data" id="csvUploadForm">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="csv_import">

                            <div class="csv-dropzone" id="csvDropzone">
                                <input type="file" name="csv_file" id="csvFileInput" accept=".csv">
                                <span class="drop-icon">📁</span>
                                <div class="drop-text">
                                    <strong>Click to browse</strong> or drag and drop your CSV file here
                                </div>
                                <div class="file-info" id="csvFileName" style="display:none"></div>
                            </div>

                            <div class="csv-preview" id="csvPreview" style="display:none">
                                <div class="csv-preview-count" id="csvPreviewCount"></div>
                                <div class="csv-preview-scroll">
                                    <table class="csv-preview-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th>Unit</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody id="csvPreviewBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="form-actions" style="margin-top:16px">
                                <button type="submit" class="btn btn-primary" id="csvSubmitBtn" disabled
                                    <?= (!$categories || !$units) ? 'disabled' : '' ?>>
                                    Import Items from CSV
                                </button>
                                <button type="button" class="btn btn-secondary" id="csvClearBtn" style="display:none">Clear</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="item-panel">
                    <h2>All items</h2>
                    <p class="table-caption">
                        Search, edit or change the active status of an item.
                    </p>

                    <form class="filter-form" method="get" action="items.php">
                        <input type="search" name="search"
                            placeholder="Search code, name or category..."
                            value="<?= e($search) ?>">

                        <select name="status" aria-label="Filter by status">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>
                                All items
                            </option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>
                        </select>

                        <button class="btn btn-primary" type="submit">Search</button>
                    </form>

                    <div class="table-wrap">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Code / Item</th>
                                    <th>Category</th>
                                    <th>Unit</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$items): ?>
                                    <tr>
                                        <td colspan="5" class="empty">
                                            No items found. Add your first item using the form.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td class="name-cell">
                                                <span class="item-code"><?= e($item['ItemCode']) ?></span><br>
                                                <strong><?= e($item['ItemName']) ?></strong>
                                            </td>
                                            <td><?= e($item['CategoryName']) ?></td>
                                            <td><?= e($item['UnitSymbol']) ?></td>
                                            <td>
                                                <span class="status <?= $item['IsActive'] ? 'active' : 'inactive' ?>">
                                                    <?= $item['IsActive'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="row-actions">
                                                    <a class="btn btn-secondary btn-small"
                                                        href="items.php?edit=<?= (int)$item['ItemID'] ?>">
                                                        Edit
                                                    </a>

                                                    <form method="post" action="items.php"
                                                        onsubmit="return confirm('Change this item status?');">
                                                        <input type="hidden" name="csrf_token"
                                                            value="<?= e($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="item_id"
                                                            value="<?= (int)$item['ItemID'] ?>">
                                                        <input type="hidden" name="new_status"
                                                            value="<?= $item['IsActive'] ? 0 : 1 ?>">

                                                        <button type="submit"
                                                            class="btn btn-small <?= $item['IsActive'] ? 'btn-secondary' : 'btn-primary' ?>">
                                                            <?= $item['IsActive'] ? 'Deactivate' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        /* ===== Item code auto-generation ===== */
        async function generateItemCode() {
            const category = document.getElementById('category_id');
            const codeField = document.getElementById('item_code');
            if (codeField.readOnly) return;

            const categoryId = category.value;
            if (!categoryId) {
                codeField.value = '';
                codeField.placeholder = 'Select a category first';
                return;
            }

            codeField.value = 'Generating...';
            try {
                const response = await fetch(
                    'items.php?ajax=next_code&category_id=' +
                    encodeURIComponent(categoryId)
                );
                const data = await response.json();
                if (!response.ok || !data.code) {
                    throw new Error(data.error || 'Code generation failed.');
                }
                codeField.value = data.code;
            } catch (error) {
                codeField.value = '';
                alert(error.message || 'Unable to generate the item code.');
            }
        }

        /* ===== Tab switching ===== */
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
                document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
                btn.classList.add('active');
                var target = document.getElementById('tab-' + btn.getAttribute('data-tab'));
                if (target) target.classList.add('active');
            });
        });

        /* ===== CSV drag-and-drop & preview ===== */
        (function() {
            var dropzone = document.getElementById('csvDropzone');
            var fileInput = document.getElementById('csvFileInput');
            var fileNameEl = document.getElementById('csvFileName');
            var previewEl = document.getElementById('csvPreview');
            var previewBody = document.getElementById('csvPreviewBody');
            var previewCount = document.getElementById('csvPreviewCount');
            var submitBtn = document.getElementById('csvSubmitBtn');
            var clearBtn = document.getElementById('csvClearBtn');

            if (!dropzone) return;

            dropzone.addEventListener('click', function() { fileInput.click(); });

            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });

            dropzone.addEventListener('dragleave', function() {
                dropzone.classList.remove('dragover');
            });

            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFile(e.dataTransfer.files[0]);
                }
            });

            fileInput.addEventListener('change', function() {
                if (fileInput.files.length) handleFile(fileInput.files[0]);
            });

            clearBtn.addEventListener('click', function() {
                fileInput.value = '';
                fileNameEl.style.display = 'none';
                previewEl.style.display = 'none';
                previewBody.innerHTML = '';
                submitBtn.disabled = true;
                clearBtn.style.display = 'none';
            });

            function handleFile(file) {
                if (!file.name.toLowerCase().endsWith('.csv')) {
                    alert('Please select a .csv file.');
                    return;
                }

                fileNameEl.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                fileNameEl.style.display = 'block';
                clearBtn.style.display = 'inline-block';

                var reader = new FileReader();
                reader.onload = function(e) {
                    var text = e.target.result;
                    // Remove BOM
                    if (text.charCodeAt(0) === 0xFEFF) text = text.substring(1);

                    var lines = text.split(/\r?\n/).filter(function(l) { return l.trim() !== ''; });
                    if (lines.length < 2) {
                        previewEl.style.display = 'none';
                        submitBtn.disabled = true;
                        alert('The CSV file has no data rows.');
                        return;
                    }

                    var header = parseCSVLine(lines[0]);
                    var nameIdx = header.indexOf('ItemName');
                    var catIdx = header.indexOf('CategoryName');
                    var unitIdx = header.indexOf('UnitSymbol');
                    var descIdx = header.indexOf('Description');

                    if (nameIdx === -1 || catIdx === -1 || unitIdx === -1) {
                        alert('CSV must have columns: ItemName, CategoryName, UnitSymbol');
                        submitBtn.disabled = true;
                        return;
                    }

                    var html = '';
                    var count = 0;
                    for (var i = 1; i < lines.length; i++) {
                        var cols = parseCSVLine(lines[i]);
                        var name = (cols[nameIdx] || '').trim();
                        if (!name) continue;
                        count++;
                        html += '<tr>' +
                            '<td>' + count + '</td>' +
                            '<td>' + esc(name) + '</td>' +
                            '<td>' + esc((cols[catIdx] || '').trim()) + '</td>' +
                            '<td>' + esc((cols[unitIdx] || '').trim()) + '</td>' +
                            '<td>' + esc((cols[descIdx] || '').trim()) + '</td>' +
                            '</tr>';
                    }

                    if (count === 0) {
                        previewEl.style.display = 'none';
                        submitBtn.disabled = true;
                        alert('No valid data rows found in the CSV.');
                        return;
                    }

                    previewBody.innerHTML = html;
                    previewCount.textContent = count + ' item(s) found in file';
                    previewEl.style.display = 'block';
                    submitBtn.disabled = false;
                };
                reader.readAsText(file, 'UTF-8');
            }

            function esc(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            function parseCSVLine(line) {
                var result = [];
                var current = '';
                var inQuotes = false;
                for (var i = 0; i < line.length; i++) {
                    var ch = line[i];
                    if (inQuotes) {
                        if (ch === '"') {
                            if (i + 1 < line.length && line[i + 1] === '"') {
                                current += '"';
                                i++;
                            } else {
                                inQuotes = false;
                            }
                        } else {
                            current += ch;
                        }
                    } else {
                        if (ch === '"') {
                            inQuotes = true;
                        } else if (ch === ',') {
                            result.push(current);
                            current = '';
                        } else {
                            current += ch;
                        }
                    }
                }
                result.push(current);
                return result;
            }
        })();
    </script>
</body>
</html>