
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS hostel3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hostel3;

-- TABLE: users
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `email`            VARCHAR(100) NOT NULL,
  `password`         VARCHAR(255) NOT NULL,
  `otp_code`         VARCHAR(6)   DEFAULT NULL,
  `otp_expiry`       DATETIME     DEFAULT NULL,
  `otp_created_at`   DATETIME     DEFAULT NULL,
  `otp_attempts`     INT          NOT NULL DEFAULT 0,
  `is_verified`      TINYINT(1)   DEFAULT 0,
  `reset_otp`        VARCHAR(6)   DEFAULT NULL,
  `reset_otp_expiry` DATETIME     DEFAULT NULL,
  `role`             ENUM('student','warden','owner','admin') NOT NULL DEFAULT 'student',
  `name`             VARCHAR(100) NOT NULL,
  `photo`            VARCHAR(255) DEFAULT 'default.png',
  `cover_photo`      VARCHAR(255) DEFAULT NULL,
  `description`      TEXT         DEFAULT NULL,
  `token_version`    INT(11)      NOT NULL DEFAULT 0,
  `student_phone`    VARCHAR(20)  DEFAULT NULL,
  `room_id`          INT(11)      DEFAULT NULL,
  `room_status`      ENUM('pending','approved') DEFAULT 'pending',
  `room_preference`  VARCHAR(100) DEFAULT 'No Preference',
  `fee_status`       ENUM('paid','unpaid') DEFAULT 'unpaid',
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: rooms
CREATE TABLE IF NOT EXISTS `rooms` (
  `id`          INT(11)        NOT NULL AUTO_INCREMENT,
  `room_number` VARCHAR(10)    NOT NULL,
  `room_type`   VARCHAR(20)    NOT NULL,
  `floor`       INT(11)        NOT NULL,
  `status`      ENUM('available','occupied','maintenance') DEFAULT 'available',
  `student_id`  INT(11)        DEFAULT NULL,
  `capacity`    INT            NOT NULL DEFAULT 1,
  `price`       DECIMAL(10,2)  NOT NULL DEFAULT 5000.00,
  `notes`       TEXT           DEFAULT NULL,
  `photo`       VARCHAR(255)   DEFAULT NULL,
  `deleted_at`  DATETIME       DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_room_number` (`room_number`),
  KEY `idx_status_deleted` (`status`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: attendance
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `status`     ENUM('present','absent') NOT NULL,
  `date`       DATE    NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`student_id`,`date`),
  KEY `idx_student_date` (`student_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: complaints
CREATE TABLE IF NOT EXISTS `complaints` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `student_id` INT(11)      NOT NULL,
  `subject`    VARCHAR(255) NOT NULL,
  `message`    TEXT         NOT NULL,
  `status`     ENUM('open','resolved') DEFAULT 'open',
  `reply`      TEXT         DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: food_routine
CREATE TABLE IF NOT EXISTS `food_routine` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `menu_date`  DATE         NOT NULL,
  `meal_time`  ENUM('breakfast','lunch','dinner') NOT NULL,
  `menu`       TEXT         NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_meal` (`menu_date`,`meal_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: enquiries
CREATE TABLE IF NOT EXISTS `enquiries` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `email`      VARCHAR(100) DEFAULT NULL,
  `message`    TEXT         NOT NULL,
  `source`    VARCHAR(50)  DEFAULT 'Website Form',
  `status`     ENUM('unread','read','replied') DEFAULT 'unread',
  `reply`      TEXT         DEFAULT NULL,
  `replied_at` DATETIME     DEFAULT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: daily_routine
CREATE TABLE IF NOT EXISTS `daily_routine` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `time_slot`       VARCHAR(50)  NOT NULL,
  `activity`        VARCHAR(255) NOT NULL,
  `is_school_hours` TINYINT(1)   DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: staff
CREATE TABLE IF NOT EXISTS `staff` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `role`       VARCHAR(50)  NOT NULL,
  `allocation` ENUM('Room','Canteen','Toilets','Garden','General') DEFAULT 'General',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: leaves (warden + owner two-step approval)
CREATE TABLE IF NOT EXISTS `leaves` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `student_id`    INT(11) NOT NULL,
  `reason`        TEXT    NOT NULL,
  `warden_status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `owner_status`  ENUM('pending','approved','rejected') DEFAULT 'pending',
  `final_status`  ENUM('pending','approved','rejected') DEFAULT 'pending',
  `start_date`    DATE    NOT NULL,
  `end_date`      DATE    NOT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: help_tickets (admin support queue)
CREATE TABLE IF NOT EXISTS `help_tickets` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `role`        VARCHAR(20)  NOT NULL DEFAULT 'student',
  `subject`     VARCHAR(255) NOT NULL,
  `description` TEXT         NOT NULL,
  `status`      ENUM('open','in_progress','resolved') DEFAULT 'open',
  `assigned_to` INT          DEFAULT NULL,
  `admin_note`  TEXT         DEFAULT NULL,
  `resolved_by` INT(11)      DEFAULT NULL,
  `resolved_at` DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: bookings (replaces simple users.room_id model going forward)
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `student_id`   INT(11) NOT NULL,
  `room_id`      INT(11) NOT NULL,
  `start_date`   DATE    NOT NULL,
  `status`       ENUM('pending_approval','approved','active','cancellation_requested','cancelled','rejected') DEFAULT 'pending_approval',
  `notes`        TEXT     DEFAULT NULL,
  `approved_by`  INT      DEFAULT NULL,
  `approved_at`  DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room_status`    (`room_id`, `status`),
  KEY `idx_student_status` (`student_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: room_audit_log
CREATE TABLE IF NOT EXISTS `room_audit_log` (
  `id`           INT(11)  NOT NULL AUTO_INCREMENT,
  `room_id`      INT(11)  DEFAULT NULL,
  `student_id`   INT(11)  DEFAULT NULL,
  `event`        VARCHAR(64) NOT NULL,
  `from_room_id` INT(11)  DEFAULT NULL,
  `to_room_id`   INT(11)  DEFAULT NULL,
  `actor_id`     INT(11)  DEFAULT NULL,
  `notes`        TEXT     DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room`    (`room_id`, `created_at`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: room_transfers
CREATE TABLE IF NOT EXISTS `room_transfers` (
  `id`           INT(11)  NOT NULL AUTO_INCREMENT,
  `student_id`   INT(11)  NOT NULL,
  `from_room_id` INT(11)  DEFAULT NULL,
  `to_room_id`   INT(11)  NOT NULL,
  `reason`       TEXT     NOT NULL,
  `status`       ENUM('pending','approved','rejected') DEFAULT 'pending',
  `decided_by`   INT(11)  DEFAULT NULL,
  `decided_at`   DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_status` (`student_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: chatbot_intents
CREATE TABLE IF NOT EXISTS `chatbot_intents` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `keywords`  VARCHAR(500) NOT NULL,
  `response`  TEXT         NOT NULL,
  `action`    VARCHAR(40)  DEFAULT NULL,
  `priority`  INT(11)      NOT NULL DEFAULT 0,
  `is_active` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: chatbot_conversations
CREATE TABLE IF NOT EXISTS `chatbot_conversations` (
  `id`                INT(11)     NOT NULL AUTO_INCREMENT,
  `student_id`        INT(11)     DEFAULT NULL,
  `session_id`        VARCHAR(64) NOT NULL,
  `user_message`      TEXT        NOT NULL,
  `bot_response`      TEXT        NOT NULL,
  `matched_intent_id` INT(11)     DEFAULT NULL,
  `rating`            TINYINT(1)  DEFAULT NULL,
  `created_at`        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: app_settings (key/value store for migration markers, feature toggles)
CREATE TABLE IF NOT EXISTS `app_settings` (
  `key`        VARCHAR(64)  NOT NULL,
  `value`      VARCHAR(255) NOT NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: payments (eSewa + manual gateway records)
CREATE TABLE IF NOT EXISTS `payments` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `student_id`       INT          NOT NULL,
  `room_id`          INT          NOT NULL,
  `amount`           DECIMAL(10,2) NOT NULL,
  `billing_month`    DATE         NOT NULL,
  `status`           ENUM('pending','success','failed','cancelled') DEFAULT 'pending',
  `gateway`          ENUM('esewa','manual') DEFAULT 'esewa',
  `transaction_uuid` VARCHAR(64)  NOT NULL,
  `gateway_ref`      VARCHAR(100) DEFAULT NULL,
  `receipt_code`     VARCHAR(30)  DEFAULT NULL,
  `initiated_at`     DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `paid_at`          DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uuid` (`transaction_uuid`),
  KEY `idx_student`     (`student_id`),
  KEY `idx_billing`     (`billing_month`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: monthly_fees (auto-generated bills, one row per student per month)
CREATE TABLE IF NOT EXISTS `monthly_fees` (
  `id`              INT           NOT NULL AUTO_INCREMENT,
  `student_id`      INT           NOT NULL,
  `room_id`         INT           NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `billing_month`   DATE          NOT NULL,
  `due_date`        DATE          NOT NULL,
  `status`          ENUM('unpaid','paid','overdue') DEFAULT 'unpaid',
  `paid_payment_id` INT           DEFAULT NULL,
  `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_month` (`student_id`, `billing_month`),
  KEY `idx_status_month` (`status`, `billing_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: transfer_adjustments (price diff when a student moves rooms)
CREATE TABLE IF NOT EXISTS `transfer_adjustments` (
  `id`            INT           NOT NULL AUTO_INCREMENT,
  `student_id`    INT           NOT NULL,
  `transfer_id`   INT           DEFAULT NULL,
  `from_room_id`  INT           DEFAULT NULL,
  `to_room_id`    INT           NOT NULL,
  `old_price`     DECIMAL(10,2) NOT NULL,
  `new_price`     DECIMAL(10,2) NOT NULL,
  `amount`        DECIMAL(10,2) NOT NULL,
  `direction`     ENUM('charge','credit') NOT NULL,
  `status`        ENUM('pending','settled') DEFAULT 'pending',
  `notes`         VARCHAR(255)  DEFAULT NULL,
  `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `settled_at`    DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_status` (`student_id`, `status`),
  KEY `idx_transfer`       (`transfer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────
-- SEED DATA — Password for all accounts: Test1234
-- ─────────────────────────────────────────────────

-- Order matters: ali=3, sara=4, tom=5 because the room/attendance/complaint
-- seeds below reference those IDs. Admin is appended at the end (id=6) so the
-- existing fixtures stay aligned.
INSERT INTO `users` (`email`, `password`, `role`, `name`, `student_phone`, `is_verified`) VALUES
('warden@hms.com',  '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'warden',  'John Warden', NULL,         1),
('owner@hms.com',   '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'owner',   'Mary Owner',  NULL,         1),
('ali@student.com', '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'student', 'Ali Student', '0111111111', 1),
('sara@student.com','$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'student', 'Sara Student','0122222222', 1),
('tom@student.com', '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'student', 'Tom Student', '0133333333', 1),
('admin@hms.com',   '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'admin',   'System Admin',NULL,         1);

INSERT INTO `rooms` (`room_number`, `room_type`, `floor`, `status`, `student_id`, `capacity`, `price`) VALUES
('101', 'Single', 1, 'occupied',  3, 1, 5000.00),
('102', 'Double', 1, 'occupied',  4, 2, 4000.00),
('103', 'Single', 1, 'occupied',  5, 1, 5000.00),
('104', 'Double', 2, 'available', NULL, 2, 4000.00),
('105', 'Single', 2, 'available', NULL, 1, 5000.00);

UPDATE `users` SET `room_id` = 1, `room_status` = 'approved' WHERE `id` = 3;
UPDATE `users` SET `room_id` = 2, `room_status` = 'approved' WHERE `id` = 4;
UPDATE `users` SET `room_id` = 3, `room_status` = 'approved' WHERE `id` = 5;

INSERT INTO `attendance` (`student_id`, `date`, `status`) VALUES
(3, CURDATE(), 'present'),
(4, CURDATE(), 'present'),
(5, CURDATE(), 'absent'),
(3, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'present'),
(4, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'absent'),
(5, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'present'),
(3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'present'),
(4, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'present'),
(5, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'present');

INSERT INTO `food_routine` (`menu_date`, `meal_time`, `menu`) VALUES
(CURDATE(), 'breakfast', 'Bread, Eggs and Tea'),
(CURDATE(), 'lunch',     'Rice, Lentil Soup and Vegetables'),
(CURDATE(), 'dinner',    'Chicken Curry and Rice'),
(DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'breakfast', 'Cereal, Milk and Juice'),
(DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'lunch',     'Pasta, Salad and Bread'),
(DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'dinner',    'Fish, Rice and Salad');

INSERT INTO `complaints` (`student_id`, `subject`, `message`, `status`) VALUES
(3, 'Broken window fan', 'The ceiling fan in my room is making a loud noise and is broken.', 'open'),
(4, 'Hot water issue',   'Hot water has not been working in the bathroom since Monday.', 'open');

INSERT INTO `daily_routine` (`time_slot`, `activity`, `is_school_hours`) VALUES
('06:00 AM - 07:00 AM', 'Wake up, Exercise & Morning Study', 0),
('07:00 AM - 03:00 PM', 'School/College Hours', 1),
('03:30 PM - 05:00 PM', 'Snacks & Personal Refreshment', 0),
('05:00 PM - 07:00 PM', 'Evening Guided Study', 0),
('07:30 PM - 08:30 PM', 'Dinner', 0),
('09:00 PM', 'Lights Out / Final Personal Study', 0);

INSERT INTO `staff` (`name`, `role`, `allocation`) VALUES
('Ram Bahadur', 'Cleaner',   'Toilets'),
('Shiva Thapa', 'Cook',      'Canteen'),
('Maya Devi',   'Helper',    'Canteen'),
('Kaji Sherpa', 'Gardener',  'Garden'),
('Bhim Thapa',  'Security',  'General'),
('Hari Prasad', 'Cleaner',   'Room');

-- Pricing-version marker so db.php's idempotent pricing migration knows it's already done.
INSERT INTO `app_settings` (`key`, `value`) VALUES ('pricing_version', 'v2_2026')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
