-- EDUPREDICT foundational schema
-- Run this in phpMyAdmin or MySQL CLI:
--   SOURCE schema.sql;

CREATE DATABASE IF NOT EXISTS `edupredict_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `edupredict_db`;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_key` VARCHAR(50) NOT NULL,
  `role_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_roles_role_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `user_roles` (`role_key`, `role_name`, `description`) VALUES
  ('administrator', 'Administrator', 'Manages users, classes, settings, and institution-wide analytics.'),
  ('instructor', 'Instructor', 'Manages classes, grading structures, performance monitoring, and future predictions.'),
  ('student', 'Student', 'Views class progress, grades, attendance, and academic performance insights.')
ON DUPLICATE KEY UPDATE
  `role_name` = VALUES(`role_name`),
  `description` = VALUES(`description`);

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `status` ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `administrators` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `employee_no` VARCHAR(50) DEFAULT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `contact` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_administrators_user_id` (`user_id`),
  UNIQUE KEY `uq_administrators_employee_no` (`employee_no`),
  CONSTRAINT `fk_administrators_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `instructors` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `employee_no` VARCHAR(50) DEFAULT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `department` VARCHAR(120) DEFAULT NULL,
  `contact` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instructors_user_id` (`user_id`),
  UNIQUE KEY `uq_instructors_employee_no` (`employee_no`),
  CONSTRAINT `fk_instructors_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `student_no` VARCHAR(50) DEFAULT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `contact` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_user_id` (`user_id`),
  UNIQUE KEY `uq_students_student_no` (`student_no`),
  CONSTRAINT `fk_students_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `classes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instructor_id` INT UNSIGNED DEFAULT NULL,
  `class_code` VARCHAR(30) NOT NULL,
  `class_name` VARCHAR(150) NOT NULL,
  `section` VARCHAR(100) DEFAULT NULL,
  `subject_code` VARCHAR(50) DEFAULT NULL,
  `subject_name` VARCHAR(150) NOT NULL,
  `schedule` VARCHAR(150) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `school_year` VARCHAR(20) DEFAULT NULL,
  `term` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_class_code` (`class_code`),
  KEY `idx_classes_instructor_id` (`instructor_id`),
  KEY `idx_classes_status` (`status`),
  CONSTRAINT `fk_classes_instructor_id` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `class_enrollments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `status` ENUM('active','removed') NOT NULL DEFAULT 'active',
  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_enrollments_class_student` (`class_id`, `student_id`),
  KEY `idx_class_enrollments_student_id` (`student_id`),
  KEY `idx_class_enrollments_status` (`status`),
  CONSTRAINT `fk_class_enrollments_class_id` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_enrollments_student_id` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_group` VARCHAR(80) NOT NULL DEFAULT 'general',
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `label` VARCHAR(150) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_group`, `setting_key`, `setting_value`, `label`) VALUES
  ('general', 'app_name', 'EDUPREDICT', 'Application name'),
  ('general', 'institution_name', 'Your Institution', 'Institution name'),
  ('academic', 'default_term', 'First Semester', 'Default academic term')
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `label` = VALUES(`label`);
