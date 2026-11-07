
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

// Redirect already authenticated users to the dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

// Generate a CSRF token.
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

// Process login.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['login_csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT UserID, FullName, Username, PasswordHash, Role, IsActive
                     FROM users
                     WHERE Username = ?
                     LIMIT 1'
                );

                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    $user &&
                    (int) $user['IsActive'] === 1 &&
                    password_verify($password, $user['PasswordHash'])
                ) {
                    // Regenerate the session ID after authentication.
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['UserID'];
                    $_SESSION['user_name'] = $user['FullName'];
                    $_SESSION['user_role'] = $user['Role'];

                    unset($_SESSION['login_csrf_token']);

                    header('Location: index.php');
                    exit;
                }

                $error = 'Invalid username or password, or your account is inactive.';
            } catch (PDOException $e) {
                error_log('Login database error: ' . $e->getMessage());
                $error = 'Unable to log in right now. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | Hotel Supply Management System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --navy: #17223d;
            --navy-light: #243451;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --text: #1e293b;
            --muted: #64748b;
            --border: #dbe3ef;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, #e0eaff 0, transparent 38%),
                linear-gradient(135deg, #f5f7fc, #eaf0f8);
        }

        .login-container {
            width: 100%;
            max-width: 430px;
            padding: 36px;
            background: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 20px;
            box-shadow: 0 20px 55px rgba(23, 34, 61, 0.12);
            animation: appear 0.45s ease-out;
        }

        @keyframes appear {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 13px;
            margin-bottom: 34px;
        }

        .brand-icon {
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-radius: 15px;
            background: var(--navy);
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
            box-shadow: 0 6px 15px rgba(23, 34, 61, 0.2);
        }

        .brand h1 {
            margin: 0 0 5px;
            color: var(--navy);
            font-size: 21px;
            font-weight: 750;
        }

        .brand p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .welcome {
            margin-bottom: 27px;
            text-align: center;
        }

        .welcome h2 {
            margin: 0 0 9px;
            color: var(--navy);
            font-size: 27px;
            letter-spacing: -0.5px;
        }

        .welcome p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 16px;
            pointer-events: none;
        }

        .form-input {
            display: block;
            width: 100%;
            height: 48px;
            padding: 12px 14px 12px 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s ease,
                        box-shadow 0.2s ease,
                        background 0.2s ease;
        }

        .form-input::placeholder {
            color: #a0aec0;
        }

        .form-input:hover {
            border-color: #b8c6da;
        }

        .form-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.10);
        }

        .password-input {
            padding-right: 70px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            padding: 7px 6px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--primary);
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .toggle-password:hover {
            background: #eff6ff;
        }

        .error-message {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-bottom: 20px;
            padding: 12px 14px;
            border: 1px solid #fecaca;
            border-radius: 9px;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 13px;
            line-height: 1.5;
        }

        .error-icon {
            font-weight: 800;
        }

        .login-button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            min-height: 49px;
            margin-top: 8px;
            padding: 13px 18px;
            border: 1px solid transparent;
            border-radius: 10px;
            background: var(--navy);
            color: #ffffff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 5px 12px rgba(23, 34, 61, 0.16);
            transition: background 0.2s ease,
                        box-shadow 0.2s ease,
                        transform 0.2s ease;
        }

        .login-button:hover {
            background: var(--navy-light);
            box-shadow: 0 8px 18px rgba(23, 34, 61, 0.22);
            transform: translateY(-1px);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .login-button:focus-visible,
        .toggle-password:focus-visible {
            outline: 3px solid #93c5fd;
            outline-offset: 3px;
        }

        .button-arrow {
            font-size: 18px;
            transition: transform 0.2s ease;
        }

        .login-button:hover .button-arrow {
            transform: translateX(4px);
        }

        .footer {
            margin-top: 27px;
            padding-top: 18px;
            border-top: 1px solid #edf1f7;
            text-align: center;
            color: #94a3b8;
            font-size: 11px;
        }

        @media (max-width: 480px) {
            body {
                padding: 16px;
            }

            .login-container {
                padding: 28px 22px;
                border-radius: 16px;
            }

            .brand {
                margin-bottom: 28px;
            }

            .welcome h2 {
                font-size: 24px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>

<div class="login-container">

    <div class="brand">
        <div class="brand-icon">HS</div>

        <div>
            <h1>Hotel Supply</h1>
            <p>Management System</p>
        </div>
    </div>

    <div class="welcome">
        <h2>Welcome Back</h2>
        <p>Sign in to manage your hotel supply operations.</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error-message" role="alert">
            <span class="error-icon">!</span>
            <span>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $_SESSION['login_csrf_token'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <div class="form-group">
            <label for="username">Username</label>

            <div class="input-wrapper">
                <span class="input-icon" aria-hidden="true">♙</span>

                <input
                    class="form-input"
                    type="text"
                    id="username"
                    name="username"
                    value="<?= htmlspecialchars(
                        $_POST['username'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    placeholder="Enter your username"
                    autocomplete="username"
                    maxlength="50"
                    required
                    autofocus
                >
            </div>
        </div>

        <div class="form-group">
            <label for="password">Password</label>

            <div class="input-wrapper">
                <span class="input-icon" aria-hidden="true">▣</span>

                <input
                    class="form-input password-input"
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >

                <button
                    type="button"
                    class="toggle-password"
                    id="togglePassword"
                    aria-controls="password"
                    aria-pressed="false"
                >
                    Show
                </button>
            </div>
        </div>

        <button type="submit" class="login-button">
            <span>Sign In</span>
            <span class="button-arrow" aria-hidden="true">→</span>
        </button>
    </form>

    <div class="footer">
        &copy; <?= date('Y') ?> Hotel Supply Management System
    </div>

</div>

<script>
const passwordInput = document.getElementById('password');
const togglePasswordButton = document.getElementById('togglePassword');

togglePasswordButton.addEventListener('click', function () {
    const isPasswordHidden = passwordInput.type === 'password';

    passwordInput.type = isPasswordHidden ? 'text' : 'password';
    togglePasswordButton.textContent = isPasswordHidden ? 'Hide' : 'Show';
    togglePasswordButton.setAttribute(
        'aria-pressed',
        isPasswordHidden ? 'true' : 'false'
    );
});
</script>

</body>
</html>