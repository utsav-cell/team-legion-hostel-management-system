# HMS — Workflow & File Reference

Everything in one place: what each PHP file is for, how a request flows from a browser hit to a DB write, and sequence diagrams for the five main user journeys.

> Read this top-to-bottom on your first day, then come back to specific sections as a reference. Pair it with [README.md](README.md) for setup and the high-level architecture.

---

## Table of contents

1. [High-level request lifecycle](#1-high-level-request-lifecycle)
2. [Roles and what each one owns](#2-roles-and-what-each-one-owns)
3. [Auth flow (registration → OTP → login → dashboard)](#3-auth-flow)
4. [Room booking flow (student → warden → fee creation)](#4-room-booking-flow)
5. [Payment flow (eSewa v2 sandbox: initiate → callback → receipt; plus cash/manual)](#5-payment-flow)
6. [Room transfer flow (with price-difference dues)](#6-room-transfer-flow)
7. [Attendance flow (daily marking → absent emails → reports)](#7-attendance-flow)
8. [Notification system](#8-notification-system)
9. [Per-file reference](#9-per-file-reference)
10. [Database schema (canonical tables)](#10-database-schema)
11. [Key functions in db.php](#11-key-functions-in-dbphp)

---

## 1. High-level request lifecycle

Every authenticated page goes through the same six steps:

```
┌────────────────────────────────────────────────────────────────────────┐
│  Browser → Apache → PHP-FPM → role-folder/page.php                    │
└────────────────────────────────────────────────────────────────────────┘

  ① require_once '../db.php';
        loads PDO + mysqli connections
        runs idempotent auto-migrations (ALTER TABLE IF NOT EXISTS …)

  ② require_role('student' | 'warden' | 'owner' | 'admin');
        calls get_auth()
        verifies JWT signature + expiry + token_version
        if invalid → header('Location: ../auth/login.php'); exit

  ③ (optional) csrf_verify($_POST['csrf_token']) on POST endpoints

  ④ Page logic: queries DB, mutates state

  ⑤ Renders the shared chrome:
        render_sidebar($active_page)   — role-aware nav
        render_topbar($title)          — bell, help, profile dropdown
        <div class="container"> page content </div>
        render_footer()                — info band + thin copyright

  ⑥ Output flushed → browser
```

The container is wrapped in a flex column body so the **footer info band stretches to fill** the empty viewport space on short pages; on long pages it sits at its natural 240px height.

---

## 2. Roles and what each one owns

```
┌──────────┐  registers, books a room, pays fees,
│ Student  │  views attendance/routine, submits complaints/leaves,
└──────────┘  requests room transfer, gets notifications

┌──────────┐  approves bookings + transfers + leaves,
│ Warden   │  marks daily attendance, sees complaints,
└──────────┘  can directly assign/transfer a student to a room

┌──────────┐  CRUD rooms, verifies payments, manages staff/routine,
│ Owner    │  handles enquiries, leave approvals, attendance reports
└──────────┘

┌──────────┐  resolves help tickets,
│ Admin    │  manages users (search/filter/soft-delete)
└──────────┘
```

Page guards are by **exact role match** via `require_role('student')`. There's no fine-grained permission system — if a page is in `/warden/` the warden owns it.

---

## 3. Auth flow

### Registration (with OTP email)

```
Student                auth/login.php?mode=register          MySQL          Gmail SMTP
  │                              │                            │                 │
  │  Fill form + photo  ────────▶│                            │                 │
  │                              │ validate_strong_password() │                 │
  │                              │   ✓ length≥6, upper, lower, digit            │
  │                              │ INSERT INTO users (…) ────▶│                 │
  │                              │   is_verified=0, otp_code=N, otp_expiry+10m  │
  │                              │ render_branded_email() ─────────────────────▶│
  │                              │                            │     OTP email   │
  │                              │ redirect: verify.php?email=… ──▶              │
  │                              │                            │                 │
  │  Enter 6-digit OTP  ────────▶│ auth/verify.php            │                 │
  │                              │   • check otp_attempts (rate limit)          │
  │                              │   • verify otp_code + expiry                 │
  │                              │   • UPDATE users SET is_verified=1 ─────────▶│
  │                              │                            │                 │
  │                              │ redirect: login.php?msg=Account verified ▶   │
```

**Why OTP and not a magic link:** OTP works on any device, no inbox/clipboard dance. The rate-limit on `otp_attempts` blocks brute-force.

### Login

```
auth/login.php (POST action=login)
  │
  ├─ csrf_verify()
  ├─ SELECT id, name, password, role, is_verified, token_version
  │   FROM users WHERE email = ?
  ├─ if !user           → "We couldn't find an account for that email…"
  ├─ if password wrong  → "Wrong password — try again. (Use Forgot password?…)"
  │                       AND adds .has-error class → CSS shake animation
  ├─ if !is_verified    → bounce to verify.php
  └─ set_auth($u)
        ├─ session_regenerate_id(true)
        ├─ jwt_encode({id, name, role, photo, tv, exp+30d})
        └─ setcookie('auth_token', $token, HttpOnly, 30 days)

  → redirect to {role}/dashboard.php
```

### Password rules (uniform across signup / reset / change)

Enforced by `validate_strong_password($pass)` in `db.php`:

| Rule | Why |
|---|---|
| Length ≥ 6 | Bare minimum length |
| At least one uppercase | Forces non-trivial mixing |
| At least one lowercase | Same |
| At least one digit | Same |

`<input pattern="…">` is also set so the browser blocks weak passwords before the form submits. The server-side check is the source of truth.

### Forgot / reset password

Same OTP shape as registration:

```
auth/forgot_password.php       Generates reset_otp, mails it
              │
              ▼
auth/reset_password.php        User pastes OTP + new password
              │
              │ validate_strong_password()
              │ revoke_user_tokens($user_id)   ← invalidates every existing JWT
              │ UPDATE users SET password = ?
              ▼
        Redirect to login
```

---

## 4. Room booking flow

```
Student                       Warden                          MySQL
  │                              │                               │
  │ Browse rooms ───────────────▶│                               │
  │ (browse_rooms.php)           │                               │
  │ • SELECT WHERE status<>'maintenance' AND occupancy < capacity│
  │ • shows 1/3, 2/3 partial badges                              │
  │                                                              │
  │ Tap "Book Room" ────────────────────────────────────────────▶│
  │ (book_room.php POST)                                         │
  │   • check no existing active booking                         │
  │   • check occupancy < capacity                               │
  │   • INSERT bookings (status='pending_approval')              │
  │   • mail warden + owner                                      │
  │                              │                               │
  │                              │ booking_requests.php          │
  │                              │ Approve (POST)                │
  │                              │   • re-check capacity         │
  │                              │   • UPDATE booking status='active', approved_at │
  │                              │   • UPDATE users.room_id, room_status='approved'│
  │                              │   • IF count >= capacity: rooms.status='occupied'│
  │                              │   • INSERT monthly_fees (current month)         │
  │                              │   • render_branded_email() → student            │
  │                              │   • room_audit_log: booking_approved            │
  │                              │                                                  │
  │ ◀─── notification + email ─────────────────────────────────────────────────────│
```

**Capacity guarantee:** every code path that flips `rooms.status = 'occupied'` first checks the bookings/users count against `rooms.capacity`. A 3-bed triple with 1 booking stays `available`. The student `browse_rooms.php` query also doesn't require `status='available'` — it filters on `status<>'maintenance' AND occupancy < capacity`, so partially-occupied rooms always remain bookable.

---

## 5. Payment flow

Payments use the **real eSewa v2 sandbox** — there's no manual "I have paid" claim and no owner approval step for online payments. The owner only intervenes when accepting cash in person.

### Online (eSewa)

```
Student                       api/payment.php          eSewa sandbox        api/payment_callback.php       Gmail
  │                                │                        │                          │                     │
  │ student/payments.php           │                        │                          │                     │
  │ Tap "Pay with eSewa" ─────────▶│                        │                          │                     │
  │                                │ csrf_verify()          │                          │                     │
  │                                │ Load fee, verify owner │                          │                     │
  │                                │ Cancel any prior 'pending' for same billing_month │                     │
  │                                │ uuid = HMS-{YYMMDDhhmmss}-{8 hex}                  │                     │
  │                                │ INSERT INTO payments    │                          │                     │
  │                                │   (status='pending', gateway='esewa',              │                     │
  │                                │    transaction_uuid, amount, billing_month)       │                     │
  │                                │ esewa_sign(             │                          │                     │
  │                                │   "total_amount=N,transaction_uuid=…,product_code=EPAYTEST" )           │
  │                                │ Render auto-submit POST form ─────────────────────▶│                     │
  │                                │   /api/epay/main/v2/form                           │                     │
  │                                │                        │ Show eSewa UI            │                     │
  │                                │                        │ Student pays in sandbox  │                     │
  │                                │                        │                          │                     │
  │                                │                        │ Redirect back, append    │                     │
  │                                │                        │ ?data=BASE64_JSON ──────▶│                     │
  │                                │                        │                          │                     │
  │                                │                        │ (or ?result=failure&pid= → mark cancelled)     │
  │                                │                        │                          │                     │
  │                                │                        │             base64_decode + json_decode        │
  │                                │                        │             reconstruct sign string from       │
  │                                │                        │             'signed_field_names'               │
  │                                │                        │             hash_equals(esewa_sign(s), sig)    │
  │                                │                        │             (extra) HTTP GET status API check  │
  │                                │                        │                          │                     │
  │                                │                        │             generate_receipt_code(billing)     │
  │                                │                        │              → HMS-RCPT-YYYYMM-XXXXXX          │
  │                                │                        │                          │                     │
  │                                │                        │             BEGIN TX                            │
  │                                │                        │               UPDATE payments status='success',│
  │                                │                        │                 gateway_ref, receipt_code,     │
  │                                │                        │                 paid_at                        │
  │                                │                        │               UPDATE monthly_fees status='paid'│
  │                                │                        │                 + paid_payment_id              │
  │                                │                        │               UPDATE users.fee_status='paid'   │
  │                                │                        │             COMMIT                              │
  │                                │                        │             render_receipt_email() ────────────▶│
  │                                │                        │                          │ Branded receipt     │
  │                                │                        │             Redirect: /student/payments.php    │
  │ ◀───────────────────────────────────────────────────── ?success=1&receipt=HMS-RCPT-…                     │
```

Key safety details in the success path:

- **Duplicate callback protection** — if eSewa hits the callback twice for the same UUID, the second one finds no pending row and redirects to the already-issued receipt (idempotent).
- **Signature is the trust anchor** — owner approval is unnecessary because the HMAC-SHA256 signature proves the response came from eSewa.
- **Status API is best-effort** — if eSewa's status endpoint is unreachable, the signature-verified callback is still accepted; if it returns anything other than `COMPLETE`, the payment is marked failed.

### Cash / out-of-band (Mark paid manually)

```
Student                Owner                          MySQL                  Gmail
  │                      │                              │                       │
  │ Hands owner cash ───▶│ owner/payments.php           │                       │
  │                      │ Tap "Mark paid manually"     │                       │
  │                      │ for a fee row                │                       │
  │                      │   INSERT INTO payments       │                       │
  │                      │     (status='success',       │                       │
  │                      │      gateway='manual', amount, billing_month,        │
  │                      │      receipt_code, paid_at)                          │
  │                      │   UPDATE monthly_fees status='paid' + paid_payment_id│
  │                      │   UPDATE users.fee_status='paid'                     │
  │                      │   render_receipt_email() ─────────────────────────────▶│
  │ ◀── receipt email ──────────────────────────────────────────────────────────│
```

`gateway='manual'` rows are flagged in the owner's payments table with a "Manual" badge so they can be told apart from gateway payments later.

### Wardens are blocked

`api/payment.php` returns **HTTP 403** for any caller whose JWT role is `warden`. Payments are an owner + student concern only.

---

## 6. Room transfer flow

Two entry points — student-requested and warden-initiated. Both end up with the same database side-effects and the same student notification.

### Student-requested transfer

```
Student                       Warden                                          MySQL
  │                              │                                               │
  │ request_transfer.php ───────▶                                                 │
  │ INSERT room_transfers (status='pending', from_room_id, to_room_id, reason)    │
  │                              │                                               │
  │                              │ warden/room_transfers.php Approve             │
  │                              │   BEGIN TX                                    │
  │                              │     Free old room (status='available' if empty,│
  │                              │       or rollback 'occupied'→'available' if   │
  │                              │       there are still students but room now   │
  │                              │       under capacity)                          │
  │                              │     UPDATE rooms.student_id = new student     │
  │                              │     IF count >= new room.capacity:            │
  │                              │       UPDATE rooms.status = 'occupied'        │
  │                              │     UPDATE users.room_id = to_room_id         │
  │                              │     Cancel old bookings, INSERT new approved  │
  │                              │     UPDATE room_transfers status='approved', decided_at│
  │                              │     room_audit_log: room_transfer_approved    │
  │                              │     // Price-difference reconciliation:       │
  │                              │     diff = new_price - old_price              │
  │                              │     IF diff <> 0:                             │
  │                              │       INSERT transfer_adjustments (direction= │
  │                              │         'charge' if diff>0 else 'credit',     │
  │                              │         amount=|diff|, status='pending', …)   │
  │                              │   COMMIT                                      │
  │                              │   send_app_mail(student, "Transfer approved") │
  │                              │                                               │
  │ ◀── notification + email ─────────────────────────────────────────────────── │
```

The student's notification dropdown picks this up automatically:

- *"You moved to Room 204 — Warden approved your transfer 13 May"* (green)
- *"Transfer due: NPR 1,500 — Top-up after your recent room change"* (red, link → payments.php)

### Warden-initiated direct move

`warden/list_student.php` lets the warden assign or transfer a student directly. This path skips the `room_transfers` table but writes to `room_audit_log` with `event='warden_transfer'`. The notification helper queries both tables so the student gets the same alert either way.

---

## 7. Attendance flow

```
Warden                              MySQL
  │                                  │
  │ student_attendance.php            │
  │ (auto-saves per row on toggle)    │
  │   POST mark/{id}/present|absent ─▶│
  │   INSERT/UPDATE attendance (date, student_id, status)
  │                                  │
  │ When marking ABSENT:              │
  │   render_branded_email(           │
  │     'We noticed you weren't in attendance today…' )
  │   send_app_mail() ──── Gmail ─────────▶ student
  │                                  │
  │ owner/report_attendance.php       │
  │   Date range + search filter      │
  │   per-student %, donut summary    │
```

---

## 8. Notification system

The bell-dropdown contents are built by **`get_notifications($user_id, $role)`** in `db.php`. The query set per role is:

| Role | What appears |
|---|---|
| **Student** | Leaves approved/rejected · Outstanding monthly fee · Resolved help ticket · **Room transfer approved/declined** · **Direct warden transfer** · **Pending transfer top-up due** |
| **Warden** | Pending bookings · Pending transfers · Pending leaves · Resolved help tickets |
| **Owner** | Unread enquiries · Overdue fee count · Resolved help tickets |
| **Admin** | (built from `help_tickets` filters) |

The student's three transfer-related notifications fire from these sources:

```
Approved/declined student request → room_transfers.status + decided_at (7-day window)
Warden direct transfer            → room_audit_log.event='warden_transfer' (7-day window)
Pending dues from transfer        → transfer_adjustments.direction='charge' AND status='pending'
```

Every query is wrapped in `try/catch` so a missing table doesn't crash the bell.

---

## 9. Per-file reference

### Root

| File | Role | What it does |
|---|---|---|
| `index.php` | All | `header('Location: home/index.php')` — sends visitors to the landing page |
| `db.php` | All | Core: PDO + mysqli, JWT auth, CSRF, password rules, sidebar/topbar/footer renderers, notifications, idempotent migrations, `auto_create_monthly_fees()`, `mark_overdue_fees()`, eSewa signing |
| `config.php` | All | Secrets — DB creds, JWT secret, SMTP, payment gateway keys. **Gitignored.** |
| `config.example.php` | All | Template. Copy to `config.php`. |
| `database_schema.sql` | DB | Initial schema + seed accounts (`owner@hms.com`, `warden@hms.com`, students) |

### `home/`

| File | What it does |
|---|---|
| `home/index.php` | Public marketing page — hero, facilities grid, rooms preview, security, FAQ, contact form (POSTs to `api/submit_enquiry.php`) |

### `auth/`

| File | What it does |
|---|---|
| `login.php` | Login + register UI, POST handler for both actions, signs JWT, redirects by role |
| `logout.php` | Clears the JWT cookie + session |
| `verify.php` | OTP entry page after registration |
| `resend_otp.php` | Throttled resend (60-second cooldown, max 3 per hour) |
| `forgot_password.php` | Email input, generates `reset_otp`, mails it |
| `reset_password.php` | OTP + new password, runs `validate_strong_password()`, calls `revoke_user_tokens()` |
| `change_password.php` | Logged-in self-service password change (requires current password) |
| `profile.php` | View + edit own profile (photo, cover, bio). Admin/warden/owner can also view someone else's. Calls `refresh_auth_cookie()` after a photo change so the topbar updates. |
| `mailer.php` | `send_app_mail()` and `render_branded_email()` — PHPMailer over Gmail SMTP, logs every send to `logs/smtp.log` |

### `student/`

| File | What it does |
|---|---|
| `dashboard.php` | Room status, attendance %, complaints, leaves, recent activity |
| `browse_rooms.php` | Confirmed-room view if booked, else filterable room grid with live capacity |
| `book_room.php` | POST handler — creates `pending_approval` booking, mails warden + owner |
| `room.php` | Detailed view of the student's current room |
| `my_bookings.php` | List of past + active bookings |
| `request_transfer.php` | Submit transfer request → `room_transfers` table |
| `my_attendance.php` | Personal % + monthly breakdown |
| `my_routine.php`, `food_routine.php` | Daily schedule + meal schedule |
| `request_leave.php` | Submit leave + view past requests |
| `payments.php` | Lists the current month's fee + history. The "Pay with eSewa" button POSTs `action=esewa_init` to `api/payment.php`, which redirects into the eSewa sandbox. Past receipts + transfer adjustments are listed below. |
| `my_complaints.php` | Submit complaint + view status |

### `warden/`

| File | What it does |
|---|---|
| `dashboard.php` | Pending stats, today's attendance, low-attendance list, recent complaints, students on leave, month calendar |
| `list_student.php` | Search + paginate students, assign/unassign/direct-transfer rooms |
| `booking_requests.php` | Approve/reject bookings with capacity check + branded email |
| `room_transfers.php` | Review student transfer requests; on approve, runs price-diff reconciliation |
| `student_attendance.php` | Daily P/A marking with auto-save + absent emails |
| `manage_leaves.php` | Warden's first-stage leave approval |
| `complaints.php` | Resolve complaints |
| `manage_rooms.php` | Limited room view (lock to maintenance) |

### `owner/`

| File | What it does |
|---|---|
| `dashboard.php` | Top KPIs, 14-day registration sparkline, pending approvals, recent students, warden list |
| `manage_rooms.php` | Add Room panel with chip type selector + photo dropzone; CRUD; idempotent occupancy cleanup at the top of every load |
| `manage_staff.php` | Add/relocate non-academic staff (cleaners, guards, cooks) |
| `manage_routine.php` | CRUD daily/meal routine entries |
| `manage_leaves.php` | Owner's second-stage leave approval |
| `payments.php` | Owner's payments dashboard. Filter by month, search by student, **export to CSV**, and "Mark paid manually" for cash-in-person payments (records `gateway='manual'`, generates receipt code, mails it). eSewa payments need no owner action — they're finalised by `api/payment_callback.php`. |
| `enquiries.php` | Reply to landing-page enquiries (auto-emails parent) |
| `report_attendance.php` | Date-range + per-student attendance report |

### `admin/`

| File | What it does |
|---|---|
| `dashboard.php` | Tickets-open count, users-by-role chart, quick links |
| `resolve_tickets.php` | Filterable ticket queue, mark in-progress / resolve with note + email |
| `manage_users.php` | Search/filter/role-change/soft-delete users |

### `api/`

| File | What it does |
|---|---|
| `payment.php` | `action=esewa_init` — verifies CSRF + fee ownership, cancels stale pending attempts, mints a UUID `HMS-{YYMMDDhhmmss}-{8 hex}`, inserts `payments` row, signs the eSewa string with `esewa_sign()`, renders an auto-submit POST form to `rc-epay.esewa.com.np/api/epay/main/v2/form`. Returns HTTP 403 if the caller is a warden. |
| `payment_callback.php` | eSewa's return URL. Detects success by the presence of `?data=…`. Base64-decodes the JSON, reconstructs the signed string from `signed_field_names`, verifies the HMAC with `hash_equals()`, optionally double-checks via the eSewa status API. Generates a receipt code, marks `payments` `success`, flips `monthly_fees` to `paid`, syncs `users.fee_status`, mails the branded receipt. Handles duplicate callbacks idempotently. |
| `submit_enquiry.php` | Landing-page contact form handler |
| `chatbot_intent.php` | Rule-based FAQ intent classifier for the chatbot widget |

### `chatbot/`

| File | What it does |
|---|---|
| `widget.php` | Drop-in chatbot UI (included on landing + student pages) |

### `css/` & `js/`

| File | What it does |
|---|---|
| `css/style.css` | The app's stylesheet — design tokens, all dashboard components, footer info band, smoothness layer |
| `css/landing.css` | Public marketing page only |
| `js/script.js` | `hmsConfirm`, `hmsToast`, auto-toast for `.alert`, theme toggle, sidebar collapse, form busy-state |

---

## 10. Database schema

Top-level tables and what they store:

| Table | Owns |
|---|---|
| `users` | id, name, email, password (bcrypt), role, photo, cover_photo, description, room_id, room_status, fee_status, student_phone, otp_*, reset_otp_*, token_version, is_verified, created_at |
| `rooms` | id, room_number, room_type, floor, capacity, price, notes, photo, status (`available`/`occupied`/`maintenance`), student_id (legacy single-occupancy pointer), deleted_at |
| `bookings` | id, student_id, room_id, status (`pending_approval`/`approved`/`active`/`rejected`/`cancelled`), start_date, approved_by, approved_at, notes |
| `monthly_fees` | id, student_id, room_id, billing_month, amount, status (`unpaid`/`paid`/`overdue`), due_date, paid_payment_id (FK → `payments.id` after successful payment) |
| `payments` | id, student_id, room_id, amount, billing_month, status (`pending`/`success`/`failed`/`cancelled`), gateway (`esewa`/`manual`), transaction_uuid (`HMS-…`), gateway_ref (eSewa's `transaction_code`), receipt_code (`HMS-RCPT-YYYYMM-XXXXXX`), initiated_at, paid_at |
| `attendance` | id, student_id, date, status (`present`/`absent`) |
| `complaints` | id, student_id, subject, message, status (`open`/`resolved`), created_at |
| `leaves` | id, student_id, start_date, end_date, reason, warden_status, owner_status |
| `room_transfers` | id, student_id, from_room_id, to_room_id, status, reason, decided_by, decided_at |
| `transfer_adjustments` | id, student_id, transfer_id, from_room_id, to_room_id, old_price, new_price, amount, direction (`charge`/`credit`), status (`pending`/`paid`), notes |
| `room_audit_log` | id, room_id, student_id, event (e.g. `booking_approved`, `warden_transfer`, `room_transfer_approved`), from_room_id, to_room_id, actor_id, notes, created_at |
| `enquiries` | id, name, email, message, source, status |
| `help_tickets` | id, user_id, role, subject, description, status, admin_note, resolved_by, resolved_at |
| `staff` | id, name, role, allocation |

---

## 11. Key functions in db.php

Curated list — open `db.php` for the rest.

```php
app_config($key, $default = null)
    // env-var first, then config.php

csrf_token()       // string  — single-use per session
csrf_verify($t)    // void    — throws on mismatch

jwt_encode($payload)        // base64url.signature
jwt_decode($token)          // array|false

set_auth($user)             // login — signs JWT, sets cookie, populates session
get_auth()                  // array|false — reads cookie, validates tv
refresh_auth_cookie($uid)   // re-issue JWT with fresh DB data (no logout)
revoke_user_tokens($uid)    // bump token_version → kills every session

require_role('warden')      // page guard — bounces to login otherwise
require_role_any(['warden','owner'])

validate_strong_password($pass)  // null on OK, error string otherwise
password_rules_hint()            // human-readable rules text

render_sidebar($active_page)     // role-aware nav HTML
render_topbar($title)            // top chrome HTML
render_footer()                  // info band + thin copyright

get_notifications($uid, $role)   // bell-dropdown items

get_svg_icon($name)              // inline SVG by name
e($s)                            // htmlspecialchars shorthand

// Owner-side cron-style helpers (run on page loads)
auto_create_monthly_fees()       // monthly_fees rows for every active student
generate_monthly_fees(?$month)   // explicit variant: returns count of new rows
mark_overdue_fees()              // unpaid fees past due_date → overdue

// Payment helpers
esewa_sign($data)                // HMAC-SHA256 of ESEWA_SECRET, base64-encoded
generate_receipt_code($month)    // unique HMS-RCPT-YYYYMM-XXXXXX, retried up to 10×
```

And in `auth/mailer.php`:

```php
send_app_mail($to, $name, $subject, $html)   // PHPMailer SMTP, logs to logs/smtp.log
render_branded_email($vars)                  // generic branded HTML wrapper
render_receipt_email($vars)                  // dedicated receipt template
                                              // (used after every successful payment)
```

---

That's the whole system. Anything not described here lives in code that does exactly what its name says — `student/my_complaints.php` is complaints for students, `owner/enquiries.php` is enquiry replies for owners. The codebase prefers boring over clever.
