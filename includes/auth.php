
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /hotel-supply-system/login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();

    if (($_SESSION['user_role'] ?? '') !== 'Admin') {
        http_response_code(403);
        exit('Access denied. Only administrators can access this page.');
    }
}


function isAdmin(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'Admin';
}
