# Book Catalog System

A PHP + MySQL library catalog. Guests can browse books, students can borrow and return them (with overdue fines), and admins manage the catalog.

## Features

- Browse, search, and filter the book list (title / author / genre / availability)
- Student accounts: borrow and return books, view loan history, pay overdue fines (demo payment) and print receipts
- Admin tools: add, edit, and delete books, upload cover images, view current borrowers
- Borrowing rules: max **3 books** at a time, **14-day** loan, no duplicate title per student
- Overdue fine charged per day late (`FINE_PER_DAY`, set in the PHP config)
- CSRF protection on all POST forms

## Tech stack

- PHP 8+ (PDO)
- MySQL / MariaDB
- Apache (XAMPP, Laragon, or WAMP) or PHP's built-in server

## Project structure

```
book_catalog_system/
├── index.php        # Book list, student account, receipts, borrow/return/pay actions
├── admin.php        # Admin-only: borrowers list, add/edit/delete books
├── auth.php         # Login / registration / logout
├── db_connect.php   # Database connection and shared helper functions
├── database.sql     # Database schema and seed data
├── style.css        # Styling
├── background.jpg
└── uploads/         # Uploaded book cover images
```

## How to run

## Register as Admin
Registration Code: BOOK-ADMIN-2026


### 1. Prerequisites

Install [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP) or any equivalent stack.

### 2. Get the code

```bash
git clone https://github.com/jaywendellsantos-cell/book_catalog_system.git
```

Place the folder inside your web root:

| Stack  | Web root                  |
| ------ | ------------------------- |
| XAMPP  | `C:\xampp\htdocs\`        |
| Laragon| `C:\laragon\www\`         |
| Linux  | `/var/www/html/`          |

### 3. Set up the database

1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin).
3. Import `database.sql` (Import tab), or from the terminal:
   ```bash
   mysql -u root -p < database.sql
   ```
   If `database.sql` does not create the database itself, create an empty database first and select it before importing.

### 4. Configure the connection

Open `db_connect.php` and make sure the host, database name, username, and password match your MySQL setup. XAMPP defaults are usually:

```php
host:     localhost
username: root
password: (empty)
```

### 5. Make `uploads/` writable

The `uploads/` folder stores cover images. On Linux/macOS:

```bash
chmod -R 775 uploads
```

### 6. Open the app

```
http://localhost/book_catalog_system/
```

Or, without Apache, from the project folder:

```bash
php -S localhost:8000
```

then visit `http://localhost:8000/`.

## Roles

| Role      | How                  | Can do                                              |
| --------- | -------------------- | --------------------------------------------------- |
| Guest     | not logged in        | Browse and search books                             |
| `USER`    | student account      | Borrow, return, view account, pay fines, receipts   |
| `ADMIN`   | admin account        | Manage books, view current borrowers                |

Default accounts (if any) are created by `database.sql`.

## Endpoints

This app is server-rendered PHP, so routes are query-string based. All paths are relative to the app root, e.g. `http://localhost/book_catalog_system/`.

### Public / student: `index.php`

| Method | Endpoint                                  | Access  | Description                                                                 |
| ------ | ----------------------------------------- | ------- | --------------------------------------------------------------------------- |
| GET    | `index.php`                               | Everyone| Book list                                                                   |
| GET    | `index.php?q=<text>`                      | Everyone| Search title, author, or genre                                              |
| GET    | `index.php?genre=<genre>`                 | Everyone| Filter by genre                                                             |
| GET    | `index.php?status=AVAILABLE\|BORROWED`    | Everyone| Filter by availability                                                      |
| GET    | `index.php?view=account`                  | USER    | Account info, borrowing history, fines                                      |
| GET    | `index.php?view=receipt&id=<payment_id>`  | USER    | Fine payment receipt (own receipts only)                                    |
| POST   | `index.php?do=borrow&id=<book_id>`        | USER    | Borrow a book (max 3, 14 days, no duplicate title, needs available copies)  |
| POST   | `index.php?do=return&id=<book_id>`        | USER    | Return a borrowed book                                                      |
| POST   | `index.php?do=pay&id=<borrowing_id>`      | USER    | Pay an overdue fine (demo payment), then redirects to the receipt           |

Filters can be combined: `index.php?q=harry&genre=Fantasy&status=AVAILABLE`

### Admin: `admin.php`

| Method | Endpoint                          | Access | Description                                                              |
| ------ | --------------------------------- | ------ | ------------------------------------------------------------------------ |
| GET    | `admin.php`                       | ADMIN  | Books currently borrowed, with On loan / Overdue status                  |
| GET    | `admin.php?view=form`             | ADMIN  | Add-book form                                                            |
| POST   | `admin.php?view=form`             | ADMIN  | Create a book (`title`, `author`, `genre`, `year_published`, `total_copies`, optional `cover`) |
| GET    | `admin.php?view=form&id=<id>`     | ADMIN  | Edit-book form                                                           |
| POST   | `admin.php?view=form&id=<id>`     | ADMIN  | Update a book (stock cannot go below copies currently borrowed)          |
| POST   | `admin.php?do=delete&id=<id>`     | ADMIN  | Delete a book (blocked if the book has any borrowing history)            |

### Authentication: `auth.php`

Handles login, registration, and logout. See `auth.php` for the exact parameters.

### Notes on requests

- Every POST requires a valid CSRF token (included automatically by the forms).
- Cover uploads accept JPG, PNG, or WEBP up to 2 MB.
- Admin routes redirect or deny non-admin users; student routes require a logged-in `USER`.

## Business rules

| Rule               | Value                                         |
| ------------------ | --------------------------------------------- |
| Loan length        | 14 days                                       |
| Max active loans   | 3 per student                                 |
| Same title twice   | Not allowed while the first copy is out       |
| Overdue fine       | `FINE_PER_DAY` × days late                    |
| Fine payment       | Demo only, no real charge                     |
| Deleting books     | Not allowed if the book was ever borrowed     |

## Troubleshooting

- **Blank page / DB error:** check credentials in `db_connect.php` and that MySQL is running.
- **Cover upload fails:** make sure `uploads/` exists and is writable, and the file is under 2 MB.
- **404 on localhost:** confirm the folder name matches the URL (`book_catalog_system`).
