<?php
// INDEX: the book list (everyone), plus the student pages.
//   index.php                       book list (guest, student, admin)
//   index.php?view=account          student account, loans and fines
//   index.php?view=receipt&id=5     fine payment receipt
// Forms on this page send POST to  index.php?do=borrow|return|pay&id=...
include_once 'db_connect.php';

$role = $_SESSION['user']['role'] ?? '';
$is_admin = $role === 'ADMIN';
$is_user = $role === 'USER';
$view = get_text('view') ?: 'books';

// ===========================================================================
// ACTIONS: borrow, return and pay a fine
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_user();
    check_csrf();
    $do = get_text('do');
    $id = (int) get_text('id');
    $user_id = (int) $_SESSION['user']['id'];

    // ---- Borrow a book (max 3 at a time, 14 days, not the same title twice) ----
    if ($do === 'borrow') {
        $ok = false;
        $db->beginTransaction();
        try {
            // FOR UPDATE locks the row so two students cannot take the last copy together
            $available = (int) query($db, 'SELECT available_copies FROM books WHERE id = ? FOR UPDATE', array($id))->fetchColumn();
            $mine = (int) query($db, 'SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND returned_at IS NULL', array($user_id))->fetchColumn();
            $same = (int) query($db, 'SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND book_id = ? AND returned_at IS NULL', array($user_id, $id))->fetchColumn();

            if ($available > 0 && $mine < 3 && $same === 0) {
                query($db, 'INSERT INTO borrowings (user_id, book_id, due_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))', array($user_id, $id));
                $borrowing_id = $db->lastInsertId();
                $left = $available - 1;
                query($db, 'UPDATE books SET available_copies = ?, status = ? WHERE id = ?', array($left, $left === 0 ? 'BORROWED' : 'AVAILABLE', $id));
                $ok = true;
            }
            $db->commit();
        } catch (Exception $error) {
            if ($db->inTransaction()) $db->rollBack();
            $ok = false;
        }

        if ($ok) {
            // Details for the "Borrowing confirmed" popup
            $_SESSION['borrow_notice'] = query($db, 'SELECT books.title, books.author, borrowings.borrowed_at, borrowings.due_at FROM borrowings JOIN books ON books.id = borrowings.book_id WHERE borrowings.id = ?', array($borrowing_id))->fetch();
            $_SESSION['flash'] = 'Book borrowed successfully.';
        } else {
            $_SESSION['flash'] = 'Could not borrow this book. It may be unavailable, you may already have it, or you may already have three books.';
        }
    }

    // ---- Return a book ----
    if ($do === 'return') {
        $ok = false;
        $db->beginTransaction();
        try {
            $stmt = query($db, 'UPDATE borrowings SET returned_at = NOW() WHERE book_id = ? AND user_id = ? AND returned_at IS NULL', array($id, $user_id));
            if ($stmt->rowCount() > 0) {
                query($db, "UPDATE books SET available_copies = available_copies + 1, status = 'AVAILABLE' WHERE id = ?", array($id));
                $ok = true;
            }
            $db->commit();
        } catch (Exception $error) {
            if ($db->inTransaction()) $db->rollBack();
            $ok = false;
        }
        $_SESSION['flash'] = $ok ? 'Book returned.' : 'Could not return that book.';
    }

    // ---- Pay a fine (demo payment, then show the receipt) ----
    if ($do === 'pay') {
        $loan = query($db, 'SELECT borrowings.*, COALESCE((SELECT SUM(amount) FROM fine_payments WHERE borrowing_id = borrowings.id), 0) AS paid FROM borrowings WHERE borrowings.id = ? AND borrowings.user_id = ?', array($id, $user_id))->fetch();
        $amount = $loan ? fine_owed($loan) : 0;
        if ($amount > 0) {
            $receipt_number = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            query($db, 'INSERT INTO fine_payments (borrowing_id, user_id, amount, payment_method, receipt_number) VALUES (?, ?, ?, ?, ?)', array($id, $user_id, $amount, 'DEMO', $receipt_number));
            header('Location: index.php?view=receipt&id=' . (int) $db->lastInsertId());
            exit;
        }
        $_SESSION['flash'] = 'There is no unpaid fine for that book.';
        header('Location: index.php?view=account');
        exit;
    }

    header('Location: index.php');
    exit;
}

// ===========================================================================
// STUDENT ACCOUNT PAGE:  index.php?view=account
// ===========================================================================
if ($view === 'account') {
    require_user();
    $user_id = (int) $_SESSION['user']['id'];
    $account = query($db, 'SELECT full_name, email, username, created_at FROM users WHERE id = ?', array($user_id))->fetch();

    // Every loan this student has had, with how much of the fine is already paid
    $loans = query($db, 'SELECT borrowings.*, books.title, books.author, COALESCE((SELECT SUM(amount) FROM fine_payments WHERE borrowing_id = borrowings.id), 0) AS paid FROM borrowings JOIN books ON books.id = borrowings.book_id WHERE borrowings.user_id = ? ORDER BY borrowings.borrowed_at DESC', array($user_id))->fetchAll();

    page_top();
    ?>
<div class="heading"><div><p class="eyebrow">Student account</p><h1>My account</h1></div></div>

<!-- [05] CARDS and [12] ACCOUNT PAGE -->
<section class="card account-info">
    <h2>Account information</h2>
    <?php if (!empty($account['full_name'])) { echo "<p><strong>Name:</strong> " . e($account['full_name']) . "</p>"; } ?>
    <?php if (!empty($account['email'])) { echo "<p><strong>Email:</strong> " . e($account['email']) . "</p>"; } ?>
    <p><strong>Username:</strong> <?php echo e($account['username']); ?></p>
    <p><strong>Account type:</strong> Student / user</p>
    <p><strong>Member since:</strong> <?php echo e(read_date($account['created_at'])); ?></p>
</section>

<h2 class="section-title">Borrowing history and fines</h2>
<?php if (!$loans) { echo "<div class='card'>You have not borrowed any books yet.</div>"; } ?>
<?php foreach ($loans as $loan) {
    $days_late = late_days($loan['due_at'], $loan['returned_at']);
    $fine = fine_owed($loan);
    if ($loan['returned_at']) {
        $status_text = 'Returned on ' . read_date($loan['returned_at']) . ($days_late ? ' (' . $days_late . ' day(s) late)' : '');
    } else {
        $status_text = $days_late ? 'Overdue by ' . $days_late . ' day(s)' : 'On loan';
    }
?>
<section class="card loan">
    <h3><?php echo e($loan['title']); ?> <small>by <?php echo e($loan['author']); ?></small></h3>
    <p>Borrowed: <?php echo e(read_date($loan['borrowed_at'])); ?></p>
    <p>Return by: <?php echo e(read_date($loan['due_at'])); ?></p>
    <p>Status: <?php echo e($status_text); ?></p>
    <p>Overdue fine: <strong>₱<?php echo number_format($fine, 2); ?></strong> <small>(₱<?php echo FINE_PER_DAY; ?> per day)</small></p>
    <?php if ($fine > 0) { ?>
    <form method="post" action="index.php?do=pay&id=<?php echo (int) $loan['id']; ?>" onsubmit="return confirm('Pay ₱<?php echo number_format($fine, 2); ?> using the demo payment flow?')">
        <?php echo csrf_field(); ?>
        <button class="button">Pay fine and get receipt</button>
    </form>
    <?php } ?>
</section>
<?php } ?>
<?php
    page_bottom();
    exit;
}

// ===========================================================================
// RECEIPT PAGE:  index.php?view=receipt&id=5
// ===========================================================================
if ($view === 'receipt') {
    require_user();
    $receipt = query($db, 'SELECT fine_payments.*, books.title FROM fine_payments JOIN borrowings ON borrowings.id = fine_payments.borrowing_id JOIN books ON books.id = borrowings.book_id WHERE fine_payments.id = ? AND fine_payments.user_id = ?', array((int) get_text('id'), (int) $_SESSION['user']['id']))->fetch();
    if (!$receipt) { http_response_code(404); exit('Receipt not found.'); }

    page_top();
    ?>
<!-- [14] RECEIPT PAGE -->
<section class="card receipt">
    <h1>Payment receipt</h1>
    <p><strong>Receipt number:</strong> <?php echo e($receipt['receipt_number']); ?></p>
    <p><strong>Paid on:</strong> <?php echo e(read_date($receipt['paid_at'])); ?></p>
    <p><strong>Account:</strong> <?php echo e($_SESSION['user']['username']); ?></p>
    <p><strong>Book:</strong> <?php echo e($receipt['title']); ?></p>
    <p><strong>Amount paid:</strong> ₱<?php echo number_format((float) $receipt['amount'], 2); ?></p>
    <p><strong>Payment method:</strong> Demo payment (no real charge)</p>
    <button onclick="window.print()">Print receipt</button> <a href="index.php?view=account">Back to account</a>
</section>
<?php
    page_bottom();
    exit;
}

// ===========================================================================
// BOOK LIST (default page)
// ===========================================================================

// ---- Search and filters ----
$q = get_text('q');
$genre = get_text('genre');
$status = strtoupper(get_text('status'));
if ($status !== 'AVAILABLE' && $status !== 'BORROWED') $status = '';

$where = '1=1';
$params = array();
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= ' AND (title LIKE ? OR author LIKE ? OR genre LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($genre !== '') { $where .= ' AND genre = ?'; $params[] = $genre; }
if ($status !== '') { $where .= ' AND status = ?'; $params[] = $status; }

$books = query($db, "SELECT * FROM books WHERE $where ORDER BY title", $params)->fetchAll();
$total = count($books);
$genres = query($db, 'SELECT DISTINCT genre FROM books ORDER BY genre')->fetchAll(PDO::FETCH_COLUMN);

// ---- Books this student has borrowed ----
$my_books = array();
if ($is_user) {
    $my_books = query($db, 'SELECT books.id, books.title FROM books JOIN borrowings ON books.id = borrowings.book_id WHERE borrowings.user_id = ? AND borrowings.returned_at IS NULL ORDER BY books.title', array($_SESSION['user']['id']))->fetchAll();
}
$borrowed_ids = array_map('intval', array_column($my_books, 'id'));

// ---- "Borrowing confirmed" popup ----
$borrow_notice = $_SESSION['borrow_notice'] ?? null;
unset($_SESSION['borrow_notice']);

$column_count = ($is_admin || $is_user) ? 7 : 6;

page_top('', !$is_admin);
?>
<?php if (!empty($borrow_notice)) { ?>
<!-- [13] BORROW POPUP -->
<div class="modal-backdrop">
    <section class="modal card">
        <h2>Borrowing confirmed</h2>
        <p><strong><?php echo e($borrow_notice['title']); ?></strong> by <?php echo e($borrow_notice['author']); ?></p>
        <p>Borrowed: <?php echo e(read_date($borrow_notice['borrowed_at'])); ?></p>
        <p>Return by: <?php echo e(read_date($borrow_notice['due_at'])); ?></p>
        <a class="button" href="index.php?view=account">View my account</a>
        <a class="close-link" href="index.php">Close</a>
    </section>
</div>
<?php } ?>

<!-- [04] PAGE HEADING -->
<div class="heading">
<?php if ($is_admin) { ?>
    <div>
        <p class="eyebrow">Catalog Administration</p>
        <h1>Database Management</h1>
        <p class="heading-sub">Maintain book records, availability, and inventory.</p>
    </div>
    <div class="heading-actions">
        <a class="button button-outline" href="admin.php">View borrowers</a>
        <a class="button button-large" href="admin.php?view=form"><?php echo icon('plus', 22); ?> Add New Book</a>
    </div>
<?php } else { ?>
    <div>
        <p class="eyebrow">Browse the library</p>
        <h1>Book Collection</h1>
        <p class="heading-sub">Explore our curated collection of books across every subject.</p>
    </div>
    <span class="count-pill"><?php echo $total; ?> book<?php echo $total === 1 ? '' : 's'; ?> found</span>
<?php } ?>
</div>

<?php if ($is_user) { ?>
<!-- [05] CARDS: my borrowed books (students only) -->
<section class="card my-books">
    <h2>My borrowed books (<?php echo count($my_books); ?>/3)</h2>
    <?php if (!$my_books) { ?>
        <p>You have not borrowed any books yet.</p>
    <?php } else { ?>
        <ul class="borrowed-list">
        <?php foreach ($my_books as $my_book) { ?>
            <li><?php echo e($my_book['title']); ?>
                <form method="post" action="index.php?do=return&id=<?php echo (int) $my_book['id']; ?>"><?php echo csrf_field(); ?><button class="link-button">Return</button></form>
            </li>
        <?php } ?>
        </ul>
    <?php } ?>
</section>
<?php } ?>

<?php if ($is_admin) { ?>
<!-- [10] ADMIN PANEL: white box with search and filters -->
<section class="admin-panel">
    <form class="toolbar" method="get" action="index.php">
        <div class="search-box toolbar-search">
            <input type="search" name="q" placeholder="Search Catalog Records" value="<?php echo e($q); ?>" aria-label="Search catalog records">
            <button type="submit" aria-label="Search"><?php echo icon('filter', 22); ?></button>
        </div>
        <select name="genre" class="select" aria-label="Filter by genre" onchange="this.form.submit()">
            <option value="">All genres</option>
            <?php foreach ($genres as $name) { ?><option value="<?php echo e($name); ?>" <?php echo $name === $genre ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php } ?>
        </select>
        <select name="status" class="select" aria-label="Filter by status" onchange="this.form.submit()">
            <option value="">All status</option>
            <option value="AVAILABLE" <?php echo $status === 'AVAILABLE' ? 'selected' : ''; ?>>Available</option>
            <option value="BORROWED" <?php echo $status === 'BORROWED' ? 'selected' : ''; ?>>Borrowed</option>
        </select>
    </form>
    <hr class="toolbar-line">
    <div class="table-scroll">
<?php } else { ?>
    <div class="table-wrap">
<?php } ?>

<!-- [08] TABLES and [09] BOOK ROWS -->
<table class="books-table<?php echo $is_admin ? ' is-cards' : ''; ?>">
    <thead>
    <tr>
        <th class="col-id">ID</th><th>Book Title</th><th>Author</th><th>Year Published</th><th>Genre</th>
        <th><?php echo $is_admin ? 'Quantity / Status' : 'Available'; ?></th>
        <?php if ($is_admin) { echo '<th>Actions</th>'; } elseif ($is_user) { echo '<th>Action</th>'; } ?>
    </tr>
    </thead>
    <tbody>
    <?php if (!$books) { ?>
        <tr class="row-empty"><td colspan="<?php echo $column_count; ?>">
            <?php echo ($q !== '' || $genre !== '' || $status !== '') ? 'No books match your search. <a href="index.php">Show every book</a>.' : 'No books have been added yet.'; ?>
        </td></tr>
    <?php } ?>

    <?php foreach ($books as $book) { $id = (int) $book['id']; ?>
    <tr>
        <td class="col-id"><span class="book-code"><?php echo e(book_code($id)); ?></span></td>
        <td><div class="title-cell"><?php echo cover_html($book); ?><span class="book-title"><?php echo e($book['title']); ?></span></div></td>
        <td><span class="chip"><?php echo e($book['author']); ?></span></td>
        <td><span class="year"><?php echo e($book['year_published']); ?></span></td>
        <td><span class="chip"><?php echo e($book['genre']); ?></span></td>

        <?php if ($is_admin) { ?>
        <td>
            <div class="qty qty-<?php echo strtolower($book['status']); ?>">
                <span class="qty-label"><?php echo $book['status'] === 'BORROWED' ? 'Borrowed' : 'Available'; ?></span>
                <span class="qty-count"><?php echo (int) $book['available_copies']; ?> of <?php echo (int) $book['total_copies']; ?> copies</span>
            </div>
        </td>
        <td class="actions">
            <a class="action-btn action-edit" href="admin.php?view=form&id=<?php echo $id; ?>"><?php echo icon('edit', 16); ?> Edit</a>
            <form method="post" action="admin.php?do=delete&id=<?php echo $id; ?>" onsubmit="return confirm('Delete this book?')"><?php echo csrf_field(); ?><button class="action-btn action-delete"><?php echo icon('trash', 16); ?> Delete</button></form>
        </td>
        <?php } else { ?>
        <td><span class="chip chip-count"><?php echo (int) $book['available_copies']; ?></span></td>
            <?php if ($is_user) { ?>
        <td class="actions">
            <?php if ((int) $book['available_copies'] > 0 && count($my_books) < 3 && !in_array($id, $borrowed_ids, true)) { ?>
            <form method="post" action="index.php?do=borrow&id=<?php echo $id; ?>"><?php echo csrf_field(); ?><button class="action-btn action-borrow">Borrow</button></form>
            <?php } else { echo '—'; } ?>
        </td>
            <?php } ?>
        <?php } ?>
    </tr>
    <?php } ?>
    </tbody>
</table>
    </div>
<?php if ($is_admin) { echo '</section>'; } ?>
<?php page_bottom(); ?>
