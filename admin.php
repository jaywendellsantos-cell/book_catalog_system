<?php

include_once 'db_connect.php';
require_admin();

$view = get_text('view') ?: 'borrowers';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && get_text('do') === 'delete') {
    check_csrf();
    $id = (int) get_text('id');
    $deleted = false;

    // A book that has ever been borrowed is kept, so loan and fine records are never erased.
    $history = (int) query($db, 'SELECT COUNT(*) FROM borrowings WHERE book_id = ?', array($id))->fetchColumn();
    if ($id > 0 && $history === 0) {
        $old = query($db, 'SELECT cover_image FROM books WHERE id = ?', array($id))->fetch();
        try {
            $deleted = query($db, 'DELETE FROM books WHERE id = ?', array($id))->rowCount() > 0;
        } catch (PDOException $error) {
            $deleted = false;
        }
        if ($deleted && $old) delete_cover($old['cover_image']);
    }

    $_SESSION['flash'] = $deleted ? 'Book deleted.' : 'This book could not be deleted. Books that have been borrowed are kept for loan and fine records.';
    header('Location: index.php');
    exit;
}


if ($view === 'form') {
    $id = (int) get_text('id');
    $editing = $id > 0;
    $book = array();

    if ($editing) {
        $book = query($db, 'SELECT * FROM books WHERE id = ?', array($id))->fetch();
        if (!$book) { $_SESSION['flash'] = 'Book not found.'; header('Location: index.php'); exit; }
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $title = trim((string) ($_POST['title'] ?? ''));
        $author = trim((string) ($_POST['author'] ?? ''));
        $genre = trim((string) ($_POST['genre'] ?? ''));
        $year = filter_var($_POST['year_published'] ?? '', FILTER_VALIDATE_INT);
        $copies = filter_var($_POST['total_copies'] ?? '', FILTER_VALIDATE_INT);

        // Copies that are out right now (only matters when editing)
        $active = $editing ? (int) query($db, 'SELECT COUNT(*) FROM borrowings WHERE book_id = ? AND returned_at IS NULL', array($id))->fetchColumn() : 0;

        if ($title === '' || $author === '' || $genre === '' || !$year || $year < 1 || $year > (int) date('Y') || !$copies || $copies < 1) {
            $error = 'Fill in every field, enter a valid publication year, and set stock to at least 1.';
        } elseif ($copies < $active) {
            $error = 'Stock cannot be lower than the number of copies currently borrowed.';
        } else {
            // Optional cover picture
            $cover = null;
            if (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
                $cover = save_cover($_FILES['cover']);
                if ($cover === false) $error = 'The cover must be a JPG, PNG or WEBP image under 2 MB.';
            }

            if ($error === '') {
                if ($editing) {
                    $available = $copies - $active;
                    query($db, 'UPDATE books SET title = ?, author = ?, genre = ?, year_published = ?, total_copies = ?, available_copies = ?, cover_image = COALESCE(?, cover_image), status = ? WHERE id = ?',
                        array($title, $author, $genre, $year, $copies, $available, $cover, $available === 0 ? 'BORROWED' : 'AVAILABLE', $id));
                    if ($cover && !empty($book['cover_image'])) delete_cover($book['cover_image']);   // remove the old picture
                    $_SESSION['flash'] = 'Book updated.';
                } else {
                    query($db, 'INSERT INTO books (title, author, genre, year_published, total_copies, available_copies, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        array($title, $author, $genre, $year, $copies, $copies, $cover));
                    $_SESSION['flash'] = 'Book added.';
                }
                header('Location: index.php');
                exit;
            }
        }
        $book = array_merge($book, $_POST);   // keep what was typed
    }

    page_top();
    ?>
<!-- PAGE HEADING -->
<div class="heading">
    <div>
        <p class="eyebrow">Catalog Administration</p>
        <h1><?php echo $editing ? 'Edit book' : 'Add New Book'; ?></h1>
        <p class="heading-sub"><?php echo $editing ? 'Update the details, stock, or cover.' : 'Fill in the details to add a book to the catalog.'; ?></p>
    </div>
</div>
<?php if ($error) { echo "<div class='error'>" . e($error) . "</div>"; } ?>

<form class="card form" method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <label>Title<input name="title" required maxlength="200" value="<?php echo e($book['title'] ?? ''); ?>"></label>
    <label>Author<input name="author" required maxlength="150" value="<?php echo e($book['author'] ?? ''); ?>"></label>
    <label>Genre<input name="genre" required maxlength="100" value="<?php echo e($book['genre'] ?? ''); ?>"></label>
    <label>Year published<input type="number" name="year_published" required min="1" max="<?php echo (int) date('Y'); ?>" value="<?php echo e($book['year_published'] ?? ''); ?>"></label>
    <label>Total copies in stock<input type="number" name="total_copies" required min="1" value="<?php echo e($book['total_copies'] ?? 1); ?>"></label>
    <div class="cover-field">
        <?php if (!empty($book['cover_image'])) { echo cover_html($book); } ?>
        <label>Cover image <small>(JPG, PNG or WEBP, up to 2 MB<?php echo $editing ? '; leave empty to keep the current cover' : ''; ?>)</small>
            <input type="file" name="cover" accept="image/jpeg,image/png,image/webp">
        </label>
    </div>
    <div class="form-actions"><button class="button" type="submit"><?php echo $editing ? 'Save changes' : 'Add book'; ?></button><a href="index.php">Cancel</a></div>
</form>
<?php
    page_bottom();
    exit;
}

//list of borrowers
$filter = get_text('filter');
if ($filter !== 'active' && $filter !== 'overdue') $filter = '';

$loans = query($db, 'SELECT users.username, users.full_name, users.email, books.title, books.author, borrowings.borrowed_at, borrowings.due_at FROM borrowings JOIN users ON users.id = borrowings.user_id JOIN books ON books.id = borrowings.book_id WHERE borrowings.returned_at IS NULL ORDER BY borrowings.due_at, books.title')->fetchAll();

$count_overdue = 0;
foreach ($loans as $i => $row) {
    $loans[$i]['late'] = new DateTime($row['due_at']) < new DateTime();
    if ($loans[$i]['late']) $count_overdue++;
}
$count_all = count($loans);
$count_active = $count_all - $count_overdue;

//fines
$outstanding = 0;
foreach (query($db, 'SELECT due_at, returned_at, COALESCE((SELECT SUM(amount) FROM fine_payments WHERE borrowing_id = borrowings.id), 0) AS paid FROM borrowings')->fetchAll() as $row) {
    $outstanding += fine_owed($row);
}


$shown = array();
foreach ($loans as $row) {
    if ($filter === 'overdue' && !$row['late']) continue;
    if ($filter === 'active' && $row['late']) continue;
    $shown[] = $row;
}

//exporting
if (get_text('do') === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="borrowed-books-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array('Borrower', 'Email', 'Book', 'Borrowed', 'Due', 'Status'));
    foreach ($shown as $row) {
        $cells = array($row['full_name'] !== '' ? $row['full_name'] : $row['username'], $row['email'], $row['title'], read_date($row['borrowed_at']), read_date($row['due_at']), $row['late'] ? 'Overdue' : 'Active');
        foreach ($cells as $k => $cell) { $cells[$k] = preg_match('/^[=+\-@]/', (string) $cell) ? "'" . $cell : $cell; }   // stops spreadsheet formulas
        fputcsv($out, $cells);
    }
    fclose($out);
    exit;
}

$per_page = 10;
$pages = max(1, (int) ceil(count($shown) / $per_page));
$page = min($pages, max(1, (int) get_text('page')));
$shown = array_slice($shown, ($page - 1) * $per_page, $per_page);

function borrowers_url($filter, $page = 1, $extra = array())
{
    $q = array_merge(array('filter' => $filter, 'page' => $page > 1 ? $page : ''), $extra);
    return 'admin.php?' . http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
}

page_top();
?>
<!-- heading -->
<div class="heading">
    <div class="borrow-heading">
        <p class="eyebrow">Catalog Administration</p>
        <h1>Borrowed <span class="amp">&amp;</span> Overdue</h1>
        <p class="heading-sub">Monitor active loans, overdue items, and member accounts.</p>
    </div>
    <div class="heading-actions">
        <a class="button" href="<?php echo e(borrowers_url($filter, 1, array('do' => 'export'))); ?>"><?php echo icon('download', 18); ?> Export reports</a>
    </div>
</div>

<section class="stats-card">
    <div class="stat"><span class="stat-icon stat-ok"><?php echo icon('check', 22); ?></span><div><strong><?php echo $count_active; ?></strong><span>Active</span></div></div>
    <div class="stat"><span class="stat-icon stat-bad"><?php echo icon('alert', 22); ?></span><div><strong><?php echo $count_overdue; ?></strong><span>Overdue</span></div></div>
    <div class="stat"><span class="stat-icon stat-fine"><?php echo icon('wallet', 22); ?></span><div><strong>₱<?php echo number_format($outstanding, 2); ?></strong><span>Outstanding fines</span></div></div>
</section>

<section class="admin-panel">
    <div class="tabs">
        <a class="tab<?php echo $filter === '' ? ' active' : ''; ?>" href="<?php echo e(borrowers_url('')); ?>">All Borrowed</a>
        <a class="tab<?php echo $filter === 'active' ? ' active' : ''; ?>" href="<?php echo e(borrowers_url('active')); ?>">Active</a>
        <a class="tab<?php echo $filter === 'overdue' ? ' active' : ''; ?>" href="<?php echo e(borrowers_url('overdue')); ?>">Overdue</a>
    </div>
    <hr class="toolbar-line">
    <div class="table-scroll">
    <table class="books-table is-cards borrowers-table">
        <thead><tr><th>Borrower</th><th>Book Title</th><th>Borrow Date</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$shown) { echo '<tr class="row-empty"><td colspan="6">No books to show here.</td></tr>'; } ?>
        <?php foreach ($shown as $row) {
            $who = $row['full_name'] !== '' ? $row['full_name'] : $row['username'];
            $mail = 'mailto:' . rawurlencode((string) $row['email'])
                . '?subject=' . rawurlencode('Overdue book: ' . $row['title'])
                . '&body=' . rawurlencode("Hello $who,\n\nThis is a reminder that \"" . $row['title'] . '" was due on ' . read_date($row['due_at']) . ". Please return it to the library. A fine of P" . FINE_PER_DAY . " per day applies.\n\nThank you.");
        ?>
        <tr>
            <td class="borrower-cell"><span class="avatar avatar-small"><?php echo e(initials($who)); ?></span><span class="user-text"><strong><?php echo e($who); ?></strong><small><?php echo e($row['email']); ?></small></span></td>
            <td><span class="book-title"><?php echo e($row['title']); ?></span></td>
            <td><?php echo e(short_date($row['borrowed_at'])); ?></td>
            <td class="due<?php echo $row['late'] ? ' due-late' : ''; ?>"><?php echo e(short_date($row['due_at'])); ?></td>
            <td><span class="status <?php echo $row['late'] ? 'overdue' : 'available'; ?>"><?php echo $row['late'] ? 'Overdue' : 'Active'; ?></span></td>
            <td class="actions">
                <?php if ($row['late'] && $row['email']) { ?>
                <a class="action-btn action-remind" href="<?php echo e($mail); ?>"><?php echo icon('mail', 16); ?> Send reminder</a>
                <?php } else { echo '<span class="action-none"></span>'; } ?>
            </td>
        </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>

    <?php if ($pages > 1) { ?>
    <nav class="pager" aria-label="Pages">
        <?php if ($page > 1) { ?><a href="<?php echo e(borrowers_url($filter, $page - 1)); ?>">Previous</a><?php } ?>
        <?php for ($p = 1; $p <= $pages; $p++) { ?><a class="<?php echo $p === $page ? 'current' : ''; ?>" href="<?php echo e(borrowers_url($filter, $p)); ?>"><?php echo $p; ?></a><?php } ?>
        <?php if ($page < $pages) { ?><a href="<?php echo e(borrowers_url($filter, $page + 1)); ?>">Next</a><?php } ?>
    </nav>
    <?php } ?>
</section>
<?php page_bottom(); ?>
