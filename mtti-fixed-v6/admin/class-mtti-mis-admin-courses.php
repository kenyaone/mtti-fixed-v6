<?php
/**
 * Courses Admin Class - Simplified
 */
class MTTI_MIS_Admin_Courses {
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        // Start output buffering to prevent header warnings
        if (!headers_sent()) {
            ob_start();
        }
        
        if (isset($_POST['mtti_course_submit'])) {
            check_admin_referer('mtti_course_action', 'mtti_course_nonce');
            // Clean output buffer before redirect
            if (ob_get_length()) {
                ob_end_clean();
            }
            $this->handle_form_submission();
            return;
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $course_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($action == 'add') {
            $this->display_form();
        } elseif ($action == 'edit' && $course_id) {
            $this->display_edit_form($course_id);
        } elseif ($action == 'units' && $course_id) {
            $this->display_units_management($course_id);
        } elseif ($action == 'add-unit' && $course_id) {
            $this->display_add_unit_form($course_id);
        } elseif ($action == 'edit-unit') {
            $this->display_edit_unit_form(intval($_GET['unit_id']));
        } else {
            $this->display_list();
        }
    }
    
    private function display_list() {
        // Get search parameter
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Get courses with optional search filter
        $args = array();
        if (!empty($search)) {
            $args['search'] = $search;
        }
        
        $courses = $this->db->get_courses($args);
        ?>
        <div class="wrap">
            <h1>Courses <a href="?page=mtti-mis-courses&action=add" class="page-title-action">Add New</a></h1>
            
            <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php 
                    if ($_GET['message'] == 'created') {
                        echo 'Course created successfully!';
                    } elseif ($_GET['message'] == 'updated') {
                        echo 'Course updated successfully!';
                    }
                    ?>
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Search Form -->
            <form method="get" class="search-form" style="margin: 20px 0;">
                <input type="hidden" name="page" value="mtti-mis-courses">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="Search by code, name, or category..." 
                           class="regular-text" style="width: 350px;">
                    <input type="submit" value="Search Courses" class="button">
                    <?php if (!empty($search)) : ?>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses'); ?>" class="button">Clear Search</a>
                    <?php endif; ?>
                </p>
            </form>
            
            <?php if (!empty($search)) : ?>
            <div class="notice notice-info">
                <p><strong>Search results for:</strong> "<?php echo esc_html($search); ?>" 
                   (<?php echo count($courses); ?> course<?php echo count($courses) != 1 ? 's' : ''; ?> found)</p>
            </div>
            <?php endif; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Duration</th>
                        <th>Fee</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($course->course_code); ?></strong></td>
                        <td><?php echo esc_html($course->course_name); ?></td>
                        <td><?php echo esc_html($course->category); ?></td>
                        <td><?php echo $course->duration_weeks; ?> weeks</td>
                        <td>KES <?php echo number_format($course->fee, 2); ?></td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($course->status); ?>">
                            <?php echo $course->status; ?>
                        </span></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=edit&id=' . $course->course_id); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $course->course_id); ?>" class="button button-small">
                                <span class="dashicons dashicons-list-view" style="vertical-align: middle;"></span> Manage Units
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_form() {
        ?>
        <div class="wrap">
            <h1>Add New Course</h1>
            <form method="post">
                <?php wp_nonce_field('mtti_course_action', 'mtti_course_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>Course Name *</label></th>
                        <td>
                            <input type="text" name="course_name" id="course-name" required class="regular-text">
                            <p class="description">Course code will be auto-generated based on course name</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Course Code *</label></th>
                        <td>
                            <input type="text" name="course_code" id="course-code" required class="regular-text" readonly style="background-color: #f0f0f0;">
                            <button type="button" id="edit-code-btn" class="button" style="margin-left: 10px;">Edit Code</button>
                            <p class="description">Auto-generated from course name. Click "Edit Code" to customize.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Category *</label></th>
                        <td>
                            <select name="category" required class="regular-text">
                                <option value="Computer Applications">Computer Applications</option>
                                <option value="Web Development">Web Development</option>
                                <option value="Graphic Design">Graphic Design</option>
                                <option value="Digital Marketing">Digital Marketing</option>
                                <option value="Other">Other</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Duration (weeks) *</label></th>
                        <td><input type="number" name="duration_weeks" required class="regular-text" value="8"></td>
                    </tr>
                    <tr>
                        <th><label>Fee (KES) *</label></th>
                        <td><input type="number" name="fee" required class="regular-text" step="0.01"></td>
                    </tr>
                    <tr>
                        <th><label>Description</label></th>
                        <td><textarea name="description" rows="4" class="large-text"></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="mtti_course_submit" class="button button-primary" value="Add Course">
                    <a href="?page=mtti-mis-courses" class="button">Cancel</a>
                </p>
            </form>
            
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var codeEditable = false;
                
                // Auto-generate course code from course name
                $('#course-name').on('input', function() {
                    if (!codeEditable) {
                        var courseName = $(this).val().trim();
                        var courseCode = generateCourseCode(courseName);
                        $('#course-code').val(courseCode);
                    }
                });
                
                // Allow manual editing of course code
                $('#edit-code-btn').on('click', function() {
                    codeEditable = !codeEditable;
                    if (codeEditable) {
                        $('#course-code').prop('readonly', false).css('background-color', '#fff');
                        $(this).text('Auto-Generate');
                    } else {
                        $('#course-code').prop('readonly', true).css('background-color', '#f0f0f0');
                        $(this).text('Edit Code');
                        // Re-generate code from current course name
                        var courseName = $('#course-name').val().trim();
                        var courseCode = generateCourseCode(courseName);
                        $('#course-code').val(courseCode);
                    }
                });
                
                function generateCourseCode(courseName) {
                    if (!courseName) return '';
                    
                    // Remove common words and get meaningful words
                    var words = courseName.toUpperCase()
                        .replace(/\b(THE|AND|OR|OF|IN|ON|AT|TO|FOR|WITH)\b/g, '')
                        .trim()
                        .split(/\s+/);
                    
                    var code = '';
                    
                    if (words.length === 1) {
                        // Single word: take first 3 letters
                        code = words[0].substring(0, 3);
                    } else if (words.length === 2) {
                        // Two words: take first 2 from first, 1 from second
                        code = words[0].substring(0, 2) + words[1].substring(0, 1);
                    } else {
                        // Three or more words: take first letter of first 3 words
                        code = words[0].substring(0, 1) + 
                               words[1].substring(0, 1) + 
                               words[2].substring(0, 1);
                    }
                    
                    // Add sequential number (default 01)
                    code += '-01';
                    
                    return code;
                }
            });
            </script>
        </div>
        <?php
    }
    
    private function display_edit_form($course_id) {
        $course = $this->db->get_course($course_id);
        
        if (!$course) {
            wp_die('Course not found');
        }
        ?>
        <div class="wrap">
            <h1>Edit Course</h1>
            <form method="post">
                <?php wp_nonce_field('mtti_course_action', 'mtti_course_nonce'); ?>
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                <input type="hidden" name="action" value="edit">
                
                <table class="form-table">
                    <tr>
                        <th><label>Course Code *</label></th>
                        <td>
                            <input type="text" name="course_code" required class="regular-text" 
                                   value="<?php echo esc_attr($course->course_code); ?>">
                            <p class="description">Course code can be edited if needed</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Course Name *</label></th>
                        <td>
                            <input type="text" name="course_name" required class="regular-text"
                                   value="<?php echo esc_attr($course->course_name); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label>Category *</label></th>
                        <td>
                            <select name="category" required class="regular-text">
                                <option value="Computer Applications" <?php selected($course->category, 'Computer Applications'); ?>>Computer Applications</option>
                                <option value="Web Development" <?php selected($course->category, 'Web Development'); ?>>Web Development</option>
                                <option value="Graphic Design" <?php selected($course->category, 'Graphic Design'); ?>>Graphic Design</option>
                                <option value="Digital Marketing" <?php selected($course->category, 'Digital Marketing'); ?>>Digital Marketing</option>
                                <option value="Other" <?php selected($course->category, 'Other'); ?>>Other</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Duration (weeks) *</label></th>
                        <td>
                            <input type="number" name="duration_weeks" required class="regular-text" 
                                   value="<?php echo esc_attr($course->duration_weeks); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label>Fee (KES) *</label></th>
                        <td>
                            <input type="number" name="fee" required class="regular-text" step="0.01"
                                   value="<?php echo esc_attr($course->fee); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label>Description</label></th>
                        <td>
                            <textarea name="description" rows="4" class="large-text"><?php echo esc_textarea($course->description); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Status *</label></th>
                        <td>
                            <select name="status" required class="regular-text">
                                <option value="Active" <?php selected($course->status, 'Active'); ?>>Active</option>
                                <option value="Inactive" <?php selected($course->status, 'Inactive'); ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="mtti_course_submit" class="button button-primary" value="Update Course">
                    <a href="?page=mtti-mis-courses" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function handle_form_submission() {
        $action = isset($_POST['action']) ? $_POST['action'] : 'add';
        
        $data = array(
            'course_code' => sanitize_text_field($_POST['course_code']),
            'course_name' => sanitize_text_field($_POST['course_name']),
            'category' => sanitize_text_field($_POST['category']),
            'duration_weeks' => intval($_POST['duration_weeks']),
            'fee' => floatval($_POST['fee']),
            'description' => sanitize_textarea_field($_POST['description'])
        );
        
        if ($action == 'edit') {
            $course_id = intval($_POST['course_id']);
            $data['status'] = sanitize_text_field($_POST['status']);
            $this->db->update_course($course_id, $data);
            $redirect_url = admin_url('admin.php?page=mtti-mis-courses&message=updated');
        } else {
            $data['status'] = 'Active';
            $this->db->create_course($data);
            $redirect_url = admin_url('admin.php?page=mtti-mis-courses&message=created');
        }
        
        // Use JavaScript fallback if headers already sent
        if (!headers_sent()) {
            wp_redirect($redirect_url);
            exit;
        } else {
            echo '<script type="text/javascript">window.location.href="' . esc_url($redirect_url) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($redirect_url) . '"></noscript>';
            exit;
        }
    }
    
    // ===== UNITS MANAGEMENT =====
    
    private function display_units_management($course_id) {
        // Handle unit form submissions
        if (isset($_POST['mtti_unit_submit'])) {
            check_admin_referer('mtti_unit_action', 'mtti_unit_nonce');
            $this->handle_unit_submission();
            return;
        }
        
        if (isset($_GET['delete_unit'])) {
            $this->db->delete_course_unit(intval($_GET['delete_unit']));
            wp_redirect(admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $course_id . '&message=unit-deleted'));
            exit;
        }
        
        $course = $this->db->get_course($course_id);
        $units = $this->db->get_course_units(array('course_id' => $course_id));
        ?>
        <div class="wrap">
            <h1>Manage Units: <?php echo esc_html($course->course_name); ?> (<?php echo esc_html($course->course_code); ?>)</h1>
            <p><a href="<?php echo admin_url('admin.php?page=mtti-mis-courses'); ?>" class="button">← Back to Courses</a></p>
            
            <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php 
                    if ($_GET['message'] == 'unit-created') echo 'Unit created successfully!';
                    elseif ($_GET['message'] == 'unit-updated') echo 'Unit updated successfully!';
                    elseif ($_GET['message'] == 'unit-deleted') echo 'Unit deleted successfully!';
                    ?>
                </p>
            </div>
            <?php endif; ?>
            
            <p style="background: #e7f3ff; padding: 10px; border-left: 4px solid #2196F3; margin: 20px 0;">
                <strong>Note:</strong> Unit codes are automatically generated (e.g., <?php echo esc_html($course->course_code); ?>-001, <?php echo esc_html($course->course_code); ?>-002, etc.)
            </p>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=add-unit&id=' . $course_id); ?>" 
                   class="button button-primary">Add New Unit</a>
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Unit Code</th>
                        <th>Unit Name</th>
                        <th>Order</th>
                        <th>Duration (hrs)</th>
                        <th>Credit Hours</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($units)) : ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px;">
                            No units created yet. <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=add-unit&id=' . $course_id); ?>">Add your first unit</a>
                        </td>
                    </tr>
                    <?php else : ?>
                        <?php foreach ($units as $unit) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($unit->unit_code); ?></strong></td>
                            <td><?php echo esc_html($unit->unit_name); ?></td>
                            <td><?php echo $unit->order_number; ?></td>
                            <td><?php echo $unit->duration_hours ?: '-'; ?></td>
                            <td><?php echo $unit->credit_hours ?: '-'; ?></td>
                            <td><span class="mtti-status mtti-status-<?php echo strtolower($unit->status); ?>">
                                <?php echo $unit->status; ?>
                            </span></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=edit-unit&unit_id=' . $unit->unit_id); ?>" 
                                   class="button button-small">Edit</a>
                                <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $course_id . '&delete_unit=' . $unit->unit_id); ?>" 
                                   class="button button-small" 
                                   onclick="return confirm('Are you sure you want to delete this unit?');">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_add_unit_form($course_id) {
        $course = $this->db->get_course($course_id);
        $suggested_code = $this->db->generate_unit_code($course_id);
        ?>
        <div class="wrap">
            <h1>Add Unit to: <?php echo esc_html($course->course_name); ?></h1>
            <form method="post">
                <?php wp_nonce_field('mtti_unit_action', 'mtti_unit_nonce'); ?>
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                <input type="hidden" name="action_type" value="add">
                
                <table class="form-table">
                    <tr>
                        <th><label>Unit Code (Auto-generated)</label></th>
                        <td>
                            <input type="text" name="unit_code" class="regular-text" 
                                   value="<?php echo esc_attr($suggested_code); ?>" readonly>
                            <p class="description">Unit code is automatically generated based on course code</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Unit Name *</label></th>
                        <td><input type="text" name="unit_name" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Description</label></th>
                        <td><textarea name="description" rows="4" class="large-text"></textarea></td>
                    </tr>
                    <tr>
                        <th><label>Order Number *</label></th>
                        <td>
                            <input type="number" name="order_number" required class="small-text" min="1" value="1">
                            <p class="description">Display order (1, 2, 3, etc.)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Duration (Hours)</label></th>
                        <td><input type="number" name="duration_hours" class="small-text" min="0"></td>
                    </tr>
                    <tr>
                        <th><label>Credit Hours</label></th>
                        <td><input type="number" name="credit_hours" class="small-text" step="0.5" min="0"></td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_unit_submit" class="button button-primary" value="Add Unit">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $course_id); ?>" 
                       class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function display_edit_unit_form($unit_id) {
        $unit = $this->db->get_course_unit($unit_id);
        if (!$unit) {
            wp_die('Unit not found');
        }
        
        $course = $this->db->get_course($unit->course_id);
        ?>
        <div class="wrap">
            <h1>Edit Unit: <?php echo esc_html($unit->unit_name); ?></h1>
            <form method="post">
                <?php wp_nonce_field('mtti_unit_action', 'mtti_unit_nonce'); ?>
                <input type="hidden" name="unit_id" value="<?php echo $unit_id; ?>">
                <input type="hidden" name="course_id" value="<?php echo $unit->course_id; ?>">
                <input type="hidden" name="action_type" value="edit">
                
                <table class="form-table">
                    <tr>
                        <th><label>Unit Code</label></th>
                        <td>
                            <input type="text" name="unit_code" class="regular-text" 
                                   value="<?php echo esc_attr($unit->unit_code); ?>" readonly>
                            <p class="description">Unit code cannot be changed</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Unit Name *</label></th>
                        <td><input type="text" name="unit_name" required class="regular-text" 
                                   value="<?php echo esc_attr($unit->unit_name); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Description</label></th>
                        <td><textarea name="description" rows="4" class="large-text"><?php echo esc_textarea($unit->description); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label>Order Number *</label></th>
                        <td>
                            <input type="number" name="order_number" required class="small-text" min="1" 
                                   value="<?php echo esc_attr($unit->order_number); ?>">
                            <p class="description">Display order (1, 2, 3, etc.)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Duration (Hours)</label></th>
                        <td><input type="number" name="duration_hours" class="small-text" min="0" 
                                   value="<?php echo esc_attr($unit->duration_hours); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Credit Hours</label></th>
                        <td><input type="number" name="credit_hours" class="small-text" step="0.5" min="0" 
                                   value="<?php echo esc_attr($unit->credit_hours); ?>"></td>
                    </tr>
                    <tr>
                        <th><label>Status</label></th>
                        <td>
                            <select name="status" class="regular-text">
                                <option value="Active" <?php selected($unit->status, 'Active'); ?>>Active</option>
                                <option value="Inactive" <?php selected($unit->status, 'Inactive'); ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_unit_submit" class="button button-primary" value="Update Unit">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $unit->course_id); ?>" 
                       class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function handle_unit_submission() {
        $action = sanitize_text_field($_POST['action_type']);
        $course_id = intval($_POST['course_id']);
        
        $data = array(
            'course_id' => $course_id,
            'unit_name' => sanitize_text_field($_POST['unit_name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'order_number' => intval($_POST['order_number']),
            'duration_hours' => !empty($_POST['duration_hours']) ? intval($_POST['duration_hours']) : null,
            'credit_hours' => !empty($_POST['credit_hours']) ? floatval($_POST['credit_hours']) : null
        );
        
        if ($action == 'edit') {
            $unit_id = intval($_POST['unit_id']);
            $data['status'] = sanitize_text_field($_POST['status']);
            $this->db->update_course_unit($unit_id, $data);
            $redirect_url = admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $course_id . '&message=unit-updated');
        } else {
            // Auto-generate unit code
            $data['unit_code'] = $this->db->generate_unit_code($course_id);
            $data['status'] = 'Active';
            $new_unit_id = $this->db->create_course_unit($data);
            
            // ============================================================
            // AUTO-ENROLL: Enroll all currently active students of this
            // course into the new unit immediately — no page refresh needed
            // ============================================================
            if ($new_unit_id) {
                global $wpdb;
                $unit_enrollments_table = $wpdb->prefix . 'mtti_unit_enrollments';
                $enrollments_table      = $wpdb->prefix . 'mtti_enrollments';
                
                // Get all students actively enrolled in this course
                $course_students = $wpdb->get_col($wpdb->prepare(
                    "SELECT student_id FROM {$enrollments_table}
                     WHERE course_id = %d AND status IN ('Active', 'Enrolled', 'In Progress')",
                    $course_id
                ));
                
                foreach ($course_students as $sid) {
                    // Avoid duplicates
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$unit_enrollments_table}
                         WHERE unit_id = %d AND student_id = %d",
                        $new_unit_id, $sid
                    ));
                    if (!$exists) {
                        $wpdb->insert($unit_enrollments_table, array(
                            'unit_id'         => $new_unit_id,
                            'student_id'      => intval($sid),
                            'enrollment_date' => current_time('Y-m-d'),
                            'status'          => 'Active'
                        ));
                    }
                }
            }
            
            $redirect_url = admin_url('admin.php?page=mtti-mis-courses&action=units&id=' . $course_id . '&message=unit-created');
        }
        
        wp_redirect($redirect_url);
        exit;
    }
}
