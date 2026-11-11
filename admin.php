<?php
session_start();

/* =========================================
   MIXECONIK STORE - ADMIN CONTROL PANEL
========================================= */

$base = __DIR__;
$dataDir = $base . '/data';
$uploadDir = $base . '/uploads';

foreach ([$dataDir, $uploadDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$productsFile = $dataDir . '/products.json';
$ordersFile = $dataDir . '/orders.json';

foreach ([$productsFile, $ordersFile] as $file) {
    if (!file_exists($file)) {
        file_put_contents($file, '[]', LOCK_EX);
    }
}

/* =========================================
   HELPER FUNCTIONS
========================================= */

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function readJSON($file) {
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveJSON($file, $data) {
    $fp = fopen($file, 'c+');

    if (!$fp) return false;

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    ftruncate($fp, 0);
    rewind($fp);

    $ok = fwrite($fp, $json);

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $ok !== false;
}

function removePhoto($photo, $uploadDir) {
    if (
        !is_string($photo) ||
        strpos($photo, 'uploads/') !== 0
    ) {
        return;
    }

    $path = __DIR__ . '/' . $photo;
    $real = realpath($path);
    $uploadReal = realpath($uploadDir);

    if (
        $real &&
        $uploadReal &&
        strpos($real, $uploadReal . DIRECTORY_SEPARATOR) === 0 &&
        is_file($real)
    ) {
        unlink($real);
    }
}

/* =========================================
   CSRF TOKEN
========================================= */

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

/* =========================================
   ADMIN LOGIN DETAILS
========================================= */

$adminUsername = 'admin';
$adminPassword = '123';

$error = '';
$message = '';

$statuses = [
    'Shipping',
    'Out for Delivery',
    'Delivered',
    'Cancelled'
];

/* =========================================
   ADMIN LOGIN
========================================= */

if (isset($_POST['login'])) {

    $csrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {
        $error = 'Invalid request. Please refresh the page.';

    } elseif (
        hash_equals($adminUsername, (string)($_POST['username'] ?? '')) &&
        hash_equals($adminPassword, (string)($_POST['password'] ?? ''))
    ) {

        session_regenerate_id(true);

        $_SESSION['store_admin_logged_in'] = true;
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));

        header('Location: admin.php');
        exit;

    } else {
        $error = 'Incorrect username or password.';
    }
}

/* =========================================
   ADMIN LOGOUT
========================================= */

if (isset($_GET['logout'])) {

    unset($_SESSION['store_admin_logged_in']);

    session_regenerate_id(true);

    header('Location: admin.php');
    exit;
}

$loggedIn = !empty($_SESSION['store_admin_logged_in']);

/* =========================================
   ADMIN ACTIONS
========================================= */

if (
    $loggedIn &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['login'])
) {

    $csrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['admin_csrf'], $csrf)) {

        $error = 'Invalid request. Please refresh the page.';

    } else {

        $action = $_POST['action'] ?? '';

        /* =================================
           UPDATE ORDER STATUS
        ================================= */

        if ($action === 'update_status') {

            $orders = readJSON($ordersFile);

            $orderId = (string)($_POST['order_id'] ?? '');
            $status = (string)($_POST['delivery_status'] ?? '');

            $found = false;

            if (!in_array($status, $statuses, true)) {

                $error = 'Invalid delivery status.';

            } else {

                foreach ($orders as &$order) {

                    if ((string)($order['id'] ?? '') === $orderId) {

                        $found = true;

                        $order['delivery_status'] = $status;
                        $order['status_updated_at'] = date('Y-m-d H:i:s');

                        break;
                    }
                }

                unset($order);

                if (!$found) {

                    $error = 'Order not found.';

                } elseif (saveJSON($ordersFile, $orders)) {

                    $message = 'Order delivery status updated successfully.';

                } else {

                    $error = 'Could not save the order status.';
                }
            }
        }

        /* =================================
           DELETE ORDER
        ================================= */

        elseif ($action === 'delete_order') {

            $orders = readJSON($ordersFile);
            $orderId = (string)($_POST['order_id'] ?? '');

            $found = false;

            foreach ($orders as $i => $order) {

                if ((string)($order['id'] ?? '') === $orderId) {

                    array_splice($orders, $i, 1);
                    $found = true;

                    break;
                }
            }

            if (!$found) {

                $error = 'Order not found.';

            } elseif (saveJSON($ordersFile, $orders)) {

                $message = 'Order deleted successfully.';

            } else {

                $error = 'Could not delete the order.';
            }
        }

        /* =================================
           ADD / EDIT PRODUCT
        ================================= */

        elseif ($action === 'save_product') {

            $products = readJSON($productsFile);

            $id = trim((string)($_POST['id'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));

            $price = filter_var(
                $_POST['price'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            $description = trim(
                (string)($_POST['description'] ?? '')
            );

            if (
                $name === '' ||
                strlen($name) > 450 ||
                $price === false ||
                $price < 0 ||
                $price > 100000000
            ) {

                $error = 'Enter a valid product name and price.';

            } else {

                $index = null;

                if ($id !== '') {

                    foreach ($products as $i => $p) {

                        if ((string)($p['id'] ?? '') === $id) {
                            $index = $i;
                            break;
                        }
                    }

                    if ($index === null) {
                        $error = 'Product not found.';
                    }
                }

                $photos = $index !== null
                    ? ($products[$index]['photos'] ?? [])
                    : [];

                if (!is_array($photos)) {
                    $photos = [];
                }

                /* REMOVE OLD PHOTOS */

                $remove = $_POST['remove_photos'] ?? [];

                if (is_array($remove)) {

                    foreach ($remove as $photoToRemove) {

                        foreach ($photos as $key => $photo) {

                            if ($photo === $photoToRemove) {

                                removePhoto($photo, $uploadDir);
                                unset($photos[$key]);
                            }
                        }
                    }

                    $photos = array_values($photos);
                }

                /* NEW PHOTO UPLOAD */

                $files = $_FILES['photos'] ?? null;
                $newCount = 0;

                if (
                    $files &&
                    isset($files['name']) &&
                    is_array($files['name'])
                ) {

                    foreach ($files['name'] as $filename) {

                        if ($filename !== '') {
                            $newCount++;
                        }
                    }
                }

                if (
                    $error === '' &&
                    count($photos) + $newCount > 10
                ) {

                    $error = 'Maximum 10 photos per product.';
                }

                if ($error === '' && $files && $newCount > 0) {

                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];

                    $finfo = new finfo(FILEINFO_MIME_TYPE);

                    foreach ($files['name'] as $i => $originalName) {

                        if ($originalName === '') {
                            continue;
                        }

                        if (
                            ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
                            ($files['size'][$i] ?? 0) > 5 * 1024 * 1024
                        ) {

                            $error = 'Each photo must be under 5 MB.';
                            break;
                        }

                        $tmp = $files['tmp_name'][$i] ?? '';

                        if (!is_uploaded_file($tmp)) {

                            $error = 'Invalid photo upload.';
                            break;
                        }

                        $mime = $finfo->file($tmp);

                        if (!isset($allowed[$mime])) {

                            $error = 'Only JPG, PNG and WebP photos are allowed.';
                            break;
                        }

                        $filename = bin2hex(random_bytes(16))
                            . '.' . $allowed[$mime];

                        if (!move_uploaded_file(
                            $tmp,
                            $uploadDir . '/' . $filename
                        )) {

                            $error = 'Could not upload photo.';
                            break;
                        }

                        $photos[] = 'uploads/' . $filename;
                    }
                }

                /* SAVE PRODUCT */

                if ($error === '') {

                    $product = [

                        'id' => $index !== null
                            ? $products[$index]['id']
                            : bin2hex(random_bytes(12)),

                        'name' => $name,
                        'price' => (float)$price,
                        'description' => $description,
                        'photos' => array_values($photos),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    if ($index !== null) {

                        $products[$index] = $product;

                    } else {

                        $products[] = $product;
                    }

                    if (saveJSON($productsFile, $products)) {

                        header('Location: admin.php?section=products&saved=1');
                        exit;

                    } else {

                        $error = 'Could not save product data.';
                    }
                }
            }
        }

        /* =================================
           DELETE PRODUCT
        ================================= */

        elseif ($action === 'delete_product') {

            $products = readJSON($productsFile);
            $id = (string)($_POST['id'] ?? '');

            $found = false;

            foreach ($products as $i => $p) {

                if ((string)($p['id'] ?? '') === $id) {

                    foreach (($p['photos'] ?? []) as $photo) {
                        removePhoto($photo, $uploadDir);
                    }

                    array_splice($products, $i, 1);

                    $found = true;
                    break;
                }
            }

            if (!$found) {

                $error = 'Product not found.';

            } elseif (saveJSON($productsFile, $products)) {

                $message = 'Product deleted successfully.';

            } else {

                $error = 'Could not save product data.';
            }
        }
    }
}

/* =========================================
   SUCCESS MESSAGE
========================================= */

if (isset($_GET['saved'])) {
    $message = 'Product saved successfully.';
}

/* =========================================
   LOAD DATA
========================================= */

$products = readJSON($productsFile);
$orders = array_reverse(readJSON($ordersFile));

/* =========================================
   EDIT PRODUCT
========================================= */

$editing = null;

if ($loggedIn && isset($_GET['edit'])) {

    foreach ($products as $p) {

        if ((string)($p['id'] ?? '') === (string)$_GET['edit']) {

            $editing = $p;
            break;
        }
    }
}

$section = $_GET['section'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Mixeconik Store Admin</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #080808;
    color: #ffffff;
    font-family: Arial, sans-serif;
}

header {
    text-align: center;
    padding: 22px;
    border-bottom: 1px solid #333;
}

header h1 {
    color: #168bff;
    margin: 0;
    font-size: 27px;
}

header p {
    color: #aaa;
    letter-spacing: 2px;
}

.container {
    max-width: 1100px;
    margin: auto;
    padding: 18px;
}

.box {
    background: #151515;
    border: 1px solid #333;
    border-radius: 10px;
    padding: 17px;
    margin-bottom: 18px;
}

input,
textarea,
select {
    width: 100%;
    padding: 12px;
    margin: 8px 0 14px;
    background: #222;
    color: white;
    border: 1px solid #444;
    border-radius: 6px;
}

input[type=checkbox] {
    width: auto;
}

input[type=file] {
    padding: 10px;
}

label {
    color: #ddd;
}

.btn {
    display: inline-block;
    background: #168bff;
    color: #ffffff;
    border: 0;
    padding: 11px 15px;
    border-radius: 6px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
}

.btn:hover {
    background: #006ed6;
}

.secondary {
    background: #303030;
    color: white;
}

.secondary:hover {
    background: #444;
}

.danger {
    background: #c62828;
    color: white;
}

.danger:hover {
    background: #e53935;
}

.switch {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 18px;
    background: #191919;
    color: white;
    border: 1px solid #444;
    border-radius: 9px;
    font-size: 18px;
    font-weight: bold;
    text-align: left;
    cursor: pointer;
}

.arrow {
    color: #168bff;
    font-size: 22px;
}

.section-content {
    display: none;
    margin-top: 12px;
}

.section-content.open {
    display: block;
}

.notice {
    background: #202b38;
    border-left: 4px solid #168bff;
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 5px;
}

.order {
    background: #101010;
    border: 1px solid #393939;
    border-radius: 9px;
    padding: 15px;
    margin: 14px 0;
}

.order h3 {
    color: #168bff;
    overflow-wrap: anywhere;
}

.detail {
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.card {
    background: #101010;
    border: 1px solid #333;
    padding: 12px;
    border-radius: 8px;
    min-width: 0;
}

.card img {
    width: 100%;
    height: 140px;
    object-fit: cover;
    border-radius: 5px;
}

.gallery {
    display: flex;
    gap: 8px;
    overflow: auto;
}

.gallery img {
    width: 65px;
    height: 65px;
    object-fit: cover;
}

.price {
    color: #168bff;
    font-weight: bold;
}

.order-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

@media(min-width:750px) {

    .grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

</style>

</head>

<body>

<header>

    <h1>Mixeconik Store</h1>

    <p>ADMIN CONTROL PANEL</p>

</header>

<main class="container">

<?php if ($error): ?>

    <div class="notice">
        <?= h($error) ?>
    </div>

<?php endif; ?>

<?php if ($message): ?>

    <div class="notice">
        <?= h($message) ?>
    </div>

<?php endif; ?>

<?php if (!$loggedIn): ?>

<!-- =====================================
     ADMIN LOGIN
===================================== -->

<section class="box">

    <h2>Admin Login</h2>

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?= h($_SESSION['admin_csrf']) ?>"
        >

        <label>Username</label>

        <input
            name="username"
            required
            autocomplete="username"
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button class="btn" name="login" value="1">
            Login
        </button>

    </form>

</section>

<?php else: ?>

<!-- =====================================
     ADMIN MENU
===================================== -->

<div class="box">

    <a class="btn secondary" href="index.php" target="_blank">
        View Store
    </a>

    <a class="btn secondary" href="admin.php?logout=1">
        Logout
    </a>

</div>

<!-- =====================================
     ORDER DETAILS
===================================== -->

<button
    class="switch"
    type="button"
    onclick="toggleSection('ordersSection','ordersArrow')"
>

    <span>Order Details (<?= count($orders) ?>)</span>

    <span class="arrow" id="ordersArrow">+</span>

</button>

<section
    class="section-content <?= $section === 'orders' ? 'open' : '' ?>"
    id="ordersSection"
>

<?php if (!$orders): ?>

    <div class="box">
        No orders received yet.
    </div>

<?php endif; ?>

<?php foreach ($orders as $order): ?>

    <?php

    $c = $order['customer'] ?? [];

    $fullAddress = trim(
        ($c['house'] ?? '') . ', ' .
        ($c['landmark'] ?? '') . ', ' .
        ($c['city'] ?? '') . ', ' .
        ($c['state'] ?? '') . ' - ' .
        ($c['pincode'] ?? '')
    );

    ?>

    <article class="order">

        <h3>
            Order #<?= h($order['id'] ?? '') ?>
        </h3>

        <div class="detail">

            <strong>Customer Name:</strong>
            <?= h($c['name'] ?? '') ?><br>

            <strong>Phone Number:</strong>
            <?= h($c['phone'] ?? '') ?><br>

            <strong>Full Address:</strong><br>

            <?= h($fullAddress ?: ($c['address'] ?? '')) ?><br>

            <strong>Product:</strong>
            <?= h($order['product_name'] ?? '') ?><br>

            <strong>Unit Price:</strong>
            ₹<?= number_format((float)($order['price'] ?? 0), 2) ?><br>

            <strong>Quantity:</strong>
            <?= h($order['quantity'] ?? 1) ?><br>

            <strong>Total:</strong>
            ₹<?= number_format((float)($order['total'] ?? 0), 2) ?><br>

            <strong>Payment:</strong>
            Cash on Delivery<br>

            <strong>Order Date:</strong>
            <?= h($order['created_at'] ?? '') ?><br>

            <strong>Delivery Status:</strong>

            <span class="price">
                <?= h($order['delivery_status'] ?? 'Order Placed') ?>
            </span>

        </div>

        <!-- UPDATE DELIVERY STATUS -->

        <form method="post" style="margin-top:15px">

            <input
                type="hidden"
                name="csrf"
                value="<?= h($_SESSION['admin_csrf']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="update_status"
            >

            <input
                type="hidden"
                name="order_id"
                value="<?= h($order['id'] ?? '') ?>"
            >

            <label>Update Delivery Status</label>

            <select name="delivery_status" required>

                <?php foreach ($statuses as $status): ?>

                    <option
                        value="<?= h($status) ?>"
                        <?= (($order['delivery_status'] ?? '') === $status) ? 'selected' : '' ?>
                    >
                        <?= h($status) ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button class="btn" type="submit">
                Update Order
            </button>

        </form>

        <!-- DELETE ORDER -->

        <form
            method="post"
            style="margin-top:12px"
            onsubmit="return confirm('Are you sure you want to permanently delete this order?')"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= h($_SESSION['admin_csrf']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="delete_order"
            >

            <input
                type="hidden"
                name="order_id"
                value="<?= h($order['id'] ?? '') ?>"
            >

            <button class="btn danger" type="submit">
                Delete Order
            </button>

        </form>

    </article>

<?php endforeach; ?>

</section>

<!-- =====================================
     PRODUCT MANAGEMENT
===================================== -->

<button
    class="switch"
    type="button"
    onclick="toggleSection('productsSection','productsArrow')"
    style="margin-top:15px"
>

    <span>Add Product / Manage Products</span>

    <span class="arrow" id="productsArrow">+</span>

</button>

<section
    class="section-content <?= ($section === 'products' || $editing) ? 'open' : '' ?>"
    id="productsSection"
>

<!-- ADD / EDIT PRODUCT FORM -->

<div class="box">

    <h2>
        <?= $editing ? 'Edit Product' : 'Add New Product' ?>
    </h2>

    <form method="post" enctype="multipart/form-data">

        <input
            type="hidden"
            name="csrf"
            value="<?= h($_SESSION['admin_csrf']) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="save_product"
        >

        <input
            type="hidden"
            name="id"
            value="<?= h($editing['id'] ?? '') ?>"
        >

        <label>Product Name</label>

        <input
            name="name"
            maxlength="150"
            required
            value="<?= h($editing['name'] ?? '') ?>"
        >

        <label>Product Price (₹)</label>

        <input
            type="number"
            name="price"
            min="0"
            max="100000000"
            step="0.01"
            required
            value="<?= h($editing['price'] ?? '') ?>"
        >

        <label>Product Description</label>

        <textarea
            name="description"
            rows="3"
        ><?= h($editing['description'] ?? '') ?></textarea>

        <!-- EXISTING PHOTOS -->

        <?php if ($editing && !empty($editing['photos'])): ?>

            <h3>Current Photos</h3>

            <div class="gallery">

                <?php foreach ($editing['photos'] as $photo): ?>

                    <label style="min-width:90px">

                        <img
                            src="<?= h($photo) ?>"
                            alt="Product photo"
                        >

                        <br>

                        <input
                            type="checkbox"
                            name="remove_photos[]"
                            value="<?= h($photo) ?>"
                        >

                        Remove

                    </label>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <!-- MULTIPLE PHOTO UPLOAD -->

        <label>Upload Product Photos (JPG, PNG, WebP)</label>

        <input
            type="file"
            name="photos[]"
            accept=".jpg,.jpeg,.png,.webp"
            multiple
        >

        <p style="color:#aaa">
            Maximum 10 photos per product.
            Each photo must be under 5 MB.
        </p>

        <button class="btn" type="submit">

            <?= $editing ? 'Update Product' : 'Add Product' ?>

        </button>

        <?php if ($editing): ?>

            <a
                class="btn secondary"
                href="admin.php?section=products"
            >
                Cancel Edit
            </a>

        <?php endif; ?>

    </form>

</div>

<!-- ALL PRODUCTS -->

<h2>All Products</h2>

<div class="grid">

    <?php foreach ($products as $p): ?>

        <article class="card">

            <?php if (!empty($p['photos'][0])): ?>

                <img
                    src="<?= h($p['photos'][0]) ?>"
                    alt="<?= h($p['name'] ?? '') ?>"
                >

            <?php else: ?>

                <div style="height:140px;display:grid;place-items:center;color:#888">
                    No Image
                </div>

            <?php endif; ?>

            <h3><?= h($p['name'] ?? '') ?></h3>

            <p class="price">
                ₹<?= number_format((float)($p['price'] ?? 0), 2) ?>
            </p>

            <a
                class="btn"
                href="admin.php?section=products&edit=<?= urlencode($p['id']) ?>"
            >
                Edit
            </a>

            <!-- DELETE PRODUCT -->

            <form
                method="post"
                style="margin-top:10px"
                onsubmit="return confirm('Are you sure you want to delete this product?')"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['admin_csrf']) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="delete_product"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= h($p['id']) ?>"
                >

                <button class="btn danger" type="submit">
                    Delete
                </button>

            </form>

        </article>

    <?php endforeach; ?>

</div>

<?php if (!$products): ?>

    <div class="box">
        No products added yet.
    </div>

<?php endif; ?>

</section>

<?php endif; ?>

</main>

<!-- =====================================
     JAVASCRIPT
===================================== -->

<script>

function toggleSection(sectionId, arrowId) {

    const section = document.getElementById(sectionId);
    const arrow = document.getElementById(arrowId);

    section.classList.toggle('open');

    arrow.textContent = section.classList.contains('open')
        ? '−'
        : '+';
}

/* INITIAL ARROW ICONS */

document.querySelectorAll('.section-content').forEach(section => {

    const arrowId = section.id === 'ordersSection'
        ? 'ordersArrow'
        : 'productsArrow';

    const arrow = document.getElementById(arrowId);

    if (arrow) {

        arrow.textContent = section.classList.contains('open')
            ? '−'
            : '+';
    }

});

</script>

</body>
</html>