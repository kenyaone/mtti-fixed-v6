<?php
/**
 * The admin-specific functionality of the plugin.
 */
class MTTI_MIS_Admin {

    private $plugin_name;
    private $version;
    private $db;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->db = MTTI_MIS_Database::get_instance();
        
        // Register AJAX handlers early (before page loads)
        add_action('wp_ajax_get_student_fee_info', array($this, 'ajax_get_student_fee_info'));
    }
    
    /**
     * AJAX handler to get student fee information for payments
     * Updated to return ALL enrollments and total balance across all courses
     * Also checks student_balances table and auto-creates missing enrollment records
     */
    public function ajax_get_student_fee_info() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'student_fee_info_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page.'));
            wp_die();
        }
        
        $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
        
        if (!$student_id) {
            wp_send_json_error(array('message' => 'Invalid student ID'));
            wp_die();
        }
        
        global $wpdb;
        $table_prefix = $wpdb->prefix . 'mtti_';
        
        // Get student
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_prefix}students WHERE student_id = %d",
            $student_id
        ));
        
        if (!$student) {
            wp_send_json_error(array('message' => 'Student not found in database.'));
            wp_die();
        }
        
        // Get ALL ACTIVE enrollments with course info for this student (exclude dropped)
        $sql = $wpdb->prepare(
            "SELECT e.*, c.course_name, c.course_code, c.fee as course_fee
             FROM {$table_prefix}enrollments e
             LEFT JOIN {$table_prefix}courses c ON e.course_id = c.course_id
             WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
             ORDER BY e.enrollment_id ASC",
            $student_id
        );
        
        $enrollments = $wpdb->get_results($sql);
        
        // If no enrollments found, check student_balances table (also filter by status)
        if (empty($enrollments)) {
            $balances = $wpdb->get_results($wpdb->prepare(
                "SELECT sb.*, e.course_id, c.course_name, c.course_code, c.fee as course_fee
                 FROM {$table_prefix}student_balances sb
                 LEFT JOIN {$table_prefix}enrollments e ON sb.enrollment_id = e.enrollment_id
                 LEFT JOIN {$table_prefix}courses c ON e.course_id = c.course_id
                 WHERE sb.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
                $student_id
            ));
            
            // Convert balances to enrollments format
            if (!empty($balances)) {
                $enrollments = array();
                foreach ($balances as $bal) {
                    $enrollment = new stdClass();
                    $enrollment->enrollment_id = $bal->enrollment_id;
                    $enrollment->student_id = $bal->student_id;
                    $enrollment->course_id = $bal->course_id;
                    $enrollment->course_name = $bal->course_name;
                    $enrollment->course_code = $bal->course_code;
                    $enrollment->course_fee = $bal->course_fee;
                    $enrollment->fee = $bal->total_fee;
                    $enrollment->total_paid_from_balance = $bal->total_paid;
                    $enrollment->balance_from_table = $bal->balance;
                    $enrollments[] = $enrollment;
                }
            }
        }
        
        // If still no enrollments but student has a course_id, auto-create enrollment
        if (empty($enrollments) && !empty($student->course_id)) {
            $course = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}courses WHERE course_id = %d",
                $student->course_id
            ));
            
            if ($course) {
                // Create enrollment record (fee is stored in student_balances, not enrollments)
                $wpdb->insert(
                    $table_prefix . 'enrollments',
                    array(
                        'student_id' => $student_id,
                        'course_id' => $student->course_id,
                        'enrollment_date' => current_time('Y-m-d'),
                        'start_date' => current_time('Y-m-d'),
                        'expected_end_date' => date('Y-m-d', strtotime('+3 months')),
                        'status' => 'Active'
                    ),
                    array('%d', '%d', '%s', '%s', '%s', '%s')
                );
                
                $enrollment_id = $wpdb->insert_id;
                
                if ($enrollment_id) {
                    // Create balance record
                    $wpdb->insert(
                        $table_prefix . 'student_balances',
                        array(
                            'student_id' => $student_id,
                            'enrollment_id' => $enrollment_id,
                            'total_fee' => $course->fee,
                            'discount_amount' => 0,
                            'total_paid' => 0,
                            'balance' => $course->fee,
                            'updated_at' => current_time('mysql')
                        ),
                        array('%d', '%d', '%f', '%f', '%f', '%f', '%s')
                    );
                    
                    // Re-fetch enrollments
                    $enrollments = $wpdb->get_results($wpdb->prepare(
                        "SELECT e.*, c.course_name, c.course_code, c.fee as course_fee
                         FROM {$table_prefix}enrollments e
                         LEFT JOIN {$table_prefix}courses c ON e.course_id = c.course_id
                         WHERE e.student_id = %d 
                         ORDER BY e.enrollment_id ASC",
                        $student_id
                    ));
                }
            }
        }
        
        if (empty($enrollments)) {
            // Debug: Check tables for any data
            $enroll_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_prefix}enrollments WHERE student_id = %d", 
                $student_id
            ));
            $balance_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_prefix}student_balances WHERE student_id = %d", 
                $student_id
            ));
            
            wp_send_json_error(array(
                'message' => 'No enrollment found for this student. Please enroll the student in a course first.',
                'debug' => sprintf('Student ID: %d, Course ID: %s, Enrollments: %d, Balances: %d', 
                    $student_id, 
                    $student->course_id ?: 'NULL',
                    $enroll_count,
                    $balance_count
                )
            ));
            wp_die();
        }
        
        // Calculate totals across ALL courses
        $total_tuition = 0;
        $requires_additional_fees = false;
        $courses_list = array();
        $first_enrollment_id = $enrollments[0]->enrollment_id;
        
        $excluded_courses = array('computer applications', 'computer essentials', 'computer & online essentials');
        
        // Get balances for each enrollment
        foreach ($enrollments as $enrollment) {
            // Get balance info for this enrollment
            $balance_info = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_prefix}student_balances WHERE enrollment_id = %d",
                $enrollment->enrollment_id
            ));
            
            // ALWAYS use the live course fee from the courses table (c.fee via JOIN).
            // student_balances.total_fee can be stale/wrong and must never be the primary source.
            // Fall back to balance_info->total_fee only if the course record is missing entirely.
            $course_fee = 0;
            if (!empty($enrollment->course_fee)) {
                $course_fee = floatval($enrollment->course_fee);
            } elseif ($balance_info && $balance_info->total_fee > 0) {
                $course_fee = floatval($balance_info->total_fee);
            } elseif (!empty($enrollment->fee)) {
                $course_fee = floatval($enrollment->fee);
            }

            // Auto-repair: if stored total_fee doesn't match the actual course fee, fix it now
            if ($balance_info && $course_fee > 0 && abs(floatval($balance_info->total_fee) - $course_fee) > 0.01) {
                $wpdb->update(
                    $table_prefix . 'student_balances',
                    array('total_fee' => $course_fee),
                    array('enrollment_id' => $enrollment->enrollment_id),
                    array('%f'), array('%d')
                );
            }
            
            $total_tuition += $course_fee;
            
            $course_name = !empty($enrollment->course_name) ? $enrollment->course_name : 'Course Not Specified';
            $course_code = !empty($enrollment->course_code) ? $enrollment->course_code : '';
            
            // Check if any course requires additional fees
            $course_name_lower = strtolower($course_name);
            $course_requires_fees = true;
            foreach ($excluded_courses as $excluded) {
                if (strpos($course_name_lower, $excluded) !== false) {
                    $course_requires_fees = false;
                    break;
                }
            }
            
            if ($course_requires_fees) {
                $requires_additional_fees = true;
            }
            
            $course_display = $course_name;
            if ($course_code) {
                $course_display .= ' (' . $course_code . ')';
            }
            
            // Get per-enrollment actual paid and discount from payments table
            $course_paid_actual = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$table_prefix}payments
                 WHERE enrollment_id = %d AND status = 'Completed'",
                $enrollment->enrollment_id
            )));
            $course_discount = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(discount), 0) FROM {$table_prefix}payments
                 WHERE enrollment_id = %d AND status = 'Completed'",
                $enrollment->enrollment_id
            )));
            $course_balance = max(0, $course_fee - $course_discount - $course_paid_actual);
            
            $courses_list[] = array(
                'enrollment_id' => $enrollment->enrollment_id,
                'course_name' => $course_display,
                'course_name_raw' => $course_name,
                'course_code' => $course_code,
                'fee' => $course_fee,
                'discount' => $course_discount,
                'paid' => $course_paid_actual,
                'balance' => $course_balance
            );
        }
        
        // Additional fees are charged ONCE regardless of number of courses
        $admission_fee = $requires_additional_fees ? 1500 : 0;
        $total_fee = $total_tuition + $admission_fee;
        
        // Get total discount from actual payments table (most accurate source)
        $total_discount = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(discount), 0) FROM {$table_prefix}payments WHERE student_id = %d AND status = 'Completed'",
            $student_id
        )));
        
        // ALWAYS calculate total_paid from actual payments table (most accurate)
        $total_paid = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table_prefix}payments WHERE student_id = %d AND status = 'Completed'",
            $student_id
        )));

        // Calculate correct outstanding balance: Total Fee - Discount - Total Paid
        $total_balance = max(0, $total_fee - $total_discount - $total_paid);

        // Sync student_balances.total_paid and .balance for each enrollment using real data
        foreach ($courses_list as $course_sync) {
            if (!empty($course_sync['enrollment_id'])) {
                $wpdb->update(
                    $table_prefix . 'student_balances',
                    array(
                        'total_fee'   => $course_sync['fee'],
                        'total_paid'  => $course_sync['paid'],
                        'balance'     => $course_sync['balance'],
                    ),
                    array('enrollment_id' => $course_sync['enrollment_id']),
                    array('%f', '%f', '%f'), array('%d')
                );
            }
        }
        
        // Format courses for display
        $courses_display = array();
        foreach ($courses_list as $course) {
            $courses_display[] = $course['course_name'] . ' - KES ' . number_format($course['fee'], 2);
        }
        
        wp_send_json_success(array(
            'enrollment_id' => $first_enrollment_id,
            'course_name' => implode(', ', array_column($courses_list, 'course_name')),
            'course_name_raw' => $courses_list[0]['course_name_raw'],
            'courses_list' => $courses_list,
            'courses_display' => $courses_display,
            'num_courses' => count($courses_list),
            'tuition_fee' => $total_tuition,
            'admission_fee' => $admission_fee,
            'total_fee' => $total_fee,
            'total_discount' => $total_discount,
            'total_paid' => $total_paid,
            'balance' => $total_balance,
            'requires_additional_fees' => $requires_additional_fees
        ));
        wp_die();
    }

    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, MTTI_MIS_PLUGIN_URL . 'assets/css/mtti-mis-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, MTTI_MIS_PLUGIN_URL . 'assets/js/mtti-mis-admin.js', array('jquery'), $this->version, false);
        
        // Load WordPress media uploader on student pages (for passport photo upload)
        $screen = get_current_screen();
        if ($screen && isset($_GET['page']) && $_GET['page'] === 'mtti-mis-students') {
            wp_enqueue_media();
        }

        // Chart.js for analytics dashboard
        if (!isset($_GET['page']) || $_GET['page'] === 'mtti-mis') {
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true);
        }
        
        // Localize script for AJAX
        wp_localize_script($this->plugin_name, 'mtti_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mtti_mis_nonce')
        ));
    }

    public function add_plugin_admin_menu() {
        // Main menu
        add_menu_page(
            'MTTI MIS',
            'MTTI MIS',
            'manage_mtti',
            'mtti-mis',
            array($this, 'display_dashboard'),
            'dashicons-welcome-learn-more',
            26
        );

        // Dashboard submenu
        add_submenu_page(
            'mtti-mis',
            'Dashboard',
            'Dashboard',
            'manage_mtti',
            'mtti-mis',
            array($this, 'display_dashboard')
        );

        // Students submenu
        add_submenu_page(
            'mtti-mis',
            'Students',
            'Students',
            'manage_students',
            'mtti-mis-students',
            array($this, 'display_students')
        );

        // Staff / Teachers submenu
        add_submenu_page(
            'mtti-mis',
            'Teachers / Staff',
            '👩‍🏫 Teachers',
            'manage_mtti',
            'mtti-mis-staff',
            array($this, 'display_staff')
        );

        // Enrollments submenu - assign teachers to courses, manage class lists
        add_submenu_page(
            'mtti-mis',
            'Assign Teachers to Courses',
            '📋 Teacher → Course',
            'manage_mtti',
            'mtti-mis-enrollments',
            array($this, 'display_enrollments')
        );

        // Admission Letters submenu
        add_submenu_page(
            'mtti-mis',
            'Admission Letters',
            'Admission Letters',
            'manage_students',
            'mtti-mis-admission-letters',
            array($this, 'display_admission_letters')
        );

        // Courses submenu
        add_submenu_page(
            'mtti-mis',
            'Courses',
            'Courses',
            'manage_courses',
            'mtti-mis-courses',
            array($this, 'display_courses')
        );


        // Payments submenu
        add_submenu_page(
            'mtti-mis',
            'Payments',
            'Payments',
            'manage_payments',
            'mtti-mis-payments',
            array($this, 'display_payments')
        );

        // Assignments submenu
        add_submenu_page(
            'mtti-mis',
            'Assignments',
            'Assignments',
            'manage_assignments',
            'mtti-mis-assignments',
            array($this, 'display_assignments')
        );

        // Live Classes submenu
        add_submenu_page(
            'mtti-mis',
            'Live Classes',
            'Live Classes',
            'manage_live_classes',
            'mtti-mis-live-classes',
            array($this, 'display_live_classes')
        );

        // Certificates submenu
        add_submenu_page(
            'mtti-mis',
            'Certificates',
            'Certificates',
            'manage_mtti',
            'mtti-mis-certificates',
            array($this, 'display_certificates')
        );

        // Course Units submenu
        add_submenu_page(
            'mtti-mis',
            'Course Units',
            'Course Units',
            'manage_courses',
            'mtti-mis-units',
            array($this, 'display_units')
        );

        // Course Materials submenu
        add_submenu_page(
            'mtti-mis',
            'Materials',
            'Materials',
            'manage_courses',
            'mtti-mis-materials',
            array($this, 'display_materials')
        );

        // Lessons submenu (for teachers to upload lessons)
        add_submenu_page(
            'mtti-mis',
            'Lessons',
            'Lessons',
            'manage_courses',
            'mtti-mis-lessons',
            array($this, 'display_lessons')
        );

        // Notice Board submenu
        add_submenu_page(
            'mtti-mis',
            'Notice Board',
            'Notice Board',
            'manage_mtti',
            'mtti-mis-notice-board',
            array($this, 'display_notice_board')
        );

        // Scheme of Work submenu
        add_submenu_page(
            'mtti-mis',
            'Scheme of Work',
            '📋 Scheme of Work',
            'manage_courses',
            'mtti-mis-scheme',
            array($this, 'display_scheme')
        );

        add_submenu_page(
            'mtti-mis',
            'Interactive Content Creator',
            '⚡ Content Creator',
            'manage_courses',
            'mtti-mis-interactive',
            array($this, 'display_interactive')
        );

        // Send Notifications submenu
        add_submenu_page(
            'mtti-mis',
            'Send Notifications',
            '🔔 Notifications',
            'manage_mtti',
            'mtti-mis-notifications',
            array($this, 'display_notifications_admin')
        );

        // Settings submenu
        add_submenu_page(
            'mtti-mis',
            'Settings',
            'Settings',
            'manage_options',
            'mtti-mis-settings',
            array($this, 'display_settings')
        );
        
        // Tools/Diagnostics submenu (admin only)
        add_submenu_page(
            'mtti-mis',
            'Tools & Diagnostics',
            '🔧 Tools',
            'manage_options',
            'mtti-mis-tools',
            array($this, 'display_tools')
        );
        
        // Finance submenu
        add_submenu_page(
            'mtti-mis',
            'Finance',
            '📊 Finance',
            'manage_finance',
            'mtti-mis-finance',
            array($this, 'display_finance')
        );

        // Asset Register submenu
        add_submenu_page(
            'mtti-mis',
            'Asset Register',
            '🏢 Asset Register',
            'manage_assets',
            'mtti-mis-assets',
            array($this, 'display_assets')
        );

        // Recycle Bin submenu
        add_submenu_page(
            'mtti-mis',
            'Recycle Bin',
            '🗑️ Recycle Bin',
            'manage_options',
            'mtti-mis-trash',
            array($this, 'display_trash')
        );
    }
    
    public function display_finance() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-finance.php';
        $finance = new MTTI_MIS_Admin_Finance($this->plugin_name, $this->version);
        $finance->display();
    }

    public function display_assets() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-assets.php';
        $assets = new MTTI_MIS_Admin_Assets($this->plugin_name, $this->version);
        $assets->display();
    }

    /**
     * Display Tools & Diagnostics page
     */
    public function display_trash() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
        MTTI_MIS_Admin_Trash::create_table();
        $trash = new MTTI_MIS_Admin_Trash();
        $trash->display_page();
    }
    
    /**
     * Helper: soft-delete a record to recycle bin instead of permanent delete.
     */
    public function trash_record($type, $id, $label, $data, $related = array()) {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
        MTTI_MIS_Admin_Trash::create_table();
        $trash = new MTTI_MIS_Admin_Trash();
        return $trash->soft_delete($type, $id, $label, $data, $related);
    }
    
    public function display_tools() {
        global $wpdb;
        $table_prefix = $wpdb->prefix . 'mtti_';
        $message = '';
        
        ?>
        <div class="wrap">
            <h1>🔧 Tools & Diagnostics</h1>
            
            <?php if ($message) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            
            <!-- System Status -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2>📊 System Status</h2>
                
                <?php
                $students_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}students");
                $courses_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}courses WHERE status = 'Active'");
                $enrollments_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}enrollments WHERE status = 'Active'");
                $units_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}course_units");
                $unit_results_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}unit_results");
                ?>
                
                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="background: #e7f3ff; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #2196F3;"><?php echo $students_count; ?></div>
                        <div>Students</div>
                    </div>
                    <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #28a745;"><?php echo $courses_count; ?></div>
                        <div>Active Courses</div>
                    </div>
                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #FF9800;"><?php echo $enrollments_count; ?></div>
                        <div>Active Enrollments</div>
                    </div>
                    <div style="background: #e7f3ff; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #9C27B0;"><?php echo $units_count; ?></div>
                        <div>Course Units</div>
                    </div>
                    <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 2em; font-weight: bold; color: #2E7D32;"><?php echo $unit_results_count; ?></div>
                        <div>Unit Results</div>
                    </div>
                </div>
            </div>
            
            <!-- Grading Scale Info -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2>📝 Grading Scale</h2>
                <p>Marks are entered via <strong>Course Units</strong> menu. Maximum marks: <strong>100</strong></p>
                
                <table class="wp-list-table widefat" style="max-width: 500px;">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Marks Range</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #d4edda;"><td><strong>DISTINCTION</strong></td><td>80 - 100</td><td>✓ Pass</td></tr>
                        <tr style="background: #cce5ff;"><td><strong>CREDIT</strong></td><td>60 - 79</td><td>✓ Pass</td></tr>
                        <tr style="background: #fff3cd;"><td><strong>PASS</strong></td><td>50 - 59</td><td>✓ Pass</td></tr>
                        <tr style="background: #f8d7da;"><td><strong>REFER</strong></td><td>0 - 49</td><td>✗ Fail</td></tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Database Info -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2>💾 Database Tables</h2>
                <p class="description">MTTI MIS database tables status.</p>
                
                <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
                    <thead>
                        <tr><th>Table</th><th>Status</th><th>Records</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        $tables = array('students', 'courses', 'course_units', 'enrollments', 'unit_results', 'payments', 'certificates');
                        foreach ($tables as $table) :
                            $full_table = $table_prefix . $table;
                            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
                            $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$full_table}") : 0;
                        ?>
                        <tr>
                            <td><code><?php echo $full_table; ?></code></td>
                            <td><?php echo $exists ? '<span style="color:#28a745;">✓ OK</span>' : '<span style="color:#dc3545;">✗ Missing</span>'; ?></td>
                            <td><?php echo $count; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Plugin Info -->
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2>ℹ️ Plugin Information</h2>
                <table class="form-table">
                    <tr><th>Version</th><td><?php echo MTTI_MIS_VERSION; ?></td></tr>
                    <tr><th>WordPress</th><td><?php echo get_bloginfo('version'); ?></td></tr>
                    <tr><th>PHP</th><td><?php echo phpversion(); ?></td></tr>
                </table>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('mtti_mis_settings', 'mtti_mis_institute_name');
        register_setting('mtti_mis_settings', 'mtti_mis_institute_email');
        register_setting('mtti_mis_settings', 'mtti_mis_institute_phone');
        register_setting('mtti_mis_settings', 'mtti_mis_institute_address');
    }

    public function display_dashboard() {
        global $wpdb;
        
        // Get statistics
        $students_table = $this->db->get_table_name('students');
        $courses_table = $this->db->get_table_name('courses');
        $enrollments_table = $this->db->get_table_name('enrollments');
        $payments_table = $this->db->get_table_name('payments');
        $balances_table = $this->db->get_table_name('student_balances');
        
        $total_students = $wpdb->get_var("SELECT COUNT(*) FROM {$students_table} WHERE status = 'Active'");
        $total_courses = $wpdb->get_var("SELECT COUNT(*) FROM {$courses_table} WHERE status = 'Active'");
        $total_payments = $wpdb->get_var("SELECT SUM(amount) FROM {$payments_table} WHERE status = 'Completed'");
        
        // New financial statistics
        $total_outstanding = $wpdb->get_var("SELECT SUM(balance) FROM {$balances_table} WHERE balance > 0");
        $total_expected = $wpdb->get_var("SELECT SUM(total_fee) FROM {$balances_table}");
        $total_discounts = $wpdb->get_var("SELECT SUM(discount_amount) FROM {$balances_table}");
        $fully_paid_students = $wpdb->get_var("SELECT COUNT(DISTINCT student_id) FROM {$balances_table} WHERE balance = 0");
        
        // This month's payments
        $current_month_start = date('Y-m-01');
        $this_month_payments = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$payments_table} WHERE status = 'Completed' AND payment_date >= %s",
            $current_month_start
        ));
        
        // Students with outstanding balance
        $students_with_balance = $wpdb->get_var("SELECT COUNT(DISTINCT student_id) FROM {$balances_table} WHERE balance > 0");
        
        ?>
        <div class="wrap mtti-mis-wrap">
            <h1>
                <img src="<?php echo MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg'; ?>" style="height: 50px; vertical-align: middle; margin-right: 15px;">
                MTTI Management Information System
            </h1>
            <p class="mtti-motto">"Start Learning, Start Earning"</p>
            
            <div class="mtti-dashboard">
                <div class="mtti-stats-grid">
                    <div class="mtti-stat-card mtti-stat-students">
                        <div class="mtti-stat-icon">
                            <span class="dashicons dashicons-groups"></span>
                        </div>
                        <div class="mtti-stat-content">
                            <h3><?php echo number_format($total_students); ?></h3>
                            <p>Active Students</p>
                        </div>
                    </div>
                    
                    <div class="mtti-stat-card mtti-stat-courses">
                        <div class="mtti-stat-icon">
                            <span class="dashicons dashicons-book"></span>
                        </div>
                        <div class="mtti-stat-content">
                            <h3><?php echo number_format($total_courses); ?></h3>
                            <p>Active Courses</p>
                        </div>
                    </div>
                    
                    <?php if (current_user_can('manage_options')) : ?>
                    <div class="mtti-stat-card mtti-stat-payments">
                        <div class="mtti-stat-icon">
                            <span class="dashicons dashicons-money-alt"></span>
                        </div>
                        <div class="mtti-stat-content">
                            <h3>KES <?php echo number_format($total_payments, 2); ?></h3>
                            <p>Total Fees Collected</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (current_user_can('manage_options')) : ?>
                <!-- Financial Summary Section -->
                <div style="margin: 30px 0;">
                    <h2 style="margin-bottom: 15px;">💰 Financial Overview</h2>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                        <!-- Total Expected Fees -->
                        <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(102,126,234,0.3);">
                            <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px;">Total Expected Fees</div>
                            <div style="font-size: 26px; font-weight: bold; margin: 10px 0;">KES <?php echo number_format($total_expected, 2); ?></div>
                            <div style="font-size: 11px; opacity: 0.8;">From all enrollments</div>
                        </div>
                        
                        <!-- Total Collected -->
                        <div style="background: linear-gradient(135deg, #11998e, #38ef7d); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(17,153,142,0.3);">
                            <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px;">Total Collected (Running)</div>
                            <div style="font-size: 26px; font-weight: bold; margin: 10px 0;">KES <?php echo number_format($total_payments, 2); ?></div>
                            <div style="font-size: 11px; opacity: 0.8;">
                                <?php 
                                $collection_rate = $total_expected > 0 ? ($total_payments / $total_expected) * 100 : 0;
                                echo number_format($collection_rate, 1) . '% collection rate';
                                ?>
                            </div>
                        </div>
                        
                        <!-- Outstanding Balance -->
                        <div style="background: linear-gradient(135deg, #eb3349, #f45c43); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(235,51,73,0.3);">
                            <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px;">Outstanding Balance</div>
                            <div style="font-size: 26px; font-weight: bold; margin: 10px 0;">KES <?php echo number_format($total_outstanding, 2); ?></div>
                            <div style="font-size: 11px; opacity: 0.8;"><?php echo $students_with_balance; ?> student(s) with balance</div>
                        </div>
                        
                        <!-- This Month -->
                        <div style="background: linear-gradient(135deg, #f093fb, #f5576c); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(240,147,251,0.3);">
                            <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px;"><?php echo date('F Y'); ?> Collections</div>
                            <div style="font-size: 26px; font-weight: bold; margin: 10px 0;">KES <?php echo number_format($this_month_payments, 2); ?></div>
                            <div style="font-size: 11px; opacity: 0.8;">This month's revenue</div>
                        </div>
                        
                        <!-- Discounts Given -->
                        <div style="background: linear-gradient(135deg, #4facfe, #00f2fe); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(79,172,254,0.3);">
                            <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px;">Total Discounts Given</div>
                            <div style="font-size: 26px; font-weight: bold; margin: 10px 0;">KES <?php echo number_format($total_discounts, 2); ?></div>
                            <div style="font-size: 11px; opacity: 0.8;">Scholarships & waivers</div>
                        </div>
                        
                        <!-- Fully Paid -->
                        <div style="background: linear-gradient(135deg, #a8edea, #fed6e3); color: #333; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(168,237,234,0.3);">
                            <div style="font-size: 12px; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px;">Fully Paid Students</div>
                            <div style="font-size: 26px; font-weight: bold; margin: 10px 0;"><?php echo number_format($fully_paid_students); ?></div>
                            <div style="font-size: 11px; opacity: 0.7;">Zero balance students</div>
                        </div>
                    </div>
                </div>
                <?php endif; // end manage_options financial overview ?>
                
                <div class="mtti-quick-actions">
                    <h2>Quick Actions</h2>
                    <div class="mtti-actions-grid">
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=add'); ?>" class="mtti-action-btn">
                            <span class="dashicons dashicons-plus"></span>
                            Add New Student
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=add'); ?>" class="mtti-action-btn">
                            <span class="dashicons dashicons-plus"></span>
                            Add New Course
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=add'); ?>" class="mtti-action-btn">
                            <span class="dashicons dashicons-plus"></span>
                            Record Payment
                        </a>
                    </div>
                </div>
                
                <?php if (current_user_can('manage_options')) : ?>
                <!-- Students with Outstanding Balance -->
                <div class="mtti-recent-activity" style="margin-top: 30px;">
                    <h2>⚠️ Students with Outstanding Balance</h2>
                    <?php
                    $students_owing = $wpdb->get_results(
                        "SELECT sb.*, s.admission_number, u.display_name, c.course_name
                         FROM {$balances_table} sb
                         LEFT JOIN {$students_table} s ON sb.student_id = s.student_id
                         LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                         LEFT JOIN {$enrollments_table} e ON sb.enrollment_id = e.enrollment_id
                         LEFT JOIN {$courses_table} c ON e.course_id = c.course_id
                         WHERE sb.balance > 0
                         ORDER BY sb.balance DESC
                         LIMIT 10"
                    );
                    
                    if ($students_owing) {
                        ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Admission No</th>
                                    <th>Name</th>
                                    <th>Course</th>
                                    <th>Total Fee</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students_owing as $student) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($student->admission_number); ?></strong></td>
                                    <td><?php echo esc_html($student->display_name); ?></td>
                                    <td><?php echo esc_html($student->course_name); ?></td>
                                    <td>KES <?php echo number_format($student->total_fee, 2); ?></td>
                                    <td style="color: #1976D2;">KES <?php echo number_format($student->total_paid, 2); ?></td>
                                    <td><strong style="color: #D32F2F;">KES <?php echo number_format($student->balance, 2); ?></strong></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=view&id=' . $student->student_id); ?>" 
                                           class="button button-small">View</a>
                                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=add&student_id=' . $student->student_id); ?>" 
                                           class="button button-small button-primary">Record Payment</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><a href="<?php echo admin_url('admin.php?page=mtti-mis-students'); ?>" class="button">View All Students</a></p>
                        <?php
                    } else {
                        echo '<div style="background: #e8f5e9; padding: 20px; border-radius: 8px; text-align: center;">';
                        echo '<span class="dashicons dashicons-yes-alt" style="font-size: 48px; color: #4CAF50;"></span>';
                        echo '<p style="font-size: 16px; margin: 10px 0 0 0;">🎉 All students have cleared their balances!</p>';
                        echo '</div>';
                    }
                    ?>
                </div>
                
                <div class="mtti-recent-activity" style="margin-top: 30px;">
                    <h2>Recent Payments</h2>
                    <?php
                    $recent_payments = $this->db->get_payments(array('limit' => 5));
                    if ($recent_payments) {
                        ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Receipt No</th>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $payment) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($payment->receipt_number); ?></strong></td>
                                    <td><?php echo esc_html($payment->admission_number); ?></td>
                                    <td><strong style="color: #2E7D32;">KES <?php echo number_format($payment->amount, 2); ?></strong></td>
                                    <td><?php echo date('M j, Y', strtotime($payment->payment_date)); ?></td>
                                    <td><span class="mtti-status mtti-status-<?php echo strtolower($payment->status); ?>">
                                        <?php echo esc_html($payment->status); ?>
                                    </span></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=receipt&id=' . $payment->payment_id); ?>" 
                                           target="_blank" class="button button-small">Print Receipt</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p><a href="<?php echo admin_url('admin.php?page=mtti-mis-payments'); ?>" class="button">View All Payments</a></p>
                        <?php
                    } else {
                        echo '<p>No recent payments.</p>';
                    }
                    ?>
                </div>
                <?php endif; // end manage_options outstanding/payments ?>

                <?php if (current_user_can('manage_options')) : ?>
                <!-- ── ANALYTICS CHARTS — Last 6 Months ── -->
                <?php
                $chart_labels  = [];
                $chart_enroll  = [];
                $chart_revenue = [];
                $chart_students = [];
                for ($i = 5; $i >= 0; $i--) {
                    $m_start = date('Y-m-01', strtotime("-{$i} months"));
                    $m_end   = date('Y-m-t',  strtotime("-{$i} months"));
                    $chart_labels[]   = date('M Y', strtotime("-{$i} months"));
                    $chart_enroll[]   = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$enrollments_table} WHERE enrollment_date BETWEEN %s AND %s",
                        $m_start, $m_end
                    ));
                    $chart_revenue[]  = (float) $wpdb->get_var($wpdb->prepare(
                        "SELECT COALESCE(SUM(amount),0) FROM {$payments_table} WHERE status='Completed' AND payment_date BETWEEN %s AND %s",
                        $m_start, $m_end
                    ));
                    $chart_students[] = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$students_table} WHERE status='Active' AND created_at <= %s",
                        $m_end
                    ));
                }
                ?>
                <div style="margin-top:35px;">
                    <h2 style="margin-bottom:15px;">📊 Analytics — Last 6 Months</h2>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

                        <div style="background:#fff;padding:22px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-top:4px solid #2E7D32;">
                            <h3 style="margin:0 0 14px;font-size:13px;color:#666;text-transform:uppercase;letter-spacing:.6px;">📋 New Enrollments</h3>
                            <canvas id="mtti-chart-enroll" height="200"></canvas>
                        </div>

                        <div style="background:#fff;padding:22px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-top:4px solid #1565C0;">
                            <h3 style="margin:0 0 14px;font-size:13px;color:#666;text-transform:uppercase;letter-spacing:.6px;">💰 Revenue Collected (KES)</h3>
                            <canvas id="mtti-chart-revenue" height="200"></canvas>
                        </div>

                        <div style="background:#fff;padding:22px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);border-top:4px solid #6A1B9A;grid-column:span 2;">
                            <h3 style="margin:0 0 14px;font-size:13px;color:#666;text-transform:uppercase;letter-spacing:.6px;">👥 Cumulative Active Students</h3>
                            <canvas id="mtti-chart-students" height="90"></canvas>
                        </div>

                    </div>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof Chart === 'undefined') return;
                    const labels   = <?php echo json_encode($chart_labels); ?>;
                    const enrollD  = <?php echo json_encode($chart_enroll); ?>;
                    const revD     = <?php echo json_encode($chart_revenue); ?>;
                    const stuD     = <?php echo json_encode($chart_students); ?>;

                    Chart.defaults.font.family = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';
                    Chart.defaults.font.size   = 12;
                    const grid = { color: '#f0f0f0' };
                    const tx   = { color: '#666' };

                    new Chart(document.getElementById('mtti-chart-enroll'), {
                        type: 'bar',
                        data: { labels, datasets: [{ label: 'Enrollments', data: enrollD,
                            backgroundColor: 'rgba(46,125,50,.75)', borderColor: '#2E7D32',
                            borderWidth: 2, borderRadius: 6, borderSkipped: false }] },
                        options: { responsive: true, plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1, ...tx }, grid },
                                      x: { ticks: tx, grid: { display: false } } } }
                    });

                    new Chart(document.getElementById('mtti-chart-revenue'), {
                        type: 'line',
                        data: { labels, datasets: [{ label: 'Revenue (KES)', data: revD,
                            backgroundColor: 'rgba(21,101,192,.08)', borderColor: '#1565C0',
                            borderWidth: 2.5, fill: true, tension: 0.4,
                            pointBackgroundColor: '#1565C0', pointRadius: 5, pointHoverRadius: 7 }] },
                        options: { responsive: true, plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, grid,
                                ticks: { ...tx, callback: v => 'KES ' + Number(v).toLocaleString() } },
                                x: { ticks: tx, grid: { display: false } } } }
                    });

                    new Chart(document.getElementById('mtti-chart-students'), {
                        type: 'line',
                        data: { labels, datasets: [{ label: 'Active Students', data: stuD,
                            backgroundColor: 'rgba(106,27,154,.08)', borderColor: '#6A1B9A',
                            borderWidth: 2.5, fill: true, tension: 0.4,
                            pointBackgroundColor: '#6A1B9A', pointRadius: 5, pointHoverRadius: 7 }] },
                        options: { responsive: true, plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1, ...tx }, grid },
                                      x: { ticks: tx, grid: { display: false } } } }
                    });
                });
                </script>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    public function display_scheme() {
        // Curriculum Monitor replaces broken scheme-of-work
        if (!class_exists('MTTI_MIS_Admin_Curriculum')) {
            require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-curriculum.php';
        }
        $obj = new MTTI_MIS_Admin_Curriculum();
        $obj->display();
    }

    public function display_interactive() {
        if (!class_exists('MTTI_MIS_Admin_Interactive')) {
            require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-interactive.php';
        }
        $obj = new MTTI_MIS_Admin_Interactive();
        $obj->display();
    }

    public function display_students() {
        $admin_students = new MTTI_MIS_Admin_Students($this->plugin_name, $this->version);
        $admin_students->display();
    }

    public function display_staff() {
        if (!class_exists('MTTI_MIS_Admin_Staff')) {
            require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-staff.php';
        }
        $obj = new MTTI_MIS_Admin_Staff();
        $obj->display();
    }

    public function display_enrollments() {
        if (!class_exists('MTTI_MIS_Admin_Teacher_Courses')) {
            require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-teacher-courses.php';
        }
        $obj = new MTTI_MIS_Admin_Teacher_Courses();
        $obj->display();
    }

    public function display_courses() {
        $admin_courses = new MTTI_MIS_Admin_Courses($this->plugin_name, $this->version);
        $admin_courses->display();
    }


    public function display_payments() {
        $admin_payments = new MTTI_MIS_Admin_Payments($this->plugin_name, $this->version);
        $admin_payments->display();
    }

    public function display_assignments() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/views/assignments.php';
    }

    public function display_live_classes() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/views/live-classes.php';
    }

    public function display_certificates() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/views/certificates.php';
    }

    public function display_units() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/views/units.php';
    }

    public function display_notice_board() {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/views/notice-board.php';
    }

    public function display_notifications_admin() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_notifications';
        
        // Handle form submit — send notification
        if (isset($_POST['mtti_send_notif']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mtti_send_notif_action')) {
            $title    = sanitize_text_field($_POST['notif_title'] ?? '');
            $message  = sanitize_textarea_field($_POST['notif_message'] ?? '');
            $type     = sanitize_key($_POST['notif_type'] ?? 'info');
            $audience = sanitize_key($_POST['notif_audience'] ?? 'all');
            
            if ($title && $message) {
                // Ensure table exists
                if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
                    echo '<div class="notice notice-error"><p>Notifications table not found. Please run NEW-TABLES-v6.sql first.</p></div>';
                } else {
                    if ($audience === 'all') {
                        $students = $wpdb->get_col("SELECT student_id FROM {$wpdb->prefix}mtti_students WHERE status='Active'");
                    } else {
                        $students = $wpdb->get_col($wpdb->prepare(
                            "SELECT DISTINCT s.student_id FROM {$wpdb->prefix}mtti_students s
                             INNER JOIN {$wpdb->prefix}mtti_enrollments e ON s.student_id = e.student_id
                             WHERE e.course_id = %d AND s.status = 'Active'",
                            intval($audience)
                        ));
                    }
                    $sent = 0;
                    foreach ($students as $sid) {
                        $wpdb->insert($table, array(
                            'student_id' => intval($sid),
                            'type'       => $type,
                            'title'      => $title,
                            'message'    => $message,
                            'is_read'    => 0,
                            'created_at' => current_time('mysql'),
                        ));
                        $sent++;
                    }
                    echo '<div class="notice notice-success is-dismissible"><p>✓ Notification sent to <strong>' . $sent . '</strong> student(s).</p></div>';
                }
            }
        }
        
        // Load courses for audience selector
        $courses = $wpdb->get_results("SELECT course_id, course_code, course_name FROM {$wpdb->prefix}mtti_courses WHERE status='Active' ORDER BY course_name");
        
        // Recent notifications
        $recent = array();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            $recent = $wpdb->get_results(
                "SELECT n.*, s.admission_number FROM {$table} n
                 LEFT JOIN {$wpdb->prefix}mtti_students s ON n.student_id = s.student_id
                 ORDER BY n.created_at DESC LIMIT 50"
            );
        }
        ?>
        <div class="wrap">
            <h1>🔔 Send Notifications</h1>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:16px;">
                <div>
                    <div class="card" style="padding:20px;">
                        <h2 style="margin-top:0;">Send New Notification</h2>
                        <form method="post">
                            <?php wp_nonce_field('mtti_send_notif_action'); ?>
                            <input type="hidden" name="mtti_send_notif" value="1">
                            <table class="form-table">
                                <tr>
                                    <th>Audience</th>
                                    <td>
                                        <select name="notif_audience" class="regular-text">
                                            <option value="all">All Active Students</option>
                                            <?php foreach ($courses as $c): ?>
                                            <option value="<?php echo esc_attr($c->course_id); ?>">
                                                <?php echo esc_html($c->course_code . ' — ' . $c->course_name); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Type</th>
                                    <td>
                                        <select name="notif_type">
                                            <option value="info">ℹ️ Info</option>
                                            <option value="success">✅ Success</option>
                                            <option value="warning">⚠️ Warning</option>
                                            <option value="danger">🚨 Urgent</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Title</th>
                                    <td><input type="text" name="notif_title" class="regular-text" required placeholder="e.g. Exam Results Available" maxlength="200"></td>
                                </tr>
                                <tr>
                                    <th>Message</th>
                                    <td><textarea name="notif_message" class="large-text" rows="4" required placeholder="Write your notification message here..." maxlength="500"></textarea></td>
                                </tr>
                            </table>
                            <?php submit_button('📤 Send Notification'); ?>
                        </form>
                    </div>
                </div>
                <div>
                    <div class="card" style="padding:20px;">
                        <h2 style="margin-top:0;">Recent Notifications (50)</h2>
                        <?php if (!empty($recent)): ?>
                        <table class="wp-list-table widefat striped" style="font-size:12px;">
                            <thead><tr><th>Student</th><th>Title</th><th>Type</th><th>Read</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($recent as $n): ?>
                            <tr>
                                <td><?php echo esc_html($n->admission_number); ?></td>
                                <td><?php echo esc_html($n->title); ?></td>
                                <td><?php echo esc_html($n->type); ?></td>
                                <td><?php echo $n->is_read ? '✓' : '—'; ?></td>
                                <td><?php echo esc_html(date('M j, g:i A', strtotime($n->created_at))); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p style="color:#666;">No notifications sent yet. Run NEW-TABLES-v6.sql first if the table is missing.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function display_settings() {
        // Handle role permissions save
        if (isset($_POST['mtti_save_role_permissions']) && wp_verify_nonce($_POST['mtti_role_permissions_nonce'], 'mtti_role_permissions_action')) {
            $this->save_role_permissions();
            echo '<div class="notice notice-success is-dismissible"><p>Role permissions updated successfully!</p></div>';
        }
        
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1>MTTI MIS Settings</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=mtti-mis-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                    General Settings
                </a>
                <a href="?page=mtti-mis-settings&tab=roles" class="nav-tab <?php echo $active_tab == 'roles' ? 'nav-tab-active' : ''; ?>">
                    🔐 Role Permissions
                </a>
            </h2>
            
            <?php if ($active_tab == 'general') : ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('mtti_mis_settings');
                do_settings_sections('mtti_mis_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Institute Name</th>
                        <td>
                            <input type="text" name="mtti_mis_institute_name" 
                                   value="<?php echo esc_attr(get_option('mtti_mis_institute_name', 'Masomotele Technical Training Institute')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email</th>
                        <td>
                            <input type="email" name="mtti_mis_institute_email" 
                                   value="<?php echo esc_attr(get_option('mtti_mis_institute_email', 'info@mtti.ac.ke')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Phone</th>
                        <td>
                            <input type="text" name="mtti_mis_institute_phone" 
                                   value="<?php echo esc_attr(get_option('mtti_mis_institute_phone', '+254 XXX XXXXXX')); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Address</th>
                        <td>
                            <textarea name="mtti_mis_institute_address" rows="3" class="large-text"><?php 
                                echo esc_textarea(get_option('mtti_mis_institute_address', 'Sagaas Center, Eldoret, Kenya')); 
                            ?></textarea>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <?php else : ?>
            <!-- Role Permissions Tab -->
            <?php $this->display_role_permissions(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display role permissions management interface
     */
    private function display_role_permissions() {
        // Define MTTI capabilities with descriptions
        $mtti_capabilities = array(
            'manage_mtti' => array(
                'label' => 'Access MTTI Dashboard',
                'description' => 'View the main MTTI MIS dashboard'
            ),
            'manage_students' => array(
                'label' => 'Manage Students',
                'description' => 'Add, edit, delete students'
            ),
            'view_students' => array(
                'label' => 'View Students',
                'description' => 'View student list and details (read-only)'
            ),
            'manage_courses' => array(
                'label' => 'Manage Courses & Units',
                'description' => 'Add, edit courses, units, and materials'
            ),
            'manage_enrollments' => array(
                'label' => 'Manage Enrollments',
                'description' => 'Enroll students in courses'
            ),
            'manage_payments' => array(
                'label' => 'Manage Payments',
                'description' => 'Record and manage fee payments'
            ),
            'manage_assignments' => array(
                'label' => 'Manage Assignments',
                'description' => 'Create and manage assignments'
            ),
            'manage_live_classes' => array(
                'label' => 'Manage Live Classes',
                'description' => 'Schedule and manage live classes'
            ),
            'manage_certificates' => array(
                'label' => 'Manage Certificates',
                'description' => 'Generate certificates and transcripts'
            ),
            'manage_notices' => array(
                'label' => 'Manage Notice Board',
                'description' => 'Create and manage notices'
            ),
            'manage_attendance' => array(
                'label' => 'Manage Attendance',
                'description' => 'Mark and view attendance'
            ),
            'manage_assessments' => array(
                'label' => 'Manage Assessments',
                'description' => 'Enter and manage exam results'
            ),
            'schedule_exams' => array(
                'label' => 'Schedule Exams',
                'description' => 'Create and schedule exams for students'
            ),
            'manage_finance' => array(
                'label' => 'Manage Finance (Income & Expenses)',
                'description' => 'Record income, expenses and view P&L reports'
            ),
            'manage_assets' => array(
                'label' => 'Manage Asset Register',
                'description' => 'Add, edit and view institutional assets'
            ),
        );
        
        // Get MTTI roles (exclude administrator and student)
        $editable_roles = array(
            'mtti_systems_admin' => 'MTTI Systems Admin',
            'mtti_teacher' => 'MTTI Teacher',
            'mtti_registrar' => 'MTTI Registrar',
            'mtti_accountant' => 'MTTI Accountant',
        );
        
        ?>
        <div style="margin-top: 20px;">
            <div style="background:#e7f3ff;padding:15px;margin-bottom:20px;border-left:4px solid #2196F3;border-radius:4px;">
                <strong>💡 Role Permissions Manager:</strong> Customize what each role can do. Check the boxes to grant permissions, uncheck to remove them. 
                Administrator always has full access.
            </div>
            
            <form method="post">
                <?php wp_nonce_field('mtti_role_permissions_action', 'mtti_role_permissions_nonce'); ?>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                    <thead>
                        <tr>
                            <th style="width:200px;">Permission</th>
                            <?php foreach ($editable_roles as $role_slug => $role_name) : ?>
                            <th style="text-align:center;width:140px;">
                                <?php echo esc_html($role_name); ?>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mtti_capabilities as $cap => $cap_info) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($cap_info['label']); ?></strong>
                                <br><span style="color:#666;font-size:11px;"><?php echo esc_html($cap_info['description']); ?></span>
                            </td>
                            <?php foreach ($editable_roles as $role_slug => $role_name) : 
                                $role = get_role($role_slug);
                                $has_cap = $role && $role->has_cap($cap);
                            ?>
                            <td style="text-align:center;">
                                <input type="checkbox" 
                                       name="role_caps[<?php echo esc_attr($role_slug); ?>][<?php echo esc_attr($cap); ?>]" 
                                       value="1"
                                       <?php checked($has_cap); ?>
                                       style="transform:scale(1.3);">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top:20px;padding:15px;background:#fff3e0;border-left:4px solid #FF9800;border-radius:4px;">
                    <strong>⚠️ Note:</strong> 
                    <ul style="margin:10px 0 0 20px;">
                        <li><strong>Administrator</strong> always has full access to all features</li>
                        <li><strong>MTTI Student</strong> role only has access to the Student Portal (frontend)</li>
                        <li>Changes take effect immediately after saving</li>
                        <li>Users need to log out and back in to see permission changes</li>
                    </ul>
                </div>
                
                <p class="submit">
                    <input type="submit" name="mtti_save_role_permissions" class="button button-primary button-large" value="💾 Save Role Permissions">
                    <button type="button" class="button" onclick="resetToDefaults()">🔄 Reset to Defaults</button>
                </p>
            </form>
            
            <!-- Current Users by Role -->
            <div style="margin-top:30px;">
                <h3>👥 Current Users by Role</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Users</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $all_roles = array_merge(array('administrator' => 'Administrator'), $editable_roles, array('mtti_student' => 'MTTI Student'));
                        foreach ($all_roles as $role_slug => $role_name) : 
                            $users = get_users(array('role' => $role_slug));
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($role_name); ?></strong></td>
                            <td>
                                <?php 
                                if ($users) {
                                    $user_names = array();
                                    foreach (array_slice($users, 0, 5) as $user) {
                                        $user_names[] = esc_html($user->display_name);
                                    }
                                    echo implode(', ', $user_names);
                                    if (count($users) > 5) {
                                        echo ' <em>and ' . (count($users) - 5) . ' more...</em>';
                                    }
                                } else {
                                    echo '<span style="color:#999;">No users</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <span style="background:<?php echo count($users) > 0 ? '#4CAF50' : '#ccc'; ?>;color:#fff;padding:2px 10px;border-radius:10px;">
                                    <?php echo count($users); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <script>
        function resetToDefaults() {
            if (confirm('Reset all role permissions to defaults? This will restore the original permission settings.')) {
                // Default permissions
                var defaults = {
                    'mtti_systems_admin': ['manage_mtti', 'manage_students', 'view_students', 'manage_courses', 'manage_enrollments', 'manage_payments', 'manage_assignments', 'manage_live_classes', 'manage_certificates', 'manage_notices', 'manage_attendance', 'manage_assessments'],
                    'mtti_teacher': ['manage_mtti', 'manage_students', 'view_students', 'manage_courses', 'manage_assignments', 'manage_live_classes', 'manage_notices', 'manage_attendance', 'manage_assessments'],
                    'mtti_registrar': ['manage_mtti', 'manage_students', 'view_students', 'manage_courses', 'manage_enrollments', 'manage_certificates', 'manage_notices'],
                    'mtti_accountant': ['manage_mtti', 'manage_students', 'view_students', 'manage_payments', 'manage_enrollments']
                };
                
                // Uncheck all first
                document.querySelectorAll('input[name^="role_caps"]').forEach(function(cb) {
                    cb.checked = false;
                });
                
                // Check defaults
                for (var role in defaults) {
                    defaults[role].forEach(function(cap) {
                        var checkbox = document.querySelector('input[name="role_caps[' + role + '][' + cap + ']"]');
                        if (checkbox) checkbox.checked = true;
                    });
                }
                
                alert('Defaults restored. Click "Save Role Permissions" to apply changes.');
            }
        }
        </script>
        <?php
    }
    
    /**
     * Save role permissions
     */
    private function save_role_permissions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // MTTI capabilities to manage
        $mtti_capabilities = array(
            'manage_mtti',
            'manage_students',
            'view_students',
            'manage_courses',
            'manage_enrollments',
            'manage_payments',
            'manage_assignments',
            'manage_live_classes',
            'manage_certificates',
            'manage_notices',
            'manage_attendance',
            'manage_assessments',
        );
        
        // Roles we can edit
        $editable_roles = array(
            'mtti_systems_admin',
            'mtti_teacher',
            'mtti_registrar',
            'mtti_accountant',
        );
        
        $role_caps = isset($_POST['role_caps']) ? $_POST['role_caps'] : array();
        
        foreach ($editable_roles as $role_slug) {
            $role = get_role($role_slug);
            if (!$role) continue;
            
            foreach ($mtti_capabilities as $cap) {
                $has_permission = isset($role_caps[$role_slug][$cap]) && $role_caps[$role_slug][$cap] == '1';
                
                if ($has_permission) {
                    $role->add_cap($cap);
                } else {
                    $role->remove_cap($cap);
                }
            }
        }
    }
    
    /**
     * Display Materials page
     */
    public function display_materials() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_materials';
        $courses_table = $wpdb->prefix . 'mtti_courses';
        $units_table = $wpdb->prefix . 'mtti_course_units';
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        // Handle form submission
        if (isset($_POST['mtti_material_submit'])) {
            check_admin_referer('mtti_material_action', 'mtti_material_nonce');
            
            // Handle file upload
            $file_url = '';
            $file_type = '';
            $file_size = 0;
            
            if (!empty($_FILES['material_file']['name'])) {
                $upload = wp_handle_upload($_FILES['material_file'], array('test_form' => false));
                if (isset($upload['url'])) {
                    $file_url = $upload['url'];
                    $file_type = pathinfo($upload['file'], PATHINFO_EXTENSION);
                    $file_size = filesize($upload['file']);
                }
            } elseif (!empty($_POST['file_url'])) {
                $file_url = esc_url_raw($_POST['file_url']);
                $file_type = pathinfo($file_url, PATHINFO_EXTENSION);
            }
            
            $data = array(
                'course_id' => intval($_POST['course_id']),
                'unit_id' => !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : null,
                'title' => sanitize_text_field($_POST['title']),
                'description' => sanitize_textarea_field($_POST['description']),
                'file_url' => $file_url,
                'file_type' => $file_type,
                'file_size' => $file_size,
                'status' => sanitize_text_field($_POST['status']),
                'uploaded_by' => get_current_user_id()
            );
            
            if (isset($_POST['material_id']) && $_POST['material_id']) {
                $wpdb->update($table, $data, array('material_id' => intval($_POST['material_id'])));
                $message = 'updated';
            } else {
                $wpdb->insert($table, $data);
                $message = 'added';
            }
            
            wp_redirect(admin_url('admin.php?page=mtti-mis-materials&message=' . $message));
            exit;
        }
        
        // Handle delete
        if ($action == 'delete' && isset($_GET['id'])) {
            $mid = intval($_GET['id']);
            $mat_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE material_id = %d", $mid), ARRAY_A);
            if ($mat_data) {
                require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
                MTTI_MIS_Admin_Trash::create_table();
                $trash = new MTTI_MIS_Admin_Trash();
                $trash->soft_delete('material', $mid, $mat_data['title'] ?: 'Material #' . $mid, $mat_data);
            }
            $wpdb->delete($table, array('material_id' => $mid));
            wp_redirect(admin_url('admin.php?page=mtti-mis-materials&message=deleted'));
            exit;
        }
        
        // Get courses and units for dropdowns
        $courses = $wpdb->get_results("SELECT * FROM {$courses_table} WHERE status = 'Active' ORDER BY course_name");
        $units = $wpdb->get_results("SELECT * FROM {$units_table} WHERE status = 'Active' ORDER BY course_id, unit_code");
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📥 Course Materials</h1>
            
            <?php if ($action == 'list') : ?>
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-materials&action=add'); ?>" class="page-title-action">Add New Material</a>
                <hr class="wp-header-end">
                
                <?php if (isset($_GET['message'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Material <?php echo esc_html($_GET['message']); ?> successfully!</p>
                </div>
                <?php endif; ?>
                
                <?php
                $mat_course_filter = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
                $mat_where = "WHERE m.status != 'Deleted'";
                if ($mat_course_filter) {
                    $mat_where .= $wpdb->prepare(" AND m.course_id = %d", $mat_course_filter);
                }
                // Deduplicate by file_url — keep only the most recently uploaded per unique URL
                $materials = $wpdb->get_results(
                    "SELECT m.*, c.course_name, c.course_code, cu.unit_name, cu.unit_code
                     FROM {$table} m
                     LEFT JOIN {$courses_table} c ON m.course_id = c.course_id
                     LEFT JOIN {$units_table} cu ON m.unit_id = cu.unit_id
                     INNER JOIN (
                         SELECT MAX(material_id) as max_id FROM {$table} GROUP BY COALESCE(file_url, material_id)
                     ) dedup ON m.material_id = dedup.max_id
                     {$mat_where}
                     ORDER BY m.created_at DESC"
                );
                ?>

                <div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <form method="get" style="display:flex;gap:8px;align-items:center;">
                        <input type="hidden" name="page" value="mtti-mis-materials">
                        <select name="course_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #ddd;border-radius:5px;">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?php echo $c->course_id; ?>" <?php selected($mat_course_filter, $c->course_id); ?>>
                                <?php echo esc_html($c->course_code . ' — ' . $c->course_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <span style="font-size:12px;color:#888;">ℹ️ <strong>Materials</strong> = standalone downloadable files. Content-type lessons (video, PDF notes) live under <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons'); ?>">Lessons</a>.</span>
                </div>

                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Course</th>
                            <th>Unit</th>
                            <th>Type</th>
                            <th>Downloads</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($materials) : foreach ($materials as $m) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($m->title); ?></strong></td>
                            <td><?php echo esc_html($m->course_code . ' - ' . $m->course_name); ?></td>
                            <td><?php echo $m->unit_name ? esc_html($m->unit_code) : '-'; ?></td>
                            <td><?php echo strtoupper(esc_html($m->file_type)); ?></td>
                            <td><?php echo intval($m->download_count); ?></td>
                            <td><span class="mtti-status mtti-status-<?php echo strtolower($m->status); ?>"><?php echo esc_html($m->status); ?></span></td>
                            <td>
                                <a href="<?php echo esc_url($m->file_url); ?>" target="_blank">View</a> |
                                <a href="<?php echo admin_url('admin.php?page=mtti-mis-materials&action=edit&id=' . $m->material_id); ?>">Edit</a> |
                                <a href="<?php echo admin_url('admin.php?page=mtti-mis-materials&action=delete&id=' . $m->material_id); ?>" onclick="return confirm('Delete this material?');" style="color: #dc3232;">Delete</a> |
                                <a href="#" onclick="mttiMatGenQuiz(<?php echo intval($m->material_id); ?>,<?php echo intval($m->course_id); ?>,'<?php echo esc_js($m->title); ?>'); return false;" style="color:#7B1FA2;">🤖 Gen Quiz</a>
                            </td>
                        </tr>
                        <?php endforeach; else : ?>
                        <tr><td colspan="7">No materials found. <a href="<?php echo admin_url('admin.php?page=mtti-mis-materials&action=add'); ?>">Add your first material</a>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php // ── AI QUIZ MODAL (shared with lessons page) ── ?>
                <div id="mtti-quiz-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
                    <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:680px;width:94%;max-height:88vh;overflow-y:auto;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.3);">
                        <button onclick="document.getElementById('mtti-quiz-modal').style.display='none';" style="position:absolute;top:14px;right:18px;background:none;border:none;font-size:22px;cursor:pointer;color:#666;">✕</button>
                        <h2 style="margin:0 0 4px;font-size:18px;">🤖 AI Quiz Generator</h2>
                        <p id="mtti-quiz-lesson-name" style="color:#666;font-size:13px;margin:0 0 18px;"></p>
                        <div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                            <label style="font-size:12px;font-weight:700;color:#555;">Questions: <select id="mtti-quiz-count" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;"><option value="5">5</option><option value="8" selected>8</option><option value="10">10</option></select></label>
                            <label style="font-size:12px;font-weight:700;color:#555;">Types: <select id="mtti-quiz-types" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;"><option value="mixed" selected>Mixed</option><option value="mcq">MCQ only</option><option value="fib">Fill-in-Blank</option><option value="short">Short Answer</option></select></label>
                            <label style="font-size:12px;font-weight:700;color:#555;">Difficulty: <select id="mtti-quiz-diff" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></label>
                        </div>
                        <div style="margin-bottom:14px;"><label style="font-size:12px;font-weight:700;color:#555;display:block;margin-bottom:4px;">Extra context / key points (optional):</label><textarea id="mtti-quiz-context" rows="3" placeholder="Paste key points or notes..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:13px;resize:vertical;"></textarea></div>
                        <button id="mtti-quiz-generate-btn" onclick="mttiDoGenerateQuiz()" style="background:#7B1FA2;color:#fff;border:none;border-radius:7px;padding:10px 24px;font-size:14px;font-weight:700;cursor:pointer;width:100%;margin-bottom:14px;">✨ Generate Questions</button>
                        <div id="mtti-quiz-spinner" style="display:none;text-align:center;padding:24px;color:#888;font-size:13px;">⏳ Generating interactive quiz — please wait…</div>
                        <div id="mtti-quiz-result" style="display:none;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                                <h3 style="margin:0;font-size:15px;">Questions Preview</h3>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button onclick="mttiPostQuizToCourse()" id="mtti-quiz-post-btn" style="background:#0a5e2a;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;opacity:.5;" disabled>📤 Post to Course</button>
                                    <a id="mtti-quiz-download-btn" href="#" target="_blank" style="background:#1565C0;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;">⬇ Download HTML</a>
                                    <button onclick="mttiDoGenerateQuiz()" style="background:#555;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">🔄 Regenerate</button>
                                </div>
                            </div>
                            <div id="mtti-quiz-questions-wrap" style="border:1px solid #e0e0e0;border-radius:8px;padding:14px;background:#fafafa;max-height:320px;overflow-y:auto;font-size:13px;line-height:1.7;"></div>
                            <div id="mtti-quiz-status" style="margin-top:8px;font-size:12px;font-weight:700;color:#2E7D32;"></div>
                        </div>
                    </div>
                </div>
                <script>
                var mttiCurrentLessonId=0,mttiCurrentCourseId=0,mttiCurrentLessonTitle='',mttiCurrentFileUrl='';
                function mttiMatGenQuiz(lid,cid,title){
                    mttiCurrentLessonId=lid;mttiCurrentCourseId=cid;mttiCurrentLessonTitle=title;mttiCurrentFileUrl='';
                    document.getElementById('mtti-quiz-lesson-name').innerText='📄 '+title;
                    document.getElementById('mtti-quiz-context').value='';
                    document.getElementById('mtti-quiz-result').style.display='none';
                    document.getElementById('mtti-quiz-spinner').style.display='none';
                    document.getElementById('mtti-quiz-generate-btn').style.display='block';
                    document.getElementById('mtti-quiz-status').innerText='';
                    document.getElementById('mtti-quiz-post-btn').disabled=true;
                    document.getElementById('mtti-quiz-post-btn').style.opacity='.5';
                    document.getElementById('mtti-quiz-modal').style.display='flex';
                }
                function mttiDoGenerateQuiz(){
                    document.getElementById('mtti-quiz-generate-btn').style.display='none';
                    document.getElementById('mtti-quiz-result').style.display='none';
                    document.getElementById('mtti-quiz-spinner').style.display='block';
                    document.getElementById('mtti-quiz-status').innerText='';
                    jQuery.ajax({url:ajaxurl,method:'POST',data:{action:'mtti_ai_generate_quiz',nonce:'<?php echo wp_create_nonce('mtti_ai_quiz'); ?>',lesson_id:mttiCurrentLessonId,course_id:mttiCurrentCourseId,title:mttiCurrentLessonTitle,context:document.getElementById('mtti-quiz-context').value,count:document.getElementById('mtti-quiz-count').value,types:document.getElementById('mtti-quiz-types').value,difficulty:document.getElementById('mtti-quiz-diff').value},
                    success:function(r){
                        document.getElementById('mtti-quiz-spinner').style.display='none';
                        document.getElementById('mtti-quiz-generate-btn').style.display='block';
                        if(r.success){
                            mttiCurrentFileUrl=r.data.file_url;
                            document.getElementById('mtti-quiz-questions-wrap').innerHTML=r.data.html;
                            document.getElementById('mtti-quiz-download-btn').href=r.data.file_url;
                            document.getElementById('mtti-quiz-download-btn').download=r.data.filename;
                            document.getElementById('mtti-quiz-status').innerText='✅ '+r.data.q_count+' questions ready. Click Post to Course.';
                            document.getElementById('mtti-quiz-post-btn').disabled=false;
                            document.getElementById('mtti-quiz-post-btn').style.opacity='1';
                            document.getElementById('mtti-quiz-result').style.display='block';
                        } else { alert('Error: '+(r.data||'Check Claude API key in Settings → MTTI Think Sharp')); }
                    },error:function(){document.getElementById('mtti-quiz-spinner').style.display='none';document.getElementById('mtti-quiz-generate-btn').style.display='block';alert('Network error.');}});
                }
                function mttiPostQuizToCourse(){
                    if(!mttiCurrentCourseId||!mttiCurrentFileUrl) return;
                    var btn=document.getElementById('mtti-quiz-post-btn');
                    var status=document.getElementById('mtti-quiz-status');
                    btn.disabled=true;btn.innerText='⏳ Posting…';
                    jQuery.ajax({url:ajaxurl,method:'POST',data:{action:'mtti_ai_post_quiz_to_course',nonce:'<?php echo wp_create_nonce('mtti_ai_quiz'); ?>',course_id:mttiCurrentCourseId,lesson_id:mttiCurrentLessonId,title:mttiCurrentLessonTitle,file_url:mttiCurrentFileUrl},
                    success:function(r){
                        btn.innerText='📤 Post to Course';
                        if(r.success){btn.innerText='✅ Posted!';btn.style.background='#2E7D32';status.style.color='#2E7D32';status.innerHTML=r.data;}
                        else{btn.disabled=false;status.style.color='#C62828';status.innerText='❌ '+(r.data||'Could not post. Try again.');}
                    },error:function(){btn.disabled=false;btn.innerText='📤 Post to Course';status.style.color='#C62828';status.innerText='❌ Network error.';}});
                }
                </script>
                
            <?php else : 
                $material = null;
                if ($action == 'edit' && isset($_GET['id'])) {
                    $material = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE material_id = %d", intval($_GET['id'])));
                }
            ?>
                <hr class="wp-header-end">
                
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('mtti_material_action', 'mtti_material_nonce'); ?>
                    <?php if ($material) : ?>
                    <input type="hidden" name="material_id" value="<?php echo $material->material_id; ?>">
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="title">Title *</label></th>
                            <td><input type="text" name="title" id="title" class="regular-text" required value="<?php echo $material ? esc_attr($material->title) : ''; ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="course_id">Course *</label></th>
                            <td>
                                <select name="course_id" id="course_id" class="regular-text" required>
                                    <option value="">Select Course</option>
                                    <?php foreach ($courses as $c) : ?>
                                    <option value="<?php echo $c->course_id; ?>" <?php selected($material ? $material->course_id : '', $c->course_id); ?>>
                                        <?php echo esc_html($c->course_code . ' - ' . $c->course_name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="unit_id">Unit (Optional)</label></th>
                            <td>
                                <select name="unit_id" id="unit_id" class="regular-text">
                                    <option value="">All Units / General</option>
                                    <?php foreach ($units as $u) : ?>
                                    <option value="<?php echo $u->unit_id; ?>" <?php selected($material ? $material->unit_id : '', $u->unit_id); ?>>
                                        <?php echo esc_html($u->unit_code . ' - ' . $u->unit_name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="description">Description</label></th>
                            <td><textarea name="description" id="description" rows="3" class="large-text"><?php echo $material ? esc_textarea($material->description) : ''; ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="material_file">Upload File</label></th>
                            <td>
                                <input type="file" name="material_file" id="material_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.mp4,.mp3,.jpg,.png,.txt">
                                <p class="description">Accepted: PDF, DOC, PPT, XLS, ZIP, MP4, MP3, Images (Max 50MB)</p>
                                <?php if ($material && $material->file_url) : ?>
                                <p>Current file: <a href="<?php echo esc_url($material->file_url); ?>" target="_blank"><?php echo esc_html(basename($material->file_url)); ?></a></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="file_url">Or File URL</label></th>
                            <td>
                                <input type="url" name="file_url" id="file_url" class="large-text" value="<?php echo $material ? esc_url($material->file_url) : ''; ?>" placeholder="https://example.com/file.pdf">
                                <p class="description">If not uploading, paste a direct link to the file (Google Drive, Dropbox, etc.)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="status">Status</label></th>
                            <td>
                                <select name="status" id="status" class="regular-text">
                                    <option value="Active" <?php selected($material ? $material->status : '', 'Active'); ?>>Active</option>
                                    <option value="Inactive" <?php selected($material ? $material->status : '', 'Inactive'); ?>>Inactive</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="mtti_material_submit" class="button button-primary" value="<?php echo $material ? 'Update Material' : 'Add Material'; ?>">
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-materials'); ?>" class="button">Cancel</a>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display Lessons page
     */
    public function display_lessons() {
        require_once plugin_dir_path(__FILE__) . 'class-mtti-mis-admin-lessons.php';
        $lessons = new MTTI_MIS_Admin_Lessons($this->plugin_name, $this->version);
        $lessons->display();
    }
    
    /**
     * Display Admission Letters page
     */
    public function display_admission_letters() {
        require_once plugin_dir_path(__FILE__) . 'class-mtti-mis-admin-admission-letters.php';
        $admission_letters = new MTTI_MIS_Admin_Admission_Letters($this->plugin_name, $this->version);
        $admission_letters->display();
    }
}
