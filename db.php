<?php
// ─────────────────────────────────────────────────
// db.php — Core: DB connections, auth, helpers, sidebar, auto-migrations
//   Every page in the app starts with `require_once 'db.php'`. This file:
//     • Loads config.php and exposes app_config() for typed reads.
//     • Opens a PDO connection ($pdo) used by every page in the app.
//     • Runs idempotent CREATE TABLE / ALTER TABLE migrations on every
//       request so a fresh `git pull` self-heals the schema.
//     • Provides JWT-cookie auth (set_auth / get_auth / revoke_user_tokens),
//       CSRF tokens, role guards (require_role / require_role_any), and
//       the sidebar renderer.
// ─────────────────────────────────────────────────

// Holds the parsed config.php associative array. Overridden by env vars
// inside app_config() so deployments can override secrets without editing files.
$localConfig = [];
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

/**
 * Read a config key. Resolution order: environment variable → config.php → $default.
 * Letting env vars win lets us override secrets in CI/prod without editing files.
 */
function app_config($key, $default = null) {
    global $localConfig;
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return $localConfig[$key] ?? $default;
}

define('DB_HOST', app_config('DB_HOST', '127.0.0.1'));
define('DB_USER', app_config('DB_USER', 'root'));
define('DB_PASS', app_config('DB_PASS', ''));
define('DB_NAME', app_config('DB_NAME', 'hostel2'));
define('JWT_SECRET', app_config('JWT_SECRET', 'hms_secure_key_2024_change_this'));
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $ex) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>HMS Setup Required</title>
        <style>
            body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;min-height:100vh;display:grid;place-items:center;padding:24px}
            .setup-card{max-width:820px;background:rgba(15,23,42,.88);border:1px solid rgba(255,255,255,.08);border-radius:28px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
            h1{margin:0 0 12px;font-size:clamp(2rem,4vw,3rem)}
            p{color:#cbd5e1;line-height:1.7}
            code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#020617;color:#f8fafc;border-radius:16px}
            pre{padding:18px;overflow:auto;border:1px solid rgba(255,255,255,.08)}
            .pill{display:inline-block;padding:8px 14px;border-radius:999px;background:#2563eb;color:#ffffff;font-weight:800;margin-bottom:16px}
            ul{padding-left:20px;color:#cbd5e1}
            a{color:#60a5fa}
        </style>
    </head>
    <body>
        <div class="setup-card">
            <span class="pill">Localhost setup needed</span>
            <h1>Database connection failed</h1>
            <p>HMS could not connect to MySQL using <strong><?= htmlspecialchars(DB_HOST) ?></strong> / database <strong><?= htmlspecialchars(DB_NAME) ?></strong>.</p>
            <ul>
                <li>Start Apache and MySQL in XAMPP or Laragon.</li>
                <li>Create the database by importing <strong>database_schema.sql</strong>.</li>
                <li>If your localhost password or database name is different, copy <strong>config.example.php</strong> to <strong>config.php</strong> and update it.</li>
            </ul>
            <pre>mysql -u root -p &lt; database_schema.sql</pre>
            <p>MySQL error: <?= htmlspecialchars($ex->getMessage()) ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Run a statement that should "succeed if it can, silently skip if it cannot".
 * Used by the auto-migration block so a missing column or pre-existing column
 * never bricks the request — keeps migrations resilient to partial schemas.
 */
function db_try(PDO $pdo, string $sql): bool {
    try { $pdo->exec($sql); return true; }
    catch (PDOException $e) { return false; }
}

/**
 * Probe whether a SELECT runs at all — used to test if a column exists
 * (e.g. `SELECT new_col FROM table LIMIT 1`). Returns false on any error.
 */
function db_probe(PDO $pdo, string $sql): bool {
    try { $pdo->query($sql); return true; }
    catch (PDOException $e) { return false; }
}

// Auto-migration: Ensure new columns and tables exist
if (!db_probe($pdo, "SELECT is_verified FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN otp_code VARCHAR(6) DEFAULT NULL AFTER password");
    db_try($pdo, "ALTER TABLE users ADD COLUMN is_verified TINYINT(1) DEFAULT 0 AFTER otp_code");
    db_try($pdo, "UPDATE users SET is_verified = 1");
}
// Owner, warden, and admin accounts are created manually and never go through OTP flow,
// so ensure they are always marked verified regardless of seed order.
db_try($pdo, "UPDATE users SET is_verified = 1 WHERE role IN ('owner', 'warden', 'admin') AND is_verified = 0");
if (!db_probe($pdo, "SELECT otp_expiry FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN otp_expiry DATETIME DEFAULT NULL AFTER otp_code");
}
if (!db_probe($pdo, "SELECT otp_created_at FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN otp_created_at DATETIME DEFAULT NULL AFTER otp_expiry");
}
if (!db_probe($pdo, "SELECT otp_attempts FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN otp_attempts INT NOT NULL DEFAULT 0 AFTER otp_created_at");
}
if (!db_probe($pdo, "SELECT reset_otp FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN reset_otp VARCHAR(6) DEFAULT NULL AFTER is_verified");
    db_try($pdo, "ALTER TABLE users ADD COLUMN reset_otp_expiry DATETIME DEFAULT NULL AFTER reset_otp");
}
if (!db_probe($pdo, "SELECT token_version FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0 AFTER photo");
}
if (!db_probe($pdo, "SELECT fee_status FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN fee_status ENUM('paid','unpaid') DEFAULT 'unpaid' AFTER room_preference");
}
if (!db_probe($pdo, "SELECT cover_photo FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN cover_photo VARCHAR(255) DEFAULT NULL AFTER photo");
}
if (!db_probe($pdo, "SELECT description FROM users LIMIT 1")) {
    db_try($pdo, "ALTER TABLE users ADD COLUMN description TEXT DEFAULT NULL AFTER cover_photo");
}

db_try($pdo, "CREATE TABLE IF NOT EXISTS `enquiries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `source` VARCHAR(50) DEFAULT 'Website Form',
  `status` ENUM('unread', 'read', 'replied') DEFAULT 'unread',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
if (!db_probe($pdo, "SELECT reply FROM enquiries LIMIT 1")) {
    db_try($pdo, "ALTER TABLE enquiries ADD COLUMN reply TEXT DEFAULT NULL AFTER status");
    db_try($pdo, "ALTER TABLE enquiries ADD COLUMN replied_at DATETIME DEFAULT NULL AFTER reply");
}

db_try($pdo, "CREATE TABLE IF NOT EXISTS `daily_routine` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `time_slot` VARCHAR(50) NOT NULL,
  `activity` VARCHAR(255) NOT NULL,
  `is_school_hours` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (!db_probe($pdo, "SELECT warden_status FROM leaves LIMIT 1")) {
    db_try($pdo, "ALTER TABLE leaves ADD COLUMN warden_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER reason");
    db_try($pdo, "ALTER TABLE leaves ADD COLUMN owner_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER warden_status");
    db_try($pdo, "ALTER TABLE leaves DROP COLUMN status");
}

db_try($pdo, "CREATE TABLE IF NOT EXISTS `staff` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `role` VARCHAR(50) NOT NULL,
  `allocation` ENUM('Room','Canteen','Toilets','Garden','General') DEFAULT 'General',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (!db_probe($pdo, "SELECT allocation FROM staff LIMIT 1")) {
    db_try($pdo, "ALTER TABLE staff ADD COLUMN allocation ENUM('Room','Canteen','Toilets','Garden','General') DEFAULT 'General' AFTER role");
}
if (db_probe($pdo, "SELECT assigned_area FROM staff LIMIT 1")) {
    db_try($pdo, "UPDATE staff SET allocation = assigned_area WHERE allocation IS NULL OR allocation = ''");
}

db_try($pdo, "CREATE TABLE IF NOT EXISTS `leaves` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `reason` TEXT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `warden_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `owner_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `final_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ─── Admin role + help tickets (Phase 2) ─────────
// Add 'admin' to role ENUM if not present
try {
    $_enumType = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
} catch (PDOException $e) { $_enumType = false; }
if (!$_enumType || strpos($_enumType, "'admin'") === false) {
    db_try($pdo, "ALTER TABLE users MODIFY COLUMN role ENUM('student','warden','owner','admin') NOT NULL DEFAULT 'student'");
}
db_try($pdo, "CREATE TABLE IF NOT EXISTS `help_tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `role` VARCHAR(20) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('open','in_progress','resolved') DEFAULT 'open',
  `assigned_to` INT DEFAULT NULL,
  `resolved_by` INT DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `admin_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ─── Sprint-2 schema ──────────────────────────────
// Add 'maintenance' to rooms.status (TL-125) — MySQL ignores if column already has the value.
db_try($pdo, "ALTER TABLE rooms MODIFY COLUMN status ENUM('available','occupied','maintenance') DEFAULT 'available'");
// Soft-delete + price + capacity for room CRUD (referenced by upcoming features)
if (!db_probe($pdo, "SELECT deleted_at FROM rooms LIMIT 1")) {
    db_try($pdo, "ALTER TABLE rooms ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}
if (!db_probe($pdo, "SELECT capacity FROM rooms LIMIT 1")) {
    db_try($pdo, "ALTER TABLE rooms ADD COLUMN capacity INT NOT NULL DEFAULT 1");
    db_try($pdo, "UPDATE rooms SET capacity = CASE WHEN room_type='Double' THEN 2 WHEN room_type='Triple' THEN 3 ELSE 1 END");
}
if (!db_probe($pdo, "SELECT price FROM rooms LIMIT 1")) {
    db_try($pdo, "ALTER TABLE rooms ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 5000.00");
    db_try($pdo, "UPDATE rooms SET price = CASE WHEN room_type='Single' THEN 5000 WHEN room_type='Double' THEN 4000 WHEN room_type='Triple' THEN 3000 ELSE 5000 END");
}
if (!db_probe($pdo, "SELECT notes FROM rooms LIMIT 1")) {
    db_try($pdo, "ALTER TABLE rooms ADD COLUMN notes TEXT DEFAULT NULL AFTER price");
}
// Unique room_number — guard with a check since the auto-seed may have inserted dupes earlier.
try {
    $_uniq_exists = (bool)$pdo->query("SHOW INDEX FROM rooms WHERE Key_name = 'uniq_room_number'")->fetch();
} catch (PDOException $e) { $_uniq_exists = true; }
if (!$_uniq_exists) {
    db_try($pdo, "ALTER TABLE rooms ADD UNIQUE KEY uniq_room_number (room_number)");
}

// Bookings — replaces simple users.room_id model going forward (legacy column kept).
db_try($pdo, "CREATE TABLE IF NOT EXISTS `bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `room_id` INT(11) NOT NULL,
  `start_date` DATE NOT NULL,
  `status` ENUM('pending_approval','approved','active','cancellation_requested','cancelled','rejected') DEFAULT 'pending_approval',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room_status` (`room_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Room audit log (TL-124).
db_try($pdo, "CREATE TABLE IF NOT EXISTS `room_audit_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `room_id` INT(11) DEFAULT NULL,
  `student_id` INT(11) DEFAULT NULL,
  `event` VARCHAR(64) NOT NULL,
  `from_room_id` INT(11) DEFAULT NULL,
  `to_room_id` INT(11) DEFAULT NULL,
  `actor_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room` (`room_id`, `created_at`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Room transfer requests (TL-111).
db_try($pdo, "CREATE TABLE IF NOT EXISTS `room_transfers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `from_room_id` INT(11) DEFAULT NULL,
  `to_room_id` INT(11) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `decided_by` INT(11) DEFAULT NULL,
  `decided_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_status` (`student_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Help tickets for admin support queue.
db_try($pdo, "CREATE TABLE IF NOT EXISTS `help_tickets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'student',
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('open','in_progress','resolved') DEFAULT 'open',
  `admin_note` TEXT DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Chatbot tables (TL-190–195).
db_try($pdo, "CREATE TABLE IF NOT EXISTS `chatbot_intents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `keywords` VARCHAR(500) NOT NULL,
  `response` TEXT NOT NULL,
  `priority` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
if (!db_probe($pdo, "SELECT action FROM chatbot_intents LIMIT 1")) {
    db_try($pdo, "ALTER TABLE chatbot_intents ADD COLUMN action VARCHAR(40) DEFAULT NULL AFTER response");
}

db_try($pdo, "CREATE TABLE IF NOT EXISTS `chatbot_conversations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) DEFAULT NULL,
  `session_id` VARCHAR(64) NOT NULL,
  `user_message` TEXT NOT NULL,
  `bot_response` TEXT NOT NULL,
  `matched_intent_id` INT(11) DEFAULT NULL,
  `rating` TINYINT(1) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Seed chatbot intents — landing page focused (prospective students/parents).
// Bumping the seed marker forces a re-seed when intents change.
$cb_seed_marker = 'v6_prices_5000_4000_3000_2026';
try {
    $cb_current = $pdo->query("SELECT response FROM chatbot_intents WHERE keywords = '__seed_marker__' LIMIT 1")->fetchColumn();
} catch (PDOException $e) { $cb_current = false; }
if ($cb_current === false) { $cb_current = null; }
if ($cb_current !== $cb_seed_marker) {
    db_try($pdo, "DELETE FROM chatbot_intents");
    db_try($pdo, "INSERT INTO chatbot_intents (keywords, response, action, priority) VALUES
        ('__seed_marker__', '$cb_seed_marker', NULL, 0),
        ('hi,hello,hey,namaste,start', 'Namaste! I can help you learn about HMS Hostel — fees, rooms, meals, location, security and how to apply. Tap a topic below or type your question.', NULL, 100),
        ('fee,fees,rent,price,cost,monthly,charge,how much', 'Monthly fees depend on room type — Single NPR 5,000, Double NPR 4,000, Triple NPR 3,000 per month. All include three meals a day, fiber WiFi, laundry twice a week, electricity and water. No hidden charges. A one-time refundable deposit of NPR 5,000 is collected at check-in. Contact the front desk for payment arrangements.', NULL, 95),
        ('room,rooms,accommodation,bed,sharing,single,double,triple', 'We offer Single, Double and Triple sharing rooms. All are furnished with a study desk, wardrobe, ergonomic chair, reading lamp and an attached or shared bathroom depending on tier. Triple sharing is the most popular with students.', NULL, 90),
        ('wifi,internet,fiber,speed,study,desk', 'Fiber internet across the building (100 Mbps shared, no cap). Quiet study zones on every floor open 24/7 with desk lamps and power outlets at every seat.', NULL, 90),
        ('food,meal,menu,breakfast,lunch,dinner,canteen,vegetarian,non-veg', 'Three home-cooked meals a day in the canteen — breakfast 7–9am, lunch 12–2pm, dinner 7–9pm. Both veg and non-veg options. Special menu on weekends. Snacks and tea available throughout the day.', NULL, 90),
        ('security,safe,safety,cctv,biometric,warden,female,girl', 'Full CCTV coverage on entrances, corridors and common areas. Biometric entry, resident warden on-site 24/7, and separate floors for male and female students. Parents can request a daily check-in report.', NULL, 90),
        ('location,address,where,thamel,kathmandu,nearby,college,university', 'We are in Thamel, central Kathmandu — 5 minutes from Tribhuvan University and walking distance from major colleges, hospitals and the bus park. Maps link is in the Enquiry section below.', NULL, 85),
        ('apply,register,signup,sign up,join,how,enroll,admission,book', 'Click the Login button at the top, then Register here. Fill the form, upload a photo and verify your email with the OTP we send. The warden allocates a room within 24 hours.', NULL, 85),
        ('visit,tour,walkin,walk in,see,inspection,parents', 'You can walk in any day between 9am and 6pm for a guided tour. Or fill the Enquiry form below — our team will call to schedule a slot.', NULL, 80),
        ('contact,phone,call,email,whatsapp,owner,reach', 'Front desk: +977-1-XXXXXXX. Email: hello@hms-hostel.com. WhatsApp the same number. Or use the Enquiry form below and we will respond same day.', NULL, 80),
        ('leave,leaves,go home,vacation,outing,curfew,timing,gate', 'Main gate closes at 8pm. Late return needs warden permission. Students can apply for overnight or multi-day leave from the dashboard once enrolled — both warden and owner must approve.', NULL, 75),
        ('rules,policy,policies,allowed,not allowed,smoking,alcohol,visitors', 'Strictly no smoking or alcohol on the premises. Visitors allowed in the common lounge 9am–8pm with prior warden notice. Quiet hours 10pm–6am.', NULL, 75),
        ('refund,deposit,leave hostel,quit', 'The NPR 5,000 deposit is fully refunded at exit if there is no damage. Monthly fee refunds: cancel more than 30 days early = 90%, 7–30 days = 50%, less than 7 days = 0%.', NULL, 75),
        ('laundry,wash,clothes', 'Laundry is included — Mondays and Thursdays. Drop your bag with name tag at the canteen counter, pick it up next day.', NULL, 70),
        ('thanks,thank you,thx,bye,goodbye', 'Happy to help! Tap any topic above to keep exploring, or fill the Enquiry form to schedule a visit.', NULL, 60)");
}

// Performance indexes (TL-185–188). CREATE INDEX is not idempotent in older MySQL, so wrap in checks.
$idxChk = function($table, $name) use ($pdo) {
    try {
        return (bool)$pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$name'")->fetch();
    } catch (PDOException $e) {
        return false;
    }
};
if (!$idxChk('rooms', 'idx_status_deleted'))            db_try($pdo, "CREATE INDEX idx_status_deleted ON rooms(status, deleted_at)");
if (!$idxChk('bookings', 'idx_student_status'))         db_try($pdo, "CREATE INDEX idx_student_status ON bookings(student_id, status)");
if (!$idxChk('attendance', 'idx_student_date'))         db_try($pdo, "CREATE INDEX idx_student_date ON attendance(student_id, date)");
// Room photo column
if (!db_probe($pdo, "SELECT photo FROM rooms LIMIT 1")) {
    db_try($pdo, "ALTER TABLE rooms ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER notes");
}
// Bookings approval columns
if (!db_probe($pdo, "SELECT approved_by FROM bookings LIMIT 1")) {
    db_try($pdo, "ALTER TABLE bookings ADD COLUMN approved_by INT DEFAULT NULL AFTER notes");
    db_try($pdo, "ALTER TABLE bookings ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER approved_by");
}
// ─── Payments system (Phase 3) ───────────────────
db_try($pdo, "CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `room_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `billing_month` DATE NOT NULL,
  `status` ENUM('pending','success','failed','cancelled') DEFAULT 'pending',
  `gateway` ENUM('esewa','manual') DEFAULT 'esewa',
  `transaction_uuid` VARCHAR(64) NOT NULL,
  `gateway_ref` VARCHAR(100) DEFAULT NULL,
  `receipt_code` VARCHAR(30) DEFAULT NULL,
  `initiated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uniq_uuid` (`transaction_uuid`),
  KEY `idx_student` (`student_id`),
  KEY `idx_billing` (`billing_month`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

db_try($pdo, "CREATE TABLE IF NOT EXISTS `monthly_fees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `room_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `billing_month` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `status` ENUM('unpaid','paid','overdue') DEFAULT 'unpaid',
  `paid_payment_id` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_student_month` (`student_id`, `billing_month`),
  KEY `idx_status_month` (`status`, `billing_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ─── Transfer price-diff adjustments ──────────────
// When a student transfers rooms, the price difference is recorded here.
// direction='charge'  → student owes us (moved to a pricier room)
// direction='credit'  → we owe the student (moved to a cheaper room)
// status: 'pending' until paid/refunded, then 'settled'
db_try($pdo, "CREATE TABLE IF NOT EXISTS `transfer_adjustments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `transfer_id` INT DEFAULT NULL,
  `from_room_id` INT DEFAULT NULL,
  `to_room_id` INT NOT NULL,
  `old_price` DECIMAL(10,2) NOT NULL,
  `new_price` DECIMAL(10,2) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `direction` ENUM('charge','credit') NOT NULL,
  `status` ENUM('pending','settled') DEFAULT 'pending',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `settled_at` DATETIME DEFAULT NULL,
  KEY `idx_student_status` (`student_id`, `status`),
  KEY `idx_transfer` (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ─── End sprint-2 schema ──────────────────────────


// Seed routine if empty
try {
    $_routine_count = (int)$pdo->query("SELECT COUNT(*) FROM daily_routine")->fetchColumn();
} catch (PDOException $e) { $_routine_count = -1; }
if ($_routine_count === 0) {
    db_try($pdo, "INSERT INTO daily_routine (time_slot, activity, is_school_hours) VALUES
        ('06:00 AM - 07:00 AM', 'Wake up, Exercise & Morning Study', 0),
        ('07:00 AM - 03:00 PM', 'School/College Hours', 1),
        ('03:30 PM - 05:00 PM', 'Snacks & Personal Refreshment', 0),
        ('05:00 PM - 07:00 PM', 'Evening Guided Study', 0),
        ('07:30 PM - 08:30 PM', 'Dinner', 0),
        ('09:00 PM', 'Lights Out / Final Personal Study', 0)");
}

// Seed rooms only on a fresh install (rooms table empty). Once the warden
// edits/deletes a room, it stays that way — we don't repopulate.
try {
    $_room_count = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
} catch (PDOException $e) { $_room_count = -1; }
if ($_room_count === 0) {
    $_room_ins = $pdo->prepare("INSERT IGNORE INTO rooms (room_number, room_type, floor, status) VALUES (?, ?, ?, 'available')");
    // Floor 1: rooms 101-115 (alternating Double/Single)
    for ($i = 101; $i <= 115; $i++) {
        $type = ($i % 2 == 0) ? 'Double' : 'Single';
        $_room_ins->execute([(string)$i, $type, 1]);
    }
    // Floor 2: rooms 201-215 (alternating Double/Single)
    for ($i = 201; $i <= 215; $i++) {
        $type = ($i % 2 == 0) ? 'Double' : 'Single';
        $_room_ins->execute([(string)$i, $type, 2]);
    }
    // Floor 3: 10 rooms with explicit Single/Double/Triple capacity and price
    $floor3_rooms = [
        ['301', 'Single', 1, 5000.00],
        ['302', 'Double', 2, 4000.00],
        ['303', 'Triple', 3, 3000.00],
        ['304', 'Single', 1, 5000.00],
        ['305', 'Double', 2, 4000.00],
        ['306', 'Triple', 3, 3000.00],
        ['307', 'Single', 1, 5000.00],
        ['308', 'Double', 2, 4000.00],
        ['309', 'Triple', 3, 3000.00],
        ['310', 'Triple', 3, 3000.00],
    ];
    $_f3_ins = $pdo->prepare(
        "INSERT IGNORE INTO rooms (room_number, room_type, floor, status, capacity, price)
         VALUES (?, ?, 3, 'available', ?, ?)"
    );
    foreach ($floor3_rooms as $r) {
        $_f3_ins->execute([$r[0], $r[1], $r[2], $r[3]]);
    }
}

// ── Pricing refresh (v2): Single 5000, Double 4000, Triple 3000 ─────────────
// Gated by a version marker in app_settings so warden's per-room price overrides
// aren't clobbered on subsequent page loads.
db_try($pdo, "CREATE TABLE IF NOT EXISTS `app_settings` (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$_pricing_target = 'v2_2026';
try {
    $_pv_cur = $pdo->query("SELECT `value` FROM app_settings WHERE `key` = 'pricing_version' LIMIT 1")->fetchColumn();
} catch (PDOException $e) { $_pv_cur = false; }
if ($_pv_cur === false) { $_pv_cur = null; }
if ($_pv_cur !== $_pricing_target) {
    // Reset prices per room type
    db_try($pdo, "UPDATE rooms SET price = CASE
        WHEN room_type = 'Single' THEN 5000.00
        WHEN room_type = 'Double' THEN 4000.00
        WHEN room_type = 'Triple' THEN 3000.00
        ELSE price END");
    // Sync any unpaid/overdue monthly_fees so the current bill reflects the new price
    db_try($pdo, "UPDATE monthly_fees mf
        JOIN rooms r ON r.id = mf.room_id
        SET mf.amount = r.price
        WHERE mf.status IN ('unpaid','overdue')");
    // Mark migration done
    db_try($pdo, "INSERT INTO app_settings (`key`, `value`) VALUES ('pricing_version', 'v2_2026')
                         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
}

/**
 * Escape a string for safe HTML output. Use this on EVERY piece of
 * user/db data that gets rendered — it's the main line of defence
 * against stored XSS.
 */
function e($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Boot a session as soon as db.php is included so CSRF/auth helpers can rely on $_SESSION.
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Get-or-create the per-session CSRF token. Forms render it in a hidden
 * <input name="csrf_token"> field; the server then verifies via csrf_verify().
 */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Constant-time compare the submitted token to the session token.
 * Hard-fails the request with a 403 on mismatch — never silently passes.
 */
function csrf_verify($token) {
    if (empty($_SESSION['csrf']) ||
        !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        die('Security error: Invalid CSRF token. Please go back and try again.');
    }
}

/**
 * Encode a payload as a signed JWT (HS256) using JWT_SECRET from config.
 * Used to issue the auth cookie in set_auth(). Output: "header.payload.sig".
 */
function jwt_encode($payload) {
    $header    = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload   = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

/**
 * Verify + decode a JWT string. Returns the payload array on success,
 * false on any tampering / shape mismatch. Caller must still check exp.
 */
function jwt_decode($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $valid = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($valid, $sig)) return false;
    return json_decode(base64url_decode($payload), true);
}

/** Base64-URL helpers (RFC 7515) — JWTs use '-_' instead of '+/' and strip '=' padding. */
function base64url_encode($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function base64url_decode($d) {
    $d = strtr($d, '-_', '+/');
    $pad = strlen($d) % 4;
    if ($pad) {
        $d .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($d);
}

/**
 * Standard cookie options for the auth cookie. HttpOnly + SameSite=Lax,
 * Secure flag flipped on automatically when served over HTTPS.
 */
function auth_cookie_options($expires) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
}

/**
 * Issue a 30-day signed JWT cookie + populate the session on successful login.
 *
 * The JWT embeds `tv` (token_version) so we can invalidate ALL of a user's
 * existing cookies by bumping users.token_version (see revoke_user_tokens).
 * Useful when a user changes their password or logs out from "all devices".
 */
function set_auth($user) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true); // rotate session ID to defeat fixation attacks
    $tokenVersion = (int)($user['token_version'] ?? 0);
    $token = jwt_encode([
        'id'    => $user['id'],
        'name'  => $user['name'],
        'role'  => $user['role'],
        'photo' => $user['photo'] ?? 'default.png',
        'tv'    => $tokenVersion,
        'exp'   => time() + (86400 * 30)
    ]);
    setcookie('auth_token', $token, auth_cookie_options(time() + (86400 * 30)));
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_photo'] = $user['photo'] ?? 'default.png';
}

/**
 * Re-issue the auth JWT cookie with fresh DB data WITHOUT bumping token_version.
 * Call this after the user updates their profile (name/photo) so the topbar +
 * sidebar — which read from the JWT, not the DB — reflect the new data
 * immediately, without forcing the user to log out and back in.
 */
function refresh_auth_cookie($user_id) {
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT id, name, role, photo, token_version
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$user_id]);
    $user = $stmt->fetch();
    if (!$user) return false;

    $token = jwt_encode([
        'id'    => $user['id'],
        'name'  => $user['name'],
        'role'  => $user['role'],
        'photo' => $user['photo'] ?? 'default.png',
        'tv'    => (int)($user['token_version'] ?? 0),
        'exp'   => time() + (86400 * 30),
    ]);
    setcookie('auth_token', $token, auth_cookie_options(time() + (86400 * 30)));
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_photo'] = $user['photo'] ?? 'default.png';
    return true;
}

/**
 * Read the signed auth cookie and return the decoded payload (id, name, role, …)
 * if everything checks out. Returns false (and clears the cookie) if:
 *   • no cookie is present
 *   • signature is invalid
 *   • JWT exp has passed
 *   • the user's current token_version no longer matches the cookie's `tv`
 */
function get_auth() {
    if (!empty($_COOKIE['auth_token'])) {
        $d = jwt_decode($_COOKIE['auth_token']);
        if ($d && !empty($d['exp']) && $d['exp'] > time()) {
            if (!isset($d['tv'])) {
                // Cookie predates the token-version field — force re-login.
                setcookie('auth_token', '', auth_cookie_options(time() - 3600));
                return false;
            }
            $currentVersion = get_user_token_version((int)$d['id']);
            if ($currentVersion !== null && (int)$d['tv'] === $currentVersion) {
                return $d;
            }
        }
        // Bad / expired / revoked cookie — clear it so the browser stops sending it.
        setcookie('auth_token', '', auth_cookie_options(time() - 3600));
    }
    return false;
}

/** Look up a user's current token_version (used by get_auth() to validate cookies). */
function get_user_token_version($userId) {
    global $pdo;
    if (!$userId) return null;
    $stmt = $pdo->prepare('SELECT token_version FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['token_version'] : null;
}

/**
 * Invalidate every existing JWT cookie for this user by bumping their
 * token_version. Existing cookies still verify cryptographically, but
 * get_auth() rejects them on the version mismatch. Used by logout-all-devices
 * and password-change flows.
 */
function revoke_user_tokens($userId) {
    global $pdo;
    if (!$userId) return;
    $stmt = $pdo->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = ?');
    $stmt->execute([$userId]);
}

/**
 * Strong password policy used by signup, password-reset and change-password.
 * Returns null on success, or a human-readable error message describing what's
 * wrong. Keep the rules in one place so every flow enforces the same minimum.
 *
 * Rules:
 *   • at least 6 characters
 *   • at least one uppercase letter (A–Z)
 *   • at least one lowercase letter (a–z)
 *   • at least one digit (0–9)
 */
function validate_strong_password($pass): ?string {
    if (!is_string($pass) || strlen($pass) < 6) {
        return 'Password must be at least 6 characters long.';
    }
    if (!preg_match('/[A-Z]/', $pass)) {
        return 'Password must include at least one uppercase letter (A–Z).';
    }
    if (!preg_match('/[a-z]/', $pass)) {
        return 'Password must include at least one lowercase letter (a–z).';
    }
    if (!preg_match('/\d/', $pass)) {
        return 'Password must include at least one number (0–9).';
    }
    return null;
}

/**
 * One-line public description of the password rules — show alongside the
 * password input so users know what's expected before they submit.
 */
function password_rules_hint(): string {
    return 'At least 6 characters, with one uppercase letter, one lowercase letter, and one number.';
}

/**
 * Page guard: only allow users whose role exactly matches $role.
 * Anyone else (logged out, wrong role) is bounced to the login page.
 * Also mirrors auth fields into $_SESSION so legacy pages can read $_SESSION['user_id'] etc.
 */
function require_role($role) {
    $auth = get_auth();
    if (!$auth || $auth['role'] !== $role) {
        header('Location: ../auth/login.php');
        exit;
    }
    $_SESSION['user_id']    = $auth['id'];
    $_SESSION['user_role']  = $auth['role'];
    $_SESSION['user_name']  = $auth['name'];
    $_SESSION['user_photo'] = $auth['photo'] ?? 'default.png';
}

/**
 * Like require_role() but accepts a list of acceptable roles. Used by pages
 * that more than one role needs to access (e.g. attendance is open to both
 * warden AND owner).
 */
function require_role_any(array $roles) {
    $auth = get_auth();
    if (!$auth || !in_array($auth['role'], $roles, true)) {
        header('Location: ../auth/login.php');
        exit;
    }
    $_SESSION['user_id']    = $auth['id'];
    $_SESSION['user_role']  = $auth['role'];
    $_SESSION['user_name']  = $auth['name'];
    $_SESSION['user_photo'] = $auth['photo'] ?? 'default.png';
}

/**
 * Return the inline SVG markup for a named icon. Used by render_sidebar()
 * to put a consistent icon next to each menu link without external requests.
 * Falls back to the 'home' icon when an unknown name is requested.
 */
function get_svg_icon($name) {
    $icons = [
        'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9M5 11v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8"/></svg>',
        'bed' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 7v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7M4 12h16M4 7h16v4H4z"/></svg>',
        'clipboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h-2V2h-4v2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><path d="M9 11h6M9 15h6"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/><path d="M16 2v4M8 2v4M3 10h18M7 14h1M12 14h1M17 14h1M7 18h1M12 18h1M17 18h1"/></svg>',
        'door' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6a2 2 0 0 0 0 4h18a2 2 0 0 0 0-4H3z"/><path d="M3 10v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10"/><circle cx="17" cy="16" r="1" fill="currentColor"/></svg>',
        'alert' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 20h20L12 2z"/><path d="M12 9v4M12 17h.01"/></svg>',
        'key' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="15" r="3"/><path d="M13.172 5.172a4 4 0 0 0-5.656 0l-6 6a4 4 0 1 0 5.656 5.656l6-6"/></svg>',
        'checkmark' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        'chart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18M3 12v6M3 6v6M12 3v18"/><rect x="6" y="9" width="2" height="9"/><rect x="10" y="6" width="2" height="12"/><rect x="14" y="3" width="2" height="15"/></svg>',
        'money' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M9 11h6"/><path d="M15 15H9M15 9h-6"/></svg>',
        'message' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'trending'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'bell'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
        'help'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12" y2="17"/></svg>',
        'chevron-down'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>',
        'x'              => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'ticket'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><line x1="9" y1="12" x2="15" y2="12"/></svg>',
        'shield'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'user-minus'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
        'settings'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'receipt'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'download'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    ];
    return $icons[$name] ?? '';
}

/**
 * Render the role-aware left sidebar.
 *
 * @param string $active_page  Filename (e.g. "payments.php") used to add
 *                             the .active class to the current menu item.
 *                             Compared by basename so cross-folder hrefs
 *                             (like "../owner/report_attendance.php") still
 *                             highlight when you're on that page.
 * @return string  HTML for the sidebar; empty string if user not logged in.
 */
function render_sidebar($active_page, $base_prefix = '') {
    $auth = get_auth();
    if (!$auth) return '';

    // Per-role menu definitions. Each row: ['href', 'label', 'icon'].
    // Hrefs are resolved RELATIVE to the calling page's folder.
    // When the sidebar is rendered from outside the role folder (e.g.
    // auth/profile.php), the caller passes $base_prefix like "../student/"
    // so the relative links still land in the role folder.
    $menus = [
        'student' => [
            ['href' => 'dashboard.php',     'label' => 'Dashboard', 'icon' => 'home'],
            ['href' => 'browse_rooms.php',  'label' => 'Browse Rooms', 'icon' => 'bed'],
            ['href' => 'my_bookings.php',   'label' => 'My Bookings', 'icon' => 'clipboard'],
            ['href' => 'room.php',              'label' => 'My Room', 'icon' => 'door'],
            ['href' => 'request_transfer.php',  'label' => 'Request Transfer', 'icon' => 'trending'],
            ['href' => 'my_attendance.php', 'label' => 'Attendance', 'icon' => 'checkmark'],
            ['href' => 'my_routine.php',    'label' => 'Daily Routine', 'icon' => 'calendar'],
            ['href' => 'food_routine.php',  'label' => 'Meal Schedule', 'icon' => 'message'],
            ['href' => 'request_leave.php', 'label' => 'Request Leave', 'icon' => 'key'],
            ['href' => 'payments.php',      'label' => 'Pay Fees',   'icon' => 'money'],
            ['href' => 'my_complaints.php', 'label' => 'Complaints', 'icon' => 'alert'],
        ],
        'warden' => [
            ['href' => 'dashboard.php',                  'label' => 'Dashboard', 'icon' => 'home'],
            ['href' => 'list_student.php',               'label' => 'Student List', 'icon' => 'users'],
            ['href' => 'booking_requests.php',           'label' => 'Booking Requests', 'icon' => 'clipboard'],
            ['href' => 'room_transfers.php',             'label' => 'Room Transfers', 'icon' => 'door'],
            ['href' => 'student_attendance.php',         'label' => 'Mark Attendance', 'icon' => 'checkmark'],
            ['href' => '../owner/report_attendance.php', 'label' => 'Attendance Report', 'icon' => 'trending'],
            ['href' => 'manage_leaves.php',              'label' => 'Student Leaves', 'icon' => 'door'],
            ['href' => 'complaints.php',                 'label' => 'Complaints', 'icon' => 'alert'],
        ],
        'owner' => [
            ['href' => 'dashboard.php',         'label' => 'Dashboard', 'icon' => 'chart'],
            ['href' => 'manage_rooms.php',      'label' => 'Manage Rooms', 'icon' => 'bed'],
            ['href' => 'payments.php',          'label' => 'Payments',     'icon' => 'money'],
            ['href' => 'enquiries.php',         'label' => 'Enquiries', 'icon' => 'message'],
            ['href' => 'manage_routine.php',    'label' => 'Manage Routine', 'icon' => 'calendar'],
            ['href' => 'manage_staff.php',      'label' => 'Manage Staff', 'icon' => 'users'],
            ['href' => 'manage_leaves.php',     'label' => 'Student Leaves', 'icon' => 'door'],
            ['href' => 'report_attendance.php', 'label' => 'Attendance Report', 'icon' => 'trending'],
        ],
        'admin' => [
            ['href' => 'dashboard.php',       'label' => 'Dashboard',    'icon' => 'chart'],
            ['href' => 'resolve_tickets.php', 'label' => 'Help Tickets', 'icon' => 'ticket'],
            ['href' => 'manage_users.php',    'label' => 'Manage Users', 'icon' => 'shield'],
        ]
    ];

    $role_menu = $menus[$auth['role']] ?? [];
    $roleLabel = ucfirst($auth['role']);
    $html = '<div class="sidebar">';
    $html .= '<div class="sidebar-branding">';
    $html .= '<a class="sidebar-logo" href="' . $base_prefix . 'dashboard.php">HMS</a>';
    $html .= '<div class="sidebar-meta"><span class="sidebar-kicker">Student Residence</span><span class="sidebar-role">' . e($roleLabel) . ' Portal</span></div>';
    $html .= '</div>';
    $html .= '<button class="sidebar-toggle" type="button" aria-label="Toggle menu">☰</button>';
    $html .= '<ul class="sidebar-menu">';
    foreach ($role_menu as $m) {
        // Match by filename so cross-folder hrefs like "../owner/report_attendance.php"
        // still highlight when the user is on report_attendance.php from the owner folder.
        $active = (basename($active_page) === basename($m['href'])) ? 'class="active"' : '';
        $icon_name = $m['icon'] ?? 'home';
        $icon_svg = get_svg_icon($icon_name);
        $html .= '<li><a href="' . $base_prefix . $m['href'] . '" ' . $active . '><span class="menu-icon">' . $icon_svg . '</span>' . $m['label'] . '</a></li>';
    }
    $html .= '<li class="sidebar-spacer"></li>';
    $html .= '<li><span class="sidebar-user">' . e($auth['name']) . '</span></li>';
    $html .= '</ul></div>';
    return $html;
}

/**
 * Get notification items for the current user based on their role.
 * Returns an array of ['title','meta','href','color'] entries.
 */
function get_notifications($user_id, $role) {
    global $pdo;
    $items = [];
    try {
        switch ($role) {
            case 'student':
                $stmt = $pdo->prepare(
                    "SELECT warden_status, owner_status, start_date, end_date
                     FROM leaves WHERE student_id = ? ORDER BY created_at DESC LIMIT 4"
                );
                $stmt->execute([$user_id]);
                foreach ($stmt->fetchAll() as $l) {
                    if ($l['warden_status'] === 'approved' && $l['owner_status'] === 'approved') {
                        $items[] = ['title' => 'Leave approved', 'meta' => date('d M', strtotime($l['start_date'])) . ' - ' . date('d M', strtotime($l['end_date'])), 'href' => 'request_leave.php', 'color' => 'green'];
                    } elseif ($l['warden_status'] === 'rejected' || $l['owner_status'] === 'rejected') {
                        $items[] = ['title' => 'Leave request rejected', 'meta' => 'From ' . date('d M', strtotime($l['start_date'])), 'href' => 'request_leave.php', 'color' => 'red'];
                    }
                }

                // Room transfer decisions — show for 7 days after the warden decides.
                // Covers both flows: student-requested transfers (room_transfers table)
                // and warden-initiated direct moves (logged into room_audit_log).
                try {
                    $tr = $pdo->prepare(
                        "SELECT rt.status, rt.decided_at,
                                rf.room_number AS from_room,
                                rto.room_number AS to_room
                         FROM room_transfers rt
                         LEFT JOIN rooms rf  ON rf.id  = rt.from_room_id
                         LEFT JOIN rooms rto ON rto.id = rt.to_room_id
                         WHERE rt.student_id = ?
                           AND rt.decided_at IS NOT NULL
                           AND rt.decided_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         ORDER BY rt.decided_at DESC LIMIT 3"
                    );
                    $tr->execute([$user_id]);
                    foreach ($tr->fetchAll() as $t) {
                        if ($t['status'] === 'approved') {
                            $items[] = [
                                'title' => 'You moved to Room ' . ($t['to_room'] ?? '?'),
                                'meta'  => 'Warden approved your transfer ' . date('d M', strtotime($t['decided_at'])),
                                'href'  => 'room.php',
                                'color' => 'green',
                            ];
                        } elseif ($t['status'] === 'rejected') {
                            $items[] = [
                                'title' => 'Transfer request declined',
                                'meta'  => 'Room ' . ($t['to_room'] ?? '?') . ' on ' . date('d M', strtotime($t['decided_at'])),
                                'href'  => 'request_transfer.php',
                                'color' => 'red',
                            ];
                        }
                    }
                } catch (Exception $e) { /* table may not exist yet */ }

                // Warden-initiated direct transfers (no student request) — pulled
                // from the audit log so the student still gets notified.
                try {
                    $wt = $pdo->prepare(
                        "SELECT ral.created_at,
                                rto.room_number AS to_room
                         FROM room_audit_log ral
                         LEFT JOIN rooms rto ON rto.id = ral.to_room_id
                         WHERE ral.student_id = ?
                           AND ral.event = 'warden_transfer'
                           AND ral.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         ORDER BY ral.created_at DESC LIMIT 2"
                    );
                    $wt->execute([$user_id]);
                    foreach ($wt->fetchAll() as $t) {
                        $items[] = [
                            'title' => 'You moved to Room ' . ($t['to_room'] ?? '?'),
                            'meta'  => 'Warden assigned you a new room ' . date('d M', strtotime($t['created_at'])),
                            'href'  => 'room.php',
                            'color' => 'green',
                        ];
                    }
                } catch (Exception $e) { /* audit log may not exist */ }

                // Outstanding charges from a room transfer (price-difference dues).
                // If the student moved to a pricier room, the delta sits as a
                // pending charge until they pay it.
                try {
                    $adj = $pdo->prepare(
                        "SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total
                         FROM transfer_adjustments
                         WHERE student_id = ?
                           AND direction = 'charge'
                           AND status = 'pending'"
                    );
                    $adj->execute([$user_id]);
                    $row = $adj->fetch();
                    if ($row && (int)$row['c'] > 0) {
                        $items[] = [
                            'title' => 'Transfer due: NPR ' . number_format((float)$row['total'], 0),
                            'meta'  => (int)$row['c'] > 1
                                ? $row['c'] . ' room transfer adjustments pending'
                                : 'Top-up after your recent room change',
                            'href'  => 'payments.php',
                            'color' => 'red',
                        ];
                    }
                } catch (Exception $e) { /* table may not exist yet */ }

                try {
                    $fs = $pdo->prepare("SELECT billing_month, amount, status FROM monthly_fees WHERE student_id=? AND status IN('unpaid','overdue') ORDER BY billing_month DESC LIMIT 1");
                    $fs->execute([$user_id]);
                    $fee = $fs->fetch();
                    if ($fee) {
                        $items[] = ['title' => 'Fee due: NPR ' . number_format($fee['amount'], 0), 'meta' => date('F Y', strtotime($fee['billing_month'])) . ' - ' . ucfirst($fee['status']), 'href' => 'payments.php', 'color' => $fee['status'] === 'overdue' ? 'red' : 'amber'];
                    }
                } catch (Exception $e) {}
                // Resolved help tickets (show for 3 days after resolution)
                try {
                    $tr = $pdo->prepare("SELECT COUNT(*) FROM help_tickets WHERE user_id=? AND status='resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)");
                    $tr->execute([$user_id]);
                    $rcnt = (int)$tr->fetchColumn();
                    if ($rcnt > 0) $items[] = ['title' => 'Support ticket resolved', 'meta' => 'Admin has responded to your request', 'href' => 'dashboard.php', 'color' => 'green'];
                } catch (Exception $e) {}
                break;
            case 'warden':
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending_approval'")->fetchColumn();
                if ($cnt > 0) $items[] = ['title' => "$cnt booking request" . ($cnt > 1 ? 's' : '') . " pending", 'meta' => 'New room bookings awaiting your review', 'href' => 'booking_requests.php', 'color' => 'amber'];
                $tcnt = (int)$pdo->query("SELECT COUNT(*) FROM room_transfers WHERE status='pending'")->fetchColumn();
                if ($tcnt > 0) $items[] = ['title' => "$tcnt transfer request" . ($tcnt > 1 ? 's' : '') . " pending", 'meta' => 'Students want to move rooms', 'href' => 'room_transfers.php', 'color' => 'amber'];
                $lcnt = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE warden_status='pending'")->fetchColumn();
                if ($lcnt > 0) $items[] = ['title' => "$lcnt leave request" . ($lcnt > 1 ? 's' : '') . " pending", 'meta' => 'Need your approval', 'href' => 'manage_leaves.php', 'color' => 'amber'];
                try {
                    $tr = $pdo->prepare("SELECT COUNT(*) FROM help_tickets WHERE user_id=? AND status='resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)");
                    $tr->execute([$user_id]);
                    if ((int)$tr->fetchColumn() > 0) $items[] = ['title' => 'Support ticket resolved', 'meta' => 'Admin has responded to your request', 'href' => 'dashboard.php', 'color' => 'green'];
                } catch (Exception $e) {}
                break;
            case 'owner':
                $ecnt = (int)$pdo->query("SELECT COUNT(*) FROM enquiries WHERE status='unread'")->fetchColumn();
                if ($ecnt > 0) $items[] = ['title' => "$ecnt unread enquiries", 'meta' => 'Public messages awaiting reply', 'href' => 'enquiries.php', 'color' => ''];
                try {
                    $tr = $pdo->prepare("SELECT COUNT(*) FROM help_tickets WHERE user_id=? AND status='resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)");
                    $tr->execute([$user_id]);
                    if ((int)$tr->fetchColumn() > 0) $items[] = ['title' => 'Support ticket resolved', 'meta' => 'Admin has responded to your request', 'href' => 'dashboard.php', 'color' => 'green'];
                } catch (Exception $e) {}
                try {
                    $bm = date('Y-m-01');
                    $ocnt = (int)$pdo->prepare("SELECT COUNT(*) FROM monthly_fees WHERE status='overdue' AND billing_month=?")->execute([$bm]) ? 0 : 0;
                    $os = $pdo->prepare("SELECT COUNT(*) FROM monthly_fees WHERE status='overdue' AND billing_month=?");
                    $os->execute([$bm]);
                    $ocnt = (int)$os->fetchColumn();
                    if ($ocnt > 0) $items[] = ['title' => "$ocnt student(s) with overdue fees", 'meta' => date('F Y') . ' - past due date', 'href' => 'payments.php', 'color' => 'red'];
                } catch (Exception $e) {}
                break;
            case 'admin':
                try {
                    $tcnt = (int)$pdo->query("SELECT COUNT(*) FROM help_tickets WHERE status='open'")->fetchColumn();
                    if ($tcnt > 0) $items[] = ['title' => "$tcnt open help tickets", 'meta' => 'Awaiting resolution', 'href' => 'resolve_tickets.php', 'color' => 'amber'];
                } catch (Exception $e) {}
                break;
        }
    } catch (Exception $e) {}
    return $items;
}

/**
 * Generate a unique HMS receipt code: HMS-RCPT-YYYYMM-XXXXXX
 */
function generate_receipt_code(string $billing_month): string {
    global $pdo;
    $ym = date('Ym', strtotime($billing_month));
    for ($i = 0; $i < 10; $i++) {
        $suffix = strtoupper(substr(md5(uniqid('rcpt', true)), 0, 6));
        $code   = 'HMS-RCPT-' . $ym . '-' . $suffix;
        $chk    = $pdo->prepare("SELECT id FROM payments WHERE receipt_code = ? LIMIT 1");
        $chk->execute([$code]);
        if (!$chk->fetch()) return $code;
    }
    return 'HMS-RCPT-' . $ym . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Create monthly_fees rows for all active students who don't have one yet
 * for the given billing month (defaults to current month).
 * Returns the count of newly created rows.
 */
function generate_monthly_fees(string $billing_month = ''): int {
    global $pdo;
    if (!$billing_month) $billing_month = date('Y-m-01');
    $due_date = date('Y-m-10', strtotime($billing_month));

    // Also pick up students whose room was assigned via the bookings system
    // even if users.room_id isn't set yet. COALESCE(u.room_id, b.room_id) covers both.
    $students = $pdo->query(
        "SELECT u.id AS student_id,
                COALESCE(u.room_id, b.room_id) AS room_id,
                COALESCE(r.price, r2.price, 3000) AS price
         FROM users u
         LEFT JOIN bookings b ON b.student_id = u.id AND b.status IN ('approved','active')
         LEFT JOIN rooms r  ON r.id  = u.room_id
         LEFT JOIN rooms r2 ON r2.id = b.room_id
         WHERE u.role = 'student'
           AND (u.room_id IS NOT NULL OR b.id IS NOT NULL)"
    )->fetchAll();

    $created = 0;
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO monthly_fees (student_id, room_id, amount, billing_month, due_date)
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($students as $s) {
        if (!$s['room_id']) continue;
        $amount = max(1000, (float)($s['price'] ?? 3000));
        $ins->execute([$s['student_id'], $s['room_id'], $amount, $billing_month, $due_date]);
        if ($ins->rowCount() > 0) $created++;
    }
    return $created;
}

/**
 * Mark any monthly_fees rows as overdue if their due_date has passed.
 * Returns the number of rows updated.
 */
function mark_overdue_fees(): int {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE monthly_fees SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Sign a data string for eSewa using HMAC-SHA256 with the configured secret.
 */
function esewa_sign(string $data): string {
    return base64_encode(hash_hmac('sha256', $data, app_config('ESEWA_SECRET', '8gBm/:&EnhH.1/q'), true));
}

// Auto-generate monthly fees on the first page load of each new calendar month.
// Session flag prevents re-running on every request; only fires when no fees
// exist yet for the current billing month (i.e. first day of a new month).
(function () use ($pdo) {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $key = 'fees_autogen_' . date('Ym');
    if (!empty($_SESSION[$key])) return;
    $_SESSION[$key] = 1;
    try {
        $bm  = date('Y-m-01');
        $chk = $pdo->prepare("SELECT COUNT(*) FROM monthly_fees WHERE billing_month = ?");
        $chk->execute([$bm]);
        if ((int)$chk->fetchColumn() === 0) {
            generate_monthly_fees($bm);
        }
    } catch (Exception $e) {}
})();

/**
 * Render a minimal footer for inner pages.
 */
function render_footer(bool $minimal = false): string {
    $year = date('Y');
    if ($minimal) {
        return <<<HTML
<footer class="app-footer app-footer--pinned">
    <span class="af-brand"><span class="af-logo">HMS</span>Student Residence</span>
    <span class="af-sep">&middot;</span>
    <span class="af-loc">Thamel, Kathmandu</span>
    <span class="af-spacer"></span>
    <span class="af-copy">&copy; {$year} HMS &middot; All rights reserved</span>
</footer>
HTML;
    }
    return <<<HTML
<section class="page-info-band" aria-label="Hostel info">
    <div class="pib-inner">
        <div class="pib-col pib-brand-col">
            <div class="pib-brand">
                <span class="pib-logo">HMS</span>
                <div>
                    <div class="pib-name">HMS Student Residence</div>
                    <div class="pib-tag">A quiet, safe home built for serious students.</div>
                </div>
            </div>
        </div>
        <div class="pib-col">
            <div class="pib-col-title">Where to find us</div>
            <ul class="pib-list">
                <li><svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>Thamel, Kathmandu</li>
                <li><svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>+977 1-555-0100</li>
                <li><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>hello@hmsresidence.np</li>
            </ul>
        </div>
        <div class="pib-col">
            <div class="pib-col-title">Office hours</div>
            <ul class="pib-list">
                <li><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Mon&ndash;Fri &nbsp;7:00 AM &ndash; 9:00 PM</li>
                <li><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Sat &ndash; Sun &nbsp;8:00 AM &ndash; 6:00 PM</li>
                <li><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>24/7 security on site</li>
            </ul>
        </div>
        <div class="pib-col">
            <div class="pib-col-title">Need a hand?</div>
            <div class="pib-help">
                <p>Stuck on something? Drop us a help ticket and admin will get back to you.</p>
                <a href="#" onclick="document.getElementById('help-btn')?.click(); return false;" class="pib-help-link">
                    Open a ticket
                    <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>
<footer class="app-footer">
    <span class="af-brand"><span class="af-logo">HMS</span>Student Residence</span>
    <span class="af-sep">&middot;</span>
    <span class="af-loc">Thamel, Kathmandu</span>
    <span class="af-spacer"></span>
    <span class="af-copy">&copy; {$year} HMS &middot; All rights reserved</span>
</footer>
HTML;
}

/**
 * Render the fixed topbar: help button, notification bell, profile dropdown.
 * Also outputs the help modal and notification panel HTML + inline JS.
 *
 * @param string $title  Optional page title shown on the left of the topbar.
 */
function render_topbar($title = '') {
    $auth = get_auth();
    if (!$auth) return '';

    $name  = $auth['name'];
    $role  = $auth['role'];
    $photo = $auth['photo'] ?? 'default.png';

    $parts    = array_filter(explode(' ', trim($name)));
    $initials = strtoupper(substr($parts[0] ?? 'U', 0, 1))
              . strtoupper(substr($parts[1] ?? '', 0, 1));
    // Only emit a photo URL if the file actually exists on disk. Avoids the
    // brief broken-image flash that made fresh uploads occasionally look
    // like the photo "didn't save".
    $upload_dir_abs = __DIR__ . '/uploads/students/';
    $photo_on_disk  = ($photo && $photo !== 'default.png' && is_file($upload_dir_abs . $photo));
    $photo_url      = $photo_on_disk ? '../uploads/students/' . rawurlencode($photo) : null;

    $notifs     = get_notifications((int)$auth['id'], $role);
    $notif_cnt  = count($notifs);

    ob_start(); ?>
<script>
(function(){
    try {
        var saved = localStorage.getItem('hms-theme');
        if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
    } catch(e){}
})();
</script>
<header class="topbar" id="main-topbar">
    <div class="topbar-greeting">
        <?php if ($title === 'Dashboard'): ?>
            <span class="topbar-hello">Hello,</span>
            <strong class="topbar-username"><?= e($name) ?></strong>
        <?php endif; ?>
    </div>
    <div class="topbar-right">
        <button class="topbar-icon-btn theme-toggle" id="theme-toggle-btn" title="Toggle dark mode" type="button" aria-label="Toggle dark mode">
            <span class="icon-moon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </span>
            <span class="icon-sun">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </span>
        </button>
        <?php if ($role !== 'admin'): ?>
        <button class="topbar-icon-btn" id="help-btn" title="Help and Support" type="button">
            <?= get_svg_icon('help') ?>
        </button>
        <?php endif; ?>
        <button class="topbar-icon-btn" id="notif-btn" title="Notifications" type="button">
            <?= get_svg_icon('bell') ?>
            <?php if ($notif_cnt > 0): ?><span class="notif-badge"><?= min($notif_cnt, 9) ?></span><?php endif; ?>
        </button>
        <div class="topbar-sep"></div>
        <div class="topbar-profile" id="topbar-profile-wrap" role="button" tabindex="0">
            <div class="topbar-avatar">
                <?php if ($photo_url): ?>
                    <img src="<?= $photo_url ?>" alt="<?= e($name) ?>"
                         onerror="this.parentNode.textContent='<?= e($initials) ?>'">
                <?php else: ?><?= e($initials) ?><?php endif; ?>
            </div>
            <span class="topbar-name"><?= e($name) ?></span>
            <span class="topbar-caret" style="display:inline-flex;width:14px;height:14px;color:var(--muted)"><?= get_svg_icon('chevron-down') ?></span>
            <div class="topbar-dropdown" id="topbar-dropdown">
                <a href="../auth/profile.php">My Profile</a>
                <a href="../auth/change_password.php">Change Password</a>
                <a href="../auth/logout.php" class="danger-item">Logout</a>
            </div>
        </div>
    </div>
</header>

<!-- Help modal -->
<div class="modal-overlay" id="help-modal" role="dialog" aria-modal="true">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">Help &amp; Support</span>
            <button class="modal-close" id="help-modal-close" type="button"><?= get_svg_icon('x') ?></button>
        </div>
        <p style="font-size:0.855rem;color:var(--muted);margin-bottom:1.1rem;">Describe your issue and our admin team will get back to you shortly.</p>
        <form id="help-form" data-no-busy="true">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group">
                <label>Subject</label>
                <input type="text" name="subject" placeholder="Brief description of your issue" required maxlength="255">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Description</label>
                <textarea name="description" rows="4" placeholder="Provide as much detail as possible..." required minlength="10"></textarea>
            </div>
            <div style="display:flex;gap:0.6rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" id="help-cancel-btn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="help-submit-btn">Submit Ticket</button>
            </div>
        </form>
    </div>
</div>

<!-- Notification panel -->
<div class="notif-panel" id="notif-panel" role="region">
    <div class="notif-panel-header">
        <span class="notif-panel-title">Notifications</span>
    </div>
    <?php if (empty($notifs)): ?>
        <div style="padding:1.5rem 1rem;text-align:center;color:var(--muted);font-size:0.82rem;">You are all caught up.</div>
    <?php else: foreach ($notifs as $n): ?>
        <a class="notif-item" href="<?= e($n['href']) ?>">
            <span class="notif-dot <?= e($n['color']) ?>"></span>
            <div>
                <div class="notif-item-title"><?= e($n['title']) ?></div>
                <div class="notif-item-meta"><?= e($n['meta']) ?></div>
            </div>
        </a>
    <?php endforeach; endif; ?>
</div>

<script>
(function(){
    const pw  = document.getElementById('topbar-profile-wrap');
    const dd  = document.getElementById('topbar-dropdown');
    const nb  = document.getElementById('notif-btn');
    const np  = document.getElementById('notif-panel');
    const hb  = document.getElementById('help-btn');
    const hm  = document.getElementById('help-modal');
    const hmc = document.getElementById('help-modal-close');
    const hcc = document.getElementById('help-cancel-btn');
    const hf  = document.getElementById('help-form');

    function closeAll() {
        if (pw) pw.classList.remove('open');
        if (np) np.classList.remove('open');
    }

    if (pw) pw.addEventListener('click', function(e){ e.stopPropagation(); np && np.classList.remove('open'); pw.classList.toggle('open'); });
    if (nb) nb.addEventListener('click', function(e){ e.stopPropagation(); pw && pw.classList.remove('open'); np && np.classList.toggle('open'); });
    if (hb) hb.addEventListener('click', function(){ hm && hm.classList.add('open'); closeAll(); });
    if (hmc) hmc.addEventListener('click', function(){ hm && hm.classList.remove('open'); });
    if (hcc) hcc.addEventListener('click', function(){ hm && hm.classList.remove('open'); });
    if (hm)  hm.addEventListener('click', function(e){ if (e.target === hm) hm.classList.remove('open'); });
    document.addEventListener('click', closeAll);

    if (hf) {
        hf.addEventListener('submit', function(e){
            e.preventDefault();
            const btn = document.getElementById('help-submit-btn');
            btn.disabled = true; btn.textContent = 'Submitting...';
            fetch('../api/help_ticket.php', {method:'POST', body: new FormData(hf)})
                .then(r => r.json())
                .then(d => {
                    hm.classList.remove('open');
                    hf.reset();
                    const t = document.createElement('div');
                    t.className = 'alert alert-success';
                    t.style.cssText = 'position:fixed;top:70px;right:1.5rem;z-index:9999;max-width:300px;';
                    t.textContent = d.success ? 'Ticket submitted. Admin will respond soon.' : (d.message || 'Failed to submit.');
                    if (!d.success) t.className = 'alert alert-error';
                    document.body.appendChild(t);
                    setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),400); }, 3500);
                    btn.disabled = false; btn.textContent = 'Submit Ticket';
                })
                .catch(()=>{
                    const t = document.createElement('div');
                    t.className = 'alert alert-error';
                    t.style.cssText = 'position:fixed;top:70px;right:1.5rem;z-index:9999;max-width:300px;';
                    t.textContent = 'Network error. Please try again.';
                    document.body.appendChild(t);
                    setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),400); }, 3500);
                    btn.disabled=false; btn.textContent='Submit Ticket';
                });
        });
    }
})();
</script>
<?php
    return ob_get_clean();
}

?>

