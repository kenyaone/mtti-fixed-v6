<?php
/**
 * Students Admin Class
 */
class MTTI_MIS_Admin_Students {

    private $plugin_name;
    private $version;
    private $db;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->db = MTTI_MIS_Database::get_instance();
    }

    public function display() {
        // Start output buffering to prevent header warnings
        if (!headers_sent()) {
            ob_start();
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        // Handle form submissions
        if (isset($_POST['mtti_student_submit'])) {
            check_admin_referer('mtti_student_action', 'mtti_student_nonce');
            // Clean output buffer before redirect
            if (ob_get_length()) {
                ob_end_clean();
            }
            $this->handle_form_submission();
            return;
        }
        
        // Handle delete action
        if ($action == 'delete' && $student_id) {
            // Clean output buffer before redirect
            if (ob_get_length()) {
                ob_end_clean();
            }
            $this->delete_student($student_id);
            return;
        }

        switch ($action) {
            case 'add':
                $this->display_add_form();
                break;
            case 'edit':
                $this->display_edit_form($student_id);
                break;
            case 'view':
                $this->display_student_details($student_id);
                break;
            case 'id_card':
                $this->display_id_card($student_id);
                break;
            default:
                $this->display_list();
        }
    }

    private function display_list() {
        // Get search parameter
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Get students with search filter
        $args = array();
        if (!empty($search)) {
            $args['search'] = $search;
        }
        
        $students = $this->db->get_students($args);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Students</h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=add'); ?>" class="page-title-action">Add New</a>
            <button onclick="window.print()" class="page-title-action" style="background: #2271b1; color: white; border: none; cursor: pointer;">
                <span class="dashicons dashicons-printer" style="vertical-align: middle;"></span> Print List
            </button>
            <hr class="wp-header-end">
            
            <style>
                @media print {
                    /* Hide WordPress admin elements */
                    #wpadminbar, #adminmenumain, .wrap > h1 .page-title-action, 
                    .search-form, .notice, .wp-header-end {
                        display: none !important;
                    }
                    
                    /* Hide action links in table */
                    .wp-list-table td:last-child,
                    .wp-list-table th:last-child {
                        display: none !important;
                    }
                    
                    /* Adjust layout for printing */
                    .wrap {
                        margin: 0 !important;
                        padding: 20px !important;
                    }
                    
                    .wrap > h1 {
                        font-size: 24px !important;
                        margin-bottom: 20px !important;
                    }
                    
                    /* Table styling */
                    .wp-list-table {
                        border: 1px solid #000 !important;
                        font-size: 11px !important;
                    }
                    
                    .wp-list-table th {
                        background: #f0f0f0 !important;
                        border: 1px solid #000 !important;
                        padding: 8px 5px !important;
                        font-weight: bold !important;
                    }
                    
                    .wp-list-table td {
                        border: 1px solid #ccc !important;
                        padding: 6px 5px !important;
                    }
                    
                    /* Status badges */
                    .mtti-status {
                        border: 1px solid #000 !important;
                        padding: 2px 6px !important;
                        font-size: 10px !important;
                    }
                    
                    /* Print header info */
                    .print-header {
                        display: block !important;
                        text-align: center;
                        margin-bottom: 20px;
                        border-bottom: 2px solid #000;
                        padding-bottom: 10px;
                    }
                    
                    .print-footer {
                        display: block !important;
                        margin-top: 30px;
                        font-size: 10px;
                        text-align: center;
                    }
                }
                
                @media screen {
                    .print-header, .print-footer {
                        display: none;
                    }
                }
            </style>
            
            <!-- Print Header (only visible when printing) -->
            <div class="print-header">
                <h2><?php echo get_option('mtti_mis_settings')['institute_name'] ?? 'Masomotele Technical Training Institute'; ?></h2>
                <p><strong>Student List Report</strong></p>
                <p>Generated: <?php echo date('F j, Y g:i A'); ?></p>
                <?php if (!empty($search)) : ?>
                <p>Search Filter: "<?php echo esc_html($search); ?>"</p>
                <?php endif; ?>
            </div>
            
            <!-- Search Form -->
            <form method="get" class="search-form" style="margin: 20px 0;">
                <input type="hidden" name="page" value="mtti-mis-students">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="Search by admission number, name, ID number, or email..." 
                           class="regular-text" style="width: 400px;">
                    <input type="submit" value="Search Students" class="button">
                    <?php if (!empty($search)) : ?>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-students'); ?>" class="button">Clear Search</a>
                    <?php endif; ?>
                </p>
            </form>
            
            <?php if (!empty($search)) : ?>
            <div class="notice notice-info">
                <p><strong>Search results for:</strong> "<?php echo esc_html($search); ?>" 
                   (<?php echo count($students); ?> student<?php echo count($students) != 1 ? 's' : ''; ?> found)</p>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['message'])) : ?>
            <div class="notice <?php echo ($_GET['message'] == 'created_no_email') ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
                <p>
                    <?php 
                    if ($_GET['message'] == 'created') {
                        echo 'Student created successfully!';
                        if (isset($_GET['admission'])) {
                            echo ' Admission number: <strong>' . esc_html($_GET['admission']) . '</strong>';
                        }
                        echo '<br><small>Welcome email sent to student.</small>';
                    } elseif ($_GET['message'] == 'created_no_email') {
                        echo 'Student created successfully!';
                        if (isset($_GET['admission'])) {
                            echo ' Admission number: <strong>' . esc_html($_GET['admission']) . '</strong>';
                        }
                        echo '<br><small><strong>Note:</strong> Email could not be sent (mail function disabled on server). Please manually provide login credentials to the student.</small>';
                    } elseif ($_GET['message'] == 'deleted') {
                        echo 'Student deleted successfully!';
                    }
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Admission No</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students) : foreach ($students as $student) : 
                        // Get course info directly from student record
                        $course = null;
                        if ($student->course_id) {
                            $course = $this->db->get_course($student->course_id);
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($student->admission_number); ?></strong></td>
                        <td><?php echo esc_html($student->display_name); ?></td>
                        <td>
                            <?php if ($course) : ?>
                                <span style="color: #2E7D32; font-weight: bold;" title="<?php echo esc_attr($course->course_name); ?>">
                                    ✓ <?php echo esc_html($course->course_code); ?>
                                </span>
                            <?php else : ?>
                                <span style="color: #999;">— Not Assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($student->user_email); ?></td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($student->status); ?>">
                            <?php echo esc_html($student->status); ?>
                        </span></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=view&id=' . $student->student_id); ?>">View</a> |
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=edit&id=' . $student->student_id); ?>">Edit</a> |
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-admission-letters&action=preview&student_id=' . $student->student_id); ?>" target="_blank" style="color: #00a32a;">Letter</a> |
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=delete&id=' . $student->student_id); ?>" 
                               onclick="return confirm('Are you sure you want to delete this student? This will also delete all enrollments, payments, and submissions.');" 
                               style="color: #dc3232;">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="6">No students found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Print Footer (only visible when printing) -->
            <div class="print-footer">
                <p>Total Students: <?php echo count($students); ?></p>
                <p>_________________________________</p>
                <p>Authorized Signature</p>
                <p style="margin-top: 10px; font-size: 9px;">
                    <?php echo get_option('mtti_mis_settings')['institute_name'] ?? 'M.T.T.I'; ?> | 
                    Sagaas Center, Fourth Floor | 
                    "Start Learning, Start Earning"
                </p>
            </div>
        </div>
        <?php
    }

    private function display_add_form() {
        $courses = $this->db->get_courses(array('status' => 'Active'));
        ?>
        <div class="wrap">
            <h1>Add New Student</h1>
            <form method="post" action="">
                <?php wp_nonce_field('mtti_student_action', 'mtti_student_nonce'); ?>
                <input type="hidden" name="action" value="add">
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="first_name">First Name *</label></th>
                        <td><input type="text" name="first_name" id="first_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="last_name">Last Name *</label></th>
                        <td><input type="text" name="last_name" id="last_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email">Email</label></th>
                        <td>
                            <input type="email" name="email" id="email" class="regular-text">
                            <p class="description">Optional - if not provided, a placeholder will be generated</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="phone">Phone</label></th>
                        <td><input type="text" name="phone" id="phone" class="regular-text"></td>
                    </tr>
                    <tr style="background: #f0f7ff;">
                        <th scope="row"><label>📷 Passport Photo</label></th>
                        <td>
                            <div id="photo-preview-add" style="margin-bottom: 10px;">
                                <img id="photo-img-add" src="" style="max-width: 150px; max-height: 180px; border: 2px solid #ddd; border-radius: 4px; display: none; object-fit: cover;">
                            </div>
                            <input type="hidden" name="photo_url" id="photo_url_add" value="">
                            <button type="button" class="button" id="upload-photo-btn-add">
                                <span class="dashicons dashicons-camera" style="vertical-align: middle;"></span> Upload Photo
                            </button>
                            <button type="button" class="button" id="remove-photo-btn-add" style="display: none; color: #dc3232;">
                                <span class="dashicons dashicons-no" style="vertical-align: middle;"></span> Remove
                            </button>
                            <p class="description">Upload a passport-sized photo (recommended: 300×400px, max 2MB). Used for Student ID card.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="primary_course">Primary Course *</label></th>
                        <td>
                            <select name="primary_course" id="primary_course" class="regular-text" required>
                                <option value="">Select Primary Course</option>
                                <?php foreach ($courses as $course) : ?>
                                <option value="<?php echo $course->course_id; ?>">
                                    <?php echo esc_html($course->course_name . ' - ' . $course->course_code . ' (KES ' . number_format($course->fee) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Admission number will be generated based on this course</p>
                        </td>
                    </tr>
                    <tr style="background: #e8f5e9;">
                        <th scope="row"><label for="admission_date"><strong>📅 Admission Date *</strong></label></th>
                        <td>
                            <input type="date" name="admission_date" id="admission_date" class="regular-text" 
                                   value="" required style="width: 200px; font-size: 14px; padding: 5px;">
                            <p class="description"><strong>Important:</strong> Select the date the student is being admitted. This date will be used for the admission letter and enrollment records.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Additional Courses</label></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Additional Courses</legend>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 4px;">
                                    <?php foreach ($courses as $course) : ?>
                                    <label style="display: block; margin-bottom: 8px; cursor: pointer;">
                                        <input type="checkbox" name="additional_courses[]" value="<?php echo $course->course_id; ?>" class="additional-course-checkbox">
                                        <strong><?php echo esc_html($course->course_name); ?></strong>
                                        <span style="color: #666;"> - <?php echo esc_html($course->course_code); ?></span>
                                        <span style="color: #2271b1;"> (KES <?php echo number_format($course->fee); ?>)</span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description">Select additional courses the student is enrolling in (optional). The primary course above will be automatically included.</p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="discount_amount">Discount/Scholarship (KES)</label></th>
                        <td>
                            <input type="number" name="discount_amount" id="discount_amount" class="regular-text" min="0" step="0.01" value="0">
                            <p class="description">Enter any discount or scholarship amount to apply to the total fee</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="id_number">ID Number</label></th>
                        <td><input type="text" name="id_number" id="id_number" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="date_of_birth">Date of Birth</label></th>
                        <td><input type="date" name="date_of_birth" id="date_of_birth" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gender">Gender</label></th>
                        <td>
                            <select name="gender" id="gender" class="regular-text">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="county">County</label></th>
                        <td><input type="text" name="county" id="county" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="address">Address</label></th>
                        <td><textarea name="address" id="address" rows="3" class="large-text"></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="emergency_contact">Emergency Contact</label></th>
                        <td><input type="text" name="emergency_contact" id="emergency_contact" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="emergency_phone">Emergency Phone</label></th>
                        <td><input type="text" name="emergency_phone" id="emergency_phone" class="regular-text"></td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="mtti_student_submit" class="button button-primary" value="Add Student">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-students'); ?>" class="button">Cancel</a>
                </p>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                // Auto-check/uncheck primary course in additional courses
                $('#primary_course').on('change', function() {
                    var primaryCourseId = $(this).val();
                    // Uncheck the checkbox for the primary course (it will be added automatically)
                    $('.additional-course-checkbox').each(function() {
                        if ($(this).val() === primaryCourseId) {
                            $(this).prop('checked', false).prop('disabled', true);
                        } else {
                            $(this).prop('disabled', false);
                        }
                    });
                });
                
                // Trigger on page load
                $('#primary_course').trigger('change');
                
                // Passport Photo Upload (Add Form)
                var photoFrame;
                $('#upload-photo-btn-add').on('click', function(e) {
                    e.preventDefault();
                    if (photoFrame) { photoFrame.open(); return; }
                    photoFrame = wp.media({
                        title: 'Select Passport Photo',
                        button: { text: 'Use This Photo' },
                        multiple: false,
                        library: { type: 'image' }
                    });
                    photoFrame.on('select', function() {
                        var attachment = photoFrame.state().get('selection').first().toJSON();
                        var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                        $('#photo_url_add').val(attachment.url);
                        $('#photo-img-add').attr('src', url).show();
                        $('#remove-photo-btn-add').show();
                    });
                    photoFrame.open();
                });
                $('#remove-photo-btn-add').on('click', function() {
                    $('#photo_url_add').val('');
                    $('#photo-img-add').attr('src', '').hide();
                    $(this).hide();
                });
            });
            </script>
        </div>
        <?php
    }

    private function display_edit_form($student_id) {
        $student = $this->db->get_student($student_id);
        if (!$student) {
            wp_die('Student not found');
        }
        
        // Get all WordPress users for linking
        $wp_users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
        
        // Get all active courses
        $courses = $this->db->get_courses(array('status' => 'Active'));
        ?>
        <div class="wrap">
            <h1>Edit Student</h1>
            <form method="post" action="">
                <?php wp_nonce_field('mtti_student_action', 'mtti_student_nonce'); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                <input type="hidden" name="old_course_id" value="<?php echo intval($student->course_id); ?>">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Admission Number</th>
                        <td><strong><?php echo esc_html($student->admission_number); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="first_name">First Name *</label></th>
                        <td>
                            <input type="text" name="first_name" id="first_name" class="regular-text" 
                                   value="<?php echo esc_attr($student->first_name ?? ''); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="last_name">Last Name *</label></th>
                        <td>
                            <input type="text" name="last_name" id="last_name" class="regular-text" 
                                   value="<?php echo esc_attr($student->last_name ?? ''); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email">Email Address</label></th>
                        <td>
                            <input type="email" name="email" id="email" class="regular-text" 
                                   value="<?php echo esc_attr($student->user_email ?? ''); ?>"
                                   style="width: 300px;">
                            <p class="description">This will update the linked WordPress user's email if one is linked.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="phone">Phone Number</label></th>
                        <td>
                            <input type="text" name="phone" id="phone" class="regular-text" 
                                   value="<?php echo esc_attr(get_user_meta($student->user_id, 'phone', true) ?? ''); ?>">
                        </td>
                    </tr>
                    <tr style="background: #f0f7ff;">
                        <th scope="row"><label>📷 Passport Photo</label></th>
                        <td>
                            <div id="photo-preview-edit" style="margin-bottom: 10px;">
                                <?php if (!empty($student->photo_url)) : ?>
                                <img id="photo-img-edit" src="<?php echo esc_url($student->photo_url); ?>" style="max-width: 150px; max-height: 180px; border: 2px solid #ddd; border-radius: 4px; object-fit: cover;">
                                <?php else : ?>
                                <img id="photo-img-edit" src="" style="max-width: 150px; max-height: 180px; border: 2px solid #ddd; border-radius: 4px; display: none; object-fit: cover;">
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="photo_url" id="photo_url_edit" value="<?php echo esc_attr($student->photo_url ?? ''); ?>">
                            <button type="button" class="button" id="upload-photo-btn-edit">
                                <span class="dashicons dashicons-camera" style="vertical-align: middle;"></span> 
                                <?php echo !empty($student->photo_url) ? 'Change Photo' : 'Upload Photo'; ?>
                            </button>
                            <button type="button" class="button" id="remove-photo-btn-edit" style="<?php echo empty($student->photo_url) ? 'display:none;' : ''; ?> color: #dc3232;">
                                <span class="dashicons dashicons-no" style="vertical-align: middle;"></span> Remove
                            </button>
                            <p class="description">Upload a passport-sized photo (recommended: 300×400px, max 2MB). Used for Student ID card.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="user_id">Link to WordPress User</label></th>
                        <td>
                            <select name="user_id" id="user_id" class="regular-text">
                                <option value="">-- Select User --</option>
                                <?php foreach ($wp_users as $user) : ?>
                                    <option value="<?php echo $user->ID; ?>" <?php selected($student->user_id, $user->ID); ?>>
                                        <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Link this student to a WordPress user account for portal access.</p>
                            <?php if ($student->user_id) : ?>
                                <p><strong>Currently linked to:</strong> <?php echo esc_html($student->display_name . ' (' . $student->user_email . ')'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="background: #e8f5e9;">
                        <th scope="row"><label for="admission_date"><strong>📅 Admission Date</strong></label></th>
                        <td>
                            <?php 
                            $enrollment_date = !empty($student->enrollment_date) ? date('Y-m-d', strtotime($student->enrollment_date)) : '';
                            ?>
                            <input type="date" name="admission_date" id="admission_date" class="regular-text" 
                                   value="<?php echo esc_attr($enrollment_date); ?>" style="width: 200px; font-size: 14px; padding: 5px;">
                            <p class="description">The original admission date for this student. Changing this will update the student's enrollment date record.</p>
                            <?php if (!empty($student->enrollment_date)) : ?>
                                <p style="color: #666; margin-top: 5px;">
                                    <strong>Current Admission Date:</strong> <?php echo date('F j, Y', strtotime($student->enrollment_date)); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="background: #f0f7f0;">
                        <th scope="row"><label for="course_id"><strong>📚 Primary Course</strong></label></th>
                        <td>
                            <select name="course_id" id="course_id" class="regular-text" style="min-width: 300px;">
                                <option value="">-- Select Course --</option>
                                <?php foreach ($courses as $course) : ?>
                                    <option value="<?php echo $course->course_id; ?>" <?php selected($student->course_id, $course->course_id); ?>>
                                        <?php echo esc_html($course->course_code . ' - ' . $course->course_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><strong>Important:</strong> This is the course the student will see in the Student Portal/App. Changing this will auto-enroll student in all units of the new course.</p>
                        </td>
                    </tr>
                    <?php
                    // Get current enrollments for this student
                    global $wpdb;
                    $table_prefix = $wpdb->prefix . 'mtti_';
                    
                    // Fetch ONLY existing ACTIVE enrollments - NO auto-creation
                    // Enrollments are created explicitly via: Add Student, Enrollments page, or Payments page
                    $current_enrollments = $wpdb->get_results($wpdb->prepare(
                        "SELECT e.*, c.course_name, c.course_code, c.fee 
                         FROM {$table_prefix}enrollments e
                         LEFT JOIN {$table_prefix}courses c ON e.course_id = c.course_id
                         WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
                        $student_id
                    ));
                    $enrolled_course_ids = array_map(function($e) { return $e->course_id; }, $current_enrollments);
                    
                    // Also get dropped enrollments for display (but not for checkbox selection)
                    $dropped_enrollments = $wpdb->get_results($wpdb->prepare(
                        "SELECT e.*, c.course_name, c.course_code, c.fee 
                         FROM {$table_prefix}enrollments e
                         LEFT JOIN {$table_prefix}courses c ON e.course_id = c.course_id
                         WHERE e.student_id = %d AND e.status = 'Dropped'",
                        $student_id
                    ));
                    ?>
                    <tr style="background: #fff8e1;">
                        <th scope="row"><label><strong>📋 Current Enrollments</strong></label></th>
                        <td>
                            <?php if (!empty($current_enrollments)) : ?>
                            <div style="background: #e8f5e9; border: 1px solid #4CAF50; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                <strong style="color: #2E7D32;">✅ Enrolled in <?php echo count($current_enrollments); ?> active course(s) - Edit enrollment dates below:</strong>
                                <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #c8e6c9;">
                                            <th style="padding: 8px; text-align: left; border: 1px solid #a5d6a7;">Course</th>
                                            <th style="padding: 8px; text-align: left; border: 1px solid #a5d6a7;">Enrollment Date</th>
                                            <th style="padding: 8px; text-align: left; border: 1px solid #a5d6a7;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($current_enrollments as $enrollment) : 
                                        $enroll_date = !empty($enrollment->enrollment_date) ? date('Y-m-d', strtotime($enrollment->enrollment_date)) : '';
                                    ?>
                                        <tr style="background: #fff;">
                                            <td style="padding: 8px; border: 1px solid #e0e0e0;">
                                                <strong><?php echo esc_html($enrollment->course_name); ?></strong>
                                                <span style="color: #666; font-size: 12px;">(<?php echo esc_html($enrollment->course_code); ?>)</span>
                                                <br><span style="color: #2271b1; font-size: 12px;">KES <?php echo number_format($enrollment->fee); ?></span>
                                            </td>
                                            <td style="padding: 8px; border: 1px solid #e0e0e0;">
                                                <input type="date" name="enrollment_dates[<?php echo $enrollment->enrollment_id; ?>]" 
                                                       value="<?php echo esc_attr($enroll_date); ?>" 
                                                       style="width: 150px; padding: 4px;">
                                            </td>
                                            <td style="padding: 8px; border: 1px solid #e0e0e0;">
                                                <span style="color: #2E7D32; font-weight: bold;"><?php echo esc_html($enrollment->status); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p class="description" style="margin-top: 10px; color: #666;">
                                    <strong>Note:</strong> Modify enrollment dates above to correct historical records. Changes will be saved when you update the student.
                                </p>
                            </div>
                            <?php else : ?>
                            <div style="background: #ffebee; border: 1px solid #f44336; padding: 10px; border-radius: 4px;">
                                <span style="color: #c62828;">⚠️ Student is not enrolled in any active course</span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($dropped_enrollments)) : ?>
                            <div style="background: #fff3e0; border: 1px solid #ff9800; padding: 15px; border-radius: 4px; margin-top: 10px;">
                                <strong style="color: #e65100;">📤 Dropped Courses (<?php echo count($dropped_enrollments); ?>):</strong>
                                <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #ffe0b2;">
                                            <th style="padding: 8px; text-align: left; border: 1px solid #ffcc80;">Course</th>
                                            <th style="padding: 8px; text-align: left; border: 1px solid #ffcc80;">Original Enrollment Date</th>
                                            <th style="padding: 8px; text-align: left; border: 1px solid #ffcc80;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($dropped_enrollments as $enrollment) : 
                                        $enroll_date = !empty($enrollment->enrollment_date) ? date('M j, Y', strtotime($enrollment->enrollment_date)) : 'N/A';
                                    ?>
                                        <tr style="background: #fff;">
                                            <td style="padding: 8px; border: 1px solid #e0e0e0;">
                                                <span style="text-decoration: line-through; color: #999;"><?php echo esc_html($enrollment->course_name); ?></span>
                                                <span style="color: #999; font-size: 12px;">(<?php echo esc_html($enrollment->course_code); ?>)</span>
                                            </td>
                                            <td style="padding: 8px; border: 1px solid #e0e0e0; color: #999;">
                                                <?php echo esc_html($enroll_date); ?>
                                            </td>
                                            <td style="padding: 8px; border: 1px solid #e0e0e0;">
                                                <span style="color: #e65100; font-weight: bold;">Dropped</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p class="description" style="margin-top: 10px; color: #888; font-size: 11px;">
                                    <em>Dropped courses are preserved for historical records. To re-enroll, check the course in "Additional Courses" below.</em>
                                </p>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="background: #e3f2fd;">
                        <th scope="row"><label><strong>➕ Enroll in Additional Courses</strong></label></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">Additional Courses</legend>
                                <p style="margin: 0 0 10px 0; color: #666;">
                                    <strong><?php echo count($courses); ?> active courses available</strong>
                                </p>
                                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #2196F3; padding: 15px; background: #fff; border-radius: 4px;">
                                    <?php if (empty($courses)) : ?>
                                        <p style="color: #d32f2f;">No active courses found. Please add courses in the Courses menu.</p>
                                    <?php else : ?>
                                    <?php foreach ($courses as $course) : 
                                        $is_enrolled = in_array($course->course_id, $enrolled_course_ids);
                                        $is_primary = ($course->course_id == $student->course_id);
                                    ?>
                                    <label style="display: block; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; <?php echo $is_enrolled ? 'background: #e8f5e9;' : ''; ?>">
                                        <input type="checkbox" name="additional_courses[]" value="<?php echo $course->course_id; ?>" 
                                               class="additional-course-checkbox"
                                               data-fee="<?php echo $course->fee; ?>"
                                               data-course-name="<?php echo esc_attr($course->course_name); ?>"
                                               data-is-enrolled="<?php echo $is_enrolled ? '1' : '0'; ?>"
                                               <?php echo $is_enrolled ? 'checked' : ''; ?>
                                               <?php echo $is_primary ? 'disabled' : ''; ?>>
                                        <?php if ($is_primary) : ?>
                                            <input type="hidden" name="additional_courses[]" value="<?php echo $course->course_id; ?>">
                                        <?php endif; ?>
                                        <strong><?php echo esc_html($course->course_name); ?></strong>
                                        <span style="color: #666;"> - <?php echo esc_html($course->course_code); ?></span>
                                        <span style="color: #2271b1; font-weight: bold;"> (KES <?php echo number_format($course->fee); ?>)</span>
                                        <?php if ($is_enrolled) : ?>
                                            <span style="color: #2E7D32; font-size: 11px; margin-left: 5px;">✓ Enrolled</span>
                                        <?php endif; ?>
                                        <?php if ($is_primary) : ?>
                                            <span style="color: #E65100; font-size: 11px; margin-left: 5px;">★ Primary</span>
                                        <?php endif; ?>
                                    </label>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <p class="description" style="margin-top: 10px;">
                                    <strong>Note:</strong> Check courses to enroll the student. Unchecking <strong>WILL</strong> mark the course as "Dropped".
                                    New enrollments will be added to the student's record.
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <!-- FEE SUMMARY SECTION -->
                    <?php
                    // Calculate current total fees and paid amounts - ONLY for ACTIVE enrollments
                    $current_total_fee = 0;
                    $current_total_paid = 0;
                    $current_total_balance = 0;
                    
                    $balances = $wpdb->get_results($wpdb->prepare(
                        "SELECT sb.*, c.course_name, c.course_code, c.fee as actual_course_fee,
                                e.status as enrollment_status
                         FROM {$wpdb->prefix}mtti_student_balances sb
                         LEFT JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
                         LEFT JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
                         WHERE sb.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
                        $student_id
                    ));
                    
                    $payments_table = $wpdb->prefix . 'mtti_payments';
                    foreach ($balances as &$bal) {
                        // Use stored total_fee (locked at enrollment) — fall back to current course fee only if missing
                        $actual_fee = floatval($bal->total_fee) > 0 ? floatval($bal->total_fee) : floatval($bal->actual_course_fee);
                        $discount   = floatval($bal->discount_amount ?? 0);
                        $net_fee    = max(0, $actual_fee - $discount);
                        
                        // Get actual payments for this enrollment
                        $paid = floatval($wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table}
                             WHERE student_id = %d AND enrollment_id = %d AND status = 'Completed'",
                            $student_id, $bal->enrollment_id
                        )));
                        
                        $bal->total_fee  = $actual_fee;
                        $bal->total_paid = $paid;
                        $bal->balance    = max(0, $net_fee - $paid);
                        
                        $current_total_fee     += $bal->total_fee;
                        $current_total_paid    += $bal->total_paid;
                        $current_total_balance += $bal->balance;
                    }
                    unset($bal);

                    // ── Handle unlinked payments (no enrollment_id) ──────────────────
                    // Payments recorded without an enrollment_id won't be caught above.
                    // Distribute them across balances that still have an outstanding amount.
                    if (!empty($balances)) {
                        $unlinked_paid = floatval($wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table}
                             WHERE student_id = %d AND status = 'Completed'
                               AND (enrollment_id IS NULL OR enrollment_id = 0
                                    OR enrollment_id NOT IN (
                                        SELECT enrollment_id FROM {$wpdb->prefix}mtti_enrollments
                                        WHERE student_id = %d AND status IN ('Active','Enrolled','In Progress')
                                    ))",
                            $student_id, $student_id
                        )));

                        if ($unlinked_paid > 0) {
                            // Reset running totals — we'll recalculate after distributing
                            $current_total_fee     = 0;
                            $current_total_paid    = 0;
                            $current_total_balance = 0;

                            foreach ($balances as &$bal2) {
                                if ($unlinked_paid > 0 && $bal2->balance > 0) {
                                    $apply           = min($unlinked_paid, $bal2->balance);
                                    $bal2->total_paid += $apply;
                                    $bal2->balance    -= $apply;
                                    $unlinked_paid   -= $apply;
                                }
                                $current_total_fee     += $bal2->total_fee;
                                $current_total_paid    += $bal2->total_paid;
                                $current_total_balance += $bal2->balance;
                            }
                            unset($bal2);
                        }
                    }

                    // ── Persist corrected total_fee back to DB so it stays fixed ────
                    foreach ($balances as $bal_fix) {
                        $stored_fee = floatval($wpdb->get_var($wpdb->prepare(
                            "SELECT total_fee FROM {$wpdb->prefix}mtti_student_balances WHERE enrollment_id = %d",
                            $bal_fix->enrollment_id
                        )));
                        if (abs($stored_fee - floatval($bal_fix->total_fee)) > 0.01) {
                            $wpdb->update(
                                $wpdb->prefix . 'mtti_student_balances',
                                array('total_fee' => floatval($bal_fix->total_fee),
                                      'balance'   => floatval($bal_fix->balance)),
                                array('enrollment_id' => $bal_fix->enrollment_id),
                                array('%f', '%f'), array('%d')
                            );
                        }
                    }
                    ?>
                    <tr style="background: #e8f5e9;">
                        <th scope="row"><label><strong>📊 Fee Summary</strong></label></th>
                        <td>
                            <div style="background: #fff; border: 2px solid #4CAF50; padding: 20px; border-radius: 8px;">
                                <!-- Current Enrollments Summary -->
                                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px dashed #ccc;">
                                    <h4 style="margin: 0 0 10px 0; color: #2E7D32;">📚 Current Enrollments</h4>
                                    <?php if (!empty($balances)) : ?>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                        <thead>
                                            <tr style="background: #f5f5f5;">
                                                <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Course</th>
                                                <th style="text-align: right; padding: 8px; border: 1px solid #ddd;">Fee</th>
                                                <th style="text-align: right; padding: 8px; border: 1px solid #ddd;">Paid</th>
                                                <th style="text-align: right; padding: 8px; border: 1px solid #ddd;">Balance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($balances as $bal) : ?>
                                            <tr>
                                                <td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html($bal->course_name); ?></td>
                                                <td style="text-align: right; padding: 8px; border: 1px solid #ddd;">KES <?php echo number_format($bal->total_fee, 2); ?></td>
                                                <td style="text-align: right; padding: 8px; border: 1px solid #ddd; color: #1976D2;">KES <?php echo number_format($bal->total_paid, 2); ?></td>
                                                <td style="text-align: right; padding: 8px; border: 1px solid #ddd; color: <?php echo $bal->balance > 0 ? '#D32F2F' : '#2E7D32'; ?>; font-weight: bold;">
                                                    KES <?php echo number_format($bal->balance, 2); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr style="background: #e3f2fd; font-weight: bold;">
                                                <td style="padding: 8px; border: 1px solid #ddd;"><strong>SUBTOTAL (Current)</strong></td>
                                                <td style="text-align: right; padding: 8px; border: 1px solid #ddd;">KES <?php echo number_format($current_total_fee, 2); ?></td>
                                                <td style="text-align: right; padding: 8px; border: 1px solid #ddd; color: #1976D2;">KES <?php echo number_format($current_total_paid, 2); ?></td>
                                                <td style="text-align: right; padding: 8px; border: 1px solid #ddd; color: <?php echo $current_total_balance > 0 ? '#D32F2F' : '#2E7D32'; ?>;">
                                                    KES <?php echo number_format($current_total_balance, 2); ?>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <?php else : ?>
                                    <p style="color: #666; margin: 0;">No current enrollments</p>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- New Courses Being Added -->
                                <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px dashed #ccc;">
                                    <h4 style="margin: 0 0 10px 0; color: #E65100;">➕ New Courses Being Added</h4>
                                    <div id="new-courses-list" style="min-height: 40px;">
                                        <p style="color: #999; margin: 0; font-style: italic;">Select new courses above to see fees here...</p>
                                    </div>
                                    <div id="new-courses-total" style="display: none; margin-top: 10px; padding: 10px; background: #fff3e0; border-radius: 4px;">
                                        <strong>New Courses Total: <span id="new-courses-fee-total" style="color: #E65100;">KES 0.00</span></strong>
                                    </div>
                                </div>
                                
                                <!-- Grand Total -->
                                <div style="background: linear-gradient(135deg, #1976D2, #1565C0); color: white; padding: 20px; border-radius: 8px; margin-top: 15px;">
                                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                                        <div>
                                            <div style="font-size: 12px; opacity: 0.9;">TOTAL FEES (All Courses)</div>
                                            <div style="font-size: 24px; font-weight: bold;" id="grand-total-fee">
                                                KES <?php echo number_format($current_total_fee, 2); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="font-size: 12px; opacity: 0.9;">TOTAL PAID</div>
                                            <div style="font-size: 24px; font-weight: bold; color: #81D4FA;">
                                                KES <?php echo number_format($current_total_paid, 2); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="font-size: 12px; opacity: 0.9;">TOTAL BALANCE DUE</div>
                                            <div style="font-size: 24px; font-weight: bold; color: #FFCDD2;" id="grand-total-balance">
                                                KES <?php echo number_format($current_total_balance, 2); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Hidden fields for JavaScript calculations -->
                            <input type="hidden" id="current-total-fee" value="<?php echo $current_total_fee; ?>">
                            <input type="hidden" id="current-total-paid" value="<?php echo $current_total_paid; ?>">
                            <input type="hidden" id="current-total-balance" value="<?php echo $current_total_balance; ?>">
                        </td>
                    </tr>
                    
                    <tr style="background: #fff3e0;">
                        <th scope="row"><label for="new_course_payment"><strong>💰 Payment for New Courses</strong></label></th>
                        <td>
                            <div style="background: #fff; border: 1px solid #FF9800; padding: 15px; border-radius: 4px;">
                                <div id="payment-section-message" style="display: none; background: #ffebee; border: 1px solid #f44336; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                                    <span style="color: #c62828;">⚠️ Select new courses above to enable payment</span>
                                </div>
                                
                                <div id="payment-fields">
                                    <div style="margin-bottom: 15px; padding: 10px; background: #e3f2fd; border-radius: 4px;">
                                        <strong>New Courses Fee: <span id="payment-new-fee" style="color: #1976D2;">KES 0.00</span></strong>
                                    </div>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <label for="new_course_payment" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                            Payment Amount (KES)
                                        </label>
                                        <input type="number" name="new_course_payment" id="new_course_payment" 
                                               class="regular-text" min="0" step="0.01" value="0"
                                               style="width: 200px; font-size: 18px; padding: 8px;">
                                        <p class="description">Enter the amount being paid now for the new courses</p>
                                    </div>
                                    <div style="margin-bottom: 15px;">
                                        <label for="new_course_payment_method" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                            Payment Method
                                        </label>
                                        <select name="new_course_payment_method" id="new_course_payment_method" style="width: 200px;">
                                            <option value="Cash">Cash</option>
                                            <option value="M-Pesa">M-Pesa</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="Card">Card</option>
                                            <option value="Cheque">Cheque</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="new_course_transaction_ref" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                            Transaction Reference (Optional)
                                        </label>
                                        <input type="text" name="new_course_transaction_ref" id="new_course_transaction_ref" 
                                               class="regular-text" placeholder="e.g., M-Pesa code, Bank ref, etc."
                                               style="width: 300px;">
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="id_number">ID Number</label></th>
                        <td><input type="text" name="id_number" id="id_number" class="regular-text" 
                                   value="<?php echo esc_attr($student->id_number); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="date_of_birth">Date of Birth</label></th>
                        <td><input type="date" name="date_of_birth" id="date_of_birth" class="regular-text" 
                                   value="<?php echo esc_attr($student->date_of_birth); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gender">Gender</label></th>
                        <td>
                            <select name="gender" id="gender" class="regular-text">
                                <option value="">Select Gender</option>
                                <option value="Male" <?php selected($student->gender, 'Male'); ?>>Male</option>
                                <option value="Female" <?php selected($student->gender, 'Female'); ?>>Female</option>
                                <option value="Other" <?php selected($student->gender, 'Other'); ?>>Other</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="county">County</label></th>
                        <td><input type="text" name="county" id="county" class="regular-text" 
                                   value="<?php echo esc_attr($student->county); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="address">Address</label></th>
                        <td><textarea name="address" id="address" rows="3" class="large-text"><?php 
                            echo esc_textarea($student->address); 
                        ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="emergency_contact">Emergency Contact</label></th>
                        <td><input type="text" name="emergency_contact" id="emergency_contact" class="regular-text" 
                                   value="<?php echo esc_attr($student->emergency_contact); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="emergency_phone">Emergency Phone</label></th>
                        <td><input type="text" name="emergency_phone" id="emergency_phone" class="regular-text" 
                                   value="<?php echo esc_attr($student->emergency_phone); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text">
                                <option value="Active" <?php selected($student->status, 'Active'); ?>>Active</option>
                                <option value="Inactive" <?php selected($student->status, 'Inactive'); ?>>Inactive</option>
                                <option value="Graduated" <?php selected($student->status, 'Graduated'); ?>>Graduated</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="mtti_student_submit" class="button button-primary" value="Update Student">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-students'); ?>" class="button">Cancel</a>
                </p>
            </form>
            
            <script>
            jQuery(document).ready(function($) {
                // Format number as currency
                function formatCurrency(amount) {
                    return 'KES ' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                
                // Get base values
                var currentTotalFee = parseFloat($('#current-total-fee').val()) || 0;
                var currentTotalPaid = parseFloat($('#current-total-paid').val()) || 0;
                var currentTotalBalance = parseFloat($('#current-total-balance').val()) || 0;
                
                // Update fee summary when checkboxes change
                function updateFeeSummary() {
                    var newCourses = [];
                    var newCoursesTotalFee = 0;
                    
                    // Loop through all course checkboxes
                    $('.additional-course-checkbox').each(function() {
                        var $checkbox = $(this);
                        var isChecked = $checkbox.is(':checked');
                        var wasEnrolled = $checkbox.data('is-enrolled') === '1' || $checkbox.data('is-enrolled') === 1;
                        var fee = parseFloat($checkbox.data('fee')) || 0;
                        var courseName = $checkbox.data('course-name');
                        
                        // If checked and NOT already enrolled, it's a new course
                        if (isChecked && !wasEnrolled) {
                            newCourses.push({
                                name: courseName,
                                fee: fee
                            });
                            newCoursesTotalFee += fee;
                        }
                    });
                    
                    // Update new courses list display
                    var $newCoursesList = $('#new-courses-list');
                    var $newCoursesTotal = $('#new-courses-total');
                    
                    if (newCourses.length > 0) {
                        var html = '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';
                        html += '<tbody>';
                        newCourses.forEach(function(course) {
                            html += '<tr style="background: #fff3e0;">';
                            html += '<td style="padding: 8px; border: 1px solid #ddd;"><strong>➕ ' + course.name + '</strong></td>';
                            html += '<td style="text-align: right; padding: 8px; border: 1px solid #ddd; color: #E65100; font-weight: bold;">' + formatCurrency(course.fee) + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                        $newCoursesList.html(html);
                        
                        // Show total
                        $('#new-courses-fee-total').text(formatCurrency(newCoursesTotalFee));
                        $newCoursesTotal.show();
                        
                        // Update payment section
                        $('#payment-section-message').hide();
                        $('#payment-new-fee').text(formatCurrency(newCoursesTotalFee));
                    } else {
                        $newCoursesList.html('<p style="color: #999; margin: 0; font-style: italic;">Select new courses above to see fees here...</p>');
                        $newCoursesTotal.hide();
                        $('#payment-new-fee').text('KES 0.00');
                    }
                    
                    // Update grand totals
                    var grandTotalFee = currentTotalFee + newCoursesTotalFee;
                    var grandTotalBalance = currentTotalBalance + newCoursesTotalFee;
                    
                    $('#grand-total-fee').text(formatCurrency(grandTotalFee));
                    $('#grand-total-balance').text(formatCurrency(grandTotalBalance));
                }
                
                // Bind change event to all course checkboxes
                $('.additional-course-checkbox').on('change', function() {
                    updateFeeSummary();
                });
                
                // Update payment field when amount changes
                $('#new_course_payment').on('input', function() {
                    var paymentAmount = parseFloat($(this).val()) || 0;
                    var newCoursesTotalFee = 0;
                    
                    // Calculate new courses fee
                    $('.additional-course-checkbox').each(function() {
                        var $checkbox = $(this);
                        var isChecked = $checkbox.is(':checked');
                        var wasEnrolled = $checkbox.data('is-enrolled') === '1' || $checkbox.data('is-enrolled') === 1;
                        var fee = parseFloat($checkbox.data('fee')) || 0;
                        
                        if (isChecked && !wasEnrolled) {
                            newCoursesTotalFee += fee;
                        }
                    });
                    
                    // Update grand total balance considering the payment
                    var grandTotalBalance = currentTotalBalance + newCoursesTotalFee - paymentAmount;
                    if (grandTotalBalance < 0) grandTotalBalance = 0;
                    
                    $('#grand-total-balance').text(formatCurrency(grandTotalBalance));
                });
                
                // Initial calculation
                updateFeeSummary();
                
                // Passport Photo Upload (Edit Form)
                var photoFrameEdit;
                $('#upload-photo-btn-edit').on('click', function(e) {
                    e.preventDefault();
                    if (photoFrameEdit) { photoFrameEdit.open(); return; }
                    photoFrameEdit = wp.media({
                        title: 'Select Passport Photo',
                        button: { text: 'Use This Photo' },
                        multiple: false,
                        library: { type: 'image' }
                    });
                    photoFrameEdit.on('select', function() {
                        var attachment = photoFrameEdit.state().get('selection').first().toJSON();
                        var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                        $('#photo_url_edit').val(attachment.url);
                        $('#photo-img-edit').attr('src', url).show();
                        $('#remove-photo-btn-edit').show();
                        $('#upload-photo-btn-edit').html('<span class="dashicons dashicons-camera" style="vertical-align: middle;"></span> Change Photo');
                    });
                    photoFrameEdit.open();
                });
                $('#remove-photo-btn-edit').on('click', function() {
                    $('#photo_url_edit').val('');
                    $('#photo-img-edit').attr('src', '').hide();
                    $(this).hide();
                    $('#upload-photo-btn-edit').html('<span class="dashicons dashicons-camera" style="vertical-align: middle;"></span> Upload Photo');
                });
            });
            </script>
        </div>
        <?php
    }

    private function display_student_details($student_id) {
        $student = $this->db->get_student($student_id);
        if (!$student) {
            wp_die('Student not found');
        }

        // Enrollments are created explicitly via: Add Student, Enrollments page, or Payments page
        // NO auto-creation on view/edit pages to prevent phantom enrollments and fee balances

        $enrollments = $this->db->get_enrollments(array('student_id' => $student_id));
        $payments = $this->db->get_payments(array('student_id' => $student_id));
        $balances = $this->db->get_student_balance($student_id);
        ?>
        <div class="wrap">
            <h1>Student Details</h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=edit&id=' . $student_id); ?>" 
               class="page-title-action">Edit</a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-admission-letters&action=preview&student_id=' . $student_id); ?>" 
               class="page-title-action" style="background: #00a32a; color: white; border-color: #00a32a;" target="_blank">
                <span class="dashicons dashicons-media-document" style="vertical-align: middle;"></span> Admission Letter
            </a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-certificates&action=transcript&student_id=' . $student_id); ?>" 
               class="page-title-action" target="_blank">
                <span class="dashicons dashicons-media-document"></span> Print Transcript
            </a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students'); ?>" 
               class="page-title-action">Back to List</a>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=id_card&id=' . $student_id); ?>" 
               class="page-title-action" style="background: #1976D2; color: white; border-color: #1565C0;" target="_blank">
                <span class="dashicons dashicons-id-alt" style="vertical-align: middle;"></span> Generate ID Card
            </a>
            
            <?php if (isset($_GET['message']) && $_GET['message'] == 'created_no_email') : ?>
            <div class="notice notice-warning" style="margin: 20px 0;">
                <p><strong>⚠️ Email Not Sent - Manual Credential Sharing Required</strong></p>
                <p>The student account was created successfully, but the welcome email could not be sent (mail function is disabled on this server).</p>
                <p><strong>Please manually share these credentials with the student:</strong></p>
                <div style="background: #f0f0f0; padding: 15px; margin: 10px 0; border-left: 4px solid #ffb900;">
                    <p style="margin: 5px 0;"><strong>Login URL:</strong> <?php echo wp_login_url(); ?></p>
                    <p style="margin: 5px 0;"><strong>Username:</strong> <?php echo esc_html($student->user_email); ?></p>
                    <p style="margin: 5px 0;"><strong>Email:</strong> <?php echo esc_html($student->user_email); ?></p>
                    <p style="margin: 5px 0;"><strong>Admission Number:</strong> <?php echo esc_html($student->admission_number); ?></p>
                    <p style="margin: 5px 0; color: #d63638;"><strong>Note:</strong> The temporary password was auto-generated. You may want to reset it via Users menu and share it with the student.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['courses']) && intval($_GET['courses']) > 1) : ?>
            <div class="notice notice-success" style="margin: 20px 0;">
                <p><strong>✅ Student enrolled in <?php echo intval($_GET['courses']); ?> courses successfully!</strong></p>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['admission_changed']) && $_GET['admission_changed'] == '1') : ?>
            <div class="notice notice-warning" style="margin: 20px 0; border-left-color: #ff9800;">
                <p><strong>🔄 Admission Number Changed!</strong></p>
                <p style="margin: 5px 0;">
                    <span style="text-decoration: line-through; color: #999;"><?php echo esc_html($_GET['old_admission']); ?></span>
                    &nbsp;➜&nbsp;
                    <strong style="color: #2e7d32; font-size: 16px;"><?php echo esc_html($_GET['new_admission']); ?></strong>
                </p>
                <p style="margin: 5px 0; font-size: 12px; color: #666;">
                    <em>The admission number was automatically updated because the primary course was changed. Please update any external records if necessary.</em>
                </p>
            </div>
            <?php endif; ?>
            
            <?php 
            // Get all enrolled courses for display
            global $wpdb;
            $enrollments_table = $wpdb->prefix . 'mtti_enrollments';
            $courses_table = $wpdb->prefix . 'mtti_courses';
            
            // Check if discount_amount column exists
            $column_check = $wpdb->get_results("SHOW COLUMNS FROM {$enrollments_table} LIKE 'discount_amount'");
            $has_discount_column = !empty($column_check);
            
            // Only show ACTIVE enrollments (not dropped courses)
            if ($has_discount_column) {
                $enrolled_courses = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.*, e.discount_amount, e.enrollment_date, e.status as enrollment_status
                     FROM {$enrollments_table} e
                     JOIN {$courses_table} c ON e.course_id = c.course_id
                     WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
                     ORDER BY e.enrollment_date DESC",
                    $student_id
                ));
            } else {
                $enrolled_courses = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.*, 0 as discount_amount, e.enrollment_date, e.status as enrollment_status
                     FROM {$enrollments_table} e
                     JOIN {$courses_table} c ON e.course_id = c.course_id
                     WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
                     ORDER BY e.enrollment_date DESC",
                    $student_id
                ));
            }
            
            // Also get dropped courses for separate display
            $dropped_courses = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, e.enrollment_date, e.status as enrollment_status
                 FROM {$enrollments_table} e
                 JOIN {$courses_table} c ON e.course_id = c.course_id
                 WHERE e.student_id = %d AND e.status = 'Dropped'
                 ORDER BY e.enrollment_date DESC",
                $student_id
            ));
            ?>
            
            <div class="mtti-student-details">
                <table class="form-table">
                    <?php if (!empty($student->photo_url)) : ?>
                    <tr style="background: #f0f7ff;">
                        <th>Passport Photo:</th>
                        <td>
                            <img src="<?php echo esc_url($student->photo_url); ?>" 
                                 style="max-width: 150px; max-height: 180px; border: 2px solid #1976D2; border-radius: 6px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
                        </td>
                    </tr>
                    <?php else : ?>
                    <tr style="background: #fff3e0;">
                        <th>Passport Photo:</th>
                        <td>
                            <span style="color: #e65100;">⚠️ No photo uploaded</span>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=edit&id=' . $student_id); ?>" style="margin-left: 10px;">Upload now</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Admission Number:</th>
                        <td><strong><?php echo esc_html($student->admission_number); ?></strong></td>
                    </tr>
                    <tr style="background: #e8f5e9;">
                        <th>Admission Date:</th>
                        <td>
                            <strong style="color: #2E7D32;">
                                <?php echo !empty($student->enrollment_date) ? date('F j, Y', strtotime($student->enrollment_date)) : 'Not set'; ?>
                            </strong>
                        </td>
                    </tr>
                    <tr>
                        <th>Name:</th>
                        <td><?php echo esc_html($student->display_name); ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?php echo esc_html($student->user_email); ?></td>
                    </tr>
                    <tr>
                        <th>ID Number:</th>
                        <td><?php echo esc_html($student->id_number); ?></td>
                    </tr>
                    <tr>
                        <th>Date of Birth:</th>
                        <td><?php echo $student->date_of_birth ? date('F j, Y', strtotime($student->date_of_birth)) : 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <th>Gender:</th>
                        <td><?php echo esc_html($student->gender); ?></td>
                    </tr>
                    <tr>
                        <th>County:</th>
                        <td><?php echo esc_html($student->county); ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($student->status); ?>">
                            <?php echo esc_html($student->status); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <th>Enrolled Courses:</th>
                        <td>
                            <?php if (!empty($enrolled_courses)) : ?>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <?php foreach ($enrolled_courses as $ec) : ?>
                                <span style="background: #e8f4fd; padding: 5px 12px; border-radius: 20px; border: 1px solid #2271b1; color: #2271b1; font-size: 13px;">
                                    📚 <?php echo esc_html($ec->course_name); ?>
                                    <small style="color: #666;">(<?php echo esc_html($ec->course_code); ?>)</small>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin-top: 10px; color: #666; font-size: 12px;">
                                Total: <?php echo count($enrolled_courses); ?> active course(s)
                            </p>
                            <?php else : ?>
                            <span style="color: #999;">No active course enrollments</span>
                            <?php endif; ?>
                            
                            <?php if (!empty($dropped_courses)) : ?>
                            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ccc;">
                                <p style="color: #e65100; font-size: 12px; margin-bottom: 8px;"><strong>📤 Dropped Courses:</strong></p>
                                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($dropped_courses as $dc) : ?>
                                <span style="background: #fff3e0; padding: 5px 12px; border-radius: 20px; border: 1px solid #ff9800; color: #e65100; font-size: 12px; text-decoration: line-through;">
                                    <?php echo esc_html($dc->course_name); ?>
                                    <small>(<?php echo esc_html($dc->course_code); ?>)</small>
                                </span>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2>Enrollments</h2>
                <?php if ($enrollments) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Enrollment Date</th>
                            <th>Status</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $enrollment) : ?>
                        <tr>
                            <td><?php echo esc_html($enrollment->course_name); ?></td>
                            <td><?php echo date('M j, Y', strtotime($enrollment->enrollment_date)); ?></td>
                            <td><span class="mtti-status mtti-status-<?php echo strtolower($enrollment->status); ?>">
                                <?php echo esc_html($enrollment->status); ?>
                            </span></td>
                            <td><?php echo esc_html($enrollment->final_grade ?: 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p>No enrollments found.</p>
                <?php endif; ?>

                <h2>Fee Balances</h2>
                <?php if ($balances) : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Total Fee</th>
                            <th>Discount</th>
                            <th>Total Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_balance = 0;
                        $grand_total_fee = 0;
                        $grand_total_paid = 0;
                        $grand_total_discount = 0;
                        foreach ($balances as $balance) : 
                            $total_balance += $balance->balance;
                            $grand_total_fee += $balance->total_fee;
                            $grand_total_paid += $balance->total_paid;
                            $discount = isset($balance->discount_amount) ? $balance->discount_amount : 0;
                            $grand_total_discount += $discount;
                            $status = $balance->balance == 0 ? 'Fully Paid' : ($balance->total_paid > 0 ? 'Partial Payment' : 'No Payment');
                        ?>
                        <tr>
                            <td><?php echo esc_html($balance->course_name); ?></td>
                            <td>KES <?php echo number_format($balance->total_fee, 2); ?></td>
                            <td style="color: #2E7D32;"><?php echo $discount > 0 ? 'KES ' . number_format($discount, 2) : '-'; ?></td>
                            <td style="color: #1976D2;">KES <?php echo number_format($balance->total_paid, 2); ?></td>
                            <td><strong style="color: <?php echo $balance->balance == 0 ? '#2E7D32' : '#D32F2F'; ?>">KES <?php echo number_format($balance->balance, 2); ?></strong></td>
                            <td><span class="mtti-status mtti-status-<?php echo $balance->balance == 0 ? 'completed' : 'pending'; ?>">
                                <?php echo $status; ?>
                            </span></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background: #f5f5f5; font-weight: bold;">
                            <td><strong>TOTALS:</strong></td>
                            <td>KES <?php echo number_format($grand_total_fee, 2); ?></td>
                            <td style="color: #2E7D32;">KES <?php echo number_format($grand_total_discount, 2); ?></td>
                            <td style="color: #1976D2;">KES <?php echo number_format($grand_total_paid, 2); ?></td>
                            <td><strong style="color: <?php echo $total_balance == 0 ? '#2E7D32' : '#D32F2F'; ?>">KES <?php echo number_format($total_balance, 2); ?></strong></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                <?php else : ?>
                <p>No balance information available.</p>
                <?php endif; ?>

                <!-- Payment History with Running Total -->
                <h2>Payment History (Running Total)</h2>
                <?php if ($payments) : 
                    // Sort payments by date ascending for running total
                    usort($payments, function($a, $b) {
                        return strtotime($a->payment_date) - strtotime($b->payment_date);
                    });
                    $running_total = 0;
                ?>
                <div style="background: #e3f2fd; border: 1px solid #1976D2; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                        <div>
                            <strong style="font-size: 16px;">💰 Total Fees Paid:</strong>
                            <span style="font-size: 24px; font-weight: bold; color: #1976D2; margin-left: 10px;">
                                KES <?php 
                                    $total_payments = 0;
                                    foreach ($payments as $p) {
                                        if ($p->status == 'Completed') {
                                            $total_payments += $p->amount;
                                        }
                                    }
                                    echo number_format($total_payments, 2);
                                ?>
                            </span>
                        </div>
                        <div style="text-align: right;">
                            <span style="color: #666; font-size: 12px;">Total Transactions: <?php echo count($payments); ?></span>
                        </div>
                    </div>
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Receipt No</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th style="background: #e8f5e9;"><strong>Running Total</strong></th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $count = 0;
                        foreach ($payments as $payment) : 
                            $count++;
                            if ($payment->status == 'Completed') {
                                $running_total += $payment->amount;
                            }
                        ?>
                        <tr>
                            <td><?php echo $count; ?></td>
                            <td><strong><?php echo esc_html($payment->receipt_number); ?></strong></td>
                            <td><?php echo date('M j, Y', strtotime($payment->payment_date)); ?></td>
                            <td><?php echo esc_html($payment->payment_method); ?></td>
                            <td style="font-weight: bold;">KES <?php echo number_format($payment->amount, 2); ?></td>
                            <td style="background: #e8f5e9; font-weight: bold; color: #2E7D32;">
                                KES <?php echo number_format($running_total, 2); ?>
                            </td>
                            <td>
                                <span class="mtti-status mtti-status-<?php echo strtolower($payment->status); ?>">
                                    <?php echo esc_html($payment->status); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=receipt&id=' . $payment->payment_id); ?>" 
                                   target="_blank" class="button button-small" title="Print Receipt">
                                    <span class="dashicons dashicons-media-text"></span>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot style="background: #f5f5f5;">
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong>GRAND TOTAL PAID:</strong></td>
                            <td colspan="2" style="background: #c8e6c9;"><strong style="font-size: 16px; color: #2E7D32;">KES <?php echo number_format($running_total, 2); ?></strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                <p style="margin-top: 10px;">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=add&student_id=' . $student_id); ?>" class="button button-primary">
                        <span class="dashicons dashicons-plus" style="vertical-align: middle;"></span> Record New Payment
                    </a>
                </p>
                <?php else : ?>
                <p>No payments recorded yet.</p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=add&student_id=' . $student_id); ?>" class="button button-primary">
                        <span class="dashicons dashicons-plus" style="vertical-align: middle;"></span> Record First Payment
                    </a>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function handle_form_submission() {
        global $wpdb;
        $action = $_POST['action'];

        if ($action == 'add') {
            // Generate password
            $password = wp_generate_password(12, false);
            
            // Handle email - generate placeholder if not provided
            $email = sanitize_email($_POST['email']);
            if (empty($email)) {
                // Generate a placeholder email using name and timestamp
                $first_name = sanitize_text_field($_POST['first_name']);
                $last_name = sanitize_text_field($_POST['last_name']);
                $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $first_name . $last_name));
                $username = $username . '_' . time();
                $email = $username . '@student.mtti.local';
            }
            
            // Create username from email or generate one
            $user_login = strpos($email, '@student.mtti.local') !== false 
                ? str_replace('@student.mtti.local', '', $email) 
                : $email;
            
            // Create WordPress user first
            $user_data = array(
                'user_login' => $user_login,
                'user_email' => $email,
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'user_pass' => $password,
                'role' => 'mtti_student'
            );

            // Suppress WordPress default new user notification to avoid duplicate emails
            // (we send our own custom welcome email below with MTTI-formatted credentials)
            add_filter('wp_send_new_user_notification_to_user', '__return_false');
            add_filter('wp_send_new_user_notification_to_admin', '__return_false');

            $user_id = wp_insert_user($user_data);

            // Re-enable notifications for other users created elsewhere
            remove_filter('wp_send_new_user_notification_to_user', '__return_false');
            remove_filter('wp_send_new_user_notification_to_admin', '__return_false');

            if (is_wp_error($user_id)) {
                wp_die('Error creating user: ' . $user_id->get_error_message());
            }

            // Add phone to user meta
            if (!empty($_POST['phone'])) {
                update_user_meta($user_id, 'phone', sanitize_text_field($_POST['phone']));
            }

            // Get primary course ID
            $primary_course_id = intval($_POST['primary_course']);
            
            // Get additional courses
            $additional_courses = isset($_POST['additional_courses']) ? array_map('intval', $_POST['additional_courses']) : array();
            
            // Combine all courses (primary + additional), removing duplicates
            $all_course_ids = array_unique(array_merge(array($primary_course_id), $additional_courses));
            
            // Get discount amount
            $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;

            // Get admission date from form or use current date as fallback
            $admission_date = !empty($_POST['admission_date']) ? sanitize_text_field($_POST['admission_date']) : current_time('Y-m-d');
            $admission_datetime = $admission_date . ' ' . current_time('H:i:s');

            // Create student record with primary course_id
            $student_data = array(
                'user_id' => $user_id,
                'admission_number' => '', // Will be generated
                'course_id' => $primary_course_id, // Primary course assignment
                'id_number' => sanitize_text_field($_POST['id_number']),
                'date_of_birth' => sanitize_text_field($_POST['date_of_birth']),
                'gender' => sanitize_text_field($_POST['gender']),
                'address' => sanitize_textarea_field($_POST['address']),
                'county' => sanitize_text_field($_POST['county']),
                'emergency_contact' => sanitize_text_field($_POST['emergency_contact']),
                'emergency_phone' => sanitize_text_field($_POST['emergency_phone']),
                'enrollment_date' => $admission_datetime,
                'status' => 'Active',
                'photo_url' => !empty($_POST['photo_url']) ? esc_url_raw($_POST['photo_url']) : null
            );

            // Generate course-based admission number
            $student_data['admission_number'] = $this->db->generate_admission_number($primary_course_id);

            $student_id = $this->db->create_student($student_data);
            
            // ============================================
            // CREATE ENROLLMENTS FOR ALL SELECTED COURSES
            // ============================================
            $enrollments_table = $wpdb->prefix . 'mtti_enrollments';
            $balances_table = $wpdb->prefix . 'mtti_student_balances';
            $courses_table = $wpdb->prefix . 'mtti_courses';
            $units_table = $wpdb->prefix . 'mtti_course_units';
            $unit_enrollments_table = $wpdb->prefix . 'mtti_unit_enrollments';
            
            // Check if discount_amount column exists
            $column_check = $wpdb->get_results("SHOW COLUMNS FROM {$enrollments_table} LIKE 'discount_amount'");
            $has_discount_column = !empty($column_check);
            
            // Calculate discount per course (distribute evenly or apply to first course)
            $discount_per_course = count($all_course_ids) > 0 ? $discount_amount / count($all_course_ids) : 0;
            
            foreach ($all_course_ids as $course_id) {
                if (!$course_id) continue;
                
                // Get course info for fee
                $course_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$courses_table} WHERE course_id = %d",
                    $course_id
                ));
                if (!$course_info) continue;
                
                // Create enrollment record for each course using the selected admission date
                $enrollment_data = array(
                    'student_id' => $student_id,
                    'course_id' => $course_id,
                    'enrollment_date' => $admission_date,
                    'status' => 'Active'
                );
                
                // Only add discount_amount if the column exists
                if ($has_discount_column) {
                    $enrollment_data['discount_amount'] = $discount_per_course;
                }
                
                $wpdb->insert($enrollments_table, $enrollment_data);
                $new_enrollment_id = $wpdb->insert_id;
                
                // Create student balance record for this enrollment
                if ($new_enrollment_id) {
                    $course_fee = floatval($course_info->fee);
                    $disc = floatval($discount_per_course);
                    $balance = max(0, $course_fee - $disc);
                    
                    $wpdb->insert(
                        $balances_table,
                        array(
                            'student_id' => $student_id,
                            'enrollment_id' => $new_enrollment_id,
                            'total_fee' => $course_fee,
                            'discount_amount' => $disc,
                            'total_paid' => 0,
                            'balance' => $balance,
                            'updated_at' => current_time('mysql')
                        ),
                        array('%d', '%d', '%f', '%f', '%f', '%f', '%s')
                    );

                    // Auto-create a discount payment record so it appears in payment history
                    if ($disc > 0) {
                        $payments_table_d = $wpdb->prefix . 'mtti_payments';
                        $last_receipt_d = $wpdb->get_var("SELECT receipt_number FROM {$payments_table_d} ORDER BY payment_id DESC LIMIT 1");
                        if ($last_receipt_d && preg_match('/(\d+)$/', $last_receipt_d, $m)) {
                            $next_d = intval($m[1]) + 1;
                        } else {
                            $next_d = 1001;
                        }
                        $receipt_d = 'DISC-' . str_pad($next_d, 6, '0', STR_PAD_LEFT);
                        $wpdb->insert(
                            $payments_table_d,
                            array(
                                'student_id'            => $student_id,
                                'enrollment_id'         => $new_enrollment_id,
                                'receipt_number'        => $receipt_d,
                                'gross_amount'          => $course_fee,
                                'discount'              => $disc,
                                'amount'                => 0,           // No cash collected — it's a discount
                                'payment_method'        => 'Discount',
                                'transaction_reference' => '',
                                'payment_date'          => current_time('Y-m-d'),
                                'payment_for'           => 'Discount/Scholarship — ' . esc_html($course_info->course_name),
                                'status'                => 'Completed',
                                'received_by'           => get_current_user_id(),
                                'notes'                 => 'Discount applied at admission. Gross fee: KES ' . number_format($course_fee, 2) . '. Discount: KES ' . number_format($disc, 2) . '.',
                                'created_at'            => current_time('mysql')
                            ),
                            array('%d','%d','%s','%f','%f','%f','%s','%s','%s','%s','%s','%d','%s','%s')
                        );
                    }
                }
                
                // Get all active units for this course
                $units = $wpdb->get_results($wpdb->prepare(
                    "SELECT unit_id FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
                    $course_id
                ));
                
                // Enroll student in each unit (for marks entry) using the admission date
                foreach ($units as $unit) {
                    $wpdb->insert($unit_enrollments_table, array(
                        'unit_id' => $unit->unit_id,
                        'student_id' => $student_id,
                        'enrollment_date' => $admission_date,
                        'status' => 'Active'
                    ));
                }
            }
            
            // Try to send welcome email with credentials (optional - won't fail if mail is disabled)
            try {
                $email_sent = $this->send_welcome_email(
                    $user_data['user_email'],
                    $user_data['user_login'],
                    $password,
                    $user_data['first_name'],
                    $student_data['admission_number']
                );
                
                $redirect_message = $email_sent ? 'created' : 'created_no_email';
            } catch (Exception $e) {
                // Email failed but student was created successfully
                error_log('MTTI MIS: Failed to send welcome email - ' . $e->getMessage());
                $redirect_message = 'created_no_email';
            }

            // Try normal redirect first
            $redirect_url = admin_url('admin.php?page=mtti-mis-students&action=view&id=' . $student_id . '&message=' . $redirect_message . '&admission=' . $student_data['admission_number'] . '&courses=' . count($all_course_ids));
            
            if (!headers_sent()) {
                wp_redirect($redirect_url);
                exit;
            } else {
                // Fallback to JavaScript redirect if headers already sent
                echo '<script type="text/javascript">window.location.href="' . esc_url($redirect_url) . '";</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($redirect_url) . '"></noscript>';
                exit;
            }

        } elseif ($action == 'edit') {
            $student_id = intval($_POST['student_id']);
            $old_course_id = intval($_POST['old_course_id'] ?? 0);
            $new_course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
            
            $student_data = array(
                'user_id' => !empty($_POST['user_id']) ? intval($_POST['user_id']) : null,
                'course_id' => $new_course_id,
                'id_number' => sanitize_text_field($_POST['id_number']),
                'date_of_birth' => sanitize_text_field($_POST['date_of_birth']),
                'gender' => sanitize_text_field($_POST['gender']),
                'address' => sanitize_textarea_field($_POST['address']),
                'county' => sanitize_text_field($_POST['county']),
                'emergency_contact' => sanitize_text_field($_POST['emergency_contact']),
                'emergency_phone' => sanitize_text_field($_POST['emergency_phone']),
                'status' => sanitize_text_field($_POST['status']),
                'photo_url' => isset($_POST['photo_url']) ? esc_url_raw($_POST['photo_url']) : null
            );
            
            // Add admission date if provided
            if (!empty($_POST['admission_date'])) {
                $admission_date = sanitize_text_field($_POST['admission_date']);
                $student_data['enrollment_date'] = $admission_date . ' 00:00:00';
            }
            
            // ============================================
            // REGENERATE ADMISSION NUMBER IF PRIMARY COURSE CHANGED
            // ============================================
            $admission_changed = false;
            $new_admission_number = '';
            $old_admission_number = '';
            
            // Check if admission number needs to be regenerated
            // Either: primary course changed, OR admission number prefix doesn't match current course
            $should_regenerate_admission = false;
            
            if ($new_course_id) {
                // Get current student and course info
                $current_student = $this->db->get_student($student_id);
                $old_admission_number = $current_student ? $current_student->admission_number : '';
                
                // Get the course code for the new/current primary course
                $course_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT course_code FROM {$wpdb->prefix}mtti_courses WHERE course_id = %d",
                    $new_course_id
                ));
                
                if ($course_info) {
                    $expected_prefix = strtoupper(substr($course_info->course_code, 0, 3));
                    $current_prefix = strtoupper(substr($old_admission_number, 0, 3));
                    
                    // Regenerate if:
                    // 1. Primary course changed, OR
                    // 2. Current admission number prefix doesn't match the primary course code
                    if ($new_course_id != $old_course_id || $expected_prefix != $current_prefix) {
                        $should_regenerate_admission = true;
                    }
                }
            }
            
            if ($should_regenerate_admission) {
                // Generate new admission number
                $new_admission_number = $this->db->generate_admission_number($new_course_id);
                $student_data['admission_number'] = $new_admission_number;
                $admission_changed = true;
            }

            $this->db->update_student($student_id, $student_data);
            
            // ============================================
            // UPDATE ENROLLMENT DATES FOR EXISTING ENROLLMENTS
            // ============================================
            $enrollments_table_update = $wpdb->prefix . 'mtti_enrollments';
            
            // Handle individual enrollment date updates
            if (!empty($_POST['enrollment_dates']) && is_array($_POST['enrollment_dates'])) {
                foreach ($_POST['enrollment_dates'] as $enrollment_id => $enrollment_date) {
                    $enrollment_id = intval($enrollment_id);
                    $enrollment_date = sanitize_text_field($enrollment_date);
                    
                    if ($enrollment_id > 0 && !empty($enrollment_date)) {
                        $wpdb->update(
                            $enrollments_table_update,
                            array(
                                'enrollment_date' => $enrollment_date
                            ),
                            array(
                                'enrollment_id' => $enrollment_id,
                                'student_id' => $student_id
                            ),
                            array('%s'),
                            array('%d', '%d')
                        );
                    }
                }
            }
            
            // ============================================
            // UPDATE WORDPRESS USER (Email, Name, Phone)
            // ============================================
            $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : null;
            
            // If no user_id in form, get from student record
            if (!$user_id) {
                $student = $this->db->get_student($student_id);
                $user_id = $student ? $student->user_id : null;
            }
            
            if ($user_id) {
                $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
                $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
                $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
                $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
                
                // Prepare user data for update
                $user_update_data = array(
                    'ID' => $user_id
                );
                
                if (!empty($first_name)) {
                    $user_update_data['first_name'] = $first_name;
                }
                
                if (!empty($last_name)) {
                    $user_update_data['last_name'] = $last_name;
                }
                
                // Update display name
                if (!empty($first_name) || !empty($last_name)) {
                    $user_update_data['display_name'] = trim($first_name . ' ' . $last_name);
                }
                
                // Update email if provided and valid
                if (!empty($email)) {
                    // Check if email is already used by another user
                    $existing_user = get_user_by('email', $email);
                    if (!$existing_user || $existing_user->ID == $user_id) {
                        $user_update_data['user_email'] = $email;
                    }
                }
                
                // Update the WordPress user
                if (count($user_update_data) > 1) {
                    wp_update_user($user_update_data);
                }
                
                // Update phone in user meta
                if (!empty($phone)) {
                    update_user_meta($user_id, 'phone', $phone);
                }
            }
            
            // ============================================
            // HANDLE ADDITIONAL COURSE ENROLLMENTS
            // ============================================
            $additional_courses = isset($_POST['additional_courses']) ? array_map('intval', $_POST['additional_courses']) : array();
            
            // Include primary course in the list
            if ($new_course_id && !in_array($new_course_id, $additional_courses)) {
                $additional_courses[] = $new_course_id;
            }
            
            // Get current enrollments
            $enrollments_table = $wpdb->prefix . 'mtti_enrollments';
            $courses_table = $wpdb->prefix . 'mtti_courses';
            
            // Get ALL enrollments (including dropped) with their status
            $all_enrollments = $wpdb->get_results($wpdb->prepare(
                "SELECT course_id, enrollment_id, status FROM {$enrollments_table} WHERE student_id = %d",
                $student_id
            ));
            
            // Build arrays for active and all enrolled course IDs
            $active_enrollments = array();
            $all_enrollment_course_ids = array();
            $enrollment_by_course = array();
            
            foreach ($all_enrollments as $enroll) {
                $all_enrollment_course_ids[] = $enroll->course_id;
                $enrollment_by_course[$enroll->course_id] = $enroll;
                if (in_array($enroll->status, array('Active', 'Enrolled', 'In Progress'))) {
                    $active_enrollments[] = $enroll->course_id;
                }
            }
            
            // Track newly created enrollments for payment
            $new_enrollment_ids = array();
            
            // Enroll in new courses OR reactivate dropped courses
            foreach ($additional_courses as $course_id) {
                // Check if course was previously enrolled (could be dropped)
                if (in_array($course_id, $all_enrollment_course_ids)) {
                    // Check if it's dropped - if so, reactivate it
                    $existing = $enrollment_by_course[$course_id];
                    if ($existing->status == 'Dropped') {
                        // Reactivate the enrollment
                        $wpdb->update(
                            $enrollments_table,
                            array('status' => 'Active'),
                            array('enrollment_id' => $existing->enrollment_id),
                            array('%s'),
                            array('%d')
                        );
                        
                        // Also reactivate unit enrollments
                        $units_table = $wpdb->prefix . 'mtti_course_units';
                        $unit_enrollments_table = $wpdb->prefix . 'mtti_unit_enrollments';
                        
                        $course_units = $wpdb->get_col($wpdb->prepare(
                            "SELECT unit_id FROM {$units_table} WHERE course_id = %d",
                            $course_id
                        ));
                        
                        if (!empty($course_units)) {
                            foreach ($course_units as $unit_id) {
                                $wpdb->update(
                                    $unit_enrollments_table,
                                    array('status' => 'Active'),
                                    array('unit_id' => $unit_id, 'student_id' => $student_id),
                                    array('%s'),
                                    array('%d', '%d')
                                );
                            }
                        }
                    }
                    // If already active, do nothing
                } else {
                    // Brand new enrollment
                    // Get course info
                    $course = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$courses_table} WHERE course_id = %d",
                        $course_id
                    ));
                    
                    if ($course) {
                        // Create new enrollment (fee is stored in student_balances, not enrollments)
                        $wpdb->insert(
                            $enrollments_table,
                            array(
                                'student_id' => $student_id,
                                'course_id' => $course_id,
                                'enrollment_date' => current_time('Y-m-d'),
                                'status' => 'Active'
                            ),
                            array('%d', '%d', '%s', '%s')
                        );
                        
                        $enrollment_id = $wpdb->insert_id;
                        
                        // Track this new enrollment for payment processing
                        $new_enrollment_ids[] = $enrollment_id;
                        
                        // Auto-enroll in course units
                        $units_table = $wpdb->prefix . 'mtti_course_units';
                        $unit_enrollments_table = $wpdb->prefix . 'mtti_unit_enrollments';
                        
                        $units = $wpdb->get_results($wpdb->prepare(
                            "SELECT unit_id FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
                            $course_id
                        ));
                        
                        foreach ($units as $unit) {
                            $exists = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$unit_enrollments_table} WHERE unit_id = %d AND student_id = %d",
                                $unit->unit_id, $student_id
                            ));
                            
                            if (!$exists) {
                                $wpdb->insert($unit_enrollments_table, array(
                                    'unit_id' => $unit->unit_id,
                                    'student_id' => $student_id,
                                    'enrollment_date' => current_time('Y-m-d'),
                                    'status' => 'Active'
                                ));
                            }
                        }
                        
                        // Create student balance record for new enrollment
                        $balances_table = $wpdb->prefix . 'mtti_student_balances';
                        $balance_exists = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$balances_table} WHERE enrollment_id = %d",
                            $enrollment_id
                        ));
                        
                        if (!$balance_exists) {
                            $wpdb->insert(
                                $balances_table,
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
                        }
                    }
                }
            }
            
            // ============================================
            // DEACTIVATE REMOVED COURSE ENROLLMENTS
            // ============================================
            // Find courses that were ACTIVE but are no longer in the selected list
            $courses_to_deactivate = array_diff($active_enrollments, $additional_courses);
            
            if (!empty($courses_to_deactivate)) {
                $unit_enrollments_table = $wpdb->prefix . 'mtti_unit_enrollments';
                $units_table = $wpdb->prefix . 'mtti_course_units';
                
                foreach ($courses_to_deactivate as $course_id) {
                    // Update enrollment status to 'Dropped' instead of deleting
                    // This preserves history and any associated payments/grades
                    $wpdb->update(
                        $enrollments_table,
                        array(
                            'status' => 'Dropped',
                            'updated_at' => current_time('mysql')
                        ),
                        array(
                            'student_id' => $student_id,
                            'course_id' => $course_id
                        ),
                        array('%s', '%s'),
                        array('%d', '%d')
                    );
                    
                    // Also deactivate unit enrollments for the dropped course
                    $course_units = $wpdb->get_col($wpdb->prepare(
                        "SELECT unit_id FROM {$units_table} WHERE course_id = %d",
                        $course_id
                    ));
                    
                    if (!empty($course_units)) {
                        $unit_ids_placeholder = implode(',', array_fill(0, count($course_units), '%d'));
                        $query = $wpdb->prepare(
                            "UPDATE {$unit_enrollments_table} 
                             SET status = 'Dropped' 
                             WHERE student_id = %d AND unit_id IN ($unit_ids_placeholder)",
                            array_merge(array($student_id), $course_units)
                        );
                        $wpdb->query($query);
                    }
                }
            }
            
            // ============================================
            // AUTO-ENROLL IN NEW COURSE UNITS (if primary course changed)
            // ============================================
            if ($new_course_id && $new_course_id != $old_course_id) {
                $units_table = $wpdb->prefix . 'mtti_course_units';
                $unit_enrollments_table = $wpdb->prefix . 'mtti_unit_enrollments';
                
                // Get all active units for the new course
                $units = $wpdb->get_results($wpdb->prepare(
                    "SELECT unit_id FROM {$units_table} WHERE course_id = %d AND status = 'Active'",
                    $new_course_id
                ));
                
                // Enroll student in each unit (ignore duplicates)
                foreach ($units as $unit) {
                    // Check if already enrolled
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$unit_enrollments_table} WHERE unit_id = %d AND student_id = %d",
                        $unit->unit_id, $student_id
                    ));
                    
                    if (!$exists) {
                        $wpdb->insert($unit_enrollments_table, array(
                            'unit_id' => $unit->unit_id,
                            'student_id' => $student_id,
                            'enrollment_date' => current_time('Y-m-d'),
                            'status' => 'Active'
                        ));
                    }
                }
            }
            
            // ============================================
            // PROCESS PAYMENT FOR NEW COURSE ENROLLMENTS
            // ============================================
            $new_course_payment = isset($_POST['new_course_payment']) ? floatval($_POST['new_course_payment']) : 0;
            
            if ($new_course_payment > 0 && !empty($new_enrollment_ids)) {
                $payments_table = $wpdb->prefix . 'mtti_payments';
                $balances_table = $wpdb->prefix . 'mtti_student_balances';
                $payment_method = isset($_POST['new_course_payment_method']) ? sanitize_text_field($_POST['new_course_payment_method']) : 'Cash';
                $transaction_ref = isset($_POST['new_course_transaction_ref']) ? sanitize_text_field($_POST['new_course_transaction_ref']) : '';
                
                // Generate receipt number
                $receipt_prefix = 'MTTI';
                $last_receipt = $wpdb->get_var("SELECT receipt_number FROM {$payments_table} ORDER BY payment_id DESC LIMIT 1");
                if ($last_receipt && preg_match('/(\d+)$/', $last_receipt, $matches)) {
                    $next_num = intval($matches[1]) + 1;
                } else {
                    $next_num = 1001;
                }
                $receipt_number = $receipt_prefix . '-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
                
                // Calculate payment distribution across new enrollments
                $num_new_enrollments = count($new_enrollment_ids);
                $payment_per_enrollment = $new_course_payment / $num_new_enrollments;
                
                // Apply payment to first new enrollment (or distribute if preferred)
                $first_enrollment_id = $new_enrollment_ids[0];
                
                // Create payment record
                $wpdb->insert(
                    $payments_table,
                    array(
                        'student_id' => $student_id,
                        'enrollment_id' => $first_enrollment_id,
                        'receipt_number' => $receipt_number,
                        'amount' => $new_course_payment,
                        'gross_amount' => $new_course_payment,
                        'discount' => 0,
                        'payment_method' => $payment_method,
                        'transaction_reference' => $transaction_ref,
                        'payment_date' => current_time('Y-m-d'),
                        'payment_for' => 'Course Fee - New Enrollment',
                        'status' => 'Completed',
                        'received_by' => get_current_user_id(),
                        'notes' => 'Payment for new course enrollment(s) added during student edit',
                        'created_at' => current_time('mysql')
                    ),
                    array('%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
                );
                
                $payment_id = $wpdb->insert_id;
                
                // Update balance for the first enrollment
                if ($payment_id) {
                    $balance = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$balances_table} WHERE enrollment_id = %d",
                        $first_enrollment_id
                    ));
                    
                    if ($balance) {
                        $new_total_paid = $balance->total_paid + $new_course_payment;
                        $new_balance = $balance->total_fee - $balance->discount_amount - $new_total_paid;
                        if ($new_balance < 0) $new_balance = 0;
                        
                        $wpdb->update(
                            $balances_table,
                            array(
                                'total_paid' => $new_total_paid,
                                'balance' => $new_balance,
                                'updated_at' => current_time('mysql')
                            ),
                            array('enrollment_id' => $first_enrollment_id),
                            array('%f', '%f', '%s'),
                            array('%d')
                        );
                    }
                }
            }

            // Build redirect URL with appropriate message
            $redirect_url = admin_url('admin.php?page=mtti-mis-students&action=view&id=' . $student_id . '&message=updated');
            
            // Add admission number change notification if applicable
            if ($admission_changed) {
                $redirect_url .= '&admission_changed=1&old_admission=' . urlencode($old_admission_number) . '&new_admission=' . urlencode($new_admission_number);
            }
            
            if (!headers_sent()) {
                wp_redirect($redirect_url);
                exit;
            } else {
                echo '<script type="text/javascript">window.location.href="' . esc_url($redirect_url) . '";</script>';
                echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($redirect_url) . '"></noscript>';
                exit;
            }
        }
    }

    /**
     * Display printable Student ID Card (credit card size: 85.6mm × 53.98mm)
     */
    private function display_id_card($student_id) {
        $student = $this->db->get_student($student_id);
        if (!$student) {
            wp_die('Student not found');
        }

        // Get primary course
        $course = null;
        if ($student->course_id) {
            $course = $this->db->get_course($student->course_id);
        }

        $settings = get_option('mtti_mis_settings', array());
        $institute_name = isset($settings['institute_name']) ? $settings['institute_name'] : 'Masomotele Technical Training Institute';
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        $photo_url = !empty($student->photo_url) ? $student->photo_url : '';
        $admission_date = !empty($student->enrollment_date) ? date('d/m/Y', strtotime($student->enrollment_date)) : 'N/A';
        $course_name = $course ? $course->course_name : 'N/A';
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Student ID Card - <?php echo esc_html($student->display_name); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                
                @page {
                    size: auto;
                    margin: 10mm;
                }
                
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: #f5f5f5;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 20px;
                }
                
                .print-controls {
                    margin-bottom: 20px;
                    text-align: center;
                }
                
                .print-controls button {
                    padding: 10px 30px;
                    font-size: 16px;
                    cursor: pointer;
                    border: none;
                    border-radius: 4px;
                    margin: 0 5px;
                }
                
                .btn-print {
                    background: #1976D2;
                    color: white;
                }
                
                .btn-back {
                    background: #666;
                    color: white;
                }
                
                .cards-container {
                    display: flex;
                    flex-direction: column;
                    gap: 20px;
                    align-items: center;
                }
                
                /* ===== FRONT CARD ===== */
                .id-card {
                    width: 85.6mm;
                    height: 53.98mm;
                    border-radius: 3mm;
                    overflow: hidden;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                    position: relative;
                    background: white;
                }
                
                .card-front {
                    background: linear-gradient(135deg, #0a5e2a 0%, #0d7a35 40%, #10963f 100%);
                    color: white;
                    display: flex;
                    flex-direction: column;
                    height: 100%;
                }
                
                .card-front .card-header {
                    display: flex;
                    align-items: center;
                    padding: 2.5mm 3mm 1.5mm 3mm;
                    background: rgba(255,255,255,0.12);
                    border-bottom: 0.5mm solid rgba(255,255,255,0.2);
                }
                
                .card-front .card-header img.logo {
                    width: 9mm;
                    height: 9mm;
                    border-radius: 50%;
                    object-fit: cover;
                    border: 0.3mm solid rgba(255,255,255,0.5);
                    flex-shrink: 0;
                }
                
                .card-front .card-header .inst-info {
                    margin-left: 2mm;
                    flex: 1;
                }
                
                .card-front .card-header .inst-name {
                    font-size: 6.5pt;
                    font-weight: 700;
                    letter-spacing: 0.3px;
                    line-height: 1.2;
                    text-transform: uppercase;
                }
                
                .card-front .card-header .inst-tag {
                    font-size: 4.5pt;
                    opacity: 0.85;
                    font-style: italic;
                }
                
                .card-front .card-header .card-type {
                    background: #f5a623;
                    color: #000;
                    font-size: 5pt;
                    font-weight: 700;
                    padding: 1mm 2mm;
                    border-radius: 1mm;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    flex-shrink: 0;
                }
                
                .card-front .card-body {
                    display: flex;
                    flex: 1;
                    padding: 2mm 3mm 2mm 3mm;
                    gap: 3mm;
                }
                
                .card-front .photo-area {
                    width: 18mm;
                    height: 23mm;
                    border-radius: 1.5mm;
                    overflow: hidden;
                    border: 0.5mm solid rgba(255,255,255,0.4);
                    flex-shrink: 0;
                    background: rgba(255,255,255,0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .card-front .photo-area img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                }
                
                .card-front .photo-area .no-photo {
                    font-size: 5pt;
                    opacity: 0.6;
                    text-align: center;
                    padding: 2mm;
                }
                
                .card-front .details-area {
                    flex: 1;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    gap: 1mm;
                }
                
                .card-front .student-name {
                    font-size: 8pt;
                    font-weight: 700;
                    line-height: 1.2;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                    border-bottom: 0.3mm solid rgba(255,255,255,0.3);
                    padding-bottom: 1mm;
                    margin-bottom: 0.5mm;
                }
                
                .card-front .detail-row {
                    display: flex;
                    font-size: 5.5pt;
                    line-height: 1.4;
                }
                
                .card-front .detail-row .label {
                    color: rgba(255,255,255,0.7);
                    width: 15mm;
                    flex-shrink: 0;
                    font-weight: 600;
                }
                
                .card-front .detail-row .value {
                    font-weight: 500;
                    flex: 1;
                }
                
                .card-front .card-footer {
                    background: rgba(0,0,0,0.15);
                    padding: 1mm 3mm;
                    text-align: center;
                    font-size: 4.5pt;
                    font-style: italic;
                    letter-spacing: 0.5px;
                    color: rgba(255,255,255,0.8);
                }
                
                /* ===== BACK CARD ===== */
                .card-back {
                    background: white;
                    color: #333;
                    display: flex;
                    flex-direction: column;
                    height: 100%;
                    padding: 3mm;
                }
                
                .card-back .back-header {
                    text-align: center;
                    font-size: 6pt;
                    font-weight: 700;
                    color: #0a5e2a;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    padding-bottom: 1.5mm;
                    border-bottom: 0.3mm solid #0a5e2a;
                    margin-bottom: 2mm;
                }
                
                .card-back .terms {
                    font-size: 5pt;
                    line-height: 1.6;
                    flex: 1;
                    color: #555;
                }
                
                .card-back .terms p {
                    margin-bottom: 1mm;
                    padding-left: 2mm;
                    text-indent: -2mm;
                }
                
                .card-back .back-footer {
                    margin-top: 2mm;
                    padding-top: 1.5mm;
                    border-top: 0.3mm solid #ccc;
                    text-align: center;
                    font-size: 5pt;
                    color: #0a5e2a;
                }
                
                .card-back .back-footer .address {
                    font-size: 4.5pt;
                    color: #777;
                    margin-top: 0.5mm;
                }
                
                .card-back .signature-area {
                    display: flex;
                    justify-content: space-between;
                    margin-top: 2mm;
                    padding-top: 1.5mm;
                }
                
                .card-back .sig-block {
                    text-align: center;
                    font-size: 4.5pt;
                    color: #666;
                    width: 30mm;
                }
                
                .card-back .sig-block .sig-line {
                    border-top: 0.3mm solid #999;
                    margin-bottom: 0.5mm;
                    margin-top: 5mm;
                }
                
                .card-label {
                    text-align: center;
                    font-size: 10pt;
                    color: #666;
                    margin-bottom: 5px;
                    font-weight: 600;
                }
                
                @media print {
                    body {
                        background: white;
                        padding: 0;
                        margin: 0;
                    }
                    
                    .print-controls {
                        display: none !important;
                    }
                    
                    .card-label {
                        font-size: 8pt;
                        margin-bottom: 2mm;
                        color: #999;
                    }
                    
                    .cards-container {
                        gap: 8mm;
                    }
                    
                    .id-card {
                        box-shadow: none;
                        border: 0.3mm solid #ccc;
                    }
                }
            </style>
        </head>
        <body>
            <div class="print-controls">
                <button class="btn-print" onclick="window.print()">🖨️ Print ID Card</button>
                <button class="btn-back" onclick="window.close()">← Close</button>
                <p style="margin-top: 10px; font-size: 12px; color: #666;">
                    Tip: Set paper size to A4, enable "Background graphics" in print settings for best results.
                </p>
            </div>
            
            <div class="cards-container">
                <!-- FRONT SIDE -->
                <div>
                    <div class="card-label">— FRONT —</div>
                    <div class="id-card">
                        <div class="card-front">
                            <div class="card-header">
                                <img class="logo" src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo">
                                <div class="inst-info">
                                    <div class="inst-name"><?php echo esc_html($institute_name); ?></div>
                                    <div class="inst-tag">TVETA Accredited</div>
                                </div>
                                <div class="card-type">Student ID</div>
                            </div>
                            <div class="card-body">
                                <div class="photo-area">
                                    <?php if ($photo_url) : ?>
                                        <img src="<?php echo esc_url($photo_url); ?>" alt="Student Photo">
                                    <?php else : ?>
                                        <div class="no-photo">No Photo<br>Available</div>
                                    <?php endif; ?>
                                </div>
                                <div class="details-area">
                                    <div class="student-name"><?php echo esc_html($student->display_name); ?></div>
                                    <div class="detail-row">
                                        <span class="label">Adm No:</span>
                                        <span class="value"><?php echo esc_html($student->admission_number); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Course:</span>
                                        <span class="value"><?php echo esc_html($course_name); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Admitted:</span>
                                        <span class="value"><?php echo esc_html($admission_date); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                "Start Learning, Start Earning"
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- BACK SIDE -->
                <div>
                    <div class="card-label">— BACK —</div>
                    <div class="id-card">
                        <div class="card-back">
                            <div class="back-header">Student Identification Card</div>
                            <div class="terms">
                                <p>1. This card is the property of M.T.T.I and must be returned upon request.</p>
                                <p>2. The holder is a registered student of Masomotele Technical Training Institute.</p>
                                <p>3. If found, please return to the address below.</p>
                                <p>4. This card is non-transferable and must be presented on demand.</p>
                            </div>
                            <div class="signature-area">
                                <div class="sig-block">
                                    <div class="sig-line"></div>
                                    Student Signature
                                </div>
                                <div class="sig-block">
                                    <div class="sig-line"></div>
                                    Director / Stamp
                                </div>
                            </div>
                            <div class="back-footer">
                                <strong><?php echo esc_html($institute_name); ?></strong>
                                <div class="address">Sagaas Centre, 4th Floor, Eldoret | Tel: +254 799 497 739</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit; // Full HTML page, no WordPress chrome
    }
    
    private function delete_student($student_id) {
        global $wpdb;
        
        // Get student info first
        $student = $this->db->get_student($student_id);
        
        if (!$student) {
            wp_die('Student not found');
        }
        
        // Collect all related records for recovery
        $enrollments_table = $this->db->get_table_name('enrollments');
        $payments_table = $this->db->get_table_name('payments');
        $submissions_table = $this->db->get_table_name('assignment_submissions');
        $attendance_table = $this->db->get_table_name('attendance');
        $assessments_table = $this->db->get_table_name('assessments');
        $balances_table = $this->db->get_table_name('student_balances');
        $students_table = $this->db->get_table_name('students');
        
        $related = array();
        
        // Save related records for restoration
        $enrollments = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$enrollments_table} WHERE student_id = %d", $student_id), ARRAY_A);
        foreach ($enrollments as $e) $related[] = array('_table' => 'mtti_enrollments', '_data' => $e);
        
        $payments = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$payments_table} WHERE student_id = %d", $student_id), ARRAY_A);
        foreach ($payments as $p) $related[] = array('_table' => 'mtti_payments', '_data' => $p);
        
        $balances = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$balances_table} WHERE student_id = %d", $student_id), ARRAY_A);
        foreach ($balances as $b) $related[] = array('_table' => 'mtti_student_balances', '_data' => $b);
        
        // Get full student data as array
        $student_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$students_table} WHERE student_id = %d", $student_id), ARRAY_A);
        
        // Soft-delete: save to recycle bin
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
        MTTI_MIS_Admin_Trash::create_table();
        $trash = new MTTI_MIS_Admin_Trash();
        $label = ($student->display_name ?: 'Student') . ' (' . ($student->admission_number ?: $student_id) . ')';
        $trash->soft_delete('student', $student_id, $label, $student_data, $related);
        
        // Now delete from live tables
        $wpdb->delete($submissions_table, array('student_id' => $student_id));
        $wpdb->delete($attendance_table, array('student_id' => $student_id));
        $wpdb->delete($assessments_table, array('student_id' => $student_id));
        $wpdb->delete($payments_table, array('student_id' => $student_id));
        $wpdb->delete($balances_table, array('student_id' => $student_id));
        $wpdb->delete($enrollments_table, array('student_id' => $student_id));
        $wpdb->delete($students_table, array('student_id' => $student_id));
        
        // NOTE: WordPress user is NOT deleted — can be reassigned later if student is restored
        
        $redirect_url = admin_url('admin.php?page=mtti-mis-students&message=deleted');
        
        if (!headers_sent()) {
            wp_redirect($redirect_url);
            exit;
        } else {
            echo '<script type="text/javascript">window.location.href="' . esc_url($redirect_url) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($redirect_url) . '"></noscript>';
            exit;
        }
    }
    
    private function send_welcome_email($email, $username, $password, $first_name, $admission_number) {
        // Check if mail function is available
        if (!function_exists('wp_mail')) {
            return false;
        }
        
        $settings = get_option('mtti_mis_settings', array());
        $institute_name = isset($settings['institute_name']) ? $settings['institute_name'] : 'Masomotele Technical Training Institute';
        $login_url = wp_login_url();
        
        $subject = 'Welcome to ' . $institute_name . ' - Your Login Credentials';
        
        $message = "Dear $first_name,\n\n";
        $message .= "Welcome to $institute_name!\n\n";
        $message .= "Your student account has been created successfully. Here are your login credentials:\n\n";
        $message .= "Admission Number: $admission_number\n";
        $message .= "Username/Email: $username\n";
        $message .= "Password: $password\n\n";
        $message .= "Login URL: $login_url\n\n";
        $message .= "IMPORTANT: Please change your password after first login for security.\n\n";
        $message .= "After logging in, you can:\n";
        $message .= "- View your courses and enrollments\n";
        $message .= "- Check your fee balance\n";
        $message .= "- View and download payment receipts\n";
        $message .= "- Access assignments and submit work\n";
        $message .= "- View your exam results and grades\n";
        $message .= "- Join live online classes\n";
        $message .= "- Download your certificates and transcripts\n\n";
        $message .= "If you have any questions, please contact the administration office.\n\n";
        $message .= "\"Start Learning, Start Earning\"\n\n";
        $message .= "Best regards,\n";
        $message .= $institute_name;
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        // Try to send email, return true/false based on success
        try {
            return @wp_mail($email, $subject, $message, $headers);
        } catch (Exception $e) {
            error_log('MTTI MIS Email Error: ' . $e->getMessage());
            return false;
        }
    }
}
