<?php
// DATABASE CONNECTION + SHARED HELPERS + PAGE LAYOUT
// Every page starts with:  include_once 'db_connect.php';

// ---- Settings you may want to change ----
$db_host = 'localhost';
$db_name = 'book_catalog';
$db_user = 'root';
$db_pass = '';

define('APP_NAME', 'Book Catalog System');                                  // name in the top bar
define('FINE_PER_DAY', 10);                                                 // pesos per overdue day
define('ADMIN_REGISTRATION_CODE', getenv('BOOK_ADMIN_CODE') ?: 'BOOK-ADMIN-2026');

date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();

// ---- Connect to MySQL ----
try {
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("SET time_zone = '+08:00'");   // same clock as PHP, so due dates and fines agree
} catch (PDOException $error) {
    die('Could not connect to the database. Check db_connect.php and import database.sql.');
}

// Runs one prepared query. Example: query($db, 'SELECT * FROM books WHERE id = ?', array($id))->fetch();
function query($db, $sql, $params = array())
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ---------------------------------------------------------------------------
// TEXT AND DATES
// ---------------------------------------------------------------------------
function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

function read_date($value)
{
    $time = strtotime((string) $value);
    return $time ? date('M j, Y \a\t g:i A', $time) : '';
}

// Text from the address bar (?q=...), trimmed and shortened.
function get_text($key, $max = 100)
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
}

// ---------------------------------------------------------------------------
// WHO MAY OPEN A PAGE
// ---------------------------------------------------------------------------
function require_login() { if (!isset($_SESSION['user'])) { header('Location: auth.php'); exit; } }
function require_admin() { require_login(); if ($_SESSION['user']['role'] !== 'ADMIN') { http_response_code(403); exit('You do not have permission to view this page.'); } }
function require_user() { require_login(); if ($_SESSION['user']['role'] !== 'USER') { http_response_code(403); exit('You do not have permission to do that.'); } }

// ---------------------------------------------------------------------------
// CSRF: put csrf_field() inside every form that uses method post, and call check_csrf() when it is sent
// ---------------------------------------------------------------------------
function csrf_token() { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }

function check_csrf()
{
    $sent = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

// ---------------------------------------------------------------------------
// FINES  (late days stop counting on the day the book is returned)
// ---------------------------------------------------------------------------
function late_days($due_at, $returned_at = null)
{
    $due = (new DateTime($due_at))->setTime(0, 0);
    $end = $returned_at ? (new DateTime($returned_at))->setTime(0, 0) : new DateTime('today');
    return max(0, (int) $due->diff($end)->format('%r%a'));
}

// Fine still owed for one loan row (the row needs the 'paid' column).
function fine_owed($loan)
{
    return max(0, late_days($loan['due_at'], $loan['returned_at']) * FINE_PER_DAY - (float) $loan['paid']);
}

// ---------------------------------------------------------------------------
// SMALL DISPLAY HELPERS
// ---------------------------------------------------------------------------
function book_code($id) { return 'BK 100-' . str_pad((string) (int) $id, 3, '0', STR_PAD_LEFT); }

function initials($name)
{
    $name = trim((string) $name);
    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

// Cover picture, or a plain box when the book has no cover.
function cover_html($book)
{
    if (!empty($book['cover_image'])) return '<img class="cover" src="uploads/covers/' . e(basename($book['cover_image'])) . '" alt="Cover of ' . e($book['title']) . '">';
    return '<div class="cover cover-empty">' . icon('book', 26) . '</div>';
}

// Small inline icons. Example: echo icon('plus');
function icon($name, $size = 20)
{
    $paths = array(
        'book'   => '<path d="M2 4h6a4 4 0 0 1 4 4v12a3 3 0 0 0-3-3H2z"/><path d="M22 4h-6a4 4 0 0 0-4 4v12a3 3 0 0 1 3-3h7z"/>',
        'filter' => '<path d="M3 6h18M6 12h12M10 18h4"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'edit'   => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash'  => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'arrow'  => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    );
    return '<svg class="icon" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

// ---------------------------------------------------------------------------
// BOOK COVER UPLOAD
// ---------------------------------------------------------------------------
// Saves an uploaded cover in uploads/covers/ under a random name. Returns the file name, or false.
function save_cover($file)
{
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) return false;
    $info = @getimagesize($file['tmp_name']);
    $types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp');
    if (!$info || !isset($types[$info[2]])) return false;
    $dir = __DIR__ . '/uploads/covers';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    $name = bin2hex(random_bytes(10)) . '.' . $types[$info[2]];
    return move_uploaded_file($file['tmp_name'], $dir . '/' . $name) ? $name : false;
}

function delete_cover($name)
{
    if (!$name) return;
    $path = __DIR__ . '/uploads/covers/' . basename($name);
    if (is_file($path)) @unlink($path);
}

// ---------------------------------------------------------------------------
// CREATE AN ACCOUNT  (used by the register page and the first-admin setup)
// Returns true when the account was created, or an error message.
// ---------------------------------------------------------------------------
function register_account($db, $data, $role = 'USER', $check_code = true)
{
    if ($role === 'ADMIN' && $check_code && !hash_equals(ADMIN_REGISTRATION_CODE, (string) ($data['admin_code'] ?? ''))) return 'The admin registration code is incorrect.';
    $name = trim((string) ($data['full_name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $confirm = (string) ($data['confirm_password'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) return 'Enter your full name (2-100 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) return 'Enter a valid email address.';
    if (strlen($password) < 6) return 'Use a password of at least 6 characters.';
    if ($password !== $confirm) return 'The two passwords do not match.';
    if (query($db, 'SELECT id FROM users WHERE email = ?', array($email))->fetchColumn()) return 'An account with that email already exists.';

    // The username is made from the email, so people can also log in with it.
    $base = preg_replace('/[^a-zA-Z0-9_]/', '_', strstr($email, '@', true));
    $base = substr(str_pad($base, 3, '_'), 0, 24);
    $username = $base;
    while (query($db, 'SELECT id FROM users WHERE username = ?', array($username))->fetchColumn()) {
        $username = $base . '_' . random_int(100, 999);
    }

    try {
        query($db, 'INSERT INTO users (full_name, email, username, password, role) VALUES (?, ?, ?, ?, ?)', array($name, $email, $username, password_hash($password, PASSWORD_DEFAULT), $role));
    } catch (PDOException $error) {
        return 'Could not create the account. Please try again.';
    }
    return true;
}

// ---------------------------------------------------------------------------
// PAGE LAYOUT: page_top() starts every page, page_bottom() ends it
// $page_class = 'auth-page' hides the top bar (login and register pages)
// $show_search = true shows the search box in the top bar
// ---------------------------------------------------------------------------
function page_top($page_class = '', $show_search = false)
{
    $me = $_SESSION['user'] ?? null;
    $me_name = $me ? (trim((string) ($me['name'] ?? '')) ?: $me['username']) : '';
    $me_email = $me['email'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bellota+Text:ital,wght@0,400;0,700;1,400;1,700&family=Fira+Sans:wght@400;500;600;700&family=Noto+Serif:wght@400;600;700&display=swap">
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?php echo e($page_class); ?>">
<?php if ($page_class !== 'auth-page') { ?>
<!-- [03] TOP BAR -->
<header class="topbar">
    <a class="brand" href="index.php">
        <span class="brand-logo"><?php echo icon('book', 26); ?></span>
        <span class="brand-name"><?php echo e(APP_NAME); ?></span>
    </a>

    <div class="topbar-right">
        <?php if ($show_search) { ?>
        <form class="search-box topbar-search" method="get" action="index.php" role="search">
            <input type="search" name="q" placeholder="Search Catalog Records" value="<?php echo e(get_text('q')); ?>" aria-label="Search catalog records">
            <button type="submit" aria-label="Search"><?php echo icon('filter', 22); ?></button>
        </form>
        <?php } ?>

        <?php if ($me) { ?>
            <?php if ($me['role'] === 'USER') { ?><a class="topbar-user" href="index.php?view=account" title="My account"><?php } else { ?><div class="topbar-user"><?php } ?>
                <span class="avatar"><?php echo e(initials($me_name)); ?></span>
                <span class="user-text"><strong><?php echo e($me_name); ?></strong><?php if ($me_email) { ?><small><?php echo e($me_email); ?></small><?php } ?></span>
            <?php echo $me['role'] === 'USER' ? '</a>' : '</div>'; ?>
            <span class="topbar-divider"></span>
            <a class="topbar-logout" href="auth.php?mode=logout"><?php echo icon('logout', 22); ?> Logout</a>
        <?php } else { ?>
            <a class="topbar-link" href="auth.php">User login</a>
            <a class="topbar-button" href="auth.php?type=admin">Admin Login <?php echo icon('arrow', 18); ?></a>
        <?php } ?>
    </div>
</header>
<?php } ?>
<main class="wrap">
<?php
    // [15] NOTICES: green message after an action
    if (!empty($_SESSION['flash'])) {
        echo "<div class='notice'>" . e($_SESSION['flash']) . "</div>";
        unset($_SESSION['flash']);
    }
}

function page_bottom()
{
    ?>
</main>
</body>
</html>
<?php
}
