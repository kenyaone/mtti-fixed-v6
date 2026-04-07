-- ============================================
-- MTTI MIS v3.8.0 Database Setup Script
-- ============================================
-- 
-- This script creates the certificates table required for
-- certificate verification functionality.
--
-- USAGE:
-- 1. Open phpMyAdmin
-- 2. Select your WordPress database
-- 3. Click "SQL" tab
-- 4. Copy and paste this entire script
-- 5. Click "Go" to execute
--
-- ============================================

-- Step 1: Check if table already exists
SELECT 'Checking for existing certificates table...' as Status;

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'Table EXISTS - Skipping creation'
        ELSE 'Table DOES NOT EXIST - Will create'
    END as TableStatus
FROM information_schema.tables 
WHERE table_schema = DATABASE() 
  AND table_name = 'wp_mtti_certificates';

-- Step 2: Create certificates table (will skip if exists)
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
  `qr_code_path` varchar(255) DEFAULT NULL,
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

SELECT 'Certificates table created successfully!' as Status;

-- Step 3: Verify table structure
SELECT 'Verifying table structure...' as Status;

DESCRIBE wp_mtti_certificates;

-- Step 4: Check table exists and show column count
SELECT 
    COUNT(*) as column_count,
    'Columns in certificates table' as description
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'wp_mtti_certificates';

-- Step 5: Show table indexes
SELECT 'Table indexes:' as Status;

SHOW INDEX FROM wp_mtti_certificates;

-- Step 6: Display success message
SELECT 
    '✓ Database setup complete!' as Status,
    'You can now activate the MTTI MIS plugin' as NextStep;

-- ============================================
-- Optional: Test Queries
-- ============================================

-- Uncomment these to test after generating your first certificate:

-- Check total certificates
-- SELECT COUNT(*) as total_certificates FROM wp_mtti_certificates;

-- View last 5 certificates
-- SELECT 
--     certificate_number,
--     student_name,
--     course_name,
--     grade,
--     status,
--     issue_date
-- FROM wp_mtti_certificates
-- ORDER BY certificate_id DESC
-- LIMIT 5;

-- Find a specific certificate
-- SELECT * FROM wp_mtti_certificates 
-- WHERE certificate_number = 'MTTI/CERT/2025/123456';

-- ============================================
-- Troubleshooting
-- ============================================

-- If you get errors, try these:

-- 1. Check database permissions:
-- SHOW GRANTS FOR CURRENT_USER();

-- 2. Check if database has other MTTI tables:
-- SHOW TABLES LIKE 'wp_mtti_%';

-- 3. Check table engine and charset:
-- SELECT 
--     table_name,
--     engine,
--     table_collation
-- FROM information_schema.tables
-- WHERE table_schema = DATABASE()
--   AND table_name = 'wp_mtti_certificates';

-- ============================================
-- Notes
-- ============================================

/*
Table Size Estimate:
- Each certificate record: ~1-2 KB
- 1,000 certificates: ~1-2 MB
- 10,000 certificates: ~10-20 MB
- Very minimal database impact

Indexes Created:
- Primary Key: certificate_id (auto-increment)
- Unique: certificate_number (prevents duplicates)
- Unique: verification_code (prevents duplicates)
- Index: student_id (fast student lookups)
- Index: course_id (fast course lookups)
- Index: status (fast status filtering)
- Index: issue_date (fast date range queries)

Character Set:
- UTF-8 (utf8mb4) for international character support
- Supports emojis and special characters
- Collation: utf8mb4_unicode_ci (case-insensitive)
*/
