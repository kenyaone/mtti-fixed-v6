<?php
/**
 * MTTI MIS Learner Portal
 * @package MTTI_MIS
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MTTI_MIS_Learner_Portal {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_shortcode('mtti_learner_portal', array($this, 'render_portal'));
        add_shortcode('mtti_student_portal', array($this, 'render_portal'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Full-page mode: bypass theme entirely when ?mtti_portal or on the designated portal page
        add_action('template_redirect', array($this, 'maybe_render_fullpage'));
        
        add_action('wp_ajax_mtti_submit_assignment', array($this, 'ajax_submit_assignment'));
        add_action('wp_ajax_mtti_get_notifications', array($this, 'ajax_get_notifications'));
        add_action('wp_ajax_mtti_mark_notifications_read', array($this, 'ajax_mark_notifications_read'));

        add_action('wp_ajax_mtti_save_goal', array($this, 'ajax_save_goal'));
        add_action('wp_ajax_mtti_complete_goal', array($this, 'ajax_complete_goal'));
        add_action('wp_ajax_mtti_post_discussion', array($this, 'ajax_post_discussion'));
        add_action('wp_ajax_mtti_get_discussions', array($this, 'ajax_get_discussions'));
        add_action('wp_ajax_mtti_learner_mark_attendance', array($this, 'ajax_learner_mark_attendance'));
    }
    
    /**
     * Full-page portal mode — bypasses the WordPress theme completely.
     * 
     * Activates when:
     * 1. The page contains [mtti_learner_portal] or [mtti_student_portal] shortcode, OR
     * 2. The URL has ?mtti_portal query parameter
     * 
     * This eliminates all Astra/theme blank space issues since no theme template is loaded.
     */
    public function maybe_render_fullpage() {
        global $post;
        
        $is_portal = false;
        
        // Check for query param
        if (isset($_GET['mtti_portal'])) {
            $is_portal = true;
        }
        
        // Check if current page has the shortcode
        if (!$is_portal && is_a($post, 'WP_Post')) {
            if (has_shortcode($post->post_content, 'mtti_learner_portal') || 
                has_shortcode($post->post_content, 'mtti_student_portal')) {
                $is_portal = true;
            }
        }
        
        if (!$is_portal) return;
        
        // Render full-page portal (no theme)
        $this->render_fullpage_portal();
        exit; // Stop WordPress from loading the theme template
    }
    
    /**
     * Outputs a complete HTML page with the portal — no theme wrapper at all.
     */
    private function render_fullpage_portal() {
        // Prevent page caching — portal is always personalised per student
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
        // Tell popular cache plugins to bypass this page
        if (!defined('DONOTCACHEPAGE'))    define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEDB'))      define('DONOTCACHEDB', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);

        $student = null;
        $portal_html = '';
        
        if (!is_user_logged_in()) {
            $portal_html = $this->render_login_form();
        } else {
            $student = $this->get_current_student();
            if (!$student) {
                $portal_html = $this->render_not_enrolled();
            } else {
                $tab = isset($_GET['portal_tab']) ? sanitize_key($_GET['portal_tab']) : 'dashboard';
                
                ob_start();
                echo '<div class="mtti-portal-wrapper" id="mtti-portal">';
                $this->render_header($student);
                echo '<div class="mtti-portal-container">';
                $this->render_sidebar($tab);
                echo '<main class="mtti-portal-main">';
                
                switch ($tab) {
                    case 'courses': $this->render_courses($student); break;
                    case 'units': $this->render_courses($student); break; // merged into courses
                    case 'lessons': $this->render_lessons($student); break;
                    case 'materials': $this->render_lessons($student); break; // alias
                    case 'assignments': $this->render_assignments($student); break;
                    case 'attendance': $this->render_learner_attendance($student); break;
                    case 'results': $this->render_results($student); break;
                    case 'transcript': $this->render_results($student); break; // merged into results
                    case 'payments': $this->render_payments($student); break;
                    case 'notices': $this->render_notices($student); break;
                    case 'notifications': $this->render_notifications($student); break;
                    case 'profile': $this->render_profile($student); break;
                    case 'calendar': $this->render_dashboard($student); break; // removed — go to dashboard
                    case 'chat': $this->render_dashboard($student); break; // removed — go to dashboard
                    case 'leaderboard': $this->render_leaderboard($student); break;
                    case 'syllabus': // fallthrough
                    case 'scheme': $this->render_scheme_of_work($student); break;
                    default: $this->render_dashboard($student);
                }
                
                echo '</main></div></div>';
                $portal_html = ob_get_clean();
            }
        }
        
        // Output complete HTML page
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Learner Portal — <?php bloginfo('name'); ?></title>
<?php
        // Enqueue our styles/scripts
        wp_enqueue_style('mtti-learner-portal', MTTI_MIS_PLUGIN_URL . 'assets/css/learner-portal.css', array(), MTTI_MIS_VERSION);
        wp_enqueue_script('jquery');
        wp_enqueue_script('mtti-learner-portal', MTTI_MIS_PLUGIN_URL . 'assets/js/learner-portal.js', array('jquery'), MTTI_MIS_VERSION, true);
        wp_localize_script('mtti-learner-portal', 'mttiPortal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mtti_portal_nonce'),
        ));
        wp_head();
?>
<style>
    /* Clean slate — no theme interference */
    html, body { margin: 0 !important; padding: 0 !important; background: #EFF4EF; }
    #wpadminbar ~ * { margin-top: 0 !important; }
</style>
</head>
<body class="mtti-portal-fullpage">
<?php wp_body_open(); ?>
<?php echo $portal_html; ?>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }
    
    public function enqueue_assets() {
        global $post;
        if (!is_a($post, 'WP_Post')) return;
        
        if (has_shortcode($post->post_content, 'mtti_learner_portal') || 
            has_shortcode($post->post_content, 'mtti_student_portal')) {
            wp_enqueue_style('mtti-learner-portal', MTTI_MIS_PLUGIN_URL . 'assets/css/learner-portal.css', array(), MTTI_MIS_VERSION);
            wp_enqueue_script('mtti-learner-portal', MTTI_MIS_PLUGIN_URL . 'assets/js/learner-portal.js', array('jquery'), MTTI_MIS_VERSION, true);
            wp_localize_script('mtti-learner-portal', 'mttiPortal', array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('mtti_portal_nonce'),
                'quizNonce'  => wp_create_nonce('mtti_quiz_nonce'),
            ));

            // postMessage relay: intercept submitExamToMTTI() calls from sandboxed iframes
            wp_add_inline_script('mtti-learner-portal', '
(function(){
    window.addEventListener("message", function(e) {
        var d = e.data;
        if (!d || d.type !== "mtti_quiz_score") return;
        var lessonId = d.lesson_id || (function(){
            var iframes = document.querySelectorAll("iframe[data-lesson-id]");
            return iframes.length ? iframes[0].getAttribute("data-lesson-id") : 0;
        })();
        if (!lessonId) return;
        var fd = new FormData();
        fd.append("action",    "mtti_save_quiz_score");
        fd.append("lesson_id", lessonId);
        fd.append("score",     d.score   || 0);
        fd.append("total",     d.total   || 0);
        fd.append("percent",   d.percent || 0);
        fetch(mttiPortal.ajaxUrl, { method:"POST", body: fd, credentials:"same-origin" })
          .then(function(r){ return r.json(); })
          .then(function(res){ console.log("[MTTI] Quiz saved", res); })
          .catch(function(err){ console.warn("[MTTI] Quiz save error", err); });
    });
})();
            ');
            
            // Add body class for Astra theme isolation
            add_filter('body_class', function($classes) {
                $classes[] = 'mtti-portal-active';
                return $classes;
            });
            
            // Inline CSS to kill Astra spacing immediately
            wp_add_inline_style('mtti-learner-portal', '
                body.mtti-portal-active .entry-header,
                body.mtti-portal-active .ast-single-post-order .entry-header { display:none!important; }
                body.mtti-portal-active .entry-content,
                body.mtti-portal-active .ast-container,
                body.mtti-portal-active #primary,
                body.mtti-portal-active .content-area,
                body.mtti-portal-active .site-main,
                body.mtti-portal-active .ast-separate-container .ast-article-single,
                body.mtti-portal-active .ast-separate-container .ast-article-single .entry-content { 
                    max-width:none!important; padding:0!important; margin:0!important; width:100%!important; 
                }
            ');
        }
    }
    
    private function get_current_student() {
        if (!is_user_logged_in()) return null;
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_students';
        $courses_table = $wpdb->prefix . 'mtti_courses';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, 
                    c.course_id, c.course_name, c.course_code, c.fee, c.duration_weeks, c.description as course_description
             FROM {$table} s 
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID 
             LEFT JOIN {$courses_table} c ON s.course_id = c.course_id
             WHERE s.user_id = %d",
            get_current_user_id()
        ));
    }
    
    /**
     * Get all enrolled course IDs for a student
     */
    private function get_enrolled_course_ids($student) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT e.course_id
             FROM {$wpdb->prefix}mtti_enrollments e
             WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
            $student->student_id
        ));
        // Always include the primary course_id if set
        if (!empty($student->course_id) && !in_array($student->course_id, $ids)) {
            $ids[] = $student->course_id;
        }
        return array_map('intval', $ids);
    }

    /**
     * Get enrolled courses as objects (id, name, code)
     */
    private function get_enrolled_courses($student) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.course_id, c.course_name, c.course_code
             FROM {$wpdb->prefix}mtti_enrollments e
             JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
             ORDER BY e.enrollment_date DESC",
            $student->student_id
        ));
    }

    /**
     * Render a course-filter dropdown when student is in multiple courses.
     * Returns the currently selected course_id (or 0 for "All Courses").
     */
    private function render_course_filter($student, $tab_name) {
        $courses = $this->get_enrolled_courses($student);
        if (count($courses) <= 1) {
            return !empty($student->course_id) ? intval($student->course_id) : 0;
        }
        $selected = isset($_GET['filter_course']) ? intval($_GET['filter_course']) : 0;
        $base = add_query_arg('portal_tab', $tab_name, get_permalink());

        echo '<div class="mtti-course-filter" style="margin-bottom:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
        echo '<label style="font-weight:600;font-size:13px;color:var(--text-2);">📚 Course:</label>';
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">';

        // "All" button
        $all_active = ($selected === 0) ? 'background:var(--mtti-primary);color:#fff;' : 'background:var(--bg-subtle);color:var(--text-2);';
        echo '<a href="' . esc_url(remove_query_arg('filter_course', $base)) . '" class="mtti-btn" style="padding:6px 14px;font-size:12px;border-radius:20px;text-decoration:none;' . $all_active . '">All Courses</a>';

        foreach ($courses as $c) {
            $is_active = ($selected === intval($c->course_id));
            $style = $is_active
                ? 'background:var(--mtti-primary);color:#fff;'
                : 'background:var(--bg-subtle);color:var(--text-2);';
            $url = add_query_arg('filter_course', $c->course_id, $base);
            echo '<a href="' . esc_url($url) . '" class="mtti-btn" style="padding:6px 14px;font-size:12px;border-radius:20px;text-decoration:none;' . $style . '">';
            echo esc_html($c->course_code) . '</a>';
        }
        echo '</div></div>';

        return $selected;
    }

    public function render_portal($atts = array()) {
        // Prevent caching — content is user-specific
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

        if (!is_user_logged_in()) {
            return $this->render_login_form();
        }
        
        $student = $this->get_current_student();
        if (!$student) {
            return $this->render_not_enrolled();
        }
        
        $tab = isset($_GET['portal_tab']) ? sanitize_key($_GET['portal_tab']) : 'dashboard';
        
        ob_start();
        echo '<div class="mtti-portal-wrapper" id="mtti-portal">';
        $this->render_header($student);
        echo '<div class="mtti-portal-container">';
        $this->render_sidebar($tab);
        echo '<main class="mtti-portal-main">';
        
        switch ($tab) {
            case 'courses': $this->render_courses($student); break;
            case 'units': $this->render_courses($student); break; // merged
            case 'lessons': $this->render_lessons($student); break;
            case 'materials': $this->render_lessons($student); break; // alias
            case 'assignments': $this->render_assignments($student); break;
            case 'attendance': $this->render_learner_attendance($student); break;
            case 'results': $this->render_results($student); break;
            case 'transcript': $this->render_results($student); break; // merged
            case 'payments': $this->render_payments($student); break;
            case 'notices': $this->render_notices($student); break;
            case 'notifications': $this->render_notifications($student); break;
            case 'profile': $this->render_profile($student); break;
            case 'calendar': $this->render_dashboard($student); break; // removed
            case 'chat': $this->render_dashboard($student); break; // removed
            case 'leaderboard': $this->render_leaderboard($student); break;
            case 'syllabus': // fallthrough
            case 'scheme': $this->render_scheme_of_work($student); break;
            default: $this->render_dashboard($student);
        }
        
        echo '</main></div></div>';
        return ob_get_clean();
    }
    
    private function render_login_form() {
        global $wpdb;
        $error = '';
        // Use a clean redirect URL with a cache-buster so the browser does a fresh GET
        // after setting the auth cookie — prevents the login form looping on POST.
        $redirect_to = add_query_arg('loggedin', '1', get_permalink());
        
        // Handle login form submission
        if (isset($_POST['mtti_portal_login']) && isset($_POST['mtti_login_nonce'])) {
            if (wp_verify_nonce($_POST['mtti_login_nonce'], 'mtti_portal_login_action')) {
                $username = sanitize_text_field($_POST['log']);
                $password = $_POST['pwd'];
                $logged_in = false;
                
                // Check if username looks like an admission number (contains /)
                if (strpos($username, '/') !== false) {
                    // Look up student by admission number
                    $students_table = $wpdb->prefix . 'mtti_students';
                    $accounts_table = $wpdb->prefix . 'mtti_student_accounts';
                    
                    // Ensure accounts table exists
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$accounts_table}'");
                    if (!$table_exists) {
                        // Create the table if it doesn't exist
                        $charset_collate = $wpdb->get_charset_collate();
                        $wpdb->query("CREATE TABLE IF NOT EXISTS {$accounts_table} (
                            account_id INT AUTO_INCREMENT PRIMARY KEY,
                            student_id INT NOT NULL,
                            admission_number VARCHAR(50) NOT NULL UNIQUE,
                            password_hash VARCHAR(255) NOT NULL,
                            must_change_password TINYINT(1) DEFAULT 1,
                            last_login DATETIME NULL,
                            login_attempts INT DEFAULT 0,
                            locked_until DATETIME NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) {$charset_collate}");
                    }
                    
                    $student = $wpdb->get_row($wpdb->prepare(
                        "SELECT s.user_id, s.student_id, s.admission_number, a.password_hash, a.account_id
                         FROM {$students_table} s
                         LEFT JOIN {$accounts_table} a ON s.admission_number = a.admission_number
                         WHERE s.admission_number = %s",
                        $username
                    ));
                    
                    // Check if student exists but has no WordPress user linked
                    if ($student && !$student->user_id) {
                        $error = 'Your account setup is incomplete. Please contact administration.';
                    } elseif ($student && $student->user_id) {
                        // Calculate default password: last 4 chars of admission number + "1234"
                        $default_password = substr($student->admission_number, -4) . '1234';
                        
                        // Method 1: Check MTTI student account password (if account exists)
                        if ($student->password_hash && password_verify($password, $student->password_hash)) {
                            // Password matches student account - log in via WordPress
                            $wp_user = get_user_by('ID', $student->user_id);
                            if ($wp_user) {
                                wp_set_current_user($wp_user->ID);
                                wp_set_auth_cookie($wp_user->ID, isset($_POST['rememberme']));
                                
                                // Update last login
                                if ($student->account_id) {
                                    $wpdb->update(
                                        $accounts_table,
                                        array('last_login' => current_time('mysql'), 'login_attempts' => 0),
                                        array('account_id' => $student->account_id)
                                    );
                                }
                                
                                $logged_in = true;
                                wp_safe_redirect($redirect_to);
                                exit;
                            }
                        }
                        
                        // Method 1B: AUTO-CREATE ACCOUNT on first login with correct default password
                        // If no account exists OR password matches default, allow login
                        if (!$logged_in && $password === $default_password) {
                            // Create or update the student account
                            if (!$student->account_id) {
                                $wpdb->insert($accounts_table, array(
                                    'student_id' => $student->student_id,
                                    'admission_number' => $student->admission_number,
                                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                    'must_change_password' => 1,
                                    'last_login' => current_time('mysql'),
                                    'login_attempts' => 0
                                ));
                            }
                            
                            // Log in the student
                            $wp_user = get_user_by('ID', $student->user_id);
                            if ($wp_user) {
                                wp_set_current_user($wp_user->ID);
                                wp_set_auth_cookie($wp_user->ID, isset($_POST['rememberme']));
                                $logged_in = true;
                                wp_safe_redirect($redirect_to);
                                exit;
                            }
                        }
                        
                        // Method 2: Try WordPress authentication with the user's WP username
                        if (!$logged_in) {
                            $wp_user = get_user_by('ID', $student->user_id);
                            if ($wp_user) {
                                $username = $wp_user->user_login;
                            }
                        }
                    }
                }
                
                // Method 3: Standard WordPress authentication (only if no error set)
                if (!$logged_in && empty($error)) {
                    $user = wp_signon(array(
                        'user_login' => $username,
                        'user_password' => $password,
                        'remember' => isset($_POST['rememberme'])
                    ), is_ssl());
                    
                    if (is_wp_error($user)) {
                        $error = 'Invalid admission number or password. Please try again.';
                    } else {
                        // Successful login - redirect to portal
                        wp_safe_redirect($redirect_to);
                        exit;
                    }
                }
            }
        }
        
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        
        ob_start();
        ?>
        <div class="mtti-portal-login">
            <div class="mtti-login-card" style="max-width: 400px; width: 100%;">
                <img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" style="width: 80px; height: 80px; border-radius: 50%; margin-bottom: 15px;">
                <h2 style="color: #2E7D32; margin-bottom: 5px;">M.T.T.I Learner Portal</h2>
                <p style="color: #FF9800; font-style: italic; margin-bottom: 20px;">Start Learning, Start Earning</p>
                
                <?php if ($error): ?>
                <div style="background: #ffebee; color: #c62828; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px;">
                    ⚠️ <?php echo esc_html($error); ?>
                </div>
                <?php endif; ?>
                
                <form method="post" action="" style="text-align: left;">
                    <?php wp_nonce_field('mtti_portal_login_action', 'mtti_login_nonce'); ?>
                    <input type="hidden" name="mtti_portal_login" value="1">
                    
                    <div style="margin-bottom: 15px;">
                        <label for="user_login" style="display: block; margin-bottom: 5px; font-weight: 500; color: #333;">Admission Number</label>
                        <input type="text" name="log" id="user_login" required 
                               style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;"
                               placeholder="e.g. COA/2025/0001">
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="user_pass" style="display: block; margin-bottom: 5px; font-weight: 500; color: #333;">Password</label>
                        <input type="password" name="pwd" id="user_pass" required 
                               style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;"
                               placeholder="Enter your password">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; color: #666;">
                            <input type="checkbox" name="rememberme" value="forever">
                            Remember me
                        </label>
                    </div>
                    
                    <button type="submit" class="mtti-btn mtti-btn-primary" style="width: 100%; padding: 14px; font-size: 16px; justify-content: center;">
                        🔐 Log In
                    </button>
                </form>
                
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                    <p style="font-size: 13px; color: #666; margin-bottom: 10px;">
                        <a href="<?php echo esc_url(wp_lostpassword_url($redirect_to)); ?>" style="color: #1976D2;">Forgot your password?</a>
                    </p>
                    <p style="font-size: 12px; color: #999;">
                        Having trouble? Contact administration for assistance.
                    </p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_not_enrolled() {
        // If the logged-in user is an admin/staff, show a helpful redirect instead
        if (current_user_can('manage_options') || current_user_can('edit_posts')) {
            return '<div class="mtti-portal-not-enrolled">
                <div class="mtti-message-card">
                    <div class="mtti-message-icon">🔐</div>
                    <h2>Administrator Account</h2>
                    <p>You are logged in as an administrator. The student portal is for students only.</p>
                    <p style="margin-top:12px;">
                        <a href="' . admin_url() . '" class="button button-primary">Go to WP Admin Dashboard</a>
                        &nbsp;&nbsp;
                        <a href="' . wp_logout_url(get_permalink()) . '" class="button">Log Out</a>
                    </p>
                </div>
            </div>';
        }
        return '<div class="mtti-portal-not-enrolled">
            <div class="mtti-message-card">
                <div class="mtti-message-icon">⚠️</div>
                <h2>Student Profile Not Found</h2>
                <p>Your account is not linked to a student profile. Please contact administration.</p>
                <p style="margin-top:12px;">
                    <a href="' . wp_logout_url(get_permalink()) . '" style="color:#1976D2;">Log out and try a different account</a>
                </p>
            </div>
        </div>';
    }
    
    private function render_header($student) {
        // Get unread notification count
        global $wpdb;
        $notif_count = 0;
        $notif_table = $wpdb->prefix . 'mtti_notifications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$notif_table}'")) {
            $notif_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$notif_table} WHERE student_id = %d AND is_read = 0",
                $student->student_id
            )));
        }
        
        echo '<header class="mtti-portal-header">
            <div class="mtti-portal-logo">
                <img src="' . esc_url(MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg') . '" alt="MTTI">
                <div class="mtti-portal-title">
                    <h1>Learner Portal</h1>
                    <span class="mtti-portal-motto">Start Learning, Start Earning</span>
                </div>
            </div>
            <div class="mtti-header-right">
                <button class="mtti-icon-btn mtti-dark-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">🌙</button>
                <div class="mtti-notif-wrap">
                    <button class="mtti-icon-btn mtti-notif-btn" title="Notifications" aria-label="Notifications">
                        🔔
                        ' . ($notif_count > 0 ? '<span class="mtti-notif-badge">' . ($notif_count > 9 ? '9+' : $notif_count) . '</span>' : '') . '
                    </button>
                    <div class="mtti-notif-dropdown">
                        <div class="mtti-notif-hdr">
                            <strong>Notifications</strong>
                            <button class="mtti-notif-mark-all">Mark all read</button>
                        </div>
                        <div class="mtti-notif-scroll"><div class="mtti-notif-empty">Loading...</div></div>
                    </div>
                </div>
                <div class="mtti-user-info">
                    <span class="mtti-user-name">' . esc_html($student->display_name) . '</span>
                    <span class="mtti-user-id">' . esc_html($student->admission_number) . '</span>
                </div>
                <div class="mtti-user-avatar">' . get_avatar($student->user_id, 36) . '</div>
            </div>
        </header>';
    }
    
    private function render_sidebar($current_tab) {
        global $wpdb;
        $base = get_permalink();
        $menu = array(
            'dashboard'     => array('icon' => '📊', 'label' => 'Dashboard'),
            'courses'       => array('icon' => '📚', 'label' => 'My Courses'),
            'lessons'       => array('icon' => '📖', 'label' => 'Lessons & Materials'),
            'assignments'   => array('icon' => '📝', 'label' => 'Assignments'),
            'attendance'    => array('icon' => '✅', 'label' => 'Attendance'),
            'results'       => array('icon' => '🏆', 'label' => 'Results & Transcript'),
            'payments'      => array('icon' => '💳', 'label' => 'Payments'),
            'notices'       => array('icon' => '🔔', 'label' => 'Notices'),
            'notifications' => array('icon' => '📬', 'label' => 'Notifications'),
            'leaderboard'   => array('icon' => '🥇', 'label' => 'Leaderboard'),
            'profile'       => array('icon' => '👤', 'label' => 'Profile'),
        );

        // Get unread notification count for badge
        $unread_count = 0;
        $student = $this->get_current_student();
        if ($student) {
            $ntbl = $wpdb->prefix . 'mtti_notifications';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$ntbl}'")) {
                $unread_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$ntbl} WHERE student_id=%d AND is_read=0",
                    $student->student_id
                ));
            }
        }

        echo '<nav class="mtti-portal-sidebar"><ul class="mtti-portal-menu">';
        foreach ($menu as $tab => $item) {
            $active = ($current_tab === $tab) ? ' class="active"' : '';
            $url    = add_query_arg('portal_tab', $tab, $base);
            echo '<li' . $active . '><a href="' . esc_url($url) . '">';
            echo '<span class="menu-icon">' . $item['icon'] . '</span>';
            echo '<span class="menu-label">' . $item['label'];
            if ($tab === 'notifications' && $unread_count > 0) {
                echo ' <span style="background:#C62828;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;">'
                    . ($unread_count > 9 ? '9+' : $unread_count) . '</span>';
            }
            echo '</span>';
            echo '</a></li>';
        }
        echo '</ul>';
        echo '<div class="mtti-portal-logout"><a href="' . wp_logout_url(get_permalink()) . '"><span class="menu-icon">🚪</span> <span>Log Out</span></a></div>';
        echo '</nav>';
    }
    
    private function render_dashboard($student) {
        global $wpdb;
        
        $has_course = !empty($student->course_id);
        
        // Fee balance — recalculate live from actual payments (same method as admin view student)
        // so the learner portal always matches what the admin sees.
        $balance_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sb.enrollment_id, sb.discount_amount,
                    COALESCE(NULLIF(sb.total_fee, 0), c.fee) as actual_fee
             FROM {$wpdb->prefix}mtti_student_balances sb
             INNER JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE sb.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
            $student->student_id
        ));
        $balance = 0;
        foreach ($balance_rows as $br) {
            $net_fee = max(0, floatval($br->actual_fee) - floatval($br->discount_amount));
            $paid    = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}mtti_payments
                 WHERE student_id = %d AND enrollment_id = %d AND status = 'Completed'",
                $student->student_id, $br->enrollment_id
            )));
            $balance += max(0, $net_fee - $paid);
        }
        
        // Progress = interactive practicals viewed by student ÷ total interactives for ALL enrolled courses
        $progress_pct       = 0;
        $interactives_done  = 0;
        $interactives_total = 0;
        $enrolled_ids = $this->get_enrolled_course_ids($student);
        if (!empty($enrolled_ids)) {
            $placeholders = implode(',', array_fill(0, count($enrolled_ids), '%d'));
            $interactives_total = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mtti_lessons
                 WHERE course_id IN ($placeholders) AND content_type = 'html_interactive' AND status = 'Published'
                 AND title NOT LIKE '🤖 Quiz:%'",
                ...$enrolled_ids
            )));
            $interactives_done = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT l.lesson_id)
                 FROM {$wpdb->prefix}mtti_lessons l
                 INNER JOIN {$wpdb->prefix}mtti_lesson_views lv ON lv.lesson_id = l.lesson_id
                 WHERE l.course_id IN ($placeholders) AND l.content_type = 'html_interactive'
                   AND l.status = 'Published' AND lv.student_id = %d
                   AND l.title NOT LIKE '🤖 Quiz:%'",
                ...array_merge($enrolled_ids, array($student->student_id))
            )));
            // Suppress any DB error if lesson_views table doesn't exist yet
            if ($wpdb->last_error) { $interactives_done = 0; $wpdb->last_error = ''; }
            $progress_pct = $interactives_total > 0
                ? min(100, round(($interactives_done / $interactives_total) * 100))
                : 0;
        }
        
        // Recent results
        $recent_results = $wpdb->get_results($wpdb->prepare(
            "SELECT ur.*, cu.unit_name, cu.unit_code
             FROM {$wpdb->prefix}mtti_unit_results ur
             LEFT JOIN {$wpdb->prefix}mtti_course_units cu ON ur.unit_id = cu.unit_id
             WHERE ur.student_id = %d ORDER BY ur.result_date DESC LIMIT 5",
            $student->student_id
        ));
        
        // Notices
        $notices = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mtti_notices
             WHERE status = 'Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())
             AND target_audience IN ('All', 'Students')
             ORDER BY priority DESC, created_at DESC LIMIT 3"
        );
        
        // Goal widget data
        $goal_text   = get_user_meta(get_current_user_id(), 'mtti_weekly_goal', true);
        $goal_done   = intval(get_user_meta(get_current_user_id(), 'mtti_goal_done', true));
        $goal_set_at = get_user_meta(get_current_user_id(), 'mtti_goal_set_at', true);
        // Auto-reset goal if it's from a previous week
        if ($goal_set_at) {
            $set_week = date('W-Y', strtotime($goal_set_at));
            $now_week = date('W-Y');
            if ($set_week !== $now_week) {
                delete_user_meta(get_current_user_id(), 'mtti_weekly_goal');
                delete_user_meta(get_current_user_id(), 'mtti_goal_done');
                delete_user_meta(get_current_user_id(), 'mtti_goal_set_at');
                $goal_text = '';
                $goal_done = 0;
            }
        }
        
        echo '<div class="mtti-dashboard">';
        
        $first_name = explode(' ', $student->display_name)[0];
        echo '<h2 class="mtti-page-title">Welcome back, ' . esc_html($first_name) . '! 👋</h2>';
        
        // ── PAYMENT REMINDER ────────────────────────
        if ($balance > 0) {
            echo '<div class="mtti-payment-reminder">';
            echo '<span style="font-size:36px;">⚠️</span>';
            echo '<div style="flex:1;min-width:200px;">';
            echo '<h3 style="margin:0 0 4px;color:#e65100;">Payment Reminder</h3>';
            echo '<p style="margin:0;color:var(--text-2);">Outstanding balance: <strong style="color:var(--danger);font-size:17px;">KES ' . number_format($balance, 2) . '</strong></p>';
            echo '<p style="margin:4px 0 0;font-size:12px;color:var(--text-3);">Please clear your balance to avoid interruption of studies.</p>';
            echo '</div>';
            echo '<a href="' . esc_url(add_query_arg('portal_tab', 'payments', get_permalink())) . '" class="mtti-btn mtti-btn-primary" style="background:var(--accent)!important;">💳 Pay Now</a>';
            echo '</div>';
        }
        
        // ── PROGRESS RING ────────────────────────────
        if ($has_course) {
            $r             = 40;
            $circumference = 2 * M_PI * $r;

            echo '<div class="mtti-progress-ring-wrap">';
            echo '<svg class="mtti-ring-svg" width="100" height="100" viewBox="0 0 100 100">';
            echo '<circle class="mtti-ring-track" cx="50" cy="50" r="' . $r . '"/>';
            echo '<circle class="mtti-ring-fill" cx="50" cy="50" r="' . $r . '"
                    data-pct="' . $progress_pct . '"
                    style="stroke-dasharray:' . $circumference . ';stroke-dashoffset:' . $circumference . '"/>';
            echo '<text class="mtti-ring-label" x="50" y="46">';
            echo '<tspan class="mtti-ring-pct" x="50" dy="0">' . $progress_pct . '%</tspan>';
            echo '<tspan class="mtti-ring-sub" x="50" dy="14">complete</tspan>';
            echo '</text>';
            echo '</svg>';

            echo '<div class="mtti-ring-info">';
            echo '<h3>' . esc_html($student->course_code . ' — ' . $student->course_name) . '</h3>';
            if ($interactives_total > 0) {
                echo '<p>⚡ ' . $interactives_done . ' of ' . $interactives_total . ' practicals completed</p>';
            } else {
                echo '<p style="color:var(--text-3);font-size:13px;">No practicals assigned yet</p>';
            }
            echo '<a href="' . esc_url(add_query_arg('portal_tab', 'lessons', get_permalink())) . '" class="mtti-btn mtti-btn-secondary" style="font-size:12px;margin-top:8px;">📖 Continue Studying</a>';
            echo '</div></div>';
        }
        
        // ── WEEKLY GOAL ──────────────────────────────
        echo '<div class="mtti-goal-widget">';
        if (!$goal_text) {
            echo '<h3>📌 Set Your Goal This Week</h3>';
            echo '<p style="font-size:13px;opacity:.85;margin:0 0 8px;">What do you want to achieve? (e.g. "Complete 2 units", "Study 3 hours daily")</p>';
            echo '<div class="mtti-goal-input-row">';
            echo '<input type="text" class="mtti-goal-input" placeholder="My goal this week..." maxlength="100">';
            echo '<button class="mtti-goal-btn">Set Goal</button>';
            echo '</div>';
        } else {
            $goal_pct = $goal_done ? 100 : 0;
            echo '<h3>📌 Weekly Goal</h3>';
            echo '<div class="mtti-goal-text">' . esc_html($goal_text) . '</div>';
            echo '<div class="mtti-goal-progress-bar"><div class="mtti-goal-progress-fill" style="width:' . $goal_pct . '%"></div></div>';
            echo '<div class="mtti-goal-status">';
            echo '<span>' . ($goal_done ? '🎉 Completed!' : 'In progress...') . '</span>';
            if (!$goal_done) {
                echo '<button class="mtti-goal-complete mtti-goal-btn" style="font-size:12px;padding:4px 12px;">✓ Mark Done</button>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        echo '<div class="mtti-dashboard-grid">';
        
        // ── RECENT RESULTS ───────────────────────────
        if (!empty($recent_results)) {
            echo '<div class="mtti-dashboard-card"><h3>Recent Results</h3>';
            echo '<div class="mtti-results-list">';
            foreach ($recent_results as $r) {
                $gc = 'grade-' . strtolower(substr($r->grade, 0, 1));
                echo '<div class="mtti-result-item">';
                echo '<span class="mtti-result-unit">' . esc_html($r->unit_name) . '</span>';
                echo '<span class="mtti-result-grade ' . $gc . '">' . esc_html($r->grade) . '</span>';
                echo '</div>';
            }
            echo '</div>';
            echo '<div style="margin-top:14px;"><a href="' . esc_url(add_query_arg('portal_tab','results',get_permalink())) . '" class="mtti-btn mtti-btn-secondary" style="font-size:12px;">View all results →</a></div>';
            echo '</div>';
        }
        
        // ── RECENT NOTICES ───────────────────────────
        if (!empty($notices)) {
            echo '<div class="mtti-dashboard-card"><h3>Notice Board</h3>';
            echo '<div class="mtti-notices-list">';
            foreach ($notices as $n) {
                echo '<div class="mtti-notice-item">';
                echo '<strong>' . esc_html($n->title) . '</strong>';
                echo '<p>' . esc_html(wp_trim_words($n->content, 12)) . '</p>';
                echo '</div>';
            }
            echo '</div>';
            echo '<div style="margin-top:14px;"><a href="' . esc_url(add_query_arg('portal_tab','notices',get_permalink())) . '" class="mtti-btn mtti-btn-secondary" style="font-size:12px;">All notices →</a></div>';
            echo '</div>';
        }
        
        // ── QUICK LINKS ──────────────────────────────
        echo '<div class="mtti-dashboard-card full-width"><h3>Quick Access</h3>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;">';
        $base = get_permalink();
        $links = array(

            'calendar'    => array('📅', 'Calendar'),
            'leaderboard' => array('🥇', 'Leaderboard'),
            'lessons'     => array('📖', 'Lessons'),
            'scheme'      => array('📋', 'Scheme of Work'),
            'transcript'  => array('📜', 'Transcript'),
        );
        foreach ($links as $tab => $info) {
            echo '<a href="' . esc_url(add_query_arg('portal_tab', $tab, $base)) . '" class="mtti-btn mtti-btn-secondary" style="gap:6px;">' . $info[0] . ' ' . $info[1] . '</a>';
        }
        echo '</div></div>';
        
        echo '</div></div>';
    }
    private function render_courses($student) {
        global $wpdb;
        
        // Get ALL enrolled courses for this student (not just primary course)
        $enrolled_courses = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, e.enrollment_date, e.status as enrollment_status
             FROM {$wpdb->prefix}mtti_enrollments e
             JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE e.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
             ORDER BY e.enrollment_date DESC",
            $student->student_id
        ));
        
        $has_courses = !empty($enrolled_courses);
        
        echo '<div class="mtti-my-courses">';
        echo '<h2 class="mtti-page-title">My Courses</h2>';
        
        if ($has_courses) {
            echo '<p style="color: #666; margin-bottom: 20px;">You are enrolled in <strong>' . count($enrolled_courses) . '</strong> course(s)</p>';
            
            foreach ($enrolled_courses as $course) {
                // Get course units for this specific course
                $units = $wpdb->get_results($wpdb->prepare(
                    "SELECT cu.*, 
                            (SELECT ur.passed FROM {$wpdb->prefix}mtti_unit_results ur 
                             WHERE ur.unit_id = cu.unit_id AND ur.student_id = %d LIMIT 1) as student_passed,
                            (SELECT ur.grade FROM {$wpdb->prefix}mtti_unit_results ur 
                             WHERE ur.unit_id = cu.unit_id AND ur.student_id = %d LIMIT 1) as student_grade
                     FROM {$wpdb->prefix}mtti_course_units cu
                     WHERE cu.course_id = %d AND cu.status = 'Active'
                     ORDER BY cu.order_number, cu.unit_code",
                    $student->student_id, $student->student_id, $course->course_id
                ));
                
                $is_primary = ($course->course_id == $student->course_id);
                
                echo '<div class="mtti-course-detail-card" style="background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); ' . ($is_primary ? 'border-left: 4px solid #4CAF50;' : '') . '">';
                echo '<div class="mtti-course-detail-header">';
                echo '<span class="mtti-badge">' . esc_html($course->course_code) . '</span>';
                if ($is_primary) {
                    echo ' <span style="background: #E65100; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; margin-left: 5px;">PRIMARY</span>';
                }
                echo '<h3 style="margin: 10px 0;">' . esc_html($course->course_name) . '</h3>';
                echo '<span class="mtti-status-badge" style="background: rgba(76, 175, 80, 0.15); color: #4CAF50;">' . esc_html($course->enrollment_status) . '</span>';
                echo '</div>';
                echo '<div class="mtti-course-meta" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px; color: #666;">';
                echo '<span>📅 Enrolled: ' . date('M j, Y', strtotime($course->enrollment_date)) . '</span>';
                if ($course->duration_weeks) {
                    echo '<span>⏱️ Duration: ' . intval($course->duration_weeks) . ' weeks</span>';
                }
                if ($course->fee) {
                    echo '<span>💰 Fee: KES ' . number_format($course->fee, 2) . '</span>';
                }
                echo '</div>';
                
                if ($course->description) {
                    echo '<p style="margin-top: 15px; color: #666;">' . esc_html($course->description) . '</p>';
                }
                
                // Show course units for this course
                if (!empty($units)) {
                    echo '<div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">';
                    echo '<h4 style="margin: 0 0 10px; color: #333; font-size: 14px;">📑 Course Units (' . count($units) . ')</h4>';
                    echo '<div class="mtti-units-accordion">';
                    foreach ($units as $unit) {
                        $status_class = '';
                        $status_text = 'Pending';
                        if ($unit->student_passed) {
                            $status_class = 'completed';
                            $status_text = 'Completed';
                        } elseif ($unit->student_grade) {
                            $status_class = 'pending';
                            $status_text = 'In Progress';
                        }
                        
                        echo '<div class="mtti-unit-item">';
                        echo '<div class="mtti-unit-name">';
                        echo '<strong>' . esc_html($unit->unit_code) . '</strong> - ' . esc_html($unit->unit_name);
                        if ($unit->duration_hours) {
                            echo ' <small style="color: #999;">(' . $unit->duration_hours . ' hrs)</small>';
                        }
                        echo '</div>';
                        if ($unit->student_grade) {
                            $grade_class = 'grade-' . strtolower(substr($unit->student_grade, 0, 1));
                            echo '<span class="mtti-unit-grade ' . $grade_class . '">' . esc_html($unit->student_grade) . '</span>';
                        } else {
                            echo '<span class="mtti-status-badge ' . $status_class . '">' . $status_text . '</span>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
                
                echo '</div>'; // End course card
            }
        } else {
            echo '<div class="mtti-empty-state"><span>📚</span><h3>No Courses Assigned</h3><p>Contact administration to be enrolled in a course.</p></div>';
        }
        echo '</div>';
    }
    
    private function render_units($student) {
        global $wpdb;
        
        $enrolled_ids = $this->get_enrolled_course_ids($student);
        
        echo '<div class="mtti-course-units">';
        echo '<h2 class="mtti-page-title">Course Units</h2>';
        
        $filter_course = $this->render_course_filter($student, 'units');
        
        // Determine which course IDs to query
        if ($filter_course > 0 && in_array($filter_course, $enrolled_ids)) {
            $query_ids = array($filter_course);
        } elseif (!empty($enrolled_ids)) {
            $query_ids = $enrolled_ids;
        } elseif (!empty($student->course_id)) {
            $query_ids = array(intval($student->course_id));
        } else {
            $query_ids = array();
        }
        
        if (!empty($query_ids)) {
            $placeholders = implode(',', array_fill(0, count($query_ids), '%d'));
            $units = $wpdb->get_results($wpdb->prepare(
                "SELECT cu.*, c.course_name, c.course_code, ur.grade, ur.passed, ur.score
                 FROM {$wpdb->prefix}mtti_course_units cu
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON cu.course_id = c.course_id
                 LEFT JOIN {$wpdb->prefix}mtti_unit_results ur ON cu.unit_id = ur.unit_id AND ur.student_id = %d
                 WHERE cu.course_id IN ($placeholders) AND cu.status = 'Active'
                 ORDER BY c.course_name, cu.order_number, cu.unit_code",
                $student->student_id, ...$query_ids
            ));
        } else {
            $units = array();
        }
        
        if (!empty($units)) {
            // Group by course
            $by_course = array();
            foreach ($units as $unit) {
                $ckey = $unit->course_id;
                if (!isset($by_course[$ckey])) {
                    $by_course[$ckey] = array(
                        'course_name' => $unit->course_name,
                        'course_code' => $unit->course_code,
                        'units' => array()
                    );
                }
                $by_course[$ckey]['units'][] = $unit;
            }
            
            foreach ($by_course as $ckey => $course_data) {
                echo '<div class="mtti-course-units-group">';
                echo '<h3><span class="mtti-badge">' . esc_html($course_data['course_code']) . '</span> ' . esc_html($course_data['course_name']) . '</h3>';
            
                foreach ($course_data['units'] as $unit) {
                    $completed = $unit->passed ? ' completed' : '';
                    echo '<div class="mtti-unit-card' . $completed . '" style="background: white; border-radius: 8px; padding: 15px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">';
                    echo '<div style="flex: 1; min-width: 200px;">';
                    echo '<span class="mtti-unit-code" style="font-weight: bold; color: #2E7D32;">' . esc_html($unit->unit_code) . '</span>';
                    echo '<span class="mtti-unit-name"> - ' . esc_html($unit->unit_name) . '</span>';
                    if ($unit->duration_hours) {
                        echo ' <small style="color: #999;">(' . $unit->duration_hours . ' hrs)</small>';
                    }
                    echo '</div>';
                    echo '<div style="display: flex; align-items: center; gap: 10px;">';
                    if ($unit->grade) {
                        $gc = strtolower(substr($unit->grade, 0, 1));
                        echo '<span class="mtti-unit-grade grade-' . $gc . '" style="padding: 4px 12px; border-radius: 4px; font-weight: bold;">' . esc_html($unit->grade) . '</span>';
                    }
                    if ($unit->passed) echo '<span class="mtti-completed-badge" style="color: #4CAF50;">✓ Completed</span>';
                    echo '</div>';
                    echo '</div>';
                }
                echo '</div>';
            }
        } else {
            echo '<div class="mtti-empty-state"><span>📑</span><h3>No Units Available</h3><p>Course units will appear here once they are added.</p></div>';
        }
        echo '</div>';
    }
    
    private function render_assignments($student) {
        global $wpdb;
        
        $enrolled_ids = $this->get_enrolled_course_ids($student);
        
        echo '<div class="mtti-assignments">';
        echo '<h2 class="mtti-page-title">Assignments</h2>';
        
        $filter_course = $this->render_course_filter($student, 'assignments');
        
        // Determine which course IDs to query
        if ($filter_course > 0 && in_array($filter_course, $enrolled_ids)) {
            $query_ids = array($filter_course);
        } elseif (!empty($enrolled_ids)) {
            $query_ids = $enrolled_ids;
        } elseif (!empty($student->course_id)) {
            $query_ids = array(intval($student->course_id));
        } else {
            $query_ids = array();
        }
        
        $assignments = array();
        if (!empty($query_ids)) {
            $placeholders = implode(',', array_fill(0, count($query_ids), '%d'));
            $assignments = $wpdb->get_results($wpdb->prepare(
                "SELECT a.*, c.course_name, c.course_code,
                        sub.submission_id, sub.submitted_at, sub.score
                 FROM {$wpdb->prefix}mtti_assignments a
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON a.course_id = c.course_id
                 LEFT JOIN {$wpdb->prefix}mtti_assignment_submissions sub ON a.assignment_id = sub.assignment_id AND sub.student_id = %d
                 WHERE a.course_id IN ($placeholders) AND a.status = 'Active'
                 ORDER BY c.course_name, a.due_date DESC",
                $student->student_id, ...$query_ids
            ));
        }
        
        if (!empty($assignments)) {
            foreach ($assignments as $a) {
                $overdue = (strtotime($a->due_date) < time() && !$a->submission_id) ? ' overdue' : '';
                $submitted = $a->submission_id ? ' submitted' : '';
                
                echo '<div class="mtti-assignment-card' . $overdue . $submitted . '">';
                echo '<div class="mtti-assignment-header">';
                echo '<span class="mtti-badge">' . esc_html($a->course_code) . '</span>';
                echo '<h3>' . esc_html($a->title) . '</h3>';
                
                if ($a->submission_id) {
                    echo '<span class="mtti-status-badge submitted">✓ Submitted</span>';
                } elseif ($overdue) {
                    echo '<span class="mtti-status-badge overdue">⚠️ Overdue</span>';
                } else {
                    echo '<span class="mtti-status-badge pending">⏳ Pending</span>';
                }
                echo '</div>';
                
                if ($a->description) {
                    echo '<p>' . esc_html($a->description) . '</p>';
                }
                
                echo '<div class="mtti-assignment-meta">';
                echo '<span>📅 Due: ' . date('M j, Y g:i A', strtotime($a->due_date)) . '</span>';
                echo '<span>🏆 Max: ' . intval($a->max_score) . ' pts</span>';
                if ($a->score !== null) {
                    echo '<span>📊 Score: ' . intval($a->score) . '</span>';
                }
                echo '</div>';
                
                if ($a->file_path) {
                    echo '<a href="' . esc_url($a->file_path) . '" class="mtti-btn mtti-btn-secondary" target="_blank">📥 Download</a> ';
                }
                if (!$a->submission_id && !$overdue) {
                    echo '<button class="mtti-btn mtti-btn-primary mtti-submit-btn" data-id="' . intval($a->assignment_id) . '">📤 Submit</button>';
                }
                echo '</div>';
            }
        } else {
            echo '<div class="mtti-empty-state"><span>📝</span><h3>No Assignments</h3></div>';
        }
        
        echo $this->get_submission_modal();
        echo '</div>';
    }
    
    private function get_submission_modal() {
        return '<div id="mtti-submit-modal" class="mtti-modal" style="display:none !important;">
            <div class="mtti-modal-content">
                <h3>📤 Submit Assignment</h3>
                <form id="mtti-submit-form" enctype="multipart/form-data">
                    <input type="hidden" name="assignment_id" id="modal-assignment-id">
                    <input type="hidden" name="action" value="mtti_submit_assignment">
                    <input type="hidden" name="nonce" value="' . wp_create_nonce('mtti_portal_nonce') . '">
                    <div class="mtti-form-group">
                        <label><strong>Upload File</strong> (PDF, DOC, DOCX, ZIP, TXT - Max 10MB)</label>
                        <input type="file" name="submission_file" accept=".pdf,.doc,.docx,.zip,.txt" required 
                               style="padding: 15px; background: #f5f5f5; border: 2px dashed #ccc; cursor: pointer;">
                    </div>
                    <div class="mtti-form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" rows="3" placeholder="Add any notes or comments about your submission..."></textarea>
                    </div>
                    <div class="mtti-modal-actions">
                        <button type="button" class="mtti-btn mtti-btn-secondary mtti-modal-close" style="background: #f44336; color: white;">✕ Cancel</button>
                        <button type="submit" class="mtti-btn mtti-btn-primary">📤 Submit Assignment</button>
                    </div>
                </form>
            </div>
        </div>';
    }
    
    private function render_results($student) {
        global $wpdb;
        
        // Get unit results for this student
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ur.*, cu.unit_name, cu.unit_code, c.course_name, c.course_code
             FROM {$wpdb->prefix}mtti_unit_results ur
             JOIN {$wpdb->prefix}mtti_course_units cu ON ur.unit_id = cu.unit_id
             JOIN {$wpdb->prefix}mtti_courses c ON cu.course_id = c.course_id
             WHERE ur.student_id = %d
             ORDER BY c.course_name, cu.unit_name",
            $student->student_id
        ));
        
        echo '<div class="mtti-results">';
        echo '<h2 class="mtti-page-title">🏆 My Results</h2>';
        
        if (!empty($results)) {
            // Group by course
            $grouped = array();
            foreach ($results as $r) {
                $course = $r->course_name ?: 'General';
                $grouped[$course][] = $r;
            }
            
            // Calculate overall average
            $total_marks = 0;
            $count = 0;
            $passed = 0;
            foreach ($results as $r) {
                $total_marks += $r->score;
                $count++;
                if ($r->passed) $passed++;
            }
            $overall_avg = $count > 0 ? round($total_marks / $count, 1) : 0;
            $overall_grade = $overall_avg >= 80 ? 'DISTINCTION' : ($overall_avg >= 60 ? 'CREDIT' : ($overall_avg >= 50 ? 'PASS' : 'REFER'));
            
            // Summary card
            echo '<div class="mtti-card" style="background: linear-gradient(135deg, #2E7D32, #1976D2); color: white; padding: 20px; margin-bottom: 20px; border-radius: 10px;">';
            echo '<div style="display: flex; justify-content: space-around; text-align: center;">';
            echo '<div><div style="font-size: 2rem; font-weight: bold;">' . count($results) . '</div><div>Total Units</div></div>';
            echo '<div><div style="font-size: 2rem; font-weight: bold;">' . $passed . '</div><div>Passed</div></div>';
            echo '<div><div style="font-size: 2rem; font-weight: bold;">' . $overall_avg . '%</div><div>Average</div></div>';
            echo '<div><div style="font-size: 2rem; font-weight: bold;">' . $overall_grade . '</div><div>Overall Grade</div></div>';
            echo '</div></div>';
            
            foreach ($grouped as $course => $units) {
                echo '<div class="mtti-card" style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 10px;">';
                echo '<h3 style="margin: 0 0 15px; color: #333;"><span style="background: #2E7D32; color: white; padding: 3px 10px; border-radius: 5px; font-size: 0.8rem;">' . esc_html($course) . '</span></h3>';
                echo '<table class="mtti-table"><thead><tr><th>Unit Code</th><th>Unit Name</th><th>Marks</th><th>Grade</th><th>Status</th></tr></thead><tbody>';
                
                foreach ($units as $r) {
                    $grade_color = $r->grade == 'DISTINCTION' ? '#2E7D32' : ($r->grade == 'CREDIT' ? '#1976D2' : ($r->grade == 'PASS' ? '#FF9800' : '#D32F2F'));
                    echo '<tr>';
                    echo '<td>' . esc_html($r->unit_code) . '</td>';
                    echo '<td><strong>' . esc_html($r->unit_name) . '</strong></td>';
                    echo '<td>' . esc_html($r->score) . '/100</td>';
                    echo '<td><span style="background: ' . $grade_color . '; color: white; padding: 2px 8px; border-radius: 4px; font-weight: bold;">' . esc_html($r->grade) . '</span></td>';
                    echo '<td>' . ($r->passed ? '<span style="color:#2E7D32;">✓ Pass</span>' : '<span style="color:#D32F2F;">✗ Refer</span>') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
            
            // Print transcript button + certificate download
            echo '<div style="text-align: center; margin-top: 20px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">';
            echo '<button onclick="window.print()" class="mtti-btn mtti-btn-secondary" style="padding:12px 30px;">📜 Print Transcript</button>';

            // Check if certificate issued for any enrolled course
            $cert = $wpdb->get_row($wpdb->prepare(
                "SELECT c.certificate_number, c.verification_code, c.course_name, c.grade, c.completion_date, c.issue_date
                 FROM {$wpdb->prefix}mtti_certificates c
                 WHERE c.student_id = %d AND c.status = 'Valid'
                 ORDER BY c.issue_date DESC LIMIT 1",
                $student->student_id
            ));

            if ($cert) {
                $dl_url = add_query_arg(array(
                    'action'    => 'mtti_download_certificate',
                    'student_id'=> $student->student_id,
                    'cert_no'   => urlencode($cert->certificate_number),
                    'nonce'     => wp_create_nonce('mtti_cert_' . $student->student_id),
                ), admin_url('admin-ajax.php'));
                echo '<a href="' . esc_url($dl_url) . '" target="_blank" class="mtti-btn mtti-btn-primary" style="padding:12px 30px;">🎓 Download Certificate</a>';
                echo '</div>';
                echo '<div style="margin-top:12px;padding:12px 18px;background:#E8F5E9;border-radius:8px;border-left:4px solid #2E7D32;font-size:13px;">';
                echo '<strong>Certificate issued:</strong> ' . esc_html($cert->course_name) . ' · Grade: <strong>' . esc_html($cert->grade) . '</strong>';
                echo ' · Cert No: <code>' . esc_html($cert->certificate_number) . '</code>';
                echo ' · Issued: ' . date('d M Y', strtotime($cert->issue_date));
                echo '</div>';
            } else {
                // Show eligibility hint
                $all_passed = ($passed > 0 && $passed === $count);
                $balance    = floatval($wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(balance),0) FROM {$wpdb->prefix}mtti_student_balances WHERE student_id=%d",
                    $student->student_id
                )));
                echo '</div>';
                if ($all_passed && $balance <= 0) {
                    echo '<div style="margin-top:12px;padding:12px 18px;background:#FFF8E1;border-radius:8px;border-left:4px solid #F9A825;font-size:13px;">';
                    echo '🎓 You appear eligible for a certificate. Please contact MTTI admin to have it issued.';
                    echo '</div>';
                } elseif ($balance > 0) {
                    echo '<div style="margin-top:12px;padding:12px 18px;background:#FFEBEE;border-radius:8px;border-left:4px solid #C62828;font-size:13px;">';
                    echo '⚠️ Certificate requires a cleared balance. Outstanding: <strong>KES ' . number_format($balance, 2) . '</strong>';
                    echo '</div>';
                }
            }
            
        } else {
            echo '<div class="mtti-empty-state" style="text-align: center; padding: 60px 20px;">';
            echo '<span style="font-size: 4rem;">🏆</span>';
            echo '<h3>No Results Yet</h3>';
            echo '<p style="color: #666;">Your marks will appear here once entered by your instructor via Course Units.</p>';
            echo '</div>';
        }
        echo '</div>';
    }
    
    /**
     * Render Payments page
     */
    private function render_payments($student) {
        global $wpdb;
        
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mtti_payments WHERE student_id = %d ORDER BY payment_date DESC",
            $student->student_id
        ));
        
        // Calculate total paid from confirmed payments
        $total_paid = 0;
        foreach ($payments as $p) {
            if ($p->status === 'Completed' || $p->status === 'Confirmed') $total_paid += floatval($p->amount);
        }
        
        // Try student_balances table first (most accurate)
        // Recalculate live from actual payments — same as admin view — so figures always match.
        $balance_data = $wpdb->get_results($wpdb->prepare(
            "SELECT sb.enrollment_id, sb.discount_amount,
                    COALESCE(NULLIF(sb.total_fee, 0), c.fee) as actual_fee
             FROM {$wpdb->prefix}mtti_student_balances sb
             INNER JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
             LEFT JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE sb.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')",
            $student->student_id
        ));

        $total_fees   = 0;
        $total_paid   = 0;
        $balance      = 0;

        foreach ($balance_data as $br) {
            $net_fee     = max(0, floatval($br->actual_fee) - floatval($br->discount_amount));
            $paid_row    = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}mtti_payments
                 WHERE student_id = %d AND enrollment_id = %d AND status = 'Completed'",
                $student->student_id, $br->enrollment_id
            )));
            $total_fees  += floatval($br->actual_fee);
            $total_paid  += $paid_row;
            $balance     += max(0, $net_fee - $paid_row);
        }

        if (empty($balance_data)) {
            // Fallback: use course fee from enrollment
            $total_fees = floatval($student->fee ?: 0);
            $balance = $total_fees - $total_paid;
        }
        
        // Ensure balance is not negative
        if ($balance < 0) $balance = 0;
        
        echo '<div class="mtti-payments">';
        echo '<h2 class="mtti-page-title">💳 Payments & Fees</h2>';
        
        // Fee breakdown by course — recalculate paid live from actual payments
        $course_balances = $wpdb->get_results($wpdb->prepare(
            "SELECT c.course_name, c.course_code, sb.total_fee, sb.discount_amount,
                    e.enrollment_id,
                    COALESCE(NULLIF(sb.total_fee, 0), c.fee) as actual_fee
             FROM {$wpdb->prefix}mtti_student_balances sb
             INNER JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
             INNER JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
             WHERE sb.student_id = %d AND e.status IN ('Active', 'Enrolled', 'In Progress')
             ORDER BY c.course_name",
            $student->student_id
        ));

        // Attach live paid amount to each course row
        foreach ($course_balances as &$cb) {
            $cb_paid = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}mtti_payments
                 WHERE student_id = %d AND enrollment_id = %d AND status = 'Completed'
                   AND payment_method != 'Discount'",
                $student->student_id, $cb->enrollment_id
            )));
            $cb->live_paid    = $cb_paid;
            $cb->live_balance = max(0, floatval($cb->actual_fee) - floatval($cb->discount_amount) - $cb_paid);
        }
        unset($cb);

        echo '<div class="mtti-payment-summary">';
        echo '<div class="mtti-summary-card"><span>📚 Total Fees</span><strong>KES ' . number_format($total_fees ?: 0, 2) . '</strong></div>';
        echo '<div class="mtti-summary-card success"><span>💰 Total Paid</span><strong>KES ' . number_format($total_paid, 2) . '</strong></div>';
        $bc = $balance > 0 ? 'warning' : 'success';
        echo '<div class="mtti-summary-card ' . $bc . '"><span>📊 Balance</span><strong>KES ' . number_format($balance ?: 0, 2) . '</strong></div>';
        echo '</div>';

        // Show course-by-course breakdown
        if (!empty($course_balances)) {
            echo '<h3 style="margin-top: 25px;">📚 Fee Breakdown by Course</h3>';
            echo '<table class="mtti-table"><thead><tr><th>Course</th><th>Fee</th><th>Discount</th><th>Net Fee</th><th>Paid</th><th>Balance</th></tr></thead><tbody>';
            foreach ($course_balances as $cb) {
                $bal_color   = $cb->live_balance > 0 ? '#D32F2F' : '#2E7D32';
                $cb_discount = floatval($cb->discount_amount);
                $cb_net_fee  = max(0, floatval($cb->actual_fee) - $cb_discount);
                echo '<tr>';
                echo '<td><strong>' . esc_html($cb->course_name) . '</strong><br><small style="color:#666;">' . esc_html($cb->course_code) . '</small></td>';
                echo '<td>KES ' . number_format($cb->actual_fee, 2) . '</td>';
                echo '<td style="color: #2E7D32;">' . ($cb_discount > 0 ? '- KES ' . number_format($cb_discount, 2) : '<span style="color:#aaa;">—</span>') . '</td>';
                echo '<td>KES ' . number_format($cb_net_fee, 2) . '</td>';
                echo '<td style="color: #1976D2;">KES ' . number_format($cb->live_paid, 2) . '</td>';
                echo '<td style="color: ' . $bal_color . '; font-weight: bold;">KES ' . number_format($cb->live_balance, 2) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Payment history — deduplicated by payment_id, exclude discount records from cash list
        $cash_payments = array_filter($payments, function($p) { return $p->payment_method !== 'Discount'; });
        $discount_payments = array_filter($payments, function($p) { return $p->payment_method === 'Discount'; });

        // Show discount records first if any
        if (!empty($discount_payments)) {
            echo '<h3 style="margin-top: 25px;">🎓 Discounts / Scholarships</h3>';
            echo '<table class="mtti-table"><thead><tr><th>Reference</th><th>Date</th><th>Description</th><th>Amount</th></tr></thead><tbody>';
            foreach ($discount_payments as $p) {
                echo '<tr>';
                echo '<td>' . esc_html($p->receipt_number) . '</td>';
                echo '<td>' . date('M j, Y', strtotime($p->payment_date)) . '</td>';
                echo '<td>' . esc_html($p->payment_for) . '</td>';
                echo '<td style="color:#e65100; font-weight:bold;">- KES ' . number_format($p->discount, 2) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($cash_payments)) {
            echo '<h3 style="margin-top: 25px;">📜 Payment History</h3>';
            echo '<table class="mtti-table"><thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
            foreach ($cash_payments as $p) {
                echo '<tr>';
                echo '<td>' . esc_html($p->receipt_number) . '</td>';
                echo '<td>' . date('M j, Y', strtotime($p->payment_date)) . '</td>';
                echo '<td>' . esc_html($p->payment_method) . '</td>';
                echo '<td style="font-weight:bold; color:#2E7D32;">KES ' . number_format($p->amount, 2) . '</td>';
                echo '<td><span class="mtti-status-badge">' . esc_html($p->status) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div style="background: #fff3e0; padding: 20px; border-radius: 8px; text-align: center; margin-top: 20px;">';
            echo '<p style="margin: 0; color: #e65100;">No payment records found yet.</p>';
            echo '</div>';
        }
        
        echo '<div class="mtti-payment-instructions"><h3>💡 How to Pay — Lipa na M-PESA</h3>';
        echo '<div class="mtti-instructions-grid">';
        echo '<div class="mtti-instruction-card"><h4>📱 M-PESA Paybill</h4><ol>
            <li>Go to <strong>M-PESA</strong> on your phone</li>
            <li>Select <strong>Lipa na M-PESA</strong></li>
            <li>Select <strong>Pay Bill</strong></li>
            <li>Business Number: <strong>880100</strong></li>
            <li>Account Number: <strong>219391</strong></li>
            <li>Enter the <strong>Amount</strong></li>
            <li>Enter your <strong>M-PESA PIN</strong></li>
            <li>Confirm and <strong>Send</strong></li></ol>
            <div style="margin-top:14px;padding:12px;background:#e8f5e9;border-radius:8px;border:1px solid #c8e6c9;">
                <strong style="color:#2E7D32;display:block;margin-bottom:4px;">📌 Important:</strong>
                <span style="font-size:12px;color:#333;">Use Account Number <strong>219391</strong> and keep your M-PESA confirmation message. Share it with the accounts office for faster reconciliation.</span>
            </div>
        </div>';
        echo '<div class="mtti-instruction-card"><h4>🏦 Bank Deposit — NCBA Bank</h4><ol>
            <li>Bank: <strong>NCBA Bank</strong></li>
            <li>Account Name: <strong>Masomotele Technical Training Institute</strong></li>
            <li>Account Number: <strong>1006329155</strong></li>
            <li>Reference: <strong>' . esc_html($student->admission_number) . '</strong></li></ol>
            <div style="margin-top:14px;padding:12px;background:#fff3e0;border-radius:8px;border:1px solid #ffe0b2;">
                <strong style="color:#E65100;display:block;margin-bottom:4px;">📞 Need Help?</strong>
                <span style="font-size:12px;color:#333;">Visit the accounts office at Sagaas Center, 4th Floor, Eldoret or call the admin for assistance.</span>
            </div>
        </div>';
        echo '</div></div>';
        echo '</div>';
    }
    
    /* ══════════════════════════════════════════════════
     *  NOTIFICATIONS — full page tab
     * ══════════════════════════════════════════════════ */
    private function render_notifications($student) {
        global $wpdb;
        $ntbl = $wpdb->prefix . 'mtti_notifications';

        // Mark all as read when page is opened
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ntbl}'")) {
            $wpdb->update($ntbl, ['is_read' => 1], ['student_id' => $student->student_id], ['%d'], ['%d']);
        }

        // Filter by type
        $filter    = sanitize_key($_GET['notif_type'] ?? 'all');
        $per_page  = 20;
        $page      = max(1, intval($_GET['npage'] ?? 1));
        $offset    = ($page - 1) * $per_page;

        $where = "WHERE student_id = {$student->student_id}";
        if ($filter !== 'all') $where .= $wpdb->prepare(" AND type = %s", $filter);

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$ntbl} {$where}") ?: 0;
        $notifications = $wpdb->get_results(
            "SELECT * FROM {$ntbl} {$where} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}"
        );

        $portal_url = get_permalink();

        // Type icons & colours
        $type_cfg = [
            'fee'     => ['icon'=>'💳','bg'=>'#E3F2FD','border'=>'#1565C0','label'=>'Fee'],
            'lesson'  => ['icon'=>'📖','bg'=>'#E8F5E9','border'=>'#2E7D32','label'=>'Lesson'],
            'quiz'    => ['icon'=>'🧠','bg'=>'#EDE7F6','border'=>'#6A1B9A','label'=>'Quiz'],
            'success' => ['icon'=>'✅','bg'=>'#E8F5E9','border'=>'#2E7D32','label'=>'Success'],
            'warning' => ['icon'=>'⚠️','bg'=>'#FFF8E1','border'=>'#F9A825','label'=>'Warning'],
            'info'    => ['icon'=>'ℹ️','bg'=>'#E3F2FD','border'=>'#1565C0','label'=>'Info'],
        ];

        echo '<div class="mtti-notifications-page">';
        echo '<h2 class="mtti-page-title">📬 Notifications</h2>';

        // Filter pills
        $types_with_counts = $wpdb->get_results(
            "SELECT type, COUNT(*) as cnt FROM {$ntbl} WHERE student_id={$student->student_id} GROUP BY type"
        );
        $counts_by_type = [];
        foreach ($types_with_counts as $r) $counts_by_type[$r->type] = $r->cnt;

        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">';
        $all_url = add_query_arg(['portal_tab'=>'notifications','notif_type'=>'all'], $portal_url);
        $active_style  = 'background:var(--mtti-primary);color:#fff;border-color:var(--mtti-primary);';
        $inactive_style = 'background:#fff;color:var(--text-secondary);border-color:var(--border);';
        echo '<a href="'.esc_url($all_url).'" style="padding:5px 14px;border-radius:20px;border:1px solid;font-size:12px;font-weight:600;text-decoration:none;'.($filter==='all'?$active_style:$inactive_style).'">All ('.$total.')</a>';
        foreach ($type_cfg as $t => $cfg) {
            if (empty($counts_by_type[$t])) continue;
            $t_url = add_query_arg(['portal_tab'=>'notifications','notif_type'=>$t], $portal_url);
            echo '<a href="'.esc_url($t_url).'" style="padding:5px 14px;border-radius:20px;border:1px solid;font-size:12px;font-weight:600;text-decoration:none;'.($filter===$t?$active_style:$inactive_style).'">'
                .$cfg['icon'].' '.$cfg['label'].' ('.$counts_by_type[$t].')</a>';
        }
        echo '</div>';

        if (empty($notifications)) {
            echo '<div class="mtti-empty-state" style="padding:60px 20px;text-align:center;">';
            echo '<span style="font-size:4rem;">📭</span>';
            echo '<h3 style="margin:12px 0 6px;">All caught up!</h3>';
            echo '<p style="color:var(--text-3);">No notifications yet. They\'ll appear here when fees are recorded, new lessons are posted, or quizzes are added.</p>';
            echo '</div>';
        } else {
            echo '<div style="display:flex;flex-direction:column;gap:10px;">';
            foreach ($notifications as $n) {
                $cfg  = $type_cfg[$n->type] ?? ['icon'=>'🔔','bg'=>'#f5f5f5','border'=>'#ccc'];
                $time = human_time_diff(strtotime($n->created_at), current_time('timestamp')) . ' ago';
                echo '<div style="background:'.$cfg['bg'].';border-left:4px solid '.$cfg['border'].';border-radius:8px;padding:14px 18px;display:flex;gap:14px;align-items:flex-start;">';
                echo '<span style="font-size:24px;flex-shrink:0;">'.$cfg['icon'].'</span>';
                echo '<div style="flex:1;min-width:0;">';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">';
                echo '<strong style="font-size:14px;color:var(--text-1);">'.esc_html($n->title).'</strong>';
                echo '<span style="font-size:11px;color:var(--text-3);white-space:nowrap;">'.$time.'</span>';
                echo '</div>';
                echo '<p style="font-size:13px;color:var(--text-2);margin:4px 0 0;line-height:1.5;">'.esc_html($n->message).'</p>';
                if (!empty($n->link)) {
                    echo '<a href="'.esc_url($n->link).'" style="font-size:12px;color:'.($cfg['border']).';margin-top:6px;display:inline-block;font-weight:600;">View →</a>';
                }
                echo '</div></div>';
            }
            echo '</div>';

            // Pagination
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div style="display:flex;justify-content:center;gap:8px;margin-top:20px;">';
                for ($p = 1; $p <= $total_pages; $p++) {
                    $p_url = add_query_arg(['portal_tab'=>'notifications','notif_type'=>$filter,'npage'=>$p], $portal_url);
                    $is_cur = ($p === $page);
                    echo '<a href="'.esc_url($p_url).'" style="padding:6px 12px;border-radius:6px;border:1px solid var(--border);font-size:13px;font-weight:600;text-decoration:none;'
                        .($is_cur?'background:var(--mtti-primary);color:#fff;':'background:#fff;color:var(--text-2);').'">'.$p.'</a>';
                }
                echo '</div>';
            }
        }
        echo '</div>';
    }

    private function render_notices($student) {
        global $wpdb;

        $notices = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mtti_notices 
             WHERE status = 'Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())
             AND target_audience IN ('All', 'Students')
             ORDER BY priority DESC, created_at DESC"
        );
        
        echo '<div class="mtti-notices-page">';
        echo '<h2 class="mtti-page-title">Notice Board</h2>';
        
        if (!empty($notices)) {
            echo '<div class="mtti-notices-grid">';
            foreach ($notices as $n) {
                $pc = strtolower($n->priority);
                echo '<div class="mtti-notice-card priority-' . $pc . '">';
                echo '<div class="mtti-notice-header">';
                echo '<span class="mtti-notice-category">' . esc_html($n->category) . '</span>';
                if ($n->priority === 'High' || $n->priority === 'Urgent') {
                    echo '<span class="mtti-priority-badge">' . esc_html($n->priority) . '</span>';
                }
                echo '</div>';
                echo '<h3>' . esc_html($n->title) . '</h3>';
                echo '<div class="mtti-notice-content">' . wp_kses_post(nl2br($n->content)) . '</div>';
                echo '<div class="mtti-notice-footer">📅 ' . date('M j, Y', strtotime($n->created_at)) . '</div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="mtti-empty-state"><span>🔔</span><h3>No Notices</h3></div>';
        }
        echo '</div>';
    }
    
    private function render_profile($student) {
        $password_message = '';
        $password_error = '';
        
        // Handle password change form submission
        if (isset($_POST['mtti_change_password']) && isset($_POST['mtti_password_nonce'])) {
            if (wp_verify_nonce($_POST['mtti_password_nonce'], 'mtti_change_password_action')) {
                $current_password = $_POST['current_password'];
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];
                
                $user = wp_get_current_user();
                
                // Validate current password
                if (!wp_check_password($current_password, $user->user_pass, $user->ID)) {
                    $password_error = 'Current password is incorrect.';
                } elseif (strlen($new_password) < 6) {
                    $password_error = 'New password must be at least 6 characters.';
                } elseif ($new_password !== $confirm_password) {
                    $password_error = 'New passwords do not match. Please try again.';
                } else {
                    // Update password
                    wp_set_password($new_password, $user->ID);
                    
                    // Re-authenticate user
                    wp_set_current_user($user->ID);
                    wp_set_auth_cookie($user->ID);
                    
                    $password_message = 'Password changed successfully!';
                }
            }
        }
        
        echo '<div class="mtti-profile">';
        echo '<h2 class="mtti-page-title">My Profile</h2>';
        
        echo '<div class="mtti-profile-grid">';
        echo '<div class="mtti-profile-card">';
        echo get_avatar($student->user_id, 120);
        echo '<h3>' . esc_html($student->display_name) . '</h3>';
        echo '<p>' . esc_html($student->admission_number) . '</p>';
        echo '<span class="mtti-status-badge">' . esc_html($student->status) . '</span>';
        echo '</div>';
        
        echo '<div class="mtti-profile-details">';
        echo '<h3>Personal Information</h3>';
        echo '<div class="mtti-detail-grid">';
        echo '<div><strong>Full Name:</strong> ' . esc_html($student->display_name) . '</div>';
        echo '<div><strong>Email:</strong> ' . esc_html($student->user_email) . '</div>';
        echo '<div><strong>Admission No:</strong> ' . esc_html($student->admission_number) . '</div>';
        if ($student->id_number) echo '<div><strong>ID Number:</strong> ' . esc_html($student->id_number) . '</div>';
        if ($student->gender) echo '<div><strong>Gender:</strong> ' . esc_html($student->gender) . '</div>';
        if ($student->county) echo '<div><strong>County:</strong> ' . esc_html($student->county) . '</div>';
        echo '<div><strong>Enrolled:</strong> ' . date('M j, Y', strtotime($student->enrollment_date)) . '</div>';
        echo '</div>';
        
        if ($student->emergency_contact) {
            echo '<h3>Emergency Contact</h3>';
            echo '<div class="mtti-detail-grid">';
            echo '<div><strong>Name:</strong> ' . esc_html($student->emergency_contact) . '</div>';
            if ($student->emergency_phone) echo '<div><strong>Phone:</strong> ' . esc_html($student->emergency_phone) . '</div>';
            echo '</div>';
        }
        
        // Password Change Section
        echo '<div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #eee;">';
        echo '<h3>🔐 Change Password</h3>';
        
        if ($password_message) {
            echo '<div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 6px; margin-bottom: 15px;">✓ ' . esc_html($password_message) . '</div>';
        }
        if ($password_error) {
            echo '<div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 15px;">⚠️ ' . esc_html($password_error) . '</div>';
        }
        
        echo '<form method="post" action="" style="max-width: 400px;">';
        wp_nonce_field('mtti_change_password_action', 'mtti_password_nonce');
        echo '<input type="hidden" name="mtti_change_password" value="1">';
        
        echo '<div style="margin-bottom: 15px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 500;">Current Password</label>';
        echo '<input type="password" name="current_password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">';
        echo '</div>';
        
        echo '<div style="margin-bottom: 15px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 500;">New Password</label>';
        echo '<input type="password" name="new_password" required minlength="6" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" placeholder="At least 6 characters">';
        echo '</div>';
        
        echo '<div style="margin-bottom: 15px;">';
        echo '<label style="display: block; margin-bottom: 5px; font-weight: 500;">Confirm New Password</label>';
        echo '<input type="password" name="confirm_password" required minlength="6" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" placeholder="Re-enter new password">';
        echo '</div>';
        
        echo '<button type="submit" class="mtti-btn mtti-btn-primary" style="width: 100%;">🔐 Change Password</button>';
        echo '</form>';
        echo '</div>';
        
        echo '</div></div></div>';
    }
    
    public function ajax_submit_assignment() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in.'));
        }
        
        $student = $this->get_current_student();
        if (!$student) {
            wp_send_json_error(array('message' => 'Student not found.'));
        }
        
        $assignment_id = intval($_POST['assignment_id']);
        if (!$assignment_id) {
            wp_send_json_error(array('message' => 'Invalid assignment.'));
        }
        
        if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'Please upload a file.'));
        }
        
        $allowed = array('pdf', 'doc', 'docx', 'zip', 'txt');
        $ext = strtolower(pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            wp_send_json_error(array('message' => 'Invalid file type.'));
        }
        
        if ($_FILES['submission_file']['size'] > 10 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File too large.'));
        }
        
        $upload_dir = wp_upload_dir();
        $mtti_dir = $upload_dir['basedir'] . '/mtti-mis/submissions';
        if (!file_exists($mtti_dir)) wp_mkdir_p($mtti_dir);
        
        $filename = $student->admission_number . '_' . $assignment_id . '_' . time() . '.' . $ext;
        $filepath = $mtti_dir . '/' . $filename;
        
        if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $filepath)) {
            wp_send_json_error(array('message' => 'Upload failed.'));
        }
        
        global $wpdb;
        $result = $wpdb->insert($wpdb->prefix . 'mtti_assignment_submissions', array(
            'assignment_id' => $assignment_id,
            'student_id' => $student->student_id,
            'file_path' => $upload_dir['baseurl'] . '/mtti-mis/submissions/' . $filename,
            'file_name' => sanitize_file_name($_FILES['submission_file']['name']),
            'notes' => sanitize_textarea_field($_POST['notes']),
            'submitted_at' => current_time('mysql'),
            'status' => 'Submitted'
        ));
        
        if ($result) {
            wp_send_json_success(array('message' => 'Assignment submitted!'));
        } else {
            wp_send_json_error(array('message' => 'Save failed.'));
        }
    }
    
    /**
     * Render Materials/Downloads page
     */
    /**
     * Render Lessons page for learners
     */
    private function render_lessons($student) {
        global $wpdb;
        
        // Check if viewing a specific lesson
        if (isset($_GET['lesson_id'])) {
            $this->render_single_lesson($student, intval($_GET['lesson_id']));
            return;
        }
        
        // Get all enrolled course IDs
        $enrolled_ids = $this->get_enrolled_course_ids($student);
        
        // Course filter (shows switcher if multi-enrolled)
        echo '<div class="mtti-lessons">';
        echo '<h2 class="mtti-page-title">📖 Lessons & Materials</h2>';
        echo '<p style="color:var(--text-2);margin-bottom:20px;">All your course content in one place — lessons, videos, notes, and downloadable files.</p>';
        
        $filter_course = $this->render_course_filter($student, 'lessons');
        
        // Determine which course IDs to query
        if ($filter_course > 0 && in_array($filter_course, $enrolled_ids)) {
            $query_ids = array($filter_course);
        } elseif (!empty($enrolled_ids)) {
            $query_ids = $enrolled_ids;
        } elseif (!empty($student->course_id)) {
            $query_ids = array(intval($student->course_id));
        } else {
            $query_ids = array();
        }
        
        $lessons   = array();
        $quizzes   = array();
        $materials = array();
        if (!empty($query_ids)) {
            $placeholders = implode(',', array_fill(0, count($query_ids), '%d'));
            
            // Real lessons — exclude AI practice quizzes
            $lessons = $wpdb->get_results($wpdb->prepare(
                "SELECT l.*, cu.unit_name, cu.unit_code, c.course_name, c.course_code
                 FROM {$wpdb->prefix}mtti_lessons l
                 LEFT JOIN {$wpdb->prefix}mtti_course_units cu ON l.unit_id = cu.unit_id
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON l.course_id = c.course_id
                 WHERE l.course_id IN ($placeholders) AND l.status = 'Published'
                   AND l.title NOT LIKE '🤖 Quiz:%'
                 ORDER BY c.course_name, l.unit_id, l.order_number ASC",
                ...$query_ids
            ));

            // Practice quizzes — shown separately, not in transcripts or results
            $quizzes = $wpdb->get_results($wpdb->prepare(
                "SELECT l.*, c.course_name, c.course_code
                 FROM {$wpdb->prefix}mtti_lessons l
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON l.course_id = c.course_id
                 WHERE l.course_id IN ($placeholders) AND l.status = 'Published'
                   AND l.title LIKE '🤖 Quiz:%'
                 ORDER BY l.created_at DESC",
                ...$query_ids
            ));
            
            $materials = $wpdb->get_results($wpdb->prepare(
                "SELECT m.*, cu.unit_name, cu.unit_code, c.course_name, c.course_code
                 FROM {$wpdb->prefix}mtti_materials m
                 LEFT JOIN {$wpdb->prefix}mtti_course_units cu ON m.unit_id = cu.unit_id
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON m.course_id = c.course_id
                 WHERE m.course_id IN ($placeholders) AND m.status = 'Active'
                 ORDER BY c.course_name, m.unit_id, m.created_at ASC",
                ...$query_ids
            ));
        }
        
        if (!empty($lessons) || !empty($materials)) {
            // Group lessons by course, then by unit
            $by_course = array();
            foreach ($lessons as $lesson) {
                $ckey = $lesson->course_id ?: 0;
                $unit_key = $lesson->unit_id ?: 'general';
                if (!isset($by_course[$ckey])) {
                    $by_course[$ckey] = array(
                        'course_name' => $lesson->course_name ?: 'General',
                        'course_code' => $lesson->course_code ?: '',
                        'units' => array()
                    );
                }
                if (!isset($by_course[$ckey]['units'][$unit_key])) {
                    $by_course[$ckey]['units'][$unit_key] = array(
                        'name' => $lesson->unit_name ?: 'General',
                        'code' => $lesson->unit_code ?: '',
                        'lessons' => array(),
                        'materials' => array()
                    );
                }
                $by_course[$ckey]['units'][$unit_key]['lessons'][] = $lesson;
            }
            
            // Add materials to their course/unit groups
            foreach ($materials as $m) {
                $ckey = $m->course_id ?: 0;
                $unit_key = $m->unit_id ?: 'general';
                if (!isset($by_course[$ckey])) {
                    $by_course[$ckey] = array(
                        'course_name' => $m->course_name ?: 'General',
                        'course_code' => $m->course_code ?: '',
                        'units' => array()
                    );
                }
                if (!isset($by_course[$ckey]['units'][$unit_key])) {
                    $by_course[$ckey]['units'][$unit_key] = array(
                        'name' => $m->unit_name ?: 'General',
                        'code' => $m->unit_code ?: '',
                        'lessons' => array(),
                        'materials' => array()
                    );
                }
                $by_course[$ckey]['units'][$unit_key]['materials'][] = $m;
            }
            
            $show_course_headers = (count($by_course) > 1);
            
            foreach ($by_course as $ckey => $course_data) {
                // Course header when showing multiple courses
                if ($show_course_headers) {
                    echo '<div style="margin:24px 0 12px;padding:12px 18px;background:linear-gradient(135deg,#1B5E20,#2E7D32);border-radius:8px;color:#fff;display:flex;align-items:center;gap:10px;">';
                    echo '<span style="font-size:20px;">📚</span>';
                    echo '<div><span style="font-size:11px;opacity:.8;">' . esc_html($course_data['course_code']) . '</span>';
                    echo '<h3 style="margin:0;font-size:15px;color:#fff;">' . esc_html($course_data['course_name']) . '</h3></div>';
                    echo '</div>';
                }
                
                foreach ($course_data['units'] as $unit_key => $unit_data) {
                $lcount = count($unit_data['lessons']);
                $mcount = count($unit_data['materials']);
                
                echo '<div class="mtti-card" style="margin-bottom:18px;padding:0;overflow:hidden;">';
                
                // Unit header
                echo '<div style="padding:16px 20px;background:var(--bg-subtle);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">';
                echo '<div>';
                if ($unit_data['code']) {
                    echo '<span class="mtti-badge" style="margin-right:8px;">' . esc_html($unit_data['code']) . '</span>';
                }
                echo '<strong style="font-size:15px;">' . esc_html($unit_data['name']) . '</strong>';
                echo '</div>';
                echo '<div style="display:flex;gap:12px;font-size:12px;color:var(--text-3);">';
                if ($lcount) echo '<span>📖 ' . $lcount . ' lesson' . ($lcount != 1 ? 's' : '') . '</span>';
                if ($mcount) echo '<span>📥 ' . $mcount . ' file' . ($mcount != 1 ? 's' : '') . '</span>';
                echo '</div>';
                echo '</div>';
                
                echo '<div style="padding:14px 20px;">';
                
                // Lessons
                foreach ($unit_data['lessons'] as $index => $lesson) {
                    $type_icons = array('video' => '🎬', 'pdf' => '📕', 'document' => '📘', 'presentation' => '📙', 'audio' => '🎵', 'text' => '📝', 'file' => '📄', 'html_interactive' => '⚡');
                    $icon = $type_icons[$lesson->content_type] ?? '📄';
                    $lesson_url = add_query_arg(array('portal_tab' => 'lessons', 'lesson_id' => $lesson->lesson_id), get_permalink());
                    
                    echo '<div style="display:flex;align-items:center;gap:14px;padding:12px 0;' . ($index > 0 ? 'border-top:1px solid var(--border);' : '') . '">';
                    echo '<div style="width:36px;height:36px;background:var(--mtti-primary-xl);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--mtti-primary);flex-shrink:0;font-size:13px;">';
                    echo intval($lesson->order_number ?: ($index + 1));
                    echo '</div>';
                    echo '<div style="flex:1;min-width:0;">';
                    echo '<a href="' . esc_url($lesson_url) . '" style="color:var(--text-1);text-decoration:none;font-weight:600;font-size:14px;">' . esc_html($lesson->title) . '</a>';
                    echo '<div style="display:flex;gap:12px;font-size:11px;color:var(--text-3);margin-top:3px;">';
                    echo '<span>' . $icon . ' ' . ucfirst($lesson->content_type ?? 'lesson') . '</span>';
                    if ($lesson->duration_minutes) echo '<span>⏱ ' . intval($lesson->duration_minutes) . ' min</span>';
                    echo '</div></div>';
                    echo '<a href="' . esc_url($lesson_url) . '" class="mtti-btn mtti-btn-primary" style="padding:6px 14px;font-size:12px;flex-shrink:0;">Open</a>';
                    echo '</div>';
                }
                
                // Materials for this unit
                if (!empty($unit_data['materials'])) {
                    if (!empty($unit_data['lessons'])) {
                        echo '<div style="border-top:2px dashed var(--border);margin:8px 0;padding-top:10px;">';
                        echo '<span style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;">📥 Downloadable Files</span>';
                        echo '</div>';
                    }
                    foreach ($unit_data['materials'] as $m) {
                        $file_icon = $this->get_file_icon($m->file_type);
                        $size = $m->file_size ? $this->format_file_size($m->file_size) : '';
                        
                        echo '<div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-top:1px solid var(--border);">';
                        echo '<span style="font-size:28px;flex-shrink:0;">' . $file_icon . '</span>';
                        echo '<div style="flex:1;min-width:0;">';
                        echo '<strong style="font-size:13px;color:var(--text-1);">' . esc_html($m->title) . '</strong>';
                        if ($m->description) echo '<div style="font-size:12px;color:var(--text-3);margin-top:2px;">' . esc_html(wp_trim_words($m->description, 12)) . '</div>';
                        echo '<div style="font-size:11px;color:var(--text-3);margin-top:3px;">';
                        if ($size) echo '📁 ' . $size . ' · ';
                        echo '📅 ' . date('M j, Y', strtotime($m->created_at));
                        echo '</div></div>';
                        echo '<a href="' . esc_url($m->file_url) . '" class="mtti-btn mtti-btn-secondary" style="padding:6px 14px;font-size:12px;flex-shrink:0;" target="_blank" download>📥 Download</a>';
                        echo '</div>';
                    }
                }
                
                echo '</div></div>';
            }
            } // end course loop

            // ── PRACTICE QUIZZES — separate section, not in results/transcript ──
            if (!empty($quizzes)) {
                echo '<div style="margin-top:28px;">';
                echo '<div style="padding:12px 18px;background:linear-gradient(135deg,#4a148c,#7B1FA2);border-radius:8px;color:#fff;display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
                echo '<span style="font-size:20px;">🤖</span>';
                echo '<div>';
                echo '<span style="font-size:11px;opacity:.8;display:block;">PRACTICE ONLY · scores not recorded in results or transcripts</span>';
                echo '<h3 style="margin:0;font-size:15px;color:#fff;">Practice Quizzes</h3>';
                echo '</div></div>';
                foreach ($quizzes as $quiz) {
                    $quiz_url  = add_query_arg(array('portal_tab' => 'lessons', 'lesson_id' => $quiz->lesson_id), get_permalink());
                    $quiz_name = preg_replace('/^🤖 Quiz:\s*/u', '', $quiz->title);

                    // Fetch best score + attempt count for this student
                    $attempt_stats = $wpdb->get_row($wpdb->prepare(
                        "SELECT COUNT(*) AS attempts, MAX(percent) AS best_pct, MAX(score) AS best_score, MAX(total) AS best_total
                         FROM {$wpdb->prefix}mtti_quiz_attempts
                         WHERE lesson_id = %d AND student_id = %d",
                        $quiz->lesson_id, $student->student_id
                    ));
                    $attempts  = intval($attempt_stats->attempts ?? 0);
                    $best_pct  = $attempt_stats->best_pct !== null ? round(floatval($attempt_stats->best_pct)) : null;
                    $best_score = $attempt_stats->best_score;
                    $best_total = $attempt_stats->best_total;

                    echo '<div class="mtti-card" style="margin-bottom:10px;display:flex;align-items:center;gap:14px;padding:14px 18px;">';
                    echo '<span style="font-size:28px;">📝</span>';
                    echo '<div style="flex:1;min-width:0;">';
                    echo '<strong style="font-size:14px;color:var(--text-1);">' . esc_html($quiz_name) . '</strong>';
                    echo '<div style="font-size:11px;color:var(--text-3);margin-top:3px;">';
                    echo date('d M Y', strtotime($quiz->created_at)) . ' · ' . esc_html($quiz->course_code ?? $quiz->course_name ?? '');
                    echo '</div>';

                    if ($attempts > 0) {
                        // Score badge colour: green ≥70, orange ≥50, red <50
                        $badge_bg = $best_pct >= 70 ? '#2E7D32' : ($best_pct >= 50 ? '#E65100' : '#C62828');
                        echo '<div style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
                        echo '<span style="background:' . $badge_bg . ';color:#fff;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;">';
                        echo 'Best: ' . intval($best_score) . '/' . intval($best_total) . ' (' . $best_pct . '%)';
                        echo '</span>';
                        echo '<span style="font-size:11px;color:var(--text-3);">🔁 ' . $attempts . ' attempt' . ($attempts > 1 ? 's' : '') . '</span>';
                        echo '</div>';
                    }

                    echo '</div>';
                    $btn_label = $attempts > 0 ? 'Retry →' : 'Start →';
                    echo '<a href="' . esc_url($quiz_url) . '" class="mtti-btn mtti-btn-primary" style="padding:6px 16px;font-size:12px;flex-shrink:0;">' . $btn_label . '</a>';
                    echo '</div>';
                }
                echo '</div>';
            }

        } else {
            echo '<div class="mtti-empty-state"><span>📖</span><h3>No Lessons Available Yet</h3><p style="color:var(--text-3);">Lessons and materials will appear here when your instructors upload them.</p></div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render single lesson view
     */
    private function render_single_lesson($student, $lesson_id) {
        global $wpdb;
        
        $lesson = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, cu.unit_name, cu.unit_code, c.course_name, c.course_code
             FROM {$wpdb->prefix}mtti_lessons l
             LEFT JOIN {$wpdb->prefix}mtti_course_units cu ON l.unit_id = cu.unit_id
             LEFT JOIN {$wpdb->prefix}mtti_courses c ON l.course_id = c.course_id
             WHERE l.lesson_id = %d AND l.status = 'Published'",
            $lesson_id
        ));
        
        if (!$lesson) {
            echo '<div class="mtti-empty-state"><h3>Lesson not found</h3></div>';
            return;
        }
        
        // Check if student has access (enrolled in course or free preview)
        $enrolled_ids = $this->get_enrolled_course_ids($student);
        if (!in_array(intval($lesson->course_id), $enrolled_ids) && !$lesson->is_free_preview) {
            echo '<div class="mtti-empty-state"><h3>Access Denied</h3><p>You are not enrolled in this course.</p></div>';
            return;
        }
        
        // Update total view count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}mtti_lessons SET view_count = view_count + 1 WHERE lesson_id = %d",
            $lesson_id
        ));

        // Track this student's view (for progress calculation) — skip practice quizzes
        $is_practice_quiz = (strpos($lesson->title ?? '', '🤖 Quiz:') === 0);
        if (!$is_practice_quiz) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}mtti_lesson_views (lesson_id, student_id, viewed_at)
                 VALUES (%d, %d, NOW())
                 ON DUPLICATE KEY UPDATE viewed_at = NOW()",
                $lesson_id, $student->student_id
            ));
        }
        
        // Get previous and next lessons
        $prev_lesson = $wpdb->get_row($wpdb->prepare(
            "SELECT lesson_id, title FROM {$wpdb->prefix}mtti_lessons 
             WHERE course_id = %d AND status = 'Published' 
             AND (unit_id = %d OR (unit_id IS NULL AND %d IS NULL))
             AND order_number < %d 
             ORDER BY order_number DESC LIMIT 1",
            $lesson->course_id, $lesson->unit_id, $lesson->unit_id, $lesson->order_number
        ));
        
        $next_lesson = $wpdb->get_row($wpdb->prepare(
            "SELECT lesson_id, title FROM {$wpdb->prefix}mtti_lessons 
             WHERE course_id = %d AND status = 'Published' 
             AND (unit_id = %d OR (unit_id IS NULL AND %d IS NULL))
             AND order_number > %d 
             ORDER BY order_number ASC LIMIT 1",
            $lesson->course_id, $lesson->unit_id, $lesson->unit_id, $lesson->order_number
        ));
        
        $back_url = add_query_arg('portal_tab', 'lessons', get_permalink());
        
        echo '<div class="mtti-single-lesson">';
        
        // Breadcrumb
        echo '<div style="margin-bottom: 20px;">';
        echo '<a href="' . esc_url($back_url) . '" style="color: #1976D2; text-decoration: none;">← Back to Lessons</a>';
        echo '</div>';
        
        // Lesson header
        echo '<div style="background: white; border-radius: 8px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
        
        // Course/Unit badges
        echo '<div style="margin-bottom: 10px;">';
        echo '<span style="background: #e3f2fd; padding: 4px 12px; border-radius: 4px; font-size: 12px; color: #1976D2;">' . esc_html($lesson->course_code) . '</span>';
        if ($lesson->unit_name) {
            echo ' <span style="background: #f3e5f5; padding: 4px 12px; border-radius: 4px; font-size: 12px; color: #7B1FA2;">' . esc_html($lesson->unit_code) . '</span>';
        }
        echo '</div>';
        
        echo '<h2 style="margin: 10px 0;">' . esc_html($lesson->title) . '</h2>';
        
        if ($lesson->description) {
            echo '<p style="color: #666; margin: 0;">' . esc_html($lesson->description) . '</p>';
        }
        
        // Meta
        echo '<div style="margin-top: 15px; display: flex; gap: 20px; font-size: 13px; color: #888;">';
        $type_icons = array('video' => '🎬', 'pdf' => '📕', 'document' => '📘', 'presentation' => '📙', 'audio' => '🎵', 'text' => '📝', 'file' => '📄', 'html_interactive' => '⚡');
        echo '<span>' . ($type_icons[$lesson->content_type] ?? '📄') . ' ' . ucfirst($lesson->content_type) . '</span>';
        if ($lesson->duration_minutes) {
            echo '<span>⏱️ ' . intval($lesson->duration_minutes) . ' minutes</span>';
        }
        echo '<span>👁️ ' . number_format(intval($lesson->view_count)) . ' views</span>';
        echo '</div>';
        
        echo '</div>';
        
        // Video content
        if ($lesson->content_type == 'video' && $lesson->content_url) {
            echo '<div style="background: #000; border-radius: 8px; overflow: hidden; margin-bottom: 20px;">';
            echo $this->embed_video_player($lesson->content_url);
            echo '</div>';
        }
        
        // File download
        if ($lesson->content_url && !in_array($lesson->content_type, array('video', 'text'))) {
            echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
            echo '<span style="font-size: 40px;">' . ($type_icons[$lesson->content_type] ?? '📄') . '</span>';
            echo '<div style="flex: 1;">';
            echo '<strong>' . esc_html(basename($lesson->content_url)) . '</strong>';
            if ($lesson->file_size) {
                echo '<br><small style="color: #666;">' . size_format($lesson->file_size) . '</small>';
            }
            echo '</div>';
            echo '<a href="' . esc_url($lesson->content_url) . '" class="mtti-btn mtti-btn-primary" target="_blank" download>📥 Download</a>';
            echo '</div>';
        }
        
        // Lesson content
        if ($lesson->content) {
            if ($lesson->content_type === 'html_interactive') {
                // Render interactive HTML in a sandboxed iframe (full-page, scripts allowed)
                // We serve it via a dedicated AJAX endpoint so scripts aren't stripped
                $iframe_url = add_query_arg(array(
                    'action'    => 'mtti_serve_interactive',
                    'lesson_id' => $lesson->lesson_id,
                    'nonce'     => wp_create_nonce('serve_interactive_' . $lesson->lesson_id),
                ), admin_url('admin-ajax.php'));
                echo '<div style="background:white;border-radius:8px;overflow:hidden;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">';
                echo '<div style="background:#1B5E20;color:white;padding:8px 16px;font-size:12px;display:flex;align-items:center;justify-content:space-between;">';
                echo '<span>⚡ Interactive Content — ' . esc_html($lesson->title) . '</span>';
                echo '<a href="' . esc_url($iframe_url) . '" target="_blank" style="color:rgba(255,255,255,.8);font-size:11px;text-decoration:none;">🔗 Open full screen</a>';
                echo '</div>';
                echo '<iframe src="' . esc_url($iframe_url) . '" data-lesson-id="' . intval($lesson->lesson_id) . '" style="width:100%;height:700px;border:none;" sandbox="allow-scripts allow-forms allow-popups"></iframe>';
                echo '</div>';
            } else {
                // Regular text/HTML content
                echo '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
                echo '<div class="lesson-content" style="line-height: 1.8; font-size: 15px;">';
                echo wp_kses_post($lesson->content);
                echo '</div>';
                echo '</div>';
            }
        }
        
        // Navigation
        echo '<div style="display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap;">';
        
        if ($prev_lesson) {
            $prev_url = add_query_arg(array('portal_tab' => 'lessons', 'lesson_id' => $prev_lesson->lesson_id), get_permalink());
            echo '<a href="' . esc_url($prev_url) . '" class="mtti-btn mtti-btn-secondary">← Previous: ' . esc_html(wp_trim_words($prev_lesson->title, 5)) . '</a>';
        } else {
            echo '<div></div>';
        }
        
        if ($next_lesson) {
            $next_url = add_query_arg(array('portal_tab' => 'lessons', 'lesson_id' => $next_lesson->lesson_id), get_permalink());
            echo '<a href="' . esc_url($next_url) . '" class="mtti-btn mtti-btn-primary">Next: ' . esc_html(wp_trim_words($next_lesson->title, 5)) . ' →</a>';
        }
        
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Embed video player for YouTube/Vimeo/direct URLs
     */
    private function embed_video_player($url) {
        // YouTube
        if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches) ||
            preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $video_id = $matches[1];
            return '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                <iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" 
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;" 
                        frameborder="0" allowfullscreen></iframe>
            </div>';
        }
        
        // Vimeo
        if (preg_match('/vimeo\.com\/([0-9]+)/', $url, $matches)) {
            $video_id = $matches[1];
            return '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                <iframe src="https://player.vimeo.com/video/' . esc_attr($video_id) . '" 
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;" 
                        frameborder="0" allowfullscreen></iframe>
            </div>';
        }
        
        // Direct video file
        return '<video controls style="width: 100%; max-width: 100%;">
            <source src="' . esc_url($url) . '" type="video/mp4">
            Your browser does not support the video tag.
        </video>';
    }

    /* ══════════════════════════════════════════════════
     *  LEARNER ATTENDANCE — view history + self-mark via session code
     * ══════════════════════════════════════════════════ */
    private function render_learner_attendance($student) {
        global $wpdb;
        $p        = $wpdb->prefix . 'mtti_';
        $today    = date('Y-m-d');
        $nonce    = wp_create_nonce('mtti_learner_attendance');

        echo '<h2 class="mtti-page-title">✅ Attendance</h2>';

        // ── GET ENROLLED COURSES ─────────────────────
        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT e.enrollment_id, e.course_id, c.course_name, c.course_code
             FROM {$p}enrollments e
             INNER JOIN {$p}courses c ON c.course_id = e.course_id
             WHERE e.student_id = %d AND e.status IN ('Active','Enrolled','In Progress')",
            $student->student_id
        ));

        if (empty($courses)) {
            echo '<div class="mtti-empty-state"><p>No active enrolments found.</p></div>';
            return;
        }

        // ── SELF-MARK FORM ───────────────────────────
        echo '<div class="mtti-card" style="margin-bottom:20px;">';
        echo '<h3 style="margin:0 0 10px;font-size:15px;">📍 Mark Today\'s Attendance</h3>';
        echo '<p style="font-size:13px;color:var(--text-2);margin:0 0 14px;">Your lecturer will display a 6-digit session code at the start of class. Enter it below to mark yourself <strong>Present</strong>.</p>';

        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">';
        // Course selector
        echo '<div>';
        echo '<label style="display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">COURSE</label>';
        echo '<select id="att-course-id" style="padding:9px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);">';
        foreach ($courses as $c) {
            echo '<option value="' . intval($c->course_id) . '">' . esc_html($c->course_code . ' — ' . $c->course_name) . '</option>';
        }
        echo '</select></div>';
        // Code input
        echo '<div>';
        echo '<label style="display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">SESSION CODE</label>';
        echo '<input id="att-code-input" type="text" maxlength="6" placeholder="e.g. 482916"
                     style="padding:9px 12px;border:1px solid var(--border);border-radius:6px;width:130px;font-size:18px;font-weight:700;letter-spacing:6px;text-align:center;background:var(--bg-subtle);color:var(--text-primary);">';
        echo '</div>';
        echo '<button onclick="mttiSubmitAttCode(\'' . $nonce . '\')" class="mtti-btn mtti-btn-primary" style="padding:9px 20px;">✅ Mark Present</button>';
        echo '</div>';
        echo '<div id="att-code-result" style="margin-top:10px;font-size:13px;font-weight:600;"></div>';
        echo '</div>';

        // ── ATTENDANCE HISTORY PER COURSE ────────────
        foreach ($courses as $c) {
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT a.date, a.status
                 FROM {$p}attendance a
                 INNER JOIN {$p}enrollments e ON a.enrollment_id = e.enrollment_id
                 WHERE e.student_id = %d AND e.course_id = %d
                 ORDER BY a.date DESC
                 LIMIT 30",
                $student->student_id, $c->course_id
            ));

            $total   = count($records);
            $present = count(array_filter($records, fn($r) => in_array($r->status, ['Present','Late'])));
            $rate    = $total > 0 ? round($present / $total * 100) : 0;
            $rate_color = $rate >= 75 ? '#2E7D32' : ($rate >= 50 ? '#FF8F00' : '#C62828');

            echo '<div class="mtti-card" style="margin-bottom:16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">';
            echo '<h3 style="margin:0;font-size:14px;">' . esc_html($c->course_code . ' — ' . $c->course_name) . '</h3>';
            echo '<span style="font-size:22px;font-weight:800;color:' . $rate_color . ';">' . $rate . '% <span style="font-size:12px;font-weight:500;">attendance</span></span>';
            echo '</div>';

            if ($total < 75) {
                $warn_color = $rate < 75 ? '#FFF3E0' : '#E8F5E9';
                $warn_text  = $rate < 75
                    ? '⚠️ Your attendance is below 75%. Risk of not sitting exams.'
                    : '✅ Good attendance — keep it up!';
                echo '<div style="padding:8px 14px;border-radius:6px;background:' . $warn_color . ';font-size:12px;margin-bottom:12px;">' . $warn_text . '</div>';
            }

            if (!empty($records)) {
                $status_colors = ['Present'=>'#2E7D32','Absent'=>'#C62828','Late'=>'#FF8F00','Excused'=>'#1565C0'];
                echo '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
                foreach ($records as $r) {
                    $sc = $status_colors[$r->status] ?? '#888';
                    echo '<div style="padding:5px 10px;border-radius:6px;border:1px solid ' . $sc . ';font-size:11px;">';
                    echo '<span style="color:' . $sc . ';font-weight:700;">' . esc_html($r->status[0]) . '</span> ';
                    echo '<span style="color:var(--text-2);">' . date('d M', strtotime($r->date)) . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            } else {
                echo '<p style="font-size:13px;color:var(--text-3);margin:0;">No attendance records yet.</p>';
            }
            echo '</div>';
        }

        // ── JAVASCRIPT ───────────────────────────────
        echo '<script>
function mttiSubmitAttCode(nonce) {
    var code     = document.getElementById("att-code-input").value.trim();
    var courseId = document.getElementById("att-course-id").value;
    var result   = document.getElementById("att-code-result");

    if (code.length !== 6 || isNaN(code)) {
        result.style.color = "#C62828";
        result.innerText = "⚠️ Please enter a valid 6-digit code.";
        return;
    }
    result.style.color = "#888";
    result.innerText = "Checking code…";

    jQuery.post("' . admin_url('admin-ajax.php') . '", {
        action:    "mtti_learner_mark_attendance",
        nonce:     nonce,
        code:      code,
        course_id: courseId,
        att_date:  "' . $today . '"
    }, function(r) {
        if (r.success) {
            result.style.color = "#2E7D32";
            result.innerText = "✅ " + r.data;
            document.getElementById("att-code-input").value = "";
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            result.style.color = "#C62828";
            result.innerText = "❌ " + (r.data || "Invalid code. Please try again.");
        }
    }).fail(function() {
        result.style.color = "#C62828";
        result.innerText = "❌ Network error — please try again.";
    });
}
</script>';
    }

    private function render_materials($student) {
        global $wpdb;
        
        // Get materials for student's course
        $materials = array();
        if (!empty($student->course_id)) {
            $materials = $wpdb->get_results($wpdb->prepare(
                "SELECT m.*, cu.unit_name, cu.unit_code
                 FROM {$wpdb->prefix}mtti_materials m
                 LEFT JOIN {$wpdb->prefix}mtti_course_units cu ON m.unit_id = cu.unit_id
                 WHERE m.course_id = %d AND m.status = 'Active'
                 ORDER BY m.created_at DESC",
                $student->course_id
            ));
        }
        
        echo '<div class="mtti-materials">';
        echo '<h2 class="mtti-page-title">📥 Course Materials</h2>';
        echo '<p style="color: #666; margin-bottom: 20px;">Download course notes, PDFs, and other learning materials.</p>';
        
        if (!empty($materials)) {
            echo '<div class="mtti-materials-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">';
            
            foreach ($materials as $m) {
                $icon = $this->get_file_icon($m->file_type);
                $size = $m->file_size ? $this->format_file_size($m->file_size) : '';
                
                echo '<div class="mtti-material-card" style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
                echo '<div style="display: flex; align-items: flex-start; gap: 15px;">';
                echo '<span style="font-size: 40px;">' . $icon . '</span>';
                echo '<div style="flex: 1;">';
                echo '<h4 style="margin: 0 0 5px; color: #333;">' . esc_html($m->title) . '</h4>';
                if ($m->unit_name) {
                    echo '<span class="mtti-badge" style="font-size: 10px;">' . esc_html($m->unit_code) . '</span> ';
                }
                if ($m->description) {
                    echo '<p style="margin: 10px 0; font-size: 13px; color: #666;">' . esc_html($m->description) . '</p>';
                }
                echo '<div style="display: flex; gap: 15px; font-size: 12px; color: #999; margin-top: 10px;">';
                if ($size) echo '<span>📁 ' . $size . '</span>';
                echo '<span>📅 ' . date('M j, Y', strtotime($m->created_at)) . '</span>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                echo '<a href="' . esc_url($m->file_url) . '" class="mtti-btn mtti-btn-primary" style="width: 100%; margin-top: 15px; justify-content: center;" target="_blank" download>';
                echo '📥 Download</a>';
                echo '</div>';
            }
            
            echo '</div>';
        } else {
            echo '<div class="mtti-empty-state" style="background: white; border-radius: 8px; padding: 40px; text-align: center;">';
            echo '<span style="font-size: 64px; display: block; margin-bottom: 15px;">📂</span>';
            echo '<h3>No Materials Available</h3>';
            echo '<p style="color: #666;">Course materials will appear here when uploaded by your instructors.</p>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Get file icon based on type
     */
    private function get_file_icon($file_type) {
        $icons = array(
            'pdf' => '📕',
            'doc' => '📘',
            'docx' => '📘',
            'ppt' => '📙',
            'pptx' => '📙',
            'xls' => '📗',
            'xlsx' => '📗',
            'zip' => '🗜️',
            'rar' => '🗜️',
            'mp4' => '🎬',
            'mp3' => '🎵',
            'jpg' => '🖼️',
            'png' => '🖼️',
            'txt' => '📄',
        );
        return isset($icons[$file_type]) ? $icons[$file_type] : '📄';
    }
    
    /**
     * Format file size
     */
    private function format_file_size($bytes) {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' bytes';
    }
    
    /**
     * Render Transcript page
     */
    private function render_transcript($student) {
        global $wpdb;
        
        // Get unit results for student
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ur.*, cu.unit_name, cu.unit_code, c.course_name, c.course_code
             FROM {$wpdb->prefix}mtti_unit_results ur
             JOIN {$wpdb->prefix}mtti_course_units cu ON ur.unit_id = cu.unit_id
             JOIN {$wpdb->prefix}mtti_courses c ON cu.course_id = c.course_id
             WHERE ur.student_id = %d
             ORDER BY c.course_name, cu.unit_name",
            $student->student_id
        ));
        
        // Check if has any results
        if (empty($results)) {
            echo '<div class="mtti-transcript-page">';
            echo '<h2 class="mtti-page-title">📜 Academic Transcript</h2>';
            echo '<div class="mtti-empty-state" style="text-align: center; padding: 60px 20px;">';
            echo '<span style="font-size: 4rem;">📜</span>';
            echo '<h3>No Results Available</h3>';
            echo '<p style="color: #666;">Your marks will appear here once entered by your instructor.</p>';
            echo '</div></div>';
            return;
        }
        
        // Calculate statistics
        $total_marks = 0;
        $passed = 0;
        $failed = 0;
        foreach ($results as $r) {
            $total_marks += $r->score;
            if ($r->passed) $passed++; else $failed++;
        }
        $overall_avg = count($results) > 0 ? round($total_marks / count($results), 1) : 0;
        $overall_grade = $overall_avg >= 80 ? 'DISTINCTION' : ($overall_avg >= 60 ? 'CREDIT' : ($overall_avg >= 50 ? 'PASS' : 'REFER'));
        $overall_remarks = $overall_grade;
        
        // Get fees balance
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM {$wpdb->prefix}mtti_students WHERE student_id = %d",
            $student->student_id
        )) ?: 0;
        
        // Certificate eligibility
        $all_passed = ($failed == 0);
        $avg_above_50 = ($overall_avg >= 50);
        $fees_cleared = ($balance <= 0);
        $certificate_eligible = $all_passed && $avg_above_50 && $fees_cleared;
        
        $settings = get_option('mtti_mis_settings', array());
        $institute_name = $settings['institute_name'] ?? 'Masomotele Technical Training Institute';
        
        echo '<div class="mtti-transcript-page">';
        echo '<h2 class="mtti-page-title">📜 Academic Transcript</h2>';
        
        // Action buttons
        echo '<div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">';
        echo '<button onclick="window.print()" class="mtti-btn mtti-btn-primary">🖨️ Print Transcript</button>';
        
        // Certificate eligibility message
        if ($certificate_eligible) {
            echo '<span style="background: #00b894; color: white; padding: 10px 20px; border-radius: 5px;">✓ Eligible for Certificate</span>';
        } else {
            echo '<span style="background: #ff7675; color: white; padding: 10px 20px; border-radius: 5px;">⚠ Not Eligible for Certificate</span>';
        }
        echo '</div>';
        
        // Eligibility details
        if (!$certificate_eligible) {
            echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
            echo '<strong>Certificate Requirements:</strong><ul style="margin: 10px 0 0 20px;">';
            echo '<li>' . ($all_passed ? '✓' : '✗') . ' All units passed (50% minimum)</li>';
            echo '<li>' . ($avg_above_50 ? '✓' : '✗') . ' Overall average above 50%</li>';
            echo '<li>' . ($fees_cleared ? '✓' : '✗') . ' Fees fully paid (Balance: KES ' . number_format($balance, 2) . ')</li>';
            echo '</ul></div>';
        }
        
        // Transcript card
        echo '<div class="mtti-transcript" id="printable-transcript" style="background: white; border-radius: 8px; padding: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">';
        
        // Header
        echo '<div class="mtti-transcript-header" style="text-align: center; border-bottom: 2px solid #2E7D32; padding-bottom: 20px; margin-bottom: 20px;">';
        echo '<img src="' . esc_url(MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg') . '" style="width: 80px; height: 80px; border-radius: 50%; margin-bottom: 10px;">';
        echo '<h2 style="margin: 10px 0 5px; color: #2E7D32;">' . esc_html($institute_name) . '</h2>';
        echo '<p style="color: #FF9800; font-style: italic; margin: 0;">Start Learning, Start Earning</p>';
        echo '<h3 style="margin: 15px 0 0; color: #333;">ACADEMIC TRANSCRIPT</h3>';
        echo '</div>';
        
        // Student Info
        $enrolled_courses = $this->get_enrolled_courses($student);
        $course_names = array();
        if (!empty($enrolled_courses)) {
            foreach ($enrolled_courses as $ec) {
                $course_names[] = esc_html($ec->course_code . ' - ' . $ec->course_name);
            }
        }
        $courses_display = !empty($course_names) ? implode(', ', $course_names) : esc_html($student->course_name ?: 'N/A');
        
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; padding: 15px; background: #f8f9fa; border-radius: 6px;">';
        echo '<div><strong>Name:</strong> ' . esc_html($student->display_name) . '</div>';
        echo '<div><strong>Admission No:</strong> ' . esc_html($student->admission_number) . '</div>';
        echo '<div style="grid-column: 1 / -1;"><strong>Course(s):</strong> ' . $courses_display . '</div>';
        echo '<div><strong>Date:</strong> ' . date('F j, Y') . '</div>';
        echo '</div>';
        
        // Results table
        echo '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
        echo '<thead><tr style="background: #2E7D32; color: white;">';
        echo '<th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Course</th>';
        echo '<th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Unit Code</th>';
        echo '<th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Unit Name</th>';
        echo '<th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Marks</th>';
        echo '<th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Grade</th>';
        echo '<th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Status</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($results as $r) {
            $status = $r->passed ? 'PASS' : 'REFER';
            $status_color = $r->passed ? '#4CAF50' : '#D32F2F';
            $grade_color = $r->grade == 'DISTINCTION' ? '#2E7D32' : ($r->grade == 'CREDIT' ? '#1976D2' : ($r->grade == 'PASS' ? '#FF9800' : '#D32F2F'));
            
            echo '<tr>';
            echo '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($r->course_name ?: 'General') . '</td>';
            echo '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($r->unit_code) . '</td>';
            echo '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html($r->unit_name) . '</td>';
            echo '<td style="padding: 10px; border: 1px solid #ddd; text-align: center;">' . $r->score . '/100</td>';
            echo '<td style="padding: 10px; border: 1px solid #ddd; text-align: center;"><strong style="color: ' . $grade_color . ';">' . esc_html($r->grade) . '</strong></td>';
            echo '<td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: ' . $status_color . ';"><strong>' . $status . '</strong></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Summary
        echo '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; padding: 20px; background: #e8f5e9; border-radius: 6px; text-align: center; margin-bottom: 20px;">';
        echo '<div><strong style="display: block; color: #666; font-size: 0.9rem;">Total Units</strong><span style="font-size: 1.8rem; color: #333; font-weight: bold;">' . count($results) . '</span></div>';
        echo '<div><strong style="display: block; color: #666; font-size: 0.9rem;">Passed</strong><span style="font-size: 1.8rem; color: #4CAF50; font-weight: bold;">' . $passed . '</span></div>';
        echo '<div><strong style="display: block; color: #666; font-size: 0.9rem;">Average</strong><span style="font-size: 1.8rem; color: #2E7D32; font-weight: bold;">' . $overall_avg . '%</span></div>';
        echo '<div><strong style="display: block; color: #666; font-size: 0.9rem;">Overall Grade</strong><span style="font-size: 1.8rem; color: #2E7D32; font-weight: bold;">' . $overall_grade . '</span></div>';
        echo '</div>';
        
        // Final Result
        echo '<div style="text-align: center; padding: 15px; background: ' . ($overall_avg >= 50 ? '#d4edda' : '#f8d7da') . '; border-radius: 6px; margin-bottom: 20px;">';
        echo '<strong style="font-size: 1.2rem;">Final Result: ' . $overall_remarks . '</strong>';
        echo '</div>';
        
        // Footer
        echo '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; align-items: flex-end;">';
        echo '<div style="text-align: center;"><div style="border-top: 1px solid #333; width: 200px; margin-top: 50px; padding-top: 5px;">Registrar\'s Signature</div></div>';
        echo '<div style="text-align: center;"><div style="border-top: 1px solid #333; width: 200px; margin-top: 50px; padding-top: 5px;">Date & Stamp</div></div>';
        echo '</div>';
        
        echo '<p style="text-align: center; margin-top: 20px; font-size: 11px; color: #999;">Generated on ' . date('F j, Y \a\t g:i A') . ' | This is a computer-generated document.</p>';
        
        echo '</div>'; // End transcript
        echo '</div>'; // End page
        
        // Print styles
        echo '<style>
        @media print {
            body * { visibility: hidden; }
            #printable-transcript, #printable-transcript * { visibility: visible; }
            #printable-transcript { position: absolute; left: 0; top: 0; width: 100%; padding: 20px; }
            .mtti-portal-header, .mtti-portal-sidebar, .mtti-btn, .mtti-page-title { display: none !important; }
            .mtti-transcript-page > div:not(#printable-transcript) { display: none !important; }
        }
        </style>';
    }
    
    // ══════════════════════════════════════════════════════════
    // NEW v5.0 — CALENDAR
    // ══════════════════════════════════════════════════════════
    private function render_calendar($student) {
        global $wpdb;
        
        $events = array();
        $base = get_permalink();
        
        // Get all enrolled course IDs
        $enrolled_ids = $this->get_enrolled_course_ids($student);
        
        // Assignments with due dates from all enrolled courses
        if (!empty($enrolled_ids)) {
            $placeholders = implode(',', array_fill(0, count($enrolled_ids), '%d'));
            $assignments = $wpdb->get_results($wpdb->prepare(
                "SELECT a.title, a.due_date, c.course_code
                 FROM {$wpdb->prefix}mtti_assignments a
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON a.course_id = c.course_id
                 WHERE a.course_id IN ($placeholders) AND a.status = 'Active' AND a.due_date IS NOT NULL",
                ...$enrolled_ids
            ));
            foreach ($assignments as $a) {
                $label = $a->title . ' (Due)';
                if ($a->course_code) $label = '[' . $a->course_code . '] ' . $label;
                $events[] = array(
                    'date'  => date('Y-m-d', strtotime($a->due_date)),
                    'time'  => date('g:i A', strtotime($a->due_date)),
                    'title' => $label,
                    'type'  => 'assignment',
                );
            }
            
            // Live classes from all enrolled courses
            $classes = $wpdb->get_results($wpdb->prepare(
                "SELECT lc.title, lc.scheduled_at, c.course_code
                 FROM {$wpdb->prefix}mtti_live_classes lc
                 LEFT JOIN {$wpdb->prefix}mtti_courses c ON lc.course_id = c.course_id
                 WHERE lc.course_id IN ($placeholders) AND lc.status = 'Scheduled' AND lc.scheduled_at >= NOW()",
                ...$enrolled_ids
            ));
            foreach ($classes as $c) {
                $label = $c->title;
                if ($c->course_code) $label = '[' . $c->course_code . '] ' . $label;
                $events[] = array(
                    'date'  => date('Y-m-d', strtotime($c->scheduled_at)),
                    'time'  => date('g:i A', strtotime($c->scheduled_at)),
                    'title' => $label,
                    'type'  => 'class',
                );
            }
        }
        
        // Active notices (use their created date as the event date)
        $notices = $wpdb->get_results(
            "SELECT title, created_at FROM {$wpdb->prefix}mtti_notices
             WHERE status = 'Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())
             ORDER BY created_at DESC LIMIT 5"
        );
        foreach ($notices as $n) {
            $events[] = array(
                'date'  => date('Y-m-d', strtotime($n->created_at)),
                'time'  => '',
                'title' => $n->title,
                'type'  => 'notice',
            );
        }
        
        // Fee due reminder if balance > 0
        $balance = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(sb.balance),0) FROM {$wpdb->prefix}mtti_student_balances sb
             INNER JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
             WHERE sb.student_id = %d AND e.status IN ('Active','Enrolled','In Progress')",
            $student->student_id
        )));
        if ($balance > 0) {
            // Add end-of-month reminder
            $events[] = array(
                'date'  => date('Y-m-t'),
                'time'  => '',
                'title' => 'Fee Balance Due: KES ' . number_format($balance, 0),
                'type'  => 'fee',
            );
        }
        
        // Whatsapp share URL for this month's events
        $wa_text = 'My MTTI Schedule — ' . date('F Y') . ":\n";
        foreach ($events as $ev) {
            $wa_text .= date('D d', strtotime($ev['date'])) . ' — ' . $ev['title'] . "\n";
        }
        $wa_url = 'https://wa.me/?text=' . urlencode($wa_text);
        
        echo '<div class="mtti-calendar-page">';
        echo '<h2 class="mtti-page-title">📅 Calendar</h2>';
        
        // Current month navigation
        $month = isset($_GET['cal_month']) ? intval($_GET['cal_month']) : intval(date('n'));
        $year = isset($_GET['cal_year']) ? intval($_GET['cal_year']) : intval(date('Y'));
        if ($month < 1) { $month = 12; $year--; }
        if ($month > 12) { $month = 1; $year++; }
        
        $prev_month = $month - 1; $prev_year = $year;
        if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
        $next_month = $month + 1; $next_year = $year;
        if ($next_month > 12) { $next_month = 1; $next_year++; }
        
        $first_day = mktime(0, 0, 0, $month, 1, $year);
        $days_in_month = intval(date('t', $first_day));
        $start_day = intval(date('w', $first_day)); // 0=Sun
        $month_name = date('F Y', $first_day);
        $today = date('Y-m-d');
        
        // Build events array indexed by date
        $events_by_date = array();
        foreach ($events as $ev) {
            $d = $ev['date'];
            if (!isset($events_by_date[$d])) $events_by_date[$d] = array();
            $events_by_date[$d][] = $ev;
        }
        
        $base_url = get_permalink();
        $prev_url = add_query_arg(array('portal_tab' => 'calendar', 'cal_month' => $prev_month, 'cal_year' => $prev_year), $base_url);
        $next_url = add_query_arg(array('portal_tab' => 'calendar', 'cal_month' => $next_month, 'cal_year' => $next_year), $base_url);
        
        // Calendar card
        echo '<div class="mtti-card" style="margin-bottom:20px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">';
        echo '<a href="' . esc_url($prev_url) . '" class="mtti-btn mtti-btn-secondary" style="padding:6px 14px;font-size:13px;">‹ Prev</a>';
        echo '<h3 style="margin:0;font-size:18px;">' . esc_html($month_name) . '</h3>';
        echo '<a href="' . esc_url($next_url) . '" class="mtti-btn mtti-btn-secondary" style="padding:6px 14px;font-size:13px;">Next ›</a>';
        echo '</div>';
        
        // Day headers
        echo '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;">';
        foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day) {
            echo '<div style="font-size:10px;font-weight:700;color:var(--text-3);padding:6px 0;text-transform:uppercase;">' . $day . '</div>';
        }
        
        // Blank cells before month starts
        for ($i = 0; $i < $start_day; $i++) {
            echo '<div style="min-height:42px;"></div>';
        }
        
        // Days
        $type_colors = array('assignment' => '#E65100', 'class' => '#2E7D32', 'notice' => '#1565C0', 'fee' => '#C62828');
        
        for ($d = 1; $d <= $days_in_month; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $is_today = ($date_str === $today);
            $has_events = isset($events_by_date[$date_str]);
            
            $bg = $is_today ? 'var(--mtti-primary)' : ($has_events ? 'var(--mtti-primary-xl)' : 'var(--bg-subtle)');
            $color = $is_today ? 'white' : 'var(--text-1)';
            $border_style = $has_events && !$is_today ? 'border:1px solid var(--mtti-primary-light);' : 'border:1px solid transparent;';
            
            echo '<div style="min-height:42px;background:' . $bg . ';color:' . $color . ';' . $border_style . 'border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:13px;font-weight:' . ($is_today ? '700' : '500') . ';position:relative;">';
            echo $d;
            if ($has_events) {
                echo '<div style="display:flex;gap:2px;margin-top:2px;">';
                foreach ($events_by_date[$date_str] as $ev) {
                    $ec = $type_colors[$ev['type']] ?? 'var(--text-3)';
                    echo '<span style="width:5px;height:5px;border-radius:50%;background:' . $ec . ';"></span>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>'; // grid
        
        // Legend
        echo '<div style="display:flex;gap:16px;margin-top:14px;flex-wrap:wrap;">';
        echo '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);"><span style="width:8px;height:8px;border-radius:50%;background:#E65100;"></span> Assignment</div>';
        echo '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);"><span style="width:8px;height:8px;border-radius:50%;background:#2E7D32;"></span> Class</div>';
        echo '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);"><span style="width:8px;height:8px;border-radius:50%;background:#1565C0;"></span> Notice</div>';
        echo '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-3);"><span style="width:8px;height:8px;border-radius:50%;background:#C62828;"></span> Fee Due</div>';
        echo '</div>';
        
        echo '</div>'; // card
        
        // Upcoming events list
        $upcoming = array_filter($events, function($e) { return $e['date'] >= date('Y-m-d'); });
        usort($upcoming, function($a, $b) { return strcmp($a['date'], $b['date']); });
        
        if (!empty($upcoming)) {
            echo '<h3 style="font-size:14px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin:0 0 12px;">📌 Upcoming Events</h3>';
            foreach (array_slice($upcoming, 0, 10) as $ev) {
                $ec = $type_colors[$ev['type']] ?? 'var(--text-3)';
                echo '<div class="mtti-event-item" style="border-left-color:' . $ec . ';">';
                echo '<div class="mtti-event-dot" style="background:' . $ec . ';"></div>';
                echo '<div class="mtti-event-text">';
                echo '<strong>' . esc_html($ev['title']) . '</strong>';
                echo '<span>' . date('D, M j', strtotime($ev['date']));
                if ($ev['time']) echo ' at ' . esc_html($ev['time']);
                echo '</span></div></div>';
            }
        } else {
            echo '<div class="mtti-card" style="text-align:center;padding:30px;color:var(--text-3);">No upcoming events this month.</div>';
        }
        
        echo '</div>';
    }
    
    // ══════════════════════════════════════════════════════════
    // NEW v5.0 — AI STUDY ASSISTANT
    // ══════════════════════════════════════════════════════════

    private function render_leaderboard($student) {
        global $wpdb;
        
        if (empty($student->course_id)) {
            echo '<div class="mtti-empty-state"><span>🥇</span><h3>No Course Assigned</h3><p>You need to be enrolled in a course to see the leaderboard.</p></div>';
            return;
        }
        
        // Get leaderboard for this course
        $leaders = $wpdb->get_results($wpdb->prepare(
            "SELECT s.student_id, s.admission_number, u.display_name,
                    COUNT(ur.result_id) as unit_count,
                    AVG(ur.percentage) as avg_pct,
                    SUM(ur.passed) as passed_count,
                    COALESCE(ls.show_on_leaderboard, 1) as show_on_lb
             FROM {$wpdb->prefix}mtti_unit_results ur
             INNER JOIN {$wpdb->prefix}mtti_course_units cu ON ur.unit_id = cu.unit_id
             INNER JOIN {$wpdb->prefix}mtti_students s ON ur.student_id = s.student_id
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}mtti_leaderboard_settings ls ON s.student_id = ls.student_id
             WHERE cu.course_id = %d AND s.status = 'Active'
             GROUP BY s.student_id
             HAVING unit_count > 0
             ORDER BY avg_pct DESC
             LIMIT 20",
            $student->course_id
        ));
        
        // Check student's own leaderboard preference
        $my_setting = $wpdb->get_row($wpdb->prepare(
            "SELECT show_on_leaderboard FROM {$wpdb->prefix}mtti_leaderboard_settings WHERE student_id = %d",
            $student->student_id
        ));
        $show_me = $my_setting ? intval($my_setting->show_on_leaderboard) : 1;
        
        echo '<div class="mtti-leaderboard-page">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">';
        echo '<h2 class="mtti-page-title" style="margin:0;">🥇 Leaderboard</h2>';
        
        // Privacy toggle
        $toggle_val = $show_me ? 0 : 1;
        $toggle_label = $show_me ? '🙈 Hide me from leaderboard' : '👁 Show me on leaderboard';
        echo '<form method="post" action="">';
        wp_nonce_field('mtti_lb_toggle', 'mtti_lb_nonce');
        echo '<input type="hidden" name="mtti_lb_show" value="' . $toggle_val . '">';
        echo '<button type="submit" class="mtti-btn mtti-btn-secondary" style="font-size:12px;">' . $toggle_label . '</button>';
        echo '</form>';
        echo '</div>';
        
        // Handle toggle submission
        if (isset($_POST['mtti_lb_show']) && wp_verify_nonce($_POST['mtti_lb_nonce'] ?? '', 'mtti_lb_toggle')) {
            $new_val = intval($_POST['mtti_lb_show']);
            $wpdb->replace("{$wpdb->prefix}mtti_leaderboard_settings", array(
                'student_id'        => $student->student_id,
                'show_on_leaderboard' => $new_val,
            ), array('%d', '%d'));
            echo '<div style="background:var(--primary-xl);border:1px solid #A5D6A7;color:var(--primary);padding:10px 16px;border-radius:var(--r-sm);margin-bottom:16px;font-size:13px;">✓ Preference saved.</div>';
            $show_me = $new_val;
        }
        
        if (empty($leaders)) {
            echo '<div class="mtti-empty-state"><span>🏆</span><h3>No Results Yet</h3><p>The leaderboard will appear once students have submitted assessments.</p></div>';
            echo '</div>';
            return;
        }
        
        // Find your own rank
        $my_rank = 0;
        $my_avg  = 0;
        foreach ($leaders as $i => $l) {
            if ($l->student_id == $student->student_id) {
                $my_rank = $i + 1;
                $my_avg  = round($l->avg_pct, 1);
                break;
            }
        }
        
        echo '<p style="font-size:13px;color:var(--text-3);margin-bottom:16px;">';
        echo esc_html($student->course_name) . ' — ' . count($leaders) . ' students ranked';
        if ($my_rank && $show_me) echo ' &middot; <strong>You are #' . $my_rank . '</strong>';
        echo '</p>';
        
        $rank_icons = array('🥇', '🥈', '🥉');
        
        echo '<div class="mtti-leaderboard">';
        foreach ($leaders as $i => $l) {
            $rank = $i + 1;
            $is_me = ($l->student_id == $student->student_id);
            $rank_icon = isset($rank_icons[$i]) ? $rank_icons[$i] : '#' . $rank;
            $grade = round($l->avg_pct) >= 80 ? 'DISTINCTION' : (round($l->avg_pct) >= 60 ? 'CREDIT' : (round($l->avg_pct) >= 50 ? 'PASS' : 'REFER'));
            $grade_cls = $grade === 'DISTINCTION' ? 'grade-a' : ($grade === 'CREDIT' ? 'grade-c' : ($grade === 'PASS' ? 'grade-p' : 'grade-r'));
            
            // If student opted out, show anonymously (unless it's the current student)
            $display_name = (!$l->show_on_lb && !$is_me) ? 'Anonymous' : esc_html($l->display_name);
            $row_class = 'mtti-lb-row' . ($is_me ? ' you' : '');
            
            echo '<div class="' . $row_class . '">';
            echo '<span class="mtti-lb-rank">' . $rank_icon . '</span>';
            echo '<span class="mtti-lb-avatar">' . ($is_me ? '⭐' : '👤') . '</span>';
            echo '<span class="mtti-lb-name">' . $display_name;
            if ($is_me) echo ' <span class="mtti-lb-you-tag">YOU</span>';
            echo '</span>';
            echo '<span class="mtti-result-grade ' . $grade_cls . '">' . $grade . '</span>';
            echo '<span class="mtti-lb-score">' . round($l->avg_pct, 1) . '%</span>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '<p style="font-size:11px;color:var(--text-3);margin-top:16px;">Rankings based on average score across all completed units. Resets monthly.</p>';
        echo '</div>';
    }
    
    // ══════════════════════════════════════════════════════════
    // NEW v5.0 — AJAX HANDLERS
    // ══════════════════════════════════════════════════════════
    
    public function ajax_get_notifications() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        
        $student = $this->get_current_student();
        if (!$student) wp_send_json_error();
        
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_notifications';
        
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            wp_send_json_success(array('notifications' => array(), 'unread_count' => 0));
            return;
        }
        
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE student_id = %d ORDER BY created_at DESC LIMIT 30",
            $student->student_id
        ));
        $unread = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE student_id = %d AND is_read = 0",
            $student->student_id
        ));
        
        wp_send_json_success(array(
            'notifications' => $notifications,
            'unread_count'  => intval($unread),
        ));
    }
    
    public function ajax_mark_notifications_read() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        
        $student = $this->get_current_student();
        if (!$student) wp_send_json_error();
        
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_notifications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            $wpdb->update($table, array('is_read' => 1), array('student_id' => $student->student_id), array('%d'), array('%d'));
        }
        wp_send_json_success();
    }
    

    public function ajax_save_goal() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        
        $goal = sanitize_text_field($_POST['goal'] ?? '');
        if (!$goal) wp_send_json_error();
        
        $uid = get_current_user_id();
        update_user_meta($uid, 'mtti_weekly_goal',   $goal);
        update_user_meta($uid, 'mtti_goal_done',     0);
        update_user_meta($uid, 'mtti_goal_set_at',   current_time('mysql'));
        
        wp_send_json_success();
    }
    
    public function ajax_complete_goal() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        
        update_user_meta(get_current_user_id(), 'mtti_goal_done', 1);
        
        // Create a notification for completing the goal
        $student = $this->get_current_student();
        if ($student) {
            global $wpdb;
            $table = $wpdb->prefix . 'mtti_notifications';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
                $goal = get_user_meta(get_current_user_id(), 'mtti_weekly_goal', true);
                $wpdb->insert($table, array(
                    'student_id' => $student->student_id,
                    'type'       => 'success',
                    'title'      => '🎉 Goal Completed!',
                    'message'    => 'You completed your weekly goal: ' . $goal,
                    'is_read'    => 0,
                    'created_at' => current_time('mysql'),
                ));
            }
        }
        
        wp_send_json_success();
    }
    // ══════════════════════════════════════════════════════════
    // ENHANCEMENT 09 — STUDY GROUP CHAT
    // ══════════════════════════════════════════════════════════
    private function render_study_chat($student) {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_discussions';
        
        // Ensure table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            echo '<div class="mtti-empty-state"><span>💬</span><h3>Chat Setting Up</h3>';
            echo '<p>Run the SQL file <code>NEW-TABLES-v6.sql</code> to enable Study Chat.</p></div>';
            return;
        }
        
        // Handle new post
        if (isset($_POST['mtti_chat_post']) && wp_verify_nonce($_POST['mtti_chat_nonce'] ?? '', 'mtti_chat_action')) {
            $message = sanitize_textarea_field($_POST['chat_message'] ?? '');
            $parent_id = intval($_POST['parent_id'] ?? 0);
            if ($message && strlen($message) >= 3) {
                $wpdb->insert($table, array(
                    'course_id'  => $student->course_id,
                    'student_id' => $student->student_id,
                    'message'    => $message,
                    'parent_id'  => $parent_id ?: null,
                    'created_at' => current_time('mysql'),
                    'status'     => 'published',
                ));
            }
        }
        
        // Handle upvote
        if (isset($_GET['upvote']) && is_user_logged_in()) {
            $did = intval($_GET['upvote']);
            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mtti_discussion_votes WHERE discussion_id=%d AND student_id=%d",
                $did, $student->student_id
            ));
            if (!$already) {
                $wpdb->insert("{$wpdb->prefix}mtti_discussion_votes", array(
                    'discussion_id' => $did,
                    'student_id'    => $student->student_id,
                    'created_at'    => current_time('mysql'),
                ));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET upvotes = upvotes + 1 WHERE discussion_id = %d",
                    $did
                ));
            }
            wp_safe_redirect(add_query_arg('portal_tab', 'chat', get_permalink()));
            exit;
        }
        
        // Load threads (top-level only, ordered by upvotes then date)
        $threads = array();
        if ($student->course_id) {
            $threads = $wpdb->get_results($wpdb->prepare(
                "SELECT d.*, u.display_name,
                        (SELECT COUNT(*) FROM {$table} r WHERE r.parent_id = d.discussion_id AND r.status='published') as reply_count
                 FROM {$table} d
                 LEFT JOIN {$wpdb->users} u ON (SELECT user_id FROM {$wpdb->prefix}mtti_students WHERE student_id = d.student_id LIMIT 1) = u.ID
                 WHERE d.course_id = %d AND d.parent_id IS NULL AND d.status = 'published'
                 ORDER BY d.is_pinned DESC, d.upvotes DESC, d.created_at DESC
                 LIMIT 30",
                $student->course_id
            ));
        }
        
        $base = get_permalink();
        echo '<div class="mtti-chat-page">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">';
        echo '<h2 class="mtti-page-title" style="margin:0;">💬 Study Chat — ' . esc_html($student->course_code) . '</h2>';
        echo '<span style="font-size:12px;color:var(--text-muted);">' . esc_html($student->course_name) . '</span>';
        echo '</div>';
        
        // New post form
        echo '<div class="mtti-chat-post-form mtti-card" style="margin-bottom:20px;">';
        echo '<h3 style="margin:0 0 12px;font-size:13px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Ask a Question or Share a Tip</h3>';
        echo '<form method="post" action="">';
        wp_nonce_field('mtti_chat_action', 'mtti_chat_nonce');
        echo '<input type="hidden" name="mtti_chat_post" value="1">';
        echo '<input type="hidden" name="parent_id" value="0">';
        echo '<textarea name="chat_message" class="mtti-chat-textarea" placeholder="Type your question or tip here... (min 3 characters)" rows="3" required minlength="3" maxlength="1000"></textarea>';
        echo '<div style="margin-top:9px;text-align:right;">';
        echo '<button type="submit" class="mtti-btn mtti-btn-primary">📤 Post</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        
        // Threads list
        if (!empty($threads)) {
            echo '<div class="mtti-threads-list">';
            foreach ($threads as $thread) {
                $is_mine = ($thread->student_id == $student->student_id);
                echo '<div class="mtti-thread-card mtti-card" data-id="' . intval($thread->discussion_id) . '">';
                
                // Thread header
                echo '<div class="mtti-thread-meta">';
                echo '<span class="mtti-thread-author">' . ($is_mine ? '⭐ You' : '👤 ' . esc_html($thread->display_name ?: 'Student')) . '</span>';
                echo '<span class="mtti-thread-time">' . human_time_diff(strtotime($thread->created_at)) . ' ago</span>';
                if ($thread->is_pinned) echo '<span class="mtti-pin-badge">📌 Pinned</span>';
                if ($thread->is_verified) echo '<span class="mtti-verified-badge">✅ Instructor Verified</span>';
                echo '</div>';
                
                // Message
                echo '<div class="mtti-thread-msg">' . esc_html($thread->message) . '</div>';
                
                // Actions
                echo '<div class="mtti-thread-actions">';
                echo '<a href="' . esc_url(add_query_arg(array('portal_tab'=>'chat','upvote'=>$thread->discussion_id), $base)) . '" class="mtti-thread-upvote">👍 ' . intval($thread->upvotes) . '</a>';
                echo '<button class="mtti-reply-toggle" data-id="' . intval($thread->discussion_id) . '">💬 Reply (' . intval($thread->reply_count) . ')</button>';
                echo '</div>';
                
                // Replies (hidden by default, loaded via JS)
                echo '<div class="mtti-replies" id="replies-' . intval($thread->discussion_id) . '" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">';
                
                // Load replies from DB
                $replies = $wpdb->get_results($wpdb->prepare(
                    "SELECT d.*, u.display_name FROM {$table} d
                     LEFT JOIN {$wpdb->users} u ON (SELECT user_id FROM {$wpdb->prefix}mtti_students WHERE student_id = d.student_id LIMIT 1) = u.ID
                     WHERE d.parent_id = %d AND d.status = 'published'
                     ORDER BY d.created_at ASC LIMIT 20",
                    $thread->discussion_id
                ));
                
                foreach ($replies as $reply) {
                    $r_mine = ($reply->student_id == $student->student_id);
                    echo '<div class="mtti-reply-item">';
                    echo '<div class="mtti-thread-meta" style="font-size:11px;">';
                    echo '<span class="mtti-thread-author">' . ($r_mine ? '⭐ You' : '👤 ' . esc_html($reply->display_name ?: 'Student')) . '</span>';
                    echo '<span class="mtti-thread-time">' . human_time_diff(strtotime($reply->created_at)) . ' ago</span>';
                    if ($reply->is_verified) echo '<span class="mtti-verified-badge">✅ Verified</span>';
                    echo '</div>';
                    echo '<div class="mtti-thread-msg" style="font-size:13px;">' . esc_html($reply->message) . '</div>';
                    echo '</div>';
                }
                
                // Reply form
                echo '<form method="post" action="" style="margin-top:10px;">';
                wp_nonce_field('mtti_chat_action', 'mtti_chat_nonce');
                echo '<input type="hidden" name="mtti_chat_post" value="1">';
                echo '<input type="hidden" name="parent_id" value="' . intval($thread->discussion_id) . '">';
                echo '<div style="display:flex;gap:7px;">';
                echo '<input type="text" name="chat_message" class="mtti-chat-input" placeholder="Write a reply..." maxlength="500" required minlength="3">';
                echo '<button type="submit" class="mtti-btn mtti-btn-primary" style="padding:7px 14px;font-size:12px;">Reply</button>';
                echo '</div>';
                echo '</form>';
                
                echo '</div>'; // .mtti-replies
                echo '</div>'; // .mtti-thread-card
            }
            echo '</div>';
        } else {
            echo '<div class="mtti-empty-state"><span>💬</span><h3>No Discussions Yet</h3>';
            echo '<p>Be the first to post a question or tip for your course!</p></div>';
        }
        
        echo '</div>'; // .mtti-chat-page
    }
    
    public function ajax_post_discussion() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        $student = $this->get_current_student();
        if (!$student) wp_send_json_error();
        
        $message   = sanitize_textarea_field($_POST['message'] ?? '');
        $parent_id = intval($_POST['parent_id'] ?? 0);
        if (strlen($message) < 3) wp_send_json_error(array('message' => 'Message too short.'));
        
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'mtti_discussions', array(
            'course_id'  => $student->course_id,
            'student_id' => $student->student_id,
            'message'    => $message,
            'parent_id'  => $parent_id ?: null,
            'created_at' => current_time('mysql'),
            'status'     => 'published',
        ));
        wp_send_json_success(array('id' => $wpdb->insert_id));
    }
    
    public function ajax_get_discussions() {
        check_ajax_referer('mtti_portal_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();
        $student = $this->get_current_student();
        if (!$student) wp_send_json_error();
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_discussions';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            wp_send_json_success(array('threads' => array()));
            return;
        }
        $threads = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, u.display_name FROM {$table} d
             LEFT JOIN {$wpdb->users} u ON (SELECT user_id FROM {$wpdb->prefix}mtti_students WHERE student_id = d.student_id LIMIT 1) = u.ID
             WHERE d.course_id = %d AND d.parent_id IS NULL AND d.status='published'
             ORDER BY d.is_pinned DESC, d.upvotes DESC, d.created_at DESC LIMIT 30",
            $student->course_id
        ));
        wp_send_json_success(array('threads' => $threads));
    }


    /* ══════════════════════════════════════════════════
     *  CURRICULUM TRACKER — Student view of units + lessons
     * ══════════════════════════════════════════════════ */
    private function render_scheme_of_work($student) {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        echo '<h2 class="mtti-page-title">📋 Scheme of Work</h2>';
        echo '<p style="color:var(--text-2);margin-bottom:20px;">Detailed course content breakdown — what you\'ll learn in each unit and lesson.</p>';

        $enrolled_ids = $this->get_enrolled_course_ids($student);
        if (empty($enrolled_ids)) {
            echo '<div class="mtti-empty-state"><span>📋</span><h3>No course assigned</h3><p>You are not enrolled in a course yet.</p></div>';
            return;
        }

        $filter_course = $this->render_course_filter($student, 'scheme');
        
        // Determine which course IDs to show
        if ($filter_course > 0 && in_array($filter_course, $enrolled_ids)) {
            $query_ids = array($filter_course);
        } else {
            $query_ids = $enrolled_ids;
        }

        // Get courses info
        $cid_placeholders = implode(',', array_fill(0, count($query_ids), '%d'));
        $courses_info = $wpdb->get_results($wpdb->prepare(
            "SELECT course_id, course_name, course_code, duration_weeks, description
             FROM {$wpdb->prefix}mtti_courses
             WHERE course_id IN ($cid_placeholders)",
            ...$query_ids
        ), OBJECT_K);

        foreach ($query_ids as $cid) {
            if (!isset($courses_info[$cid])) continue;
            $course = $courses_info[$cid];

            // Course info header
            echo '<div class="mtti-card" style="margin-bottom:20px;background:linear-gradient(135deg,#1B5E20,#2E7D32);color:white;">';
            echo '<h3 style="color:white;margin:0 0 6px;">' . esc_html($course->course_name) . '</h3>';
            echo '<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;opacity:.85;">';
            echo '<span>📝 ' . esc_html($course->course_code) . '</span>';
            if ($course->duration_weeks) echo '<span>⏱ ' . intval($course->duration_weeks) . ' weeks</span>';
            if ($course->description) echo '<div style="margin-top:8px;font-size:12px;opacity:.8;max-width:600px;">' . esc_html(wp_trim_words($course->description, 30)) . '</div>';
            echo '</div></div>';

            // Get all units with their lessons for this course
            $units = $wpdb->get_results($wpdb->prepare(
                "SELECT cu.*,
                        COUNT(DISTINCT l.lesson_id) as lesson_count,
                        COUNT(DISTINCT CASE WHEN l.content_type='html_interactive' AND l.title NOT LIKE '🤖 Quiz:%' THEN l.lesson_id END) as interactive_count,
                        COUNT(DISTINCT lv.lesson_id) as viewed_count
                 FROM {$p}course_units cu
                 LEFT JOIN {$p}lessons l ON l.unit_id = cu.unit_id AND l.status = 'Published' AND l.title NOT LIKE '🤖 Quiz:%'
                 LEFT JOIN {$p}lesson_views lv ON lv.lesson_id = l.lesson_id AND lv.student_id = %d
                 WHERE cu.course_id = %d AND cu.status = 'Active'
                 ORDER BY cu.order_number ASC, cu.unit_code ASC",
                $student->student_id, $cid
            ));

        if (!$units) {
            echo '<div class="mtti-card" style="text-align:center;padding:40px;">';
            echo '<span style="font-size:48px;display:block;margin-bottom:12px;">📚</span>';
            echo '<h3>Scheme of work not set up yet</h3>';
            echo '<p style="color:var(--text-3);">Units and lesson details will appear here once they are added.</p>';
            echo '</div>';
            return;
        }

        $total_units = count($units);
        $units_full = count(array_filter($units, function($u){ return $u->lesson_count > 0 && $u->viewed_count >= $u->lesson_count; }));
        $total_lessons = array_sum(array_column($units, 'lesson_count'));

        // Progress summary
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:22px;">';
        foreach ([
            ['📚', $total_units, 'Units'],
            ['📖', $total_lessons, 'Lessons'],
            ['✅', $units_full, 'Units Completed'],
        ] as $s) {
            echo '<div class="mtti-stat-card" style="flex:1;min-width:100px;">';
            echo '<div class="mtti-stat-content"><span class="mtti-stat-value">' . $s[0] . ' ' . $s[1] . '</span>';
            echo '<span class="mtti-stat-label">' . $s[2] . '</span></div></div>';
        }
        echo '</div>';

        // Each unit with lesson details
        foreach ($units as $i => $u) {
            $has_lessons = $u->lesson_count > 0;
            $all_viewed = $has_lessons && ($u->viewed_count >= $u->lesson_count);
            $some_viewed = $u->viewed_count > 0 && !$all_viewed;
            
            $border_color = $all_viewed ? 'var(--mtti-success)' : ($some_viewed ? 'var(--mtti-secondary)' : 'var(--border)');
            $status = $all_viewed ? '✅ Completed' : ($some_viewed ? '▶ In Progress' : '○ Not Started');

            echo '<div class="mtti-card" style="margin-bottom:14px;border-left:4px solid ' . $border_color . ';padding:0;overflow:hidden;">';
            
            // Unit header
            echo '<div style="padding:16px 20px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;cursor:pointer;" onclick="this.parentElement.querySelector(\'.scheme-lessons\').classList.toggle(\'scheme-open\')">';
            echo '<div>';
            echo '<span style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.07em;">UNIT ' . ($i+1) . ' · ' . esc_html($u->unit_code) . '</span>';
            echo '<div style="font-size:15px;font-weight:700;color:var(--text-1);margin-top:3px;">' . esc_html($u->unit_name) . '</div>';
            if ($u->description) {
                echo '<div style="font-size:12px;color:var(--text-3);margin-top:4px;max-width:500px;">' . esc_html(wp_trim_words($u->description, 20)) . '</div>';
            }
            echo '<div style="display:flex;gap:14px;margin-top:6px;font-size:11px;color:var(--text-3);">';
            if ($u->duration_hours) echo '<span>⏱ ' . $u->duration_hours . ' hours</span>';
            if ($has_lessons) echo '<span>📖 ' . $u->lesson_count . ' lessons</span>';
            if ($u->interactive_count > 0) echo '<span>⚡ ' . $u->interactive_count . ' practicals</span>';
            echo '</div>';
            echo '</div>';
            echo '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">';
            echo '<span style="font-size:11px;font-weight:600;white-space:nowrap;">' . $status . '</span>';
            echo '<span style="font-size:18px;color:var(--text-3);">▼</span>';
            echo '</div>';
            echo '</div>';
            
            // Lessons inside this unit (collapsible)
            $unit_lessons = $wpdb->get_results($wpdb->prepare(
                "SELECT l.lesson_id, l.title, l.description, l.content_type, l.duration_minutes, l.order_number,
                        CASE WHEN lv.lesson_id IS NOT NULL THEN 1 ELSE 0 END as is_viewed
                 FROM {$p}lessons l
                 LEFT JOIN {$p}lesson_views lv ON lv.lesson_id = l.lesson_id AND lv.student_id = %d
                 WHERE l.unit_id = %d AND l.status = 'Published'
                 ORDER BY l.order_number ASC",
                $student->student_id, $u->unit_id
            ));
            
            // Also get materials for this unit
            $unit_materials = $wpdb->get_results($wpdb->prepare(
                "SELECT title, description, file_url, file_type, file_size, created_at
                 FROM {$p}materials WHERE unit_id = %d AND status = 'Active' ORDER BY created_at ASC",
                $u->unit_id
            ));
            
            echo '<div class="scheme-lessons" style="border-top:1px solid var(--border);padding:0 20px 16px;display:none;">';
            
            if (!empty($unit_lessons)) {
                foreach ($unit_lessons as $li => $lesson) {
                    $type_icons = array('video' => '🎬', 'pdf' => '📕', 'document' => '📘', 'text' => '📝', 'html_interactive' => '⚡');
                    $icon = $type_icons[$lesson->content_type] ?? '📄';
                    $viewed_icon = $lesson->is_viewed ? '✅' : '○';
                    $lesson_url = add_query_arg(array('portal_tab' => 'lessons', 'lesson_id' => $lesson->lesson_id), get_permalink());
                    
                    echo '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;' . ($li > 0 ? 'border-top:1px solid var(--border);' : 'margin-top:12px;') . '">';
                    echo '<span style="font-size:14px;">' . $viewed_icon . '</span>';
                    echo '<div style="flex:1;min-width:0;">';
                    echo '<a href="' . esc_url($lesson_url) . '" style="font-size:13px;font-weight:600;color:var(--text-1);text-decoration:none;">' . esc_html($lesson->title) . '</a>';
                    if ($lesson->description) echo '<div style="font-size:11px;color:var(--text-3);margin-top:2px;">' . esc_html(wp_trim_words($lesson->description, 15)) . '</div>';
                    echo '<div style="font-size:10px;color:var(--text-3);margin-top:2px;">' . $icon . ' ' . ucfirst($lesson->content_type ?? 'lesson');
                    if ($lesson->duration_minutes) echo ' · ⏱ ' . intval($lesson->duration_minutes) . ' min';
                    echo '</div>';
                    echo '</div>';
                    echo '<a href="' . esc_url($lesson_url) . '" style="font-size:11px;color:var(--mtti-secondary);text-decoration:none;flex-shrink:0;">Open →</a>';
                    echo '</div>';
                }
            }
            
            if (!empty($unit_materials)) {
                echo '<div style="margin-top:10px;padding-top:10px;border-top:2px dashed var(--border);">';
                echo '<span style="font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;">📥 Attached Files</span>';
                foreach ($unit_materials as $m) {
                    $ficon = $this->get_file_icon($m->file_type);
                    echo '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;">';
                    echo '<span>' . $ficon . '</span>';
                    echo '<span style="flex:1;font-size:12px;color:var(--text-2);">' . esc_html($m->title) . '</span>';
                    echo '<a href="' . esc_url($m->file_url) . '" style="font-size:11px;color:var(--mtti-primary);" target="_blank" download>Download</a>';
                    echo '</div>';
                }
                echo '</div>';
            }
            
            if (empty($unit_lessons) && empty($unit_materials)) {
                echo '<p style="padding:14px 0;color:var(--text-3);font-size:13px;font-style:italic;">No lessons added to this unit yet.</p>';
            }
            
            echo '</div>';
            echo '</div>';
        }
        
        // Collapsible JS
        echo '<style>.scheme-lessons.scheme-open{display:block!important;}</style>';
        } // end foreach course loop
    }

    /* ══════════════════════════════════════════════════
     *  LEARNER SELF-MARK ATTENDANCE VIA SESSION CODE
     * ══════════════════════════════════════════════════ */
    public function ajax_learner_mark_attendance() {
        check_ajax_referer('mtti_learner_attendance', 'nonce');
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $course_id = intval($_POST['course_id'] ?? 0);
        $att_date  = sanitize_text_field($_POST['att_date'] ?? '');
        $submitted = preg_replace('/\D/', '', $_POST['code'] ?? '');

        if (!$course_id || !$att_date || strlen($submitted) !== 6) {
            wp_send_json_error('Invalid request.');
        }

        // ── 1. Validate the session code ─────────────
        $code_key    = 'mtti_att_code_' . $course_id . '_' . $att_date;
        $active_code = get_transient($code_key);

        if (!$active_code) {
            wp_send_json_error('No active session code. Ask your lecturer to start a session.');
        }
        if ($submitted !== $active_code) {
            wp_send_json_error('Wrong code. Please check and try again.');
        }

        // ── 2. Confirm student is enrolled ───────────
        $user_id = get_current_user_id();
        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT s.student_id, e.enrollment_id
             FROM {$p}students s
             INNER JOIN {$p}enrollments e ON e.student_id = s.student_id
             WHERE s.user_id = %d AND e.course_id = %d
               AND e.status IN ('Active','Enrolled','In Progress')
             LIMIT 1",
            $user_id, $course_id
        ));

        if (!$student) {
            wp_send_json_error('You are not enrolled in this course.');
        }

        // ── 3. Prevent duplicate self-marking for same day ───────────────
        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}attendance
             WHERE enrollment_id = %d AND date = %s",
            $student->enrollment_id, $att_date
        ));

        if ($already > 0) {
            wp_send_json_error('You have already marked attendance for today.');
        }

        // ── 4. Insert attendance record ──────────────
        $inserted = $wpdb->insert(
            $p . 'attendance',
            array(
                'enrollment_id' => $student->enrollment_id,
                'date'          => $att_date,
                'status'        => 'Present',
                'notes'         => 'Self-marked via session code',
                'created_at'    => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            wp_send_json_error('Could not save attendance. Please try again.');
        }

        wp_send_json_success('Attendance marked — you are Present for today!');
    }

} // end class MTTI_MIS_Learner_Portal


add_action('init', function() {
    MTTI_MIS_Learner_Portal::get_instance();
}, 10);
