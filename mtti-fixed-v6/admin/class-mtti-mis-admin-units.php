<?php
/**
 * Course Units Admin Class
 * 
 * Manages course units (modules) with marks entry for students.
 * Units appear on student transcripts with their scores.
 * Now supports adding learners directly to units (v3.9.8)
 * 
 * @version 3.9.8
 */
class MTTI_MIS_Admin_Units {
    
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        // Handle form submissions BEFORE any output to prevent header errors
        if (isset($_POST['mtti_unit_submit']) && isset($_POST['mtti_unit_nonce'])) {
            if (wp_verify_nonce($_POST['mtti_unit_nonce'], 'mtti_unit_action')) {
                $this->process_unit_form();
                return;
            }
        }
        
        if (isset($_POST['save_unit_marks']) && isset($_POST['mtti_marks_nonce'])) {
            if (wp_verify_nonce($_POST['mtti_marks_nonce'], 'mtti_marks_action')) {
                $this->process_marks_form();
                return;
            }
        }
        
        // Handle unit enrollment form submissions
        if (isset($_POST['mtti_unit_enrollment_submit']) && isset($_POST['mtti_unit_enrollment_nonce'])) {
            if (wp_verify_nonce($_POST['mtti_unit_enrollment_nonce'], 'mtti_unit_enrollment_action')) {
                $this->process_unit_enrollment_form();
                return;
            }
        }
        
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['unit_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_unit_' . $_GET['unit_id'])) {
                $this->process_delete();
                return;
            }
        }
        
        // Handle remove unit enrollment
        if (isset($_GET['action']) && $_GET['action'] == 'remove-unit-enrollment' && isset($_GET['enrollment_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'remove_unit_enrollment_' . $_GET['enrollment_id'])) {
                $this->process_remove_unit_enrollment();
                return;
            }
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'add':
                $this->display_add_form();
                break;
            case 'edit':
                $this->display_edit_form(intval($_GET['unit_id']));
                break;
            case 'view':
                $this->display_course_units(intval($_GET['course_id']));
                break;
            case 'enter-marks':
                $this->display_enter_marks_form(intval($_GET['unit_id']));
                break;
            case 'view-results':
                $this->display_unit_results(intval($_GET['unit_id']));
                break;
            case 'manage-learners':
                $this->display_manage_learners(intval($_GET['unit_id']));
                break;
            case 'add-learner':
                $this->display_add_learner_form(intval($_GET['unit_id']));
                break;
            default:
                $this->display_list();
        }
    }
    
    private function process_unit_form() {
        $action = sanitize_text_field($_POST['form_action']);
        
        if ($action === 'add') {
            $data = array(
                'course_id' => intval($_POST['course_id']),
                'unit_code' => sanitize_text_field($_POST['unit_code']),
                'unit_name' => sanitize_text_field($_POST['unit_name']),
                'description' => sanitize_textarea_field($_POST['description']),
                'duration_hours' => !empty($_POST['duration_hours']) ? intval($_POST['duration_hours']) : null,
                'credit_hours' => !empty($_POST['credit_hours']) ? floatval($_POST['credit_hours']) : null,
                'order_number' => intval($_POST['order_number']),
                'status' => sanitize_text_field($_POST['status'])
            );
            
            if (empty($data['unit_code'])) {
                $data['unit_code'] = $this->db->generate_unit_code($data['course_id']);
            }
            
            $this->db->create_course_unit($data);
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=view&course_id=' . $data['course_id'] . '&message=created'));
            exit;
            
        } elseif ($action === 'edit') {
            $unit_id = intval($_POST['unit_id']);
            $data = array(
                'course_id' => intval($_POST['course_id']),
                'unit_code' => sanitize_text_field($_POST['unit_code']),
                'unit_name' => sanitize_text_field($_POST['unit_name']),
                'description' => sanitize_textarea_field($_POST['description']),
                'duration_hours' => !empty($_POST['duration_hours']) ? intval($_POST['duration_hours']) : null,
                'credit_hours' => !empty($_POST['credit_hours']) ? floatval($_POST['credit_hours']) : null,
                'order_number' => intval($_POST['order_number']),
                'status' => sanitize_text_field($_POST['status'])
            );
            
            $this->db->update_course_unit($unit_id, $data);
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=view&course_id=' . $data['course_id'] . '&message=updated'));
            exit;
            
        } elseif ($action === 'bulk_add') {
            $course_id = intval($_POST['course_id']);
            $unit_names = sanitize_textarea_field($_POST['unit_names_bulk']);
            $names = array_filter(array_map('trim', explode("\n", $unit_names)));
            
            $count = 0;
            $order = 1;
            foreach ($names as $name) {
                if (empty($name)) continue;
                $data = array(
                    'course_id' => $course_id,
                    'unit_code' => $this->db->generate_unit_code($course_id),
                    'unit_name' => $name,
                    'order_number' => $order++,
                    'status' => 'Active'
                );
                $this->db->create_course_unit($data);
                $count++;
            }
            
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=view&course_id=' . $course_id . '&message=bulk-added&count=' . $count));
            exit;
        }
    }
    
    private function process_marks_form() {
        global $wpdb;
        
        $unit_id = intval($_POST['unit_id']);
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $results_table = $this->db->get_table_name('unit_results');
        $student_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : array();
        $scores = isset($_POST['scores']) ? $_POST['scores'] : array();
        $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : array();
        
        foreach ($student_ids as $student_id) {
            $student_id = intval($student_id);
            $score_raw = isset($scores[$student_id]) ? trim($scores[$student_id]) : '';
            
            // Check if a record already exists for this student/unit
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT result_id FROM {$results_table} WHERE unit_id = %d AND student_id = %d",
                $unit_id, $student_id
            ));
            
            // If input is blank: delete existing record (clears the marks) and skip
            if ($score_raw === '') {
                if ($existing) {
                    $wpdb->delete($results_table, array('result_id' => $existing));
                }
                continue;
            }
            
            // Validate: must be a number between 0 and 100
            if (!is_numeric($score_raw)) continue;
            $score = floatval($score_raw);
            if ($score < 0) $score = 0;
            if ($score > 100) $score = 100;
            
            $grade = $this->db->calculate_grade($score, 100);
            $passed = $this->db->is_passing_grade($grade) ? 1 : 0;
            $percentage = $score;
            
            $data = array(
                'unit_id'     => $unit_id,
                'student_id'  => $student_id,
                'score'       => $score,
                'percentage'  => $percentage,
                'grade'       => $grade,
                'passed'      => $passed,
                'remarks'     => isset($remarks[$student_id]) ? sanitize_text_field($remarks[$student_id]) : '',
                'result_date' => current_time('mysql')
            );
            
            if ($existing) {
                $wpdb->update($results_table, $data, array('result_id' => $existing));
            } else {
                $wpdb->insert($results_table, $data);
            }
        }
        
        wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=view-results&unit_id=' . $unit_id . '&message=marks-saved'));
        exit;
    }
    
    private function process_delete() {
        global $wpdb;
        $unit_id = intval($_GET['unit_id']);
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $course_id = $unit->course_id;
        $results_table = $this->db->get_table_name('unit_results');
        $wpdb->delete($results_table, array('unit_id' => $unit_id));
        $this->db->delete_course_unit($unit_id);
        
        wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=view&course_id=' . $course_id . '&message=deleted'));
        exit;
    }
    
    private function display_list() {
        global $wpdb;
        $courses = $this->db->get_courses(array('status' => 'Active'));
        $units_table = $this->db->get_table_name('course_units');
        ?>
        <div class="wrap">
            <h1>Course Units <a href="?page=mtti-mis-units&action=add" class="page-title-action">Add New Unit</a></h1>
            <?php $this->display_messages(); ?>
            
            <div style="background: #e7f3ff; padding: 15px; margin: 20px 0; border-left: 4px solid #2196F3;">
                <strong>📚 Course Units Management</strong><br>
                Manage units here. Enter marks for each unit - these appear on student transcripts.<br>
                <strong>Grading:</strong> DISTINCTION (80-100%), CREDIT (60-79%), PASS (50-59%), REFER (0-49%)
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Course Code</th><th>Course Name</th><th>Units</th><th>Hours</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($courses) : foreach ($courses as $course) : 
                    $stats = $wpdb->get_row($wpdb->prepare(
                        "SELECT COUNT(*) as cnt, COALESCE(SUM(duration_hours), 0) as hrs FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
                        $course->course_id
                    ));
                ?>
                <tr>
                    <td><strong><?php echo esc_html($course->course_code); ?></strong></td>
                    <td><?php echo esc_html($course->course_name); ?></td>
                    <td><span style="background:#2196F3;color:#fff;padding:2px 8px;border-radius:3px;"><?php echo intval($stats->cnt); ?></span></td>
                    <td><?php echo intval($stats->hrs); ?> hrs</td>
                    <td>
                        <a href="?page=mtti-mis-units&action=view&course_id=<?php echo $course->course_id; ?>" class="button button-small">View Units</a>
                        <a href="?page=mtti-mis-units&action=add&course_id=<?php echo $course->course_id; ?>" class="button button-small button-primary">Add Unit</a>
                    </td>
                </tr>
                <?php endforeach; else : ?>
                <tr><td colspan="5">No courses found. <a href="?page=mtti-mis-courses&action=add">Create a course first</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_course_units($course_id) {
        global $wpdb;
        $course = $this->db->get_course($course_id);
        if (!$course) wp_die('Course not found');
        
        $units_table = $this->db->get_table_name('course_units');
        $results_table = $this->db->get_table_name('unit_results');
        
        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT u.*, (SELECT COUNT(*) FROM {$results_table} WHERE unit_id = u.unit_id) as results_count
             FROM {$units_table} u WHERE u.course_id = %d ORDER BY u.order_number ASC, u.unit_id ASC",
            $course_id
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($course->course_name); ?> - Units
                <a href="?page=mtti-mis-units&action=add&course_id=<?php echo $course_id; ?>" class="page-title-action">Add Unit</a>
                <a href="?page=mtti-mis-units" class="page-title-action">← Back</a>
            </h1>
            <?php $this->display_messages(); ?>
            
            <div style="background:#f8f9fa;padding:15px;margin-bottom:20px;border-left:4px solid #2196F3;">
                <strong>Course:</strong> <?php echo esc_html($course->course_code); ?> | 
                <strong>Duration:</strong> <?php echo esc_html($course->duration_weeks); ?> weeks
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>#</th><th>Unit Code</th><th>Unit Name</th><th>Hours</th><th>Results</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($units) : $n = 1; foreach ($units as $unit) : ?>
                <tr>
                    <td><?php echo $n++; ?></td>
                    <td><strong><?php echo esc_html($unit->unit_code); ?></strong></td>
                    <td><?php echo esc_html($unit->unit_name); ?></td>
                    <td><?php echo $unit->duration_hours ? intval($unit->duration_hours) : '-'; ?></td>
                    <td><span style="background:<?php echo $unit->results_count > 0 ? '#4CAF50' : '#FF9800'; ?>;color:#fff;padding:2px 8px;border-radius:3px;"><?php echo $unit->results_count; ?></span></td>
                    <td><?php echo esc_html($unit->status); ?></td>
                    <td>
                        <a href="?page=mtti-mis-units&action=manage-learners&unit_id=<?php echo $unit->unit_id; ?>" class="button button-small" title="Add learners to this unit">👥 Learners</a>
                        <a href="?page=mtti-mis-units&action=enter-marks&unit_id=<?php echo $unit->unit_id; ?>" class="button button-small button-primary">Enter Marks</a>
                        <a href="?page=mtti-mis-units&action=view-results&unit_id=<?php echo $unit->unit_id; ?>" class="button button-small">View Results</a>
                        <a href="?page=mtti-mis-units&action=edit&unit_id=<?php echo $unit->unit_id; ?>" class="button button-small">Edit</a>
                        <a href="<?php echo wp_nonce_url('?page=mtti-mis-units&action=delete&unit_id=' . $unit->unit_id, 'delete_unit_' . $unit->unit_id); ?>" class="button button-small" onclick="return confirm('Delete this unit and all its results?');">Delete</a>
                    </td>
                </tr>
                <?php endforeach; else : ?>
                <tr><td colspan="7" style="text-align:center;padding:20px;">No units. <a href="?page=mtti-mis-units&action=add&course_id=<?php echo $course_id; ?>" class="button button-primary">Add First Unit</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_enter_marks_form($unit_id) {
        global $wpdb;
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $course = $this->db->get_course($unit->course_id);
        $students_table = $this->db->get_table_name('students');
        $enrollments_table = $this->db->get_table_name('enrollments');
        $results_table = $this->db->get_table_name('unit_results');
        $units_table = $this->db->get_table_name('course_units');
        $unit_enrollments_table = $this->db->get_table_name('unit_enrollments');
        
        // Get students who are either:
        // 1. Enrolled in this course, OR
        // 2. Already have results for any unit in this course, OR
        // 3. Enrolled directly in this unit (unit enrollment)
        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.student_id, s.admission_number, COALESCE(u.display_name, s.admission_number) as display_name,
                    CASE 
                        WHEN EXISTS (SELECT 1 FROM {$enrollments_table} WHERE student_id = s.student_id AND course_id = %d AND status != 'Cancelled') THEN 'course'
                        WHEN EXISTS (SELECT 1 FROM {$unit_enrollments_table} WHERE student_id = s.student_id AND unit_id = %d AND status = 'Active') THEN 'unit'
                        ELSE 'results'
                    END as enrollment_type
             FROM {$students_table} s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.student_id IN (
                 SELECT student_id FROM {$enrollments_table} WHERE course_id = %d AND status != 'Cancelled'
                 UNION
                 SELECT ur.student_id FROM {$results_table} ur 
                 INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id 
                 WHERE cu.course_id = %d
                 UNION
                 SELECT student_id FROM {$unit_enrollments_table} WHERE unit_id = %d AND status = 'Active'
             )
             ORDER BY s.admission_number ASC",
            $unit->course_id,
            $unit_id,
            $unit->course_id,
            $unit->course_id,
            $unit_id
        ));
        
        if (empty($students)) {
            ?>
            <div class="wrap">
                <h1>Enter Marks: <?php echo esc_html($unit->unit_name); ?></h1>
                <p><a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>">← Back to Units</a></p>
                <div class="notice notice-warning">
                    <p><strong>No students found for this unit.</strong></p>
                    <p>To enter marks for <strong><?php echo esc_html($unit->unit_name); ?></strong>, you can:</p>
                    <ol>
                        <li>Enroll students in the full course (<strong><?php echo esc_html($course->course_name); ?></strong>), or</li>
                        <li>Add learners directly to this specific unit (for students who only want to take this unit)</li>
                    </ol>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-units&action=add-learner&unit_id=' . $unit_id); ?>" class="button button-primary">Add Learner to This Unit</a>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-enrollments&action=add'); ?>" class="button">Enroll in Full Course</a>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-units&action=manage-learners&unit_id=' . $unit_id); ?>" class="button">Manage Unit Learners</a>
                    </p>
                </div>
            </div>
            <?php
            return;
        }
        
        $existing = array();
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$results_table} WHERE unit_id = %d", $unit_id));
        foreach ($results as $r) $existing[$r->student_id] = $r;
        ?>
        <div class="wrap">
            <h1>Enter Marks: <?php echo esc_html($unit->unit_name); ?></h1>
            <p><a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>">← Back to Units</a></p>
            
            <div style="background:#e7f3ff;padding:15px;margin:20px 0;border-left:4px solid #2196F3;">
                <strong>Course:</strong> <?php echo esc_html($course->course_name); ?><br>
                <strong>Unit:</strong> <?php echo esc_html($unit->unit_name); ?> (<?php echo esc_html($unit->unit_code); ?>)<br>
                <strong>Max Marks:</strong> 100 marks | <strong>Failing:</strong> Below 50% (REFER)
                <br><small>💡 Tip: Students with <span style="background:#9C27B0;color:#fff;padding:1px 4px;border-radius:2px;font-size:10px;">UNIT</span> badge are enrolled only in this unit, not the full course.</small>
            </div>
            
            <p style="margin-bottom:10px;">
                <a href="?page=mtti-mis-units&action=add-learner&unit_id=<?php echo $unit_id; ?>" class="button">➕ Add Learner to Unit</a>
                <a href="?page=mtti-mis-units&action=manage-learners&unit_id=<?php echo $unit_id; ?>" class="button">👥 Manage Unit Learners</a>
            </p>
            
            <form method="post">
                <?php wp_nonce_field('mtti_marks_action', 'mtti_marks_nonce'); ?>
                <input type="hidden" name="unit_id" value="<?php echo $unit_id; ?>">
                
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Admission No</th><th>Student Name</th><th>Type</th><th>Marks (0-100)</th><th>Grade</th><th>Status</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $student) : $r = isset($existing[$student->student_id]) ? $existing[$student->student_id] : null; ?>
                    <tr>
                        <td><?php echo esc_html($student->admission_number); ?></td>
                        <td><?php echo esc_html($student->display_name); ?></td>
                        <td>
                            <?php if ($student->enrollment_type === 'unit') : ?>
                                <span style="background:#9C27B0;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;" title="Enrolled in this unit only">UNIT</span>
                            <?php elseif ($student->enrollment_type === 'course') : ?>
                                <span style="background:#2196F3;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;" title="Enrolled in full course">COURSE</span>
                            <?php else : ?>
                                <span style="background:#757575;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;" title="Has results but no active enrollment">LEGACY</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="hidden" name="student_ids[]" value="<?php echo $student->student_id; ?>">
                            <input type="number" name="scores[<?php echo $student->student_id; ?>]" min="0" max="100" step="1" value="<?php echo $r ? esc_attr($r->score) : ''; ?>" class="score-input" data-student="<?php echo $student->student_id; ?>" style="width:80px;">
                        </td>
                        <td><strong class="grade-display" id="grade-<?php echo $student->student_id; ?>" style="font-size:16px;"><?php echo $r ? esc_html($r->grade) : '-'; ?></strong></td>
                        <td><span id="status-<?php echo $student->student_id; ?>"><?php echo $r ? ($r->passed ? '<span style="color:#2E7D32;">✓ Pass</span>' : '<span style="color:#D32F2F;">✗ Fail</span>') : '-'; ?></span></td>
                        <td><input type="text" name="remarks[<?php echo $student->student_id; ?>]" value="<?php echo $r ? esc_attr($r->remarks) : ''; ?>" placeholder="Optional" style="width:100%;"></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="save_unit_marks" class="button button-primary" value="Save All Marks">
                    <a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>" class="button">Cancel</a>
                </p>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                function calcGrade(s) {
                    if (s === '' || isNaN(s)) return '-';
                    s = parseFloat(s);
                    if (s >= 80) return 'DISTINCTION';
                    if (s >= 60) return 'CREDIT';
                    if (s >= 50) return 'PASS';
                    if (s >= 1) return 'REFER';
                    return '-';
                }
                function isPassing(g) { return g !== 'REFER' && g !== '-'; }
                function getColor(g) {
                    if (g === 'DISTINCTION') return '#2E7D32';
                    if (g === 'CREDIT') return '#1976D2';
                    if (g === 'PASS') return '#FF9800';
                    return '#D32F2F';
                }
                $('.score-input').on('input change', function() {
                    var id = $(this).data('student'), g = calcGrade($(this).val());
                    $('#grade-' + id).text(g).css('color', getColor(g));
                    $('#status-' + id).html(g === '-' ? '-' : (isPassing(g) ? '<span style="color:#2E7D32;">✓ Pass</span>' : '<span style="color:#D32F2F;">✗ Fail</span>'));
                }).trigger('change');
            });
            </script>
        </div>
        <?php
    }
    
    private function display_unit_results($unit_id) {
        global $wpdb;
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $course = $this->db->get_course($unit->course_id);
        $results_table = $this->db->get_table_name('unit_results');
        $students_table = $this->db->get_table_name('students');
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, s.admission_number, u.display_name FROM {$results_table} r
             LEFT JOIN {$students_table} s ON r.student_id = s.student_id
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE r.unit_id = %d ORDER BY r.score DESC",
            $unit_id
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($unit->unit_name); ?> - Results
                <a href="?page=mtti-mis-units&action=enter-marks&unit_id=<?php echo $unit_id; ?>" class="page-title-action">Enter/Edit Marks</a>
                <a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>" class="page-title-action">← Back</a>
            </h1>
            <?php $this->display_messages(); ?>
            
            <div style="background:#f8f9fa;padding:15px;margin:20px 0;border-left:4px solid #2196F3;">
                <strong>Course:</strong> <?php echo esc_html($course->course_name); ?> | <strong>Unit:</strong> <?php echo esc_html($unit->unit_code); ?> | <strong>Max:</strong> 100 marks
            </div>
            
            <?php if ($results) : $passed = $failed = $total = 0; ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Rank</th><th>Admission No</th><th>Student Name</th><th>Marks</th><th>Grade</th><th>Status</th><th>Remarks</th><th>Transcript</th></tr></thead>
                <tbody>
                <?php $rank = 1; foreach ($results as $r) : $total += $r->score; if ($r->passed) $passed++; else $failed++; ?>
                <tr>
                    <td><?php echo $rank++; ?></td>
                    <td><?php echo esc_html($r->admission_number); ?></td>
                    <td><?php echo esc_html($r->display_name); ?></td>
                    <td><strong><?php echo esc_html($r->score); ?></strong>/100</td>
                    <td><?php echo number_format($r->percentage, 1); ?>%</td>
                    <td><strong style="color:<?php echo ($r->grade == 'DISTINCTION') ? '#2E7D32' : ($r->grade == 'CREDIT' ? '#1976D2' : ($r->grade == 'PASS' ? '#FF9800' : '#D32F2F')); ?>"><?php echo esc_html($r->grade); ?></strong></td>
                    <td><?php echo $r->passed ? '<span style="color:#2E7D32;font-weight:bold;">✓ Pass</span>' : '<span style="color:#D32F2F;font-weight:bold;">✗ Fail</span>'; ?></td>
                    <td><?php echo esc_html($r->remarks); ?></td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-certificates&action=unit-transcript&unit_id=' . $unit_id . '&student_id=' . $r->student_id); ?>" 
                           class="button button-small" target="_blank" title="View unit transcript">
                            📄 Transcript
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:20px;padding:15px;background:#f0f0f1;">
                <strong>Statistics:</strong> Total: <?php echo count($results); ?> | 
                <span style="color:#2E7D32;">Passed: <?php echo $passed; ?></span> | 
                <span style="color:#D32F2F;">Failed: <?php echo $failed; ?></span> | 
                Pass Rate: <?php echo number_format(($passed / count($results)) * 100, 1); ?>% |
                Average: <?php echo number_format($total / count($results), 1); ?>/100
            </div>
            <?php else : ?>
            <div class="notice notice-info"><p>No results yet. <a href="?page=mtti-mis-units&action=enter-marks&unit_id=<?php echo $unit_id; ?>">Enter marks now</a></p></div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function display_add_form() {
        $courses = $this->db->get_courses(array('status' => 'Active'));
        $preselected = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
        ?>
        <div class="wrap">
            <h1>Add New Unit</h1>
            <p><a href="?page=mtti-mis-units">← Back</a></p>
            
            <form method="post">
                <?php wp_nonce_field('mtti_unit_action', 'mtti_unit_nonce'); ?>
                <input type="hidden" name="form_action" value="add">
                <table class="form-table">
                    <tr><th>Course *</th><td>
                        <select name="course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c) : ?>
                            <option value="<?php echo $c->course_id; ?>" <?php selected($preselected, $c->course_id); ?>><?php echo esc_html($c->course_name . ' (' . $c->course_code . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Unit Code</th><td><input type="text" name="unit_code" class="regular-text" placeholder="Auto-generated if blank"></td></tr>
                    <tr><th>Unit Name *</th><td><input type="text" name="unit_name" class="regular-text" required></td></tr>
                    <tr><th>Description</th><td><textarea name="description" rows="3" class="large-text"></textarea></td></tr>
                    <tr><th>Duration (hours)</th><td><input type="number" name="duration_hours" min="0" class="small-text"></td></tr>
                    <tr><th>Credit Hours</th><td><input type="number" name="credit_hours" min="0" step="0.5" class="small-text"></td></tr>
                    <tr><th>Display Order</th><td><input type="number" name="order_number" min="0" value="0" class="small-text"></td></tr>
                    <tr><th>Status</th><td><select name="status"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></td></tr>
                </table>
                <p class="submit"><input type="submit" name="mtti_unit_submit" class="button button-primary" value="Add Unit"> <a href="?page=mtti-mis-units" class="button">Cancel</a></p>
            </form>
            
            <hr style="margin:30px 0;">
            <h2>Bulk Add Units</h2>
            <form method="post">
                <?php wp_nonce_field('mtti_unit_action', 'mtti_unit_nonce'); ?>
                <input type="hidden" name="form_action" value="bulk_add">
                <table class="form-table">
                    <tr><th>Course *</th><td>
                        <select name="course_id" required>
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $c) : ?>
                            <option value="<?php echo $c->course_id; ?>" <?php selected($preselected, $c->course_id); ?>><?php echo esc_html($c->course_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Unit Names *</th><td><textarea name="unit_names_bulk" rows="10" class="large-text" required placeholder="Word Processing&#10;Spreadsheets&#10;Presentations"></textarea><p class="description">One unit per line</p></td></tr>
                </table>
                <p class="submit"><input type="submit" name="mtti_unit_submit" class="button button-secondary" value="Add Multiple Units"></p>
            </form>
        </div>
        <?php
    }
    
    private function display_edit_form($unit_id) {
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $course = $this->db->get_course($unit->course_id);
        $courses = $this->db->get_courses(array('status' => 'Active'));
        ?>
        <div class="wrap">
            <h1>Edit Unit</h1>
            <p><a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>">← Back to <?php echo esc_html($course->course_name); ?></a></p>
            
            <form method="post">
                <?php wp_nonce_field('mtti_unit_action', 'mtti_unit_nonce'); ?>
                <input type="hidden" name="form_action" value="edit">
                <input type="hidden" name="unit_id" value="<?php echo $unit_id; ?>">
                <table class="form-table">
                    <tr><th>Course *</th><td>
                        <select name="course_id" required>
                            <?php foreach ($courses as $c) : ?>
                            <option value="<?php echo $c->course_id; ?>" <?php selected($unit->course_id, $c->course_id); ?>><?php echo esc_html($c->course_name . ' (' . $c->course_code . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th>Unit Code *</th><td><input type="text" name="unit_code" value="<?php echo esc_attr($unit->unit_code); ?>" class="regular-text" required></td></tr>
                    <tr><th>Unit Name *</th><td><input type="text" name="unit_name" value="<?php echo esc_attr($unit->unit_name); ?>" class="regular-text" required></td></tr>
                    <tr><th>Description</th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea($unit->description); ?></textarea></td></tr>
                    <tr><th>Duration (hours)</th><td><input type="number" name="duration_hours" value="<?php echo esc_attr($unit->duration_hours); ?>" min="0" class="small-text"></td></tr>
                    <tr><th>Credit Hours</th><td><input type="number" name="credit_hours" value="<?php echo esc_attr($unit->credit_hours); ?>" min="0" step="0.5" class="small-text"></td></tr>
                    <tr><th>Display Order</th><td><input type="number" name="order_number" value="<?php echo esc_attr($unit->order_number); ?>" min="0" class="small-text"></td></tr>
                    <tr><th>Status</th><td><select name="status"><option value="Active" <?php selected($unit->status, 'Active'); ?>>Active</option><option value="Inactive" <?php selected($unit->status, 'Inactive'); ?>>Inactive</option></select></td></tr>
                </table>
                <p class="submit"><input type="submit" name="mtti_unit_submit" class="button button-primary" value="Update Unit"> <a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>" class="button">Cancel</a></p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Process unit enrollment form submission
     */
    private function process_unit_enrollment_form() {
        global $wpdb;
        
        $action = sanitize_text_field($_POST['enrollment_action']);
        $unit_id = intval($_POST['unit_id']);
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $unit_enrollments_table = $this->db->get_table_name('unit_enrollments');
        
        if ($action === 'add_single') {
            $student_id = intval($_POST['student_id']);
            
            // Check if already enrolled
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT enrollment_id FROM {$unit_enrollments_table} WHERE unit_id = %d AND student_id = %d",
                $unit_id, $student_id
            ));
            
            if ($exists) {
                wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=manage-learners&unit_id=' . $unit_id . '&message=already-enrolled'));
                exit;
            }
            
            $data = array(
                'unit_id' => $unit_id,
                'student_id' => $student_id,
                'enrollment_date' => current_time('Y-m-d'),
                'status' => 'Active',
                'notes' => sanitize_textarea_field($_POST['notes'])
            );
            
            $wpdb->insert($unit_enrollments_table, $data);
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=manage-learners&unit_id=' . $unit_id . '&message=learner-added'));
            exit;
            
        } elseif ($action === 'add_bulk') {
            $student_ids = isset($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : array();
            $count = 0;
            
            foreach ($student_ids as $student_id) {
                if ($student_id <= 0) continue;
                
                // Check if already enrolled
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT enrollment_id FROM {$unit_enrollments_table} WHERE unit_id = %d AND student_id = %d",
                    $unit_id, $student_id
                ));
                
                if ($exists) continue;
                
                $data = array(
                    'unit_id' => $unit_id,
                    'student_id' => $student_id,
                    'enrollment_date' => current_time('Y-m-d'),
                    'status' => 'Active'
                );
                
                $wpdb->insert($unit_enrollments_table, $data);
                $count++;
            }
            
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=manage-learners&unit_id=' . $unit_id . '&message=bulk-added&count=' . $count));
            exit;
        }
    }
    
    /**
     * Process remove unit enrollment
     */
    private function process_remove_unit_enrollment() {
        global $wpdb;
        
        $enrollment_id = intval($_GET['enrollment_id']);
        $unit_enrollments_table = $this->db->get_table_name('unit_enrollments');
        
        // Get unit_id before deleting
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT unit_id FROM {$unit_enrollments_table} WHERE enrollment_id = %d",
            $enrollment_id
        ));
        
        if (!$enrollment) wp_die('Enrollment not found');
        
        $wpdb->delete($unit_enrollments_table, array('enrollment_id' => $enrollment_id));
        
        wp_safe_redirect(admin_url('admin.php?page=mtti-mis-units&action=manage-learners&unit_id=' . $enrollment->unit_id . '&message=learner-removed'));
        exit;
    }
    
    /**
     * Display manage learners page for a unit
     */
    private function display_manage_learners($unit_id) {
        global $wpdb;
        
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $course = $this->db->get_course($unit->course_id);
        
        $students_table = $this->db->get_table_name('students');
        $enrollments_table = $this->db->get_table_name('enrollments');
        $unit_enrollments_table = $this->db->get_table_name('unit_enrollments');
        $results_table = $this->db->get_table_name('unit_results');
        
        // Get unit-specific enrollments
        $unit_enrollments = $wpdb->get_results($wpdb->prepare(
            "SELECT ue.*, s.admission_number, COALESCE(u.display_name, s.admission_number) as display_name
             FROM {$unit_enrollments_table} ue
             LEFT JOIN {$students_table} s ON ue.student_id = s.student_id
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE ue.unit_id = %d
             ORDER BY ue.enrollment_date DESC",
            $unit_id
        ));
        
        // Get count of course-enrolled students
        $course_enrolled_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT student_id) FROM {$enrollments_table} WHERE course_id = %d AND status != 'Cancelled'",
            $unit->course_id
        ));
        
        // Get count of students with results
        $with_results_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$results_table} WHERE unit_id = %d",
            $unit_id
        ));
        
        ?>
        <div class="wrap">
            <h1>
                Manage Learners: <?php echo esc_html($unit->unit_name); ?>
                <a href="?page=mtti-mis-units&action=add-learner&unit_id=<?php echo $unit_id; ?>" class="page-title-action">➕ Add Learner</a>
                <a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>" class="page-title-action">← Back to Units</a>
            </h1>
            <?php $this->display_messages(); ?>
            
            <div style="background:#e7f3ff;padding:15px;margin:20px 0;border-left:4px solid #2196F3;">
                <strong>📚 Course:</strong> <?php echo esc_html($course->course_name); ?> (<?php echo esc_html($course->course_code); ?>)<br>
                <strong>📖 Unit:</strong> <?php echo esc_html($unit->unit_code); ?> - <?php echo esc_html($unit->unit_name); ?>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin:20px 0;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center;">
                    <div style="font-size:32px;font-weight:bold;color:#2196F3;"><?php echo intval($course_enrolled_count); ?></div>
                    <div style="color:#666;">Course Enrollments</div>
                    <small>Students enrolled in full course</small>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center;">
                    <div style="font-size:32px;font-weight:bold;color:#9C27B0;"><?php echo count($unit_enrollments); ?></div>
                    <div style="color:#666;">Unit-Only Enrollments</div>
                    <small>Students taking only this unit</small>
                </div>
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center;">
                    <div style="font-size:32px;font-weight:bold;color:#4CAF50;"><?php echo intval($with_results_count); ?></div>
                    <div style="color:#666;">Results Entered</div>
                    <small>Students with marks recorded</small>
                </div>
            </div>
            
            <h2 style="margin-top:30px;">Unit-Only Enrollments</h2>
            <p class="description">These are students enrolled specifically in this unit (not the full course). This is useful for students who want to take just one unit from a course.</p>
            
            <?php if ($unit_enrollments) : ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
                <thead>
                    <tr>
                        <th>Admission No</th>
                        <th>Student Name</th>
                        <th>Enrollment Date</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($unit_enrollments as $enrollment) : ?>
                <tr>
                    <td><strong><?php echo esc_html($enrollment->admission_number); ?></strong></td>
                    <td><?php echo esc_html($enrollment->display_name); ?></td>
                    <td><?php echo esc_html(date('M j, Y', strtotime($enrollment->enrollment_date))); ?></td>
                    <td>
                        <span style="background:<?php echo $enrollment->status === 'Active' ? '#4CAF50' : '#FF9800'; ?>;color:#fff;padding:2px 8px;border-radius:3px;">
                            <?php echo esc_html($enrollment->status); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($enrollment->notes ?: '-'); ?></td>
                    <td>
                        <a href="<?php echo wp_nonce_url('?page=mtti-mis-units&action=remove-unit-enrollment&enrollment_id=' . $enrollment->enrollment_id, 'remove_unit_enrollment_' . $enrollment->enrollment_id); ?>" 
                           class="button button-small" 
                           onclick="return confirm('Remove this student from the unit? This will not delete their results.');"
                           style="color:#D32F2F;">Remove</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <div class="notice notice-info" style="margin-top:15px;">
                <p>No students are enrolled specifically in this unit yet.</p>
                <p><a href="?page=mtti-mis-units&action=add-learner&unit_id=<?php echo $unit_id; ?>" class="button button-primary">Add First Learner</a></p>
            </div>
            <?php endif; ?>
            
            <div style="margin-top:30px;padding:15px;background:#fff3e0;border-left:4px solid #FF9800;border-radius:4px;">
                <strong>💡 When to use Unit Enrollments:</strong>
                <ul style="margin:10px 0 0 20px;">
                    <li>A student wants to learn only one specific skill/module</li>
                    <li>A professional wants to upgrade a specific competency</li>
                    <li>Students from other courses want to cross-enroll in this unit</li>
                    <li>Guest learners participating in a single workshop/module</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Display add learner form for a unit
     */
    private function display_add_learner_form($unit_id) {
        global $wpdb;
        
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) wp_die('Unit not found');
        
        $course = $this->db->get_course($unit->course_id);
        
        $students_table = $this->db->get_table_name('students');
        $enrollments_table = $this->db->get_table_name('enrollments');
        $unit_enrollments_table = $this->db->get_table_name('unit_enrollments');
        
        // Get all active students who are NOT enrolled in the full course and NOT already in this unit
        $available_students = $wpdb->get_results($wpdb->prepare(
            "SELECT s.student_id, s.admission_number, COALESCE(u.display_name, s.admission_number) as display_name
             FROM {$students_table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.status = 'Active'
             AND s.student_id NOT IN (
                 SELECT student_id FROM {$enrollments_table} WHERE course_id = %d AND status != 'Cancelled'
             )
             AND s.student_id NOT IN (
                 SELECT student_id FROM {$unit_enrollments_table} WHERE unit_id = %d AND status = 'Active'
             )
             ORDER BY s.admission_number ASC",
            $unit->course_id,
            $unit_id
        ));
        
        ?>
        <div class="wrap">
            <h1>Add Learner to Unit: <?php echo esc_html($unit->unit_name); ?></h1>
            <p>
                <a href="?page=mtti-mis-units&action=manage-learners&unit_id=<?php echo $unit_id; ?>">← Back to Manage Learners</a> |
                <a href="?page=mtti-mis-units&action=view&course_id=<?php echo $unit->course_id; ?>">← Back to Units</a>
            </p>
            
            <div style="background:#e7f3ff;padding:15px;margin:20px 0;border-left:4px solid #2196F3;">
                <strong>📚 Course:</strong> <?php echo esc_html($course->course_name); ?><br>
                <strong>📖 Unit:</strong> <?php echo esc_html($unit->unit_code); ?> - <?php echo esc_html($unit->unit_name); ?>
            </div>
            
            <div style="background:#fff3e0;padding:15px;margin:20px 0;border-left:4px solid #FF9800;">
                <strong>ℹ️ Note:</strong> Students already enrolled in <strong><?php echo esc_html($course->course_name); ?></strong> are automatically included in all units. 
                Use this form to add students who want to take ONLY this unit without enrolling in the full course.
            </div>
            
            <?php if (empty($available_students)) : ?>
            <div class="notice notice-warning">
                <p><strong>No available students found.</strong></p>
                <p>All existing students are either enrolled in the full course or already added to this unit.</p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=add'); ?>" class="button button-primary">Register New Student</a>
                </p>
            </div>
            <?php else : ?>
            
            <!-- Single Student Enrollment -->
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                <h2 style="margin-top:0;">Add Single Learner</h2>
                <form method="post">
                    <?php wp_nonce_field('mtti_unit_enrollment_action', 'mtti_unit_enrollment_nonce'); ?>
                    <input type="hidden" name="enrollment_action" value="add_single">
                    <input type="hidden" name="unit_id" value="<?php echo $unit_id; ?>">
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="student_id">Select Student *</label></th>
                            <td>
                                <select name="student_id" id="student_id" required style="min-width:300px;">
                                    <option value="">-- Select a Student --</option>
                                    <?php foreach ($available_students as $student) : ?>
                                    <option value="<?php echo $student->student_id; ?>">
                                        <?php echo esc_html($student->admission_number . ' - ' . $student->display_name); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Only showing students not enrolled in the full course</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="notes">Notes</label></th>
                            <td>
                                <textarea name="notes" id="notes" rows="2" class="large-text" placeholder="Optional: reason for unit-only enrollment, special arrangements, etc."></textarea>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="mtti_unit_enrollment_submit" class="button button-primary" value="Add Learner">
                        <a href="?page=mtti-mis-units&action=manage-learners&unit_id=<?php echo $unit_id; ?>" class="button">Cancel</a>
                    </p>
                </form>
            </div>
            
            <!-- Bulk Student Enrollment -->
            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="margin-top:0;">Bulk Add Learners</h2>
                <p class="description">Select multiple students to add to this unit at once.</p>
                
                <form method="post">
                    <?php wp_nonce_field('mtti_unit_enrollment_action', 'mtti_unit_enrollment_nonce'); ?>
                    <input type="hidden" name="enrollment_action" value="add_bulk">
                    <input type="hidden" name="unit_id" value="<?php echo $unit_id; ?>">
                    
                    <div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#f9f9f9;margin:10px 0;">
                        <label style="display:block;margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #ddd;">
                            <input type="checkbox" id="select-all-students"> <strong>Select All</strong>
                        </label>
                        <?php foreach ($available_students as $student) : ?>
                        <label style="display:block;padding:5px 0;">
                            <input type="checkbox" name="student_ids[]" value="<?php echo $student->student_id; ?>" class="student-checkbox">
                            <?php echo esc_html($student->admission_number . ' - ' . $student->display_name); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <p class="submit">
                        <input type="submit" name="mtti_unit_enrollment_submit" class="button button-secondary" value="Add Selected Learners">
                    </p>
                </form>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#select-all-students').on('change', function() {
                    $('.student-checkbox').prop('checked', $(this).is(':checked'));
                });
            });
            </script>
            
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function display_messages() {
        if (!isset($_GET['message'])) return;
        $msg = '';
        $type = 'success';
        switch ($_GET['message']) {
            case 'created': $msg = 'Unit created successfully!'; break;
            case 'updated': $msg = 'Unit updated successfully!'; break;
            case 'deleted': $msg = 'Unit deleted successfully!'; break;
            case 'bulk-added': $msg = (isset($_GET['count']) ? intval($_GET['count']) : 0) . ' units added successfully!'; break;
            case 'marks-saved': $msg = 'Marks saved successfully!'; break;
            case 'learner-added': $msg = 'Learner added to unit successfully!'; break;
            case 'learner-removed': $msg = 'Learner removed from unit successfully!'; break;
            case 'already-enrolled': 
                $msg = 'This student is already enrolled in this unit.'; 
                $type = 'warning';
                break;
        }
        if ($msg) echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
}
