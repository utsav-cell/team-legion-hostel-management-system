# Hostel Management System (HMS)

PHP + MySQL hostel management platform with role-based dashboards for students, wardens, and owners. Includes public landing + enquiry form, secure auth, attendance, complaints, room allocation, routine management, and fee tracking.

---

## Tech Stack

- PHP (procedural with reusable helpers)
- MySQL / MariaDB (PDO + MySQLi)
- Vanilla JS and CSS
- JWT cookie + sessions + CSRF

---

## Project Structure (Current)

```
team-legion-hostel-management-system/
├── index.php                   → Redirects to landing page
├── home/index.php              → Public landing + enquiry form
├── login.php                   → Login + student registration
├── forget_password.php         → Password reset request (disabled message)
├── logout.php                  → Logout + token revoke
├── db.php                      → DB config + auth + CSRF + helpers + auto-migrations
├── config.example.php          → Sample local config
├── database_schema.sql         → Database schema + seed data
├── migrate.php                 → One-time migration helper (self-deletes)
├── populate_data.php           → Optional extra seed data
│
├── api/
│   └── submit_enquiry.php      → Public enquiry endpoint (AJAX)
│
├── css/
│   ├── landing.css             → Landing page styles
│   └── style.css               → App UI styles
├── js/
│   └── script.js               → UI behaviors (sidebar, counters, search)
├── assets/
│   └── img/                    → Landing page images
│
├── student/
│   ├── dashboard.php           → Student overview
│   ├── room.php                → Room details + approval banner
│   ├── my_attendance.php       → Attendance history
│   ├── my_routine.php          → Daily routine (read-only)
│   ├── food_routine.php        → Food menu
│   ├── my_complaints.php       → Submit + view complaints
│   └── request_leave.php       → Leave request + history
│
├── warden/
│   ├── dashboard.php           → Warden overview
│   ├── room_requests.php       → Room allocation approvals
│   ├── student_attendance.php  → Mark attendance (bulk + AJAX)
│   ├── complaints.php          → Review + reply to complaints
│   ├── manage_leaves.php       → First-level leave approval
│   └── list_student.php        → Student directory
│
└── owner/
    ├── dashboard.php           → Owner overview + final room approvals
    ├── enquiries.php           → Public enquiries inbox
    ├── manage_routine.php      → Edit daily routine
    ├── manage_staff.php        → Staff management
    ├── manage_leaves.php       → Final leave approval
    ├── report_attendance.php   → Attendance reports
    └── student_fees.php        → Fee tracking
```

---

## Setup

### 1. Database
```bash
mysql -u root -p < database_schema.sql
```
This creates the `hostel2` database with tables and demo data.

### 2. Configure DB credentials
- Copy [config.example.php](config.example.php) to [config.php](config.php)
- Update credentials and JWT secret

### 3. Run (XAMPP / WAMP / Laragon)
- Place the project in your web root (example: `htdocs/`)
- Start Apache + MySQL
- Open `http://localhost/team-legion-hostel-management-system/`

### 4. Optional helpers
- [migrate.php](migrate.php) adds missing columns and then deletes itself
- [populate_data.php](populate_data.php) adds extra rooms and student accounts

---

## Demo Credentials (password: `Test1234`)

| Role    | Email                | Phone        |
|---------|----------------------|--------------|
| Warden  | warden@hms.com        | —            |
| Owner   | owner@hms.com         | —            |
| Student | ali@student.com       | 0111111111   |
| Student | sara@student.com      | 0122222222   |
| Student | tom@student.com       | 0133333333   |

---

## Program Flow

### 1. Public Landing + Enquiry
1. User hits [index.php](index.php) → redirected to [home/index.php](home/index.php)
2. Enquiry form sends POST to [api/submit_enquiry.php](api/submit_enquiry.php)
3. Enquiries are stored in `enquiries` and managed by owners in [owner/enquiries.php](owner/enquiries.php)

### 2. Authentication + Session
1. Login and registration handled by [login.php](login.php)
2. Auth state stored in JWT cookie + session via helpers in [db.php](db.php)
3. Role gates enforced with `require_role()` in each dashboard page
4. Logout invalidates tokens in [logout.php](logout.php)

### 3. Role Dashboards
- Student → [student/dashboard.php](student/dashboard.php)
- Warden → [warden/dashboard.php](warden/dashboard.php)
- Owner → [owner/dashboard.php](owner/dashboard.php)

### 4. Core Workflows

**Room allocation**
1. Student accounts start with `room_status = pending`
2. Warden assigns/approves rooms in [warden/room_requests.php](warden/room_requests.php)
3. Owner can approve allocations in [owner/dashboard.php](owner/dashboard.php)
4. Students view status and confirmation banner in [student/room.php](student/room.php)

**Attendance**
1. Warden marks attendance (bulk or AJAX) in [warden/student_attendance.php](warden/student_attendance.php)
2. Students review history in [student/my_attendance.php](student/my_attendance.php)
3. Owners view summaries in [owner/report_attendance.php](owner/report_attendance.php)

**Complaints**
1. Students submit complaints in [student/my_complaints.php](student/my_complaints.php)
2. Warden reviews and replies in [warden/complaints.php](warden/complaints.php)
3. Students see status and replies on their dashboard

**Leave requests**
1. Student submits leave in [student/request_leave.php](student/request_leave.php)
2. Warden provides first approval in [warden/manage_leaves.php](warden/manage_leaves.php)
3. Owner provides final approval in [owner/manage_leaves.php](owner/manage_leaves.php)

**Routine and food menu**
1. Owner edits routine in [owner/manage_routine.php](owner/manage_routine.php)
2. Students view routine in [student/my_routine.php](student/my_routine.php)
3. Food menu shown in [student/food_routine.php](student/food_routine.php)

---

## Security Notes

- CSRF protection in forms via `csrf_token()` and `csrf_verify()`
- JWT cookie includes `token_version` to invalidate old sessions
- Output escaping via `e()` helper

---

## Troubleshooting

- If you see a database error screen, update credentials in [config.php](config.php) and import [database_schema.sql](database_schema.sql)
- Ensure Apache + MySQL are running in your local stack
