-- =====================================================
-- MTTI MIS - Complete Database Setup Script
-- Run this in phpMyAdmin to create all required tables
-- =====================================================
-- IMPORTANT: Replace 'wp_' with your actual table prefix if different
-- Your prefix appears to be: wp_
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- Students table
CREATE TABLE IF NOT EXISTS `wp_mtti_students` (
    `student_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED NULL,
    `admission_number` varchar(20) NOT NULL,
    `course_id` bigint(20) NULL,
    `id_number` varchar(20) NULL,
    `date_of_birth` date NULL,
    `gender` varchar(10) NULL,
    `address` text NULL,
    `county` varchar(50) NULL,
    `emergency_contact` varchar(100) NULL,
    `emergency_phone` varchar(20) NULL,
    `enrollment_date` date NOT NULL,
    `status` varchar(20) DEFAULT 'Active',
    `photo_url` varchar(255) NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`student_id`),
    UNIQUE KEY `admission_number` (`admission_number`),
    KEY `user_id` (`user_id`),
    KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff table
CREATE TABLE IF NOT EXISTS `wp_mtti_staff` (
    `staff_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `user_id` bigint(20) UNSIGNED NULL,
    `staff_number` varchar(20) NOT NULL,
    `id_number` varchar(20) NULL,
    `date_of_birth` date NULL,
    `gender` varchar(10) NULL,
    `address` text NULL,
    `department` varchar(100) NULL,
    `position` varchar(100) NULL,
    `specialization` text NULL,
    `hire_date` date NOT NULL,
    `status` varchar(20) DEFAULT 'Active',
    `photo_url` varchar(255) NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`staff_id`),
    UNIQUE KEY `staff_number` (`staff_number`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courses table
CREATE TABLE IF NOT EXISTS `wp_mtti_courses` (
    `course_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_code` varchar(20) NOT NULL,
    `course_name` varchar(200) NOT NULL,
    `description` text NULL,
    `category` varchar(50) NOT NULL,
    `duration_weeks` int(11) NOT NULL,
    `fee` decimal(10,2) NOT NULL,
    `max_capacity` int(11) DEFAULT 20,
    `prerequisites` text NULL,
    `learning_outcomes` text NULL,
    `status` varchar(20) DEFAULT 'Active',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`course_id`),
    UNIQUE KEY `course_code` (`course_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course Units table
CREATE TABLE IF NOT EXISTS `wp_mtti_course_units` (
    `unit_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `unit_code` varchar(20) NOT NULL,
    `unit_name` varchar(200) NOT NULL,
    `description` text NULL,
    `order_number` int(11) DEFAULT 0,
    `duration_hours` int(11) NULL,
    `credit_hours` decimal(5,2) NULL,
    `status` varchar(20) DEFAULT 'Active',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`unit_id`),
    UNIQUE KEY `unit_code` (`unit_code`),
    KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enrollments table
CREATE TABLE IF NOT EXISTS `wp_mtti_enrollments` (
    `enrollment_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `student_id` bigint(20) NOT NULL,
    `course_id` bigint(20) NOT NULL,
    `staff_id` bigint(20) NULL,
    `batch_number` varchar(20) NULL,
    `enrollment_date` date NOT NULL,
    `start_date` date NULL,
    `expected_end_date` date NULL,
    `actual_end_date` date NULL,
    `status` varchar(20) DEFAULT 'Enrolled',
    `final_grade` varchar(5) NULL,
    `certificate_issued` tinyint(1) DEFAULT 0,
    `certificate_number` varchar(50) NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`enrollment_id`),
    KEY `student_id` (`student_id`),
    KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance table
CREATE TABLE IF NOT EXISTS `wp_mtti_attendance` (
    `attendance_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `enrollment_id` bigint(20) NOT NULL,
    `date` date NOT NULL,
    `status` varchar(20) NOT NULL,
    `time_in` time NULL,
    `time_out` time NULL,
    `notes` text NULL,
    `marked_by` bigint(20) UNSIGNED NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attendance_id`),
    KEY `enrollment_id` (`enrollment_id`),
    KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessments table
CREATE TABLE IF NOT EXISTS `wp_mtti_assessments` (
    `assessment_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `enrollment_id` bigint(20) NOT NULL,
    `assessment_type` varchar(50) NOT NULL,
    `assessment_name` varchar(200) NOT NULL,
    `max_score` decimal(5,2) NOT NULL,
    `score_obtained` decimal(5,2) NULL,
    `percentage` decimal(5,2) NULL,
    `grade` varchar(5) NULL,
    `assessment_date` date NOT NULL,
    `remarks` text NULL,
    `assessed_by` bigint(20) NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`assessment_id`),
    KEY `enrollment_id` (`enrollment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payments table
CREATE TABLE IF NOT EXISTS `wp_mtti_payments` (
    `payment_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `student_id` bigint(20) NOT NULL,
    `enrollment_id` bigint(20) NULL,
    `gross_amount` decimal(10,2) DEFAULT 0.00,
    `discount` decimal(10,2) DEFAULT 0.00,
    `amount` decimal(10,2) NOT NULL,
    `payment_method` varchar(50) NOT NULL,
    `transaction_reference` varchar(100) NULL,
    `payment_date` date NOT NULL,
    `payment_for` varchar(200) NULL,
    `receipt_number` varchar(50) NOT NULL,
    `status` varchar(20) DEFAULT 'Completed',
    `received_by` bigint(20) UNSIGNED NULL,
    `notes` text NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`payment_id`),
    UNIQUE KEY `receipt_number` (`receipt_number`),
    KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignments table
CREATE TABLE IF NOT EXISTS `wp_mtti_assignments` (
    `assignment_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `staff_id` bigint(20) NOT NULL,
    `title` varchar(200) NOT NULL,
    `description` text NULL,
    `file_path` varchar(255) NULL,
    `file_name` varchar(255) NULL,
    `due_date` datetime NOT NULL,
    `max_score` decimal(5,2) DEFAULT 100.00,
    `status` varchar(20) DEFAULT 'Active',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`assignment_id`),
    KEY `course_id` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment submissions table
CREATE TABLE IF NOT EXISTS `wp_mtti_assignment_submissions` (
    `submission_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `assignment_id` bigint(20) NOT NULL,
    `student_id` bigint(20) NOT NULL,
    `submission_text` text NULL,
    `file_path` varchar(255) NULL,
    `file_name` varchar(255) NULL,
    `score` decimal(5,2) NULL,
    `feedback` text NULL,
    `submitted_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `graded_at` datetime NULL,
    `graded_by` bigint(20) NULL,
    `status` varchar(20) DEFAULT 'Submitted',
    PRIMARY KEY (`submission_id`),
    KEY `assignment_id` (`assignment_id`),
    KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live classes table
CREATE TABLE IF NOT EXISTS `wp_mtti_live_classes` (
    `class_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `staff_id` bigint(20) NOT NULL,
    `batch_number` varchar(20) NULL,
    `title` varchar(200) NOT NULL,
    `description` text NULL,
    `meeting_link` varchar(500) NULL,
    `meeting_platform` varchar(50) DEFAULT 'Zoom',
    `meeting_id` varchar(100) NULL,
    `meeting_password` varchar(100) NULL,
    `scheduled_date` datetime NOT NULL,
    `duration_minutes` int(11) DEFAULT 60,
    `status` varchar(20) DEFAULT 'Scheduled',
    `recording_link` varchar(500) NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`class_id`),
    KEY `course_id` (`course_id`),
    KEY `scheduled_date` (`scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student balances table
CREATE TABLE IF NOT EXISTS `wp_mtti_student_balances` (
    `balance_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `student_id` bigint(20) NOT NULL,
    `enrollment_id` bigint(20) NOT NULL,
    `total_fee` decimal(10,2) NOT NULL,
    `discount_amount` decimal(10,2) DEFAULT 0.00,
    `total_paid` decimal(10,2) DEFAULT 0.00,
    `balance` decimal(10,2) NOT NULL,
    `last_payment_date` date NULL,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`balance_id`),
    UNIQUE KEY `enrollment_id` (`enrollment_id`),
    KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exams table
CREATE TABLE IF NOT EXISTS `wp_mtti_exams` (
    `exam_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `unit_id` bigint(20) NULL,
    `exam_name` varchar(100) NOT NULL,
    `exam_type` varchar(50) NOT NULL,
    `exam_date` date NOT NULL,
    `duration_minutes` int NOT NULL,
    `max_score` decimal(10,2) NOT NULL,
    `pass_mark` decimal(10,2) NOT NULL,
    `description` text NULL,
    `status` varchar(20) DEFAULT 'Scheduled',
    `created_by` bigint(20) UNSIGNED NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`exam_id`),
    KEY `course_id` (`course_id`),
    KEY `unit_id` (`unit_id`),
    KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exam Results table
CREATE TABLE IF NOT EXISTS `wp_mtti_exam_results` (
    `result_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `exam_id` bigint(20) NOT NULL,
    `unit_id` bigint(20) NULL,
    `student_id` bigint(20) NOT NULL,
    `score` decimal(10,2) NOT NULL,
    `percentage` decimal(5,2) NOT NULL,
    `grade` varchar(5) NULL,
    `passed` tinyint(1) DEFAULT 0,
    `remarks` text NULL,
    `result_date` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`result_id`),
    UNIQUE KEY `exam_student` (`exam_id`, `student_id`),
    KEY `exam_id` (`exam_id`),
    KEY `unit_id` (`unit_id`),
    KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unit Enrollments table
CREATE TABLE IF NOT EXISTS `wp_mtti_unit_enrollments` (
    `enrollment_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `unit_id` bigint(20) NOT NULL,
    `student_id` bigint(20) NOT NULL,
    `enrollment_date` date NOT NULL,
    `status` varchar(20) DEFAULT 'Active',
    `notes` text NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`enrollment_id`),
    UNIQUE KEY `unit_student` (`unit_id`, `student_id`),
    KEY `unit_id` (`unit_id`),
    KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unit Results table
CREATE TABLE IF NOT EXISTS `wp_mtti_unit_results` (
    `result_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `unit_id` bigint(20) NOT NULL,
    `student_id` bigint(20) NOT NULL,
    `score` decimal(10,2) NOT NULL,
    `percentage` decimal(5,2) NOT NULL,
    `grade` varchar(5) NULL,
    `passed` tinyint(1) DEFAULT 0,
    `remarks` text NULL,
    `result_date` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`result_id`),
    UNIQUE KEY `unit_student` (`unit_id`, `student_id`),
    KEY `unit_id` (`unit_id`),
    KEY `student_id` (`student_id`),
    KEY `grade` (`grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notices table
CREATE TABLE IF NOT EXISTS `wp_mtti_notices` (
    `notice_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `title` varchar(200) NOT NULL,
    `content` text NOT NULL,
    `category` varchar(50) NOT NULL,
    `priority` varchar(20) DEFAULT 'Normal',
    `target_audience` varchar(50) DEFAULT 'All',
    `expiry_date` date NULL,
    `status` varchar(20) DEFAULT 'Active',
    `created_by` bigint(20) UNSIGNED NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`notice_id`),
    KEY `created_by` (`created_by`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certificates table
CREATE TABLE IF NOT EXISTS `wp_mtti_certificates` (
    `certificate_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `certificate_number` varchar(100) NOT NULL,
    `verification_code` varchar(50) NOT NULL,
    `student_id` bigint(20) NOT NULL,
    `student_name` varchar(255) NOT NULL,
    `admission_number` varchar(50) NOT NULL,
    `course_id` bigint(20) NOT NULL,
    `course_name` varchar(255) NOT NULL,
    `course_code` varchar(50) NOT NULL,
    `grade` varchar(50) NOT NULL,
    `completion_date` date NOT NULL,
    `issue_date` date NOT NULL,
    `status` varchar(20) NOT NULL DEFAULT 'Valid',
    `notes` text DEFAULT NULL,
    `qr_code_path` varchar(255) NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`certificate_id`),
    UNIQUE KEY `certificate_number` (`certificate_number`),
    UNIQUE KEY `verification_code` (`verification_code`),
    KEY `student_id` (`student_id`),
    KEY `course_id` (`course_id`),
    KEY `status` (`status`),
    KEY `issue_date` (`issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materials table
CREATE TABLE IF NOT EXISTS `wp_mtti_materials` (
    `material_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `unit_id` bigint(20) NULL,
    `title` varchar(200) NOT NULL,
    `description` text NULL,
    `file_url` varchar(500) NOT NULL,
    `file_type` varchar(20) NULL,
    `file_size` bigint(20) NULL,
    `download_count` int(11) DEFAULT 0,
    `status` varchar(20) DEFAULT 'Active',
    `uploaded_by` bigint(20) UNSIGNED NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`material_id`),
    KEY `course_id` (`course_id`),
    KEY `unit_id` (`unit_id`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lessons table
CREATE TABLE IF NOT EXISTS `wp_mtti_lessons` (
    `lesson_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `course_id` bigint(20) NOT NULL,
    `unit_id` bigint(20) NULL,
    `title` varchar(200) NOT NULL,
    `description` text NULL,
    `content` longtext NULL,
    `video_url` varchar(500) NULL,
    `duration_minutes` int(11) NULL,
    `order_number` int(11) DEFAULT 0,
    `status` varchar(20) DEFAULT 'Active',
    `created_by` bigint(20) UNSIGNED NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`lesson_id`),
    KEY `course_id` (`course_id`),
    KEY `unit_id` (`unit_id`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERT SAMPLE COURSES (Optional - Remove if you have existing courses)
-- =====================================================
INSERT INTO `wp_mtti_courses` (`course_code`, `course_name`, `description`, `category`, `duration_weeks`, `fee`, `status`) VALUES
('COE-01', 'Computer Essentials', 'Basic computer skills including MS Office, Internet, and Email', 'Computer', 4, 1500.00, 'Active'),
('COA-01', 'Computer Applications', 'Comprehensive computer applications training', 'Computer', 8, 4000.00, 'Active'),
('WDD-01', 'Web Design and Development', 'HTML, CSS, JavaScript, and WordPress development', 'Technology', 12, 10000.00, 'Active'),
('ARI-01', 'Artificial Intelligence', 'Introduction to AI, Machine Learning basics', 'Technology', 8, 5000.00, 'Active'),
('PRP-01', 'Programming/Coding Principles', 'Python, JavaScript, and programming fundamentals', 'Technology', 12, 10000.00, 'Active'),
('CCI-01', 'CCTV Installation', 'Security camera installation and configuration', 'Technical', 6, 18000.00, 'Active'),
('CMP-01', 'Computer Repair', 'Hardware troubleshooting and repair', 'Technical', 8, 12000.00, 'Active'),
('CYS-01', 'Cyber Security', 'Network security and ethical hacking basics', 'Technology', 8, 12000.00, 'Active'),
('DGM-01', 'Digital Marketing', 'Social media marketing, SEO, and online advertising', 'Business', 6, 8000.00, 'Active'),
('GRD-01', 'Graphic Design', 'Adobe Photoshop, Illustrator, and design principles', 'Creative', 8, 10000.00, 'Active'),
('MBR-01', 'Mobile Phone Repair', 'Smartphone hardware and software repair', 'Technical', 6, 15000.00, 'Active');

COMMIT;

-- =====================================================
-- VERIFICATION: Check if tables were created
-- =====================================================
-- Run this query to verify: SHOW TABLES LIKE 'wp_mtti_%';

-- =====================================================
-- RECYCLE BIN / SOFT DELETE TABLE (v7.2.0)
-- =====================================================
CREATE TABLE IF NOT EXISTS `wp_mtti_trash` (
    `trash_id` bigint(20) NOT NULL AUTO_INCREMENT,
    `record_type` varchar(50) NOT NULL COMMENT 'student, payment, lesson, material, assignment, unit, notice, staff, scheme_week',
    `record_id` bigint(20) NOT NULL COMMENT 'Original primary key ID',
    `record_label` varchar(255) NOT NULL COMMENT 'Human-readable label',
    `record_data` longtext NOT NULL COMMENT 'Full JSON of the deleted record',
    `related_data` longtext NULL COMMENT 'JSON of related records deleted together',
    `deleted_by` bigint(20) UNSIGNED NOT NULL COMMENT 'WP user ID who deleted',
    `deleted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` datetime NULL COMMENT 'Auto-purge date (NULL = keep forever)',
    PRIMARY KEY (`trash_id`),
    KEY `record_type` (`record_type`),
    KEY `deleted_at` (`deleted_at`),
    KEY `expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
