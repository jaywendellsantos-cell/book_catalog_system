<?php
// AUTH: sign in, register, sign out, and first-admin setup.
//   auth.php                      student login          auth.php?type=admin            admin login
//   auth.php?mode=register        student registration   auth.php?mode=register&type=admin   admin registration
//   auth.php?mode=logout          sign out
//   auth.php?mode=setup           create the very first admin (works only while no admin exists)
include_once 'db_connect.php';

$mode = get_text('mode') ?: 'login';
if (!in_array($mode, array('login', 'register', 'logout', 'setup'), true)) $mode = 'login';
$is_admin = get_text('type') === 'admin';
$type_link = $is_admin ? '&type=admin' : '';      // keeps admin/student when moving between pages
$error = '';
$done = false;

// ---- Sign out ----
if ($mode === 'logout') {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: auth.php');
    exit;
}

// ---- First-admin setup is only allowed while there is no admin ----
if ($mode === 'setup') {
    if ((int) query($db, "SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn() > 0) {
        http_response_code(403);
        exit('Setup is finished: an administrator already exists. You can sign in at auth.php?type=admin');
    }
    $is_admin = true;
}

// ---- Form submitted ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if ($mode === 'login') {
        $login = trim((string) ($_POST['login'] ?? ''));
        $account = query($db, 'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', array($login, strtolower($login)))->fetch();
        $wanted_role = $is_admin ? 'ADMIN' : 'USER';

        if ($account && password_verify((string) ($_POST['password'] ?? ''), $account['password']) && $account['role'] === $wanted_role) {
            session_regenerate_id(true);
            $_SESSION['user'] = array(
                'id' => $account['id'],
                'username' => $account['username'],
                'role' => $account['role'],
                'name' => $account['full_name'],
                'email' => (string) $account['email'],
            );
            header('Location: index.php');
            exit;
        }
        $error = 'Username or password is incorrect for this account type.';
    } else {
        // register (student or admin) and setup (admin, no code needed)
        $result = register_account($db, $_POST, $is_admin ? 'ADMIN' : 'USER', $mode !== 'setup');
        if ($result !== true) {
            $error = $result;
        } elseif ($mode === 'setup') {
            $done = true;
        } else {
            $_SESSION['flash'] = 'Account created. You can now sign in.';
            header('Location: auth.php' . ($is_admin ? '?type=admin' : ''));
            exit;
        }
    }
}

// ---- Text for the card ----
if ($mode === 'login') {
    $eyebrow = 'Welcome back';
    $title = 'Sign in to your account';
    $sub = $is_admin ? 'Enter your details to manage the catalog.' : 'Enter your details to borrow books and check your fines.';
} else {
    $eyebrow = 'Get started';
    $title = $is_admin ? 'Create your admin account' : 'Create your account';
    $sub = $is_admin ? 'Set up your profile to begin managing your library' : 'Set up your profile to start borrowing books';
}

page_top('auth-page');
?>
<!-- [16] LOGIN AND REGISTER -->
<section class="auth-card">
    <div class="auth-brand">
        <div class="auth-brand-left">
            <div class="auth-logo"><?php echo icon('book', 24); ?></div>
            <span class="auth-brand-name"><?php echo e(APP_NAME); ?></span>
        </div>
        <div class="auth-tagline">Manage stories.<br>Organize knowledge.</div>
    </div>

    <p class="auth-eyebrow"><?php echo e($eyebrow); ?></p>
    <h1><?php echo e($title); ?></h1>
    <p class="auth-sub"><?php echo e($sub); ?></p>

    <?php if ($error) { echo "<div class='auth-error'>" . e($error) . "</div>"; } ?>
    <?php if ($done) { echo "<div class='auth-ok'>Admin account created. You can sign in now.</div>"; } ?>

<?php if ($done) { ?>
    <p class="auth-switch"><a href="auth.php?type=admin">Go to admin login</a></p>
<?php } elseif ($mode === 'login') { ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="field">
            <label for="login">Email or username</label>
            <div class="input-wrap"><?php echo icon('user', 18); ?><input type="text" id="login" name="login" placeholder="you@email.com" required autocomplete="username" value="<?php echo e($_POST['login'] ?? ''); ?>"></div>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap"><?php echo icon('lock', 18); ?><input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password"></div>
        </div>
        <button class="btn btn-main" type="submit">Sign in as <?php echo $is_admin ? 'Admin' : 'User'; ?> <?php echo icon('arrow', 18); ?></button>
    </form>

    <p class="auth-switch">Don't have an account? <a href="auth.php?mode=register<?php echo $type_link; ?>">Register here</a></p>
    <p class="auth-switch auth-switch-small"><?php echo $is_admin ? 'Not an admin?' : 'Are you an admin?'; ?> <a href="auth.php<?php echo $is_admin ? '' : '?type=admin'; ?>"><?php echo $is_admin ? 'Sign in as a user' : 'Sign in as admin'; ?></a></p>

    <div class="auth-divider">or</div>
    <a class="btn btn-outline" href="index.php">Continue as Guest <?php echo icon('arrow', 18); ?></a>
<?php } else { ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="field">
            <label for="full_name">Full name</label>
            <div class="input-wrap"><?php echo icon('user', 18); ?><input type="text" id="full_name" name="full_name" placeholder="Your full name" required maxlength="100" autocomplete="name" value="<?php echo e($_POST['full_name'] ?? ''); ?>"></div>
        </div>
        <div class="field">
            <label for="email">Email address</label>
            <div class="input-wrap"><?php echo icon('mail', 18); ?><input type="email" id="email" name="email" placeholder="you@email.com" required maxlength="150" autocomplete="email" value="<?php echo e($_POST['email'] ?? ''); ?>"></div>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap"><?php echo icon('lock', 18); ?><input type="password" id="password" name="password" placeholder="Enter your password" required minlength="6" autocomplete="new-password"></div>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm password</label>
            <div class="input-wrap"><?php echo icon('lock', 18); ?><input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your password" required minlength="6" autocomplete="new-password"></div>
        </div>
        <?php if ($is_admin && $mode === 'register') { ?>
        <div class="field">
            <label for="admin_code">Admin registration code</label>
            <div class="input-wrap"><?php echo icon('lock', 18); ?><input type="password" id="admin_code" name="admin_code" placeholder="Code from the library office" required></div>
        </div>
        <?php } ?>
        <button class="btn btn-main" type="submit"><?php echo $is_admin ? 'Create Admin Account' : 'Create Account'; ?> <?php echo icon('arrow', 18); ?></button>
    </form>

    <?php if ($mode === 'register') { ?>
    <p class="auth-switch">Already have an account? <a href="auth.php<?php echo $is_admin ? '?type=admin' : ''; ?>">Log in</a></p>
    <p class="auth-switch auth-switch-small"><?php echo $is_admin ? 'Not an admin?' : 'Are you an admin?'; ?> <a href="auth.php?mode=register<?php echo $is_admin ? '' : '&type=admin'; ?>"><?php echo $is_admin ? 'Register as a user' : 'Register as admin'; ?></a></p>
    <?php } ?>
<?php } ?>
</section>
<?php page_bottom(); ?>
