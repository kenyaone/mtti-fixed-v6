<?php
/**
 * Enrollments Admin Class - Complete Implementation
 */
class MTTI_MIS_Admin_Enrollments {
    
    private $plugin_name;
    private $version;
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        // Handle form submissions
        if (isset($_POST['mtti_enrollment_submit'])) {
            check_admin_referer('mtti_enrollment_action', 'mtti_enrollment_nonce');
            $this->handle_form_submission();
            return;
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $enrollment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        switch ($action) {
            case 'add':
                $this->display_add_form();
                break;
            case 'edit':
                $this->display_edit_form($enrollment_id);
                break;
            case 'view':
                $this->display_enrollment_details($enrollment_id);
                break;
            default:
                $this->display_list();
        }
    }
    
    private function display_list() {
        global $wpdb;
        $enrollments = $this->db->get_enrollments();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Enrollments</h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=add'); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">
            
            <div class="notice notice-info">
                <p><strong>💡 Note:</strong> Enrollments are now optional. Students are assigned to courses directly via the <strong>Students → Edit</strong> page. 
                This enrollments table can be used for tracking specific enrollment periods or multiple course enrollments.</p>
            </div>
            
            <?php if (isset($_GET['message']) && $_GET['message'] == 'created') : ?>
            <div class="notice notice-success is-dismissible">
                <p>Enrollment created successfully!</p>
            </div>
            <?php endif; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission Number</th>
                        <th>Course</th>
                        <th>Start Date</th>
                        <th>Expected End</th>
                        <th>Status</th>
                        <th>Grade</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($enrollments) : foreach ($enrollments as $enrollment) : ?>
                    <tr>
                        <td><?php echo esc_html($enrollment->student_name ?: 'Unknown Student'); ?></td>
                        <td><strong><?php echo esc_html($enrollment->admission_number); ?></strong></td>
                        <td><?php echo esc_html($enrollment->course_name); ?> (<?php echo esc_html($enrollment->course_code); ?>)</td>
                        <td><?php echo date('M j, Y', strtotime($enrollment->start_date)); ?></td>
                        <td><?php echo date('M j, Y', strtotime($enrollment->expected_end_date)); ?></td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower(str_replace(' ', '', $enrollment->status)); ?>">
                            <?php echo esc_html($enrollment->status); ?>
                        </span></td>
                        <td><?php echo $enrollment->final_grade ? '<strong>' . esc_html($enrollment->final_grade) . '</strong>' : '-'; ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=view&id=' . $enrollment->enrollment_id); ?>">View</a> |
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=edit&id=' . $enrollment->enrollment_id); ?>">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="8">No enrollments found. <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=add'); ?>">Create first enrollment</a></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_add_form() {
        $students = $this->db->get_students(array('status' => 'Active'));
        $courses = $this->db->get_courses(array('status' => 'Active'));
        $staff = $this->get_staff();
        ?>
        <div class="wrap">
            <h1>Add New Enrollment</h1>
            <form method="post" action="">
                <?php wp_nonce_field('mtti_enrollment_action', 'mtti_enrollment_nonce'); ?>
                <input type="hidden" name="action" value="add">
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="student_id">Student *</label></th>
                        <td>
                            <select name="student_id" id="student_id" class="regular-text" required>
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student) : ?>
                                <option value="<?php echo $student->student_id; ?>">
                                    <?php echo esc_html($student->admission_number . ' - ' . $student->display_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="course_id">Course *</label></th>
                        <td>
                            <select name="course_id" id="course_id" class="regular-text" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course) : ?>
                                <option value="<?php echo $course->course_id; ?>" data-duration="<?php echo $course->duration_weeks; ?>" data-fee="<?php echo $course->fee; ?>">
                                    <?php echo esc_html($course->course_name . ' - KES ' . number_format($course->fee, 2) . ' (' . $course->duration_weeks . ' weeks)'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="staff_id">Instructor</label></th>
                        <td>
                            <select name="staff_id" id="staff_id" class="regular-text">
                                <option value="">Not Assigned</option>
                                <?php foreach ($staff as $teacher) : ?>
                                <option value="<?php echo $teacher->staff_id; ?>">
                                    <?php echo esc_html($teacher->staff_number . ' - ' . $teacher->display_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_number">Batch Number</label></th>
                        <td>
                            <input type="text" name="batch_number" id="batch_number" class="regular-text" placeholder="e.g., 2024-01">
                            <p class="description">Group students by batch for class management</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="enrollment_date">Enrollment Date *</label></th>
                        <td>
                            <input type="date" name="enrollment_date" id="enrollment_date" class="regular-text" value="<?php echo date('Y-m-d'); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="start_date">Start Date *</label></th>
                        <td>
                            <input type="date" name="start_date" id="start_date" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expected_end_date">Expected End Date</label></th>
                        <td>
                            <input type="date" name="expected_end_date" id="expected_end_date" class="regular-text" readonly>
                            <p class="description">Calculated automatically based on course duration</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="discount_amount">Discount Amount (Optional)</label></th>
                        <td>
                            <input type="number" name="discount_amount" id="discount_amount" class="regular-text" step="0.01" min="0" value="0" placeholder="0.00">
                            <p class="description">Enter any discount applied to the course fee (e.g., scholarship, early bird discount)</p>
                            <p id="fee-calculation" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2E7D32; display:none;">
                                <strong>Fee Calculation:</strong><br>
                                Course Fee: <span id="original-fee">KES 0.00</span><br>
                                Discount: <span id="discount-display">KES 0.00</span><br>
                                <strong style="color: #2E7D32;">Net Fee to Pay: <span id="net-fee">KES 0.00</span></strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status *</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text" required>
                                <option value="Enrolled">Enrolled</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Suspended">Suspended</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <div class="mtti-notice info">
                    <p><strong>Note:</strong> When enrollment is created, student balance will be automatically initialized with the course fee.</p>
                </div>
                
                <p class="submit">
                    <input type="submit" name="mtti_enrollment_submit" class="button button-primary" value="Create Enrollment">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var courseFee = 0;
            
            // Calculate end date based on start date and course duration
            $('#start_date, #course_id').on('change', function() {
                var startDate = $('#start_date').val();
                var duration = $('#course_id option:selected').data('duration');
                courseFee = parseFloat($('#course_id option:selected').data('fee')) || 0;
                
                if (startDate && duration) {
                    var start = new Date(startDate);
                    start.setDate(start.getDate() + (duration * 7)); // weeks to days
                    
                    var end = start.toISOString().split('T')[0];
                    $('#expected_end_date').val(end);
                }
                
                // Update fee calculation display
                updateFeeCalculation();
            });
            
            // Calculate net fee when discount changes
            $('#discount_amount').on('input', function() {
                updateFeeCalculation();
            });
            
            function updateFeeCalculation() {
                if (courseFee > 0) {
                    var discount = parseFloat($('#discount_amount').val()) || 0;
                    
                    // Prevent discount from being more than course fee
                    if (discount > courseFee) {
                        discount = courseFee;
                        $('#discount_amount').val(courseFee.toFixed(2));
                    }
                    
                    var netFee = courseFee - discount;
                    
                    $('#original-fee').text('KES ' + courseFee.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
                    $('#discount-display').text('KES ' + discount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
                    $('#net-fee').text('KES ' + netFee.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'));
                    $('#fee-calculation').show();
                } else {
                    $('#fee-calculation').hide();
                }
            }
        });
        </script>
        <?php
    }
    
    private function display_edit_form($enrollment_id) {
        global $wpdb;
        $enrollments_table = $this->db->get_table_name('enrollments');
        
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$enrollments_table} WHERE enrollment_id = %d",
            $enrollment_id
        ));
        
        if (!$enrollment) {
            wp_die('Enrollment not found');
        }
        
        $staff = $this->get_staff();
        ?>
        <div class="wrap">
            <h1>Edit Enrollment</h1>
            <form method="post" action="">
                <?php wp_nonce_field('mtti_enrollment_action', 'mtti_enrollment_nonce'); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="enrollment_id" value="<?php echo $enrollment_id; ?>">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Student</th>
                        <td>
                            <?php 
                            $student = $this->db->get_student($enrollment->student_id);
                            echo '<strong>' . esc_html($student->admission_number . ' - ' . $student->display_name) . '</strong>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Course</th>
                        <td>
                            <?php 
                            $course = $this->db->get_course($enrollment->course_id);
                            echo '<strong>' . esc_html($course->course_name) . '</strong>';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="staff_id">Instructor</label></th>
                        <td>
                            <select name="staff_id" id="staff_id" class="regular-text">
                                <option value="">Not Assigned</option>
                                <?php foreach ($staff as $teacher) : ?>
                                <option value="<?php echo $teacher->staff_id; ?>" <?php selected($enrollment->staff_id, $teacher->staff_id); ?>>
                                    <?php echo esc_html($teacher->display_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_number">Batch Number</label></th>
                        <td>
                            <input type="text" name="batch_number" id="batch_number" class="regular-text" value="<?php echo esc_attr($enrollment->batch_number); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="start_date">Start Date</label></th>
                        <td>
                            <input type="date" name="start_date" id="start_date" class="regular-text" value="<?php echo esc_attr($enrollment->start_date); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expected_end_date">Expected End Date</label></th>
                        <td>
                            <input type="date" name="expected_end_date" id="expected_end_date" class="regular-text" value="<?php echo esc_attr($enrollment->expected_end_date); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="actual_end_date">Actual End Date</label></th>
                        <td>
                            <input type="date" name="actual_end_date" id="actual_end_date" class="regular-text" value="<?php echo esc_attr($enrollment->actual_end_date); ?>">
                            <p class="description">Leave blank if course is ongoing</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text">
                                <option value="Enrolled" <?php selected($enrollment->status, 'Enrolled'); ?>>Enrolled</option>
                                <option value="In Progress" <?php selected($enrollment->status, 'In Progress'); ?>>In Progress</option>
                                <option value="Completed" <?php selected($enrollment->status, 'Completed'); ?>>Completed</option>
                                <option value="Suspended" <?php selected($enrollment->status, 'Suspended'); ?>>Suspended</option>
                                <option value="Cancelled" <?php selected($enrollment->status, 'Cancelled'); ?>>Cancelled</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="final_grade">Final Grade</label></th>
                        <td>
                            <input type="text" name="final_grade" id="final_grade" class="regular-text" value="<?php echo esc_attr($enrollment->final_grade); ?>" maxlength="5">
                            <p class="description">A, B, C, D, or F</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Certificate</th>
                        <td>
                            <?php if ($enrollment->certificate_issued) : ?>
                                <span class="mtti-certificate-badge">
                                    <span class="dashicons dashicons-awards"></span>
                                    Issued: <?php echo esc_html($enrollment->certificate_number); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #999;">Not issued</span>
                                <?php if ($enrollment->status == 'Completed') : ?>
                                <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=issue-certificate&id=' . $enrollment_id); ?>" class="button button-small">Issue Certificate</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_enrollment_submit" class="button button-primary" value="Update Enrollment">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function display_enrollment_details($enrollment_id) {
        global $wpdb;
        $enrollments_table = $this->db->get_table_name('enrollments');
        
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, s.admission_number, c.course_name, c.course_code, c.fee
             FROM {$enrollments_table} e
             LEFT JOIN {$wpdb->prefix}mtti_students s ON e.student_id = s.student_id
             LEFT JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE e.enrollment_id = %d",
            $enrollment_id
        ));
        
        if (!$enrollment) {
            wp_die('Enrollment not found');
        }
        
        $student = $this->db->get_student($enrollment->student_id);
        $balance = $this->db->get_student_balance($enrollment->student_id);
        ?>
        <div class="wrap">
            <h1>Enrollment Details</h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=edit&id=' . $enrollment_id); ?>" class="page-title-action">Edit</a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments'); ?>" class="page-title-action">Back to List</a>
            
            <div class="mtti-student-details">
                <h2>Enrollment Information</h2>
                <table class="form-table">
                    <tr>
                        <th>Student:</th>
                        <td><strong><?php echo esc_html($enrollment->admission_number . ' - ' . $student->display_name); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Course:</th>
                        <td><strong><?php echo esc_html($enrollment->course_name . ' (' . $enrollment->course_code . ')'); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Batch:</th>
                        <td><?php echo esc_html($enrollment->batch_number ?: 'Not assigned'); ?></td>
                    </tr>
                    <tr>
                        <th>Enrollment Date:</th>
                        <td><?php echo date('F j, Y', strtotime($enrollment->enrollment_date)); ?></td>
                    </tr>
                    <tr>
                        <th>Start Date:</th>
                        <td><?php echo date('F j, Y', strtotime($enrollment->start_date)); ?></td>
                    </tr>
                    <tr>
                        <th>Expected End:</th>
                        <td><?php echo date('F j, Y', strtotime($enrollment->expected_end_date)); ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower(str_replace(' ', '', $enrollment->status)); ?>">
                            <?php echo esc_html($enrollment->status); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <th>Final Grade:</th>
                        <td><?php echo $enrollment->final_grade ? '<strong style="font-size: 24px;">' . esc_html($enrollment->final_grade) . '</strong>' : '-'; ?></td>
                    </tr>
                    <tr>
                        <th>Certificate:</th>
                        <td>
                            <?php if ($enrollment->certificate_issued) : ?>
                                <span class="mtti-certificate-badge">
                                    <span class="dashicons dashicons-awards"></span>
                                    <?php echo esc_html($enrollment->certificate_number); ?>
                                </span>
                            <?php else : ?>
                                Not issued
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <h2>Fee Balance</h2>
                <?php if ($balance) : 
                    $enroll_balance = null;
                    foreach ($balance as $b) {
                        if ($b->enrollment_id == $enrollment_id) {
                            $enroll_balance = $b;
                            break;
                        }
                    }
                    if ($enroll_balance) :
                ?>
                <div class="mtti-payment-status <?php echo $enroll_balance->balance == 0 ? '' : 'partial'; ?>">
                    <table class="form-table">
                        <tr>
                            <th>Total Fee:</th>
                            <td>KES <?php echo number_format($enroll_balance->total_fee, 2); ?></td>
                        </tr>
                        <tr>
                            <th>Total Paid:</th>
                            <td>KES <?php echo number_format($enroll_balance->total_paid, 2); ?></td>
                        </tr>
                        <tr>
                            <th>Balance:</th>
                            <td><strong class="mtti-balance <?php echo $enroll_balance->balance == 0 ? 'zero' : 'negative'; ?>">
                                KES <?php echo number_format($enroll_balance->balance, 2); ?>
                            </strong></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td>
                                <?php 
                                if ($enroll_balance->balance == 0) {
                                    echo '<span class="mtti-status mtti-status-completed">Fully Paid</span>';
                                } elseif ($enroll_balance->total_paid > 0) {
                                    echo '<span class="mtti-status mtti-status-pending">Partial Payment</span>';
                                } else {
                                    echo '<span class="mtti-status mtti-status-cancelled">No Payment</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endif; endif; ?>
            </div>
        </div>
        <?php
    }
    
    private function handle_form_submission() {
        global $wpdb;
        $action = $_POST['action'];
        
        if ($action == 'add') {
            $discount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
            
            $data = array(
                'student_id' => intval($_POST['student_id']),
                'course_id' => intval($_POST['course_id']),
                'staff_id' => !empty($_POST['staff_id']) ? intval($_POST['staff_id']) : null,
                'batch_number' => sanitize_text_field($_POST['batch_number']),
                'enrollment_date' => sanitize_text_field($_POST['enrollment_date']),
                'start_date' => sanitize_text_field($_POST['start_date']),
                'expected_end_date' => sanitize_text_field($_POST['expected_end_date']),
                'status' => sanitize_text_field($_POST['status']),
                'discount_amount' => $discount  // Add discount to enrollment data
            );
            
            $enrollment_id = $this->db->create_enrollment($data);
            
            // Auto-enroll student in all course units (create empty unit_results entries)
            $this->auto_enroll_in_units($data['student_id'], $data['course_id']);
            
            wp_redirect(admin_url('admin.php?page=mtti-mis-enrollments&action=view&id=' . $enrollment_id . '&message=created'));
            exit;
            
        } elseif ($action == 'edit') {
            $enrollment_id = intval($_POST['enrollment_id']);
            
            $data = array(
                'staff_id' => !empty($_POST['staff_id']) ? intval($_POST['staff_id']) : null,
                'batch_number' => sanitize_text_field($_POST['batch_number']),
                'start_date' => sanitize_text_field($_POST['start_date']),
                'expected_end_date' => sanitize_text_field($_POST['expected_end_date']),
                'actual_end_date' => !empty($_POST['actual_end_date']) ? sanitize_text_field($_POST['actual_end_date']) : null,
                'status' => sanitize_text_field($_POST['status']),
                'final_grade' => sanitize_text_field($_POST['final_grade'])
            );
            
            $this->db->update_enrollment($enrollment_id, $data);
            
            wp_redirect(admin_url('admin.php?page=mtti-mis-enrollments&action=view&id=' . $enrollment_id . '&message=updated'));
            exit;
        }
    }
    
    /**
     * Auto-enroll student in all units of a course
     * Creates empty unit_results entries so they appear in marks entry form
     */
    private function auto_enroll_in_units($student_id, $course_id) {
        global $wpdb;
        
        $units_table = $this->db->get_table_name('course_units');
        $results_table = $this->db->get_table_name('unit_results');
        
        // Get all active units for this course
        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT unit_id FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
            $course_id
        ));
        
        if (empty($units)) {
            return;
        }
        
        // Create unit_results entry for each unit (with NULL score - not yet graded)
        foreach ($units as $unit) {
            // Check if entry already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT result_id FROM {$results_table} WHERE unit_id = %d AND student_id = %d",
                $unit->unit_id,
                $student_id
            ));
            
            if (!$exists) {
                $wpdb->insert($results_table, array(
                    'unit_id' => $unit->unit_id,
                    'student_id' => $student_id,
                    'score' => null,
                    'percentage' => null,
                    'grade' => null,
                    'passed' => 0,
                    'remarks' => '',
                    'result_date' => current_time('mysql')
                ));
            }
        }
    }
    
    private function get_staff() {
        global $wpdb;
        $staff_table = $this->db->get_table_name('staff');
        
        return $wpdb->get_results(
            "SELECT s.*, u.display_name 
             FROM {$staff_table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.status = 'Active'
             ORDER BY u.display_name ASC"
        );
    }
}
