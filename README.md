# HMS — Hostel Management System

Web app for managing a student hostel: rooms, bookings, monthly fees, attendance, complaints, leave requests, and a public landing page with an FAQ chatbot. Built by **Team Legion** for our college Software Engineering project.

> Stack: **PHP 8 / MySQL / vanilla JS** on **XAMPP**. No frameworks, no bundler — open the project in `htdocs`, point a browser at it, and it runs.

---

## Quick start (XAMPP on Windows)

1. **Clone into XAMPP's web root** so the URLs in the app resolve cleanly:
   ```
   C:\xampp\htdocs\team-legion-hostel-management-system
   ```
2. **Start Apache + MySQL** in the XAMPP Control Panel.
3. **Create the database** in phpMyAdmin:
   - Database name: `hostel2`
   - Import `database_schema.sql` to create tables and seed the demo accounts.
4. **Copy the config template and fill it in**:
   ```bash
   copy config.example.php config.php
   ```
   Then edit `config.php` — at minimum set your Gmail SMTP credentials (see [Email setup](#email-setup) below). The DB defaults already match XAMPP's stock `root` / no-password setup.
5. **Visit** `http://localhost/team-legion-hostel-management-system/` — the root redirects to the public landing page.
6. **Optional:** run `populate_data.php` once in the browser to insert 20 rooms and 30 demo students.

`db.php` runs idempotent auto-migrations on every request, so newer columns/tables get added automatically as you `git pull`.

---

## Demo credentials

Seeded by `database_schema.sql`. Password for **every** seeded account is `Test1234`.

| Role    | Email              |
|---------|--------------------|
| Owner   | `owner@hms.com`    |
| Warden  | `warden@hms.com`   |
| Student | `ali@student.com`  |
| Student | `sara@student.com` |
| Student | `tom@student.com`  |

New self-registered students get a real OTP email — see [Email setup](#email-setup).

---

## Folder layout

Code is grouped by **who uses it**, not by what it is. Each role has one folder; auth/OTP and the public site live in their own folders so nothing leaks across boundaries.

```
team-legion-hostel-management-system/
├── index.php              redirects to home/index.php
├── db.php                 PDO + mysqli connection, auth helpers, sidebar, auto-migrations
├── config.php             secrets (DB creds, SMTP, payment gateway keys) — gitignored
├── config.example.php     template to copy
│
├── home/                  public landing page (no login required)
├── auth/                  registration, login, logout, OTP verify, resend OTP,
│                          forgot/reset password, mailer.php (branded email helpers)
│
├── student/               every page a logged-in student sees
├── warden/                every page a logged-in warden sees
├── owner/                 every page a logged-in owner sees
│
├── api/                   JSON endpoints — payment.php, payment_callback.php, submit_enquiry.php
├── chatbot/               rule-based FAQ widget + intent API (mounted on landing + student pages)
│
├── assets/img/            static images, including esewa_qr.png and khalti_qr.png
├── css/, js/              shared styles + scripts
├── uploads/students/      profile photos uploaded at registration
├── logs/                  smtp.log + payment_callback.log (debugging)
├── vendor/                Composer deps (PHPMailer)
│
├── database_schema.sql    initial schema + demo accounts/rooms
└── populate_data.php      adds 20 rooms + 30 students for a fuller demo
```

---

## What each role can do

### Student
- Register with email + photo → receive 6-digit OTP → verify → log in
- Browse available rooms (filter by type / floor / max price); see live `1/2 beds taken` badges
- Book a room → goes to warden as a pending request
- Pay monthly fees by scanning the owner's eSewa or Khalti QR, then tap "I have paid" to submit a claim
- Download branded payment receipt (with a unique `HMS-RCPT-…` code) by email after the owner approves
- View own attendance, daily routine, meal schedule
- Submit complaints, request leave

### Warden
- Approve / reject booking requests (transactionally, with capacity check so a 2-bed room only seats 2)
- See full student list, mark daily attendance (P/A toggle, auto-saves, with live name search)
- Absent students automatically get a polite branded email asking why
- View attendance report (donut chart + per-student %)
- Review room transfer requests, leave requests, complaints

### Owner
- CRUD rooms (locked while occupied; can be marked maintenance)
- Verify pending payment claims → approving emails the student a branded receipt and flips fee status to paid
- Track per-student fee history with last paid date / amount / gateway
- Manage staff, daily routine, leave requests, enquiries (replies email the parent who submitted)
- Full attendance report with date-range, search by name/ID, print/PDF

---

## Payments

The student "Pay Fees" page shows a modal with the owner's wallet QR. The student scans, pays manually through eSewa or Khalti, then taps **I have paid** which creates a `pending` row in `payment_transactions`. The owner approves in **Manage Payments**, which:

1. Generates a unique receipt code (`HMS-RCPT-YYYYMM-XXXXXX`)
2. Writes `status='success'` + `users.fee_status='paid'` in one transaction
3. Emails the student a branded HTML receipt
4. Notifies all owners

QR images live at:
- `assets/img/esewa_qr.png`
- `assets/img/khalti_qr.png`

Replace these with your own wallet QR screenshots before deploying.

> Real-gateway integration (server-to-server lookup with `pidx` for Khalti, signed form POST for eSewa) is **also wired up** in `api/payment.php` + `api/payment_callback.php` and gated behind `mode=khalti_init` / `mode=esewa_init`. Switch the student page over to those modes once you have production merchant keys.

---

## Email setup

All emails go through **PHPMailer over Gmail SMTP**. To send from your own Gmail account:

1. Enable 2-Step Verification on the Google account.
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

Every send is logged to `logs/smtp.log` (timestamps + success/failure + Gmail's response). If OTPs aren't arriving, that file has the answer.

> **XAMPP on Windows note:** `mailer.php` disables peer verification (`verify_peer=false`) because XAMPP ships without a CA bundle. That's safe for local dev but should be tightened in production.

All transactional emails (OTP, booking approved/rejected, payment receipt, absent notification, etc.) use the shared `render_branded_email()` helper in `auth/mailer.php` so they all look like they came from the same product.

---

## Configuration reference

| Key                  | What it's for                                                            |
|----------------------|--------------------------------------------------------------------------|
| `DB_HOST/USER/PASS/NAME` | MySQL connection                                                     |
| `JWT_SECRET`         | Signs the auth cookie — change to a long random string for production    |
| `SMTP_*`             | Gmail SMTP credentials (see above)                                       |
| `PAYMENT_BASE_URL`   | Base URL the gateway uses to redirect back (e.g. `http://localhost/...`) |
| `KHALTI_BASE`        | `https://dev.khalti.com` for sandbox, `https://khalti.com` for live      |
| `KHALTI_SECRET_KEY`  | Get yours at https://test-admin.khalti.com (sandbox)                     |
| `ESEWA_BASE`         | `https://rc-epay.esewa.com.np` for sandbox                               |
| `ESEWA_STATUS_BASE`  | `https://rc.esewa.com.np` for sandbox                                    |
| `ESEWA_MERCHANT`     | `EPAYTEST` for sandbox                                                   |
| `ESEWA_SECRET`       | `8gBm/:&EnhH.1/q` for sandbox                                            |

Never commit `config.php` — it's in `.gitignore`.

---

## Sprint 2 — what shipped, what's queued

**Shipped**
- OTP-based registration with rate-limited verification + resend cooldown
- Room CRUD with capacity-aware booking flow
- Booking approval lifecycle (`pending_approval → active`) with auto payment-contract creation
- QR-scan payment flow with owner verification + branded receipt
- Attendance: warden marks per day, absent emails fire automatically
- Attendance report: donut summary + searchable per-student table with circular % rings
- Branded HTML email template used by every transactional email
- FAQ chatbot mounted on landing + student pages

**Queued for next sprint**
- Booking cancellation + tiered refund engine (90% / 50% / 0%)
- Monthly invoice PDF generation
- Revenue dashboard with charts + CSV export
- Pagination on attendance / leave list pages

---

## Team Legion

Built as a college SE project. The code prefers **simple over clever** — flat PHP files, prepared statements, no ORM, no router. If you can read the file path, you can find the feature.

Issues / questions: open a GitHub issue on this repo.
