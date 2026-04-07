<?php
/**
 * Database Upgrade Handler
 * Handles database schema updates when plugin is updated
 */
class MTTI_MIS_Upgrader {
    
    /**
     * Run database upgrades
     */
    public static function upgrade() {
        $current_version = get_option('mtti_mis_db_version', '1.0.0');
        
        // Check if we need to upgrade to 3.1
        if (version_compare($current_version, '3.1.0', '<')) {
            self::upgrade_to_3_1();
        }
        
        // Check if we need to upgrade to 3.9.8 (unit enrollments)
        if (version_compare($current_version, '3.9.8', '<')) {
            self::upgrade_to_3_9_8();
        }
        
        // Check if we need to upgrade to 3.9.9 (ensure discount_amount column exists)
        if (version_compare($current_version, '3.9.9', '<')) {
            self::upgrade_to_3_9_9();
        }
        
        // Fix grade column in unit_results - was varchar(5), too small for DISTINCTION/CREDIT
        if (version_compare($current_version, '4.9.0', '<')) {
            self::upgrade_to_4_9_0();
        }

        // Create lesson_views table for per-student progress tracking
        if (version_compare($current_version, '7.1.0', '<')) {
            self::upgrade_to_7_1_0();
        }

        // Back-fill student_balances rows for enrollments that have none
        if (version_compare($current_version, '7.2.0', '<')) {
            self::upgrade_to_7_2_0();
        }
        
        // Update database version
        update_option('mtti_mis_db_version', '7.2.0');
    }
    
    /**
     * Upgrade database to version 3.1
     * Adds discount and gross_amount columns to payments table
     */
    private static function upgrade_to_3_1() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtti_payments';
        
        // Check if columns already exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
        $column_names = array();
        foreach ($columns as $column) {
            $column_names[] = $column->Field;
        }
        
        // Add gross_amount column if it doesn't exist
        if (!in_array('gross_amount', $column_names)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN gross_amount decimal(10,2) DEFAULT 0.00 AFTER enrollment_id");
            
            // Set gross_amount to current amount for existing records
            $wpdb->query("UPDATE {$table_name} SET gross_amount = amount WHERE gross_amount = 0");
        }
        
        // Add discount column if it doesn't exist
        if (!in_array('discount', $column_names)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN discount decimal(10,2) DEFAULT 0.00 AFTER gross_amount");
        }
        
        // Log the upgrade
        error_log('MTTI MIS: Database upgraded to version 3.1 - Added discount and gross_amount columns to payments table');
    }
    
    /**
     * Upgrade database to version 3.9.8
     * Adds unit_enrollments table for students who want to take individual units
     */
    private static function upgrade_to_3_9_8() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'mtti_unit_enrollments';
        
        // Check if table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $sql = "CREATE TABLE {$table_name} (
                enrollment_id bigint(20) NOT NULL AUTO_INCREMENT,
                unit_id bigint(20) NOT NULL,
                student_id bigint(20) NOT NULL,
                enrollment_date date NOT NULL,
                status varchar(20) DEFAULT 'Active',
                notes text NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (enrollment_id),
                UNIQUE KEY unit_student (unit_id, student_id),
                KEY unit_id (unit_id),
                KEY student_id (student_id)
            ) $charset_collate;";
            
            dbDelta($sql);
            
            // Log the upgrade
            error_log('MTTI MIS: Database upgraded to version 3.9.8 - Added unit_enrollments table for individual unit registrations');
        }
    }
    
    /**
     * Upgrade database to version 3.9.9
     * Ensures discount_amount column exists in student_balances table
     */
    private static function upgrade_to_3_9_9() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtti_student_balances';
        
        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            error_log('MTTI MIS: student_balances table does not exist - will be created on next activation');
            return;
        }
        
        // Check existing columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
        $column_names = array();
        foreach ($columns as $column) {
            $column_names[] = $column->Field;
        }
        
        // Add discount_amount column if it doesn't exist
        if (!in_array('discount_amount', $column_names)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN discount_amount decimal(10,2) DEFAULT 0.00 AFTER total_fee");
            error_log('MTTI MIS: Added discount_amount column to student_balances table');
        }
        
        // Recalculate balances for all records: balance = total_fee - discount_amount - total_paid
        $wpdb->query("UPDATE {$table_name} SET balance = total_fee - COALESCE(discount_amount, 0) - total_paid");
        
        error_log('MTTI MIS: Database upgraded to version 3.9.9 - Ensured discount_amount column exists');
    }
    
    /**
     * Upgrade to version 4.9.0
     * Fix grade column in unit_results: varchar(5) is too small for DISTINCTION (11 chars)
     * This was silently causing all marks saves to fail due to data truncation errors
     */
    private static function upgrade_to_4_9_0() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mtti_unit_results';
        
        // Check the current column size
        $col = $wpdb->get_row("SHOW COLUMNS FROM {$table_name} LIKE 'grade'");
        
        if ($col) {
            // Alter to varchar(20) to fit DISTINCTION, CREDIT, PASS, REFER
            $wpdb->query("ALTER TABLE {$table_name} MODIFY COLUMN grade varchar(20) NULL");
            error_log('MTTI MIS: Database upgraded to version 4.9.0 - Fixed grade column in unit_results (varchar(5) → varchar(20))');
        }
    }

    /**
     * Upgrade to 7.1.0 — create lesson_views table for per-student progress tracking
     */
    private static function upgrade_to_7_1_0() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'mtti_lesson_views';
        $sql = "CREATE TABLE {$table} (
            view_id      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lesson_id    bigint(20) unsigned NOT NULL,
            student_id   bigint(20) unsigned NOT NULL,
            viewed_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (view_id),
            UNIQUE KEY lesson_student (lesson_id, student_id),
            KEY student_id (student_id)
        ) {$charset};";
        dbDelta($sql);
        error_log('MTTI MIS: Database upgraded to version 7.1.0 - Created mtti_lesson_views table');
    }

    /**
     * Upgrade to 7.2.0
     *
     * Back-fills a student_balances row for every enrollment that has none.
     *
     * Root cause this fixes:
     *   Students enrolled before the student_balances table was introduced have an
     *   enrollment row but NO balance row.  A LEFT JOIN on student_balances returns
     *   NULL for every column, so COALESCE(b.discount_amount, 0) always returns 0 —
     *   discount never appears on the admission letter no matter how the query is written.
     *
     * For each orphaned enrollment we:
     *   1. Take the course fee from courses.fee (locked-in fee).
     *   2. Sum any discount already recorded in payments.discount for that enrollment.
     *   3. Sum all completed payments.amount for that enrollment.
     *   4. Insert a properly calculated student_balances row.
     */
    private static function upgrade_to_7_2_0() {
        global $wpdb;

        $balances_table    = $wpdb->prefix . 'mtti_student_balances';
        $enrollments_table = $wpdb->prefix . 'mtti_enrollments';
        $courses_table     = $wpdb->prefix . 'mtti_courses';
        $payments_table    = $wpdb->prefix . 'mtti_payments';

        // Find every active enrollment that has no student_balances row
        $orphaned = $wpdb->get_results(
            "SELECT e.enrollment_id, e.student_id, e.course_id, c.fee
             FROM {$enrollments_table} e
             INNER JOIN {$courses_table} c ON c.course_id = e.course_id
             LEFT  JOIN {$balances_table} b ON b.enrollment_id = e.enrollment_id
             WHERE b.enrollment_id IS NULL
               AND e.status IN ('Active','Enrolled','In Progress','Completed')"
        );

        if (empty($orphaned)) {
            update_option('mtti_mis_db_version', '7.2.0');
            return;
        }

        $inserted = 0;
        foreach ($orphaned as $row) {
            $total_fee = floatval($row->fee);

            // Pull any discount recorded in payments for this enrollment
            $discount = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(discount), 0) FROM {$payments_table}
                 WHERE enrollment_id = %d AND status = 'Completed'",
                $row->enrollment_id
            )));

            // Also try unlinked payments (enrollment_id IS NULL) tied to student
            // as a best-effort — only apply if no linked payment discount found
            if ($discount == 0) {
                $discount = floatval($wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(discount), 0) FROM {$payments_table}
                     WHERE student_id = %d
                       AND (enrollment_id IS NULL OR enrollment_id = 0)
                       AND status = 'Completed'",
                    $row->student_id
                )));
            }

            // Sum all completed payments for this enrollment
            $total_paid = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table}
                 WHERE enrollment_id = %d AND status = 'Completed'",
                $row->enrollment_id
            )));

            $balance = max(0, $total_fee - $discount - $total_paid);

            $wpdb->insert(
                $balances_table,
                array(
                    'student_id'      => $row->student_id,
                    'enrollment_id'   => $row->enrollment_id,
                    'total_fee'       => $total_fee,
                    'discount_amount' => $discount,
                    'total_paid'      => $total_paid,
                    'balance'         => $balance,
                ),
                array('%d', '%d', '%f', '%f', '%f', '%f')
            );
            $inserted++;
        }

        error_log("MTTI MIS: Database upgraded to version 7.2.0 - Back-filled {$inserted} missing student_balances rows");
    }
}
