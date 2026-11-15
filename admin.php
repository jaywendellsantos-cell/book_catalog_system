<?php
// ADMIN: pages only an administrator can open.
//   admin.php                      books that are borrowed right now
//   admin.php?view=form            add a book
//   admin.php?view=form&id=5       edit book 5
// The Delete button on the book list sends POST to  admin.php?do=delete&id=...
include_once 'db_connect.php';
require_admin();

$view = get_text('view') ?: 'borrowers';

// ===========================================================================
// DELETE A BOOK
// ===========================================================================
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

// ===========================================================================
// ADD / EDIT A BOOK:  admin.php?view=form  (add)   admin.php?view=form&id=5  (edit)
// ===========================================================================
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
<!-- [04] PAGE HEADING -->
<div class="heading">
    <div>
        <p class="eyebrow">Catalog Administration</p>
        <h1><?php echo $editing ? 'Edit book' : 'Add New Book'; ?></h1>
        <p class="heading-sub"><?php echo $editing ? 'Update the details, stock, or cover.' : 'Fill in the details to add a book to the catalog.'; ?></p>
    </div>
</div>
<?php if ($error) { echo "<div class='error'>" . e($error) . "</div>"; } ?>

<!-- [07] FORMS -->
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

// ===========================================================================
// CURRENT BORROWERS (default admin page)
// ===========================================================================
$borrowers = query($db, 'SELECT users.username, users.full_name, books.title, books.author, borrowings.borrowed_at, borrowings.due_at FROM borrowings JOIN users ON users.id = borrowings.user_id JOIN books ON books.id = borrowings.book_id WHERE borrowings.returned_at IS NULL ORDER BY borrowings.due_at, books.title')->fetchAll();

page_top();
?>
<!-- [04] PAGE HEADING -->
<div class="heading">
    <div>
        <p class="eyebrow">Catalog Administration</p>
        <h1>Current borrowers</h1>
        <p class="heading-sub">These are the books that have not been returned yet.</p>
    </div>
    <a class="button button-outline" href="index.php">Back to books</a>
</div>

<!-- [08] TABLES and [11] STATUS BADGES -->
<div class="table-wrap">
<table class="borrowers-table">
    <thead><tr><th>Student</th><th>Book</th><th>Author</th><th>Borrowed</th><th>Return by</th><th>Loan status</th></tr></thead>
    <tbody>
    <?php if (!$borrowers) { echo '<tr class="row-empty"><td colspan="6">No books are currently borrowed.</td></tr>'; } ?>
    <?php foreach ($borrowers as $row) { $late = new DateTime($row['due_at']) < new DateTime(); ?>
    <tr>
        <td><?php echo e($row['full_name'] !== '' ? $row['full_name'] : $row['username']); ?></td>
        <td><?php echo e($row['title']); ?></td>
        <td><span class="chip"><?php echo e($row['author']); ?></span></td>
        <td><?php echo e(read_date($row['borrowed_at'])); ?></td>
        <td><?php echo e(read_date($row['due_at'])); ?></td>
        <td><span class="status <?php echo $late ? 'overdue' : 'available'; ?>"><?php echo $late ? 'Overdue' : 'On loan'; ?></span></td>
    </tr>
    <?php } ?>
    </tbody>
</table>
</div>
<?php page_bottom(); ?>
