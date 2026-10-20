<?php
session_start();
require_once __DIR__ . '/../config/database.php';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';
$formValues = [];

/* -------------------- SAVE / UPDATE / STATUS -------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid request. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['vendor_id'] ?? 0);
            $name = trim($_POST['vendor_name'] ?? '');
            $contactPerson = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $formValues = $_POST;

            if ($name === '') {
                throw new RuntimeException('Vendor name is required.');
            }

            if (strlen($name) > 150) {
                throw new RuntimeException('Vendor name is too long.');
            }

            if (strlen($contactPerson) > 150) {
                throw new RuntimeException('Contact person name is too long.');
            }

            if (strlen($phone) > 30) {
                throw new RuntimeException('Phone number is too long.');
            }

            if (strlen($address) > 1000) {
                throw new RuntimeException('Address is too long.');
            }

            if (strlen($notes) > 2000) {
                throw new RuntimeException('Notes are too long.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE market_vendor
                     SET VendorName = ?,
                         ContactPerson = ?,
                         Phone = ?,
                         Address = ?,
                         Notes = ?
                     WHERE VendorID = ?'
                );

                $stmt->execute([
                    $name,
                    $contactPerson !== '' ? $contactPerson : null,
                    $phone !== '' ? $phone : null,
                    $address !== '' ? $address : null,
                    $notes !== '' ? $notes : null,
                    $id
                ]);

                $_SESSION['vendor_flash'] = 'Vendor updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO market_vendor
                        (VendorName, ContactPerson, Phone, Address, Notes)
                     VALUES (?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $name,
                    $contactPerson !== '' ? $contactPerson : null,
                    $phone !== '' ? $phone : null,
                    $address !== '' ? $address : null,
                    $notes !== '' ? $notes : null
                ]);

                $_SESSION['vendor_flash'] = 'Vendor added successfully.';
            }

            header('Location: vendors.php');
            exit;
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['vendor_id'] ?? 0);
            $status = (int)($_POST['new_status'] ?? -1);

            if ($id < 1 || !in_array($status, [0, 1], true)) {
                throw new RuntimeException('Invalid vendor request.');
            }

            $stmt = $pdo->prepare(
                'UPDATE market_vendor SET IsActive = ? WHERE VendorID = ?'
            );
            $stmt->execute([$status, $id]);

            $_SESSION['vendor_flash'] = $status
                ? 'Vendor activated successfully.'
                : 'Vendor deactivated successfully.';

            header('Location: vendors.php');
            exit;
        }

        throw new RuntimeException('Invalid action.');

    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $message = 'Database error. Please check the vendor table structure.';
        $messageType = 'error';
    } catch (RuntimeException $ex) {
        $message = $ex->getMessage();
        $messageType = 'error';
    }
}

/* -------------------- FLASH MESSAGE -------------------- */

if (isset($_SESSION['vendor_flash'])) {
    $message = $_SESSION['vendor_flash'];
    unset($_SESSION['vendor_flash']);
}

/* -------------------- EDIT VENDOR -------------------- */

$editVendor = null;

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM market_vendor WHERE VendorID = ?'
    );
    $stmt->execute([(int)$_GET['edit']]);
    $editVendor = $stmt->fetch();

    if (!$editVendor) {
        $message = 'Vendor not found.';
        $messageType = 'error';
    }
}

/* -------------------- SEARCH AND FILTER -------------------- */

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        VendorName LIKE ?
        OR ContactPerson LIKE ?
        OR Phone LIKE ?
        OR Address LIKE ?
        OR Notes LIKE ?
    )';

    $term = '%' . $search . '%';
    $params = array_merge($params, array_fill(0, 5, $term));
}

if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'IsActive = ?';
    $params[] = $status === 'active' ? 1 : 0;
}

$sql = 'SELECT VendorID, VendorName, ContactPerson, Phone, Address, Notes, IsActive
        FROM market_vendor';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY VendorName';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vendors = $stmt->fetchAll();

/* -------------------- COUNTS -------------------- */

$total = (int)$pdo->query(
    'SELECT COUNT(*) FROM market_vendor'
)->fetchColumn();

$active = (int)$pdo->query(
    'SELECT COUNT(*) FROM market_vendor WHERE IsActive = 1'
)->fetchColumn();

$inactive = $total - $active;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vendor Management | Hotel Supply</title>
<link rel="stylesheet" href="../assets/css/style.css">

<style>
.page-title{margin-bottom:24px}
.page-title h1{margin:0 0 8px;font-size:26px}
.page-title p,.muted{color:#778198;font-size:13px}
.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:20px}
.card,.panel{background:white;border:1px solid #e8ebf2;border-radius:12px;padding:20px}
.card span{font-size:12px;color:#778198}
.card strong{display:block;font-size:25px;margin-top:8px}
.layout{display:grid;grid-template-columns:minmax(250px,.8fr) minmax(0,1.7fr);gap:20px;align-items:start}
.panel h2{font-size:17px;margin:0 0 18px}
.field{margin-bottom:15px}
.field label{display:block;font-size:12px;font-weight:600;margin-bottom:7px}
.field input,.field textarea,.filters input,.filters select{
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
.filters{display:grid;grid-template-columns:minmax(0,1fr) 120px auto;gap:8px;margin-bottom:16px}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:12px}
th,td{text-align:left;padding:12px 9px;border-bottom:1px solid #edf0f5;vertical-align:middle}
th{background:#f7f8fc;color:#778198;font-size:10px;text-transform:uppercase}
td{white-space:nowrap}
td.address{white-space:normal;min-width:140px;max-width:220px}
td.notes{white-space:normal;min-width:140px;max-width:220px}
.pill{border-radius:20px;padding:5px 8px;font-size:10px;font-weight:bold}
.on{background:#e5f8ed;color:#197547}
.off{background:#f0f1f4;color:#687184}
.actions{display:flex;gap:6px;align-items:center}
.actions form{margin:0}
.empty{text-align:center;color:#778198;padding:25px}
@media(max-width:1000px){.layout{grid-template-columns:1fr}}
@media(max-width:650px){
    .cards{grid-template-columns:1fr}
    .filters{grid-template-columns:1fr}
    .panel{padding:14px}
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
    <a class="nav-link active" href="vendors.php">🛒 &nbsp; Market Vendors</a>

    <p class="menu-label">OPERATIONS</p>
    <a class="nav-link" href="prices.php">💰 &nbsp; Price Lists</a>
    <a class="nav-link" href="purchases.php">🧾 &nbsp; Market Purchases</a>
    <a class="nav-link" href="orders.php">📋 &nbsp; Hotel Orders</a>
    <a class="nav-link" href="deliveries.php">🚚 &nbsp; Deliveries</a>
    <a class="nav-link" href="invoices.php">📄 &nbsp; Invoices</a>
    <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
    <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
</aside>

<main class="main">

    <div class="page-title">
        <h1>Market Vendors</h1>
        <p>Manage market vendors, contact information and purchase sources.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="cards">
        <div class="card">
            <span>Total vendors</span>
            <strong><?= $total ?></strong>
        </div>
        <div class="card">
            <span>Active vendors</span>
            <strong><?= $active ?></strong>
        </div>
        <div class="card">
            <span>Inactive vendors</span>
            <strong><?= $inactive ?></strong>
        </div>
    </div>

    <div class="layout">

        <!-- ADD / EDIT VENDOR -->
        <section class="panel">
            <h2><?= $editVendor ? 'Edit vendor' : 'Add vendor' ?></h2>

            <form method="post" action="vendors.php">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="vendor_id"
                       value="<?= (int)($editVendor['VendorID'] ?? 0) ?>">

                <div class="field">
                    <label for="vendor_name">Vendor name *</label>
                    <input
                        id="vendor_name"
                        name="vendor_name"
                        required
                        maxlength="150"
                        placeholder="e.g. Fresh Veggies Market"
                        value="<?= e($editVendor['VendorName'] ?? $formValues['vendor_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="contact_person">Contact person</label>
                    <input
                        id="contact_person"
                        name="contact_person"
                        maxlength="150"
                        placeholder="Contact person's name"
                        value="<?= e($editVendor['ContactPerson'] ?? $formValues['contact_person'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="phone">Phone number</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        placeholder="e.g. 077 123 4567"
                        value="<?= e($editVendor['Phone'] ?? $formValues['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="address">Vendor address</label>
                    <textarea
                        id="address"
                        name="address"
                        maxlength="1000"
                        placeholder="Enter the vendor address"><?= e($editVendor['Address'] ?? $formValues['address'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea
                        id="notes"
                        name="notes"
                        maxlength="2000"
                        placeholder="Optional notes about this vendor"><?= e($editVendor['Notes'] ?? $formValues['notes'] ?? '') ?></textarea>
                </div>

                <button class="btn primary" type="submit">
                    <?= $editVendor ? 'Save changes' : 'Add vendor' ?>
                </button>

                <?php if ($editVendor): ?>
                    <a class="btn secondary" href="vendors.php">Cancel</a>
                <?php endif; ?>
            </form>
        </section>

        <!-- VENDOR LIST -->
        <section class="panel">
            <h2>Vendors</h2>

            <form method="get" action="vendors.php" class="filters">
                <input
                    type="search"
                    name="search"
                    placeholder="Search vendors or contacts..."
                    value="<?= e($search) ?>">

                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                        All statuses
                    </option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>
                        Active
                    </option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>
                        Inactive
                    </option>
                </select>

                <button class="btn primary" type="submit">Search</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Contact</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$vendors): ?>
                        <tr>
                            <td colspan="7" class="empty">No vendors found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vendors as $vendor): ?>
                            <tr>
                                <td>
                                    <strong><?= e($vendor['VendorName']) ?></strong>
                                </td>

                                <td><?= e($vendor['ContactPerson'] ?: '—') ?></td>

                                <td><?= e($vendor['Phone'] ?: '—') ?></td>

                                <td class="address">
                                    <?= e($vendor['Address'] ?: '—') ?>
                                </td>

                                <td class="notes">
                                    <?= e($vendor['Notes'] ?: '—') ?>
                                </td>

                                <td>
                                    <span class="pill <?= $vendor['IsActive'] ? 'on' : 'off' ?>">
                                        <?= $vendor['IsActive'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions">
                                        <a class="btn secondary"
                                           href="vendors.php?edit=<?= (int)$vendor['VendorID'] ?>">
                                            Edit
                                        </a>

                                        <form method="post"
                                              action="vendors.php"
                                              onsubmit="return confirm('Change this vendor status?');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="vendor_id"
                                                   value="<?= (int)$vendor['VendorID'] ?>">
                                            <input type="hidden" name="new_status"
                                                   value="<?= $vendor['IsActive'] ? 0 : 1 ?>">

                                            <button
                                                class="btn <?= $vendor['IsActive'] ? 'danger' : 'primary' ?>"
                                                type="submit">
                                                <?= $vendor['IsActive'] ? 'Deactivate' : 'Activate' ?>
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
        </section>

    </div>
</main>
</div>
</body>
</html>