-- ============================================================
-- MTTI MIS v6 — New Tables for All 10 Enhancements
-- Run this file ONCE in phpMyAdmin or via WP-CLI
-- Your table prefix may differ from 'wp_' — adjust below
-- ============================================================

-- ── Enhancement 02: Notification Centre ─────────────────────
CREATE TABLE IF NOT EXISTS `wp_mtti_notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`      INT NOT NULL,
    `type`            VARCHAR(30) NOT NULL DEFAULT 'info',
    -- type values: info | success | warning | danger
    `title`           VARCHAR(200) NOT NULL,
    `message`         TEXT NOT NULL,
    `is_read`         TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student  (`student_id`),
    INDEX idx_unread   (`student_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Enhancement 07: Peer Leaderboard (opt-in/out) ────────────
CREATE TABLE IF NOT EXISTS `wp_mtti_leaderboard_settings` (
    `setting_id`          INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`          INT NOT NULL UNIQUE,
    `show_on_leaderboard` TINYINT(1) NOT NULL DEFAULT 1,
    -- 1 = visible, 0 = anonymous
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Enhancement 09: Study Group Chat / Discussions ──────────
CREATE TABLE IF NOT EXISTS `wp_mtti_discussions` (
    `discussion_id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id`     INT NOT NULL,
    `student_id`    INT NOT NULL,
    `parent_id`     INT NULL DEFAULT NULL,
    -- NULL = top-level thread; INT = reply to thread
    `message`       TEXT NOT NULL,
    `upvotes`       INT NOT NULL DEFAULT 0,
    `is_pinned`     TINYINT(1) NOT NULL DEFAULT 0,
    `is_verified`   TINYINT(1) NOT NULL DEFAULT 0,
    -- is_verified = 1 set by admin/instructor to mark correct answer
    `status`        VARCHAR(20) NOT NULL DEFAULT 'published',
    -- status: published | pending | hidden
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_course      (`course_id`),
    INDEX idx_parent      (`parent_id`),
    INDEX idx_student     (`student_id`),
    FOREIGN KEY (`parent_id`) REFERENCES `wp_mtti_discussions`(`discussion_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_mtti_discussion_votes` (
    `vote_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `discussion_id` INT NOT NULL,
    `student_id`    INT NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (`discussion_id`, `student_id`),
    INDEX idx_discussion (`discussion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed a welcome notification for all existing students ────
-- (Optional: remove if you don't want this)
INSERT IGNORE INTO `wp_mtti_notifications` (`student_id`, `type`, `title`, `message`)
SELECT student_id, 'success',
       '🎉 Portal Upgraded!',
       'Your learner portal has been updated with new features: Dark Mode, AI Tutor, Calendar, Leaderboard, Study Chat, and more!'
FROM `wp_mtti_students`
WHERE status = 'Active';

-- ============================================================
-- Done. 4 tables created / confirmed.
-- ============================================================
