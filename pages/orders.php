
<?php
//declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

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

/**
 * Parse an Excel (.xlsx) file into an array of rows.
 * Uses PHP's built-in ZipArchive and SimpleXML without external dependencies.
 */
function parseXlsxOrderFile(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive extension is required to read Excel (.xlsx) files.');
    }

    $zip = new ZipArchive();
    $res = $zip->open($filePath);
    if ($res !== true) {
        throw new RuntimeException('Failed to open Excel file. Please ensure it is a valid .xlsx file.');
    }

    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent !== false) {
        $xml = @simplexml_load_string($ssContent);
        if ($xml) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string)($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#i', $name)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('No worksheet found in Excel file.');
    }

    $xml = @simplexml_load_string($sheetXml);
    if (!$xml || !isset($xml->sheetData)) {
        throw new RuntimeException('Invalid or unreadable worksheet in Excel file.');
    }

    $rows = [];
    foreach ($xml->sheetData->row as $rowNode) {
        $rowCells = [];
        $maxCol = -1;

        foreach ($rowNode->c as $cNode) {
            $cellRef = (string)($cNode['r'] ?? '');
            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/i', $cellRef, $matches)) {
                $colStr = strtoupper($matches[1]);
                $colIndex = 0;
                for ($k = 0; $k < strlen($colStr); $k++) {
                    $colIndex = $colIndex * 26 + (ord($colStr[$k]) - 64);
                }
                $colIndex -= 1;
            } else {
                $colIndex = $maxCol + 1;
            }

            $type = (string)($cNode['t'] ?? '');
            $val = '';

            if ($type === 's') {
                $ssIndex = (int)($cNode->v ?? 0);
                $val = $sharedStrings[$ssIndex] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($cNode->is->t ?? '');
            } elseif ($type === 'b') {
                $val = ((string)($cNode->v ?? '') === '1') ? '1' : '0';
            } else {
                $val = (string)($cNode->v ?? '');
            }

            $rowCells[$colIndex] = trim($val);
            if ($colIndex > $maxCol) {
                $maxCol = $colIndex;
            }
        }

        $normalizedRow = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $normalizedRow[$c] = $rowCells[$c] ?? '';
        }

        if ($maxCol >= 0) {
            $rows[] = $normalizedRow;
        }
    }

    return $rows;
}

/**
 * Parse a CSV file into an array of rows.
 */
function parseCsvOrderFile(string $filePath): array
{
    $rawContent = file_get_contents($filePath);
    if ($rawContent === false || trim($rawContent) === '') {
        throw new RuntimeException('The uploaded CSV file is empty.');
    }

    if (str_starts_with($rawContent, "\xEF\xBB\xBF")) {
        $rawContent = substr($rawContent, 3);
    } elseif (str_starts_with($rawContent, "\xFF\xFE") || str_starts_with($rawContent, "\xFE\xFF")) {
        $rawContent = mb_convert_encoding($rawContent, 'UTF-8', 'UTF-16');
    }

    $fileLines = preg_split('/\r\n|\r|\n/', trim($rawContent));
    $delimiters = [',', ';', "\t"];
    $bestDelimiter = ',';
    $maxDelimCount = 0;
    foreach ($fileLines as $fLine) {
        $trimmed = trim($fLine);
        if ($trimmed === '') continue;
        foreach ($delimiters as $delim) {
            $cnt = substr_count($trimmed, $delim);
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

    $rows = [];
    while (($row = fgetcsv($handle, 0, $bestDelimiter)) !== false) {
        if ($row === [null] || empty($row)) {
            continue;
        }
        $row = array_map(function($c) {
            return trim((string)$c);
        }, $row);
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

/*
|--------------------------------------------------------------------------
| CSV Order Template Download
|--------------------------------------------------------------------------
*/
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel_order_template.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ItemCode', 'ItemName', 'Unit', 'Quantity', 'ItemNotes']);

    $itemsStmt = $pdo->query("
        SELECT i.ItemCode, i.ItemName, u.UnitSymbol
        FROM item i
        LEFT JOIN unit u ON u.UnitID = i.UnitID
        WHERE i.IsActive = 1
        ORDER BY i.ItemName
    ");
    $activeItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($activeItems)) {
        foreach ($activeItems as $item) {
            fputcsv($out, [
                $item['ItemCode'],
                $item['ItemName'],
                $item['UnitSymbol'] ?? '',
                '',
                ''
            ]);
        }
    } else {
        fputcsv($out, ['ITM001', 'Sample Item', 'KG', '10.000', 'Morning delivery']);
    }

    fclose($out);
    exit;
}

/*
|--------------------------------------------------------------------------
| CSV Order Export
|--------------------------------------------------------------------------
*/
if (isset($_GET['export'])) {
    $exportId = (int)$_GET['export'];

    $orderStmt = $pdo->prepare("
        SELECT o.OrderID, o.OrderDate, h.HotelName
        FROM hotel_order o
        INNER JOIN hotel h ON h.HotelID = o.HotelID
        WHERE o.OrderID = ?
    ");
    $orderStmt->execute([$exportId]);
    $exportOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$exportOrder) {
        http_response_code(404);
        exit('Order not found.');
    }

    $detailStmt = $pdo->prepare("
        SELECT i.ItemCode, i.ItemName, u.UnitSymbol, d.OrderedQuantity, d.ItemNotes
        FROM hotel_order_detail d
        INNER JOIN item i ON i.ItemID = d.ItemID
        LEFT JOIN unit u ON u.UnitID = i.UnitID
        WHERE d.OrderID = ?
        ORDER BY i.ItemName
    ");
    $detailStmt->execute([$exportId]);

    $safeHotel = preg_replace('/[^A-Za-z0-9_-]/', '_', $exportOrder['HotelName']);
    $filename = "order_{$exportId}_{$safeHotel}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ItemCode', 'ItemName', 'Unit', 'Quantity', 'ItemNotes']);

    while ($row = $detailStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['ItemCode'],
            $row['ItemName'],
            $row['UnitSymbol'] ?? '',
            number_format((float)$row['OrderedQuantity'], 3, '.', ''),
            $row['ItemNotes'] ?? ''
        ]);
    }

    fclose($out);
    exit;
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

            // Ensure all selected items are valid, active, and NOS items have positive whole number quantities.
            if ($rows) {
                $checkItem = $pdo->prepare(
                    'SELECT i.ItemID, i.ItemName, u.UnitSymbol
                     FROM item i
                     LEFT JOIN unit u ON u.UnitID = i.UnitID
                     WHERE i.ItemID = ? AND i.IsActive = 1'
                );

                foreach ($rows as $row) {
                    $checkItem->execute([$row['item_id']]);
                    $itemInfo = $checkItem->fetch(PDO::FETCH_ASSOC);

                    if (!$itemInfo) {
                        $errors[] = 'One or more selected items are unavailable or inactive.';
                        break;
                    }

                    if (strtoupper((string)($itemInfo['UnitSymbol'] ?? '')) === 'NOS') {
                        $qty = (float)$row['quantity'];
                        if (abs($qty - round($qty)) > 0.00001 || (int)$qty <= 0) {
                            $errors[] = "Item '{$itemInfo['ItemName']}' is measured in NOS (Number of Items) and must have a positive whole number (integer) quantity.";
                        }
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

    /*
    |--------------------------------------------------------------------------
    | Import order from CSV / Excel (.xlsx)
    |--------------------------------------------------------------------------
    */
    if ($action === 'import_order') {
        $targetOrderId = (int)($_POST['target_order_id'] ?? 0);
        $hotelId = (int)($_POST['hotel_id'] ?? 0);
        $orderDate = trim((string)($_POST['order_date'] ?? date('Y-m-d')));
        $emailReceivedAt = trim((string)($_POST['email_received_at'] ?? ''));
        $emailReference = trim((string)($_POST['email_reference'] ?? ''));
        $emailSubject = trim((string)($_POST['email_subject'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'Received'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (!in_array($status, ['Received', 'Confirmed'], true)) {
            $status = 'Received';
        }

        try {
            if (!isset($_FILES['order_file']) || $_FILES['order_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the form.',
                    UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded. Please choose a CSV or Excel file.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                ];
                $code = $_FILES['order_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new RuntimeException($uploadErrors[$code] ?? 'Please select a valid CSV or Excel file.');
            }

            $file = $_FILES['order_file'];

            if ((int)$file['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('The file cannot exceed 10 MB.');
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx', 'xls', 'txt'], true)) {
                throw new RuntimeException('Unsupported file format. Please upload a .csv or .xlsx (Excel) file.');
            }

            $fh = @fopen($file['tmp_name'], 'rb');
            $magic = $fh ? fread($fh, 4) : '';
            if ($fh) fclose($fh);
            $isZip = ($magic === "PK\x03\x04");

            if ($ext === 'xlsx' || $isZip) {
                $rawRows = parseXlsxOrderFile($file['tmp_name']);
            } else {
                $rawRows = parseCsvOrderFile($file['tmp_name']);
            }

            if (!$rawRows) {
                throw new RuntimeException('The uploaded file is empty or contains no readable rows.');
            }

            $headerRowIndex = false;
            $codeIndex = false;
            $nameIndex = false;
            $qtyIndex = false;
            $notesIndex = false;

            foreach ($rawRows as $rIdx => $rCells) {
                $cleanCells = array_map(function($c) {
                    $c = preg_replace('/^\xEF\xBB\xBF/', '', (string)$c);
                    $c = trim($c, " \t\n\r\0\x0B\"'");
                    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $c));
                }, $rCells);

                $foundCode = false;
                $foundName = false;
                $foundQty = false;
                $foundNotes = false;

                foreach ($cleanCells as $cIdx => $cellText) {
                    if ($foundCode === false && (
                        str_contains($cellText, 'code') ||
                        str_contains($cellText, 'itemno') ||
                        str_contains($cellText, 'itemnum') ||
                        str_contains($cellText, 'sku') ||
                        $cellText === 'itemid' ||
                        $cellText === 'id' ||
                        $cellText === 'code' ||
                        $cellText === 'no'
                    )) {
                        $foundCode = $cIdx;
                        continue;
                    }
                    if ($foundQty === false && (
                        str_contains($cellText, 'quantity') ||
                        str_contains($cellText, 'qty') ||
                        str_contains($cellText, 'amount') ||
                        str_contains($cellText, 'count') ||
                        str_contains($cellText, 'ordered') ||
                        $cellText === 'qty' ||
                        $cellText === 'quantity'
                    )) {
                        $foundQty = $cIdx;
                        continue;
                    }
                    if ($foundName === false && (
                        str_contains($cellText, 'name') ||
                        str_contains($cellText, 'desc') ||
                        str_contains($cellText, 'item') ||
                        str_contains($cellText, 'product') ||
                        $cellText === 'item' ||
                        $cellText === 'product'
                    )) {
                        $foundName = $cIdx;
                        continue;
                    }
                    if ($foundNotes === false && (
                        str_contains($cellText, 'note') ||
                        str_contains($cellText, 'remark') ||
                        str_contains($cellText, 'comment') ||
                        str_contains($cellText, 'instruction')
                    )) {
                        $foundNotes = $cIdx;
                        continue;
                    }
                }

                if (($foundCode !== false || $foundName !== false) && $foundQty !== false) {
                    $headerRowIndex = $rIdx;
                    $codeIndex = $foundCode;
                    $nameIndex = $foundName;
                    $qtyIndex = $foundQty;
                    $notesIndex = $foundNotes;
                    break;
                }
            }

            $startDataIdx = 0;
            if ($headerRowIndex !== false) {
                $startDataIdx = $headerRowIndex + 1;
            } else {
                $firstRow = $rawRows[0] ?? [];
                if (count($firstRow) >= 2) {
                    $codeIndex = 0;
                    if (count($firstRow) >= 3 && is_numeric(preg_replace('/[^\d.]/', '', (string)($firstRow[2] ?? '')))) {
                        $nameIndex = 1;
                        $qtyIndex = 2;
                        $notesIndex = count($firstRow) >= 4 ? 3 : false;
                    } elseif (is_numeric(preg_replace('/[^\d.]/', '', (string)($firstRow[1] ?? '')))) {
                        $qtyIndex = 1;
                    } else {
                        throw new RuntimeException('File headers must include ItemCode (or Code / ItemName) and Quantity (or Qty).');
                    }
                } else {
                    throw new RuntimeException('File headers must include ItemCode (or Code / ItemName) and Quantity (or Qty).');
                }
            }

            $activeItemsList = $pdo->query('
                SELECT i.ItemID, i.ItemCode, i.ItemName, u.UnitSymbol
                FROM item i
                LEFT JOIN unit u ON u.UnitID = i.UnitID
                WHERE i.IsActive = 1
            ')->fetchAll(PDO::FETCH_ASSOC);

            $itemsByCode = [];
            $itemsByName = [];
            foreach ($activeItemsList as $it) {
                $ck = strtolower(trim((string)$it['ItemCode']));
                if ($ck !== '') {
                    $itemsByCode[$ck] = $it;
                }
                $nk = strtolower(trim((string)$it['ItemName']));
                if ($nk !== '') {
                    $itemsByName[$nk] = $it;
                }
            }

            $itemsToSave = [];
            $parseErrors = [];

            for ($i = $startDataIdx; $i < count($rawRows); $i++) {
                $rowCells = $rawRows[$i];
                $rowNum = $i + 1;

                $rawCode = $codeIndex !== false ? trim((string)($rowCells[$codeIndex] ?? '')) : '';
                $rawName = $nameIndex !== false ? trim((string)($rowCells[$nameIndex] ?? '')) : '';
                $rawQty = $qtyIndex !== false ? trim((string)($rowCells[$qtyIndex] ?? '')) : '';
                $rawNote = $notesIndex !== false ? trim((string)($rowCells[$notesIndex] ?? '')) : '';

                if ($rawCode === '' && $rawName === '' && $rawQty === '') {
                    continue;
                }

                $matched = null;
                if ($rawCode !== '') {
                    $matched = $itemsByCode[strtolower($rawCode)] ?? null;
                }
                if (!$matched && $rawName !== '') {
                    $matched = $itemsByName[strtolower($rawName)] ?? null;
                }

                if (!$matched) {
                    $ident = $rawCode !== '' ? $rawCode : ($rawName !== '' ? $rawName : "Row {$rowNum}");
                    $parseErrors[] = "Row {$rowNum}: Item '{$ident}' was not found or is inactive in the catalog.";
                    continue;
                }

                $cleanQtyStr = preg_replace('/[^\d.]/', '', str_replace([' ', ','], '', $rawQty));
                if ($cleanQtyStr === '' || !is_numeric($cleanQtyStr) || (float)$cleanQtyStr <= 0) {
                    $parseErrors[] = "Row {$rowNum} ('{$matched['ItemName']}'): Quantity '{$rawQty}' is invalid. It must be greater than zero.";
                    continue;
                }

                $qty = round((float)$cleanQtyStr, 3);
                if ($qty > 999999999) {
                    $parseErrors[] = "Row {$rowNum} ('{$matched['ItemName']}'): Quantity exceeds maximum allowed limit.";
                    continue;
                }

                if (strtoupper((string)($matched['UnitSymbol'] ?? '')) === 'NOS') {
                    if (abs($qty - round($qty)) > 0.00001 || (int)$qty <= 0) {
                        $parseErrors[] = "Row {$rowNum}: Item '{$matched['ItemName']}' is measured in NOS and must have a positive whole number (integer) quantity.";
                        continue;
                    }
                    $qty = (float)round($qty);
                }

                $itemId = (int)$matched['ItemID'];
                if (isset($itemsToSave[$itemId])) {
                    $itemsToSave[$itemId]['quantity'] += $qty;
                    if ($rawNote !== '') {
                        $itemsToSave[$itemId]['notes'] = $itemsToSave[$itemId]['notes'] !== ''
                            ? $itemsToSave[$itemId]['notes'] . '; ' . $rawNote
                            : $rawNote;
                    }
                } else {
                    $itemsToSave[$itemId] = [
                        'item_id' => $itemId,
                        'item_name' => $matched['ItemName'],
                        'item_code' => $matched['ItemCode'],
                        'quantity' => $qty,
                        'notes' => $rawNote,
                    ];
                }
            }

            if ($parseErrors) {
                throw new RuntimeException(implode('<br>', array_slice($parseErrors, 0, 10)) . (count($parseErrors) > 10 ? '<br>...and ' . (count($parseErrors) - 10) . ' more errors.' : ''));
            }

            if (!$itemsToSave) {
                throw new RuntimeException('No valid item rows were found in the uploaded file.');
            }

            if ($targetOrderId > 0) {
                $checkStmt = $pdo->prepare('SELECT OrderID, HotelID, Status, OrderDate, Notes FROM hotel_order WHERE OrderID = ?');
                $checkStmt->execute([$targetOrderId]);
                $existingOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingOrder) {
                    throw new RuntimeException("Target Order #{$targetOrderId} does not exist.");
                }

                if ($existingOrder['Status'] === 'Cancelled') {
                    throw new RuntimeException("Order #{$targetOrderId} is cancelled and cannot be updated.");
                }

                $delCheck = $pdo->prepare('SELECT COUNT(*) FROM delivery WHERE OrderID = ? AND Status <> "Cancelled"');
                $delCheck->execute([$targetOrderId]);
                if ((int)$delCheck->fetchColumn() > 0) {
                    throw new RuntimeException("Order #{$targetOrderId} already has delivery records and its item quantities cannot be modified.");
                }

                if ($hotelId <= 0) {
                    $hotelId = (int)$existingOrder['HotelID'];
                }

                $hStmt = $pdo->prepare('SELECT HotelID FROM hotel WHERE HotelID = ? AND IsActive = 1');
                $hStmt->execute([$hotelId]);
                if (!$hStmt->fetchColumn()) {
                    throw new RuntimeException('The selected hotel is invalid or inactive.');
                }

                $finalDate = $orderDate !== '' ? $orderDate : substr((string)$existingOrder['OrderDate'], 0, 10);

                $pdo->beginTransaction();

                $updateOrderStmt = $pdo->prepare('
                    UPDATE hotel_order
                    SET HotelID = ?,
                        OrderDate = ?,
                        EmailReceivedAt = ?,
                        EmailReference = ?,
                        EmailSubject = ?,
                        Status = ?,
                        Notes = ?
                    WHERE OrderID = ?
                ');
                $updateOrderStmt->execute([
                    $hotelId,
                    $finalDate,
                    $emailReceivedAt !== '' ? str_replace('T', ' ', $emailReceivedAt) . ':00' : null,
                    $emailReference !== '' ? $emailReference : null,
                    $emailSubject !== '' ? $emailSubject : null,
                    $status,
                    $notes !== '' ? $notes : ($existingOrder['Notes'] ?? null),
                    $targetOrderId
                ]);

                $delDetails = $pdo->prepare('DELETE FROM hotel_order_detail WHERE OrderID = ?');
                $delDetails->execute([$targetOrderId]);

                $insDetail = $pdo->prepare('
                    INSERT INTO hotel_order_detail (OrderID, ItemID, OrderedQuantity, ItemNotes)
                    VALUES (?, ?, ?, ?)
                ');
                foreach ($itemsToSave as $r) {
                    $insDetail->execute([
                        $targetOrderId,
                        $r['item_id'],
                        $r['quantity'],
                        $r['notes'] !== '' ? $r['notes'] : null
                    ]);
                }

                $pdo->commit();
                orderFlash('success', "Order #{$targetOrderId} updated successfully with " . count($itemsToSave) . " items from " . htmlspecialchars($file['name']) . ".");
                orderRedirect("orders.php?view={$targetOrderId}");
            } else {
                if ($hotelId <= 0) {
                    throw new RuntimeException('Please select a hotel for the new order.');
                }

                $hStmt = $pdo->prepare('SELECT HotelID FROM hotel WHERE HotelID = ? AND IsActive = 1');
                $hStmt->execute([$hotelId]);
                if (!$hStmt->fetchColumn()) {
                    throw new RuntimeException('The selected hotel is invalid or inactive.');
                }

                if ($orderDate === '') {
                    $orderDate = date('Y-m-d');
                }

                $pdo->beginTransaction();

                $insOrder = $pdo->prepare('
                    INSERT INTO hotel_order (HotelID, OrderDate, EmailReceivedAt, EmailReference, EmailSubject, Status, Notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $insOrder->execute([
                    $hotelId,
                    $orderDate,
                    $emailReceivedAt !== '' ? str_replace('T', ' ', $emailReceivedAt) . ':00' : null,
                    $emailReference !== '' ? $emailReference : null,
                    $emailSubject !== '' ? $emailSubject : null,
                    $status,
                    $notes !== '' ? $notes : null
                ]);

                $newOrderId = (int)$pdo->lastInsertId();

                $insDetail = $pdo->prepare('
                    INSERT INTO hotel_order_detail (OrderID, ItemID, OrderedQuantity, ItemNotes)
                    VALUES (?, ?, ?, ?)
                ');
                foreach ($itemsToSave as $r) {
                    $insDetail->execute([
                        $newOrderId,
                        $r['item_id'],
                        $r['quantity'],
                        $r['notes'] !== '' ? $r['notes'] : null
                    ]);
                }

                $pdo->commit();
                orderFlash('success', "Hotel Order #{$newOrderId} created successfully with " . count($itemsToSave) . " items from " . htmlspecialchars($file['name']) . ".");
                orderRedirect("orders.php?view={$newOrderId}");
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            orderFlash('error', $e->getMessage());
            orderRedirect('orders.php#import');
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

$editableOrdersStmt = $pdo->query("
    SELECT o.OrderID, o.HotelID, o.OrderDate, o.Status, o.EmailSubject, o.EmailReference,
           h.HotelName,
           (SELECT COUNT(*) FROM hotel_order_detail od WHERE od.OrderID = o.OrderID) AS ItemCount,
           (SELECT COALESCE(SUM(od.OrderedQuantity), 0) FROM hotel_order_detail od WHERE od.OrderID = o.OrderID) AS TotalQuantity
    FROM hotel_order o
    INNER JOIN hotel h ON h.HotelID = o.HotelID
    WHERE o.Status NOT IN ('Delivered', 'Partially Delivered', 'Cancelled')
      AND (
          SELECT COUNT(*)
          FROM delivery d
          WHERE d.OrderID = o.OrderID AND d.Status <> 'Cancelled'
      ) = 0
    ORDER BY o.OrderID DESC
");
$editableOrders = $editableOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

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
        .order-form-tabs {
            display:flex; gap:8px; margin-bottom:22px;
            border-bottom:2px solid #e7ebf0; padding-bottom:2px;
        }
        .order-tab-btn {
            background:transparent; border:none; padding:10px 18px;
            font-size:14px; font-weight:600; color:#64748b; cursor:pointer;
            border-radius:8px 8px 0 0; border-bottom:3px solid transparent;
            margin-bottom:-2px; transition:all 0.2s ease;
        }
        .order-tab-btn:hover { color:#1e293b; background:#f8fafc; }
        .order-tab-btn.active { color:#3157c8; background:#eef2ff; border-bottom:3px solid #3157c8; }
        .csv-dropzone {
            border:2px dashed #cbd5e1; border-radius:10px; padding:28px 16px;
            text-align:center; background:#f8fafc; cursor:pointer; position:relative;
            transition:all 0.2s ease;
        }
        .csv-dropzone:hover, .csv-dropzone.dragover { border-color:#3157c8; background:#eef2ff; }
        .csv-dropzone input[type="file"] {
            position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:pointer;
        }
        .file-info {
            display:inline-flex; align-items:center; gap:8px; padding:7px 14px;
            background:#fff; border:1px solid #d1d5db; border-radius:6px;
            font-size:13px; font-weight:600; color:#1e293b;
        }
        .csv-preview { margin-top:18px; }
        .csv-preview-count { font-size:12px; font-weight:600; color:#067647; }
        .csv-preview-scroll {
            max-height:240px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;
        }
        .csv-preview-table { width:100%; border-collapse:collapse; font-size:12px; }
        .csv-preview-table th {
            background:#f8fafc; padding:8px 10px; text-align:left; color:#475569;
            font-weight:600; position:sticky; top:0; border-bottom:1px solid #e2e8f0;
        }
        .csv-preview-table td { padding:7px 10px; border-bottom:1px solid #f1f5f9; }
        .badge-ok { display:inline-block; padding:2px 7px; border-radius:12px; background:#ecfdf3; color:#067647; font-size:11px; font-weight:700; }
        .badge-warn { display:inline-block; padding:2px 7px; border-radius:12px; background:#fff4d6; color:#8a5b00; font-size:11px; font-weight:700; }
        .badge-err { display:inline-block; padding:2px 7px; border-radius:12px; background:#fee4e2; color:#b42318; font-size:11px; font-weight:700; }
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
        <a class="nav-link active" href="orders.php">📋 &nbsp; Hotel Orders</a>
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
                        <a class="btn btn-secondary" href="orders.php?export=<?= (int)$viewOrder['OrderID'] ?>" title="Export this order as CSV">📥 Export CSV</a>
                        <?php if (!in_array($viewOrder['Status'], ['Cancelled', 'Delivered', 'Partially Delivered'], true)): ?>
                            <a class="btn btn-secondary" href="orders.php?edit=<?= (int)$viewOrder['OrderID'] ?>#import">📄 Update via CSV/Excel</a>
                        <?php endif; ?>
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
                    <h2><?= !empty($formOrder['OrderID']) ? 'Edit Hotel Order #' . (int)$formOrder['OrderID'] : 'Record or Update Hotel Order' ?></h2>
                    <p class="muted" style="margin:6px 0 0">Create or update hotel orders using manual entry or CSV / Excel upload.</p>
                </div>
                <?php if (!empty($formOrder['OrderID'])): ?>
                    <a class="btn btn-secondary" href="orders.php">Cancel Editing</a>
                <?php endif; ?>
            </div>

            <!-- TABS -->
            <div class="order-form-tabs">
                <button type="button" class="order-tab-btn active" id="tabBtnManual">
                    ✏️ Manual Order Form
                </button>
                <button type="button" class="order-tab-btn" id="tabBtnImport">
                    📄 Import CSV / Excel
                </button>
            </div>

            <?php if (!$hotels): ?>
                <div class="alert alert-error">No active hotels found. Add a hotel before recording an order.</div>
            <?php elseif (!$items): ?>
                <div class="alert alert-error">No active items found. Add items before recording an order.</div>
            <?php else: ?>
                <!-- TAB 1: MANUAL FORM -->
                <div id="tabContentManual">
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
                                                        data-unit="<?= e($item['UnitSymbol']) ?>"
                                                        data-name="<?= e($item['ItemName']) ?>"
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
                </div>

                <!-- TAB 2: IMPORT CSV / EXCEL FORM -->
                <div id="tabContentImport" style="display:none">
                    <div style="background:#f0f7ff;border:1px solid #c7dcfb;border-radius:8px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#1e3a8a;line-height:1.5">
                        <div style="font-weight:700;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                            <span>📄 Upload Hotel Order Sheet (.csv, .xlsx, .xls)</span>
                            <a href="orders.php?download_template=1" class="btn btn-secondary btn-small" style="background:#fff;border:1px solid #93c5fd;color:#1d4ed8;font-weight:600">
                                📥 Download CSV Order Template
                            </a>
                        </div>
                        Upload orders received from hotels in CSV or Excel format. You can create a new order or update an existing order that has no active deliveries. Columns supported: <strong>ItemCode</strong> (or Code / ItemName) and <strong>Quantity</strong> (or Qty). Items measured in <strong>NOS</strong> must have whole number quantities.
                    </div>

                    <form method="POST" action="orders.php" enctype="multipart/form-data" id="orderImportForm">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="import_order">

                        <div class="form-grid">
                            <div class="field full">
                                <label for="import_target_order">Destination Order *</label>
                                <select name="target_order_id" id="import_target_order" required>
                                    <option value="0">➕ Create a New Hotel Order</option>
                                    <?php if (!empty($editableOrders)): ?>
                                        <optgroup label="Update Existing Editable Orders (No active deliveries)">
                                            <?php foreach ($editableOrders as $eo): ?>
                                                <option value="<?= (int)$eo['OrderID'] ?>"
                                                    <?= (!empty($formOrder['OrderID']) && (int)$formOrder['OrderID'] === (int)$eo['OrderID']) ? 'selected' : '' ?>>
                                                    Update Order #<?= (int)$eo['OrderID'] ?> — <?= e($eo['HotelName']) ?> (<?= e(substr((string)$eo['OrderDate'], 0, 10)) ?> | <?= (int)$eo['ItemCount'] ?> items)
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                </select>
                                <span class="help-text">Choose whether to record a new order or update an existing order without deliveries.</span>
                            </div>

                            <div class="field">
                                <label for="import_hotel_id">Hotel *</label>
                                <select name="hotel_id" id="import_hotel_id" required>
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
                                <label for="import_order_date">Order Date *</label>
                                <input type="date" name="order_date" id="import_order_date"
                                       value="<?= e(substr((string)$formOrder['OrderDate'], 0, 10)) ?>" required>
                            </div>

                            <div class="field">
                                <label for="import_email_received_at">Email Received Date & Time</label>
                                <input type="datetime-local" name="email_received_at" id="import_email_received_at"
                                       value="<?php
                                       $emailVal = (string)($formOrder['EmailReceivedAt'] ?? '');
                                       echo e($emailVal ? date('Y-m-d\TH:i', strtotime($emailVal)) : '');
                                       ?>">
                            </div>

                            <div class="field">
                                <label for="import_email_reference">Email Reference</label>
                                <input type="text" name="email_reference" id="import_email_reference" maxlength="100"
                                       placeholder="e.g. Gmail message ID or reference"
                                       value="<?= e($formOrder['EmailReference']) ?>">
                            </div>

                            <div class="field full">
                                <label for="import_email_subject">Email Subject</label>
                                <input type="text" name="email_subject" id="import_email_subject" maxlength="255"
                                       placeholder="Paste the subject line from the hotel's email"
                                       value="<?= e($formOrder['EmailSubject']) ?>">
                            </div>

                            <div class="field">
                                <label for="import_status">Order Status</label>
                                <select name="status" id="import_status">
                                    <option value="Received" <?= $formOrder['Status'] === 'Received' ? 'selected' : '' ?>>Received</option>
                                    <option value="Confirmed" <?= $formOrder['Status'] === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                </select>
                            </div>

                            <div class="field">
                                <label for="import_notes">Order Notes</label>
                                <textarea name="notes" id="import_notes" placeholder="Special instructions or notes"><?= e($formOrder['Notes']) ?></textarea>
                            </div>

                            <div class="field full">
                                <label>Order Spreadsheet File (.csv, .xlsx, .xls) *</label>
                                <div class="csv-dropzone" id="orderDropzone">
                                    <input type="file" id="order_file" name="order_file"
                                           accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required>
                                    <div style="font-size:32px;margin-bottom:8px">📁</div>
                                    <div class="drop-text" style="font-size:14px;color:#334155">
                                        <strong>Click to browse</strong> or drag & drop your CSV or Excel file here
                                    </div>
                                    <div class="help-text" style="margin-top:6px">Supported: .csv, .xlsx, .xls (up to 10 MB)</div>
                                    <div class="file-info" id="orderFileInfo" style="display:none;margin-top:12px"></div>
                                </div>
                            </div>
                        </div>

                        <!-- CLIENT-SIDE PREVIEW SECTION -->
                        <div class="csv-preview" id="orderPreview" style="display:none">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
                                <div class="csv-preview-count" id="orderPreviewCount"></div>
                                <button type="button" class="btn btn-secondary btn-small" id="clearOrderFileBtn">Clear File</button>
                            </div>
                            <div class="csv-preview-scroll">
                                <table class="csv-preview-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40px">#</th>
                                            <th>Item Code</th>
                                            <th>Item Name</th>
                                            <th>Unit</th>
                                            <th>Quantity</th>
                                            <th>Notes</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="orderPreviewBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top:24px">
                            <a href="orders.php?download_template=1" class="btn btn-secondary">📥 Download Template</a>
                            <button type="submit" class="btn btn-primary" id="orderImportSubmitBtn">
                                📄 Import & Save Hotel Order
                            </button>
                        </div>
                    </form>
                </div>
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
                                    <a class="btn btn-secondary btn-small" href="orders.php?export=<?= (int)$order['OrderID'] ?>" title="Export CSV">📥 Export</a>
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
                                        <a class="btn btn-secondary btn-small" href="orders.php?edit=<?= (int)$order['OrderID'] ?>#import" title="Update items via CSV or Excel">📄 Import Update</a>
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
const systemItems = <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const editableOrdersData = <?= json_encode($editableOrders ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

(function () {
    // Tab switching
    const tabBtnManual = document.getElementById('tabBtnManual');
    const tabBtnImport = document.getElementById('tabBtnImport');
    const tabContentManual = document.getElementById('tabContentManual');
    const tabContentImport = document.getElementById('tabContentImport');

    function switchTab(tab) {
        if (!tabBtnManual || !tabBtnImport) return;
        if (tab === 'import') {
            tabBtnImport.classList.add('active');
            tabBtnManual.classList.remove('active');
            if (tabContentImport) tabContentImport.style.display = 'block';
            if (tabContentManual) tabContentManual.style.display = 'none';
            if (window.location.hash !== '#import') {
                history.replaceState(null, '', '#import');
            }
        } else {
            tabBtnManual.classList.add('active');
            tabBtnImport.classList.remove('active');
            if (tabContentManual) tabContentManual.style.display = 'block';
            if (tabContentImport) tabContentImport.style.display = 'none';
            if (window.location.hash === '#import') {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        }
    }

    if (tabBtnManual && tabBtnImport) {
        tabBtnManual.addEventListener('click', () => switchTab('manual'));
        tabBtnImport.addEventListener('click', () => switchTab('import'));
        if (window.location.hash === '#import') {
            switchTab('import');
        }
    }

    // Target order change sync
    const targetOrderSelect = document.getElementById('import_target_order');
    const importHotelSelect = document.getElementById('import_hotel_id');
    const importOrderDate = document.getElementById('import_order_date');
    const importEmailRef = document.getElementById('import_email_reference');
    const importEmailSub = document.getElementById('import_email_subject');

    if (targetOrderSelect) {
        targetOrderSelect.addEventListener('change', function () {
            const orderId = parseInt(this.value, 10);
            if (orderId > 0 && Array.isArray(editableOrdersData)) {
                const found = editableOrdersData.find(o => parseInt(o.OrderID, 10) === orderId);
                if (found) {
                    if (importHotelSelect) importHotelSelect.value = found.HotelID;
                    if (importOrderDate && found.OrderDate) importOrderDate.value = found.OrderDate.substring(0, 10);
                    if (importEmailSub && found.EmailSubject) importEmailSub.value = found.EmailSubject;
                    if (importEmailRef && found.EmailReference) importEmailRef.value = found.EmailReference;
                }
            }
        });
    }

    // Dropzone & client preview
    const fileInput = document.getElementById('order_file');
    const dropzone = document.getElementById('orderDropzone');
    const fileInfo = document.getElementById('orderFileInfo');
    const preview = document.getElementById('orderPreview');
    const previewBody = document.getElementById('orderPreviewBody');
    const previewCount = document.getElementById('orderPreviewCount');
    const clearBtn = document.getElementById('clearOrderFileBtn');

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function handleFileChange(file) {
        if (!file) return;
        const sizeKb = (file.size / 1024).toFixed(1);
        fileInfo.innerHTML = '📄 <strong>' + escapeHtml(file.name) + '</strong> (' + sizeKb + ' KB)';
        fileInfo.style.display = 'inline-flex';

        const ext = file.name.split('.').pop().toLowerCase();
        if (ext === 'csv' || ext === 'txt') {
            const reader = new FileReader();
            reader.onload = function (e) {
                parseAndPreviewCsv(e.target.result);
            };
            reader.readAsText(file);
        } else {
            preview.style.display = 'block';
            previewCount.innerHTML = '📊 Excel file loaded: <strong>' + escapeHtml(file.name) + '</strong> (' + sizeKb + ' KB). Ready to upload and process.';
            previewBody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;color:#64748b">Excel workbook detected. Item codes and quantities will be processed by the server upon submission.</td></tr>';
        }
    }

    function parseAndPreviewCsv(text) {
        if (!text || !text.trim()) return;
        if (text.charCodeAt(0) === 0xFEFF) text = text.substr(1);
        const lines = text.split(/\r\n|\n|\r/).filter(l => l.trim() !== '');
        if (!lines.length) return;

        const sample = lines[0];
        let delim = ',';
        if ((sample.match(/;/g) || []).length > (sample.match(/,/g) || []).length) delim = ';';
        if ((sample.match(/\t/g) || []).length > (sample.match(new RegExp(delim, 'g')) || []).length) delim = '\t';

        const rows = lines.map(line => {
            const result = [];
            let cur = '';
            let inQuotes = false;
            for (let i = 0; i < line.length; i++) {
                const ch = line[i];
                if (ch === '"') {
                    if (inQuotes && line[i + 1] === '"') {
                        cur += '"';
                        i++;
                    } else {
                        inQuotes = !inQuotes;
                    }
                } else if (ch === delim && !inQuotes) {
                    result.push(cur.trim());
                    cur = '';
                } else {
                    cur += ch;
                }
            }
            result.push(cur.trim());
            return result;
        });

        if (!rows.length) return;

        let codeIdx = 0, nameIdx = 1, qtyIdx = 3, notesIdx = 4;
        let startIdx = 0;
        const cleanHeader = rows[0].map(c => c.toLowerCase().replace(/[^a-z0-9]/g, ''));
        let fCode = -1, fName = -1, fQty = -1, fNotes = -1;

        cleanHeader.forEach((h, idx) => {
            if (fCode === -1 && (h.includes('code') || h.includes('itemno') || h.includes('sku') || h === 'id')) fCode = idx;
            if (fQty === -1 && (h.includes('qty') || h.includes('quantity') || h.includes('amount') || h.includes('ordered'))) fQty = idx;
            if (fName === -1 && (h.includes('name') || h.includes('item') || h.includes('desc') || h.includes('product'))) fName = idx;
            if (fNotes === -1 && (h.includes('note') || h.includes('remark') || h.includes('comment'))) fNotes = idx;
        });

        if ((fCode !== -1 || fName !== -1) && fQty !== -1) {
            startIdx = 1;
            codeIdx = fCode !== -1 ? fCode : -1;
            nameIdx = fName !== -1 ? fName : -1;
            qtyIdx = fQty;
            notesIdx = fNotes;
        } else {
            if (rows[0].length >= 3) {
                codeIdx = 0; nameIdx = 1; qtyIdx = 2; notesIdx = 3;
            } else if (rows[0].length === 2) {
                codeIdx = 0; nameIdx = -1; qtyIdx = 1; notesIdx = -1;
            }
        }

        const itemsMapByCode = {};
        const itemsMapByName = {};
        if (Array.isArray(systemItems)) {
            systemItems.forEach(it => {
                if (it.ItemCode) itemsMapByCode[it.ItemCode.toLowerCase().trim()] = it;
                if (it.ItemName) itemsMapByName[it.ItemName.toLowerCase().trim()] = it;
            });
        }

        let validCount = 0;
        let totalQty = 0;
        let tableHtml = '';

        for (let i = startIdx; i < rows.length; i++) {
            const r = rows[i];
            const rawCode = codeIdx >= 0 ? (r[codeIdx] || '') : '';
            const rawName = nameIdx >= 0 ? (r[nameIdx] || '') : '';
            const rawQty = qtyIdx >= 0 ? (r[qtyIdx] || '') : '';
            const rawNote = notesIdx >= 0 ? (r[notesIdx] || '') : '';

            if (!rawCode && !rawName && !rawQty) continue;

            let matched = null;
            if (rawCode && itemsMapByCode[rawCode.toLowerCase()]) matched = itemsMapByCode[rawCode.toLowerCase()];
            if (!matched && rawName && itemsMapByName[rawName.toLowerCase()]) matched = itemsMapByName[rawName.toLowerCase()];

            const cleanQty = parseFloat(rawQty.replace(/[^\d.]/g, ''));
            const hasValidQty = !isNaN(cleanQty) && cleanQty > 0;
            let isNosError = false;

            if (matched && (matched.UnitSymbol || '').toUpperCase() === 'NOS') {
                if (!Number.isInteger(cleanQty) || cleanQty <= 0) {
                    isNosError = true;
                }
            }

            let badge = '';
            if (!matched) {
                badge = '<span class="badge-warn">Item Not Found</span>';
            } else if (!hasValidQty) {
                badge = '<span class="badge-err">Invalid Qty</span>';
            } else if (isNosError) {
                badge = '<span class="badge-err">NOS Integer Required</span>';
            } else {
                badge = '<span class="badge-ok">✓ Ready</span>';
                validCount++;
                totalQty += cleanQty;
            }

            tableHtml += '<tr>' +
                '<td>' + (i - startIdx + 1) + '</td>' +
                '<td><strong>' + escapeHtml(rawCode || (matched ? matched.ItemCode : '—')) + '</strong></td>' +
                '<td>' + escapeHtml(matched ? matched.ItemName : (rawName || '—')) + '</td>' +
                '<td>' + escapeHtml(matched ? matched.UnitSymbol : '—') + '</td>' +
                '<td>' + (hasValidQty ? cleanQty.toFixed(matched && matched.UnitSymbol === 'NOS' ? 0 : 3) : escapeHtml(rawQty)) + '</td>' +
                '<td>' + escapeHtml(rawNote || '—') + '</td>' +
                '<td>' + badge + '</td>' +
                '</tr>';
        }

        preview.style.display = 'block';
        previewCount.innerHTML = '✓ Previewing ' + (rows.length - startIdx) + ' row(s) (' + validCount + ' valid items, Total Qty: ' + totalQty.toFixed(3) + ')';
        previewBody.innerHTML = tableHtml || '<tr><td colspan="7" style="text-align:center;padding:12px;color:#64748b">No data rows found</td></tr>';
    }

    if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(name => {
            dropzone.addEventListener(name, e => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(name => {
            dropzone.addEventListener(name, e => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });
        dropzone.addEventListener('drop', e => {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                handleFileChange(fileInput.files[0]);
            }
        });
        fileInput.addEventListener('change', function () {
            if (this.files && this.files.length) {
                handleFileChange(this.files[0]);
            }
        });
    }

    if (clearBtn && fileInput) {
        clearBtn.addEventListener('click', function () {
            fileInput.value = '';
            fileInfo.style.display = 'none';
            fileInfo.innerHTML = '';
            preview.style.display = 'none';
            previewBody.innerHTML = '';
        });
    }

    // Existing manual form script
    const body = document.getElementById('orderItemsBody');
    const addButton = document.getElementById('addItemRow');
    const form = document.getElementById('hotelOrderForm');

    if (!body) return;

    function syncUnitSteps() {
        body.querySelectorAll('tr').forEach(function (row) {
            const select = row.querySelector('select[name="item_id[]"]');
            const qtyInput = row.querySelector('input[name="ordered_quantity[]"]');
            if (!select || !qtyInput) return;
            const opt = select.selectedOptions[0];
            const unit = opt ? (opt.getAttribute('data-unit') || '').toUpperCase() : '';
            if (unit === 'NOS') {
                qtyInput.step = '1';
                qtyInput.min = '1';
                qtyInput.placeholder = '0';
            } else {
                qtyInput.step = '0.001';
                qtyInput.min = '0.001';
                qtyInput.placeholder = '0.000';
            }
        });
    }

    function addRow() {
        const firstRow = body.querySelector('tr');
        if (!firstRow) return;

        const newRow = firstRow.cloneNode(true);
        newRow.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
        newRow.querySelectorAll('input').forEach(input => input.value = '');
        body.appendChild(newRow);
        syncUnitSteps();
    }

    if (addButton) {
        addButton.addEventListener('click', addRow);
    }

    body.addEventListener('change', function (e) {
        if (e.target && e.target.matches('select[name="item_id[]"]')) {
            syncUnitSteps();
        }
    });

    body.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-item');
        if (!button) return;

        const rows = body.querySelectorAll('tr');

        if (rows.length === 1) {
            rows[0].querySelectorAll('select').forEach(select => select.selectedIndex = 0);
            rows[0].querySelectorAll('input').forEach(input => input.value = '');
            syncUnitSteps();
            return;
        }

        button.closest('tr').remove();
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            let invalidNos = false;
            body.querySelectorAll('tr').forEach(function (row) {
                const select = row.querySelector('select[name="item_id[]"]');
                const qtyInput = row.querySelector('input[name="ordered_quantity[]"]');
                if (!select || !qtyInput) return;
                const opt = select.selectedOptions[0];
                const unit = opt ? (opt.getAttribute('data-unit') || '').toUpperCase() : '';
                const itemName = opt ? (opt.getAttribute('data-name') || opt.textContent.trim()) : 'Item';
                const val = parseFloat(qtyInput.value);
                if (unit === 'NOS' && (!Number.isInteger(val) || val <= 0)) {
                    invalidNos = itemName;
                }
            });
            if (invalidNos) {
                e.preventDefault();
                alert("Quantity for '" + invalidNos + "' must be a positive whole number (integer) because it is measured in NOS.");
            }
        });
    }

    syncUnitSteps();
})();
</script>
</body>
</html>
