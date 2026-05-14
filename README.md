# HMS — Hostel Management System

A web application for running a student hostel end-to-end: rooms, bookings, monthly fees, attendance, complaints, leave requests, room transfers, payments, plus a public landing page with an FAQ chatbot.

Built by **Team Legion** for our college Software Engineering project.

> **Stack:** PHP 8 / MySQL / vanilla JavaScript on XAMPP. No frameworks, no bundlers — drop the project into `htdocs`, point a browser at it, and it runs.

---

## Table of contents

- [Quick start](#quick-start)
- [Demo accounts](#demo-accounts)
- [Folder layout](#folder-layout)
- [What each role can do](#what-each-role-can-do)
- [Auth & password rules](#auth--password-rules)
- [Architecture in one picture](#architecture-in-one-picture)
- [Key files & functions](#key-files--functions)
- [Payments](#payments)
- [Email setup](#email-setup)
- [Configuration reference](#configuration-reference)
- [See also: WORKFLOW.md](#see-also)

For the full screen-by-screen workflow, function reference, and flow diagrams, see **[WORKFLOW.md](WORKFLOW.md)**.

---

## Quick start

XAMPP on Windows (other LAMP setups work the same way — just install equivalents):

1. **Clone into XAMPP's web root** so the relative URLs resolve cleanly:
   ```
   C:\xampp\htdocs\team-legion-hostel-management-system-1
   ```
2. **Start Apache + MySQL** from the XAMPP Control Panel.
3. **Create the database** in phpMyAdmin:
   - Database name: `hostel2`
   - Import `database_schema.sql` (creates tables, seeds demo accounts).
4. **Copy the config template and fill it in**:
   ```
   copy config.example.php config.php
   ```
   Edit `config.php` — at minimum, set your Gmail SMTP credentials ([instructions](#email-setup)). The DB defaults already match XAMPP's stock `root` / no-password setup.
5. **Visit** `http://localhost/team-legion-hostel-management-system-1/` — the root redirects to the public landing page.
6. Log in with one of the demo accounts below, or self-register as a student.

`db.php` runs idempotent auto-migrations on every page load, so newer columns/tables get added automatically when you `git pull`.

---

## Demo accounts

Password for every seeded account is **`Test1234`**.

| Role    | Email              |
|---------|--------------------|
| Admin   | `admin@hms.com`    |
| Owner   | `owner@hms.com`    |
| Warden  | `warden@hms.com`   |
| Student | `ali@student.com`  |
| Student | `sara@student.com` |
| Student | `tom@student.com`  |

Self-registered students go through real OTP email verification.

---

## Folder layout

Code is grouped by **who uses it**, not by what kind of file it is. Each role owns one folder; auth/OTP and the public site live in their own folders.

```
team-legion-hostel-management-system-1/
├── index.php              redirects to home/index.php
├── db.php                 core: PDO + mysqli, JWT auth, CSRF, sidebar/topbar/footer,
│                          notifications, auto-migrations, password rules
├── config.php             secrets (DB creds, SMTP, payment gateway keys) — gitignored
├── config.example.php     template to copy from
├── database_schema.sql    initial schema + demo accounts
├── README.md              you are here
├── WORKFLOW.md            full workflow + diagrams + per-file reference
│
├── home/                  public landing page (no login required)
├── auth/                  registration, login, logout, OTP verify, resend OTP,
│                          forgot/reset password, change password, profile,
│                          mailer.php (branded email helpers)
│
├── student/               every page a logged-in student sees
├── warden/                every page a logged-in warden sees
├── owner/                 every page a logged-in owner sees
├── admin/                 admin help-ticket triage + user management
│
├── api/                   JSON endpoints — payment.php, payment_callback.php,
│                          submit_enquiry.php, chatbot_intent.php
├── chatbot/               rule-based FAQ widget
│
├── assets/img/            static images (landing-page hero, activity shots)
├── css/                   style.css (app) + landing.css (public site)
├── js/                    script.js (only — confirm modal, toasts, theme toggle)
├── uploads/students/      profile photos + cover photos uploaded by users
├── uploads/rooms/         room photos uploaded by owner
├── logs/                  smtp.log + payment_callback.log
└── vendor/                Composer deps (PHPMailer)
```

---

## What each role can do

### Student
- Register with email + photo → 6-digit OTP → verify → log in
- Browse available rooms (filter by type / floor / max price); live "x/y beds taken" badges and "LAST BED" tags
- Book a room — goes to the warden as a pending request
- Pay monthly fees through the **eSewa v2 sandbox** — one click on the fee row redirects into eSewa, and the signed callback finalises the payment server-side
- Get a branded HTML receipt email with a unique `HMS-RCPT-YYYYMM-XXXXXX` code as soon as the callback verifies the signature
- See own attendance, daily routine, meal schedule
- Submit complaints, request leave, request room transfer
- Get **notifications** for: leave approved/rejected, fees due, support ticket resolved, room transfer approved/declined, transfer top-up due

### Warden
- Approve / reject booking requests (transactional, capacity-aware — a 2-bed room only seats 2)
- See full student list, mark daily attendance (P/A toggle, auto-saves)
- Absent students automatically get a polite branded email asking why
- Attendance report (donut chart + per-student %)
- Review room transfer requests, leave requests, complaints
- Move a student between rooms directly (audited; logs into `room_audit_log` event `warden_transfer`)
- Price-difference auto-reconciles: a transfer to a pricier room creates a `transfer_adjustments` row marked `charge` so the student sees a top-up due

### Owner
- CRUD rooms (locked while occupied; can be marked maintenance)
- Add room: humanized form with chip-style type selector (Single/Double/Triple), drag-drop photo dropzone, NPR price prefix
- See every fee row, filter by month, search by student, **export to CSV**
- **Mark paid manually** for cash-in-person payments — records a `gateway='manual'` payment, generates a receipt code, mails it
- (eSewa payments need **no owner action** — the HMAC-verified callback marks the fee paid automatically)
- Track per-student fee history with last paid date / amount / gateway
- Manage staff, daily routine, leave requests, enquiries (replies email the parent)
- Full attendance report with date-range, search, print/PDF

### Admin
- Help-ticket inbox (students, wardens and owners file tickets; admin resolves them, students/staff get an email)
- Manage users (search/filter, soft-delete, role changes)

---

## Auth & password rules

The auth layer is built on a **signed JWT cookie** plus PHP `$_SESSION`:

- `auth/login.php` issues a signed JWT (`auth_token`) on successful login via `set_auth()`
- Every page that needs a logged-in user calls `require_role()` or `require_role_any()`
- `get_auth()` reads the JWT cookie and validates its `tv` field against the user's current `token_version` — bumping `token_version` instantly invalidates every existing session for that user (used by password-change and logout-all-devices)
- `refresh_auth_cookie()` re-issues the JWT after the user updates their name/photo so the topbar avatar reflects the new data without a logout
- All POST endpoints are CSRF-protected (`csrf_token()` / `csrf_verify()`)

**Strong password policy** (`validate_strong_password()` in `db.php`):

A password is only accepted if it has:
- At least **6 characters**
- At least **one uppercase letter** (A–Z)
- At least **one lowercase letter** (a–z)
- At least **one digit** (0–9)

The same rules are enforced in every flow that sets a password — registration, password reset (via OTP), and the in-app **Change Password** page. The HTML inputs also carry an HTML5 `pattern` attribute so the browser blocks weak passwords before the form is even sent.

**Wrong password feedback:**
- The wrong-password message is now conversational: *"Wrong password — try again. (Use 'Forgot password?' if you can't recall it.)"*
- A different message fires when the email itself isn't found, so users aren't left guessing which field was wrong.
- The login card shakes briefly when an error is returned (`shake` keyframe in `auth/login.php`), drawing the eye to the toast that appears in the corner.

---

## Architecture in one picture

```
                         ┌──────────────────────┐
                         │   home/index.php     │ ← public landing + enquiry form
                         │   (no auth)          │
                         └──────────┬───────────┘
                                    │
                  ┌─────────────────┴─────────────────┐
                  │     auth/login.php (POST)         │
                  │ • verifies password               │
                  │ • signs JWT, sets cookie          │
                  │ • redirects by role               │
                  └─────────────────┬─────────────────┘
                                    │
            ┌─────────────┬─────────┴──────────┬───────────────┐
            ▼             ▼                    ▼               ▼
       student/       warden/              owner/           admin/
       dashboard.php  dashboard.php       dashboard.php    dashboard.php

   Every page:  require_once '../db.php';
                require_role('student'|'warden'|'owner'|'admin');
                ← which reads JWT cookie via get_auth()
                ← bounces unauthenticated users to auth/login.php

   Every page renders the shared chrome:
                <?= render_sidebar($active) ?>   ← role-aware nav
                <?= render_topbar('Title') ?>    ← user dropdown + bell + theme toggle
                <div class="container"> … </div>
                <?= render_footer() ?>           ← info band + thin copyright
```

For sequence diagrams of each flow (registration, booking, payment, transfer), see **[WORKFLOW.md](WORKFLOW.md)**.

---

## Key files & functions

### `db.php`
The core of the app — every other PHP file `require_once`s this.

| Function | What it does |
|---|---|
| `app_config($key, $default)` | Reads a config value, env-var first then `config.php` |
| `csrf_token()` / `csrf_verify($t)` | Single-use form tokens, stored in session |
| `jwt_encode($payload)` / `jwt_decode($token)` | HMAC-signed compact JWT for auth cookie |
| `set_auth($user)` | Login — issues the JWT cookie + populates session |
| `get_auth()` | Reads the cookie, validates signature/expiry/token_version, returns payload or `false` |
| `refresh_auth_cookie($user_id)` | Re-issues JWT with fresh DB data (used after profile updates) |
| `revoke_user_tokens($user_id)` | Bumps `token_version`, instantly invalidates every existing JWT |
| `require_role($role)` / `require_role_any([roles])` | Page guards — bounce unauthorised users to login |
| `validate_strong_password($pass)` | Returns `null` if OK, or an error string (used by signup / reset / change-password) |
| `password_rules_hint()` | Human-readable description of the password rules — shown next to inputs |
| `render_sidebar($active_page)` | Role-aware left nav |
| `render_topbar($title)` | Fixed top bar — bell + help + theme toggle + profile menu |
| `render_footer()` | Footer info band + copyright line |
| `get_notifications($user_id, $role)` | Builds the bell-dropdown items: leaves, fees, transfers, transfer dues, support tickets |
| `e($s)` | `htmlspecialchars()` shorthand |
| `get_svg_icon($name)` | Inline SVG icons used in nav + cards |
| `auto_create_monthly_fees()` / `generate_monthly_fees($month)` / `mark_overdue_fees()` | Idempotent helpers run on owner-side page loads |
| `esewa_sign($data)` | HMAC-SHA256 of `ESEWA_SECRET`, base64-encoded — used both when initiating and when verifying eSewa callbacks |
| `generate_receipt_code($month)` | Unique receipt code `HMS-RCPT-YYYYMM-XXXXXX`, retried up to 10× on collision |

### `auth/mailer.php`
- `send_app_mail($to, $name, $subject, $html)` — fires a PHPMailer SMTP send, logs to `logs/smtp.log`
- `render_branded_email($vars)` — generic branded HTML wrapper (gradient header, footer, accent colour) used by OTP, booking, transfer, ticket-resolved and absent-attendance emails
- `render_receipt_email($vars)` — dedicated receipt template used by `payment_callback.php` and the owner's "Mark paid manually" flow

### `js/script.js`
- `hmsConfirm(message, onConfirm, title)` — styled confirm modal used everywhere a `data-confirm` form is wired
- `hmsToast(message, type)` — programmatic floating toast
- Auto-converts `.alert` server-rendered messages into floating toasts
- Theme toggle, sidebar collapse on mobile

### Per-role dashboards
- `student/dashboard.php` — room status, attendance %, open complaints, pending leaves, recent activity
- `warden/dashboard.php` — pending bookings/transfers/leaves, today's attendance, low-attendance list, recent complaints, students on leave
- `owner/dashboard.php` — total students/rooms/occupied, daily registration sparkline, pending approvals, recent students, warden list
- `admin/dashboard.php` — help ticket counts, users-by-role chart, recent activity

---

## Payments

The app uses **eSewa's v2 sandbox** (`rc-epay.esewa.com.np`) for online fee payment. There is **no manual QR-scan claim flow** — the student pays inside eSewa's sandbox and the callback updates everything server-side. The owner has an "out-of-band" path for cash payments (see below).

**Student-side flow** (`student/payments.php` → `api/payment.php` → eSewa sandbox → `api/payment_callback.php`):

1. Student lands on their current month's fee row, taps **Pay with eSewa**.
2. `api/payment.php?action=esewa_init` runs:
   - Verifies CSRF + that the `monthly_fee_id` belongs to this student and is `unpaid`/`overdue`.
   - Cancels any previous `pending` payment for the same `billing_month` (eSewa rejects reused UUIDs).
   - Generates a fresh transaction UUID: `HMS-{YYMMDDhhmmss}-{8 hex chars}`.
   - Inserts a row into the **`payments`** table with `status='pending'`, `gateway='esewa'`.
   - Builds the signed string `total_amount=…,transaction_uuid=…,product_code=EPAYTEST` and signs it with `esewa_sign()` (HMAC-SHA256 of `ESEWA_SECRET`).
   - Renders an auto-submitting HTML form POSTing to `rc-epay.esewa.com.np/api/epay/main/v2/form`.
3. Student completes payment inside eSewa.
4. eSewa redirects back to `api/payment_callback.php?data=BASE64_JSON` (success) or `…?result=failure&pid=N` (cancel/fail).
5. `payment_callback.php`:
   - On failure: marks the matching `payments` row `cancelled`, bounces back with a toast.
   - On success: base64-decodes the response, reconstructs the sign string from `signed_field_names`, verifies the signature with `hash_equals()`.
   - Optional extra check: calls eSewa's status API (`rc.esewa.com.np/api/epay/transaction-tax-bill/status/`) to confirm `status=COMPLETE`.
   - Generates a receipt code with `generate_receipt_code($billing_month)` → `HMS-RCPT-YYYYMM-XXXXXX` (unique, retried up to 10 times).
   - In one transaction: marks `payments` `success`, sets `monthly_fees.status='paid'` + `paid_payment_id`, syncs legacy `users.fee_status='paid'`.
   - Sends a branded HTML receipt via `render_receipt_email()` + `send_app_mail()`.
   - Redirects to `student/payments.php?success=1&receipt=HMS-RCPT-…`.

**No owner approval is needed for eSewa payments** — the HMAC signature is the trust anchor.

**Owner-side flow** (`owner/payments.php`):

- See every fee row, filter by month, search by student, export to CSV.
- **Mark paid manually** — used when a student pays cash in person. Records the payment with `gateway='manual'`, generates a receipt code, mails it. This is the only payment action the owner takes.

**Wardens are blocked from the payment system** (`api/payment.php` returns HTTP 403 for `role='warden'`).

> The eSewa sandbox secret + merchant code are already in `config.example.php` so a fresh clone can demo end-to-end without signup. Switch `ESEWA_BASE` to `https://epay.esewa.com.np` and use your real merchant credentials when going live.

---

## Email setup

All emails go through **PHPMailer over Gmail SMTP**.

1. Enable 2-Step Verification on your Google account.
2. Create an **App password** at https://myaccount.google.com/apppasswords
3. In `config.php`:
   ```php
   'SMTP_HOST'       => 'smtp.gmail.com',
   'SMTP_PORT'       => 587,
   'SMTP_USER'       => 'your-address@gmail.com',
   'SMTP_PASS'       => 'app password from step 2',
   'SMTP_SECURE'     => 'tls',
   'SMTP_FROM_EMAIL' => 'your-address@gmail.com',
   'SMTP_FROM_NAME'  => 'HMS',
   ```

Every send is logged to `logs/smtp.log`. If OTPs aren't arriving, that file has the answer.

> **XAMPP on Windows note:** `mailer.php` disables peer verification (`verify_peer=false`) because XAMPP ships without a CA bundle. Safe for local dev, tighten in production.

All transactional emails (OTP, booking approved/rejected, payment receipt, absent notification, ticket resolved, transfer approved) use `render_branded_email()` for a consistent look.

---

## Configuration reference

| Key                       | What it's for                                                            |
|---------------------------|--------------------------------------------------------------------------|
| `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` | MySQL connection                                       |
| `JWT_SECRET`              | Signs the auth cookie — use a long random string in production           |
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_SECURE` | Gmail SMTP credentials       |
| `SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` | Sender shown on every outgoing email                          |
| `PAYMENT_BASE_URL`        | Base URL eSewa uses to redirect back (e.g. `http://localhost/team-legion-hostel-management-system-1`) — **no trailing slash, no path arguments** (eSewa appends `?data=…` to your success URL) |
| `ESEWA_BASE`              | `https://rc-epay.esewa.com.np` for sandbox · `https://epay.esewa.com.np` for live |
| `ESEWA_STATUS_BASE`       | `https://rc.esewa.com.np` for sandbox · `https://epay.esewa.com.np` for live |
| `ESEWA_MERCHANT`          | `EPAYTEST` for sandbox · your assigned merchant code for live            |
| `ESEWA_SECRET`            | `8gBm/:&EnhH.1/q` for sandbox · your HMAC secret for live                |

Never commit `config.php` — it's in `.gitignore`.

---

## See also

- **[WORKFLOW.md](WORKFLOW.md)** — full workflow with sequence diagrams for registration, login, booking, payment, transfers, plus a per-file reference and how the code is wired together.

---

## Team Legion

Built as a college SE project. The code prefers **simple over clever** — flat PHP files, prepared statements, no ORM, no router. If you can read the file path, you can find the feature.
