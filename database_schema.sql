
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS hostel2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hostel2;

-- TABLE: users
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `email`            VARCHAR(100) NOT NULL,
  `password`         VARCHAR(255) NOT NULL,
  `otp_code`         VARCHAR(6)   DEFAULT NULL,
  `is_verified`      TINYINT(1)   DEFAULT 0,
  `reset_otp`        VARCHAR(6)   DEFAULT NULL,
  `reset_otp_expiry` DATETIME     DEFAULT NULL,
  `role`             ENUM('student','warden','owner') NOT NULL,
  `name`             VARCHAR(100) NOT NULL,
  `photo`            VARCHAR(255) DEFAULT 'default.png',
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
  `id`          INT(11)     NOT NULL AUTO_INCREMENT,
  `room_number` VARCHAR(10) NOT NULL,
  `room_type`   VARCHAR(20) NOT NULL,
  `floor`       INT(11)     NOT NULL,
  `status`      ENUM('available','occupied') DEFAULT 'available',
  `student_id`  INT(11)     DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TABLE: attendance
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `status`     ENUM('present','absent') NOT NULL,
  `date`       DATE    NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`student_id`,`date`)
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

-- ─────────────────────────────────────────────────
-- SEED DATA — Password for all accounts: Test1234
-- ─────────────────────────────────────────────────

INSERT INTO `users` (`email`, `password`, `role`, `name`, `student_phone`) VALUES
('warden@hms.com',  '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'warden',  'John Warden', NULL),
('owner@hms.com',   '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'owner',   'Mary Owner',  NULL),
('ali@student.com', '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'student', 'Ali Student', '0111111111'),
('sara@student.com','$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'student', 'Sara Student','0122222222'),
('tom@student.com', '$2y$10$/RKNhhmM.eRp2bOMq0depu5j4/Fupaoh247qD/Kd2A.N91WG6AJAy', 'student', 'Tom Student', '0133333333');

INSERT INTO `rooms` (`room_number`, `room_type`, `floor`, `status`, `student_id`) VALUES
('101', 'Single', 1, 'occupied',  3),
('102', 'Double', 1, 'occupied',  4),
('103', 'Single', 1, 'occupied',  5),
('104', 'Double', 2, 'available', NULL),
('105', 'Single', 2, 'available', NULL);

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
