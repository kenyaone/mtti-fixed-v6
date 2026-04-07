-- MTTI MIS v7.0.0 — New Tables
-- Run this in phpMyAdmin if upgrading from v6 (tables may already exist if plugin was freshly activated)
-- Safe to run multiple times (uses CREATE TABLE IF NOT EXISTS)

-- Scheme of Work table
-- Stores week-by-week course plan (admin fills in, lecturers check off, students view as roadmap)
CREATE TABLE IF NOT EXISTS `wp_mtti_scheme_of_work` (
    `week_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `week_number` int(11) NOT NULL,
    `unit_id` bigint(20) NULL,
    `topic` varchar(300) NOT NULL,
    `objectives` text NULL,
    `teaching_method` varchar(100) NULL,
    `resources` text NULL,
    `duration_hours` decimal(4,1) DEFAULT 3.0,
    `status` varchar(20) DEFAULT 'Pending' COMMENT 'Pending, In Progress, Completed, Skipped',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`week_id`),
    KEY `course_id` (`course_id`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session Logs table
-- Records lecturer clock-in/out per session for time tracking vs planned duration
CREATE TABLE IF NOT EXISTS `wp_mtti_session_logs` (
    `session_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `staff_id` bigint(20) NOT NULL,
    `course_id` bigint(20) NOT NULL,
    `topic` varchar(300) NOT NULL,
    `week_id` bigint(20) NULL COMMENT 'Linked scheme_of_work week (optional)',
    `planned_hours` decimal(4,1) DEFAULT 2.0,
    `duration_minutes` int(11) NULL COMMENT 'Filled on clock-out',
    `notes` text NULL,
    `clock_in` datetime NOT NULL,
    `clock_out` datetime NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`),
    KEY `staff_id` (`staff_id`),
    KEY `course_id` (`course_id`),
    KEY `clock_in` (`clock_in`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: If your table prefix is NOT wp_ (check wp-config.php for $table_prefix)
-- Replace wp_ above with your actual prefix e.g. wp2_ or mtti_ etc.
