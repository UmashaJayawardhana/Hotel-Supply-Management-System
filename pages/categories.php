
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid request. Refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }

            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM item_category
                 WHERE CategoryName = ? AND CategoryID <> ?'
            );
            $check->execute([$name, $id]);

            if ($check->fetchColumn() > 0) {
                throw new RuntimeException('This category already exists.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE item_category
                     SET CategoryName = ?, Description = ?
                     WHERE CategoryID = ?'
                );
                $stmt->execute([
                    $name,
                    $description !== '' ? $description : null,
                    $id
                ]);
                $_SESSION['category_flash'] = 'Category updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO item_category (CategoryName, Description)
                     VALUES (?, ?)'
                );
                $stmt->execute([
                    $name,
                    $description !== '' ? $description : null
                ]);
                $_SESSION['category_flash'] = 'Category added successfully.';
            }

            header('Location: categories.php');
            exit;
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['category_id'] ?? 0);
            $status = (int)($_POST['new_status'] ?? -1);

            if ($id < 1 || !in_array($status, [0, 1], true)) {
                throw new RuntimeException('Invalid category request.');
            }

            $stmt = $pdo->prepare(
                'UPDATE item_category SET IsActive = ? WHERE CategoryID = ?'
            );
            $stmt->execute([$status, $id]);

            $_SESSION['category_flash'] = $status
                ? 'Category activated successfully.'
                : 'Category deactivated successfully.';

            header('Location: categories.php');
            exit;
        }

        throw new RuntimeException('Invalid action.');
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $message = 'Database error. Please check the category table structure.';
        $messageType = 'error';
    } catch (RuntimeException $ex) {
        $message = $ex->getMessage();
        $messageType = 'error';
    }
}

if (isset($_SESSION['category_flash'])) {
    $message = $_SESSION['category_flash'];
    unset($_SESSION['category_flash']);
}

$editCategory = null;
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM item_category WHERE CategoryID = ?'
    );
    $stmt->execute([(int)$_GET['edit']]);
    $editCategory = $stmt->fetch();
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'all';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'CategoryName LIKE ?';
    $params[] = '%' . $search . '%';
}

if (in_array($status, ['active', 'inactive'], true)) {
    $where[] = 'IsActive = ?';
    $params[] = $status === 'active' ? 1 : 0;
}

$sql = 'SELECT CategoryID, CategoryName, Description, IsActive
        FROM item_category';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY CategoryName';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$total = (int)$pdo->query(
    'SELECT COUNT(*) FROM item_category'
)->fetchColumn();
$active = (int)$pdo->query(
    'SELECT COUNT(*) FROM item_category WHERE IsActive = 1'
)->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Category Management | Hotel Supply</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.page-title{margin-bottom:24px}
.page-title h1{margin:0 0 8px;font-size:26px}
.page-title p,.muted{color:#778198;font-size:13px}
.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;margin-bottom:20px}
.card,.panel{background:white;border:1px solid #e8ebf2;border-radius:12px;padding:20px}
.card span{font-size:12px;color:#778198}
.card strong{display:block;font-size:25px;margin-top:8px}
.layout{display:grid;grid-template-columns:minmax(250px,.8fr) minmax(0,1.7fr);gap:20px;align-items:start}
.panel h2{font-size:17px;margin:0 0 18px}
.field{margin-bottom:15px}
.field label{display:block;font-size:12px;font-weight:600;margin-bottom:7px}
.field input,.field textarea,.filters input,.filters select{width:100%;padding:11px;border:1px solid #dfe4ed;border-radius:7px;font:inherit;font-size:13px}
.field textarea{min-height:80px;resize:vertical}
.btn{display:inline-block;border:0;border-radius:7px;padding:10px 13px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;text-align:center}
.primary{background:#4658a9;color:white}.secondary{background:#eef1f7;color:#34405a}
.notice{padding:12px 14px;border-radius:8px;margin-bottom:18px;font-size:13px}
.success{background:#e8f8ef;color:#187447}.error{background:#fff0ef;color:#a93226}
.filters{display:grid;grid-template-columns:minmax(0,1fr) 120px auto;gap:8px;margin-bottom:16px}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:12px}
th,td{text-align:left;padding:12px 9px;border-bottom:1px solid #edf0f5;vertical-align:middle}
th{background:#f7f8fc;color:#778198;font-size:10px;text-transform:uppercase}
td{white-space:nowrap}td.description{white-space:normal;min-width:120px}
.pill{border-radius:20px;padding:5px 8px;font-size:10px;font-weight:bold}
.on{background:#e5f8ed;color:#197547}.off{background:#f0f1f4;color:#687184}
.actions{display:flex;gap:6px;align-items:center}.actions form{margin:0}
.empty{text-align:center;color:#778198;padding:25px}
@media(max-width:1000px){.layout{grid-template-columns:1fr}}
@media(max-width:650px){.cards{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.panel{padding:14px}}
</style>
</head>
<body>
<div class="app">
<aside class="sidebar">
    <div class="brand"><div class="brand-icon">HS</div>
        <div><h2>Hotel Supply</h2><small>Management System</small></div>
    </div>
    <p class="menu-label">MAIN MENU</p>
    <a class="nav-link" href="../index.php">▦ &nbsp; Dashboard</a>
    <a class="nav-link active" href="categories.php">▤ &nbsp; Categories</a>
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
    <a class="nav-link" href="payments.php">💳 &nbsp; Payments</a>
    <a class="nav-link" href="reports.php">📊 &nbsp; Reports</a>
</aside>

<main class="main">
    <div class="page-title">
        <h1>Category Management</h1>
        <p>Organize items into categories.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice <?= e($messageType) ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="cards">
        <div class="card"><span>Total categories</span><strong><?= $total ?></strong></div>
        <div class="card"><span>Active categories</span><strong><?= $active ?></strong></div>
    </div>

    <div class="layout">
        <section class="panel">
            <h2><?= $editCategory ? 'Edit category' : 'Add category' ?></h2>
            <form method="post" action="categories.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="category_id" value="<?= (int)($editCategory['CategoryID'] ?? 0) ?>">

                <div class="field">
                    <label for="category_name">Category name *</label>
                    <input id="category_name" name="category_name" required maxlength="100"
                           placeholder="e.g. Fruits"
                           value="<?= e($editCategory['CategoryName'] ?? '') ?>">
                </div>

                <div class="field">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="500"
                              placeholder="Optional details"><?= e($editCategory['Description'] ?? '') ?></textarea>
                </div>

                <button class="btn primary" type="submit">
                    <?= $editCategory ? 'Save changes' : 'Add category' ?>
                </button>
                <?php if ($editCategory): ?>
                    <a class="btn secondary" href="categories.php">Cancel</a>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel">
            <h2>Categories</h2>
            <form method="get" class="filters">
                <input type="search" name="search" placeholder="Search categories..."
                       value="<?= e($search) ?>">
                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button class="btn primary" type="submit">Search</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Category</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$categories): ?>
                        <tr><td colspan="4" class="empty">No categories found.</td></tr>
                    <?php else: foreach ($categories as $c): ?>
                        <tr>
                            <td><strong><?= e($c['CategoryName']) ?></strong></td>
                            <td class="description"><?= e($c['Description'] ?? '') ?></td>
                            <td><span class="pill <?= $c['IsActive'] ? 'on' : 'off' ?>">
                                <?= $c['IsActive'] ? 'Active' : 'Inactive' ?></span></td>
                            <td><div class="actions">
                                <a class="btn secondary" href="categories.php?edit=<?= (int)$c['CategoryID'] ?>">Edit</a>
                                <form method="post" onsubmit="return confirm('Change this category status?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="category_id" value="<?= (int)$c['CategoryID'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $c['IsActive'] ? 0 : 1 ?>">
                                    <button class="btn <?= $c['IsActive'] ? 'secondary' : 'primary' ?>" type="submit">
                                        <?= $c['IsActive'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </div></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
</div>
</body>
</html>
