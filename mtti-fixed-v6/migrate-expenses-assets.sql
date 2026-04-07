-- ============================================================
-- MTTI Data Migration: wpcu_ → wp_ tables
-- Run in phpMyAdmin on database: uvyzhdzt_wp265
-- Run AFTER uploading the new plugin zip
-- ============================================================

-- 1. Create wp_mtti_expenses (if not already created by plugin)
CREATE TABLE IF NOT EXISTS `wp_mtti_expenses` (
    expense_id     BIGINT NOT NULL AUTO_INCREMENT,
    expense_date   DATE NOT NULL,
    category       VARCHAR(100) NOT NULL,
    description    VARCHAR(300) NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    paid_to        VARCHAR(200) DEFAULT NULL,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
    reference      VARCHAR(100) DEFAULT NULL,
    recorded_by    BIGINT UNSIGNED DEFAULT NULL,
    attachment     VARCHAR(300) DEFAULT NULL,
    notes          TEXT DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (expense_id),
    KEY expense_date (expense_date),
    KEY category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create wp_mtti_assets (if not already created by plugin)
CREATE TABLE IF NOT EXISTS `wp_mtti_assets` (
    asset_id         BIGINT NOT NULL AUTO_INCREMENT,
    asset_code       VARCHAR(50) NOT NULL,
    name             VARCHAR(200) NOT NULL,
    category         VARCHAR(100) NOT NULL,
    description      TEXT DEFAULT NULL,
    serial_number    VARCHAR(100) DEFAULT NULL,
    purchase_date    DATE DEFAULT NULL,
    purchase_price   DECIMAL(10,2) DEFAULT 0.00,
    supplier         VARCHAR(200) DEFAULT NULL,
    location         VARCHAR(200) DEFAULT NULL,
    assigned_to      VARCHAR(200) DEFAULT NULL,
    condition_status VARCHAR(50) NOT NULL DEFAULT 'Good',
    warranty_expiry  DATE DEFAULT NULL,
    notes            TEXT DEFAULT NULL,
    photo            VARCHAR(300) DEFAULT NULL,
    recorded_by      BIGINT UNSIGNED DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id),
    UNIQUE KEY asset_code (asset_code),
    KEY category (category),
    KEY condition_status (condition_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Migrate expenses (10 rows)
INSERT INTO `wp_mtti_expenses`
    (expense_date, category, description, amount, paid_to, payment_method, reference, recorded_by, attachment, notes, created_at)
SELECT
    expense_date, category, description, amount, paid_to, payment_method, reference, recorded_by, attachment, notes, created_at
FROM `wpcu_mtti_expenses`;

-- 4. Migrate assets (121 rows)
INSERT INTO `wp_mtti_assets`
    (asset_code, name, category, description, serial_number, purchase_date, purchase_price, supplier, location, assigned_to, condition_status, warranty_expiry, notes, photo, recorded_by, created_at, updated_at)
SELECT
    asset_code, name, category, description, serial_number, purchase_date, purchase_price, supplier, location, assigned_to, condition_status, warranty_expiry, notes, photo, recorded_by, created_at, updated_at
FROM `wpcu_mtti_assets`;

-- 5. Verify migration
SELECT 'wp_mtti_expenses' as tbl, COUNT(*) as rows FROM wp_mtti_expenses
UNION ALL
SELECT 'wpcu_mtti_expenses', COUNT(*) FROM wpcu_mtti_expenses
UNION ALL
SELECT 'wp_mtti_assets', COUNT(*) FROM wp_mtti_assets
UNION ALL
SELECT 'wpcu_mtti_assets', COUNT(*) FROM wpcu_mtti_assets;
