<?php
// HOME: the landing page.   home.php
// Shows the search box, sign-in buttons, and live numbers from the catalog.
include_once 'db_connect.php';

$signed_in = isset($_SESSION['user']);
$is_admin = $signed_in && $_SESSION['user']['role'] === 'ADMIN';

$count_books = (int) query($db, 'SELECT COUNT(*) FROM books')->fetchColumn();
$count_genres = (int) query($db, 'SELECT COUNT(DISTINCT genre) FROM books')->fetchColumn();
$count_authors = (int) query($db, 'SELECT COUNT(DISTINCT author) FROM books')->fetchColumn();
$genres = query($db, 'SELECT genre, COUNT(*) AS total FROM books GROUP BY genre ORDER BY genre LIMIT 12')->fetchAll();
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
<body class="home-page">

<!-- TOP BAR -->
<header class="topbar">
    <a class="brand" href="home.php">
        <span class="brand-logo"><?php echo icon('book', 26); ?></span>
        <span class="brand-name"><?php echo e(APP_NAME); ?></span>
    </a>
    <nav class="topbar-right home-nav">
        <a class="topbar-link" href="index.php">Browse</a>
        <a class="topbar-link" href="#categories">Categories</a>
        <a class="topbar-link" href="#about">About</a>
        <?php if ($signed_in) { ?>
            <a class="topbar-button" href="index.php"><?php echo $is_admin ? 'Open dashboard' : 'Open my library'; ?> <?php echo icon('arrow', 18); ?></a>
        <?php } else { ?>
            <a class="topbar-button" href="auth.php">Sign in</a>
        <?php } ?>
    </nav>
</header>

<main class="home-main">

    <!-- HERO -->
    <section class="home-hero">
        <p class="home-eyebrow">Manage stories. Organize knowledge.</p>
        <h1>Every book, neatly in its place.</h1>
        <p class="home-sub">Search, add and keep track of your library in one cozy catalog.</p>

        <form class="home-search" method="get" action="index.php" role="search">
            <input type="search" name="q" placeholder="Search by title, author or genre" aria-label="Search the catalog">
            <button class="home-search-btn" type="submit">Search</button>
        </form>

        <?php if (!$signed_in) { ?>
        <div class="home-actions">
            <a class="home-btn home-btn-dark" href="auth.php">Sign in as User <?php echo icon('arrow', 16); ?></a>
            <a class="home-btn home-btn-outline" href="index.php">Continue as Guest</a>
        </div>
        <?php } ?>
    </section>

    <!-- NUMBERS (live from the database) -->
    <section class="home-stats">
        <div><strong><?php echo number_format($count_books); ?></strong><span>Books</span></div>
        <div><strong><?php echo number_format($count_genres); ?></strong><span>Categories</span></div>
        <div><strong><?php echo number_format($count_authors); ?></strong><span>Authors</span></div>
    </section>

    <!-- CATEGORIES -->
    <section class="home-section" id="categories">
        <h2>Browse by category</h2>
        <?php if (!$genres) { echo '<p class="home-note">No books have been added yet.</p>'; } ?>
        <div class="home-chips">
            <?php foreach ($genres as $g) { ?>
            <a class="chip" href="index.php?genre=<?php echo urlencode($g['genre']); ?>"><?php echo e($g['genre']); ?> <small>(<?php echo (int) $g['total']; ?>)</small></a>
            <?php } ?>
        </div>
    </section>

</main>

</body>
</html>
