CREATE DATABASE IF NOT EXISTS book_catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE book_catalog;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL DEFAULT '',
    username VARCHAR(30) NOT NULL UNIQUE,
    email VARCHAR(150) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('USER', 'ADMIN') NOT NULL DEFAULT 'USER',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS books (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(150) NOT NULL,
    genre VARCHAR(100) NOT NULL,
    year_published SMALLINT UNSIGNED NOT NULL,
    cover_image VARCHAR(255) NULL,
    total_copies INT UNSIGNED NOT NULL DEFAULT 1,
    available_copies INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('AVAILABLE', 'BORROWED') NOT NULL DEFAULT 'AVAILABLE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sample book (id is generated automatically). Delete this line if you do not want it.
INSERT INTO books (title, author, genre, year_published, total_copies, available_copies)
VALUES ('Story of Sandra Mae Dimagiba', 'Jay Santos', 'Horror', 2026, 30, 30);

CREATE TABLE IF NOT EXISTS borrowings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    borrowed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    due_at DATETIME NOT NULL,
    returned_at DATETIME NULL,
    CONSTRAINT fk_borrowings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    -- RESTRICT: a book with loan history cannot be deleted, so loans and fine payments are never erased by accident.
    CONSTRAINT fk_borrowings_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS fine_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrowing_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    receipt_number VARCHAR(40) NOT NULL UNIQUE,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_borrowing FOREIGN KEY (borrowing_id) REFERENCES borrowings(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
