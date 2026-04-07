<?php
/**
 * Admission Letters Admin Class
 * 
 * Generates professional admission letters for newly accepted students
 * 
 * @package MTTI_MIS
 * @since 4.4.0
 */

class MTTI_MIS_Admin_Admission_Letters {
    
    private $plugin_name;
    private $version;
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    /**
     * Main display function - routes to appropriate view
     */
    public function display() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
        
        switch ($action) {
            case 'preview':
            case 'print':
                if ($student_id) {
                    $this->display_admission_letter($student_id);
                } else {
                    $this->display_student_selector();
                }
                break;
            default:
                $this->display_student_selector();
        }
    }
    
    /**
     * Display student selector form
     */
    private function display_student_selector() {
        global $wpdb;
        
        // Get all students with their course info
        $students_table = $this->db->get_table_name('students');
        $courses_table = $this->db->get_table_name('courses');
        
        $students = $wpdb->get_results(
            "SELECT s.*, u.display_name, u.user_email, c.course_name, c.course_code, c.fee
             FROM {$students_table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             LEFT JOIN {$courses_table} c ON s.course_id = c.course_id
             ORDER BY s.created_at DESC"
        );
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-media-document" style="vertical-align: middle;"></span>
                Admission Letters
            </h1>
            <hr class="wp-header-end">
            
            <div class="notice notice-info">
                <p><strong>📝 Instructions:</strong> Select a student to generate their official admission letter. The letter includes admission number, course details, fee structure, and important information.</p>
            </div>
            
            <!-- Quick Generate Form -->
            <div class="card" style="max-width: 600px; margin-bottom: 20px;">
                <h2>Quick Generate</h2>
                <form method="get" action="">
                    <input type="hidden" name="page" value="mtti-mis-admission-letters">
                    <input type="hidden" name="action" value="preview">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="student_id">Select Student</label></th>
                            <td>
                                <select name="student_id" id="student_id" class="regular-text" required style="min-width: 400px;">
                                    <option value="">-- Select a Student --</option>
                                    <?php foreach ($students as $student) : ?>
                                    <option value="<?php echo $student->student_id; ?>">
                                        <?php echo esc_html($student->admission_number . ' - ' . ($student->display_name ?: 'Unknown')); ?>
                                        <?php if ($student->course_name) echo ' (' . esc_html($student->course_code) . ')'; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">
                            <span class="dashicons dashicons-media-document" style="vertical-align: middle;"></span>
                            Generate Admission Letter
                        </button>
                    </p>
                </form>
            </div>
            
            <!-- Recent Students Table -->
            <h2>Recent Admissions</h2>
            <p class="description">Click "Generate Letter" to create an admission letter for any student.</p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 15%;">Admission No.</th>
                        <th style="width: 20%;">Student Name</th>
                        <th style="width: 10%;">Email</th>
                        <th style="width: 20%;">Course</th>
                        <th style="width: 10%;">Fee (KES)</th>
                        <th style="width: 10%;">Date</th>
                        <th style="width: 15%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students) : foreach ($students as $student) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($student->admission_number); ?></strong></td>
                        <td><?php echo esc_html($student->display_name ?: 'N/A'); ?></td>
                        <td><?php echo esc_html($student->user_email ?: 'N/A'); ?></td>
                        <td>
                            <?php if ($student->course_name) : ?>
                                <?php echo esc_html($student->course_code . ' - ' . $student->course_name); ?>
                            <?php else : ?>
                                <span style="color: #999;">Not Assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($student->fee) : ?>
                                <?php echo number_format($student->fee, 2); ?>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($student->created_at)); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-admission-letters&action=preview&student_id=' . $student->student_id); ?>" 
                               class="button button-small button-primary">
                                <span class="dashicons dashicons-media-document" style="vertical-align: middle; font-size: 14px;"></span> 
                                Generate Letter
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="7">No students found. <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=add'); ?>">Add your first student</a>.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
        </style>
        <?php
    }
    
    /**
     * Display/Print the admission letter
     */
    private function display_admission_letter($student_id) {
        global $wpdb;
        
        // Get student data with user info
        $students_table    = $this->db->get_table_name('students');
        $courses_table     = $this->db->get_table_name('courses');
        $enrollments_table = $this->db->get_table_name('enrollments');
        $balances_table    = $this->db->get_table_name('student_balances');
        $payments_table    = $this->db->get_table_name('payments');
        
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, u.user_login
             FROM {$students_table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.student_id = %d",
            $student_id
        ));
        
        if (!$student) {
            wp_die('Student not found. <a href="' . admin_url('admin.php?page=mtti-mis-admission-letters') . '">Go back</a>');
        }
        
        // ── ENROLLED COURSES ──────────────────────────────────────────────────
        // Discount source of truth is student_balances.discount_amount — written at
        // enrollment time by initialize_balance(), and kept up-to-date by update_balance()
        // whenever a payment with a discount is recorded.
        //
        // We also read SUM(payments.discount) as a safety net for the edge case where
        // a student's balance row exists but its discount_amount column is 0 because
        // the discount was entered on the payment form before update_balance() was wired
        // to write it back. GREATEST() ensures whichever source has the real number wins.
        $enrolled_courses = $wpdb->get_results($wpdb->prepare(
            "SELECT c.course_id,
                    c.course_name,
                    c.course_code,
                    c.fee,
                    c.duration_weeks,
                    c.category,
                    c.status        AS course_status,
                    e.enrollment_id,
                    e.enrollment_date,
                    e.status        AS enrollment_status,
                    GREATEST(
                        COALESCE(b.discount_amount, 0),
                        COALESCE(pd.payment_discount, 0)
                    ) AS discount_amount
             FROM   {$enrollments_table} e
             JOIN   {$courses_table}     c  ON  c.course_id     = e.course_id
             LEFT JOIN {$balances_table} b  ON  b.enrollment_id = e.enrollment_id
             LEFT JOIN (
                 SELECT enrollment_id, SUM(discount) AS payment_discount
                 FROM   {$payments_table}
                 WHERE  status = 'Completed'
                 GROUP  BY enrollment_id
             ) pd ON pd.enrollment_id = e.enrollment_id
             WHERE  e.student_id = %d
             ORDER  BY e.enrollment_date DESC",
            $student_id
        ));

        // Fallback: student has no enrollment rows — use primary course, zero discount
        if (empty($enrolled_courses) && $student->course_id) {
            $course = $wpdb->get_row($wpdb->prepare(
                "SELECT *, 0 AS discount_amount FROM {$courses_table} WHERE course_id = %d",
                $student->course_id
            ));
            if ($course) {
                $enrolled_courses = array($course);
            }
        }

        // Sum discount across all enrolled courses
        $total_discount = 0;
        foreach ($enrolled_courses as $ec) {
            $total_discount += floatval($ec->discount_amount);
        }
        
        // Get settings
        $settings = get_option('mtti_mis_settings', array());
        $institute_name = $settings['institute_name'] ?? 'Masomotele Technical Training Institute';
        $institute_address = $settings['institute_address'] ?? 'Sagaas Center, Fourth Floor, Eldoret, Kenya';
        $institute_phone = $settings['institute_phone'] ?? '0712464936';
        $institute_email = $settings['institute_email'] ?? 'info@masomoteletraining.co.ke';
        $institute_website = $settings['institute_website'] ?? 'masomoteletraining.co.ke';
        $paybill_number = '880100';
        $account_number = '219391';
        $institute_slogan = '"Start Learning, Start Earning"';
        
        // Calculate dates
        $admission_date = $student->created_at ? date('Y-m-d', strtotime($student->created_at)) : date('Y-m-d');
        
        // Calculate fees for all courses — use stored total_fee (locked at enrollment), fall back to c.fee
        $total_tuition = 0;
        $requires_additional_fees = true;
        $excluded_courses = array('computer applications', 'computer essentials', 'computer & online essentials');

        foreach ($enrolled_courses as $ec) {
            // Prefer stored total_fee from student_balances over current course fee
            $stored = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT total_fee FROM {$balances_table} WHERE enrollment_id = %d LIMIT 1",
                $ec->enrollment_id
            )));
            $ec->fee = $stored > 0 ? $stored : floatval($ec->fee);
            $total_tuition += $ec->fee;
            $course_name_lower = strtolower($ec->course_name);
            foreach ($excluded_courses as $excluded) {
                if (strpos($course_name_lower, $excluded) !== false) {
                    $requires_additional_fees = false;
                    break;
                }
            }
        }
        
        $admission_fee = $requires_additional_fees ? 1500 : 0;
        $total_fee = $total_tuition + $admission_fee;
        $total_payable = $total_fee - $total_discount;
        
        // Get logo URL
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        
        // Output the letter (full page, no WordPress admin wrapper)
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admission Letter - <?php echo esc_html($student->admission_number); ?></title>
            <style>
                /* Print Styles */
                @media print {
                    body { 
                        margin: 0; 
                        padding: 10mm;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                    .no-print { display: none !important; }
                    .letter-container { 
                        box-shadow: none !important;
                        border: none !important;
                    }
                    @page {
                        size: A4;
                        margin: 10mm;
                    }
                    /* Attempt to hide browser print headers and footers */
                    /* Note: Users should also disable "Headers and footers" in print dialog */
                    html {
                        margin: 0;
                        padding: 0;
                    }
                    /* Hide URL in footer - browser dependent */
                    a[href]:after {
                        content: none !important;
                    }
                }
                
                /* Screen Styles */
                @media screen {
                    body { 
                        max-width: 850px; 
                        margin: 0 auto; 
                        padding: 20px;
                        background: #f0f0f1;
                    }
                    .letter-container {
                        background: white;
                        padding: 40px 50px;
                        box-shadow: 0 2px 20px rgba(0,0,0,0.1);
                        border: 1px solid #ddd;
                    }
                }
                
                /* General Styles */
                * {
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Segoe UI', Arial, sans-serif;
                    font-size: 11pt;
                    line-height: 1.6;
                    color: #333;
                }
                
                /* Header */
                .header {
                    text-align: center;
                    border-bottom: 3px solid #1F4E79;
                    padding-bottom: 20px;
                    margin-bottom: 25px;
                }
                
                .header-content {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 20px;
                }
                
                .header img {
                    max-width: 90px;
                    height: auto;
                }
                
                .header-text {
                    text-align: center;
                }
                
                .header h1 {
                    color: #1F4E79;
                    font-size: 22pt;
                    margin: 0 0 5px 0;
                    letter-spacing: 1px;
                }
                
                .header p {
                    margin: 3px 0;
                    color: #555;
                    font-size: 10pt;
                }
                
                .header .motto {
                    font-style: italic;
                    color: #C00000;
                    margin-top: 8px;
                    font-size: 11pt;
                    font-weight: bold;
                }
                
                /* Letter Title */
                .letter-title {
                    text-align: center;
                    font-size: 16pt;
                    font-weight: bold;
                    color: #1F4E79;
                    margin: 25px 0;
                    padding: 10px;
                    background: #f8f9fa;
                    border-left: 4px solid #1F4E79;
                }
                
                /* Reference and Date */
                .ref-date {
                    margin-bottom: 20px;
                    font-size: 10pt;
                }
                
                .ref-date p {
                    margin: 3px 0;
                }
                
                /* Recipient */
                .recipient {
                    margin-bottom: 20px;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 5px;
                }
                
                .recipient p {
                    margin: 3px 0;
                }
                
                /* Section Titles */
                .section-title {
                    color: #1F4E79;
                    font-weight: bold;
                    font-size: 12pt;
                    margin: 25px 0 12px 0;
                    padding: 8px 12px;
                    background: linear-gradient(90deg, #e3f2fd 0%, transparent 100%);
                    border-left: 4px solid #1F4E79;
                }
                
                /* Tables */
                table.details {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 10px 0 20px 0;
                    font-size: 10pt;
                }
                
                table.details th,
                table.details td {
                    border: 1px solid #ddd;
                    padding: 10px 12px;
                    text-align: left;
                }
                
                table.details th {
                    background: #f5f5f5;
                    width: 40%;
                    font-weight: 600;
                    color: #444;
                }
                
                table.details .highlight {
                    font-weight: bold;
                    font-size: 11pt;
                }
                
                table.details .amount {
                    text-align: right;
                    font-weight: bold;
                }
                
                table.details .total-row {
                    background: #e8f5e9;
                }
                
                table.details .total-row td,
                table.details .total-row th {
                    font-size: 12pt;
                    color: #2E7D32;
                    font-weight: bold;
                }
                
                /* Info Lists */
                ul.info-list {
                    margin: 10px 0;
                    padding-left: 25px;
                }
                
                ul.info-list li {
                    margin: 8px 0;
                    position: relative;
                }
                
                /* Payment Note */
                .payment-note {
                    background: #fff8e1;
                    padding: 12px 15px;
                    border-left: 4px solid #f9a825;
                    margin: 15px 0;
                    font-size: 10pt;
                }
                
                .payment-note strong {
                    color: #e65100;
                }
                
                /* Signature Area */
                .signature-area {
                    margin-top: 40px;
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                }
                
                .signature-left {
                    flex: 1;
                }
                
                .signature-line {
                    border-bottom: 1px solid #333;
                    width: 200px;
                    margin: 50px 0 5px 0;
                }
                
                .stamp-area {
                    text-align: center;
                    color: #999;
                    border: 2px dashed #ccc;
                    padding: 25px 20px;
                    border-radius: 5px;
                    font-size: 10pt;
                }
                
                /* Footer */
                .footer {
                    margin-top: 40px;
                    padding-top: 15px;
                    border-top: 2px solid #1F4E79;
                    text-align: center;
                    font-size: 9pt;
                    color: #666;
                }
                
                .footer p {
                    margin: 3px 0;
                }
                
                /* Action Buttons */
                .action-buttons {
                    text-align: center;
                    margin-bottom: 20px;
                    padding: 20px;
                    background: #1F4E79;
                    border-radius: 8px;
                    position: sticky;
                    top: 0;
                    z-index: 100;
                }
                
                .action-buttons button,
                .action-buttons a {
                    display: inline-block;
                    padding: 12px 25px;
                    margin: 5px 10px;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    text-decoration: none;
                    font-size: 14px;
                    font-weight: 500;
                    transition: all 0.3s ease;
                }
                
                .btn-print {
                    background: #4CAF50;
                    color: white;
                }
                
                .btn-print:hover {
                    background: #388E3C;
                    color: white;
                }
                
                .btn-back {
                    background: #fff;
                    color: #333;
                }
                
                .btn-back:hover {
                    background: #f5f5f5;
                }
                
                .btn-student {
                    background: #2196F3;
                    color: white;
                }
                
                .btn-student:hover {
                    background: #1976D2;
                    color: white;
                }
                
                /* Watermark for draft */
                .watermark {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%) rotate(-45deg);
                    font-size: 100px;
                    color: rgba(0,0,0,0.03);
                    pointer-events: none;
                    z-index: -1;
                }
            </style>
        </head>
        <body>
            <!-- Action Buttons (not printed) -->
            <div class="action-buttons no-print">
                <button class="btn-print" onclick="window.print()">
                    🖨️ Print Letter
                </button>
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-admission-letters'); ?>" class="btn-back">
                    ← Back to List
                </a>
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=view&id=' . $student_id); ?>" class="btn-student">
                    👤 View Student
                </a>
            </div>
            
            <div class="letter-container">
                <!-- Watermark -->
                <div class="watermark">M.T.T.I</div>
                
                <!-- Header -->
                <div class="header">
                    <div class="header-content">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" onerror="this.style.display='none'">
                        <div class="header-text">
                            <h1><?php echo esc_html(strtoupper($institute_name)); ?></h1>
                            <p style="color: #2E7D32; font-weight: bold;">TVETA Accredited Institution</p>
                            <p><?php echo esc_html($institute_address); ?></p>
                            <p>Tel: <?php echo esc_html($institute_phone); ?> | Email: <?php echo esc_html($institute_email); ?></p>
                            <p>Website: <?php echo esc_html($institute_website); ?></p>
                            <p class="motto"><?php echo $institute_slogan; ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Letter Title -->
                <div class="letter-title">📄 LETTER OF ADMISSION</div>
                
                <!-- Reference and Date -->
                <div class="ref-date">
                    <p><strong>Ref:</strong> <strong style="color: #C00000;"><?php echo esc_html($student->admission_number); ?></strong></p>
                    <p><strong>Date:</strong> <?php echo date('F j, Y'); ?></p>
                </div>
                
                <!-- Recipient -->
                <div class="recipient">
                    <p><strong style="color: #C00000;"><?php echo esc_html($student->display_name ?: 'Student'); ?></strong></p>
                </div>
                
                <!-- Salutation -->
                <p>Dear <strong style="color: #C00000;"><?php 
                    $first_name = $student->display_name ? explode(' ', $student->display_name)[0] : 'Student';
                    echo esc_html($first_name); 
                ?></strong>,</p>
                
                <!-- Subject -->
                <?php 
                // Build course names list for subject line
                $course_names = array();
                foreach ($enrolled_courses as $ec) {
                    $course_names[] = strtoupper($ec->course_name);
                }
                $courses_text = !empty($course_names) ? implode(', ', $course_names) : 'YOUR SELECTED COURSE(S)';
                ?>
                <p style="margin: 15px 0;">
                    <strong>RE: OFFER OF ADMISSION TO 
                    <span style="color: #1F4E79; text-decoration: underline;">
                        <?php echo esc_html($courses_text); ?>
                    </span>
                    </strong>
                </p>
                
                <!-- Body -->
                <p>We are pleased to inform you that your application for admission to <strong><?php echo esc_html($institute_name); ?></strong> has been <span style="color: #2E7D32; font-weight: bold;">SUCCESSFUL</span>.</p>
                
                <p>Congratulations on being accepted into our institution! Please find below your admission details, course information, and fee structure:</p>
                
                <!-- Student Details Section -->
                <h3 class="section-title">1. STUDENT INFORMATION</h3>
                <table class="details">
                    <tr>
                        <th>Admission Number</th>
                        <td class="highlight"><?php echo esc_html($student->admission_number); ?></td>
                    </tr>
                    <tr>
                        <th>Full Name</th>
                        <td><strong style="color: #C00000;"><?php echo esc_html($student->display_name ?: 'N/A'); ?></strong></td>
                    </tr>
                    <?php if ($student->id_number) : ?>
                    <tr>
                        <th>ID/Passport Number</th>
                        <td><?php echo esc_html($student->id_number); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($student->date_of_birth) : ?>
                    <tr>
                        <th>Date of Birth</th>
                        <td><?php echo date('F j, Y', strtotime($student->date_of_birth)); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($student->gender) : ?>
                    <tr>
                        <th>Gender</th>
                        <td><?php echo esc_html($student->gender); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($student->phone) : ?>
                    <tr>
                        <th>Phone Number</th>
                        <td><?php echo esc_html($student->phone); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <?php if (!empty($enrolled_courses)) : ?>
                <!-- Course Details Section -->
                <h3 class="section-title">2. COURSE DETAILS</h3>
                <?php 
                $course_num = 0;
                foreach ($enrolled_courses as $course) : 
                    $course_num++;
                ?>
                <?php if (count($enrolled_courses) > 1) : ?>
                <p style="font-weight: bold; color: #1F4E79; margin: 15px 0 5px 0;">Course <?php echo $course_num; ?>:</p>
                <?php endif; ?>
                <table class="details">
                    <tr>
                        <th>Course Name</th>
                        <td><strong><?php echo esc_html($course->course_name); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Course Code</th>
                        <td><?php echo esc_html($course->course_code); ?></td>
                    </tr>
                    <tr>
                        <th>Duration</th>
                        <td><?php echo esc_html($course->duration_weeks); ?> Weeks</td>
                    </tr>
                    <tr>
                        <th>Mode of Study</th>
                        <td>Full-Time / Part-Time (as applicable)</td>
                    </tr>
                </table>
                <?php endforeach; ?>
                
                <!-- Fee Structure Section -->
                <h3 class="section-title">3. FEE STRUCTURE</h3>
                <table class="details">
                    <?php if (count($enrolled_courses) > 1) : ?>
                    <!-- Show individual course fees when multiple courses -->
                    <?php foreach ($enrolled_courses as $idx => $course) : ?>
                    <tr>
                        <th><?php echo esc_html($course->course_name); ?></th>
                        <td class="amount">KES <?php echo number_format($course->fee, 2); ?></td>
                    </tr>
                    <?php if (floatval($course->discount_amount) > 0) : ?>
                    <tr style="background: #fff3e0;">
                        <th style="color: #e65100; padding-left: 25px;">&#x21b3; Discount (<?php echo esc_html($course->course_name); ?>)</th>
                        <td class="amount" style="color: #e65100;">- KES <?php echo number_format($course->discount_amount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <tr style="background: #e3f2fd;">
                        <th>Sub-Total (Tuition)</th>
                        <td class="amount"><strong>KES <?php echo number_format($total_tuition - $total_discount, 2); ?></strong></td>
                    </tr>
                    <?php else : ?>
                    <!-- Single course: show gross fee then discount immediately below -->
                    <tr>
                        <th>Tuition Fee</th>
                        <td class="amount">KES <?php echo number_format($total_tuition, 2); ?></td>
                    </tr>
                    <?php if ($total_discount > 0) : ?>
                    <tr style="background: #fff3e0;">
                        <th style="color: #e65100; padding-left: 25px;">&#x21b3; Discount/Scholarship</th>
                        <td class="amount" style="color: #e65100;">- KES <?php echo number_format($total_discount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($requires_additional_fees) : ?>
                    <tr>
                        <th>Admission Fee</th>
                        <td class="amount">KES <?php echo number_format($admission_fee, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Examination Fee</th>
                        <td class="amount">Included</td>
                    </tr>
                    <tr class="total-row">
                        <th>TOTAL PAYABLE</th>
                        <td class="amount">KES <?php echo number_format($total_payable, 2); ?></td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <!-- Payment Instructions Section -->
                <h3 class="section-title">4. PAYMENT INSTRUCTIONS</h3>
                <p>All fees should be paid via <strong>M-Pesa</strong> using the details below:</p>
                
                <div style="background: #e8f5e9; padding: 15px 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #4CAF50;">
                    <table style="width: 100%; border: none;">
                        <tr>
                            <td style="border: none; padding: 5px 0; width: 40%;"><strong>📱 M-Pesa Paybill Number:</strong></td>
                            <td style="border: none; padding: 5px 0; font-size: 14pt; color: #2E7D32;"><strong><?php echo esc_html($paybill_number); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="border: none; padding: 5px 0;"><strong>📝 Account Number:</strong></td>
                            <td style="border: none; padding: 5px 0; font-size: 14pt; color: #2E7D32;"><strong><?php echo esc_html($account_number); ?></strong></td>
                        </tr>
                    </table>
                </div>
                
                <p style="margin-top: 20px;">Alternatively, you may pay via <strong>Bank Transfer</strong>:</p>
                
                <div style="background: #e3f2fd; padding: 15px 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #1976D2;">
                    <table style="width: 100%; border: none;">
                        <tr>
                            <td style="border: none; padding: 5px 0; width: 40%;"><strong>🏦 Bank Name:</strong></td>
                            <td style="border: none; padding: 5px 0; font-size: 14pt; color: #1565C0;"><strong>NCBA Bank</strong></td>
                        </tr>
                        <tr>
                            <td style="border: none; padding: 5px 0;"><strong>📝 Account Number:</strong></td>
                            <td style="border: none; padding: 5px 0; font-size: 14pt; color: #1565C0;"><strong>1006329155</strong></td>
                        </tr>
                        <tr>
                            <td style="border: none; padding: 5px 0;"><strong>👤 Account Name:</strong></td>
                            <td style="border: none; padding: 5px 0; font-size: 14pt; color: #1565C0;"><strong>Masomotele Technical Training Institute</strong></td>
                        </tr>
                    </table>
                </div>
                
                <div class="payment-note">
                    <strong>⚠️ Important:</strong> For M-Pesa, use Account Number <strong><?php echo esc_html($account_number); ?></strong>. For Bank Transfer, use Account <strong>1006329155</strong>. Keep your payment confirmation as proof of payment.
                </div>
                
                <!-- Important Information Section -->
                <?php
                // Check if student is enrolled in Health Support / Nursing Assistant course
                $is_health_support = false;
                foreach ($enrolled_courses as $ec) {
                    $cn = strtolower($ec->course_name);
                    if (strpos($cn, 'health') !== false || strpos($cn, 'nursing') !== false || strpos($cn, 'healthcare') !== false) {
                        $is_health_support = true;
                        break;
                    }
                }
                ?>
                <h3 class="section-title">5. IMPORTANT INFORMATION</h3>
                <ul class="info-list">
                    <li>📅 Classes run <strong>Monday to Friday, 8:00 AM - 8:00 PM</strong></li>
                    <li>🏢 Report to the <strong>Administration Office</strong> on your first day for orientation and class allocation</li>
                    <li>📄 Bring this admission letter, a <strong>copy of your National ID/Passport</strong>, and <strong>2 passport-size photos</strong></li>
                    <li>💰 <strong>At least 50% of the total fee must be paid during intake</strong></li>
                    <li>📊 Maintain <strong>100% attendance</strong> to qualify for certification</li>
                    <li>📱 <strong>Phones must be on silent mode</strong> during classes</li>
                    <li>📚 All course materials will be provided; bring your own notebook and pen</li>
                    <?php if ($is_health_support) : ?>
                    <li>📋 <strong>Health Support Assistants:</strong> You are required to bring <strong>1 Ream of Paper (A4)</strong> for your coursework and practical assessments</li>
                    <?php endif; ?>
                    <li>👔 Dress code: Smart casual attire is required at all times</li>
                    <li>📜 Read and adhere to the Institute's <strong>Code of Conduct</strong></li>
                </ul>
                
                <?php if ($student->emergency_contact) : ?>
                <!-- Emergency Contact -->
                <h3 class="section-title">6. EMERGENCY CONTACT (On File)</h3>
                <table class="details">
                    <tr>
                        <th>Contact Person</th>
                        <td><?php echo esc_html($student->emergency_contact); ?></td>
                    </tr>
                    <?php if ($student->emergency_phone) : ?>
                    <tr>
                        <th>Contact Phone</th>
                        <td><?php echo esc_html($student->emergency_phone); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
                
                <!-- Closing -->
                <p style="margin-top: 25px;">We look forward to welcoming you to <strong><?php echo esc_html($institute_name); ?></strong> and supporting you on your journey to acquiring valuable skills for employment and entrepreneurship.</p>
                
                <p><strong style="color: #2E7D32;">🎉 Congratulations once again on your admission!</strong></p>
                
                <!-- Signature Area -->
                <div class="signature-area">
                    <div class="signature-left">
                        <p>Yours faithfully,</p>
                        <div class="signature-line"></div>
                        <p style="margin: 0;"><strong>Principal</strong></p>
                        <p style="margin: 0; font-size: 10pt; color: #666;"><?php echo esc_html($institute_name); ?></p>
                    </div>
                    
                    <div class="stamp-area">
                        <p style="margin: 0;">[OFFICIAL STAMP]</p>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="footer">
                    <p style="font-weight: bold; color: #1F4E79;"><?php echo esc_html($institute_name); ?></p>
                    <p style="font-style: italic; color: #C00000; font-size: 11pt; font-weight: bold;"><?php echo $institute_slogan; ?></p>
                </div>
            </div>
            
            <!-- Print Instructions (screen only) -->
            <div class="no-print" style="text-align: center; padding: 20px; margin-top: 20px; background: #fff3e0; border-radius: 8px; border: 1px solid #FF9800;">
                <p style="margin: 0; color: #E65100;"><strong>📝 Print Tip:</strong> To remove the URL from the bottom of the printed page, in your browser's Print dialog, look for "Headers and footers" or "More settings" and disable/uncheck it.</p>
            </div>
            
            <script>
                // Auto-print if action is print
                <?php if (isset($_GET['action']) && $_GET['action'] === 'print') : ?>
                window.onload = function() {
                    window.print();
                };
                <?php endif; ?>
            </script>
        </body>
        </html>
        <?php
        exit; // Stop WordPress from loading admin footer
    }
}
