
<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

require_once __DIR__ . '/../config/database.php';

function userEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function userRedirect(string $url = 'users.php'): void
{
    header('Location: ' . $url);
    exit;
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['users_csrf'];
$roles = ['Admin', 'Manager', 'Staff'];
$errors = [];
$successMessage = $_SESSION['users_flash'] ?? '';
unset($_SESSION['users_flash']);

$form = [
    'UserID' => '',
    'FullName' => '',
    'Username' => '',
    'Role' => 'Staff',
    'IsActive' => 1
];

$isEditing = false;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Your session token is invalid. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = filter_var($_POST['UserID'] ?? '', FILTER_VALIDATE_INT);
            $id = $id && $id > 0 ? $id : null;

            $fullName = trim((string)($_POST['FullName'] ?? ''));
            $username = trim((string)($_POST['Username'] ?? ''));
            $role = (string)($_POST['Role'] ?? '');
            $password = (string)($_POST['Password'] ?? '');
            $confirmPassword = (string)($_POST['ConfirmPassword'] ?? '');

            $form = [
                'UserID' => $id ?? '',
                'FullName' => $fullName,
                'Username' => $username,
                'Role' => $role,
                'IsActive' => 1
            ];
            $isEditing = $id !== null;

            if ($fullName === '' || mb_strlen($fullName) > 100) {
                $errors[] = 'Enter a name of up to 100 characters.';
            }

            if (
                $username === '' ||
                mb_strlen($username) > 50 ||
                !preg_match('/^[A-Za-z0-9_.-]+$/', $username)
            ) {
                $errors[] = 'Username must be 1–50 characters and contain only letters, numbers, dots, underscores, or hyphens.';
            }

            if (!in_array($role, $roles, true)) {
                $errors[] = 'Select a valid role.';
            }

            if ($id === null && $password === '') {
                $errors[] = 'A password is required for a new user.';
            }

            if ($password !== '') {
                if (strlen($password) < 10) {
                    $errors[] = 'Passwords must contain at least 10 characters.';
                }

                if ($password !== $confirmPassword) {
                    $errors[] = 'The passwords do not match.';
                }
            }

            if ($id !== null) {
                $check = $pdo->prepare(
                    'SELECT UserID, Role FROM users WHERE UserID = ?'
                );
                $check->execute([$id]);
                $existingUser = $check->fetch(PDO::FETCH_ASSOC);

                if (!$existingUser) {
                    $errors[] = 'The selected user no longer exists.';
                } elseif (
                    $id === (int)$_SESSION['user_id'] &&
                    $role !== 'Admin'
                ) {
                    $errors[] = 'You cannot remove your own Admin role.';
                }
            }

            // Check for duplicate usernames.
            if (!$errors) {
                $duplicateSql = 'SELECT UserID FROM users WHERE Username = ?';
                $duplicateParams = [$username];

                if ($id !== null) {
                    $duplicateSql .= ' AND UserID <> ?';
                    $duplicateParams[] = $id;
                }

                $duplicate = $pdo->prepare($duplicateSql);
                $duplicate->execute($duplicateParams);

                if ($duplicate->fetch()) {
                    $errors[] = 'That username is already in use.';
                }
            }

            if (!$errors) {
                try {
                    if ($id === null) {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $pdo->prepare("
                            INSERT INTO users
                                (FullName, Username, PasswordHash, Role, IsActive)
                            VALUES (?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([
                            $fullName,
                            $username,
                            $passwordHash,
                            $role
                        ]);

                        $_SESSION['users_flash'] = 'User account created successfully.';
                    } else {
                        if ($password !== '') {
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                            $stmt = $pdo->prepare("
                                UPDATE users
                                SET FullName = ?, Username = ?, PasswordHash = ?, Role = ?
                                WHERE UserID = ?
                            ");
                            $stmt->execute([
                                $fullName,
                                $username,
                                $passwordHash,
                                $role,
                                $id
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE users
                                SET FullName = ?, Username = ?, Role = ?
                                WHERE UserID = ?
                            ");
                            $stmt->execute([
                                $fullName,
                                $username,
                                $role,
                                $id
                            ]);
                        }

                        // Keep the current session in sync if the admin
                        // edits their own name or username.
                        if ($id === (int)$_SESSION['user_id']) {
                            $_SESSION['user_name'] = $fullName;
                        }

                        $_SESSION['users_flash'] = 'User account updated successfully.';
                    }

                    userRedirect();
                } catch (PDOException $ex) {
                    error_log('User management save error: ' . $ex->getMessage());
                    $errors[] = 'Unable to save the user. Please check the database and try again.';
                }
            }
        } elseif ($action === 'toggle_status') {
            $id = filter_var($_POST['UserID'] ?? '', FILTER_VALIDATE_INT);

            if (!$id || $id < 1) {
                $errors[] = 'Invalid user selected.';
            } elseif ($id === (int)$_SESSION['user_id']) {
                $errors[] = 'You cannot deactivate your own account.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'SELECT UserID, IsActive FROM users WHERE UserID = ? FOR UPDATE'
                    );
                    $stmt->execute([$id]);
                    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$targetUser) {
                        $pdo->rollBack();
                        $errors[] = 'The selected user was not found.';
                    } else {
                        $newStatus = (int)$targetUser['IsActive'] === 1 ? 0 : 1;

                        // Prevent deactivating the last active administrator.
                        if (
                            $newStatus === 0 &&
                            $targetUser['UserID'] > 0
                        ) {
                            $roleStmt = $pdo->prepare(
                                'SELECT Role FROM users WHERE UserID = ?'
                            );
                            $roleStmt->execute([$id]);
                            $targetRole = $roleStmt->fetchColumn();

                            if ($targetRole === 'Admin') {
                                $countStmt = $pdo->query("
                                    SELECT COUNT(*)
                                    FROM users
                                    WHERE Role = 'Admin' AND IsActive = 1
                                ");

                                if ((int)$countStmt->fetchColumn() <= 1) {
                                    $pdo->rollBack();
                                    $errors[] = 'You cannot deactivate the last active Admin.';
                                }
                            }
                        }

                        if (!$errors) {
                            $update = $pdo->prepare(
                                'UPDATE users SET IsActive = ? WHERE UserID = ?'
                            );
                            $update->execute([$newStatus, $id]);
                            $pdo->commit();

                            $_SESSION['users_flash'] = $newStatus
                                ? 'User account activated.'
                                : 'User account deactivated.';

                            userRedirect();
                        }
                    }
                } catch (PDOException $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('User management status error: ' . $ex->getMessage());
                    $errors[] = 'Unable to update the account status.';
                }
            }
        }
    }
}

// Load selected user for editing
$editId = filter_var($_GET['edit'] ?? '', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editId && $editId > 0) {
    $stmt = $pdo->prepare("
        SELECT UserID, FullName, Username, Role, IsActive
        FROM users
        WHERE UserID = ?
    ");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editUser) {
        $form = $editUser;
        $isEditing = true;
    } else {
        $errors[] = 'The selected user was not found.';
    }
}

// User search and list
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');

$sql = "
    SELECT UserID, FullName, Username, Role, IsActive, CreatedAt
    FROM users
    WHERE 1 = 1
";
$params = [];

if ($search !== '') {
    $sql .= ' AND (FullName LIKE ? OR Username LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($statusFilter === 'active' || $statusFilter === 'inactive') {
    $sql .= ' AND IsActive = ?';
    $params[] = $statusFilter === 'active' ? 1 : 0;
}

$sql .= " ORDER BY
    CASE Role
        WHEN 'Admin' THEN 1
        WHEN 'Manager' THEN 2
        ELSE 3
    END,
    FullName ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE IsActive = 1')->fetchColumn();
$adminUsers = (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE Role = 'Admin' AND IsActive = 1"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | Hotel Supply Management System</title>
    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .users-page { max-width: 1500px; margin: 0 auto; }
        .page-heading {
            display:flex; align-items:center; justify-content:space-between;
            flex-wrap:wrap; gap:15px; margin-bottom:22px;
        }
        .page-heading h1 { margin:0 0 6px; font-size:27px; }
        .page-heading p { margin:0; color:#6b7280; }
        .user-stats {
            display:grid; grid-template-columns:repeat(3,minmax(0,1fr));
            gap:15px; margin-bottom:22px;
        }
        .user-stat, .user-card {
            background:#fff; border:1px solid #e7eaf0;
            border-radius:12px; padding:20px;
        }
        .user-stat span { display:block; color:#6b7280; font-size:12px; margin-bottom:8px; }
        .user-stat strong { font-size:24px; color:#18243b; }
        .user-layout {
            display:grid; grid-template-columns:minmax(290px,350px) minmax(0,1fr);
            gap:20px; align-items:start;
        }
        .user-card h2 { font-size:17px; margin:0 0 6px; }
        .user-muted { color:#788195; font-size:12px; margin-bottom:18px; }
        .field { margin-bottom:15px; }
        .field label { display:block; font-size:12px; font-weight:600; margin-bottom:7px; color:#4b5563; }
        .field input, .field select {
            width:100%; box-sizing:border-box; padding:11px;
            border:1px solid #d9dee8; border-radius:7px; font:inherit; font-size:13px;
        }
        .field small { display:block; color:#788195; margin-top:6px; font-size:11px; }
        .btn {
            display:inline-flex; align-items:center; justify-content:center;
            gap:6px; padding:10px 13px; border-radius:7px;
            border:1px solid #d9dee8; background:#fff; color:#263247;
            text-decoration:none; cursor:pointer; font:inherit; font-size:12px;
        }
        .btn-primary { background:#2457d6; border-color:#2457d6; color:#fff; }
        .btn-danger { color:#b42318; border-color:#f2c9c6; background:#fff; }
        .btn-full { width:100%; }
        .user-alert { padding:12px 14px; border-radius:8px; margin-bottom:16px; font-size:13px; }
        .user-alert.error { color:#a61b1b; background:#fff0ef; border:1px solid #f5cfcc; }
        .user-alert.success { color:#176b43; background:#eaf8ef; border:1px solid #c9ebd5; }
        .search-row {
            display:flex; flex-wrap:wrap; gap:9px; margin-bottom:16px;
        }
        .search-row input, .search-row select {
            padding:10px; border:1px solid #d9dee8; border-radius:7px;
            font:inherit; font-size:12px; min-width:0;
        }
        .search-row input { flex:1; min-width:150px; }
        .table-wrap { overflow-x:auto; }
        .user-table { width:100%; border-collapse:collapse; font-size:12px; }
        .user-table th {
            text-align:left; background:#f7f8fb; color:#596579;
            padding:12px 10px; font-size:11px; white-space:nowrap;
            border-bottom:1px solid #e7eaf0;
        }
        .user-table td {
            padding:12px 10px; border-bottom:1px solid #eef0f4;
            color:#303b4e; vertical-align:middle;
        }
        .user-table tr:hover { background:#fafbfe; }
        .user-name { font-weight:600; }
        .user-sub { color:#7b8495; font-size:11px; margin-top:4px; }
        .pill {
            display:inline-block; padding:4px 8px; border-radius:20px;
            font-size:10px; font-weight:700; white-space:nowrap;
        }
        .role-admin { background:#e9edff; color:#3346a8; }
        .role-manager { background:#e6f4ff; color:#135d8a; }
        .role-staff { background:#f0f1f4; color:#586174; }
        .status-active { background:#e7f7ee; color:#147d4a; }
        .status-inactive { background:#fff0ef; color:#b42318; }
        .actions { display:flex; flex-wrap:wrap; gap:6px; }
        .empty { text-align:center; padding:25px !important; color:#8790a0 !important; }
        .security-note {
            background:#f7f9fc; padding:12px; border-radius:8px;
            color:#677286; font-size:11px; line-height:1.6; margin-top:15px;
        }
        @media(max-width:1050px) {
            .user-layout { grid-template-columns:1fr; }
            .user-stats { grid-template-columns:repeat(3,minmax(0,1fr)); }
        }
        @media(max-width:600px) {
            .user-stats { grid-template-columns:1fr; }
            .user-stat { padding:15px; }
            .user-card { padding:14px; }
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
        <div class="users-page">
            <div class="page-heading">
                <div>
                    <h1>User Management</h1>
                    <p>Create accounts and manage system access.</p>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="user-alert success"><?= userEscape($successMessage) ?></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="user-alert error">
                    <strong>Please check the following:</strong>
                    <ul style="margin:8px 0 0;padding-left:20px">
                        <?php foreach ($errors as $error): ?>
                            <li><?= userEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="user-stats">
                <div class="user-stat">
                    <span>Total Accounts</span>
                    <strong><?= $totalUsers ?></strong>
                </div>
                <div class="user-stat">
                    <span>Active Accounts</span>
                    <strong><?= $activeUsers ?></strong>
                </div>
                <div class="user-stat">
                    <span>Active Administrators</span>
                    <strong><?= $adminUsers ?></strong>
                </div>
            </div>

            <div class="user-layout">
                <section class="user-card">
                    <h2><?= $isEditing ? 'Edit User Account' : 'Create New User' ?></h2>
                    <div class="user-muted">
                        <?= $isEditing
                            ? 'Update the account details below.'
                            : 'Create a login for a new team member.' ?>
                    </div>

                    <form method="POST" action="users.php<?= $isEditing ? '?edit=' . (int)$form['UserID'] : '' ?>">
                        <input type="hidden" name="csrf_token" value="<?= userEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="UserID" value="<?= (int)($form['UserID'] ?: 0) ?>">

                        <div class="field">
                            <label for="FullName">Full Name *</label>
                            <input
                                type="text"
                                id="FullName"
                                name="FullName"
                                maxlength="100"
                                required
                                value="<?= userEscape($form['FullName']) ?>"
                                placeholder="Enter full name"
                            >
                        </div>

                        <div class="field">
                            <label for="Username">Username *</label>
                            <input
                                type="text"
                                id="Username"
                                name="Username"
                                maxlength="50"
                                pattern="[A-Za-z0-9_.-]+"
                                autocomplete="off"
                                required
                                value="<?= userEscape($form['Username']) ?>"
                                placeholder="e.g. staff01"
                            >
                            <small>Letters, numbers, dots, underscores and hyphens only.</small>
                        </div>

                        <div class="field">
                            <label for="Role">Role *</label>
                            <select id="Role" name="Role" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= userEscape($role) ?>"
                                        <?= $form['Role'] === $role ? 'selected' : '' ?>>
                                        <?= userEscape($role) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="Password">
                                <?= $isEditing ? 'New Password (optional)' : 'Password *' ?>
                            </label>
                            <input
                                type="password"
                                id="Password"
                                name="Password"
                                minlength="10"
                                autocomplete="new-password"
                                <?= $isEditing ? '' : 'required' ?>
                                placeholder="<?= $isEditing ? 'Leave blank to keep current password' : 'At least 10 characters' ?>"
                            >
                            <small>
                                <?= $isEditing
                                    ? 'Only enter a password if you want to change it.'
                                    : 'Use at least 10 characters.' ?>
                            </small>
                        </div>

                        <div class="field">
                            <label for="ConfirmPassword">
                                <?= $isEditing ? 'Confirm New Password' : 'Confirm Password *' ?>
                            </label>
                            <input
                                type="password"
                                id="ConfirmPassword"
                                name="ConfirmPassword"
                                minlength="10"
                                autocomplete="new-password"
                                <?= $isEditing ? '' : 'required' ?>
                                placeholder="Re-enter password"
                            >
                        </div>

                        <button type="submit" class="btn btn-primary btn-full">
                            <?= $isEditing ? 'Save Changes' : 'Create User Account' ?>
                        </button>

                        <?php if ($isEditing): ?>
                            <a href="users.php" class="btn btn-full" style="margin-top:8px">
                                Cancel Editing
                            </a>
                        <?php endif; ?>
                    </form>

                    <div class="security-note">
                        Passwords are stored as secure hashes. Existing passwords cannot be viewed;
                        an administrator can set a new password instead.
                    </div>
                </section>

                <section class="user-card">
                    <h2>System Users</h2>
                    <div class="user-muted">Search accounts and manage their status.</div>

                    <form method="GET" class="search-row">
                        <input
                            type="search"
                            name="search"
                            value="<?= userEscape($search) ?>"
                            placeholder="Search name or username"
                        >

                        <select name="status">
                            <option value="">All statuses</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>
                        </select>

                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="users.php" class="btn">Reset</a>
                    </form>

                    <div class="table-wrap">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$users): ?>
                                    <tr>
                                        <td colspan="5" class="empty">No user accounts found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $roleClass = match ($user['Role']) {
                                            'Admin' => 'role-admin',
                                            'Manager' => 'role-manager',
                                            default => 'role-staff'
                                        };
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="user-name"><?= userEscape($user['FullName']) ?></div>
                                                <div class="user-sub">
                                                    @<?= userEscape($user['Username']) ?>
                                                    <?php if ((int)$user['UserID'] === (int)$_SESSION['user_id']): ?>
                                                        · You
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="pill <?= $roleClass ?>">
                                                    <?= userEscape($user['Role']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="pill <?= (int)$user['IsActive'] === 1 ? 'status-active' : 'status-inactive' ?>">
                                                    <?= (int)$user['IsActive'] === 1 ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td><?= userEscape(date('d M Y', strtotime($user['CreatedAt']))) ?></td>
                                            <td>
                                                <div class="actions">
                                                    <a
                                                        class="btn"
                                                        href="users.php?edit=<?= (int)$user['UserID'] ?>"
                                                    >Edit</a>

                                                    <?php if ((int)$user['UserID'] !== (int)$_SESSION['user_id']): ?>
                                                        <form method="POST" action="users.php"
                                                              onsubmit="return confirm('Are you sure you want to change this account status?');">
                                                            <input type="hidden" name="csrf_token" value="<?= userEscape($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="UserID" value="<?= (int)$user['UserID'] ?>">

                                                            <button
                                                                type="submit"
                                                                class="btn <?= (int)$user['IsActive'] === 1 ? 'btn-danger' : '' ?>"
                                                            >
                                                                <?= (int)$user['IsActive'] === 1 ? 'Deactivate' : 'Activate' ?>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
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
        </div>
    </main>
</div>

<script>
const passwordInput = document.getElementById('Password');
const confirmPasswordInput = document.getElementById('ConfirmPassword');

function validatePasswordConfirmation() {
    if (confirmPasswordInput.value !== '' &&
        passwordInput.value !== confirmPasswordInput.value) {
        confirmPasswordInput.setCustomValidity('Passwords do not match.');
    } else {
        confirmPasswordInput.setCustomValidity('');
    }
}

passwordInput.addEventListener('input', validatePasswordConfirmation);
confirmPasswordInput.addEventListener('input', validatePasswordConfirmation);
</script>
</body>
</html>
