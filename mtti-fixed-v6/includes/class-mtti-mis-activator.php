<?php
/**
 * Fired during plugin activation
 */
class MTTI_MIS_Activator {

    public static function activate() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_prefix = $wpdb->prefix . 'mtti_';
        
        // Students table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}students (
            student_id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NULL,
            admission_number varchar(20) NOT NULL,
            course_id bigint(20) NULL,
            id_number varchar(20) NULL,
            date_of_birth date NULL,
            gender varchar(10) NULL,
            address text NULL,
            county varchar(50) NULL,
            emergency_contact varchar(100) NULL,
            emergency_phone varchar(20) NULL,
            enrollment_date date NOT NULL,
            status varchar(20) DEFAULT 'Active',
            photo_url varchar(255) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (student_id),
            UNIQUE KEY admission_number (admission_number),
            KEY user_id (user_id),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        // Staff table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}staff (
            staff_id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NULL,
            staff_number varchar(20) NOT NULL,
            id_number varchar(20) NULL,
            date_of_birth date NULL,
            gender varchar(10) NULL,
            address text NULL,
            department varchar(100) NULL,
            position varchar(100) NULL,
            specialization text NULL,
            hire_date date NOT NULL,
            status varchar(20) DEFAULT 'Active',
            photo_url varchar(255) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (staff_id),
            UNIQUE KEY staff_number (staff_number),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // Courses table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}courses (
            course_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_code varchar(20) NOT NULL,
            course_name varchar(200) NOT NULL,
            description text NULL,
            category varchar(50) NOT NULL,
            duration_weeks int(11) NOT NULL,
            fee decimal(10,2) NOT NULL,
            max_capacity int(11) DEFAULT 20,
            prerequisites text NULL,
            learning_outcomes text NULL,
            status varchar(20) DEFAULT 'Active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (course_id),
            UNIQUE KEY course_code (course_code)
        ) $charset_collate;";
        
        // Course Units table (v3.6.0) - Individual units/modules within courses
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}course_units (
            unit_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            unit_code varchar(20) NOT NULL,
            unit_name varchar(200) NOT NULL,
            description text NULL,
            order_number int(11) DEFAULT 0,
            duration_hours int(11) NULL,
            credit_hours decimal(5,2) NULL,
            status varchar(20) DEFAULT 'Active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (unit_id),
            UNIQUE KEY unit_code (unit_code),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        // Enrollments table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}enrollments (
            enrollment_id bigint(20) NOT NULL AUTO_INCREMENT,
            student_id bigint(20) NOT NULL,
            course_id bigint(20) NOT NULL,
            staff_id bigint(20) NULL,
            batch_number varchar(20) NULL,
            enrollment_date date NOT NULL,
            start_date date NOT NULL,
            expected_end_date date NOT NULL,
            actual_end_date date NULL,
            status varchar(20) DEFAULT 'Enrolled',
            final_grade varchar(5) NULL,
            certificate_issued tinyint(1) DEFAULT 0,
            certificate_number varchar(50) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (enrollment_id),
            KEY student_id (student_id),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        // Attendance table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}attendance (
            attendance_id bigint(20) NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) NOT NULL,
            date date NOT NULL,
            status varchar(20) NOT NULL,
            time_in time NULL,
            time_out time NULL,
            notes text NULL,
            marked_by bigint(20) UNSIGNED NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (attendance_id),
            KEY enrollment_id (enrollment_id),
            KEY date (date)
        ) $charset_collate;";
        
        // Assessments table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}assessments (
            assessment_id bigint(20) NOT NULL AUTO_INCREMENT,
            enrollment_id bigint(20) NOT NULL,
            assessment_type varchar(50) NOT NULL,
            assessment_name varchar(200) NOT NULL,
            max_score decimal(5,2) NOT NULL,
            score_obtained decimal(5,2) NULL,
            percentage decimal(5,2) NULL,
            grade varchar(5) NULL,
            assessment_date date NOT NULL,
            remarks text NULL,
            assessed_by bigint(20) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (assessment_id),
            KEY enrollment_id (enrollment_id)
        ) $charset_collate;";
        
        // Payments table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}payments (
            payment_id bigint(20) NOT NULL AUTO_INCREMENT,
            student_id bigint(20) NOT NULL,
            enrollment_id bigint(20) NULL,
            gross_amount decimal(10,2) DEFAULT 0.00,
            discount decimal(10,2) DEFAULT 0.00,
            amount decimal(10,2) NOT NULL,
            payment_method varchar(50) NOT NULL,
            transaction_reference varchar(100) NULL,
            payment_date date NOT NULL,
            payment_for varchar(200) NULL,
            receipt_number varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'Completed',
            received_by bigint(20) UNSIGNED NULL,
            notes text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (payment_id),
            UNIQUE KEY receipt_number (receipt_number),
            KEY student_id (student_id)
        ) $charset_collate;";
        
        // Assignments table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}assignments (
            assignment_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            staff_id bigint(20) NOT NULL,
            title varchar(200) NOT NULL,
            description text NULL,
            file_path varchar(255) NULL,
            file_name varchar(255) NULL,
            due_date datetime NOT NULL,
            max_score decimal(5,2) DEFAULT 100.00,
            status varchar(20) DEFAULT 'Active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (assignment_id),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        // Assignment submissions table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}assignment_submissions (
            submission_id bigint(20) NOT NULL AUTO_INCREMENT,
            assignment_id bigint(20) NOT NULL,
            student_id bigint(20) NOT NULL,
            submission_text text NULL,
            file_path varchar(255) NULL,
            file_name varchar(255) NULL,
            score decimal(5,2) NULL,
            feedback text NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            graded_at datetime NULL,
            graded_by bigint(20) NULL,
            status varchar(20) DEFAULT 'Submitted',
            PRIMARY KEY  (submission_id),
            KEY assignment_id (assignment_id),
            KEY student_id (student_id)
        ) $charset_collate;";
        
        // Live classes table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}live_classes (
            class_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            staff_id bigint(20) NOT NULL,
            batch_number varchar(20) NULL,
            title varchar(200) NOT NULL,
            description text NULL,
            meeting_link varchar(500) NULL,
            meeting_platform varchar(50) DEFAULT 'Zoom',
            meeting_id varchar(100) NULL,
            meeting_password varchar(100) NULL,
            scheduled_date datetime NOT NULL,
            duration_minutes int(11) DEFAULT 60,
            status varchar(20) DEFAULT 'Scheduled',
            recording_link varchar(500) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (class_id),
            KEY course_id (course_id),
            KEY scheduled_date (scheduled_date)
        ) $charset_collate;";
        
        // Student balances table (without net_fee - balance calculated as total_fee - discount_amount - total_paid)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}student_balances (
            balance_id bigint(20) NOT NULL AUTO_INCREMENT,
            student_id bigint(20) NOT NULL,
            enrollment_id bigint(20) NOT NULL,
            total_fee decimal(10,2) NOT NULL,
            discount_amount decimal(10,2) DEFAULT 0.00,
            total_paid decimal(10,2) DEFAULT 0.00,
            balance decimal(10,2) NOT NULL,
            last_payment_date date NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (balance_id),
            UNIQUE KEY enrollment_id (enrollment_id),
            KEY student_id (student_id)
        ) $charset_collate;";
        
        // Exams table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}exams (
            exam_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            unit_id bigint(20) NULL,
            exam_name varchar(100) NOT NULL,
            exam_type varchar(50) NOT NULL,
            exam_date date NOT NULL,
            duration_minutes int NOT NULL,
            max_score decimal(10,2) NOT NULL,
            pass_mark decimal(10,2) NOT NULL,
            description text NULL,
            status varchar(20) DEFAULT 'Scheduled',
            created_by bigint(20) UNSIGNED NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (exam_id),
            KEY course_id (course_id),
            KEY unit_id (unit_id),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        // Exam Results table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}exam_results (
            result_id bigint(20) NOT NULL AUTO_INCREMENT,
            exam_id bigint(20) NOT NULL,
            unit_id bigint(20) NULL,
            student_id bigint(20) NOT NULL,
            score decimal(10,2) NOT NULL,
            percentage decimal(5,2) NOT NULL,
            grade varchar(5) NULL,
            passed tinyint(1) DEFAULT 0,
            remarks text NULL,
            result_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (result_id),
            UNIQUE KEY exam_student (exam_id, student_id),
            KEY exam_id (exam_id),
            KEY unit_id (unit_id),
            KEY student_id (student_id)
        ) $charset_collate;";
        
        // Unit Enrollments table (v3.9.8) - For students enrolled in specific units (not necessarily the full course)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}unit_enrollments (
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
        
        // Unit Results table (v3.9.0) - For course unit marks/grades that appear on transcripts
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}unit_results (
            result_id bigint(20) NOT NULL AUTO_INCREMENT,
            unit_id bigint(20) NOT NULL,
            student_id bigint(20) NOT NULL,
            score decimal(10,2) NOT NULL,
            percentage decimal(5,2) NOT NULL,
            grade varchar(20) NULL,
            passed tinyint(1) DEFAULT 0,
            remarks text NULL,
            result_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (result_id),
            UNIQUE KEY unit_student (unit_id, student_id),
            KEY unit_id (unit_id),
            KEY student_id (student_id),
            KEY grade (grade)
        ) $charset_collate;";
        
        // Notices table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}notices (
            notice_id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            content text NOT NULL,
            category varchar(50) NOT NULL,
            priority varchar(20) DEFAULT 'Normal',
            target_audience varchar(50) DEFAULT 'All',
            expiry_date date NULL,
            status varchar(20) DEFAULT 'Active',
            created_by bigint(20) UNSIGNED NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (notice_id),
            KEY created_by (created_by),
            KEY status (status)
        ) $charset_collate;";
        
        // Certificates table (v3.8.0) - For certificate verification system
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}certificates (
            certificate_id bigint(20) NOT NULL AUTO_INCREMENT,
            certificate_number varchar(100) NOT NULL,
            verification_code varchar(50) NOT NULL,
            student_id bigint(20) NOT NULL,
            student_name varchar(255) NOT NULL,
            admission_number varchar(50) NOT NULL,
            course_id bigint(20) NOT NULL,
            course_name varchar(255) NOT NULL,
            course_code varchar(50) NOT NULL,
            grade varchar(50) NOT NULL,
            completion_date date NOT NULL,
            issue_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'Valid',
            notes text DEFAULT NULL,
            qr_code_path varchar(255) NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (certificate_id),
            UNIQUE KEY certificate_number (certificate_number),
            UNIQUE KEY verification_code (verification_code),
            KEY student_id (student_id),
            KEY course_id (course_id),
            KEY status (status),
            KEY issue_date (issue_date)
        ) $charset_collate;";
        
        // Course Materials table (v4.2.0) - For downloadable course materials
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}materials (
            material_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            unit_id bigint(20) NULL,
            title varchar(200) NOT NULL,
            description text NULL,
            file_url varchar(500) NOT NULL,
            file_type varchar(20) NULL,
            file_size bigint(20) NULL,
            download_count int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'Active',
            uploaded_by bigint(20) UNSIGNED NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (material_id),
            KEY course_id (course_id),
            KEY unit_id (unit_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Lessons table (v4.3.0) - For teachers to upload course lessons
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}lessons (
            lesson_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            unit_id bigint(20) NULL,
            title varchar(200) NOT NULL,
            description text NULL,
            content longtext NULL,
            content_type varchar(20) DEFAULT 'text',
            content_url varchar(500) NULL,
            file_size bigint(20) NULL,
            duration_minutes int(11) NULL,
            order_number int(11) DEFAULT 0,
            is_free_preview tinyint(1) DEFAULT 0,
            view_count int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'Published',
            created_by bigint(20) UNSIGNED NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (lesson_id),
            KEY course_id (course_id),
            KEY unit_id (unit_id),
            KEY status (status),
            KEY order_number (order_number)
        ) $charset_collate;";
        
        // Payment Audit Trail table (v4.2.5) - Tracks all payment changes
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}payment_audit (
            audit_id bigint(20) NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) NOT NULL,
            action varchar(20) NOT NULL,
            student_id bigint(20) NULL,
            student_name varchar(255) NULL,
            receipt_number varchar(50) NULL,
            old_amount decimal(10,2) NULL,
            new_amount decimal(10,2) NULL,
            old_data text NULL,
            new_data text NULL,
            changed_by bigint(20) UNSIGNED NOT NULL,
            changed_by_name varchar(255) NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (audit_id),
            KEY payment_id (payment_id),
            KEY action (action),
            KEY changed_by (changed_by),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // v5.0.0 — Notifications table (in-app notification centre)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}notifications (
            notification_id bigint(20) NOT NULL AUTO_INCREMENT,
            student_id bigint(20) NOT NULL,
            type varchar(20) DEFAULT 'info',
            title varchar(200) NOT NULL,
            message text NOT NULL,
            is_read tinyint(1) DEFAULT 0,
            link varchar(500) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (notification_id),
            KEY student_id (student_id),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // v5.0.0 — Discussions table (study group / course Q&A)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}discussions (
            discussion_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            student_id bigint(20) NOT NULL,
            parent_id bigint(20) NULL,
            message text NOT NULL,
            is_verified tinyint(1) DEFAULT 0,
            is_pinned tinyint(1) DEFAULT 0,
            upvotes int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'Published',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (discussion_id),
            KEY course_id (course_id),
            KEY student_id (student_id),
            KEY parent_id (parent_id)
        ) $charset_collate;";
        
        // v5.0.0 — Leaderboard settings (opt-in preference per student)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}leaderboard_settings (
            setting_id bigint(20) NOT NULL AUTO_INCREMENT,
            student_id bigint(20) NOT NULL,
            show_on_leaderboard tinyint(1) DEFAULT 1,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (setting_id),
            UNIQUE KEY student_id (student_id)
        ) $charset_collate;";
        
        // Scheme of Work table (v7.0.0)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}scheme_of_work (
            week_id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            week_number int(11) NOT NULL,
            unit_id bigint(20) NULL,
            topic varchar(300) NOT NULL,
            objectives text NULL,
            teaching_method varchar(100) NULL,
            resources text NULL,
            duration_hours decimal(4,1) DEFAULT 3.0,
            status varchar(20) DEFAULT 'Pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (week_id),
            KEY course_id (course_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Session Logs table (v7.0.0) - Lecturer clock in/out
        // Course-Teacher assignments — one teacher assigned directly to a course
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}course_teachers (
            id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            course_id  bigint(20) unsigned NOT NULL,
            staff_id   bigint(20) unsigned NOT NULL,
            assigned_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY course_id (course_id),
            KEY staff_id (staff_id)
        ) $charset_collate;";

        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}session_logs (
            session_id bigint(20) NOT NULL AUTO_INCREMENT,
            staff_id bigint(20) NOT NULL,
            course_id bigint(20) NOT NULL,
            topic varchar(300) NOT NULL,
            week_id bigint(20) NULL,
            planned_hours decimal(4,1) DEFAULT 2.0,
            duration_minutes int(11) NULL,
            notes text NULL,
            clock_in datetime NOT NULL,
            clock_out datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (session_id),
            KEY staff_id (staff_id),
            KEY course_id (course_id),
            KEY clock_in (clock_in)
        ) $charset_collate;";

        // Lesson views table — tracks which students have opened each interactive lesson (for progress)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}lesson_views (
            view_id    bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lesson_id  bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            viewed_at  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (view_id),
            UNIQUE KEY lesson_student (lesson_id, student_id),
            KEY student_id (student_id)
        ) $charset_collate;";

        // Quiz Attempts table (v6.0) — tracks practice quiz scores per student per lesson
        $sql[] = "CREATE TABLE IF NOT EXISTS {$table_prefix}quiz_attempts (
            attempt_id  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lesson_id   bigint(20) unsigned NOT NULL,
            student_id  bigint(20) unsigned NOT NULL,
            score       decimal(5,2) NOT NULL DEFAULT 0,
            total       decimal(5,2) NOT NULL DEFAULT 0,
            percent     decimal(5,2) NOT NULL DEFAULT 0,
            attempted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (attempt_id),
            KEY lesson_id  (lesson_id),
            KEY student_id (student_id),
            KEY lesson_student (lesson_id, student_id)
        ) $charset_collate;";

        // Execute all table creation
        foreach ($sql as $query) {
            dbDelta($query);
        }
        
        // Set plugin version and DB version
        update_option('mtti_mis_version', MTTI_MIS_VERSION);
        update_option('mtti_mis_db_version', '4.2.5');
        
        // Create WordPress roles
        self::create_roles();
        
        // Create upload directory
        $upload_dir = wp_upload_dir();
        $mtti_upload_dir = $upload_dir['basedir'] . '/mtti-mis';
        if (!file_exists($mtti_upload_dir)) {
            wp_mkdir_p($mtti_upload_dir);
            wp_mkdir_p($mtti_upload_dir . '/assignments');
            wp_mkdir_p($mtti_upload_dir . '/certificates');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    private static function create_roles() {
        // Define all MTTI capabilities
        $all_caps = array(
            'manage_mtti',
            'manage_students',
            'view_students',
            'manage_courses',
            'manage_enrollments',
            'manage_payments',
            'manage_staff',
            'manage_attendance',
            'manage_assessments',
            'manage_assignments',
            'manage_live_classes',
            'manage_certificates',
            'manage_notices',
            'manage_users_mtti',
            'view_own_data',
            'submit_assignments',
            'view_grades',
            'view_certificates'
        );
        
        // Add ALL MTTI capabilities to Administrator
        $admin = get_role('administrator');
        if ($admin) {
            foreach ($all_caps as $cap) {
                $admin->add_cap($cap);
            }
        }
        
        // Remove old roles first (to update them)
        remove_role('mtti_teacher');
        remove_role('mtti_student');
        remove_role('mtti_registrar');
        remove_role('mtti_accountant');
        remove_role('mtti_systems_admin');
        
        // Create MTTI Systems Admin role (can manage users but not full admin)
        add_role('mtti_systems_admin', 'MTTI Systems Admin', array(
            'read' => true,
            'manage_mtti' => true,
            'manage_students' => true,
            'view_students' => true,
            'manage_courses' => true,
            'manage_enrollments' => true,
            'manage_payments' => true,
            'manage_attendance' => true,
            'manage_assessments' => true,
            'manage_assignments' => true,
            'manage_live_classes' => true,
            'manage_certificates' => true,
            'manage_notices' => true,
            'manage_users_mtti' => true,
            // WordPress user management caps
            'list_users' => true,
            'create_users' => true,
            'edit_users' => true,
            'delete_users' => true,
            'promote_users' => true
        ));
        
        // Create MTTI Teacher role
        add_role('mtti_teacher', 'MTTI Teacher', array(
            'read' => true,
            'manage_mtti' => true,
            'view_students' => true,
            'manage_students' => true,
            'manage_courses' => true,
            'manage_attendance' => true,
            'manage_assessments' => true,
            'manage_assignments' => true,
            'manage_live_classes' => true,
            'manage_notices' => true
        ));
        
        // Create MTTI Registrar role (can manage students and enrollments)
        add_role('mtti_registrar', 'MTTI Registrar', array(
            'read' => true,
            'manage_mtti' => true,
            'manage_students' => true,
            'view_students' => true,
            'manage_courses' => true,
            'manage_enrollments' => true,
            'manage_certificates' => true,
            'manage_notices' => true
        ));
        
        // Create MTTI Accountant role (can manage payments AND add students)
        add_role('mtti_accountant', 'MTTI Accountant', array(
            'read' => true,
            'manage_mtti' => true,
            'manage_students' => true,
            'view_students' => true,
            'manage_payments' => true,
            'manage_enrollments' => true
        ));
        
        // Create MTTI Student role
        add_role('mtti_student', 'MTTI Student', array(
            'read' => true,
            'view_own_data' => true,
            'submit_assignments' => true,
            'view_grades' => true,
            'view_certificates' => true
        ));
    }
}
