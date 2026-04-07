<?php
/**
 * Certificates Admin Class - Without Enrollments
 * Generates certificates and transcripts directly from student and course data
 * Now supports bulk printing of transcripts and certificates
 * 
 * @version 3.9.8
 */
class MTTI_MIS_Admin_Certificates {
    
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        // Certificate and transcript handling is now done early via the 'init' hook
        // in mtti-mis.php to prevent "headers already sent" errors.
        // This display() method only handles the admin UI screens.
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'generate':
                $this->display_certificate_form();
                break;
            case 'transcript':
                // Transcript is handled by early hook, but if we get here somehow,
                // redirect properly or show an error
                if (isset($_GET['student_id'])) {
                    // This shouldn't happen normally, but as fallback:
                    echo '<div class="notice notice-info"><p>Please <a href="' . esc_url(admin_url('admin.php?page=mtti-mis-certificates&action=transcript&student_id=' . intval($_GET['student_id']))) . '" target="_blank">click here to view the transcript</a> in a new tab.</p></div>';
                }
                $this->display_list();
                break;
            case 'bulk-transcripts':
                $this->display_bulk_transcripts_page();
                break;
            case 'bulk-certificates':
                $this->display_bulk_certificates_page();
                break;
            default:
                $this->display_list();
        }
    }
    
    private function display_list() {
        global $wpdb;
        
        // Get all active students with their course info
        $students_table = $this->db->get_table_name('students');
        $courses_table = $this->db->get_table_name('courses');
        $units_table = $this->db->get_table_name('course_units');
        $unit_results_table = $this->db->get_table_name('unit_results');
        $enrollments_table = $this->db->get_table_name('enrollments');
        
        // FIX: Query via enrollments so students enrolled in multiple courses
        // appear once per course (e.g. Mercy Jepkemboi enrolled in COE and COA
        // will produce two rows — one for each course).
        // Previously this joined on s.course_id (primary course only), hiding
        // any additional enrollment.
        $students = $wpdb->get_results("
            SELECT s.*, u.display_name, u.user_email,
                   c.course_id, c.course_name, c.course_code,
                   e.enrollment_id, e.status AS enrollment_status
            FROM {$students_table} s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            INNER JOIN {$enrollments_table} e ON e.student_id = s.student_id
                   AND e.status IN ('Active', 'Enrolled', 'In Progress')
            LEFT JOIN {$courses_table} c ON c.course_id = e.course_id
            WHERE s.status = 'Active'
            ORDER BY u.display_name ASC, c.course_name ASC
        ");
        
        // Get exam results and fee balances for each student
        $student_data = array();
        foreach ($students as $student) {
            // Get exam results
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mtti_exam_results WHERE admission_number = %s",
                $student->admission_number
            ));
            
            $total_pct = 0;
            $passed = 0;
            $failed = 0;
            foreach ($results as $r) {
                $total_pct += $r->percentage;
                if ($r->percentage >= 50) $passed++; else $failed++;
            }
            $avg = count($results) > 0 ? round($total_pct / count($results), 1) : 0;
            
            // Balance — calculated the same way as the Student Details fee panel
            // so the two screens always show identical numbers.
            $balance = $this->get_live_balance($student->student_id, $student->course_id);
            
            // Eligibility via exam_results (legacy HTML exams)
            $has_exam_results = count($results) > 0;
            $all_passed = ($failed == 0 && $has_exam_results);
            $avg_above_50 = ($avg >= 50);
            $fees_cleared = ($balance <= 0);
            $exams_eligible = $all_passed && $avg_above_50;
            
            // Eligibility via unit_results (unit-based system) — THIS IS THE PRIMARY SYSTEM
            $units_eligible = false;
            $total_units = 0;
            $completed_units = 0;
            $units_passed = 0;
            $units_failed = 0;
            $unit_avg = 0;
            
            if ($student->course_id) {
                $total_units = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
                    $student->course_id
                )));
                if ($total_units > 0) {
                    // Count how many units have results AND get pass/fail/avg
                    $unit_stats = $wpdb->get_row($wpdb->prepare(
                        "SELECT COUNT(DISTINCT ur.unit_id) as completed,
                                SUM(CASE WHEN ur.passed = 1 THEN 1 ELSE 0 END) as passed,
                                SUM(CASE WHEN ur.passed = 0 THEN 1 ELSE 0 END) as failed,
                                AVG(ur.percentage) as avg_pct
                         FROM {$unit_results_table} ur
                         INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
                         WHERE ur.student_id = %d AND cu.course_id = %d",
                        $student->student_id, $student->course_id
                    ));
                    $completed_units = intval($unit_stats->completed);
                    $units_passed    = intval($unit_stats->passed);
                    $units_failed    = intval($unit_stats->failed);
                    $unit_avg        = $unit_stats->avg_pct ? round($unit_stats->avg_pct, 1) : 0;
                    $units_eligible  = ($completed_units >= $total_units);
                }
            }
            
            // has_results = TRUE if EITHER exam results OR unit results exist
            $has_results = $has_exam_results || ($completed_units > 0);
            
            // Display values: prefer unit results if they exist, else fall back to legacy exams
            if ($completed_units > 0) {
                $display_passed  = $units_passed;
                $display_failed  = $units_failed;
                $display_total   = $completed_units;
                $display_avg     = $unit_avg;
                $display_label   = $completed_units . '/' . $total_units . ' units';
            } else {
                $display_passed  = $passed;
                $display_failed  = $failed;
                $display_total   = count($results);
                $display_avg     = $avg;
                $display_label   = count($results) . ' exam(s)';
            }
            
            // Eligible if EITHER system confirms + fees cleared
            $eligible = ($exams_eligible || $units_eligible) && $fees_cleared;

            // KEY: student_id + course_id so multi-course students each get their own entry
            $data_key = $student->student_id . '_' . $student->course_id;
            $student_data[$data_key] = array(
                'results'        => $display_total,
                'passed'         => $display_passed,
                'failed'         => $display_failed,
                'avg'            => $display_avg,
                'label'          => $display_label,
                'balance'        => $balance,
                'eligible'       => $eligible,
                'has_results'    => $has_results,
                'total_units'    => $total_units,
                'completed_units'=> $completed_units,
            );
        }
        ?>
        <div class="wrap">
            <h1>Certificates & Transcripts 
                <a href="?page=mtti-mis-certificates&action=bulk-transcripts" class="page-title-action">📄 Bulk Print Transcripts</a>
                <a href="?page=mtti-mis-certificates&action=bulk-certificates" class="page-title-action">🎓 Bulk Print Certificates</a>
            </h1>
            <p class="description">Generate certificates and academic transcripts for students.</p>
            
            <div style="background: #e7f3ff; padding: 15px; margin: 20px 0; border-left: 4px solid #2196F3; border-radius: 4px;">
                <strong>Certificate Eligibility Requirements:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <li>✓ Has exam results</li>
                    <li>✓ All exams passed (50% minimum each)</li>
                    <li>✓ Overall average above 50%</li>
                    <li>✓ Fees fully paid (zero balance)</li>
                </ul>
            </div>
            
            <h2>Students</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Admission Number</th>
                        <th>Course</th>
                        <th>Units / Results</th>
                        <th>Average</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students && count($students) > 0) : foreach ($students as $student) : 
                        $course_name = $student->course_id ? $student->course_code . ' - ' . $student->course_name : 'Not Assigned';
                        $data_key = $student->student_id . '_' . $student->course_id;
                        $data = isset($student_data[$data_key]) ? $student_data[$data_key] : array(
                            'results' => 0, 'passed' => 0, 'failed' => 0, 'avg' => 0,
                            'label' => '', 'balance' => 0, 'eligible' => false,
                            'has_results' => false, 'total_units' => 0, 'completed_units' => 0,
                        );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($student->display_name); ?></strong></td>
                        <td><?php echo esc_html($student->admission_number); ?></td>
                        <td><?php echo esc_html($course_name); ?></td>
                        <td>
                            <?php if ($data['has_results']): ?>
                                <strong style="color: #00b894;"><?php echo $data['passed']; ?></strong> passed
                                <?php if ($data['failed'] > 0): ?>
                                    / <span style="color: #d63031;"><?php echo $data['failed']; ?> failed</span>
                                <?php endif; ?>
                                <br><small style="color:#888;"><?php echo esc_html($data['label']); ?></small>
                            <?php else: ?>
                                <span style="color: #999;">No results yet</span>
                                <?php if ($data['total_units'] > 0): ?>
                                <br><small style="color:#aaa;"><?php echo $data['total_units']; ?> unit(s) not yet assessed</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($data['has_results']): ?>
                                <strong style="color: <?php echo $data['avg'] >= 50 ? '#00b894' : '#d63031'; ?>;">
                                    <?php echo $data['avg']; ?>%
                                </strong>
                            <?php else: ?>
                                <span style="color:#ccc;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($data['balance'] > 0): ?>
                                <span style="color: #d63031;">KES <?php echo number_format($data['balance'], 2); ?></span>
                            <?php else: ?>
                                <span style="color: #00b894;">✓ Cleared</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($data['eligible']): ?>
                                <span style="background: #00b894; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px;">✓ ELIGIBLE</span>
                            <?php else: ?>
                                <span style="background: #ddd; color: #666; padding: 3px 8px; border-radius: 4px; font-size: 11px;">NOT ELIGIBLE</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($data['has_results']): ?>
                            <a href="?page=mtti-mis-certificates&action=transcript&student_id=<?php echo $student->student_id; ?>" 
                               class="button button-primary" target="_blank">
                                📜 Transcript
                            </a>
                            <?php endif; ?>
                            <?php if ($data['eligible'] && $student->course_id) : ?>
                            <a href="?page=mtti-mis-certificates&action=generate&student_id=<?php echo $student->student_id; ?>" 
                               class="button" style="background: #00b894; color: white; border-color: #00b894;">
                                🎓 Certificate
                            </a>
                            <?php elseif ($student->course_id && $data['has_results']): ?>
                            <button class="button" disabled title="Not eligible - check requirements">
                                🎓 Certificate
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px;">
                            No students found.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Display bulk transcripts selection page
     */
    private function display_bulk_transcripts_page() {
        global $wpdb;
        
        $unit_results_table = $this->db->get_table_name('unit_results');
        $units_table = $this->db->get_table_name('course_units');
        $courses_table = $this->db->get_table_name('courses');
        $students_table = $this->db->get_table_name('students');
        
        // Get all unit results grouped by course
        $results = $wpdb->get_results(
            "SELECT ur.*, cu.unit_name, cu.unit_code, cu.unit_id,
                    c.course_name, c.course_code, c.course_id,
                    s.admission_number, s.student_id,
                    COALESCE(u.display_name, s.admission_number) as student_name
             FROM {$unit_results_table} ur
             LEFT JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
             LEFT JOIN {$courses_table} c ON cu.course_id = c.course_id
             LEFT JOIN {$students_table} s ON ur.student_id = s.student_id
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             ORDER BY c.course_name, cu.unit_name, s.admission_number"
        );
        
        // Group by course then unit
        $grouped = array();
        foreach ($results as $r) {
            $course_key = $r->course_id . '_' . $r->course_name;
            $unit_key = $r->unit_id . '_' . $r->unit_name;
            if (!isset($grouped[$course_key])) {
                $grouped[$course_key] = array(
                    'course_name' => $r->course_name,
                    'course_code' => $r->course_code,
                    'units' => array()
                );
            }
            if (!isset($grouped[$course_key]['units'][$unit_key])) {
                $grouped[$course_key]['units'][$unit_key] = array(
                    'unit_name' => $r->unit_name,
                    'unit_code' => $r->unit_code,
                    'unit_id' => $r->unit_id,
                    'students' => array()
                );
            }
            $grouped[$course_key]['units'][$unit_key]['students'][] = $r;
        }
        ?>
        <div class="wrap">
            <h1>📄 Bulk Print Transcripts
                <a href="?page=mtti-mis-certificates" class="page-title-action">← Back</a>
            </h1>
            
            <div style="background:#e7f3ff;padding:15px;margin:20px 0;border-left:4px solid #2196F3;border-radius:4px;">
                <strong>Instructions:</strong>
                <ol style="margin:10px 0 0 20px;">
                    <li>Select the unit transcripts you want to print using the checkboxes</li>
                    <li>Click "Print Selected Transcripts" button</li>
                    <li>A new window will open with all selected transcripts</li>
                    <li>Use your browser's print function (Ctrl+P) to print them all</li>
                </ol>
            </div>
            
            <?php if (empty($grouped)) : ?>
            <div class="notice notice-warning">
                <p>No unit results found. Enter marks for students first to generate transcripts.</p>
            </div>
            <?php else : ?>
            
            <form id="bulk-transcripts-form">
                <div style="position:sticky;top:32px;background:#fff;padding:15px;margin-bottom:20px;border:1px solid #ccc;border-radius:4px;z-index:100;box-shadow:0 2px 5px rgba(0,0,0,0.1);">
                    <label style="margin-right:20px;">
                        <input type="checkbox" id="select-all-transcripts"> <strong>Select All</strong>
                    </label>
                    <button type="button" id="print-selected-transcripts" class="button button-primary button-large">
                        🖨️ Print Selected Transcripts (<span id="selected-count">0</span>)
                    </button>
                    <span id="selection-info" style="margin-left:20px;color:#666;"></span>
                </div>
                
                <?php foreach ($grouped as $course_key => $course_data) : ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;margin-bottom:20px;overflow:hidden;">
                    <div style="background:#2E7D32;color:#fff;padding:12px 15px;">
                        <label>
                            <input type="checkbox" class="course-checkbox" data-course="<?php echo esc_attr($course_key); ?>">
                            <strong><?php echo esc_html($course_data['course_code']); ?>:</strong> <?php echo esc_html($course_data['course_name']); ?>
                        </label>
                    </div>
                    
                    <?php foreach ($course_data['units'] as $unit_key => $unit_data) : ?>
                    <div style="border-bottom:1px solid #eee;">
                        <div style="background:#e8f5e9;padding:10px 15px;border-bottom:1px solid #ddd;">
                            <label>
                                <input type="checkbox" class="unit-checkbox" data-course="<?php echo esc_attr($course_key); ?>" data-unit="<?php echo esc_attr($unit_key); ?>">
                                <strong><?php echo esc_html($unit_data['unit_code']); ?>:</strong> <?php echo esc_html($unit_data['unit_name']); ?>
                                <span style="color:#666;font-size:12px;">(<?php echo count($unit_data['students']); ?> students)</span>
                            </label>
                        </div>
                        <div style="padding:10px 15px 10px 35px;display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));gap:5px;">
                            <?php foreach ($unit_data['students'] as $student) : ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:5px;border-radius:4px;cursor:pointer;" 
                                   onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" class="transcript-checkbox" 
                                       data-course="<?php echo esc_attr($course_key); ?>" 
                                       data-unit="<?php echo esc_attr($unit_key); ?>"
                                       value="<?php echo esc_attr($student->unit_id . '_' . $student->student_id); ?>">
                                <span><?php echo esc_html($student->admission_number); ?> - <?php echo esc_html($student->student_name); ?></span>
                                <span style="background:<?php 
                                    echo in_array($student->grade, ['A','A-']) ? '#4CAF50' : 
                                        (strpos($student->grade, 'B') === 0 ? '#2196F3' : 
                                        (strpos($student->grade, 'C') === 0 ? '#FF9800' : '#f44336')); 
                                ?>;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:bold;">
                                    <?php echo esc_html($student->grade); ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                function updateCount() {
                    var count = $('.transcript-checkbox:checked').length;
                    $('#selected-count').text(count);
                    $('#selection-info').text(count > 0 ? count + ' transcript(s) selected' : '');
                }
                
                // Select all
                $('#select-all-transcripts').on('change', function() {
                    var checked = $(this).is(':checked');
                    $('.transcript-checkbox, .unit-checkbox, .course-checkbox').prop('checked', checked);
                    updateCount();
                });
                
                // Course checkbox
                $('.course-checkbox').on('change', function() {
                    var course = $(this).data('course');
                    var checked = $(this).is(':checked');
                    $('.unit-checkbox[data-course="' + course + '"], .transcript-checkbox[data-course="' + course + '"]').prop('checked', checked);
                    updateCount();
                });
                
                // Unit checkbox
                $('.unit-checkbox').on('change', function() {
                    var unit = $(this).data('unit');
                    var checked = $(this).is(':checked');
                    $('.transcript-checkbox[data-unit="' + unit + '"]').prop('checked', checked);
                    updateCount();
                });
                
                // Individual checkbox
                $('.transcript-checkbox').on('change', function() {
                    updateCount();
                });
                
                // Print selected
                $('#print-selected-transcripts').on('click', function() {
                    var selected = [];
                    $('.transcript-checkbox:checked').each(function() {
                        selected.push($(this).val());
                    });
                    
                    if (selected.length === 0) {
                        alert('Please select at least one transcript to print.');
                        return;
                    }
                    
                    // Open bulk print page
                    var url = '<?php echo admin_url('admin.php?page=mtti-mis-certificates&action=bulk-print-transcripts'); ?>&items=' + encodeURIComponent(selected.join(','));
                    window.open(url, '_blank');
                });
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display bulk certificates selection page
     */
    private function display_bulk_certificates_page() {
        global $wpdb;
        
        $enrollments_table = $this->db->get_table_name('enrollments');
        $courses_table = $this->db->get_table_name('courses');
        $students_table = $this->db->get_table_name('students');
        $unit_results_table = $this->db->get_table_name('unit_results');
        $units_table = $this->db->get_table_name('course_units');
        
        // Get completed enrollments (students who have completed courses)
        $enrollments = $wpdb->get_results(
            "SELECT e.*, c.course_name, c.course_code, c.course_id,
                    s.admission_number, s.student_id,
                    COALESCE(u.display_name, s.admission_number) as student_name
             FROM {$enrollments_table} e
             LEFT JOIN {$courses_table} c ON e.course_id = c.course_id
             LEFT JOIN {$students_table} s ON e.student_id = s.student_id
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE e.status IN ('Completed', 'Enrolled')
             ORDER BY c.course_name, s.admission_number"
        );
        
        // Group by course
        $grouped = array();
        foreach ($enrollments as $e) {
            $course_key = $e->course_id;
            if (!isset($grouped[$course_key])) {
                $grouped[$course_key] = array(
                    'course_name' => $e->course_name,
                    'course_code' => $e->course_code,
                    'course_id' => $e->course_id,
                    'students' => array()
                );
            }
            
            // Calculate average grade from unit results
            $avg_score = $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(ur.score) FROM {$unit_results_table} ur
                 INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
                 WHERE ur.student_id = %d AND cu.course_id = %d",
                $e->student_id, $e->course_id
            ));
            
            $e->avg_grade = $avg_score ? $this->calculate_grade_from_score($avg_score) : 'N/A';
            $grouped[$course_key]['students'][] = $e;
        }
        ?>
        <div class="wrap">
            <h1>🎓 Bulk Print Certificates
                <a href="?page=mtti-mis-certificates" class="page-title-action">← Back</a>
            </h1>
            
            <div style="background:#fff3e0;padding:15px;margin:20px 0;border-left:4px solid #FF9800;border-radius:4px;">
                <strong>⚠️ Note:</strong> Certificates are generated based on course enrollments. 
                Students must be enrolled in a course to generate certificates. 
                The grade is calculated from the average of all unit results for that course.
            </div>
            
            <div style="background:#e7f3ff;padding:15px;margin:20px 0;border-left:4px solid #2196F3;border-radius:4px;">
                <strong>Instructions:</strong>
                <ol style="margin:10px 0 0 20px;">
                    <li>Select the certificates you want to print</li>
                    <li>Set the completion date for the certificates</li>
                    <li>Click "Print Selected Certificates"</li>
                    <li>A new window will open with all certificates ready to print</li>
                </ol>
            </div>
            
            <?php if (empty($grouped)) : ?>
            <div class="notice notice-warning">
                <p>No enrollments found. Enroll students in courses first to generate certificates.</p>
            </div>
            <?php else : ?>
            
            <form id="bulk-certificates-form">
                <div style="position:sticky;top:32px;background:#fff;padding:15px;margin-bottom:20px;border:1px solid #ccc;border-radius:4px;z-index:100;box-shadow:0 2px 5px rgba(0,0,0,0.1);">
                    <label style="margin-right:20px;">
                        <input type="checkbox" id="select-all-certificates"> <strong>Select All</strong>
                    </label>
                    <label style="margin-right:20px;">
                        <strong>Completion Date:</strong>
                        <input type="date" id="bulk-completion-date" value="<?php echo date('Y-m-d'); ?>" style="margin-left:5px;">
                    </label>
                    <button type="button" id="print-selected-certificates" class="button button-primary button-large">
                        🖨️ Print Selected Certificates (<span id="cert-selected-count">0</span>)
                    </button>
                </div>
                
                <?php foreach ($grouped as $course_id => $course_data) : ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;margin-bottom:20px;overflow:hidden;">
                    <div style="background:#1976D2;color:#fff;padding:12px 15px;">
                        <label>
                            <input type="checkbox" class="course-cert-checkbox" data-course="<?php echo esc_attr($course_id); ?>">
                            <strong><?php echo esc_html($course_data['course_code']); ?>:</strong> <?php echo esc_html($course_data['course_name']); ?>
                            <span style="opacity:0.8;font-size:12px;">(<?php echo count($course_data['students']); ?> students)</span>
                        </label>
                    </div>
                    
                    <div style="padding:15px;display:grid;grid-template-columns:repeat(auto-fill, minmax(350px, 1fr));gap:10px;">
                        <?php foreach ($course_data['students'] as $student) : ?>
                        <label style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;background:#fafafa;" 
                               onmouseover="this.style.background='#e3f2fd'" onmouseout="this.style.background='#fafafa'">
                            <input type="checkbox" class="certificate-checkbox" 
                                   data-course="<?php echo esc_attr($course_id); ?>"
                                   value="<?php echo esc_attr($student->student_id . '_' . $course_id); ?>">
                            <div style="flex:1;">
                                <div><strong><?php echo esc_html($student->student_name); ?></strong></div>
                                <div style="font-size:12px;color:#666;"><?php echo esc_html($student->admission_number); ?></div>
                            </div>
                            <div style="text-align:right;">
                                <span style="background:<?php 
                                    echo in_array($student->avg_grade, ['A','A-']) ? '#4CAF50' : 
                                        (strpos($student->avg_grade, 'B') === 0 ? '#2196F3' : 
                                        (strpos($student->avg_grade, 'C') === 0 ? '#FF9800' : '#9e9e9e')); 
                                ?>;color:#fff;padding:3px 10px;border-radius:4px;font-weight:bold;">
                                    <?php echo esc_html($student->avg_grade); ?>
                                </span>
                                <div style="font-size:11px;color:#666;margin-top:3px;">
                                    <?php echo esc_html(ucfirst(strtolower($student->status))); ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                function updateCertCount() {
                    var count = $('.certificate-checkbox:checked').length;
                    $('#cert-selected-count').text(count);
                }
                
                $('#select-all-certificates').on('change', function() {
                    var checked = $(this).is(':checked');
                    $('.certificate-checkbox, .course-cert-checkbox').prop('checked', checked);
                    updateCertCount();
                });
                
                $('.course-cert-checkbox').on('change', function() {
                    var course = $(this).data('course');
                    var checked = $(this).is(':checked');
                    $('.certificate-checkbox[data-course="' + course + '"]').prop('checked', checked);
                    updateCertCount();
                });
                
                $('.certificate-checkbox').on('change', function() {
                    updateCertCount();
                });
                
                $('#print-selected-certificates').on('click', function() {
                    var selected = [];
                    $('.certificate-checkbox:checked').each(function() {
                        selected.push($(this).val());
                    });
                    
                    if (selected.length === 0) {
                        alert('Please select at least one certificate to print.');
                        return;
                    }
                    
                    var completionDate = $('#bulk-completion-date').val();
                    if (!completionDate) {
                        alert('Please select a completion date.');
                        return;
                    }
                    
                    var url = '<?php echo admin_url('admin.php?page=mtti-mis-certificates&action=bulk-print-certificates'); ?>&items=' + encodeURIComponent(selected.join(',')) + '&completion_date=' + completionDate;
                    window.open(url, '_blank');
                });
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Calculate grade from score
     */
    private function calculate_grade_from_score($score) {
        $score = round($score);
        if ($score >= 78) return 'A';
        if ($score >= 71) return 'A-';
        if ($score >= 64) return 'B+';
        if ($score >= 57) return 'B';
        if ($score >= 50) return 'B-';
        if ($score >= 43) return 'C+';
        if ($score >= 36) return 'C';
        if ($score >= 29) return 'C-';
        if ($score >= 22) return 'D+';
        if ($score >= 15) return 'D';
        if ($score >= 8) return 'D-';
        return 'E';
    }

    /**
     * Calculate the live balance for one course enrollment, using the same logic
     * as the Student Details fee summary panel — so the two screens always agree.
     *
     * Logic (mirrors admin/class-mtti-mis-admin-students.php ~line 697):
     *   1. Use courses.fee as the authoritative fee (never the stale stored value).
     *   2. Read discount_amount from student_balances.
     *   3. Sum completed payments.amount linked to this enrollment.
     *   4. Add any unlinked payments (enrollment_id IS NULL / 0 / not matching an
     *      active enrollment) distributing them against this balance first.
     *   5. balance = max(0, fee - discount - paid - unlinked_applied)
     *
     * @param int $student_id
     * @param int $course_id   The specific course to check.
     * @return float           Balance owed (0 if cleared).
     */
    private function get_live_balance($student_id, $course_id) {
        global $wpdb;

        $enrollments_table = $wpdb->prefix . 'mtti_enrollments';
        $balances_table    = $wpdb->prefix . 'mtti_student_balances';
        $courses_table     = $wpdb->prefix . 'mtti_courses';
        $payments_table    = $wpdb->prefix . 'mtti_payments';

        // Get the enrollment row and actual course fee in one query
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT e.enrollment_id, c.fee AS actual_fee, sb.discount_amount
             FROM   {$enrollments_table} e
             JOIN   {$courses_table}     c  ON  c.course_id     = e.course_id
             LEFT JOIN {$balances_table} sb ON sb.enrollment_id = e.enrollment_id
             WHERE  e.student_id = %d
               AND  e.course_id  = %d
               AND  e.status IN ('Active','Enrolled','In Progress')
             LIMIT 1",
            $student_id, $course_id
        ));

        if ( ! $row ) return 0.0;

        $actual_fee      = floatval($row->actual_fee);
        $discount        = floatval($row->discount_amount ?? 0);
        $net_fee         = max(0.0, $actual_fee - $discount);
        $enrollment_id   = intval($row->enrollment_id);

        // Sum payments linked to this enrollment
        $paid = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM   {$payments_table}
             WHERE  student_id = %d AND enrollment_id = %d AND status = 'Completed'",
            $student_id, $enrollment_id
        )));

        $balance = max(0.0, $net_fee - $paid);

        // Distribute unlinked payments (same as student details panel)
        if ($balance > 0) {
            $unlinked = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM   {$payments_table}
                 WHERE  student_id = %d AND status = 'Completed'
                   AND (enrollment_id IS NULL OR enrollment_id = 0
                        OR enrollment_id NOT IN (
                            SELECT enrollment_id FROM {$enrollments_table}
                            WHERE  student_id = %d
                              AND  status IN ('Active','Enrolled','In Progress')
                        ))",
                $student_id, $student_id
            )));
            if ($unlinked > 0) {
                $balance = max(0.0, $balance - $unlinked);
            }
        }

        return $balance;
    }
    
    /**
     * Output transcript as standalone HTML page
     */
    private function output_transcript($student_id) {
        global $wpdb;
        
        // Clean ALL output buffers to get a fresh page
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Get student details
        $student = $this->db->get_student($student_id);
        if (!$student) {
            wp_die('Student not found');
        }
        
        // Get all unit results for this student
        $unit_results_table = $this->db->get_table_name('unit_results');
        $units_table = $this->db->get_table_name('course_units');
        $courses_table = $this->db->get_table_name('courses');
        
        $unit_results = $wpdb->get_results($wpdb->prepare(
            "SELECT ur.*, 
                    cu.unit_name, cu.unit_code, cu.duration_hours,
                    c.course_name, c.course_code
             FROM {$unit_results_table} ur
             LEFT JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
             LEFT JOIN {$courses_table} c ON cu.course_id = c.course_id
             WHERE ur.student_id = %d
             ORDER BY c.course_name, cu.order_number, cu.unit_code",
            $student_id
        ));
        
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        
        // Output clean HTML - no WordPress
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Transcript - <?php echo esc_html($student->display_name); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { 
            size: A4 portrait; 
            margin: 15mm 15mm 20mm 15mm;
        }
        @media print {
            .no-print { display: none !important; }
            html, body { 
                print-color-adjust: exact; 
                -webkit-print-color-adjust: exact;
                background: white !important;
                padding: 0 !important;
            }
            .transcript-container {
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
        }
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 11.5px;
            line-height: 1.5;
            color: #222;
            background: #f0f0f0;
            padding: 20px;
        }
        .transcript-container {
            max-width: 820px;
            margin: 0 auto;
            background: white;
            padding: 35px 40px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.15);
        }
        .header { 
            text-align: center; 
            border-bottom: 4px double #2E7D32; 
            padding-bottom: 18px; 
            margin-bottom: 25px; 
        }
        .header-top { display: flex; align-items: center; justify-content: center; gap: 20px; flex-wrap: wrap; }
        .logo { width: 75px; height: auto; }
        .header-text h1 { color: #1B5E20; font-size: 20px; margin: 0 0 4px 0; letter-spacing: 0.5px; }
        .header-text p { color: #555; font-size: 11px; margin: 0; }
        .header h2 { 
            color: white; background: #2E7D32; 
            font-size: 15px; margin: 15px 0 0 0; 
            padding: 8px 20px; letter-spacing: 2px; text-transform: uppercase;
            display: inline-block; border-radius: 2px;
        }
        .section-title {
            color: #1B5E20;
            font-size: 13px;
            font-weight: bold;
            margin: 22px 0 10px 0;
            padding: 6px 10px;
            background: #e8f5e9;
            border-left: 4px solid #2E7D32;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 12px 0;
            background: #fafafa;
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
        }
        .info-item { display: flex; gap: 8px; align-items: baseline; }
        .info-label { font-weight: bold; min-width: 130px; color: #444; font-size: 11px; }
        .info-value { color: #222; font-size: 11.5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 11px; }
        thead { background: #1B5E20; color: white; }
        th { padding: 9px 8px; text-align: left; border: 1px solid #388E3C; font-size: 11px; }
        td { padding: 8px 8px; text-align: left; border: 1px solid #ddd; }
        tbody tr:nth-child(even) { background: #f9fdf9; }
        tbody tr:hover { background: #f0f7f0; }
        .grade-cell { font-weight: bold; font-size: 13px; }
        .grade-a { color: #2E7D32; }
        .grade-b { color: #1565C0; }
        .grade-c { color: #E65100; }
        .grade-d, .grade-e { color: #C62828; }
        .status-passed { color: #2E7D32; font-weight: bold; }
        .status-failed { color: #C62828; font-weight: bold; }
        .course-header { background: #C8E6C9 !important; }
        .course-header td { padding: 10px 8px !important; font-weight: bold; color: #1B5E20 !important; border-top: 2px solid #2E7D32; font-size: 12px; }
        .motto { color: #E65100; font-style: italic; text-align: center; margin-top: 25px; font-weight: bold; font-size: 13px; border: 1px dashed #E65100; padding: 8px; border-radius: 4px; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 2px solid #2E7D32; font-size: 9.5px; color: #666; text-align: center; line-height: 1.8; }
        .print-btn {
            position: fixed; top: 20px; right: 20px;
            padding: 10px 22px; background: #2E7D32; color: white;
            border: none; border-radius: 4px; cursor: pointer;
            font-size: 13px; font-weight: bold; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            z-index: 9999;
        }
        .print-btn:hover { background: #1B5E20; }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Print Transcript</button>
    
    <div class="transcript-container">
        <div class="header">
            <div class="header-top">
                <img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" class="logo">
                <div class="header-text">
                    <h1>Masomotele Technical Training Institute</h1>
                    <p>TVETA Accredited | Sagaas Center, Fourth Floor, Eldoret, Kenya</p>
                </div>
            </div>
            <h2>Official Academic Transcript</h2>
        </div>
        
        <div class="section-title">Student Information</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Student Name:</span>
                <span class="info-value"><strong><?php echo esc_html($student->display_name); ?></strong></span>
            </div>
            <div class="info-item">
                <span class="info-label">Admission Number:</span>
                <span class="info-value"><strong><?php echo esc_html($student->admission_number); ?></strong></span>
            </div>
            <div class="info-item">
                <span class="info-label">Email:</span>
                <span class="info-value"><?php echo esc_html($student->user_email); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">ID Number:</span>
                <span class="info-value"><?php echo esc_html($student->id_number ?: '—'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Gender:</span>
                <span class="info-value"><?php echo esc_html($student->gender ?: '—'); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Date Generated:</span>
                <span class="info-value"><?php echo date('F j, Y'); ?></span>
            </div>
        </div>
        
        <div class="section-title">Unit Results and Grades</div>
        <table>
            <thead>
                <tr>
                    <th>Unit Code</th>
                    <th>Unit Name</th>
                    <th>Hours</th>
                    <th>Score</th>
                    <th>Percentage</th>
                    <th>Grade</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($unit_results)) : ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 30px; color: #666;">
                        No unit results found for this student.
                    </td>
                </tr>
                <?php else : 
                    $current_course = '';
                    foreach ($unit_results as $result) : 
                        if ($current_course != $result->course_name && !empty($result->course_name)) :
                            $current_course = $result->course_name;
                ?>
                <tr class="course-header">
                    <td colspan="7"><?php echo esc_html($result->course_code); ?>: <?php echo esc_html($result->course_name); ?></td>
                </tr>
                <?php endif; 
                    $grade_class = '';
                    if (in_array($result->grade, ['A', 'A-'])) $grade_class = 'grade-a';
                    elseif (strpos($result->grade, 'B') === 0) $grade_class = 'grade-b';
                    elseif (strpos($result->grade, 'C') === 0) $grade_class = 'grade-c';
                    else $grade_class = 'grade-d';
                ?>
                <tr>
                    <td><strong><?php echo esc_html($result->unit_code); ?></strong></td>
                    <td><?php echo esc_html($result->unit_name); ?></td>
                    <td><?php echo $result->duration_hours ? intval($result->duration_hours) : '-'; ?></td>
                    <td><strong><?php echo number_format($result->score, 0); ?></strong> / 84</td>
                    <td><?php echo number_format($result->percentage, 1); ?>%</td>
                    <td class="grade-cell <?php echo $grade_class; ?>"><?php echo esc_html($result->grade); ?></td>
                    <td class="<?php echo $result->passed ? 'status-passed' : 'status-failed'; ?>">
                        <?php echo $result->passed ? 'Passed' : 'Failed'; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        
        <?php
        // Summary statistics
        if (!empty($unit_results)) {
            $total_units_done = count($unit_results);
            $passed_units = 0; $failed_units = 0; $total_pct = 0;
            foreach ($unit_results as $r) {
                $total_pct += $r->percentage;
                if ($r->passed) $passed_units++; else $failed_units++;
            }
            $overall_avg = round($total_pct / $total_units_done, 1);
            $overall_grade = $overall_avg >= 80 ? 'A' : ($overall_avg >= 70 ? 'B+' : ($overall_avg >= 60 ? 'B' : ($overall_avg >= 50 ? 'C' : 'D')));
        ?>
        <div class="section-title">Academic Summary</div>
        <table style="margin:15px 0;">
            <tbody>
                <tr style="background:#f9f9f9;">
                    <td style="width:50%;padding:10px 8px;border:1px solid #ddd;"><strong>Total Units Assessed</strong></td>
                    <td style="padding:10px 8px;border:1px solid #ddd;"><?php echo $total_units_done; ?></td>
                </tr>
                <tr>
                    <td style="padding:10px 8px;border:1px solid #ddd;"><strong>Units Passed</strong></td>
                    <td style="padding:10px 8px;border:1px solid #ddd;color:#2E7D32;font-weight:bold;"><?php echo $passed_units; ?></td>
                </tr>
                <?php if ($failed_units > 0): ?>
                <tr style="background:#fff3f3;">
                    <td style="padding:10px 8px;border:1px solid #ddd;"><strong>Units Failed</strong></td>
                    <td style="padding:10px 8px;border:1px solid #ddd;color:#D32F2F;font-weight:bold;"><?php echo $failed_units; ?></td>
                </tr>
                <?php endif; ?>
                <tr style="background:#e8f5e9;">
                    <td style="padding:10px 8px;border:1px solid #ddd;"><strong>Overall Average Score</strong></td>
                    <td style="padding:10px 8px;border:1px solid #ddd;font-weight:bold;font-size:15px;"><?php echo $overall_avg; ?>% — Grade <?php echo $overall_grade; ?></td>
                </tr>
            </tbody>
        </table>
        <?php } ?>

        <?php
        // Fee balance summary
        $fee_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sb.total_fee, sb.discount_amount, sb.total_paid, sb.balance, c.course_name, c.course_code
             FROM {$wpdb->prefix}mtti_student_balances sb
             INNER JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
             INNER JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE sb.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
            $student_id
        ));
        if (!empty($fee_rows)) {
            $grand_fee = $grand_paid = $grand_balance = 0;
            foreach ($fee_rows as $fr) {
                $grand_fee += $fr->total_fee;
                $grand_paid += $fr->total_paid;
                $grand_balance += $fr->balance;
            }
        ?>
        <div class="section-title">Fee Status</div>
        <table style="margin:15px 0;">
            <thead>
                <tr>
                    <th>Course</th>
                    <th style="text-align:right;">Total Fee (KES)</th>
                    <th style="text-align:right;">Paid (KES)</th>
                    <th style="text-align:right;">Balance (KES)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fee_rows as $fr): ?>
            <tr>
                <td style="padding:8px;border:1px solid #ddd;"><?php echo esc_html($fr->course_code . ' — ' . $fr->course_name); ?></td>
                <td style="text-align:right;padding:8px;border:1px solid #ddd;"><?php echo number_format($fr->total_fee, 2); ?></td>
                <td style="text-align:right;padding:8px;border:1px solid #ddd;color:#1976D2;"><?php echo number_format($fr->total_paid, 2); ?></td>
                <td style="text-align:right;padding:8px;border:1px solid #ddd;color:<?php echo $fr->balance > 0 ? '#D32F2F' : '#2E7D32'; ?>;font-weight:bold;"><?php echo number_format($fr->balance, 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f5f5f5;font-weight:bold;">
                <td style="padding:8px;border:1px solid #ddd;">TOTAL</td>
                <td style="text-align:right;padding:8px;border:1px solid #ddd;"><?php echo number_format($grand_fee, 2); ?></td>
                <td style="text-align:right;padding:8px;border:1px solid #ddd;color:#1976D2;"><?php echo number_format($grand_paid, 2); ?></td>
                <td style="text-align:right;padding:8px;border:1px solid #ddd;color:<?php echo $grand_balance > 0 ? '#D32F2F' : '#2E7D32'; ?>;"><?php echo number_format($grand_balance, 2); ?></td>
            </tr>
            </tbody>
        </table>
        <p style="font-size:11px;color:<?php echo $grand_balance > 0 ? '#D32F2F' : '#2E7D32'; ?>;margin-top:5px;">
            <?php echo $grand_balance > 0 
                ? '⚠ Outstanding balance: KES ' . number_format($grand_balance, 2) . ' — Certificate cannot be issued until fees are fully paid.' 
                : '✓ All fees cleared — Eligible for certificate issuance.'; ?>
        </p>
        <?php } ?>
        
        <p class="motto">"Start Learning, Start Earning"</p>
        
        <!-- Signature Block -->
        <div style="margin-top:50px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:30px;page-break-inside:avoid;">
            <div style="text-align:center;">
                <div style="border-top:1px solid #333;padding-top:8px;margin-top:60px;font-size:11px;">
                    <strong>Student Signature</strong><br>
                    <span style="color:#666;"><?php echo esc_html($student->display_name); ?></span>
                </div>
            </div>
            <div style="text-align:center;">
                <div style="border-top:1px solid #333;padding-top:8px;margin-top:60px;font-size:11px;">
                    <strong>HOD / Trainer Signature</strong><br>
                    <span style="color:#666;">Head of Department</span>
                </div>
            </div>
            <div style="text-align:center;">
                <div style="border-top:1px solid #333;padding-top:8px;margin-top:60px;font-size:11px;">
                    <strong>Principal Signature & Stamp</strong><br>
                    <span style="color:#666;">Masomotele T.T.I</span>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>This is an official academic transcript from Masomotele Technical Training Institute (TVETA Accredited).</p>
            <p>Sagaas Center, Fourth Floor, Eldoret, Kenya &nbsp;|&nbsp; Generated: <?php echo date('F j, Y g:i A'); ?></p>
            <p style="margin-top:5px;font-style:italic;color:#999;">This document is only valid with an official stamp and authorized signature.</p>
        </div>
    </div>
</body>
</html><?php
        exit; // Important: Stop execution here
    }
    
    /**
     * Output certificate PDF
     */
    private function output_certificate() {
        global $wpdb;
        
        $student_id = intval($_POST['student_id']);
        $course_id = intval($_POST['course_id']);
        $grade = sanitize_text_field($_POST['grade']);
        $completion_date = sanitize_text_field($_POST['completion_date']);
        
        $student = $this->db->get_student($student_id);
        $courses_table = $this->db->get_table_name('courses');
        $course = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$courses_table} WHERE course_id = %d",
            $course_id
        ));
        
        if (!$student || !$course) {
            wp_die('Invalid student or course');
        }
        
        // Server-side validation: Check balance and unit completion
        $eligibility = $this->check_certificate_eligibility($student_id, $course_id);
        
        if (!$eligibility['eligible']) {
            wp_die(
                '<h2>Cannot Generate Certificate</h2>' .
                '<p>' . esc_html($eligibility['reason']) . '</p>' .
                '<p><a href="' . admin_url('admin.php?page=mtti-mis-certificates') . '">← Back to Certificates</a></p>',
                'Certificate Generation Error'
            );
        }
        
        $this->create_certificate_pdf($student, $course, $grade, $completion_date);
    }
    
    /**
     * Check if student is eligible for certificate
     * Returns array with 'eligible' boolean and 'reason' string
     */
    private function check_certificate_eligibility($student_id, $course_id) {
        global $wpdb;
        
        $enrollments_table = $this->db->get_table_name('enrollments');
        $payments_table = $this->db->get_table_name('payments');
        $units_table = $this->db->get_table_name('course_units');
        $unit_results_table = $this->db->get_table_name('unit_results');
        $courses_table = $this->db->get_table_name('courses');
        
        // Check enrollment
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, c.fee as course_fee FROM {$enrollments_table} e
             LEFT JOIN {$courses_table} c ON e.course_id = c.course_id
             WHERE e.student_id = %d AND e.course_id = %d",
            $student_id, $course_id
        ));
        
        if (!$enrollment) {
            return array('eligible' => false, 'reason' => 'Student is not enrolled in this course');
        }
        
        // Balance — use the same live calculation as Student Details fee panel
        $balance = $this->get_live_balance($student_id, $course_id);

        // Check balance
        if ($balance > 0) {
            return array(
                'eligible' => false, 
                'reason' => 'Outstanding fee balance of KES ' . number_format($balance, 2) . '. All fees must be paid before certificate can be issued.'
            );
        }
        
        // --- Check completion via EITHER unit_results OR exam_results ---
        
        // Method 1: Check unit_results (new unit-based system)
        $total_units = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
            $course_id
        ));
        
        $completed_units = 0;
        $units_complete = false;
        
        if ($total_units > 0) {
            $completed_units = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT ur.unit_id) FROM {$unit_results_table} ur
                 INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
                 WHERE ur.student_id = %d AND cu.course_id = %d",
                $student_id, $course_id
            ));
            $units_complete = ($completed_units >= $total_units);
        }
        
        // Method 2: Check exam_results (legacy HTML exam system)
        $students_table = $this->db->get_table_name('students');
        $admission_number = $wpdb->get_var($wpdb->prepare(
            "SELECT admission_number FROM {$students_table} WHERE student_id = %d",
            $student_id
        ));
        
        $exam_results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mtti_exam_results WHERE admission_number = %s",
            $admission_number
        ));
        
        $exams_complete = false;
        if (count($exam_results) > 0) {
            $failed = 0;
            $total_pct = 0;
            foreach ($exam_results as $r) {
                $total_pct += $r->percentage;
                if ($r->percentage < 50) $failed++;
            }
            $avg = round($total_pct / count($exam_results), 1);
            $exams_complete = ($failed == 0 && $avg >= 50);
        }
        
        // Eligible if EITHER system confirms completion
        if (!$units_complete && !$exams_complete) {
            if ($total_units > 0 && count($exam_results) > 0) {
                // Both systems have data but neither passes
                return array(
                    'eligible' => false,
                    'reason' => 'Student has not passed all assessments. Unit results: ' . $completed_units . '/' . $total_units . ' units completed. Exam results: ' . count($exam_results) . ' exams with ' . ($failed ?? 0) . ' failed.'
                );
            } elseif ($total_units > 0) {
                return array(
                    'eligible' => false, 
                    'reason' => 'Student has only completed ' . $completed_units . ' of ' . $total_units . ' units. All course units must be completed before certificate can be issued.'
                );
            } elseif (count($exam_results) > 0) {
                return array(
                    'eligible' => false,
                    'reason' => 'Student has not passed all exams. Please ensure all exams are passed with 50%+ average.'
                );
            } else {
                return array(
                    'eligible' => false, 
                    'reason' => 'No assessment results found. Student needs either unit results or exam results before certificate can be issued.'
                );
            }
        }
        
        return array('eligible' => true, 'reason' => 'Eligible for certificate');
    }
    
    private function display_certificate_form() {
        global $wpdb;
        
        $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
        $student = $this->db->get_student($student_id);
        
        if (!$student) {
            wp_die('Invalid student ID');
        }
        
        // Get exam results for this student (legacy HTML exams)
        $exam_results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mtti_exam_results WHERE admission_number = %s",
            $student->admission_number
        ));
        
        // Calculate exam_results eligibility
        $total_pct = 0;
        $passed_count = 0;
        $failed_count = 0;
        foreach ($exam_results as $r) {
            $total_pct += $r->percentage;
            if ($r->percentage >= 50) $passed_count++; else $failed_count++;
        }
        $avg_score = count($exam_results) > 0 ? round($total_pct / count($exam_results), 1) : 0;
        $avg_grade = $avg_score >= 80 ? 'A' : ($avg_score >= 70 ? 'B+' : ($avg_score >= 60 ? 'B' : ($avg_score >= 50 ? 'C' : 'D')));
        
        $has_exam_results = count($exam_results) > 0;
        $all_exams_passed = ($failed_count == 0 && $has_exam_results);
        $avg_above_50 = ($avg_score >= 50);
        $exams_eligible = $all_exams_passed && $avg_above_50;
        
        // Check unit_results eligibility (unit-based system)
        $units_table = $this->db->get_table_name('course_units');
        $unit_results_table = $this->db->get_table_name('unit_results');
        $total_units = 0;
        $completed_units = 0;
        $units_eligible = false;
        $unit_avg_score = 0;
        $unit_avg_grade = 'D';
        
        if ($student->course_id) {
            $total_units = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
                $student->course_id
            ));
            if ($total_units > 0) {
                $completed_units = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT ur.unit_id) FROM {$unit_results_table} ur
                     INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
                     WHERE ur.student_id = %d AND cu.course_id = %d",
                    $student->student_id, $student->course_id
                ));
                $unit_avg_score = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT AVG(ur.score) FROM {$unit_results_table} ur
                     INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
                     WHERE ur.student_id = %d AND cu.course_id = %d",
                    $student->student_id, $student->course_id
                ));
                $unit_avg_score = round($unit_avg_score, 1);
                $unit_avg_grade = $unit_avg_score >= 80 ? 'A' : ($unit_avg_score >= 70 ? 'B+' : ($unit_avg_score >= 60 ? 'B' : ($unit_avg_score >= 50 ? 'C' : 'D')));
                $units_eligible = ($completed_units >= $total_units);
            }
        }
        
        // Balance for THIS specific course — same calculation as Student Details fee panel
        $balance = $this->get_live_balance($student->student_id, $student->course_id);
        $fees_cleared = ($balance <= 0);
        
        // Use whichever system confirms eligibility
        $has_results = $has_exam_results || ($completed_units > 0);
        $eligible = ($exams_eligible || $units_eligible) && $fees_cleared;
        
        // Pick the best avg/grade for the form (prefer whichever system is complete)
        if ($units_eligible) {
            $display_avg = $unit_avg_score;
            $display_grade = $unit_avg_grade;
        } else {
            $display_avg = $avg_score;
            $display_grade = $avg_grade;
        }
        
        // Get course
        $course = null;
        if ($student->course_id) {
            $courses_table = $this->db->get_table_name('courses');
            $course = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$courses_table} WHERE course_id = %d",
                $student->course_id
            ));
        }
        ?>
        <div class="wrap">
            <h1>Generate Certificate</h1>
            <p><a href="<?php echo admin_url('admin.php?page=mtti-mis-certificates'); ?>" class="button">← Back to Certificates</a></p>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2>Student: <?php echo esc_html($student->display_name); ?> (<?php echo esc_html($student->admission_number); ?>)</h2>
                
                <!-- Eligibility Check -->
                <h3>Certificate Eligibility</h3>
                <table class="widefat" style="margin-bottom: 20px;">
                    <?php if ($has_exam_results): ?>
                    <tr>
                        <td><strong>Exam Results (HTML Exams)</strong></td>
                        <td><?php echo '<span style="color:#00b894;">✓ ' . count($exam_results) . ' exams completed</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>All Exams Passed (50%+)</strong></td>
                        <td><?php echo $all_exams_passed ? '<span style="color:#00b894;">✓ ' . $passed_count . '/' . count($exam_results) . ' passed</span>' : '<span style="color:#d63031;">✗ ' . $failed_count . ' exam(s) failed</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Exam Average</strong></td>
                        <td><?php echo $avg_above_50 ? '<span style="color:#00b894;">✓ ' . $avg_score . '% (' . $avg_grade . ')</span>' : '<span style="color:#d63031;">✗ ' . $avg_score . '% (below 50%)</span>'; ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($total_units > 0): ?>
                    <tr>
                        <td><strong>Unit Results</strong></td>
                        <td><?php echo $units_eligible ? '<span style="color:#00b894;">✓ ' . $completed_units . '/' . $total_units . ' units completed</span>' : '<span style="color:#d63031;">✗ ' . $completed_units . '/' . $total_units . ' units completed</span>'; ?></td>
                    </tr>
                    <?php if ($completed_units > 0): ?>
                    <tr>
                        <td><strong>Unit Average</strong></td>
                        <td><?php echo $unit_avg_score >= 50 ? '<span style="color:#00b894;">✓ ' . $unit_avg_score . '% (' . $unit_avg_grade . ')</span>' : '<span style="color:#d63031;">✗ ' . $unit_avg_score . '% (below 50%)</span>'; ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if (!$has_exam_results && $total_units == 0): ?>
                    <tr>
                        <td><strong>Assessment Results</strong></td>
                        <td><span style="color:#d63031;">✗ No exam results or unit results found</span></td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <td><strong>Fees Cleared</strong></td>
                        <td><?php echo $fees_cleared ? '<span style="color:#00b894;">✓ Fully paid</span>' : '<span style="color:#d63031;">✗ Balance: KES ' . number_format($balance, 2) . '</span>'; ?></td>
                    </tr>
                    <tr style="background: <?php echo $eligible ? '#d4edda' : '#f8d7da'; ?>;">
                        <td><strong>Status</strong></td>
                        <td><strong><?php echo $eligible ? '✓ ELIGIBLE FOR CERTIFICATE' : '✗ NOT ELIGIBLE'; ?></strong></td>
                    </tr>
                </table>
                
                <?php if (!$eligible): ?>
                <div class="notice notice-error" style="margin: 0 0 20px 0;">
                    <p><strong>Cannot Generate Certificate:</strong> Student does not meet all requirements.</p>
                </div>
                <?php elseif (!$course): ?>
                <div class="notice notice-error" style="margin: 0 0 20px 0;">
                    <p><strong>Cannot Generate Certificate:</strong> Student has no course assigned.</p>
                </div>
                <?php else: ?>
                
                <h3>Generate Certificate</h3>
                <form method="post">
                    <?php wp_nonce_field('generate_certificate', 'certificate_nonce'); ?>
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="course_id" value="<?php echo $course->course_id; ?>">
                    
                    <table class="form-table">
                        <tr>
                            <th>Course:</th>
                            <td><strong><?php echo esc_html($course->course_name); ?></strong> (<?php echo esc_html($course->course_code); ?>)</td>
                        </tr>
                        <tr>
                            <th><label for="grade">Grade *</label></th>
                            <td>
                                <input type="text" name="grade" id="grade" class="regular-text" value="<?php echo esc_attr($display_grade); ?>" required>
                                <p class="description">Auto-calculated: <?php echo $display_grade; ?> (<?php echo $display_avg; ?>%)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="completion_date">Completion Date *</label></th>
                            <td>
                                <input type="date" name="completion_date" id="completion_date" class="regular-text" value="<?php echo date('Y-m-d'); ?>" required>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="certificate_submit" class="button button-primary button-large" value="🎓 Generate Certificate">
                    </p>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    private function create_certificate_pdf($student, $course, $grade, $completion_date) {
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        $cert_number = $this->generate_certificate_number();
        $verification_code = $this->generate_verification_code();
        
        // Save certificate to database for verification
        $saved = $this->save_certificate_to_database(
            $student, 
            $course, 
            $cert_number, 
            $verification_code, 
            $grade, 
            $completion_date
        );
        
        if (!$saved) {
            error_log('MTTI MIS: Failed to save certificate to database for student ' . $student->student_id);
        }
        
        // Generate verification URL - points to verify-certificate page with shortcode
        // Create URL: /verify-certificate/?code=CERT_NUMBER
        $verify_url = home_url('/verify-certificate/?code=' . urlencode($cert_number));
        
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Completion - <?php echo esc_html($student->display_name); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        @page { 
            size: A4 landscape; 
            margin: 0; 
        }
        
        @media print {
            /* Hide ALL browser headers and footers */
            @page {
                margin: 0;
                size: A4 landscape;
            }
            
            html, body { 
                margin: 0 !important; 
                padding: 0 !important;
                width: 297mm !important;
                height: 210mm !important;
                overflow: hidden !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print { display: none !important; }
            .print-instructions { display: none !important; }
            .certificate-container {
                width: 297mm !important;
                height: 210mm !important;
                padding: 5mm !important;
                margin: 0 !important;
                box-shadow: none !important;
            }
        }
        
        body { 
            font-family: 'Georgia', 'Times New Roman', serif; 
            margin: 0; 
            padding: 10px;
            background: #f5f5f5;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .print-instructions {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px 20px;
            margin: 0 auto 15px auto;
            max-width: 297mm;
            text-align: center;
            font-family: Arial, sans-serif;
        }
        .print-instructions strong {
            color: #856404;
        }
        
        .certificate-container {
            width: 297mm;
            height: 210mm;
            max-width: 100%;
            margin: 0 auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 5mm;
        }
        
        .certificate {
            width: 100%;
            height: 100%;
            border: 12px solid #2E7D32;
            padding: 8px;
            text-align: center;
            background: white;
        }
        
        .inner-border {
            border: 2px solid #FF9800;
            height: 100%;
            padding: 20px 30px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .logo { 
            width: 70px; 
            height: auto;
            margin-bottom: 10px; 
        }
        
        h1 { 
            font-size: 28px; 
            color: #2E7D32; 
            margin: 5px 0; 
            letter-spacing: 1px;
        }
        
        h2 { 
            font-size: 14px; 
            color: #666; 
            margin: 5px 0 15px 0; 
            font-weight: normal;
        }
        
        .cert-title {
            font-size: 22px;
            color: #2E7D32;
            margin: 10px 0;
            letter-spacing: 3px;
            font-weight: bold;
        }
        
        .certify-text {
            font-size: 14px;
            margin: 15px 0 8px 0;
            color: #333;
        }
        
        .student-name {
            font-size: 36px;
            font-weight: bold;
            color: #1976D2;
            margin: 10px 0;
            border-bottom: 2px solid #FF9800;
            padding-bottom: 5px;
            display: inline-block;
        }
        
        .admission-number {
            font-size: 12px;
            color: #666;
            margin: 5px 0 15px 0;
        }
        
        .completion-text {
            font-size: 14px;
            margin: 10px 0;
            color: #333;
        }
        
        .course-name {
            font-size: 24px;
            font-weight: bold;
            color: #2E7D32;
            margin: 8px 0;
        }
        
        .course-code {
            font-size: 14px;
            color: #666;
            margin: 5px 0 15px 0;
        }
        
        .details {
            margin: 15px 0;
            font-size: 14px;
            line-height: 1.8;
        }
        
        .details p {
            margin: 5px 0;
        }
        
        .grade-highlight {
            font-size: 16px;
            color: #FF9800;
            font-weight: bold;
        }
        
        .signatures {
            display: flex;
            justify-content: center;
            gap: 100px;
            margin: 25px 0 15px 0;
            width: 100%;
        }
        
        .signature {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            width: 150px;
            margin-bottom: 5px;
        }
        
        .signature-title {
            font-size: 12px;
            color: #666;
        }
        
        .motto { 
            color: #FF9800; 
            font-style: italic; 
            margin: 15px 0;
            font-size: 14px;
            font-weight: bold;
        }
        
        .cert-info-footer {
            display: flex;
            justify-content: space-between;
            width: 100%;
            padding: 10px 20px;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            margin-top: 10px;
        }
        
        .cert-number {
            text-align: left;
        }
        
        .date-issued {
            text-align: right;
        }
        
        .print-button {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background: #2E7D32;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            z-index: 1000;
        }
        .print-button:hover {
            background: #1B5E20;
        }
    </style>
    <script>
        function printCertificate() {
            alert('IMPORTANT PRINT SETTINGS:\\n\\n1. Layout/Orientation: LANDSCAPE\\n2. Margins: None or Minimum\\n3. UNCHECK "Headers and footers" to remove URL\\n   (In Chrome: More settings → uncheck "Headers and footers")\\n\\nThen click Print.');
            window.print();
        }
    </script>
</head>
<body>
    <button class="print-button no-print" onclick="printCertificate()">🖨️ Print Certificate</button>
    
    <div class="print-instructions no-print">
        <strong>⚠️ IMPORTANT PRINT SETTINGS:</strong><br>
        1. Select <strong>LANDSCAPE</strong> orientation<br>
        2. Set margins to <strong>None</strong> or <strong>Minimum</strong><br>
        3. <strong>Uncheck "Headers and footers"</strong> to remove the URL from printing<br>
        <em>(In Chrome: More settings → uncheck "Headers and footers")</em>
    </div>
    
    <div class="certificate-container">
        <div class="certificate">
            <div class="inner-border">
                <img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" class="logo">
                
                <h1>Masomotele Technical Training Institute</h1>
                <h2>Sagaas Center, Eldoret, Kenya</h2>
                
                <p class="cert-title">CERTIFICATE OF COMPLETION</p>
                
                <p class="certify-text">This is to certify that</p>
                
                <div class="student-name"><?php echo esc_html($student->display_name); ?></div>
                <p class="admission-number">Admission Number: <?php echo esc_html($student->admission_number); ?></p>
                
                <p class="completion-text">has successfully completed the course</p>
                
                <div class="course-name"><?php echo esc_html($course->course_name); ?></div>
                <p class="course-code">(<?php echo esc_html($course->course_code); ?>)</p>
                
                <div class="details">
                    <p><strong>Grade Achieved:</strong> <span class="grade-highlight"><?php echo esc_html($grade); ?></span> &nbsp;|&nbsp; <strong>Date of Completion:</strong> <span style="color: #FF9800;"><?php echo date('F j, Y', strtotime($completion_date)); ?></span></p>
                </div>
                
                <div class="signatures">
                    <div class="signature">
                        <div class="signature-line"></div>
                        <p class="signature-title">Director</p>
                    </div>
                    <div class="signature">
                        <div class="signature-line"></div>
                        <p class="signature-title">Registrar</p>
                    </div>
                </div>
                
                <p class="motto">"Start Learning, Start Earning"</p>
                
                <div class="cert-info-footer">
                    <div class="cert-number">
                        <strong>Certificate No:</strong> <?php echo esc_html($cert_number); ?>
                    </div>
                    <div class="date-issued">
                        <strong>Date Issued:</strong> <?php echo date('F j, Y'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html><?php
        exit;
    }
    
    private function generate_certificate_number() {
        $year = date('Y');
        $random = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        return 'MTTI/CERT/' . $year . '/' . $random;
    }
    
    /**
     * Generate unique verification code
     * Format: XXXX-XXXX-XXXX (12 characters in 3 groups)
     */
    private function generate_verification_code() {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed ambiguous chars (0,O,I,1)
        $code = '';
        
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                $code .= '-';
            }
            for ($j = 0; $j < 4; $j++) {
                $code .= $chars[mt_rand(0, strlen($chars) - 1)];
            }
        }
        
        return $code;
    }
    
    /**
     * Save certificate to database for verification
     */
    private function save_certificate_to_database($student, $course, $cert_number, $verification_code, $grade, $completion_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_certificates';
        
        $certificate_data = array(
            'certificate_number' => $cert_number,
            'verification_code' => $verification_code,
            'student_id' => $student->student_id,
            'student_name' => $student->display_name,
            'admission_number' => $student->admission_number,
            'course_id' => $course->course_id,
            'course_name' => $course->course_name,
            'course_code' => $course->course_code,
            'grade' => $grade,
            'completion_date' => $completion_date,
            'issue_date' => current_time('mysql'),
            'status' => 'Valid',
            'created_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($table, $certificate_data);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
}
