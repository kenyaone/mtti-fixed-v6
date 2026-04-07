<?php
/**
 * Database helper class
 */
class MTTI_MIS_Database {

    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_table_name($table) {
        global $wpdb;
        return $wpdb->prefix . 'mtti_' . $table;
    }

    // Students
    public function get_students($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('students');
        
        $where = "WHERE 1=1";
        if (!empty($args['status'])) {
            $where .= $wpdb->prepare(" AND s.status = %s", $args['status']);
        }
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= $wpdb->prepare(
                " AND (s.admission_number LIKE %s OR s.id_number LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR s.emergency_contact LIKE %s)", 
                $search, $search, $search, $search, $search
            );
        }
        
        $limit = isset($args['limit']) ? intval($args['limit']) : 200;
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;
        
        $sql = "SELECT s.*, u.display_name, u.user_email 
                FROM {$table} s 
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
                {$where} 
                ORDER BY s.created_at DESC 
                LIMIT {$limit} OFFSET {$offset}";
        
        return $wpdb->get_results($sql);
    }

    public function get_student($student_id) {
        global $wpdb;
        $table = $this->get_table_name('students');
        
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, u.user_login
             FROM {$table} s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             WHERE s.student_id = %d",
            $student_id
        ));
        
        // Add first_name and last_name from user meta if user is linked
        if ($student && $student->user_id) {
            $student->first_name = get_user_meta($student->user_id, 'first_name', true);
            $student->last_name = get_user_meta($student->user_id, 'last_name', true);
        } else {
            $student->first_name = '';
            $student->last_name = '';
        }
        
        return $student;
    }

    public function create_student($data) {
        global $wpdb;
        $table = $this->get_table_name('students');
        
        // Generate admission number
        if (empty($data['admission_number'])) {
            $data['admission_number'] = $this->generate_admission_number($data['course_id']);
        }
        
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    public function update_student($student_id, $data) {
        global $wpdb;
        $table = $this->get_table_name('students');
        
        return $wpdb->update($table, $data, array('student_id' => $student_id));
    }

    // Courses
    public function get_courses($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('courses');
        
        $where = "WHERE 1=1";
        if (!empty($args['status'])) {
            $where .= $wpdb->prepare(" AND status = %s", $args['status']);
        }
        if (!empty($args['category'])) {
            $where .= $wpdb->prepare(" AND category = %s", $args['category']);
        }
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= $wpdb->prepare(
                " AND (course_code LIKE %s OR course_name LIKE %s OR category LIKE %s OR description LIKE %s)", 
                $search, $search, $search, $search
            );
        }
        
        return $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY course_name ASC");
    }

    public function get_course($course_id) {
        global $wpdb;
        $table = $this->get_table_name('courses');
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE course_id = %d", $course_id));
    }

    public function create_course($data) {
        global $wpdb;
        $table = $this->get_table_name('courses');
        
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    public function update_course($course_id, $data) {
        global $wpdb;
        $table = $this->get_table_name('courses');
        
        return $wpdb->update($table, $data, array('course_id' => $course_id));
    }

    // Course Units
    public function get_course_units($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('course_units');
        $courses_table = $this->get_table_name('courses');
        
        $where = "WHERE 1=1";
        if (!empty($args['course_id'])) {
            $where .= $wpdb->prepare(" AND cu.course_id = %d", $args['course_id']);
        }
        if (!empty($args['status'])) {
            $where .= $wpdb->prepare(" AND cu.status = %s", $args['status']);
        }
        
        $sql = "SELECT cu.*, c.course_name, c.course_code
                FROM {$table} cu
                LEFT JOIN {$courses_table} c ON cu.course_id = c.course_id
                {$where}
                ORDER BY cu.course_id, cu.order_number ASC";
        
        return $wpdb->get_results($sql);
    }

    public function get_course_unit($unit_id) {
        global $wpdb;
        $table = $this->get_table_name('course_units');
        
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE unit_id = %d", $unit_id));
    }

    public function create_course_unit($data) {
        global $wpdb;
        $table = $this->get_table_name('course_units');
        
        // Auto-generate unit code if not provided
        if (empty($data['unit_code'])) {
            $data['unit_code'] = $this->generate_unit_code($data['course_id']);
        }
        
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    public function update_course_unit($unit_id, $data) {
        global $wpdb;
        $table = $this->get_table_name('course_units');
        
        return $wpdb->update($table, $data, array('unit_id' => $unit_id));
    }

    public function delete_course_unit($unit_id) {
        global $wpdb;
        $table = $this->get_table_name('course_units');
        
        return $wpdb->delete($table, array('unit_id' => $unit_id));
    }

    // Enrollments
    public function get_enrollments($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('enrollments');
        $students_table = $this->get_table_name('students');
        $courses_table = $this->get_table_name('courses');
        
        $where = "WHERE 1=1";
        if (!empty($args['student_id'])) {
            $where .= $wpdb->prepare(" AND e.student_id = %d", $args['student_id']);
        }
        if (!empty($args['course_id'])) {
            $where .= $wpdb->prepare(" AND e.course_id = %d", $args['course_id']);
        }
        if (!empty($args['status'])) {
            $where .= $wpdb->prepare(" AND e.status = %s", $args['status']);
        }
        
        $sql = "SELECT e.*, 
                       s.admission_number, 
                       u.display_name as student_name,
                       c.course_name, 
                       c.course_code, 
                       c.fee
                FROM {$table} e
                LEFT JOIN {$students_table} s ON e.student_id = s.student_id
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                LEFT JOIN {$courses_table} c ON e.course_id = c.course_id
                {$where}
                ORDER BY e.created_at DESC";
        
        return $wpdb->get_results($sql);
    }

    public function create_enrollment($data) {
        global $wpdb;
        $table = $this->get_table_name('enrollments');
        
        // Extract discount_amount before inserting (it's not an enrollment table field)
        $discount = isset($data['discount_amount']) ? floatval($data['discount_amount']) : 0;
        unset($data['discount_amount']);  // Remove from enrollment data
        
        $wpdb->insert($table, $data);
        $enrollment_id = $wpdb->insert_id;
        
        // Initialize balance with discount
        $this->initialize_balance($enrollment_id, $data['student_id'], $data['course_id'], $discount);
        
        return $enrollment_id;
    }

    public function update_enrollment($enrollment_id, $data) {
        global $wpdb;
        $table = $this->get_table_name('enrollments');
        
        return $wpdb->update($table, $data, array('enrollment_id' => $enrollment_id));
    }

    // Payments
    public function get_payments($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('payments');
        $students_table = $this->get_table_name('students');
        $enrollments_table = $this->get_table_name('enrollments');
        
        $where = "WHERE 1=1";
        if (!empty($args['student_id'])) {
            $where .= $wpdb->prepare(" AND p.student_id = %d", $args['student_id']);
        }
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= $wpdb->prepare(
                " AND (p.receipt_number LIKE %s OR s.admission_number LIKE %s OR p.transaction_reference LIKE %s OR p.payment_method LIKE %s)", 
                $search, $search, $search, $search
            );
        }
        
        $sql = "SELECT p.*, s.admission_number
                FROM {$table} p
                LEFT JOIN {$students_table} s ON p.student_id = s.student_id
                LEFT JOIN {$enrollments_table} e ON p.enrollment_id = e.enrollment_id
                {$where}
                ORDER BY p.created_at DESC";
        
        return $wpdb->get_results($sql);
    }

    public function create_payment($data) {
        global $wpdb;
        $table = $this->get_table_name('payments');
        
        // Generate receipt number
        if (empty($data['receipt_number'])) {
            $data['receipt_number'] = $this->generate_receipt_number();
        }
        
        $wpdb->insert($table, $data);
        $payment_id = $wpdb->insert_id;
        
        // Update balance automatically (pass discount if present)
        if (!empty($data['enrollment_id'])) {
            $discount = isset($data['discount']) ? floatval($data['discount']) : 0;
            $this->update_balance($data['enrollment_id'], $data['amount'], $discount);
        }
        
        return $payment_id;
    }

    // Balances
    private function initialize_balance($enrollment_id, $student_id, $course_id, $discount = 0) {
        global $wpdb;
        $balances_table = $this->get_table_name('student_balances');
        $courses_table = $this->get_table_name('courses');
        
        // Get course fee
        $course = $wpdb->get_row($wpdb->prepare("SELECT fee FROM {$courses_table} WHERE course_id = %d", $course_id));
        
        $total_fee = floatval($course->fee);
        $discount_amount = floatval($discount);
        $balance = $total_fee - $discount_amount;
        
        // Ensure balance is not negative
        if ($balance < 0) {
            $balance = 0;
        }
        
        $wpdb->insert($balances_table, array(
            'student_id' => $student_id,
            'enrollment_id' => $enrollment_id,
            'total_fee' => $total_fee,
            'discount_amount' => $discount_amount,
            'total_paid' => 0,
            'balance' => $balance  // Balance = Total Fee - Discount
        ));
    }

    private function update_balance($enrollment_id, $payment_amount, $payment_discount = 0) {
        global $wpdb;
        $table = $this->get_table_name('student_balances');
        
        // Get current balance record
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE enrollment_id = %d",
            $enrollment_id
        ));
        
        if ($current) {
            $new_total_paid = $current->total_paid + $payment_amount;
            
            // Accumulate discount: use max of existing stored discount vs new running total
            // Re-sum all payments to get accurate total discount (most reliable)
            $payments_table = $this->get_table_name('payments');
            $total_discount = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(discount), 0) FROM {$payments_table}
                 WHERE enrollment_id = %d AND status = 'Completed'",
                $enrollment_id
            )));
            // Include the current payment's discount if not yet committed
            $total_discount = max($total_discount, floatval($current->discount_amount) + $payment_discount);
            
            $new_balance = $current->total_fee - $total_discount - $new_total_paid;
            
            // Ensure balance doesn't go negative
            if ($new_balance < 0) $new_balance = 0;
            
            $wpdb->update(
                $table,
                array(
                    'total_paid'      => $new_total_paid,
                    'discount_amount' => $total_discount,
                    'balance'         => $new_balance,
                    'last_payment_date' => current_time('Y-m-d')
                ),
                array('enrollment_id' => $enrollment_id)
            );
        }
    }

    public function get_student_balance($student_id) {
        global $wpdb;
        $table = $this->get_table_name('student_balances');
        $courses_table = $this->get_table_name('courses');
        $enrollments_table = $this->get_table_name('enrollments');
        $payments_table = $this->get_table_name('payments');
        
        // Get balance records with course info - ONLY for ACTIVE enrollments
        // Use INNER JOINs to ensure we only get balances tied to valid, active enrollments
        $balances = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, c.course_name, c.fee as course_fee, e.enrollment_id as e_enrollment_id, e.course_id
             FROM {$table} b
             INNER JOIN {$enrollments_table} e ON b.enrollment_id = e.enrollment_id
             INNER JOIN {$courses_table} c ON e.course_id = c.course_id
             WHERE b.student_id = %d 
               AND e.student_id = %d
               AND e.status IN ('Active', 'Enrolled', 'In Progress')",
            $student_id, $student_id
        ));
        
        if (empty($balances)) {
            return array();
        }
        
        // Calculate actual payments per enrollment from payments table
        foreach ($balances as &$balance) {
            // Use the current course fee from the courses table (course_fee from JOIN)
            // This prevents stale/wrong values in student_balances.total_fee from affecting display
            $actual_course_fee = floatval($balance->course_fee);

            // FEE PROTECTION (universal):
            // A student's fee is locked in at the time of enrollment in student_balances.
            // If total_fee is already set (> 0), we NEVER overwrite it — even if the
            // course fee has since been changed. Fee changes only affect new enrollments.
            // We only auto-set total_fee when it is missing (0 or NULL) — e.g. old records
            // that were created before the fee-locking system was introduced.
            $stored_fee = floatval($balance->total_fee);
            if ( $stored_fee <= 0 && $actual_course_fee > 0 ) {
                // Fee was never stored — set it now from the current course fee
                $discount_stored = floatval($balance->discount_amount ?? 0);
                $corrected_balance_val = max(0, $actual_course_fee - $discount_stored);
                $wpdb->update(
                    $table,
                    array(
                        'total_fee'       => $actual_course_fee,
                        'discount_amount' => $discount_stored,
                        'balance'         => $corrected_balance_val
                    ),
                    array('enrollment_id' => $balance->enrollment_id),
                    array('%f', '%f', '%f'),
                    array('%d')
                );
                $balance->total_fee = $actual_course_fee;
            }
            // If stored_fee > 0, use it as-is regardless of current course fee.
            
            $fee = floatval($balance->total_fee);
            $discount = floatval($balance->discount_amount ?? 0);
            $net_fee = $fee - $discount;
            
            // Get actual total paid for THIS specific enrollment
            $enrollment_paid = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table} 
                 WHERE student_id = %d AND enrollment_id = %d AND status = 'Completed'",
                $student_id, $balance->enrollment_id
            )));
            
            $balance->total_paid = $enrollment_paid;
            $balance->balance = max(0, $net_fee - $enrollment_paid);
        }
        
        // Also handle payments not tied to a specific enrollment (legacy/unlinked payments)
        $unlinked_paid = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(p.amount), 0) FROM {$payments_table} p
             WHERE p.student_id = %d AND p.status = 'Completed'
               AND (p.enrollment_id IS NULL OR p.enrollment_id = 0
                    OR p.enrollment_id NOT IN (
                        SELECT e.enrollment_id FROM {$enrollments_table} e 
                        WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
                    ))",
            $student_id, $student_id
        )));
        
        // Distribute unlinked payments across balances (first balance first)
        if ($unlinked_paid > 0) {
            foreach ($balances as &$balance) {
                if ($unlinked_paid <= 0) break;
                if ($balance->balance > 0) {
                    $apply = min($unlinked_paid, $balance->balance);
                    $balance->total_paid += $apply;
                    $balance->balance -= $apply;
                    $unlinked_paid -= $apply;
                }
            }
        }
        
        return $balances;
    }

    // Assignments
    public function get_assignments($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('assignments');
        $courses_table = $this->get_table_name('courses');
        
        $where = "WHERE 1=1";
        if (!empty($args['course_id'])) {
            $where .= $wpdb->prepare(" AND a.course_id = %d", $args['course_id']);
        }
        if (!empty($args['status'])) {
            $where .= $wpdb->prepare(" AND a.status = %s", $args['status']);
        }
        
        $sql = "SELECT a.*, c.course_name, c.course_code
                FROM {$table} a
                LEFT JOIN {$courses_table} c ON a.course_id = c.course_id
                {$where}
                ORDER BY a.due_date DESC";
        
        return $wpdb->get_results($sql);
    }

    public function create_assignment($data) {
        global $wpdb;
        $table = $this->get_table_name('assignments');
        
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    // Assignment Submissions
    public function get_submissions($assignment_id) {
        global $wpdb;
        $table = $this->get_table_name('assignment_submissions');
        $students_table = $this->get_table_name('students');
        
        $sql = "SELECT sub.*, s.admission_number
                FROM {$table} sub
                LEFT JOIN {$students_table} s ON sub.student_id = s.student_id
                WHERE sub.assignment_id = %d
                ORDER BY sub.submitted_at DESC";
        
        return $wpdb->get_results($wpdb->prepare($sql, $assignment_id));
    }

    public function create_submission($data) {
        global $wpdb;
        $table = $this->get_table_name('assignment_submissions');
        
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    public function update_submission($submission_id, $data) {
        global $wpdb;
        $table = $this->get_table_name('assignment_submissions');
        
        return $wpdb->update($table, $data, array('submission_id' => $submission_id));
    }

    // Live Classes
    public function get_live_classes($args = array()) {
        global $wpdb;
        $table = $this->get_table_name('live_classes');
        $courses_table = $this->get_table_name('courses');
        
        $where = "WHERE 1=1";
        if (!empty($args['course_id'])) {
            $where .= $wpdb->prepare(" AND lc.course_id = %d", $args['course_id']);
        }
        if (!empty($args['status'])) {
            $where .= $wpdb->prepare(" AND lc.status = %s", $args['status']);
        }
        
        $sql = "SELECT lc.*, c.course_name, c.course_code
                FROM {$table} lc
                LEFT JOIN {$courses_table} c ON lc.course_id = c.course_id
                {$where}
                ORDER BY lc.scheduled_date DESC";
        
        return $wpdb->get_results($sql);
    }

    public function create_live_class($data) {
        global $wpdb;
        $table = $this->get_table_name('live_classes');
        
        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    public function update_live_class($class_id, $data) {
        global $wpdb;
        $table = $this->get_table_name('live_classes');
        
        return $wpdb->update($table, $data, array('class_id' => $class_id));
    }

    // Helper functions
    public function generate_admission_number($course_id = null) {
        global $wpdb;
        $year = date('Y');
        
        if ($course_id) {
            $courses_table = $this->get_table_name('courses');
            $course = $wpdb->get_row($wpdb->prepare("SELECT course_code FROM {$courses_table} WHERE course_id = %d", $course_id));
            $prefix = strtoupper(substr($course->course_code, 0, 3));
        } else {
            $prefix = 'STU';
        }
        
        $students_table = $this->get_table_name('students');
        $last_number = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(admission_number, -4) AS UNSIGNED)) 
             FROM {$students_table} 
             WHERE admission_number LIKE %s",
            $prefix . '/' . $year . '/%'
        ));
        
        $next_number = ($last_number ? $last_number : 0) + 1;
        
        return $prefix . '/' . $year . '/' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
    }

    private function generate_receipt_number() {
        global $wpdb;
        $year = date('Y');
        $month = date('m');
        
        $table = $this->get_table_name('payments');
        $last_number = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(receipt_number, -4) AS UNSIGNED)) 
             FROM {$table} 
             WHERE receipt_number LIKE %s",
            'MTTI/RCT/' . $year . '/' . $month . '/%'
        ));
        
        $next_number = ($last_number ? $last_number : 0) + 1;
        
        return 'MTTI/RCT/' . $year . '/' . $month . '/' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Auto-generate unit code based on course code
     */
    public function generate_unit_code($course_id) {
        global $wpdb;
        
        // Get course code
        $courses_table = $this->get_table_name('courses');
        $course = $wpdb->get_row($wpdb->prepare(
            "SELECT course_code FROM {$courses_table} WHERE course_id = %d",
            $course_id
        ));
        
        if (!$course) {
            return 'UNIT-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        }
        
        // Get last unit number for this course
        $units_table = $this->get_table_name('course_units');
        $last_number = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(unit_code, -3) AS UNSIGNED)) 
             FROM {$units_table} 
             WHERE course_id = %d AND unit_code LIKE %s",
            $course_id,
            $course->course_code . '-%'
        ));
        
        $next_number = ($last_number ? $last_number : 0) + 1;
        
        return $course->course_code . '-' . str_pad($next_number, 3, '0', STR_PAD_LEFT);
    }
    
    /**
     * Calculate grade based on score (percentage-based, max 100)
     * Grading System:
     * A (DISTINCTION): 80-100%
     * B+ (CREDIT): 75-79%
     * B (CREDIT): 70-74%
     * B- (CREDIT): 65-69%
     * C+ (PASS): 60-64%
     * C (PASS): 55-59%
     * C- (PASS): 50-54%
     * D+ (REFER): 45-49%
     * D (REFER): 40-44%
     * D- (REFER): 35-39%
     * E (FAIL): 0-34%
     */
    public function calculate_grade($score, $max_score = 100) {
        // Calculate percentage
        $percentage = ($score / $max_score) * 100;
        
        // Simple category-based grading (max 100 marks)
        if ($percentage >= 80) {
            return 'DISTINCTION';
        } elseif ($percentage >= 60) {
            return 'CREDIT';
        } elseif ($percentage >= 50) {
            return 'PASS';
        } else {
            return 'REFER';
        }
    }
    
    /**
     * Check if grade is passing
     */
    public function is_passing_grade($grade) {
        return $grade !== 'REFER';
    }

    /**
     * Returns TRUE if this enrollment must keep its original (pre-increase) fee.
     *
     * Rule: Cybersecurity, Computer Repair, and Mobile Repair had fees raised.
     * Any student enrolled in those courses strictly BEFORE 02 March 2024 is
     * protected — their stored total_fee in student_balances must never be
     * overwritten with the new course fee.
     *
     * @param int $enrollment_id
     * @param int $course_id
     * @return bool
     */
    private function is_legacy_fee_protected($enrollment_id, $course_id) {
        global $wpdb;
        $balances_table = $this->get_table_name('student_balances');
        $courses_table  = $this->get_table_name('courses');

        // Universal protection: if the fee stored in student_balances at enrollment time
        // differs from the current course fee, the stored fee is the original/correct one.
        // We ALWAYS protect it — regardless of course name or enrollment date.
        // This means a student's balance is NEVER recalculated based on a fee change
        // that happened after they enrolled.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT b.total_fee as stored_fee, c.fee as current_fee
             FROM {$balances_table} b
             INNER JOIN {$courses_table} c ON c.course_id = %d
             WHERE b.enrollment_id = %d
             LIMIT 1",
            $course_id,
            $enrollment_id
        ));

        if ( ! $row ) {
            return false;
        }

        $stored  = floatval($row->stored_fee);
        $current = floatval($row->current_fee);

        // If the stored fee is already equal to the current fee, no conflict — not protected.
        if ( abs($stored - $current) <= 0.01 ) {
            return false;
        }

        // Stored fee differs from current fee: the fee was changed after enrollment.
        // Protect this enrollment — keep the original stored fee.
        return true;
    }

    /**
     * Repair all student_balances records where total_fee doesn't match the actual course fee.
     * This runs automatically on admin_init to fix any stale/wrong fee values in the DB.
     *
     * IMPORTANT: Any enrollment where student_balances.total_fee was already set at
     * enrollment time is PROTECTED — we never overwrite it with a newer course fee.
     * This ensures fee changes only affect NEW enrollments, not existing students.
     */
    public function repair_all_balances() {
        global $wpdb;
        $balances_table    = $this->get_table_name('student_balances');
        $enrollments_table = $this->get_table_name('enrollments');
        $courses_table     = $this->get_table_name('courses');
        $payments_table    = $this->get_table_name('payments');

        // Only find balances where total_fee is 0 or NULL (never properly set at enrollment).
        // We deliberately SKIP rows where total_fee > 0 — those were locked in at enrollment
        // and must never be overwritten, even if the course fee has since changed.
        $mismatches = $wpdb->get_results(
            "SELECT b.balance_id, b.enrollment_id, b.student_id,
                    b.total_fee as stored_fee, b.discount_amount,
                    c.fee as actual_fee
             FROM {$balances_table} b
             INNER JOIN {$enrollments_table} e ON b.enrollment_id = e.enrollment_id
             INNER JOIN {$courses_table} c ON e.course_id = c.course_id
             WHERE e.status IN ('Active', 'Enrolled', 'In Progress')
               AND (b.total_fee IS NULL OR b.total_fee = 0)"
        );

        if (empty($mismatches)) return;

        foreach ($mismatches as $row) {
            $actual_fee  = floatval($row->actual_fee);
            $discount    = floatval($row->discount_amount ?? 0);
            $net_fee     = max(0, $actual_fee - $discount);

            // Get actual payments for this enrollment
            $paid = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table}
                 WHERE student_id = %d AND enrollment_id = %d AND status = 'Completed'",
                $row->student_id, $row->enrollment_id
            )));

            $new_balance = max(0, $net_fee - $paid);

            $wpdb->update(
                $balances_table,
                array(
                    'total_fee'       => $actual_fee,
                    'discount_amount' => $discount,
                    'total_paid'      => $paid,
                    'balance'         => $new_balance,
                ),
                array('balance_id' => $row->balance_id),
                array('%f', '%f', '%f', '%f'),
                array('%d')
            );
        }
    }
}
