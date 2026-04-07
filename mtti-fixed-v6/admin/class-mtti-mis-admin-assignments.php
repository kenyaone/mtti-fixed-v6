<?php
/**
 * Assignments Admin Class - Complete Implementation
 */
class MTTI_MIS_Admin_Assignments {
    
    private $plugin_name;
    private $version;
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->db = MTTI_MIS_Database::get_instance();
        
        // Handle file uploads
        add_action('admin_init', array($this, 'handle_file_upload'));
    }
    
    public function display() {
        // Handle form submissions
        if (isset($_POST['mtti_assignment_submit'])) {
            check_admin_referer('mtti_assignment_action', 'mtti_assignment_nonce');
            $this->handle_form_submission();
            return;
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $assignment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        switch ($action) {
            case 'add':
                $this->display_add_form();
                break;
            case 'edit':
                $this->display_edit_form($assignment_id);
                break;
            case 'view':
                $this->display_assignment_details($assignment_id);
                break;
            case 'submissions':
                $this->display_submissions($assignment_id);
                break;
            case 'grade':
                $this->display_grade_form();
                break;
            default:
                $this->display_list();
        }
    }
    
    private function display_list() {
        $assignments = $this->db->get_assignments();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Assignments</h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=add'); ?>" class="page-title-action">Create Assignment</a>
            <hr class="wp-header-end">
            
            <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php 
                    switch ($_GET['message']) {
                        case 'created': echo 'Assignment created successfully!'; break;
                        case 'updated': echo 'Assignment updated successfully!'; break;
                        case 'graded': echo 'Submission graded successfully!'; break;
                    }
                    ?>
                </p>
            </div>
            <?php endif; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Course</th>
                        <th>Due Date</th>
                        <th>Max Score</th>
                        <th>Submissions</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assignments) : foreach ($assignments as $assignment) : 
                        $submissions_count = $this->count_submissions($assignment->assignment_id);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($assignment->title); ?></strong></td>
                        <td><?php echo esc_html($assignment->course_name); ?></td>
                        <td><?php echo date('M j, Y', strtotime($assignment->due_date)); ?></td>
                        <td><?php echo esc_html($assignment->max_score); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=submissions&id=' . $assignment->assignment_id); ?>">
                                <?php echo $submissions_count; ?> submission(s)
                            </a>
                        </td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($assignment->status); ?>">
                            <?php echo esc_html($assignment->status); ?>
                        </span></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=view&id=' . $assignment->assignment_id); ?>">View</a> |
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=submissions&id=' . $assignment->assignment_id); ?>">Submissions</a>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="7">No assignments found. <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=add'); ?>">Create first assignment</a></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_add_form() {
        $courses = $this->db->get_courses(array('status' => 'Active'));
        $staff = $this->get_staff();
        $current_user = wp_get_current_user();
        ?>
        <div class="wrap">
            <h1>Create Assignment</h1>
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('mtti_assignment_action', 'mtti_assignment_nonce'); ?>
                <input type="hidden" name="action" value="add">
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="course_id">Course *</label></th>
                        <td>
                            <select name="course_id" id="course_id" class="regular-text" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course) : ?>
                                <option value="<?php echo $course->course_id; ?>">
                                    <?php echo esc_html($course->course_name . ' (' . $course->course_code . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="title">Assignment Title *</label></th>
                        <td>
                            <input type="text" name="title" id="title" class="regular-text" required>
                            <p class="description">e.g., "Create a Personal Portfolio Website"</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="description">Description *</label></th>
                        <td>
                            <?php 
                            wp_editor('', 'description', array(
                                'textarea_name' => 'description',
                                'textarea_rows' => 8,
                                'media_buttons' => false,
                                'teeny' => true
                            )); 
                            ?>
                            <p class="description">Provide detailed instructions, requirements, and grading criteria</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="assignment_file">Attachment</label></th>
                        <td>
                            <input type="file" name="assignment_file" id="assignment_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip">
                            <p class="description">Optional: Upload assignment file (PDF, DOC, PPT, ZIP - Max 10MB)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="due_date">Due Date & Time *</label></th>
                        <td>
                            <input type="datetime-local" name="due_date" id="due_date" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_score">Maximum Score *</label></th>
                        <td>
                            <input type="number" name="max_score" id="max_score" class="regular-text" value="100" step="0.01" required>
                            <p class="description">Total points for this assignment</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status *</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text" required>
                                <option value="Active">Active (students can submit)</option>
                                <option value="Inactive">Inactive (closed for submission)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_assignment_submit" class="button button-primary" value="Create Assignment">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function display_assignment_details($assignment_id) {
        global $wpdb;
        $assignments_table = $this->db->get_table_name('assignments');
        
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, c.course_name, c.course_code, u.display_name as staff_name
             FROM {$assignments_table} a
             LEFT JOIN {$wpdb->prefix}mtti_courses c ON a.course_id = c.course_id
             LEFT JOIN {$wpdb->prefix}mtti_staff s ON a.staff_id = s.staff_id
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE a.assignment_id = %d",
            $assignment_id
        ));
        
        if (!$assignment) {
            wp_die('Assignment not found');
        }
        
        $submissions_count = $this->count_submissions($assignment_id);
        $graded_count = $this->count_graded_submissions($assignment_id);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($assignment->title); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=submissions&id=' . $assignment_id); ?>" class="page-title-action">View Submissions (<?php echo $submissions_count; ?>)</a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments'); ?>" class="page-title-action">Back to List</a>
            
            <div class="mtti-student-details">
                <h2>Assignment Details</h2>
                <table class="form-table">
                    <tr>
                        <th>Course:</th>
                        <td><strong><?php echo esc_html($assignment->course_name . ' (' . $assignment->course_code . ')'); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Created By:</th>
                        <td><?php echo esc_html($assignment->staff_name ?: 'System'); ?></td>
                    </tr>
                    <tr>
                        <th>Due Date:</th>
                        <td><strong><?php echo date('F j, Y g:i A', strtotime($assignment->due_date)); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Maximum Score:</th>
                        <td><?php echo esc_html($assignment->max_score); ?> points</td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($assignment->status); ?>">
                            <?php echo esc_html($assignment->status); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <th>Submissions:</th>
                        <td><?php echo $submissions_count; ?> total, <?php echo $graded_count; ?> graded</td>
                    </tr>
                    <?php if ($assignment->file_name) : ?>
                    <tr>
                        <th>Attachment:</th>
                        <td>
                            <a href="<?php echo wp_upload_dir()['baseurl'] . '/mtti-mis/' . $assignment->file_path; ?>" target="_blank">
                                <span class="dashicons dashicons-download"></span> <?php echo esc_html($assignment->file_name); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <h2>Description</h2>
                <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    <?php echo wpautop($assignment->description); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function display_submissions($assignment_id) {
        $submissions = $this->db->get_submissions($assignment_id);
        $assignment = $this->get_assignment($assignment_id);
        ?>
        <div class="wrap">
            <h1>Submissions: <?php echo esc_html($assignment->title); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=view&id=' . $assignment_id); ?>" class="page-title-action">Assignment Details</a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments'); ?>" class="page-title-action">Back to List</a>
            <hr class="wp-header-end">
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Submitted</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($submissions) : foreach ($submissions as $submission) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($submission->admission_number); ?></strong></td>
                        <td>
                            <?php echo date('M j, Y g:i A', strtotime($submission->submitted_at)); ?>
                            <?php if (strtotime($submission->submitted_at) > strtotime($assignment->due_date)) : ?>
                            <span style="color: #dc3232;"> (LATE)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($submission->score !== null) : ?>
                                <strong><?php echo esc_html($submission->score); ?> / <?php echo esc_html($assignment->max_score); ?></strong>
                            <?php else : ?>
                                Not graded
                            <?php endif; ?>
                        </td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($submission->status); ?>">
                            <?php echo esc_html($submission->status); ?>
                        </span></td>
                        <td>
                            <?php if ($submission->file_name) : ?>
                            <a href="<?php echo wp_upload_dir()['baseurl'] . '/mtti-mis/' . $submission->file_path; ?>" target="_blank" class="button button-small">
                                <span class="dashicons dashicons-download"></span> Download
                            </a>
                            <?php endif; ?>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=grade&submission_id=' . $submission->submission_id); ?>" class="button button-small button-primary">
                                <?php echo $submission->score !== null ? 'Update Grade' : 'Grade'; ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="5">No submissions yet.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_grade_form() {
        $submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
        $submission = $this->get_submission($submission_id);
        
        if (!$submission) {
            wp_die('Submission not found');
        }
        
        $assignment = $this->get_assignment($submission->assignment_id);
        
        // Handle grade submission
        if (isset($_POST['mtti_grade_submit'])) {
            check_admin_referer('mtti_grade_action', 'mtti_grade_nonce');
            
            $data = array(
                'score' => floatval($_POST['score']),
                'feedback' => sanitize_textarea_field($_POST['feedback']),
                'graded_at' => current_time('mysql'),
                'graded_by' => get_current_user_id(),
                'status' => 'Graded'
            );
            
            $this->db->update_submission($submission_id, $data);
            
            wp_redirect(admin_url('admin.php?page=mtti-mis-assignments&action=submissions&id=' . $submission->assignment_id . '&message=graded'));
            exit;
        }
        ?>
        <div class="wrap">
            <h1>Grade Submission</h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=submissions&id=' . $submission->assignment_id); ?>" class="page-title-action">Back to Submissions</a>
            
            <div class="mtti-form-section">
                <h2>Assignment: <?php echo esc_html($assignment->title); ?></h2>
                <p><strong>Student:</strong> <?php echo esc_html($submission->admission_number); ?></p>
                <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($submission->submitted_at)); ?></p>
                
                <?php if ($submission->submission_text) : ?>
                <h3>Submission Text:</h3>
                <div style="background: white; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 20px;">
                    <?php echo wpautop(esc_html($submission->submission_text)); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($submission->file_name) : ?>
                <p>
                    <strong>Submitted File:</strong>
                    <a href="<?php echo wp_upload_dir()['baseurl'] . '/mtti-mis/' . $submission->file_path; ?>" target="_blank" class="button">
                        <span class="dashicons dashicons-download"></span> <?php echo esc_html($submission->file_name); ?>
                    </a>
                </p>
                <?php endif; ?>
                
                <form method="post" action="">
                    <?php wp_nonce_field('mtti_grade_action', 'mtti_grade_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="score">Score * (out of <?php echo esc_html($assignment->max_score); ?>)</label></th>
                            <td>
                                <input type="number" name="score" id="score" class="regular-text" 
                                       min="0" max="<?php echo esc_attr($assignment->max_score); ?>" 
                                       step="0.01" value="<?php echo esc_attr($submission->score); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="feedback">Feedback</label></th>
                            <td>
                                <textarea name="feedback" id="feedback" rows="10" class="large-text"><?php echo esc_textarea($submission->feedback); ?></textarea>
                                <p class="description">Provide detailed feedback to help the student improve</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="mtti_grade_submit" class="button button-primary" value="Save Grade">
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-assignments&action=submissions&id=' . $submission->assignment_id); ?>" class="button">Cancel</a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    public function handle_file_upload() {
        if (!isset($_FILES['assignment_file'])) {
            return;
        }
        
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $upload_overrides = array(
            'test_form' => false,
            'mimes' => array(
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'zip' => 'application/zip'
            )
        );
        
        $movefile = wp_handle_upload($_FILES['assignment_file'], $upload_overrides);
        
        if ($movefile && !isset($movefile['error'])) {
            // File uploaded successfully
            $_POST['file_path'] = str_replace(wp_upload_dir()['basedir'] . '/', '', $movefile['file']);
            $_POST['file_name'] = basename($movefile['file']);
        }
    }
    
    private function handle_form_submission() {
        $action = $_POST['action'];
        
        if ($action == 'add') {
            $data = array(
                'course_id' => intval($_POST['course_id']),
                'staff_id' => get_current_user_id(), // Current teacher
                'title' => sanitize_text_field($_POST['title']),
                'description' => wp_kses_post($_POST['description']),
                'file_path' => isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : null,
                'file_name' => isset($_POST['file_name']) ? sanitize_text_field($_POST['file_name']) : null,
                'due_date' => sanitize_text_field($_POST['due_date']),
                'max_score' => floatval($_POST['max_score']),
                'status' => sanitize_text_field($_POST['status'])
            );
            
            $assignment_id = $this->db->create_assignment($data);
            
            wp_redirect(admin_url('admin.php?page=mtti-mis-assignments&action=view&id=' . $assignment_id . '&message=created'));
            exit;
        }
    }
    
    // Helper functions
    private function count_submissions($assignment_id) {
        global $wpdb;
        $table = $this->db->get_table_name('assignment_submissions');
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE assignment_id = %d",
            $assignment_id
        ));
    }
    
    private function count_graded_submissions($assignment_id) {
        global $wpdb;
        $table = $this->db->get_table_name('assignment_submissions');
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE assignment_id = %d AND status = 'Graded'",
            $assignment_id
        ));
    }
    
    private function get_assignment($assignment_id) {
        global $wpdb;
        $table = $this->db->get_table_name('assignments');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE assignment_id = %d",
            $assignment_id
        ));
    }
    
    private function get_submission($submission_id) {
        global $wpdb;
        $table = $this->db->get_table_name('assignment_submissions');
        $students_table = $this->db->get_table_name('students');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT sub.*, s.admission_number
             FROM {$table} sub
             LEFT JOIN {$students_table} s ON sub.student_id = s.student_id
             WHERE sub.submission_id = %d",
            $submission_id
        ));
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
