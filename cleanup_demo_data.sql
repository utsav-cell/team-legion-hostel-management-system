-- ─────────────────────────────────────────────────
-- cleanup_demo_data.sql
-- Run this ONCE in phpMyAdmin (or via `mysql -u root hostel2 < cleanup_demo_data.sql`)
-- to wipe demo clutter before a viva demo.
--
-- KEEPS:
--   - owner@hms.com  (password: Test1234)
--   - warden@hms.com (password: Test1234)
--   - admin@hms.com  (password: Test1234)
--   - ali@student.com (the one demo student, password: Test1234)
--   - All rooms (managed by warden, not wiped)
--   - daily_routine + chatbot_intents (UI reference data)
--
-- WIPES:
--   - All other students
--   - All complaints, leaves, enquiries, bookings, attendance, payments
--   - All room transfers + audit logs + transfer adjustments + monthly fees
--   - All help tickets + chatbot conversations
--   - Resets every room to 'available' with no occupant
-- ─────────────────────────────────────────────────

USE hostel2;

SET FOREIGN_KEY_CHECKS = 0;

-- Wipe per-student activity tables
TRUNCATE TABLE complaints;
TRUNCATE TABLE leaves;
TRUNCATE TABLE enquiries;
TRUNCATE TABLE bookings;
TRUNCATE TABLE attendance;
TRUNCATE TABLE payments;
TRUNCATE TABLE monthly_fees;
TRUNCATE TABLE room_transfers;
TRUNCATE TABLE room_audit_log;
TRUNCATE TABLE transfer_adjustments;
TRUNCATE TABLE help_tickets;
TRUNCATE TABLE chatbot_conversations;

-- Delete every student EXCEPT ali@student.com
DELETE FROM users
WHERE role = 'student'
  AND email <> 'ali@student.com';

-- Make sure the surviving student starts clean (no room, no fee status)
UPDATE users
SET room_id = NULL,
    room_status = 'pending',
    fee_status = 'unpaid'
WHERE email = 'ali@student.com';

-- Reset every room: available, no occupant, not soft-deleted
UPDATE rooms
SET status = 'available',
    student_id = NULL,
    deleted_at = NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- Quick sanity check (shown as the final SELECT output)
SELECT role, COUNT(*) AS count FROM users GROUP BY role;
SELECT COUNT(*) AS rooms_available FROM rooms WHERE status = 'available';
