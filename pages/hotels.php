
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
            $id = (int)($_POST['hotel_id'] ?? 0);
            $name = trim($_POST['hotel_name'] ?? '');
            $contactPerson = trim($_POST['contact_person'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');

            $formValues = $_POST;

            if ($name === '') {
                throw new RuntimeException('Hotel name is required.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }

            if (strlen($name) > 150) {
                throw new RuntimeException('Hotel name is too long.');
            }

            if (strlen($contactPerson) > 150) {
                throw new RuntimeException('Contact person name is too long.');
            }

            if (strlen($email) > 150 || strlen($phone) > 30) {
                throw new RuntimeException('Email or phone number is too long.');
            }

            if (strlen($address) > 1000) {
                throw new RuntimeException('Address is too long.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE hotel
                     SET HotelName = ?,
                         ContactPerson = ?,
                         Email = ?,
                         Phone = ?,
                         Address = ?
                     WHERE HotelID = ?'
                );

                $stmt->execute([
                    $name,
                    $contactPerson !== '' ? $contactPerson : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $address !== '' ? $address : null,
                    $id
                ]);

                $_SESSION['hotel_flash'] = 'Hotel updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO hotel
                        (HotelName, ContactPerson, Email, Phone, Address)
                     VALUES (?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $name,
                    $contactPerson !== '' ? $contactPerson : null,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $address !== '' ? $address : null
                ]);

                $_SESSION['hotel_flash'] = 'Hotel added successfully.';
            }

            header('Location: hotels.php');
            exit;
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['hotel_id'] ?? 0);
            $status = (int)($_POST['new_status'] ?? -1);

            if ($id < 1 || !in_array($status, [0, 1], true)) {
                throw new RuntimeException('Invalid hotel request.');
            }

            $stmt = $pdo->prepare(
                'UPDATE hotel SET IsActive = ? WHERE HotelID = ?'
            );
            $stmt->execute([$status, $id]);

            $_SESSION['hotel_flash'] = $status
                ? 'Hotel activated successfully.'
                : 'Hotel deactivated successfully.';

            header('Location: hotels.php');
            exit;
        }

        throw new RuntimeException('Invalid action.');

    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $message = 'Database error. Please check the hotel table structure.';
        $messageType = 'error';
    } catch (RuntimeException $ex) {
        $message = $ex->getMessage();
        $messageType = 'error';
    }
}

/* -------------------- FLASH MESSAGE -------------------- */

if (isset($_SESSION['hotel_flash'])) {
    $message = $_SESSION['hotel_flash'];
    unset($_SESSION['hotel_flash']);
}

/* -------------------- EDIT HOTEL -------------------- */

$editHotel = null;

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM hotel WHERE HotelID = ?'
    );
    $stmt->execute([(int)$_GET['edit']]);
    $editHotel = $stmt->fetch();

    if (!$editHotel) {
        $message = 'Hotel not found.';
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
        HotelName LIKE ?
        OR ContactPerson LIKE ?
        OR Email LIKE ?
        OR Phone LIKE ?
        OR Address LIKE ?
    )';

    $term = '%' . $search . '%';
    $params = array_merge($params, array_fill(0, 5, $term));
}

if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'IsActive = ?';
    $params[] = $status === 'active' ? 1 : 0;
}

$sql = 'SELECT HotelID, HotelName, ContactPerson, Email, Phone, Address, IsActive
        FROM hotel';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY HotelName';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hotels = $stmt->fetchAll();

/* -------------------- COUNTS -------------------- */

$total = (int)$pdo->query(
    'SELECT COUNT(*) FROM hotel'
)->fetchColumn();

$active = (int)$pdo->query(
    'SELECT COUNT(*) FROM hotel WHERE IsActive = 1'
)->fetchColumn();

$inactive = $total - $active;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hotel Management | Hotel Supply</title>
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
    <a class="nav-link active" href="hotels.php">🏨 &nbsp; Hotels</a>
    <a class="nav-link" href="vendors.php">🛒 &nbsp; Market Vendors</a>

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
        <h1>Hotel Management</h1>
        <p>Manage hotels, contact information and delivery customers.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="cards">
        <div class="card">
            <span>Total hotels</span>
            <strong><?= $total ?></strong>
        </div>
        <div class="card">
            <span>Active hotels</span>
            <strong><?= $active ?></strong>
        </div>
        <div class="card">
            <span>Inactive hotels</span>
            <strong><?= $inactive ?></strong>
        </div>
    </div>

    <div class="layout">

        <!-- ADD / EDIT HOTEL -->
        <section class="panel">
            <h2><?= $editHotel ? 'Edit hotel' : 'Add hotel' ?></h2>

            <form method="post" action="hotels.php">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="hotel_id"
                       value="<?= (int)($editHotel['HotelID'] ?? 0) ?>">

                <div class="field">
                    <label for="hotel_name">Hotel name *</label>
                    <input
                        id="hotel_name"
                        name="hotel_name"
                        required
                        maxlength="150"
                        placeholder="e.g. Ocean View Hotel"
                        value="<?= e($editHotel['HotelName'] ?? $formValues['hotel_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="contact_person">Contact person</label>
                    <input
                        id="contact_person"
                        name="contact_person"
                        maxlength="150"
                        placeholder="Contact person's name"
                        value="<?= e($editHotel['ContactPerson'] ?? $formValues['contact_person'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="150"
                        placeholder="hotel@example.com"
                        value="<?= e($editHotel['Email'] ?? $formValues['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="phone">Phone number</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        placeholder="e.g. 041 222 3344"
                        value="<?= e($editHotel['Phone'] ?? $formValues['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="address">Hotel address</label>
                    <textarea
                        id="address"
                        name="address"
                        maxlength="1000"
                        placeholder="Enter the hotel address"><?= e($editHotel['Address'] ?? $formValues['address'] ?? '') ?></textarea>
                </div>

                <button class="btn primary" type="submit">
                    <?= $editHotel ? 'Save changes' : 'Add hotel' ?>
                </button>

                <?php if ($editHotel): ?>
                    <a class="btn secondary" href="hotels.php">Cancel</a>
                <?php endif; ?>
            </form>
        </section>

        <!-- HOTEL LIST -->
        <section class="panel">
            <h2>Hotels</h2>

            <form method="get" action="hotels.php" class="filters">
                <input
                    type="search"
                    name="search"
                    placeholder="Search hotels or contacts..."
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
                            <th>Hotel</th>
                            <th>Contact</th>
                            <th>Phone / Email</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$hotels): ?>
                        <tr>
                            <td colspan="6" class="empty">No hotels found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($hotels as $hotel): ?>
                            <tr>
                                <td>
                                    <strong><?= e($hotel['HotelName']) ?></strong>
                                </td>

                                <td><?= e($hotel['ContactPerson'] ?: '—') ?></td>

                                <td>
                                    <?= e($hotel['Phone'] ?: '—') ?>
                                    <?php if (!empty($hotel['Email'])): ?>
                                        <br>
                                        <span class="muted"><?= e($hotel['Email']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="address">
                                    <?= e($hotel['Address'] ?: '—') ?>
                                </td>

                                <td>
                                    <span class="pill <?= $hotel['IsActive'] ? 'on' : 'off' ?>">
                                        <?= $hotel['IsActive'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions">
                                        <a class="btn secondary"
                                           href="hotels.php?edit=<?= (int)$hotel['HotelID'] ?>">
                                            Edit
                                        </a>

                                        <form method="post"
                                              action="hotels.php"
                                              onsubmit="return confirm('Change this hotel status?');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="hotel_id"
                                                   value="<?= (int)$hotel['HotelID'] ?>">
                                            <input type="hidden" name="new_status"
                                                   value="<?= $hotel['IsActive'] ? 0 : 1 ?>">

                                            <button
                                                class="btn <?= $hotel['IsActive'] ? 'danger' : 'primary' ?>"
                                                type="submit">
                                                <?= $hotel['IsActive'] ? 'Deactivate' : 'Activate' ?>
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
