# HMS — Viva Defence Study Guide (PHP from Zero)

> **Read this guide top-to-bottom.** It assumes you know the *workflow* of the hostel app but have **never written PHP**. By the end you'll be able to point at any file and explain (1) what PHP is doing line by line, (2) why that line exists, and (3) what would break if you deleted it.
>
> The code lives at `C:\xampp\htdocs\team-legion-hostel-management-system`.
> Stack: **PHP 8 + MySQL + plain JS** running on **XAMPP** (Apache + MySQL). No framework, no router.

---

## TABLE OF CONTENTS

1. [PHP crash course (every concept the project uses)](#1-php-crash-course)
2. [How the app starts — Apache, XAMPP, request lifecycle](#2-how-the-app-starts)
3. [The root files — `index.php`, `db.php`, `config.php`](#3-the-root-files)
4. [The database (`database_schema.sql`) — every table explained](#4-the-database)
5. [Authentication system — session, JWT, CSRF, password](#5-authentication-system)
6. [Role panels — student / warden / owner / admin](#6-role-panels)
7. [The `api/` folder — JSON endpoints + payments](#7-the-api-folder)
8. [The chatbot](#8-the-chatbot)
9. [The landing page (`home/`)](#9-landing-page)
10. [Glossary of every PHP function used in this codebase](#10-glossary-of-php-functions)
11. [Likely viva questions with model answers](#11-likely-viva-questions)
12. [Cheat sheet — one-liners to memorise](#12-cheat-sheet)

---

## 1. PHP CRASH COURSE

PHP is a **server-side scripting language**. The browser asks Apache for a `.php` file → Apache hands it to the PHP interpreter → PHP runs it and sends back **plain HTML** to the browser. The browser never sees PHP code.

### 1.1 Tags

```php
<?php   // PHP starts here
echo "Hello";   // sends text to the browser
?>   // PHP ends here
```

You can mix PHP into HTML:

```php
<h1>Welcome <?php echo $user; ?></h1>
<h1>Welcome <?= $user ?></h1>   // <?= is shorthand for <?php echo
```

### 1.2 Variables and types

A variable always starts with `$`:

```php
$name = "Ali";         // string
$age = 21;             // integer
$gpa = 3.45;           // float
$is_admin = true;      // boolean
$room = null;          // null
```

PHP is **loosely typed** — you don't declare types, but you can cast: `(int)$x`, `(string)$x`, `(bool)$x`. Casting to `int` is used everywhere to make sure form input isn't malicious — `(int)$_POST['id']` turns `"5; DROP TABLE"` into just `0` or `5`.

### 1.3 Arrays

PHP has **one** array type that doubles as a list and a dictionary:

```php
$nums  = [1, 2, 3];                       // indexed list
$user  = ['name' => 'Ali', 'age' => 21];  // associative array (hash map)
echo $user['name'];                       // → Ali
$user['email'] = 'a@b.com';               // add a key
```

You'll see arrays *everywhere* — `$_POST`, `$_GET`, `$_SESSION`, `$_COOKIE`, DB rows, config arrays — all just associative arrays.

### 1.4 Control flow

```php
if ($age >= 18) { ... } elseif ($age >= 13) { ... } else { ... }

foreach ($rooms as $room) { echo $room['number']; }
foreach ($rooms as $i => $room) { ... }   // also gets index/key

for ($i = 0; $i < 10; $i++) { ... }
while ($cond) { ... }
```

### 1.5 Functions

```php
function add($a, $b) {
    return $a + $b;
}
$sum = add(3, 4);  // 7
```

You'll see **default parameters** (`function f($x = 0)`) and **type hints** (`function f(string $s): ?string`) — the `?` means "or null".

### 1.6 Superglobals (special arrays PHP fills for you)

| Superglobal | Where it comes from |
|---|---|
| `$_GET` | URL query string `?name=Ali` → `$_GET['name']` |
| `$_POST` | HTML form submitted with `method="POST"` |
| `$_FILES` | File upload via `<input type="file">` |
| `$_COOKIE` | Browser cookies |
| `$_SESSION` | Server-side per-user storage (after `session_start()`) |
| `$_SERVER` | Request info — `$_SERVER['REQUEST_METHOD']`, `$_SERVER['HTTPS']`, etc. |
| `$_ENV` / `getenv()` | Environment variables |

### 1.7 Including other files

```php
require 'db.php';        // include, fatal error if missing
require_once 'db.php';   // include only once even if called multiple times
include 'header.php';    // include, warning (not fatal) if missing
```

In this project **every page starts with `require_once '../db.php';`**. That single line loads the database connection, sessions, and every helper function (auth, CSRF, sidebar, etc.).

### 1.8 String operations you'll see

```php
trim($s)              // strip leading/trailing whitespace
strtolower($s)        // lowercase
strlen($s)            // length
strpos($haystack, $needle)         // find substring position
str_replace('a', 'b', $s)
htmlspecialchars($s)  // escape <, >, &, ", ' for safe HTML output
"Hello $name"         // double-quote → variable interpolation
'Hello $name'         // single-quote → literal $name
```

### 1.9 The two HTML-output safety functions

| Function | Why it matters |
|---|---|
| `htmlspecialchars($s)` | Stops XSS — converts `<script>` to `&lt;script&gt;` so the browser displays it instead of running it. Used so often that `db.php` defines a 1-letter shortcut: `function e($s) { return htmlspecialchars(...); }`. You'll see `<?= e($name) ?>` everywhere. |
| `urlencode($s)` | Escapes characters for URLs (spaces → `%20`, etc.) — used when building redirect URLs like `header('Location: login.php?msg=' . urlencode('Verified!'));` |

---

## 2. HOW THE APP STARTS

### 2.1 XAMPP

- **Apache** = the web server. Listens on port 80. When you hit `http://localhost/team-legion-hostel-management-system/`, Apache opens the matching folder under `C:\xampp\htdocs\`.
- **MySQL** = the database engine. Listens on port 3306. Database name in this project: **`hostel2`** (the schema file says `hostel3` but the actual config uses `hostel2` — either works as long as `config.php` matches).
- **phpMyAdmin** at `http://localhost/phpmyadmin` is just a web GUI to look at the database.

### 2.2 The request lifecycle (memorise this — it's a classic viva question)

1. Browser asks `GET /team-legion-hostel-management-system/student/dashboard.php`.
2. Apache locates `student/dashboard.php` on disk.
3. Apache hands the file to the **PHP interpreter** (mod_php).
4. PHP executes the script top to bottom:
   - `require_once '../db.php'` loads DB connection + helpers.
   - `require_role('student')` checks auth — if not a student, **redirect to login** and `exit`.
   - SQL queries pull dashboard data.
   - PHP echoes HTML — including `render_sidebar()`, `render_topbar()`, the dashboard cards, and `render_footer()`.
5. Apache sends the resulting HTML back to the browser.
6. Browser renders it and downloads the CSS/JS/images referenced inside.

There is **no router** — the URL path *is* the file path. `student/dashboard.php` exists on disk, so it works.

---

## 3. THE ROOT FILES

### 3.1 `index.php` (5 lines)

```php
<?php
header("Location: home/index.php");
exit;
?>
```

- `header("Location: …")` → tells the browser to redirect (HTTP 302).
- `exit` → stop the script immediately so nothing else runs after the redirect.
- **If you removed this file**: visiting `/` would show Apache's default directory listing instead of the landing page.

### 3.2 `config.php` (gitignored — secrets)

A PHP file that just **returns an associative array** of secrets:

```php
return [
    'DB_HOST'    => '127.0.0.1',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_NAME'    => 'hostel2',
    'JWT_SECRET' => 'a-very-long-random-string',
    'SMTP_USER'  => 'your-gmail@gmail.com',
    'SMTP_PASS'  => 'app-password',
    'ESEWA_*'    => '...',
];
```

`config.example.php` is a **template** committed to git — developers copy it to `config.php` and fill in real values. The real `config.php` is in `.gitignore` so secrets never get pushed to GitHub.

### 3.3 `db.php` — THE CORE FILE

Every other PHP page starts with `require_once '../db.php';`. This file does **eight** big jobs:

#### Job 1 — load config

```php
$localConfig = require 'config.php';  // returns the array above
function app_config($key, $default = null) { ... }
```

`app_config()` reads a config key. **Order of resolution**: environment variable first → `config.php` next → fallback default. This lets a production server override secrets without editing files.

#### Job 2 — open the database (twice)

The project uses **two** PHP database libraries side by side:

```php
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);   // procedural mysqli
$pdo  = new PDO('mysql:host=...;dbname=...;charset=utf8mb4', ...); // object-oriented PDO
```

| | mysqli (`$conn`) | PDO (`$pdo`) |
|---|---|---|
| Style | Procedural functions | Object methods |
| Used by | Older pages | Newer pages |
| Example | `mysqli_query($conn, "SELECT ...")` | `$pdo->prepare("SELECT ...")->execute([...])` |

**Why both?** Historical — the project started with mysqli, newer code uses PDO. Both are kept so old files still work.

If MySQL is down, `db.php` renders a friendly "Database connection failed — start XAMPP" page and exits.

#### Job 3 — auto-migrate the schema on every page load

You'll see dozens of blocks like:

```php
if (!mysqli_query($conn, "SELECT is_verified FROM users LIMIT 1")) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN otp_code VARCHAR(6) ...");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN is_verified TINYINT(1) ...");
}
```

The trick: try to SELECT the column. If it errors, the column doesn't exist yet → run `ALTER TABLE` to add it. This is **idempotent** — runs only once even though it's *called* on every page load.

**Why on every page load?** So that anyone who clones the repo and does `git pull` gets the latest schema without a manual migration step. Simple and beginner-friendly; not how you'd do it in a real production app.

#### Job 4 — helper functions (the ones you'll be asked about)

| Function | One-line summary |
|---|---|
| `e($s)` | `htmlspecialchars` shortcut — XSS-safe HTML output |
| `csrf_token()` | Generates a 64-hex-char token, stores in session |
| `csrf_verify($t)` | Compares submitted token to session — `die(403)` on mismatch |
| `jwt_encode($payload)` | Signs a JSON payload with HMAC-SHA256 → returns a JWT string |
| `jwt_decode($jwt)` | Verifies signature, returns payload or `false` |
| `set_auth($user)` | Login — issues JWT cookie + populates `$_SESSION` |
| `get_auth()` | Reads cookie, checks signature/expiry/token-version, returns payload or `false` |
| `refresh_auth_cookie($id)` | Re-issue JWT with fresh DB data (after profile update) |
| `revoke_user_tokens($id)` | Bumps `token_version` → invalidates every existing JWT for the user |
| `require_role('student')` | If not logged in as student, redirect to login |
| `require_role_any(['warden','owner'])` | Multi-role gate |
| `validate_strong_password($p)` | Returns null if OK, else error message |
| `render_sidebar('dashboard.php')` | Returns the role-aware left navigation HTML |
| `render_topbar('Dashboard')` | Returns the fixed top bar HTML (bell, theme, profile dropdown) |
| `render_footer()` | Footer HTML |
| `get_notifications($id, $role)` | Builds the bell-dropdown items |
| `esewa_sign($data)` | HMAC-SHA256 the string with `ESEWA_SECRET` → base64 |
| `generate_receipt_code($month)` | Unique code like `HMS-RCPT-202605-AB12CD` |
| `generate_monthly_fees($month)` | Auto-create one `monthly_fees` row per active student |
| `mark_overdue_fees()` | Flip `unpaid` rows to `overdue` past their due date |

---

## 4. THE DATABASE

The schema lives in `database_schema.sql` (361 lines). All tables use **InnoDB** + **utf8mb4** (full Unicode + emojis). There are **18 tables**.

### 4.1 Tables grouped by feature

#### A) People & rooms

| Table | What it stores | Key columns |
|---|---|---|
| `users` | Every person — student/warden/owner/admin | `id`, `email` (unique), `password` (bcrypt hash), `role` (ENUM), `name`, `photo`, `room_id`, `room_status`, `is_verified`, `otp_code`, `token_version`, `fee_status` |
| `rooms` | Each room | `id`, `room_number` (unique), `room_type` (Single/Double/Triple), `floor`, `status` (available/occupied/maintenance), `capacity`, `price`, `photo`, `deleted_at` (soft delete) |
| `bookings` | Pending/approved bookings (the newer, canonical source of "which room is X in") | `student_id`, `room_id`, `status` (pending_approval/approved/active/cancelled/rejected) |
| `room_transfers` | Student-requested room moves | `student_id`, `from_room_id`, `to_room_id`, `reason`, `status` |
| `room_audit_log` | Every room change event (assignments, transfers) | `event`, `from_room_id`, `to_room_id`, `actor_id` |
| `transfer_adjustments` | Price difference when a student moves rooms | `direction` (charge/credit), `amount`, `status` |

#### B) Daily operations

| Table | What it stores |
|---|---|
| `attendance` | One row per student per day, `status` ENUM(present, absent). Unique key `(student_id, date)` prevents duplicates |
| `complaints` | Student-filed complaints — `subject`, `message`, `status`, `reply` |
| `leaves` | Leave requests with **two-step approval**: `warden_status`, `owner_status`, `final_status` |
| `daily_routine` | Time-slot activity table (6 AM exercise, 7 AM-3 PM school, …) |
| `food_routine` | Meal menu per day per `breakfast/lunch/dinner` |
| `staff` | Cleaners, cooks, gardener, security — `name`, `role`, `allocation` |

#### C) Money

| Table | What it stores |
|---|---|
| `monthly_fees` | One row per student per billing month — `amount`, `due_date`, `status` (unpaid/paid/overdue). Unique key `(student_id, billing_month)` prevents duplicate bills |
| `payments` | Every payment attempt — `gateway` (esewa/manual), `status` (pending/success/failed/cancelled), `transaction_uuid` (unique), `receipt_code` |

#### D) Support & misc

| Table | What it stores |
|---|---|
| `help_tickets` | Tickets students/staff send to admin — `subject`, `description`, `status` (open/in_progress/resolved), `admin_note` |
| `enquiries` | Public-website form submissions (also where unmatched chatbot messages land) |
| `chatbot_intents` | Rule-based bot rules — `keywords` (comma list), `response`, `priority` |
| `chatbot_conversations` | Logged messages between user and bot — for rating + analytics |
| `app_settings` | Tiny key/value store for migration markers (e.g. `pricing_version=v2_2026`) |

### 4.2 Relationships (no formal FK constraints — they're implied)

```
users (id) ←─ rooms.student_id           (legacy "user lives in this room")
users (id) ←─ bookings.student_id        (newer source of truth)
rooms (id) ←─ bookings.room_id
users (id) ←─ attendance.student_id      (one per day, unique)
users (id) ←─ complaints.student_id
users (id) ←─ leaves.student_id
users (id) ←─ monthly_fees.student_id    (one per month, unique)
users (id) ←─ payments.student_id
users (id) ←─ help_tickets.user_id
monthly_fees (id) ←─ monthly_fees.paid_payment_id  → payments (id)
```

> The project uses **`InnoDB`** but doesn't declare hard `FOREIGN KEY` constraints. Joins are written manually in SQL. This is a beginner-project simplification — fine for the project, you'd add real FKs in production.

### 4.3 The seed accounts (memorise these)

| Role | Email | ID |
|---|---|---|
| Warden | `warden@hms.com` | 1 |
| Owner  | `owner@hms.com`  | 2 |
| Student | `ali@student.com`  | 3 |
| Student | `sara@student.com` | 4 |
| Student | `tom@student.com`  | 5 |
| Admin  | `admin@hms.com`  | 6 |

Password for **all** of them: **`Test1234`** (already hashed in the SQL with `password_hash()`).

---

## 5. AUTHENTICATION SYSTEM

This is the section your examiner is most likely to grill you on. Master it.

### 5.1 Login flow — step by step

User hits `auth/login.php` → fills email + password → clicks **Log in**.

```php
// auth/login.php
if ($_POST['action'] === 'login') {
    csrf_verify($_POST['csrf_token']);                        // 1
    $stmt = $pdo->prepare(                                    // 2
        'SELECT id, name, password, role, photo, is_verified, token_version
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($pass, $u['password'])) {     // 3
        throw new Exception('Invalid email or password.');
    }
    if (!(int)$u['is_verified']) {                            // 4
        throw new Exception('Please verify your email...');
    }
    set_auth($u);                                             // 5
    $res['redirect'] = '../' . $u['role'] . '/dashboard.php';
}
```

1. **CSRF check** — does the form's hidden `csrf_token` match `$_SESSION['csrf']`? If no → 403 Forbidden. This stops a malicious site from POSTing to your login from another tab.
2. **Prepared statement** — `?` is a placeholder. The value bound by `execute([$email])` is **escaped automatically** → no SQL injection possible.
3. **`password_verify($plain, $hash)`** — compares the plaintext password the user typed against the bcrypt hash in the DB. The hash starts with `$2y$10$…` (bcrypt, 10 rounds). Constant-time compare so timing attacks are useless.
4. **Email verification gate** — `is_verified` must be 1 (set by `auth/verify.php` after the user enters the right OTP).
5. **`set_auth($u)`** — see below.

### 5.2 What `set_auth()` does (db.php:634)

```php
function set_auth($user) {
    session_regenerate_id(true);            // new session ID — defeats session fixation
    $token = jwt_encode([
        'id'    => $user['id'],
        'name'  => $user['name'],
        'role'  => $user['role'],
        'photo' => $user['photo'] ?? 'default.png',
        'tv'    => $user['token_version'],  // for instant revocation
        'exp'   => time() + 86400*30        // 30 days
    ]);
    setcookie('auth_token', $token, [
        'httponly' => true,                 // JS can't read it → XSS-safe
        'samesite' => 'Lax',                // not sent on cross-site GETs
        'secure'   => /* true on HTTPS */,
        'path'     => '/'
    ]);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_photo'] = $user['photo'] ?? 'default.png';
}
```

So a logged-in user has **two** things going for them:
- A signed **JWT cookie** (`auth_token`) that survives across browser tabs and lasts 30 days.
- A **PHP session** (`$_SESSION`) that's faster to read on the same request.

### 5.3 What is a JWT?

**JSON Web Token** = three base64url-encoded chunks separated by dots:

```
eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9 . eyJpZCI6Mywicm9sZSI6InN0dWRlbnQiLC...} . <signature>
        header                              payload                              HMAC-SHA256
```

- **Header** = `{"typ":"JWT","alg":"HS256"}`
- **Payload** = the user data (id, role, name, …) — anyone can read it; that's fine.
- **Signature** = `HMAC-SHA256(header + "." + payload, JWT_SECRET)`. Only someone who knows `JWT_SECRET` can produce a valid signature.

In `jwt_decode()` we re-compute the signature from the header+payload and compare it with **`hash_equals()`** (constant-time string compare). If they match, the token is genuine; if not, return `false` and clear the cookie.

### 5.4 `token_version` — the kill switch

Every user has `users.token_version` (integer, starts at 0). The JWT embeds it as `tv`. When `get_auth()` decodes a cookie it also re-reads the user's *current* `token_version` from the DB. **If they differ**, the cookie is rejected even though the signature is valid.

Use cases:
- **Log out from all devices**: bump `token_version`.
- **Password change**: bump `token_version` so all old sessions die.
- **Logout** (`auth/logout.php`): bump `token_version`, clear the cookie, destroy the session.

### 5.5 CSRF — the form-protection mechanism

CSRF = Cross-Site Request Forgery. Attack scenario: you're logged in to HMS, and you visit an evil site. The evil site has a hidden form that auto-POSTs to `http://localhost/.../student/payments.php?action=delete_account`. Since your browser auto-attaches your `auth_token` cookie, the request would succeed — *unless* we require a secret the evil site couldn't know.

That secret is `csrf_token()`:

```php
// db.php
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));  // 64 hex chars
    }
    return $_SESSION['csrf'];
}
function csrf_verify($token) {
    if (!hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        die('Security error: Invalid CSRF token.');
    }
}
```

Every form has a hidden input:

```html
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
```

The server compares it on POST. The evil site can't read the user's `$_SESSION['csrf']`, so its forged request fails.

### 5.6 Password storage — bcrypt

```php
$hash = password_hash($pass, PASSWORD_DEFAULT);    // store this
password_verify($plain, $hash);                    // returns true/false
```

`PASSWORD_DEFAULT` is currently **bcrypt** (`$2y$10$...`). Bcrypt:
- Adds a random **salt** automatically so two users with the same password get different hashes.
- Is **slow on purpose** (configurable "cost" — here it's 10) so brute-forcing is expensive.
- Plain `md5` or `sha1` would be **wrong** because they're fast and unsalted.

### 5.7 Password rules (`validate_strong_password`, db.php:745)

A password must have:
- ≥ 6 characters
- ≥ 1 uppercase letter
- ≥ 1 lowercase letter
- ≥ 1 digit

Rules live in **one** function so signup, password-reset, and change-password all enforce the same minimum.

### 5.8 OTP email verification (`auth/verify.php`)

1. After registration, `auth/login.php` (register branch) generates a **6-digit OTP** with `random_int(100000, 999999)`.
2. Stores `otp_code` + `otp_expiry` (NOW + 10 min) + `otp_attempts = 0` on the user row.
3. Mails the OTP to the student.
4. User goes to `verify.php`, enters the OTP. Server checks:
   - Not expired (`otp_expiry > NOW()`).
   - Not too many wrong attempts (`otp_attempts < 3`).
   - Code matches (`hash_equals($u['otp_code'], $input)`).
5. On success: `is_verified=1`, OTP fields nulled out, redirect to login.
6. On wrong code: `otp_attempts++` — after 3 wrong tries the user must click **Resend OTP**.

`auth/resend_otp.php` rate-limits resends (only after 30 seconds) and issues a fresh code.

### 5.9 The page guards

Every protected page starts with **one** of these:

```php
require_role('student');             // student-only
require_role_any(['warden','owner']); // either
```

Inside, they call `get_auth()`. If the cookie is missing/expired/revoked → `header('Location: ../auth/login.php'); exit;`. So a student can never visit `/owner/dashboard.php` even by typing the URL.

---

## 6. ROLE PANELS

### 6.1 Student (`student/`)

Each file in `student/`:

| File | What it does | DB tables used |
|---|---|---|
| `index.php` | Redirects to dashboard.php (so `/student/` works) | — |
| `dashboard.php` | Shows room, attendance %, open complaints, pending leaves, recent activity | `users`, `rooms`, `bookings`, `attendance`, `complaints`, `leaves` |
| `browse_rooms.php` | List available rooms with filters; "Book" button | `rooms`, `bookings` |
| `book_room.php` | Inserts a `bookings` row with `status='pending_approval'` | `bookings` |
| `my_bookings.php` | Shows your bookings + status (approved/pending/rejected) | `bookings`, `rooms` |
| `room.php` | "My room" detail page | `rooms`, `bookings` |
| `request_transfer.php` | Insert a `room_transfers` row | `room_transfers`, `rooms` |
| `my_attendance.php` | Calendar of your present/absent days | `attendance` |
| `my_routine.php` | View the daily routine timetable | `daily_routine` |
| `food_routine.php` | View weekly menu | `food_routine` |
| `request_leave.php` | Submit a leave request | `leaves` |
| `payments.php` | View `monthly_fees` rows; "Pay with eSewa" button | `monthly_fees`, `payments` |
| `my_complaints.php` | Submit / view your complaints | `complaints` |

### 6.2 Warden (`warden/`)

| File | What it does |
|---|---|
| `dashboard.php` | Pending bookings, today's attendance count, students on leave, recent complaints |
| `list_student.php` | Browse / search students |
| `booking_requests.php` (+ `booking_approvals.php`, `room_requests.php`) | Approve/reject pending bookings — uses a DB transaction to keep room capacity in sync |
| `room_transfers.php` | Approve student-requested transfers, with audit log entry + price-diff `transfer_adjustments` |
| `student_attendance.php` | The big one — mark daily attendance with AJAX. **Absent → auto-email the student** |
| `manage_leaves.php` | First step of two-step leave approval — sets `warden_status` |
| `complaints.php` | Reply to / resolve complaints |
| `manage_rooms.php` | Light view of rooms (CRUD lives on owner) |

#### How AJAX attendance marking works

`warden/student_attendance.php` has two flows in **one file**:
1. **GET request** → renders the page (sticky calendar + student cards).
2. **POST with `ajax=1`** → returns `application/json`. JS clicks call this to upsert one student's attendance without a page reload. If `status='absent'`, it fires a PHPMailer email.

The trick to "upsert":
```php
$chk = $pdo->prepare("SELECT id FROM attendance WHERE student_id=? AND date=?");
if ($chk->fetch()) {
    // already exists — UPDATE
} else {
    // INSERT new row
}
```

### 6.3 Owner (`owner/`)

Owner is essentially the **manager**. Files:

| File | Purpose |
|---|---|
| `dashboard.php` | Total students/rooms/occupied/unread enquiries; recent registrations |
| `manage_rooms.php` | Full CRUD on rooms — create, update, mark maintenance, soft-delete |
| `payments.php` | View all `monthly_fees`; filter by month; **Mark Paid Manually** (cash); export CSV |
| `student_fees.php` | Per-student fee history |
| `enquiries.php` | Read/reply to public-website enquiries (replies email the parent) |
| `manage_routine.php` | CRUD daily routine rows |
| `manage_staff.php` | CRUD staff (cooks, cleaners, …) |
| `manage_leaves.php` | Second step of leave approval — sets `owner_status` + `final_status` |
| `report_attendance.php` | Date-range attendance report, donut chart, print/PDF |

### 6.4 Admin (`admin/`)

The smallest panel — just two real features:

| File | Purpose |
|---|---|
| `dashboard.php` | Counts of open/in_progress/resolved tickets, users-by-role chart |
| `resolve_tickets.php` | Inbox: mark a ticket `in_progress` or `resolved` (with `admin_note`); auto-emails the user |
| `manage_users.php` | Search/filter users, role changes, soft-delete |

---

## 7. THE `api/` FOLDER

Pure JSON / form endpoints. Each one is "fat-controller" style — checks auth, runs SQL, returns JSON or redirects. Output `Content-Type: application/json` so the JS can `fetch().then(r => r.json())`.

### 7.1 `api/help_ticket.php` — the file you have open

Submits a new help ticket. **POST-only, JSON response.**

```php
require_once '../db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {     // 1
    http_response_code(405); echo json_encode([...]); exit;
}

$auth = get_auth();                              // 2
if (!$auth) { http_response_code(401); ... exit; }

csrf_verify($_POST['csrf_token'] ?? '');         // 3

$subject     = trim($_POST['subject'] ?? '');    // 4
$description = trim($_POST['description'] ?? '');
if (!$subject) { http_response_code(400); ... }
if (mb_strlen($subject) > 255) { ... }
if (mb_strlen($description) < 10) { ... }

$stmt = $pdo->prepare(                           // 5
    "INSERT INTO help_tickets (user_id, role, subject, description, status)
     VALUES (?, ?, ?, ?, 'open')"
);
$stmt->execute([$auth['id'], $auth['role'], $subject, $description]);
echo json_encode(['success' => true, 'message' => 'Ticket submitted.']);
```

**Line-by-line defence answers:**

| Line | What it does | Why |
|---|---|---|
| `require_once '../db.php'` | Loads the DB connection + helpers | Without it, no `$pdo`, no `get_auth()`, no `csrf_verify()` |
| `header('Content-Type: application/json')` | Tells the browser the response is JSON | So `fetch().then(r => r.json())` works automatically |
| `if (… !== 'POST')` | Reject GET / PUT / DELETE | Tickets are state-changing — must be POST |
| `$auth = get_auth();` | Identify the logged-in user from the JWT cookie | Only authenticated users can file tickets |
| `csrf_verify(...)` | Anti-CSRF check | Stops cross-site forged submissions |
| `trim(...)` | Strip leading/trailing whitespace | Cleans user input |
| `mb_strlen()` | Multibyte-safe string length | Works correctly even with Unicode/emoji |
| `$pdo->prepare(...)` + `execute([...])` | **Prepared statement** | Stops SQL injection — values are escaped automatically |
| `echo json_encode([...])` | Convert PHP array to JSON string | Sends a structured response back to JS |

**If you removed this file**: the "Need help?" button in the topbar would 404 — there's nowhere to POST the ticket.

### 7.2 `api/submit_enquiry.php`

Same shape — POST-only, JSON. Inserts into `enquiries`. Used by the public landing page's "Schedule a Visit" form. Notable: **no auth check** — public users can submit (only CSRF + field validation).

### 7.3 `api/payment.php` — eSewa initiation

The big one. Two endpoints:

- `action=esewa_init` — student starts paying a `monthly_fees` row.
- Used during the payment flow only.

Flow:
1. Check `$auth` is a student.
2. Verify the `monthly_fee_id` belongs to the user and is `unpaid`/`overdue`.
3. Cancel any existing `pending` payment row (eSewa rejects re-used UUIDs).
4. Generate a fresh `transaction_uuid`: `HMS-YYMMDDhhmmss-XXXXXXXX` (`bin2hex(random_bytes(4))`).
5. INSERT a `payments` row with `status='pending'`, `gateway='esewa'`.
6. Build the signed string `total_amount=...,transaction_uuid=...,product_code=EPAYTEST`.
7. Compute HMAC-SHA256 signature with `esewa_sign()` (the secret is in config).
8. Render an HTML form that auto-submits to `https://rc-epay.esewa.com.np/api/epay/main/v2/form`.

### 7.4 `api/payment_callback.php` — eSewa callback

eSewa redirects the browser back here after payment. The `data` query parameter is base64-encoded JSON.

1. Decode the data.
2. Re-build the sign string from `signed_field_names` (the field order eSewa says it signed).
3. Verify the signature with `hash_equals()` against what eSewa sent in `signature`.
4. Optionally hit eSewa's status API to triple-check `status=COMPLETE`.
5. In one DB **transaction**: mark `payments.status='success'`, mark `monthly_fees.status='paid'`, set `paid_payment_id`.
6. Generate a unique `receipt_code` like `HMS-RCPT-202605-AB12CD`.
7. Render a branded receipt email via `render_receipt_email()` + `send_app_mail()`.
8. Redirect back to `student/payments.php?success=1&receipt=...`.

**Why HMAC verification matters:** without it, an attacker could craft a fake "payment success" URL and mark their fee paid. The HMAC tells us *only eSewa* (which knows the secret) could have produced this signature.

---

## 8. THE CHATBOT

Lives in `chatbot/`. Two files:

- `chatbot/widget.php` — renders the floating bubble + chat panel HTML (loaded onto every page).
- `chatbot/api.php` — the brain. POST-only JSON.

It's **rule-based** (no AI/ML). Logic:

1. Get the user's message.
2. Tokenise it (lowercase, strip punctuation, split on whitespace).
3. Pull all active rows from `chatbot_intents` (each has comma-separated `keywords`, a `response`, and a `priority`).
4. For each intent, count keyword matches in the tokens. Multi-word keywords like `fee payment` score 2, single words score 1.
5. The intent with the highest score wins (ties broken by `priority`).
6. If **no intent matches**: insert the message into `enquiries` and reply "Got it — we've forwarded your question to the manager."
7. Log every exchange in `chatbot_conversations` so analytics + ratings work.

The `action=rate` endpoint lets the user thumbs-up/down a reply — stored in `chatbot_conversations.rating`.

---

## 9. LANDING PAGE

`home/index.php` — the public marketing page. No auth required. Contains:
- Hero section
- "About us" / amenities
- The "Schedule a Visit" form → POSTs to `api/submit_enquiry.php`
- FAQ chatbot bubble
- Login / Register links to `auth/login.php`

CSS for it is `css/landing.css`; the rest of the app uses `css/style.css`.

---

## 10. GLOSSARY OF PHP FUNCTIONS

You'll see all of these in the codebase. Memorise the **one-line description** for each.

### 10.1 DB / mysqli

| Function | Meaning |
|---|---|
| `mysqli_connect($host, $user, $pass, $db)` | Open a MySQL connection (returns `$conn`) |
| `mysqli_query($conn, "SQL")` | Run a query (returns a result or `false`) |
| `mysqli_fetch_assoc($result)` | Get the next row as `['col' => 'value']` |
| `mysqli_fetch_row($result)` | Get the next row as an indexed array |
| `mysqli_num_rows($result)` | How many rows did it return? |
| `mysqli_real_escape_string($conn, $s)` | Escape a value for SQL (only used when not using prepares) |
| `mysqli_set_charset($conn, 'utf8mb4')` | Use UTF-8 so accents & emojis work |

### 10.2 DB / PDO (newer, preferred)

| Function | Meaning |
|---|---|
| `new PDO('mysql:...;dbname=...', $user, $pass)` | Open a connection |
| `$pdo->prepare("SELECT ... WHERE id=?")` | Make a prepared statement |
| `$stmt->execute([$id])` | Run it with values bound to `?` placeholders |
| `$stmt->fetch()` | Get next row (default: associative array) |
| `$stmt->fetchAll()` | Get every remaining row |
| `$stmt->fetchColumn()` | Get just the first column of the first row (good for `SELECT COUNT(*)`) |
| `$pdo->lastInsertId()` | Get the auto-increment id of the row you just inserted |
| `$pdo->beginTransaction()` / `commit()` / `rollBack()` | Atomic multi-statement updates |

> **Prepared statements** are the #1 defence against SQL injection. The `?` placeholders are *not* string concatenation — the DB driver sends the query and the values separately, so user input can never be parsed as SQL.

### 10.3 Strings

| Function | Meaning |
|---|---|
| `trim($s)` | Strip surrounding whitespace |
| `strtolower` / `strtoupper` | Case change |
| `strlen($s)` | Length in bytes |
| `mb_strlen($s)` | Length in characters (Unicode-safe) |
| `strpos($haystack, $needle)` | Find substring position (or `false`) |
| `str_replace` | Find & replace |
| `htmlspecialchars($s)` | Escape `<>&"'` for safe HTML output |
| `nl2br($s)` | Convert `\n` to `<br>` |
| `urlencode($s)` | URL-escape (spaces → `%20`) |
| `sprintf("%d", $x)` | Formatted string (like C printf) |
| `bin2hex($bin)` | Binary → hex string |
| `random_bytes(32)` | 32 cryptographically random bytes |
| `random_int(min, max)` | Cryptographically random integer |
| `hash_hmac('sha256', $data, $secret, true)` | HMAC for JWTs / eSewa |
| `hash_equals($a, $b)` | Constant-time string compare (for tokens) |
| `base64_encode` / `base64_decode` | Standard base64 |

### 10.4 Arrays

| Function | Meaning |
|---|---|
| `count($arr)` | Number of items |
| `array_filter($arr, $fn)` | Keep items where `$fn` returns truthy |
| `array_map($fn, $arr)` | Apply `$fn` to every item |
| `in_array($needle, $arr, true)` | Is this value present? (third arg = strict ===) |
| `explode(',', $s)` | String → array (split) |
| `implode(',', $arr)` | Array → string (join) |
| `array_values($arr)` | Drop keys, re-index from 0 |
| `array_filter($arr)` | Drop falsy items |

### 10.5 Auth / security

| Function | Meaning |
|---|---|
| `password_hash($pass, PASSWORD_DEFAULT)` | bcrypt-hash a password for storage |
| `password_verify($plain, $hash)` | Constant-time check of plaintext vs hash |
| `session_start()` | Begin / resume the user's session |
| `session_regenerate_id(true)` | New session ID; delete the old (anti-fixation) |
| `session_destroy()` | Delete session data on server |
| `session_unset()` | Empty `$_SESSION` |

### 10.6 Files / uploads

| Function | Meaning |
|---|---|
| `$_FILES['photo']['tmp_name']` | Server-side temp path of the uploaded file |
| `$_FILES['photo']['name']` | Original filename |
| `pathinfo($name, PATHINFO_EXTENSION)` | Get file extension |
| `move_uploaded_file($tmp, $dest)` | Move from temp to permanent location |
| `is_dir($path)` / `mkdir($path, 0755, true)` | Create folder if missing |
| `file_get_contents($path)` | Read whole file as string |
| `file_put_contents($path, $data)` | Write a string to a file |
| `fopen` / `fputcsv` / `fclose` | Used for CSV export in attendance/payments |

### 10.7 HTTP / output

| Function | Meaning |
|---|---|
| `header("Location: x.php")` | Set response header (redirect, content-type, status…) |
| `http_response_code(403)` | Set the HTTP status code |
| `exit;` / `die("msg")` | Stop the script |
| `echo` / `print` | Send text to the response body |
| `json_encode($arr)` / `json_decode($s, true)` | PHP array ↔ JSON |
| `setcookie($name, $val, $opts)` | Send a Set-Cookie header |

### 10.8 Validation

| Function | Meaning |
|---|---|
| `filter_var($e, FILTER_VALIDATE_EMAIL)` | Returns the email if valid, else `false` |
| `preg_match('/regex/', $s)` | Regex match — returns 1/0 |
| `isset($x)` | Is the variable set & not null? |
| `empty($x)` | Is it falsy (`""`, `0`, `null`, `[]`)? |
| `is_array($x)` / `is_string($x)` / `is_int($x)` | Type checks |
| `intval($s)` or `(int)$s` | Cast to integer |

### 10.9 Date / time

| Function | Meaning |
|---|---|
| `time()` | Current Unix timestamp |
| `date('Y-m-d H:i:s')` | Format current time |
| `strtotime("2026-05-14")` | Parse string → timestamp |
| `date('Y-m-d', strtotime('+1 day'))` | "Tomorrow" |

---

## 11. LIKELY VIVA QUESTIONS

### Q1. "What is PHP, and how is it different from JavaScript?"
PHP is a **server-side** language — it runs on Apache before the browser sees anything, and its job is to generate HTML/JSON to send to the browser. JavaScript runs **in the browser** to make pages interactive. They communicate over HTTP — JS calls PHP endpoints via `fetch()`.

### Q2. "Walk me through what happens when a student logs in."
1. Browser POSTs email+password+csrf_token to `auth/login.php`.
2. `csrf_verify()` checks the token against the session.
3. `$pdo->prepare(... WHERE email = ?)` fetches the user.
4. `password_verify($input, $hashed)` checks the password.
5. `is_verified=1` is checked (OTP was confirmed earlier).
6. `set_auth()` signs a JWT, sets it as an HttpOnly cookie, fills `$_SESSION`.
7. Redirects to `student/dashboard.php`.
8. On that page, `require_role('student')` calls `get_auth()` which verifies the JWT signature, expiry, and `token_version` against the DB.

### Q3. "How are you protecting against SQL injection?"
We use **prepared statements** everywhere. Example:
```php
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
```
The `?` is a placeholder — the value is sent separately from the SQL string, so even if the user types `'; DROP TABLE users; --` the database treats it as a literal string, not SQL.

### Q4. "How are passwords stored?"
With `password_hash($pass, PASSWORD_DEFAULT)` which uses **bcrypt** with cost 10 and a random per-user salt. The hash looks like `$2y$10$...`. We never store plaintext. To check a login we use `password_verify($plain, $hash)`.

### Q5. "What is CSRF and how does this app stop it?"
Cross-Site Request Forgery — a malicious site auto-submits a form to our app using your cookie. We stop it by generating a per-session secret token (`bin2hex(random_bytes(32))`), embedding it in every form as a hidden input, and verifying with `hash_equals()` on submit. The malicious site can't read our session, so it can't forge the right token.

### Q6. "What is a JWT? Why use one instead of just a session?"
JSON Web Token — a base64-encoded `header.payload.signature` string. The signature is HMAC-SHA256 of (header+payload) with `JWT_SECRET`. We use it because cookies are **stateless** — the server can recognise a user from the JWT alone, without a session lookup. The trick is `token_version`: we still consult the DB to allow instant revocation when the user changes their password or logs out.

### Q7. "Why does the schema have both `users.room_id` and a `bookings` table?"
`users.room_id` is the **legacy** quick lookup. `bookings` is the **canonical** source — it can hold history, pending requests, and approval state (`pending_approval`, `approved`, `rejected`, `cancelled`). New code prefers `bookings`; old code still works because we sync `users.room_id` when a booking becomes approved.

### Q8. "What does `require_once '../db.php'` actually do?"
It includes the contents of `db.php` into the current file — but only once even if called multiple times. Inside `db.php` is the database connection, session start, and every helper function. Without it the page wouldn't have `$pdo`, `$conn`, or any of the helpers.

### Q9. "Explain the eSewa flow."
1. Student clicks **Pay with eSewa** on `student/payments.php`.
2. `api/payment.php?action=esewa_init` validates the fee, creates a `payments` row with `status='pending'`, generates a fresh `transaction_uuid`, builds a sign string, HMAC-signs it with `ESEWA_SECRET`, and renders an auto-submitting form to eSewa's sandbox.
3. Student pays inside eSewa.
4. eSewa redirects back to `api/payment_callback.php?data=<base64>`.
5. We re-compute the signature with the same secret and `hash_equals()` it. If it matches, we mark the payment success, set the fee to paid, generate a unique receipt code, and email a branded receipt.

### Q10. "If I deleted `db.php`, what would happen?"
Every other page would fail with a fatal error on its first line (`require_once` of a missing file). No DB connection, no auth, no sessions, no helpers. The whole app is dead.

### Q11. "Why two database libraries (mysqli AND PDO)?"
Historical — older parts of the code used **mysqli** (procedural, simpler). Newer code uses **PDO** (object-oriented, more features, easier prepared statements). Migrating everything to PDO is a future cleanup; for now both connect to the same database.

### Q12. "Explain `htmlspecialchars()`."
It converts the 5 dangerous HTML characters — `< > & " '` — into HTML entities (`&lt;`, `&gt;`, `&amp;`, `&quot;`, `&#039;`). This stops **XSS** — if a user's name is `<script>alert(1)</script>`, escaping it means the browser shows the text instead of running the script. We aliased it to `e()` for short usage everywhere: `<?= e($name) ?>`.

### Q13. "What's the role of `token_version`?"
It's an integer on each user row. The JWT embeds it as `tv`. On every page load, `get_auth()` compares the cookie's `tv` to the DB's current `token_version`. Bump the DB value (via `revoke_user_tokens()`) and every existing JWT becomes invalid — used for **logout** and **password change**.

### Q14. "How does the chatbot know what to reply?"
It's rule-based — no AI. The `chatbot_intents` table has keyword sets like `"fee, payment, esewa" → "You can pay your fee from the Pay Fees menu…"`. The user's message is tokenised; we count keyword matches per intent and pick the highest score (priority breaks ties). Unmatched messages get auto-forwarded to the owner's `enquiries` inbox.

### Q15. "What is XAMPP? Why are we using it?"
XAMPP bundles **Apache** (web server) + **MySQL** (database) + **PHP** for local development on Windows. Without it we'd have to install each piece separately. Production servers wouldn't use XAMPP; they'd use individual production builds of nginx/Apache + MySQL + PHP-FPM.

---

## 12. CHEAT SHEET

```
WORLD AT A GLANCE
─────────────────
Apache  → port 80  → serves http://localhost/...
MySQL   → port 3306 → database "hostel2"
PHP 8   → interprets every .php file

THE 4 ROLES
───────────
student ← can: book rooms, pay fees, leave/transfer requests, complaints
warden  ← can: approve bookings, mark attendance, first-step leaves
owner   ← can: CRUD rooms, view payments, reply enquiries, second-step leaves
admin   ← can: resolve help tickets, manage users

THE 6 SECURITY PILLARS
──────────────────────
1. Prepared statements        → no SQL injection
2. htmlspecialchars (e())     → no XSS
3. CSRF tokens                → no cross-site form forgery
4. password_hash bcrypt       → no plaintext passwords
5. JWT + token_version        → instant logout / revocation
6. HMAC signatures            → eSewa & JWT can't be forged

THE 4 ENTRY POINTS PHP USES
───────────────────────────
$_GET    → URL query string
$_POST   → form data
$_FILES  → uploaded files
$_COOKIE → cookies (we read auth_token from here)

THE 1-LINER ON EACH ROOT FILE
──────────────────────────────
index.php  → redirect to home/index.php
db.php     → DB + auth + helpers (every page require_once's this)
config.php → secrets (gitignored)

THE 3 BIG TABLES
────────────────
users      → everyone (role ENUM = student/warden/owner/admin)
rooms      → each room with capacity, price, status
bookings   → who's in (or wants to be in) which room

THE 3 BIG MONEY TABLES
──────────────────────
monthly_fees         → auto-bill per student per month
payments             → every paid attempt (esewa or manual)
transfer_adjustments → price diff when student switches rooms
```

---

## FINAL TIPS BEFORE THE VIVA

1. **Open `db.php` in your editor and skim the function list** (use the table in §3.3). If you know what each helper does, you can defend any page that uses them.
2. **Open `database_schema.sql`** and be ready to read any `CREATE TABLE`. Knowing the columns gives away half the answers.
3. **Walk through one login & one payment in your head** end-to-end without looking. That's the most likely "explain the workflow" question.
4. **Memorise the 6 security pillars** — examiners love security questions for web projects.
5. **Don't lie or invent jargon.** If you don't know, say "I'd need to check the file, but I think it's…" — that's much better than a confident wrong answer.
6. When asked "what does this line do?", use the **what / why / what-breaks-if-removed** structure. That structure makes your answer sound deep even if the line is trivial.

Good luck — you'll be fine.
