<?php
/**
 * Live Classes Admin Class - Complete Implementation
 */
class MTTI_MIS_Admin_Live_Classes {
    
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        if (isset($_POST['mtti_class_submit'])) {
            check_admin_referer('mtti_class_action', 'mtti_class_nonce');
            $this->handle_form_submission();
            return;
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        if ($action == 'add') {
            $this->display_add_form();
        } elseif ($action == 'view') {
            $this->display_class_details(intval($_GET['id']));
        } else {
            $this->display_list();
        }
    }
    
    private function display_list() {
        $classes = $this->db->get_live_classes();
        ?>
        <div class="wrap">
            <h1>Live Online Classes <a href="?page=mtti-mis-live-classes&action=add" class="page-title-action">Schedule Class</a></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Course</th>
                        <th>Scheduled Date</th>
                        <th>Duration</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($class->title); ?></strong></td>
                        <td><?php echo esc_html($class->course_name); ?></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($class->scheduled_date)); ?></td>
                        <td><?php echo $class->duration_minutes; ?> min</td>
                        <td><?php echo esc_html($class->meeting_platform); ?></td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($class->status); ?>">
                            <?php echo $class->status; ?>
                        </span></td>
                        <td>
                            <a href="?page=mtti-mis-live-classes&action=view&id=<?php echo $class->class_id; ?>">View</a>
                            <?php if ($class->meeting_link) : ?>
                            | <a href="<?php echo esc_url($class->meeting_link); ?>" target="_blank">Join</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function display_add_form() {
        $courses = $this->db->get_courses(array('status' => 'Active'));
        ?>
        <div class="wrap">
            <h1>Schedule Live Class</h1>
            <form method="post">
                <?php wp_nonce_field('mtti_class_action', 'mtti_class_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label>Course *</label></th>
                        <td>
                            <select name="course_id" required class="regular-text">
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course) : ?>
                                <option value="<?php echo $course->course_id; ?>">
                                    <?php echo esc_html($course->course_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Class Title *</label></th>
                        <td><input type="text" name="title" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Description</label></th>
                        <td><textarea name="description" rows="4" class="large-text"></textarea></td>
                    </tr>
                    <tr>
                        <th><label>Platform *</label></th>
                        <td>
                            <select name="meeting_platform" required class="regular-text">
                                <option value="Zoom">Zoom</option>
                                <option value="Google Meet">Google Meet</option>
                                <option value="Microsoft Teams">Microsoft Teams</option>
                                <option value="Other">Other</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Meeting Link *</label></th>
                        <td><input type="url" name="meeting_link" required class="large-text" placeholder="https://"></td>
                    </tr>
                    <tr>
                        <th><label>Meeting ID</label></th>
                        <td><input type="text" name="meeting_id" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Password</label></th>
                        <td><input type="text" name="meeting_password" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Scheduled Date & Time *</label></th>
                        <td><input type="datetime-local" name="scheduled_date" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Duration (minutes) *</label></th>
                        <td><input type="number" name="duration_minutes" value="60" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>Batch Number</label></th>
                        <td><input type="text" name="batch_number" class="regular-text"></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="mtti_class_submit" class="button button-primary" value="Schedule Class">
                    <a href="?page=mtti-mis-live-classes" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function display_class_details($class_id) {
        global $wpdb;
        $table = $this->db->get_table_name('live_classes');
        $courses_table = $this->db->get_table_name('courses');
        
        $class = $wpdb->get_row($wpdb->prepare(
            "SELECT lc.*, c.course_name 
             FROM {$table} lc
             LEFT JOIN {$courses_table} c ON lc.course_id = c.course_id
             WHERE lc.class_id = %d",
            $class_id
        ));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($class->title); ?></h1>
            <table class="form-table">
                <tr>
                    <th>Course:</th>
                    <td><strong><?php echo esc_html($class->course_name); ?></strong></td>
                </tr>
                <tr>
                    <th>Scheduled:</th>
                    <td><?php echo date('F j, Y g:i A', strtotime($class->scheduled_date)); ?></td>
                </tr>
                <tr>
                    <th>Duration:</th>
                    <td><?php echo $class->duration_minutes; ?> minutes</td>
                </tr>
                <tr>
                    <th>Platform:</th>
                    <td><?php echo esc_html($class->meeting_platform); ?></td>
                </tr>
                <tr>
                    <th>Meeting Link:</th>
                    <td><a href="<?php echo esc_url($class->meeting_link); ?>" target="_blank" class="button button-primary">Join Class</a></td>
                </tr>
                <?php if ($class->meeting_id) : ?>
                <tr>
                    <th>Meeting ID:</th>
                    <td><code><?php echo esc_html($class->meeting_id); ?></code></td>
                </tr>
                <?php endif; ?>
                <?php if ($class->meeting_password) : ?>
                <tr>
                    <th>Password:</th>
                    <td><code><?php echo esc_html($class->meeting_password); ?></code></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Status:</th>
                    <td><span class="mtti-status mtti-status-<?php echo strtolower($class->status); ?>"><?php echo $class->status; ?></span></td>
                </tr>
            </table>
            <?php if ($class->description) : ?>
            <h2>Description</h2>
            <p><?php echo wpautop(esc_html($class->description)); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function handle_form_submission() {
        $data = array(
            'course_id' => intval($_POST['course_id']),
            'staff_id' => get_current_user_id(),
            'title' => sanitize_text_field($_POST['title']),
            'description' => sanitize_textarea_field($_POST['description']),
            'meeting_link' => esc_url_raw($_POST['meeting_link']),
            'meeting_platform' => sanitize_text_field($_POST['meeting_platform']),
            'meeting_id' => sanitize_text_field($_POST['meeting_id']),
            'meeting_password' => sanitize_text_field($_POST['meeting_password']),
            'scheduled_date' => sanitize_text_field($_POST['scheduled_date']),
            'duration_minutes' => intval($_POST['duration_minutes']),
            'batch_number' => sanitize_text_field($_POST['batch_number']),
            'status' => 'Scheduled'
        );
        
        $class_id = $this->db->create_live_class($data);
        
        wp_redirect(admin_url('admin.php?page=mtti-mis-live-classes&action=view&id=' . $class_id . '&message=created'));
        exit;
    }
}
