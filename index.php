
<?php
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | Hotel Supply</title>
    <link rel="stylesheet" href="assets/css/style.css">
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

        <a class="nav-link active" href="index.php">▦ &nbsp; Dashboard</a>
        <a class="nav-link" href="pages/categories.php">▤ &nbsp; Categories</a>
        <a class="nav-link" href="pages/units.php">⚖ &nbsp; Units</a>
        <a class="nav-link" href="pages/items.php">📦 &nbsp; Items</a>
        <a class="nav-link" href="pages/hotels.php">🏨 &nbsp; Hotels</a>
        <a class="nav-link" href="pages/vendors.php">🛒 &nbsp; Market Vendors</a>

        <p class="menu-label">OPERATIONS</p>

        <a class="nav-link" href="pages/prices.php">💰 &nbsp; Price Lists</a>
        <a class="nav-link" href="pages/purchases.php">🧾 &nbsp; Market Purchases</a>
        <a class="nav-link" href="pages/orders.php">📋 &nbsp; Hotel Orders</a>
        <a class="nav-link" href="pages/deliveries.php">🚚 &nbsp; Deliveries</a>
        <a class="nav-link" href="pages/invoices.php">📄 &nbsp; Invoices</a>
        <a class="nav-link" href="pages/payments.php">💳 &nbsp; Payments</a>
        <a class="nav-link" href="pages/reports.php">📊 &nbsp; Reports</a>

        <div class="sidebar-footer">
            <span class="status-dot"></span>
            Local system running
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome to your Hotel Supply Management System.</p>
            </div>
            <div class="date">
                <?= date('d M Y') ?>
            </div>
        </header>

        <section class="welcome">
            <div>
                <span class="eyebrow">BUSINESS OVERVIEW</span>
                <h2>Manage your supply business</h2>
                <p>Track purchases, hotel orders, deliveries and payments in one place.</p>
            </div>
            <div class="welcome-icon">📦</div>
        </section>

        <section class="section-heading">
            <h2>Quick overview</h2>
            <p>Your business at a glance</p>
        </section>

        <section class="stats">
            <article class="stat-card">
                <div class="stat-icon purple">▦</div>
                <p>Items</p>
                <h3>Manage items</h3>
                <a href="pages/items.php">Open items →</a>
            </article>

            <article class="stat-card">
                <div class="stat-icon blue">🏨</div>
                <p>Hotels</p>
                <h3>Manage customers</h3>
                <a href="pages/hotels.php">Open hotels →</a>
            </article>

            <article class="stat-card">
                <div class="stat-icon orange">🛒</div>
                <p>Market purchases</p>
                <h3>Record purchases</h3>
                <a href="pages/purchases.php">Open purchases →</a>
            </article>

            <article class="stat-card">
                <div class="stat-icon green">🚚</div>
                <p>Deliveries</p>
                <h3>Track deliveries</h3>
                <a href="pages/deliveries.php">Open deliveries →</a>
            </article>
        </section>

        <section class="content-grid">
            <div class="panel">
                <h3>Daily workflow</h3>
                <p class="muted">Recommended steps for your daily operations.</p>

                <a class="workflow-row" href="pages/orders.php">
                    <span class="workflow-icon">📋</span>
                    <span><strong>Record hotel orders</strong><small>Enter orders received by email.</small></span>
                    <span class="arrow">→</span>
                </a>

                <a class="workflow-row" href="pages/purchases.php">
                    <span class="workflow-icon">🛒</span>
                    <span><strong>Record market purchases</strong><small>Enter quantities and purchase costs.</small></span>
                    <span class="arrow">→</span>
                </a>

                <a class="workflow-row" href="pages/deliveries.php">
                    <span class="workflow-icon">🚚</span>
                    <span><strong>Record deliveries</strong><small>Enter actual supplied quantities.</small></span>
                    <span class="arrow">→</span>
                </a>

                <a class="workflow-row" href="pages/payments.php">
                    <span class="workflow-icon">💳</span>
                    <span><strong>Record payments</strong><small>Track hotel payments and balances.</small></span>
                    <span class="arrow">→</span>
                </a>
            </div>

            <div class="panel info-panel">
                <div class="info-icon">💡</div>
                <h3>Remember</h3>
                <p>Delivery prices are selected from the common price list using the actual delivery date.</p>
                <hr>
                <h4>Important business rules</h4>
                <ul>
                    <li>Keep historical prices unchanged.</li>
                    <li>Save actual supplied quantities.</li>
                    <li>Calculate invoices from delivery details.</li>
                    <li>Track partial payments correctly.</li>
                </ul>
            </div>
        </section>

        <footer>
            Hotel Supply Management System
            <span>Local development version</span>
        </footer>
    </main>
</div>

</body>
</html>
