<?php
/**
 * MTTI Lecturer Portal
 * Session Timer, Attendance Marking, Scheme of Work Checklist
 * @version 6.0.0
 */
if (!defined('ABSPATH')) exit;

class MTTI_MIS_Lecturer_Portal {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_shortcode('mtti_lecturer_portal', array($this, 'render_portal'));
        // Always load assets when shortcode might be on page
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('wp_ajax_mtti_lecturer_clock_in',      array($this, 'ajax_clock_in'));
        add_action('wp_ajax_mtti_lecturer_clock_out',     array($this, 'ajax_clock_out'));
        add_action('wp_ajax_mtti_mark_attendance',        array($this, 'ajax_mark_attendance'));
        add_action('wp_ajax_mtti_generate_att_code',      array($this, 'ajax_generate_att_code'));
        add_action('wp_ajax_mtti_revoke_att_code',        array($this, 'ajax_revoke_att_code'));
        add_action('wp_ajax_mtti_get_session_students',   array($this, 'ajax_get_session_students'));
        add_action('wp_ajax_mtti_update_week_status',     array($this, 'ajax_update_week_status'));
        // AI generate removed — upload-only mode
        add_action('wp_ajax_mtti_lecturer_upload_html',     array($this, 'ajax_upload_html'));
        add_action('wp_ajax_mtti_lecturer_upload_material', array($this, 'ajax_upload_material'));
        add_action('wp_ajax_mtti_lecturer_save_quiz',       array($this, 'ajax_save_quiz'));
    }

    public function enqueue_assets() {
        wp_enqueue_style('mtti-learner-portal',    MTTI_MIS_PLUGIN_URL . 'assets/css/learner-portal.css', array(), MTTI_MIS_VERSION);
        wp_enqueue_style('mtti-lecturer-portal',   MTTI_MIS_PLUGIN_URL . 'assets/css/lecturer-portal.css', array(), MTTI_MIS_VERSION);
        wp_enqueue_script('jquery');
        wp_enqueue_script('mtti-lecturer-portal',  MTTI_MIS_PLUGIN_URL . 'assets/js/lecturer-portal.js', array('jquery'), MTTI_MIS_VERSION, true);
        wp_localize_script('mtti-lecturer-portal', 'mttiLecturer', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mtti_lecturer_nonce'),
        ));

        // Hide WP theme chrome (page title, breadcrumbs, footer padding) same as learner portal
        add_filter('body_class', function($classes) {
            $classes[] = 'mtti-portal-active';
            return $classes;
        });
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
            /* Hide the blue WordPress admin bar overlap on lecturer portal */
            body.mtti-portal-active .site-header,
            body.mtti-portal-active .ast-site-header-wrap { display:none!important; }
        ');
    }


    /* ══════════════════════════════════════════════════
     *  MAIN RENDER
     * ══════════════════════════════════════════════════ */
    public function render_portal() {
        global $wpdb;

        if (!is_user_logged_in()) {
            return $this->render_login();
        }

        $user_id      = get_current_user_id();
        $current_user = wp_get_current_user();
        $is_admin     = current_user_can('manage_options') || current_user_can('manage_mtti');

        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}mtti_staff s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.user_id = %d AND s.status = 'Active'",
            $user_id
        ));

        // Allow admins even if not in staff table — create a virtual staff object
        if (!$staff && $is_admin) {
            $staff = new stdClass();
            $staff->staff_id     = 0;
            $staff->user_id      = $user_id;
            $staff->display_name = $current_user->display_name;
            $staff->user_email   = $current_user->user_email;
            $staff->first_name   = $current_user->display_name;
            $staff->last_name    = '';
            $staff->department   = 'Administration';
            $staff->role         = 'Administrator';
        }

        if (!$staff) {
            // Diagnose: check if user has mtti_teacher role but no staff record
            $user_roles = $current_user->roles ?? [];
            $has_teacher_role = in_array('mtti_teacher', $user_roles) || in_array('administrator', $user_roles);
            
            ob_start();
            echo '<div style="max-width:520px;margin:60px auto;padding:40px;background:#fff;border:1px solid #ddd;border-radius:8px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.08);">';
            echo '<div style="font-size:56px;margin-bottom:16px;">🔒</div>';
            echo '<h2 style="margin:0 0 8px;">Lecturer Portal Access</h2>';
            
            if ($has_teacher_role) {
                echo '<p style="color:#d32f2f;font-weight:600;">Your account (' . esc_html($current_user->user_login) . ') has the teacher role but is not linked to a staff record.</p>';
                echo '<p style="color:#555;font-size:14px;">Please ask the administrator to go to:<br>';
                echo '<strong>MTTI MIS → 👩‍🏫 Teachers</strong><br>and link your WordPress account to your staff profile.</p>';
            } else {
                echo '<p style="color:#555;">Your account is not set up as a lecturer. Contact the administrator.</p>';
                echo '<p style="font-size:13px;color:#999;">Logged in as: <strong>' . esc_html($current_user->user_login) . '</strong></p>';
            }
            
            echo '<a href="' . wp_logout_url(get_permalink()) . '" style="display:inline-block;margin-top:16px;padding:8px 20px;background:#f44336;color:white;border-radius:4px;text-decoration:none;font-size:13px;">Log Out</a>';
            echo '</div>';
            return ob_get_clean();
        }

        $tab = isset($_GET['ltab']) ? sanitize_key($_GET['ltab']) : 'dashboard';

        ob_start();
        // Use same id as learner portal so all CSS rules apply identically
        echo '<div class="mtti-portal-wrapper" id="mtti-portal">';
        $this->render_header($staff);
        echo '<div class="mtti-portal-container">';
        $this->render_sidebar($tab);
        echo '<main class="mtti-portal-main">';

        switch ($tab) {
            case 'scheme':     $this->render_scheme($staff);     break;
            case 'attendance': $this->render_attendance($staff); break;
            case 'sessions':   $this->render_sessions($staff);   break;
            case 'students':   $this->render_students($staff);   break;
            case 'reports':    $this->render_reports($staff);    break;
            case 'create':     $this->render_content_creator($staff); break;
            case 'quiz':       $this->render_quiz_generator($staff);  break;
            default:           $this->render_dashboard($staff);  break;
        }

        echo '</main></div></div>';
        return ob_get_clean();
    }

    /* ── LOGIN ─────────────────────────────────────── */
    private function render_login() {
        $login_url = wp_login_url(get_permalink());
        return '<div class="mtti-portal-login">
            <div class="mtti-login-card">
                <span class="mtti-login-icon">👩‍🏫</span>
                <h2>Lecturer Portal</h2>
                <p>MTTI Staff Access</p>
                <a href="' . esc_url($login_url) . '" class="mtti-btn mtti-btn-primary mtti-btn-block">Login to Continue</a>
            </div>
        </div>';
    }

    /* ── HEADER ────────────────────────────────────── */
    private function render_header($staff) {
        $logo = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        $name = esc_html($staff->display_name);
        echo '<header class="mtti-portal-header">
            <div class="mtti-portal-logo">
                <img src="' . esc_url($logo) . '" alt="MTTI">
                <div class="mtti-portal-title">
                    <h1>MTTI — Lecturer Portal</h1>
                    <span class="mtti-portal-motto">Empowering Teachers · Tracking Progress</span>
                </div>
            </div>
            <div class="mtti-header-right">
                <button class="mtti-dark-toggle" title="Toggle dark mode">🌙</button>
                <div class="mtti-portal-user">
                    <div class="mtti-user-info">
                        <span class="mtti-user-name">' . $name . '</span>
                        <span class="mtti-user-id">' . esc_html($staff->position ?? 'Lecturer') . '</span>
                    </div>
                    ' . get_avatar($staff->user_id, 36) . '
                </div>
            </div>
        </header>';
    }

    /* ── SIDEBAR ───────────────────────────────────── */
    private function render_sidebar($active_tab) {
        $base = get_permalink();
        $menu = array(
            'dashboard'  => array('icon'=>'📊', 'label'=>'Dashboard'),
            'scheme'     => array('icon'=>'📋', 'label'=>'Scheme of Work'),
            'attendance' => array('icon'=>'✅', 'label'=>'Mark Attendance'),
            'sessions'   => array('icon'=>'⏱️', 'label'=>'Session Timer'),
            'students'   => array('icon'=>'👥', 'label'=>'My Students'),
            'reports'    => array('icon'=>'📈', 'label'=>'Reports'),
            'create'     => array('icon'=>'⚡', 'label'=>'Content Creator'),
            'quiz'       => array('icon'=>'🧠', 'label'=>'Quiz Builder'),
        );
        echo '<nav class="mtti-portal-sidebar"><ul class="mtti-portal-menu">';
        foreach ($menu as $tab => $item) {
            $active = $tab === $active_tab ? ' class="active"' : '';
            $url    = add_query_arg('ltab', $tab, $base);
            echo "<li{$active}><a href='" . esc_url($url) . "'>
                <span class='menu-icon'>{$item['icon']}</span>
                <span class='menu-label'>{$item['label']}</span>
            </a></li>";
        }
        echo '</ul>';
        echo '<div class="mtti-portal-logout"><a href="' . wp_logout_url(get_permalink()) . '"><span class="menu-icon">🚪</span> Log Out</a></div>';
        echo '</nav>';
    }

    /* ══════════════════════════════════════════════════
     *  DASHBOARD
     * ══════════════════════════════════════════════════ */
    private function render_dashboard($staff) {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        // Get assigned courses (via live classes or enrollments)
        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.course_id, c.course_code, c.course_name, c.duration_weeks
             FROM {$p}courses c
             INNER JOIN {$p}course_teachers ct ON ct.course_id = c.course_id
             WHERE ct.staff_id = %d AND c.status='Active'
             LIMIT 10",
            $staff->staff_id
        ));

        // Stats
        $total_students = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e.student_id) FROM {$p}enrollments e INNER JOIN {$p}course_teachers ct ON ct.course_id=e.course_id AND ct.staff_id=%d WHERE e.status IN ('Active','Enrolled','In Progress')",
            $staff->staff_id
        ));
        $sessions_today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}session_logs WHERE staff_id=%d AND DATE(clock_in)=CURDATE()",
            $staff->staff_id
        ));
        $scheme_done = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}scheme_of_work sow
             INNER JOIN {$p}course_teachers ct ON ct.course_id=sow.course_id
             WHERE ct.staff_id=%d AND sow.status='Completed'",
            $staff->staff_id
        ));
        $pending_weeks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}scheme_of_work sow
             INNER JOIN {$p}course_teachers ct ON ct.course_id=sow.course_id
             WHERE ct.staff_id=%d AND sow.status='Pending'",
            $staff->staff_id
        ));

        $base = get_permalink();
        echo '<h2 class="mtti-page-title">Welcome, ' . esc_html($staff->display_name) . ' 👋</h2>';

        // Stat cards
        echo '<div class="mtti-stats-grid">';
        foreach ([
            ['👥', $total_students ?? 0, 'My Students', ''],
            ['📚', count($courses), 'My Courses', ''],
            ['✅', $scheme_done ?? 0, 'Weeks Done', 'success'],
            ['⏳', $pending_weeks ?? 0, 'Weeks Pending', $pending_weeks > 0 ? 'warning' : ''],
        ] as $s) {
            echo "<div class='mtti-stat-card {$s[3]}'>
                <div class='mtti-stat-icon'>{$s[0]}</div>
                <div><div class='mtti-stat-value'>{$s[1]}</div><div class='mtti-stat-label'>{$s[2]}</div></div>
            </div>";
        }
        echo '</div>';

        // Active session check — auto-expire anything older than 8 hours (prevents month-old ghost sessions)
        $active_session = $wpdb->get_row($wpdb->prepare(
            "SELECT sl.*, c.course_name FROM {$p}session_logs sl
             LEFT JOIN {$p}courses c ON sl.course_id=c.course_id
             WHERE sl.staff_id=%d AND sl.clock_out IS NULL
             ORDER BY sl.clock_in DESC LIMIT 1",
            $staff->staff_id
        ));

        if ($active_session) {
            $age_hours = (time() - strtotime($active_session->clock_in)) / 3600;
            if ($age_hours > 8) {
                // Auto-close: set clock_out to clock_in + planned_hours (or 3h default)
                $planned_mins = $active_session->planned_hours ? $active_session->planned_hours * 60 : 180;
                $auto_out     = date('Y-m-d H:i:s', strtotime($active_session->clock_in) + ($planned_mins * 60));
                $wpdb->update(
                    $p . 'session_logs',
                    ['clock_out' => $auto_out, 'duration_minutes' => $planned_mins, 'notes' => ($active_session->notes ? $active_session->notes . ' [Auto-closed after 8h]' : 'Auto-closed after 8h')],
                    ['session_id' => $active_session->session_id]
                );
                $active_session = null; // suppress the banner
            }
        }

        if ($active_session) {
            $started = strtotime($active_session->clock_in);
            $nonce_dismiss = wp_create_nonce('mtti_lecturer_nonce');
            echo '<div class="mtti-next-up" style="background:linear-gradient(135deg,#C62828,#D32F2F);">
                <div class="mtti-next-up-icon">⏱️</div>
                <div class="mtti-next-up-content">
                    <h4>SESSION IN PROGRESS</h4>
                    <strong>' . esc_html($active_session->course_name) . ' — ' . esc_html($active_session->topic) . '</strong>
                    <span id="live-timer" data-start="' . $started . '" style="display:block;font-size:12px;opacity:.85;margin-top:4px;">Started ' . esc_html(human_time_diff($started)) . ' ago</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;margin-left:auto;">
                    <a href="' . esc_url(add_query_arg('ltab','sessions',$base)) . '" class="mtti-btn" style="background:rgba(255,255,255,.2);color:white;font-size:12px;">Clock Out →</a>
                    <button onclick="mttiForceClockOut(\'' . $nonce_dismiss . '\',' . $active_session->session_id . ')" class="mtti-btn" style="background:rgba(0,0,0,.25);color:white;font-size:11px;border:none;cursor:pointer;">✕ Dismiss</button>
                </div>
            </div>
            <script>
            function mttiForceClockOut(nonce, sid) {
                if (!confirm("Close this session now?")) return;
                jQuery.post("' . admin_url('admin-ajax.php') . '", {
                    action:"mtti_lecturer_clock_out", nonce:nonce, session_id:sid
                }, function(r){ location.reload(); });
            }
            </script>';
        }

        // My Courses
        if ($courses) {
            echo '<div class="mtti-card"><h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px;">MY COURSES</h3>';
            echo '<div class="mtti-units-accordion">';
            foreach ($courses as $c) {
                $planned = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}scheme_of_work WHERE course_id=%d",$c->course_id)));
                $done    = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}scheme_of_work WHERE course_id=%d AND status='Completed'",$c->course_id)));
                $pct     = $c->duration_weeks > 0 ? round(($done / $c->duration_weeks) * 100) : 0;
                $stu_count = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}enrollments WHERE course_id=%d AND status IN ('Active','Enrolled','In Progress')",
                    $c->course_id
                )));
                echo "<div class='mtti-unit-item'>
                    <div class='mtti-unit-name'>
                        <strong>{$c->course_code}</strong> — " . esc_html($c->course_name) . "
                        <small style='color:var(--text-muted);display:block;'>{$stu_count} students · {$done}/{$c->duration_weeks} weeks covered</small>
                        <div class='mtti-progress-bar' style='margin-top:5px;'><div class='mtti-progress-fill' style='width:{$pct}%;'></div></div>
                    </div>
                    <a href='" . esc_url(add_query_arg(array('ltab'=>'scheme','course_id'=>$c->course_id),$base)) . "' class='mtti-btn mtti-btn-secondary' style='font-size:12px;padding:6px 12px;'>📋 Scheme</a>
                    <a href='" . esc_url(add_query_arg(array('ltab'=>'attendance','course_id'=>$c->course_id),$base)) . "' class='mtti-btn mtti-btn-secondary' style='font-size:12px;padding:6px 12px;'>✅ Attend</a>
                    <a href='" . esc_url(add_query_arg(array('ltab'=>'sessions','course_id'=>$c->course_id),$base)) . "' class='mtti-btn mtti-btn-primary' style='font-size:12px;padding:6px 12px;'>▶ Start</a>
                </div>";
            }
            echo '</div></div>';
        } else {
            echo '<div class="mtti-card"><p style="color:var(--text-muted);text-align:center;padding:20px;">No courses assigned yet. Ask admin to assign you to enrollments.</p></div>';
        }
    }

    /* ══════════════════════════════════════════════════
     *  SCHEME OF WORK — Lecturer checklist view
     * ══════════════════════════════════════════════════ */
    private function render_scheme($staff) {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

        // Get lecturer's courses
        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.course_id, c.course_code, c.course_name, c.duration_weeks
             FROM {$p}courses c
             INNER JOIN {$p}course_teachers ct ON ct.course_id=c.course_id
             WHERE ct.staff_id=%d AND c.status='Active'",
            $staff->staff_id
        ));

        echo '<h2 class="mtti-page-title">📋 Scheme of Work</h2>';

        if (!$courses) {
            echo '<div class="mtti-empty-state"><span>📋</span><h3>No courses assigned</h3><p>Contact admin to assign courses to you.</p></div>';
            return;
        }

        // Course selector
        if (!$course_id && $courses) $course_id = $courses[0]->course_id;

        echo '<div style="margin-bottom:16px;">';
        foreach ($courses as $c) {
            $active = $c->course_id == $course_id ? 'mtti-btn-primary' : 'mtti-btn-secondary';
            $url = add_query_arg(array('ltab'=>'scheme','course_id'=>$c->course_id), get_permalink());
            echo "<a href='" . esc_url($url) . "' class='mtti-btn {$active}' style='margin-right:6px;font-size:12px;'>{$c->course_code}</a>";
        }
        echo '</div>';

        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}courses WHERE course_id=%d", $course_id));
        if (!$course) return;

        $weeks = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.unit_name FROM {$p}scheme_of_work s
             LEFT JOIN {$p}course_units u ON s.unit_id=u.unit_id
             WHERE s.course_id=%d ORDER BY s.week_number ASC",
            $course_id
        ));

        $done    = count(array_filter($weeks, function($w){ return $w->status === 'Completed'; }));
        $total   = $course->duration_weeks;
        $pct     = $total > 0 ? round(($done / $total) * 100) : 0;

        echo "<h3 style='margin-bottom:6px;'>{$course->course_code} — " . esc_html($course->course_name) . "</h3>";
        echo "<p style='color:var(--text-muted);font-size:13px;margin-bottom:12px;'>{$done} of {$total} weeks completed · {$pct}% through course</p>";
        echo "<div class='mtti-progress-bar' style='height:10px;margin-bottom:20px;'><div class='mtti-progress-fill' style='width:{$pct}%;'></div></div>";

        if (!$weeks) {
            echo '<div class="mtti-card" style="text-align:center;padding:30px;"><p style="color:var(--text-muted);">No scheme of work found for this course. Ask the admin to create the scheme.</p></div>';
            return;
        }

        // Timeline view
        echo '<div class="mtti-scheme-timeline">';
        foreach ($weeks as $w) {
            $status_map = [
                'Completed'   => ['color'=>'#2E7D32', 'bg'=>'#E8F5E9', 'icon'=>'✅'],
                'In Progress' => ['color'=>'#FF8F00', 'bg'=>'#FFF3E0', 'icon'=>'▶'],
                'Pending'     => ['color'=>'#999',    'bg'=>'#F5F5F5', 'icon'=>'○'],
                'Skipped'     => ['color'=>'#C62828', 'bg'=>'#FFEBEE', 'icon'=>'⊘'],
            ];
            $sm = $status_map[$w->status] ?? $status_map['Pending'];

            // Update status form
            $nonce = wp_create_nonce('mtti_lecturer_nonce');
            echo "<div class='mtti-scheme-week' style='display:flex;gap:14px;margin-bottom:12px;align-items:flex-start;'>
                <div style='width:36px;height:36px;border-radius:50%;background:{$sm['bg']};color:{$sm['color']};display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;border:2px solid {$sm['color']};'>{$sm['icon']}</div>
                <div class='mtti-card' style='flex:1;padding:14px 16px;margin-bottom:0;'>
                    <div style='display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;'>
                        <div>
                            <strong style='font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;'>WEEK {$w->week_number}" . ($w->unit_name ? " · " . esc_html($w->unit_name) : '') . "</strong>
                            <div style='font-size:14px;font-weight:600;color:var(--text-primary);margin-top:2px;'>" . esc_html($w->topic) . "</div>
                            " . ($w->objectives ? "<div style='font-size:12px;color:var(--text-muted);margin-top:4px;'>" . esc_html($w->objectives) . "</div>" : '') . "
                            <div style='display:flex;gap:14px;margin-top:6px;flex-wrap:wrap;'>
                                " . ($w->teaching_method ? "<span style='font-size:11px;color:var(--text-muted);'>📖 " . esc_html($w->teaching_method) . "</span>" : '') . "
                                " . ($w->duration_hours ? "<span style='font-size:11px;color:var(--text-muted);'>⏱ {$w->duration_hours}h planned</span>" : '') . "
                                " . ($w->resources ? "<span style='font-size:11px;color:var(--text-muted);'>📦 " . esc_html($w->resources) . "</span>" : '') . "
                            </div>
                        </div>
                        <div style='display:flex;gap:6px;flex-wrap:wrap;'>
                            " . ($w->status !== 'Completed' ? "<button class='mtti-btn mtti-btn-primary' style='font-size:11px;padding:5px 10px;' onclick='mttiMarkWeek({$w->week_id},\"Completed\",\"{$nonce}\")'>✓ Done</button>" : '') . "
                            " . ($w->status === 'Pending' ? "<button class='mtti-btn mtti-btn-secondary' style='font-size:11px;padding:5px 10px;' onclick='mttiMarkWeek({$w->week_id},\"In Progress\",\"{$nonce}\")'>▶ Start</button>" : '') . "
                            " . ($w->status === 'Completed' ? "<button class='mtti-btn mtti-btn-secondary' style='font-size:11px;padding:5px 10px;' onclick='mttiMarkWeek({$w->week_id},\"Pending\",\"{$nonce}\")'>↩ Undo</button>" : '') . "
                        </div>
                    </div>
                </div>
            </div>";
        }
        echo '</div>';
    }

    /* ══════════════════════════════════════════════════
     *  SESSION TIMER
     * ══════════════════════════════════════════════════ */
    private function render_sessions($staff) {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.course_id, c.course_code, c.course_name
             FROM {$p}courses c INNER JOIN {$p}course_teachers ct ON ct.course_id=c.course_id
             WHERE ct.staff_id=%d AND c.status='Active'",
            $staff->staff_id
        ));

        // Active session — auto-expire if older than 8 hours
        $active = $wpdb->get_row($wpdb->prepare(
            "SELECT sl.*, c.course_name FROM {$p}session_logs sl
             LEFT JOIN {$p}courses c ON sl.course_id=c.course_id
             WHERE sl.staff_id=%d AND sl.clock_out IS NULL LIMIT 1",
            $staff->staff_id
        ));
        if ($active && (time() - strtotime($active->clock_in)) > 8 * 3600) {
            $pm = $active->planned_hours ? $active->planned_hours * 60 : 180;
            $wpdb->update($p . 'session_logs',
                ['clock_out' => date('Y-m-d H:i:s', strtotime($active->clock_in) + $pm * 60), 'duration_minutes' => $pm, 'notes' => 'Auto-closed after 8h'],
                ['session_id' => $active->session_id]
            );
            $active = null;
        }

        // Recent sessions (last 30)
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT sl.*, c.course_name,
             TIMESTAMPDIFF(MINUTE, sl.clock_in, COALESCE(sl.clock_out, NOW())) AS duration_min
             FROM {$p}session_logs sl
             LEFT JOIN {$p}courses c ON sl.course_id=c.course_id
             WHERE sl.staff_id=%d
             ORDER BY sl.clock_in DESC LIMIT 30",
            $staff->staff_id
        ));

        echo '<h2 class="mtti-page-title">⏱️ Session Timer</h2>';

        // Active session card
        if ($active) {
            $started  = strtotime($active->clock_in);
            $nonce    = wp_create_nonce('mtti_lecturer_nonce');
            $planned  = $active->planned_hours ? $active->planned_hours * 60 : 180;
            echo '<div class="mtti-card" style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:white;border:none;margin-bottom:20px;">
                <h3 style="margin:0 0 6px;font-size:12px;opacity:.78;text-transform:uppercase;letter-spacing:.08em;">SESSION IN PROGRESS</h3>
                <div style="font-size:18px;font-weight:700;margin-bottom:4px;">' . esc_html($active->course_name) . '</div>
                <div style="font-size:14px;opacity:.85;margin-bottom:16px;">Topic: ' . esc_html($active->topic) . '</div>
                <div id="session-timer" data-start="' . $started . '" data-planned="' . $planned . '"
                     style="font-size:42px;font-weight:700;letter-spacing:.04em;margin-bottom:16px;">00:00:00</div>
                <div id="session-progress-wrap" style="background:rgba(255,255,255,.25);border-radius:4px;height:8px;margin-bottom:16px;">
                    <div id="session-progress-fill" style="background:white;height:8px;border-radius:4px;width:0%;transition:width 1s;"></div>
                </div>
                <p style="font-size:12px;opacity:.75;margin-bottom:16px;">Planned: ' . ($active->planned_hours ?? '—') . ' hours · <span id="overtime-label"></span></p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button onclick="mttiClockOut(\'' . $nonce . '\',' . $active->session_id . ')" class="mtti-btn" style="background:white;color:#1B5E20;font-weight:700;">
                        ⏹ Clock Out
                    </button>
                    <a href="' . esc_url(add_query_arg(array('ltab'=>'attendance','course_id'=>$active->course_id), get_permalink())) . '" class="mtti-btn" style="background:rgba(255,255,255,.2);color:white;">
                        ✅ Mark Attendance Now
                    </a>
                </div>
            </div>';
        } else {
            // Clock In form
            echo '<div class="mtti-card" style="margin-bottom:20px;">
                <h3 style="margin:0 0 14px;">▶ Start New Session</h3>
                <form id="clock-in-form">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">COURSE *</label>
                        <select name="course_id" id="ci-course" class="mtti-form-group" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);" required>
                            <option value="">— Select Course —</option>';
            foreach ($courses as $c) {
                echo "<option value='{$c->course_id}'>{$c->course_code} — " . esc_html($c->course_name) . "</option>";
            }
            echo '      </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">PLANNED HOURS</label>
                        <select name="planned_hours" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);">
                            <option value="1">1 hour</option>
                            <option value="1.5">1.5 hours</option>
                            <option value="2" selected>2 hours</option>
                            <option value="2.5">2.5 hours</option>
                            <option value="3">3 hours</option>
                            <option value="4">4 hours</option>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">TOPIC BEING COVERED TODAY *</label>
                    <input type="text" name="topic" id="ci-topic" placeholder="e.g. Introduction to Networking, Practical 3: Soldering..." style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);" required>
                    <small style="color:var(--text-muted);">This will be logged against the scheme of work</small>
                </div>
                <div style="margin-bottom:14px;" id="scheme-week-selector" style="display:none;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">LINK TO SCHEME WEEK (optional)</label>
                    <select name="week_id" id="ci-week" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);">
                        <option value="">— Not linked —</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">NOTES (optional)</label>
                    <textarea name="notes" rows="2" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);" placeholder="Any additional notes..."></textarea>
                </div>
                <button type="button" onclick="mttiClockIn()" class="mtti-btn mtti-btn-primary" style="margin-top:14px;font-size:15px;padding:12px 28px;">
                    ▶ Clock In — Start Session
                </button>
                </form>
            </div>';
        }

        // Session history
        if ($recent) {
            echo '<div class="mtti-card">
                <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px;">RECENT SESSIONS</h3>
                <div style="overflow-x:auto;">
                <table class="mtti-table">
                    <thead><tr>
                        <th>Date</th><th>Course</th><th>Topic</th>
                        <th>Clock In</th><th>Clock Out</th><th>Duration</th><th>vs Planned</th>
                    </tr></thead><tbody>';
            foreach ($recent as $r) {
                $dur_h  = $r->duration_min ? round($r->duration_min / 60, 1) : '—';
                $diff   = '';
                $diff_color = 'var(--text-muted)';
                if ($r->planned_hours && $r->clock_out) {
                    $over = round($r->duration_min / 60 - $r->planned_hours, 1);
                    if ($over > 0.2) {
                        $diff = "+{$over}h over";
                        $diff_color = '#C62828';
                    } elseif ($over < -0.2) {
                        $diff = abs($over) . "h under";
                        $diff_color = '#1565C0';
                    } else {
                        $diff = '✓ On time';
                        $diff_color = '#2E7D32';
                    }
                }
                $cin  = date('H:i', strtotime($r->clock_in));
                $cout = $r->clock_out ? date('H:i', strtotime($r->clock_out)) : '<span style="color:#C62828;">Still running</span>';
                $date = date('d M Y', strtotime($r->clock_in));
                echo "<tr>
                    <td>{$date}</td>
                    <td><strong>" . esc_html($r->course_name ?? '—') . "</strong></td>
                    <td>" . esc_html($r->topic) . "</td>
                    <td>{$cin}</td>
                    <td>{$cout}</td>
                    <td><strong>{$dur_h}h</strong></td>
                    <td><span style='color:{$diff_color};font-size:12px;font-weight:600;'>{$diff}</span></td>
                </tr>";
            }
            echo '</tbody></table></div></div>';
        }
    }

    /* ══════════════════════════════════════════════════
     *  ATTENDANCE MARKING
     * ══════════════════════════════════════════════════ */
    private function render_attendance($staff) {
        global $wpdb;
        $p         = $wpdb->prefix . 'mtti_';
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
        $att_date  = isset($_GET['att_date'])  ? sanitize_text_field($_GET['att_date']) : date('Y-m-d');

        $courses = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.course_id, c.course_code, c.course_name
             FROM {$p}courses c INNER JOIN {$p}course_teachers ct ON ct.course_id=c.course_id
             WHERE ct.staff_id=%d AND c.status='Active'",
            $staff->staff_id
        ));

        if (!$course_id && $courses) $course_id = $courses[0]->course_id;

        echo '<h2 class="mtti-page-title">✅ Mark Attendance</h2>';

        // Course + date selectors
        echo '<div class="mtti-card" style="margin-bottom:16px;">
            <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <input type="hidden" name="ltab" value="attendance">
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">COURSE</label>
                    <select name="course_id" style="padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);">';
        foreach ($courses as $c) {
            $sel = $c->course_id == $course_id ? 'selected' : '';
            echo "<option value='{$c->course_id}' {$sel}>{$c->course_code} — " . esc_html($c->course_name) . "</option>";
        }
        echo '      </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">DATE</label>
                    <input type="date" name="att_date" value="' . esc_attr($att_date) . '" style="padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--bg-subtle);color:var(--text-primary);">
                </div>
                <button type="submit" class="mtti-btn mtti-btn-primary">Load Students</button>
            </form>
        </div>';

        if (!$course_id) return;

        // Get students enrolled in this course
        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT s.student_id, s.admission_number, u.display_name
             FROM {$p}students s
             LEFT JOIN {$wpdb->users} u ON s.user_id=u.ID
             INNER JOIN {$p}enrollments e ON s.student_id=e.student_id
             WHERE e.course_id=%d AND e.status IN ('Active','Enrolled','In Progress')
             ORDER BY u.display_name",
            $course_id
        ));

        if (!$students) {
            echo '<div class="mtti-card"><p style="text-align:center;color:var(--text-muted);padding:20px;">No students found for this course.</p></div>';
            return;
        }

        // Get existing attendance for this date
        $existing_att = $wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$p}attendance a
             INNER JOIN {$p}enrollments e ON a.enrollment_id=e.enrollment_id
             WHERE e.course_id=%d AND a.date=%s",
            $course_id, $att_date
        ));

        $att_map = array();
        if ($existing_att) {
            // Re-build map by student
            $existing_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.enrollment_id, a.status, e.student_id FROM {$p}attendance a
                 INNER JOIN {$p}enrollments e ON a.enrollment_id=e.enrollment_id
                 WHERE e.course_id=%d AND a.date=%s",
                $course_id, $att_date
            ));
            foreach ($existing_rows as $row) {
                $att_map[$row->student_id] = $row->status;
            }
        }

        $already_marked = !empty($att_map);
        $nonce = wp_create_nonce('mtti_lecturer_nonce');

        echo '<div class="mtti-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h3 style="margin:0;">Students — ' . date('d M Y', strtotime($att_date)) . '</h3>
                ' . ($already_marked ? '<span style="background:#E8F5E9;color:#2E7D32;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">✓ Already Marked</span>' : '') . '
            </div>
            <div style="margin-bottom:14px;display:flex;gap:8px;">
                <button onclick="mttiMarkAll(\'Present\',\'' . $nonce . '\',\'' . $att_date . '\',' . $course_id . ')" class="mtti-btn mtti-btn-secondary" style="font-size:12px;padding:6px 12px;">✅ All Present</button>
                <button onclick="mttiMarkAll(\'Absent\',\'' . $nonce . '\',\'' . $att_date . '\',' . $course_id . ')" class="mtti-btn mtti-btn-secondary" style="font-size:12px;padding:6px 12px;color:#C62828;">❌ All Absent</button>
            </div>';

        echo '<div class="mtti-units-accordion" id="attendance-list">';
        foreach ($students as $s) {
            $current = $att_map[$s->student_id] ?? 'Present';
            $statuses = ['Present','Absent','Late','Excused'];
            $status_colors = ['Present'=>'#2E7D32','Absent'=>'#C62828','Late'=>'#FF8F00','Excused'=>'#1565C0'];

            echo "<div class='mtti-unit-item' data-student='{$s->student_id}'>
                <div class='mtti-unit-name'>" . esc_html($s->display_name) . " <small style='color:var(--text-muted);'>({$s->admission_number})</small></div>
                <div style='display:flex;gap:6px;'>";
            foreach ($statuses as $st) {
                $active_style = $current === $st ? "background:{$status_colors[$st]};color:white;" : '';
                echo "<button class='mtti-btn mtti-btn-secondary att-btn' data-status='{$st}' data-student='{$s->student_id}'
                           onclick='mttiSetAttendance(this,\"" . $s->student_id . "\",\"{$st}\",\"{$nonce}\",\"{$att_date}\",{$course_id})'
                           style='font-size:11px;padding:4px 10px;{$active_style}'>{$st}</button>";
            }
            echo '</div></div>';
        }
        echo '</div></div>';

        // Attendance summary for this course (last 10 sessions)
        $summary = $wpdb->get_results($wpdb->prepare(
            "SELECT a.date,
             SUM(CASE WHEN a.status='Present' THEN 1 ELSE 0 END) AS present,
             SUM(CASE WHEN a.status='Absent' THEN 1 ELSE 0 END) AS absent,
             COUNT(*) AS total
             FROM {$p}attendance a
             INNER JOIN {$p}enrollments e ON a.enrollment_id=e.enrollment_id
             WHERE e.course_id=%d
             GROUP BY a.date ORDER BY a.date DESC LIMIT 10",
            $course_id
        ));

        if ($summary) {
            echo '<div class="mtti-card" style="margin-top:14px;">
                <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:12px;">RECENT ATTENDANCE SESSIONS</h3>
                <table class="mtti-table"><thead><tr>
                    <th>Date</th><th>Present</th><th>Absent</th><th>Rate</th>
                </tr></thead><tbody>';
            foreach ($summary as $sm) {
                $rate = $sm->total > 0 ? round(($sm->present / $sm->total) * 100) : 0;
                $rc = $rate >= 80 ? '#2E7D32' : ($rate >= 60 ? '#FF8F00' : '#C62828');
                echo "<tr>
                    <td>" . date('d M Y', strtotime($sm->date)) . "</td>
                    <td style='color:#2E7D32;font-weight:600;'>{$sm->present}</td>
                    <td style='color:#C62828;'>{$sm->absent}</td>
                    <td><strong style='color:{$rc};'>{$rate}%</strong></td>
                </tr>";
            }
            echo '</tbody></table></div>';
        }

        // ── SESSION CODE PANEL ────────────────────────────────────────────
        // Generate a 6-digit code teachers can display; students enter it to self-mark
        if ($course_id) {
            $code_key   = 'mtti_att_code_' . $course_id . '_' . $att_date;
            $active_code = get_transient($code_key);
            $nonce2 = wp_create_nonce('mtti_lecturer_nonce');

            echo '<div class="mtti-card" style="margin-top:18px;border:2px solid var(--mtti-primary);">';
            echo '<h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">🔑 Session Code (Anti-Cheat)</h3>';

            if ($active_code) {
                echo '<p style="font-size:13px;color:var(--text-2);margin:0 0 10px;">Show this code to students — it expires in <strong>20 minutes</strong>. Students must be in class to enter it.</p>';
                echo '<div style="font-size:52px;font-weight:900;letter-spacing:14px;color:var(--mtti-primary);text-align:center;padding:16px 0;background:var(--bg-subtle);border-radius:8px;margin-bottom:12px;">' . esc_html($active_code) . '</div>';
                echo '<button onclick="mttiRevokeCode(\'' . $nonce2 . '\',' . $course_id . ',\'' . $att_date . '\')" class="mtti-btn mtti-btn-secondary" style="color:#C62828;width:100%;">🗑 Revoke Code</button>';
            } else {
                echo '<p style="font-size:13px;color:var(--text-2);margin:0 0 12px;">Generate a code before class starts. Students enter it in their portal to mark themselves present.</p>';
                echo '<button onclick="mttiGenerateCode(\'' . $nonce2 . '\',' . $course_id . ',\'' . $att_date . '\')" class="mtti-btn mtti-btn-primary" style="width:100%;">🔑 Generate Session Code</button>';
            }
            echo '<div id="mtti-code-msg" style="margin-top:8px;font-size:12px;"></div>';
            echo '</div>';

            // JS for code generation/revocation
            echo '<script>
function mttiGenerateCode(nonce, courseId, date) {
    jQuery.post(mttiLecturer.ajaxUrl, {
        action:"mtti_generate_att_code", nonce:nonce, course_id:courseId, att_date:date
    }, function(r) {
        if (r.success) { location.reload(); }
        else { document.getElementById("mtti-code-msg").innerText = r.data || "Error"; }
    });
}
function mttiRevokeCode(nonce, courseId, date) {
    if (!confirm("Revoke the current session code?")) return;
    jQuery.post(mttiLecturer.ajaxUrl, {
        action:"mtti_revoke_att_code", nonce:nonce, course_id:courseId, att_date:date
    }, function(r) { if (r.success) location.reload(); });
}
</script>';
        }
    }

    /* ══════════════════════════════════════════════════
     *  MY STUDENTS
     * ══════════════════════════════════════════════════ */
    private function render_students($staff) {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT s.student_id, s.admission_number, u.display_name, u.user_email,
             c.course_code, c.course_name, e.status as enrollment_status,
             sb.balance,
             (SELECT AVG(ur.percentage) FROM {$p}unit_results ur WHERE ur.student_id=s.student_id) as avg_grade
             FROM {$p}students s
             LEFT JOIN {$wpdb->users} u ON s.user_id=u.ID
             INNER JOIN {$p}enrollments e ON s.student_id=e.student_id
             LEFT JOIN {$p}courses c ON e.course_id=c.course_id
             INNER JOIN {$p}course_teachers ct ON ct.course_id=e.course_id AND ct.staff_id=%d
             LEFT JOIN {$p}student_balances sb ON s.student_id=sb.student_id AND sb.course_id=e.course_id
             WHERE e.status IN ('Active','Enrolled','In Progress')
             ORDER BY u.display_name",
            $staff->staff_id
        ));

        echo '<h2 class="mtti-page-title">👥 My Students</h2>';

        if (!$students) {
            echo '<div class="mtti-empty-state"><span>👥</span><h3>No students assigned</h3></div>';
            return;
        }

        echo '<div class="mtti-card">
            <p style="color:var(--text-muted);margin-bottom:14px;">' . count($students) . ' students across your courses</p>
            <div style="overflow-x:auto;">
            <table class="mtti-table"><thead><tr>
                <th>Student</th><th>Admission #</th><th>Course</th><th>Avg Grade</th><th>Balance</th><th>Status</th>
            </tr></thead><tbody>';

        foreach ($students as $s) {
            $avg  = $s->avg_grade ? round($s->avg_grade) . '%' : '—';
            $avg_color = $s->avg_grade >= 70 ? '#2E7D32' : ($s->avg_grade >= 50 ? '#FF8F00' : ($s->avg_grade ? '#C62828' : 'var(--text-muted)'));
            $bal  = $s->balance > 0 ? '<span style="color:#C62828;">KES ' . number_format($s->balance, 0) . '</span>' : '<span style="color:#2E7D32;">✓ Cleared</span>';
            echo "<tr>
                <td><strong>" . esc_html($s->display_name) . "</strong><br><small style='color:var(--text-muted);'>" . esc_html($s->user_email) . "</small></td>
                <td>" . esc_html($s->admission_number) . "</td>
                <td><strong>" . esc_html($s->course_code) . "</strong><br><small style='color:var(--text-muted);'>" . esc_html($s->course_name) . "</small></td>
                <td><strong style='color:{$avg_color};'>{$avg}</strong></td>
                <td>{$bal}</td>
                <td><span class='mtti-status-badge " . strtolower($s->enrollment_status) . "'>{$s->enrollment_status}</span></td>
            </tr>";
        }
        echo '</tbody></table></div></div>';
    }

    /* ══════════════════════════════════════════════════
     *  REPORTS — Over-time analysis
     * ══════════════════════════════════════════════════ */
    private function render_reports($staff) {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        // Monthly session summary
        $monthly = $wpdb->get_results($wpdb->prepare(
            "SELECT
               DATE_FORMAT(clock_in,'%%Y-%%m') AS month,
               COUNT(*) AS sessions,
               SUM(TIMESTAMPDIFF(MINUTE, clock_in, COALESCE(clock_out, NOW()))) AS total_min,
               SUM(planned_hours * 60) AS planned_min,
               SUM(CASE WHEN clock_out IS NOT NULL AND TIMESTAMPDIFF(MINUTE,clock_in,clock_out) > (planned_hours*60+15) THEN 1 ELSE 0 END) AS overrun_count
             FROM {$p}session_logs
             WHERE staff_id=%d AND clock_out IS NOT NULL
             GROUP BY month ORDER BY month DESC LIMIT 6",
            $staff->staff_id
        ));

        // Attendance compliance
        $att_summary = $wpdb->get_results($wpdb->prepare(
            "SELECT c.course_code, c.course_name,
             COUNT(DISTINCT a.date) AS sessions_marked,
             SUM(CASE WHEN a.status='Present' THEN 1 ELSE 0 END) AS total_present,
             COUNT(*) AS total_records
             FROM {$p}attendance a
             INNER JOIN {$p}enrollments e ON a.enrollment_id=e.enrollment_id
             INNER JOIN {$p}courses c ON e.course_id=c.course_id
             INNER JOIN {$p}course_teachers ct ON ct.course_id=c.course_id AND ct.staff_id=%d
             GROUP BY c.course_id",
            $staff->staff_id
        ));

        echo '<h2 class="mtti-page-title">📈 My Reports</h2>';

        // Monthly sessions
        echo '<div class="mtti-card" style="margin-bottom:16px;">
            <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:14px;">MONTHLY SESSION LOG</h3>';

        if ($monthly) {
            echo '<table class="mtti-table"><thead><tr>
                <th>Month</th><th>Sessions</th><th>Total Hours</th><th>Planned Hours</th><th>Overruns</th><th>Efficiency</th>
            </tr></thead><tbody>';
            foreach ($monthly as $m) {
                $actual_h  = round($m->total_min / 60, 1);
                $planned_h = round($m->planned_min / 60, 1);
                $eff       = $planned_h > 0 ? round(($actual_h / $planned_h) * 100) : 100;
                $eff_color = abs($eff - 100) <= 10 ? '#2E7D32' : ($eff > 120 ? '#C62828' : '#FF8F00');
                $month_label = date('M Y', strtotime($m->month . '-01'));
                echo "<tr>
                    <td><strong>{$month_label}</strong></td>
                    <td>{$m->sessions}</td>
                    <td><strong>{$actual_h}h</strong></td>
                    <td>{$planned_h}h</td>
                    <td>" . ($m->overrun_count > 0 ? "<span style='color:#C62828;font-weight:600;'>{$m->overrun_count} ⚠</span>" : '<span style="color:#2E7D32;">None ✓</span>') . "</td>
                    <td><span style='color:{$eff_color};font-weight:700;'>{$eff}%</span></td>
                </tr>";
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:var(--text-muted);text-align:center;padding:20px;">No session data yet. Start sessions using the Session Timer.</p>';
        }
        echo '</div>';

        // Attendance rates
        if ($att_summary) {
            echo '<div class="mtti-card">
                <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:14px;">ATTENDANCE RATES BY COURSE</h3>
                <table class="mtti-table"><thead><tr>
                    <th>Course</th><th>Sessions Marked</th><th>Avg Attendance</th>
                </tr></thead><tbody>';
            foreach ($att_summary as $a) {
                $rate = $a->total_records > 0 ? round(($a->total_present / $a->total_records) * 100) : 0;
                $rc   = $rate >= 80 ? '#2E7D32' : ($rate >= 60 ? '#FF8F00' : '#C62828');
                echo "<tr>
                    <td><strong>" . esc_html($a->course_code) . "</strong> — " . esc_html($a->course_name) . "</td>
                    <td>{$a->sessions_marked}</td>
                    <td><strong style='color:{$rc};'>{$rate}%</strong></td>
                </tr>";
            }
            echo '</tbody></table></div>';
        }
    }

    /* ══════════════════════════════════════════════════
     *  AJAX HANDLERS
     * ══════════════════════════════════════════════════ */
    public function ajax_clock_in() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('Not logged in');

        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT staff_id FROM {$p}staff WHERE user_id=%d AND status='Active'", get_current_user_id()
        ));
        if (!$staff) wp_send_json_error('Not a staff member');

        // Check not already clocked in
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT session_id FROM {$p}session_logs WHERE staff_id=%d AND clock_out IS NULL", $staff->staff_id
        ));
        if ($existing) wp_send_json_error('Already clocked in. Clock out first.');

        $wpdb->insert($p . 'session_logs', array(
            'staff_id'      => $staff->staff_id,
            'course_id'     => intval($_POST['course_id']),
            'topic'         => sanitize_text_field($_POST['topic'] ?? ''),
            'week_id'       => intval($_POST['week_id']) ?: null,
            'planned_hours' => floatval($_POST['planned_hours'] ?? 2),
            'notes'         => sanitize_textarea_field($_POST['notes'] ?? ''),
            'clock_in'      => current_time('mysql'),
        ));

        wp_send_json_success(array('session_id' => $wpdb->insert_id, 'clock_in' => current_time('mysql')));
    }

    public function ajax_clock_out() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('Not logged in');

        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';
        $session_id = intval($_POST['session_id']);

        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}session_logs WHERE session_id=%d", $session_id));
        if (!$session) wp_send_json_error('Session not found');

        $clock_out = current_time('mysql');
        $duration  = round((strtotime($clock_out) - strtotime($session->clock_in)) / 60);

        $wpdb->update($p . 'session_logs', array(
            'clock_out'      => $clock_out,
            'duration_minutes'=> $duration,
        ), array('session_id' => $session_id));

        // Auto-mark scheme week as In Progress if linked
        if ($session->week_id) {
            $wpdb->update($p . 'scheme_of_work', array('status' => 'In Progress'), array('week_id' => $session->week_id, 'status' => 'Pending'));
        }

        wp_send_json_success(array(
            'duration_hours' => round($duration / 60, 1),
            'over_under'     => $session->planned_hours ? round($duration / 60 - $session->planned_hours, 1) : null,
        ));
    }

    public function ajax_mark_attendance() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();

        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $student_id = intval($_POST['student_id']);
        $course_id  = intval($_POST['course_id']);
        $att_date   = sanitize_text_field($_POST['att_date']);
        $status     = sanitize_key($_POST['status']);

        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT enrollment_id FROM {$p}enrollments WHERE student_id=%d AND course_id=%d LIMIT 1",
            $student_id, $course_id
        ));
        if (!$enrollment) wp_send_json_error('No enrollment found');

        // Upsert attendance
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT attendance_id FROM {$p}attendance WHERE enrollment_id=%d AND date=%s",
            $enrollment->enrollment_id, $att_date
        ));

        if ($existing) {
            $wpdb->update($p . 'attendance', array('status' => $status), array('attendance_id' => $existing));
        } else {
            $wpdb->insert($p . 'attendance', array(
                'enrollment_id' => $enrollment->enrollment_id,
                'date'          => $att_date,
                'status'        => $status,
                'time_in'       => date('H:i:s'),
                'marked_by'     => get_current_user_id(),
            ));
        }

        wp_send_json_success(array('status' => $status));
    }

    public function ajax_update_week_status() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error();

        global $wpdb;
        $week_id = intval($_POST['week_id']);
        $status  = sanitize_key($_POST['status']);
        $allowed = array('Pending', 'In Progress', 'Completed', 'Skipped');
        if (!in_array($status, $allowed)) wp_send_json_error('Invalid status');

        $wpdb->update($wpdb->prefix . 'mtti_scheme_of_work', array('status' => $status), array('week_id' => $week_id));
        wp_send_json_success(array('status' => $status));
    }

    public function ajax_get_session_students() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';
        $course_id = intval($_POST['course_id']);

        $weeks = $wpdb->get_results($wpdb->prepare(
            "SELECT week_id, week_number, topic FROM {$p}scheme_of_work WHERE course_id=%d AND status IN ('Pending','In Progress') ORDER BY week_number LIMIT 20",
            $course_id
        ));
        wp_send_json_success(array('weeks' => $weeks));
    }

    /* ══════════════════════════════════════════════════
     *  INTERACTIVE CONTENT CREATOR
     * ══════════════════════════════════════════════════ */
    private function render_content_creator($staff) {
        global $wpdb;
        $p     = $wpdb->prefix . 'mtti_';
        $nonce = wp_create_nonce('mtti_lecturer_nonce');

        // Get courses for this lecturer only
        if ($staff->staff_id > 0) {
            $courses = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT c.course_id, c.course_code, c.course_name
                 FROM {$p}courses c
                 INNER JOIN {$p}course_teachers ct ON ct.course_id = c.course_id
                 WHERE ct.staff_id = %d AND c.status = 'Active'
                 ORDER BY c.course_name",
                $staff->staff_id
            ));
        } else {
            $courses = $wpdb->get_results(
                "SELECT course_id, course_code, course_name FROM {$p}courses WHERE status='Active' ORDER BY course_name"
            );
        }

        echo '<h2 class="mtti-page-title">📁 Upload Interactive Content</h2>';
        echo '<p style="color:var(--text-muted);margin-bottom:20px;">Upload HTML interactive files — quizzes, exercises, simulations. Students can access them through their portal.</p>';
        ?>

        <div class="mtti-card">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" id="lc-upload-grid">

            <div>
              <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin:0 0 10px;">SELECT FILE</h3>
              <div id="lc-dropzone"
                ondragover="event.preventDefault();this.style.borderColor='var(--mtti-primary)'"
                ondragleave="this.style.borderColor='var(--border)'"
                ondrop="lcDrop(event)"
                onclick="document.getElementById('lc-fileinput').click()"
                style="border:2px dashed var(--border);border-radius:var(--radius-sm);padding:36px 20px;text-align:center;cursor:pointer;background:var(--bg-subtle);transition:border-color .2s;">
                <div style="font-size:48px;margin-bottom:8px;">📄</div>
                <p style="font-weight:600;color:var(--text-secondary);margin:0 0 4px;">Drop HTML file here</p>
                <p style="font-size:12px;color:var(--text-muted);margin:0;">.html only · max 5MB</p>
              </div>
              <input type="file" id="lc-fileinput" accept=".html,.htm" style="display:none;" onchange="lcPickFile(this.files[0])">

              <div id="lc-fileinfo" style="display:none;margin-top:10px;padding:10px 14px;background:var(--bg-subtle);border:1px solid var(--border);border-radius:var(--radius-sm);">
                <div style="display:flex;align-items:center;gap:8px;">
                  <span style="font-size:20px;">✅</span>
                  <div style="flex:1;">
                    <div id="lc-fname" style="font-weight:600;font-size:13px;color:var(--text-primary);"></div>
                    <div id="lc-fsize" style="font-size:11px;color:var(--text-muted);"></div>
                  </div>
                  <button type="button" onclick="lcClearFile()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:16px;">✕</button>
                </div>
                <div style="margin-top:10px;border:1px solid var(--border);border-radius:4px;overflow:hidden;">
                  <div style="background:var(--bg-subtle);padding:3px 8px;font-size:10px;color:var(--text-muted);border-bottom:1px solid var(--border);">PREVIEW</div>
                  <iframe id="lc-preview" style="width:100%;height:200px;border:none;" sandbox="allow-scripts"></iframe>
                </div>
              </div>
            </div>

            <div>
              <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin:0 0 10px;">DETAILS</h3>
              <div style="margin-bottom:12px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Title <span style="color:#EF9A9A">*</span></label>
                <input type="text" id="lc-title" placeholder="e.g. Networking — Quiz 1"
                  style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;box-sizing:border-box;">
              </div>
              <div style="margin-bottom:12px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Course <span style="color:#EF9A9A">*</span></label>
                <select id="lc-course" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;">
                  <option value="">— Select Course —</option>
                  <?php foreach ($courses as $c): ?>
                  <option value="<?php echo $c->course_id; ?>"><?php echo esc_html($c->course_code.' — '.$c->course_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="margin-bottom:16px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Description</label>
                <textarea id="lc-desc" rows="3"
                  style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;resize:vertical;box-sizing:border-box;"
                  placeholder="Brief description (optional)"></textarea>
              </div>

              <div id="lc-error" style="display:none;background:rgba(198,40,40,.1);border:1px solid #C62828;border-radius:var(--radius-sm);padding:10px;margin-bottom:12px;color:#EF9A9A;font-size:13px;"></div>

              <button type="button" id="lc-savebtn" onclick="lcSave()"
                style="width:100%;padding:13px;background:linear-gradient(135deg,var(--mtti-dark),var(--mtti-primary));border:none;border-radius:var(--radius-sm);color:white;font-size:15px;font-weight:700;cursor:pointer;">
                💾 Upload &amp; Save
              </button>
              <div id="lc-status" style="text-align:center;font-size:12px;color:var(--text-muted);min-height:18px;margin-top:6px;"></div>
            </div>
          </div>
        </div>

        <script>
        (function(){
        var lcUplNonce = '<?php echo esc_js($nonce); ?>';
        var lcUplAjax  = (typeof ajaxurl!=='undefined') ? ajaxurl : (typeof mttiLecturer!=='undefined' ? mttiLecturer.ajaxUrl : '/wp-admin/admin-ajax.php');
        var lcHTML = '';

        window.lcDrop = function(e) {
            e.preventDefault();
            document.getElementById('lc-dropzone').style.borderColor = 'var(--border)';
            lcPickFile(e.dataTransfer.files[0]);
        };
        window.lcPickFile = function(file) {
            if (!file) return;
            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'html' && ext !== 'htm') { lcErr('Only .html files supported.'); return; }
            if (file.size > 5*1024*1024) { lcErr('File too large. Max 5MB.'); return; }
            document.getElementById('lc-error').style.display = 'none';
            var r = new FileReader();
            r.onload = function(e) {
                lcHTML = e.target.result || '';
                document.getElementById('lc-fname').textContent = file.name;
                document.getElementById('lc-fsize').textContent = (file.size/1024).toFixed(1)+' KB · '+lcHTML.length.toLocaleString()+' chars';
                document.getElementById('lc-preview').srcdoc = lcHTML;
                document.getElementById('lc-fileinfo').style.display = 'block';
                if (!document.getElementById('lc-title').value) {
                    document.getElementById('lc-title').value = file.name.replace(/\.html?$/i,'').replace(/[-_]/g,' ');
                }
            };
            r.readAsText(file);
        };
        window.lcClearFile = function() {
            lcHTML = '';
            document.getElementById('lc-fileinput').value = '';
            document.getElementById('lc-fileinfo').style.display = 'none';
        };

        function lcErr(msg) {
            var el = document.getElementById('lc-error');
            el.textContent = '❌ ' + msg; el.style.display = 'block';
        }

        window.lcSave = function() {
            if (!lcHTML)  { lcErr('Select an HTML file first.'); return; }
            var title = document.getElementById('lc-title').value.trim();
            var cid   = document.getElementById('lc-course').value;
            if (!title) { lcErr('Enter a title.'); return; }
            if (!cid)   { lcErr('Select a course.'); return; }
            document.getElementById('lc-error').style.display = 'none';

            var btn = document.getElementById('lc-savebtn');
            btn.disabled = true; btn.textContent = '⏳ Saving...';
            document.getElementById('lc-status').textContent = 'Uploading...';

            var fd = new FormData();
            fd.append('action',    'mtti_lecturer_upload_html');
            fd.append('nonce',     lcUplNonce);
            fd.append('course_id', cid);
            fd.append('title',     title);
            fd.append('desc',      document.getElementById('lc-desc').value);
            fd.append('html_file', new Blob([lcHTML], {type:'text/html'}), 'interactive.html');

            jQuery.ajax({
                url: lcUplAjax, type: 'POST', data: fd,
                processData: false, contentType: false,
                success: function(res) {
                    btn.disabled = false; btn.textContent = '💾 Upload & Save';
                    if (res.success) {
                        var msg = res.data.pending
                            ? '⏳ Uploaded! Awaiting admin approval before students can see it.'
                            : '✅ Saved! ' + res.data.chars + ' chars. Students can now access it.';
                        document.getElementById('lc-status').textContent = msg;
                        document.getElementById('lc-status').style.color = 'var(--mtti-primary)';
                        window.lcClearFile();
                        document.getElementById('lc-title').value = '';
                        document.getElementById('lc-desc').value = '';
                        document.getElementById('lc-course').value = '';
                    } else {
                        lcErr(res.data || 'Upload failed.');
                        document.getElementById('lc-status').textContent = '';
                    }
                },
                error: function(xhr) {
                    btn.disabled = false; btn.textContent = '💾 Upload & Save';
                    document.getElementById('lc-status').textContent = '';
                    lcErr('Request failed (HTTP ' + xhr.status + ').' + (xhr.status===413?' File too large.':xhr.status===500?' Server error — check PHP log.':''));
                }
            });
        };
        })();
        </script>

        <?php
        // ── MY UPLOADS — status of all interactives this teacher uploaded ──
        $my_uploads = $wpdb->get_results($wpdb->prepare(
            "SELECT l.lesson_id, l.title, l.status, l.created_at, l.description,
                    c.course_code, c.course_name,
                    u.unit_name
             FROM {$wpdb->prefix}mtti_lessons l
             LEFT JOIN {$wpdb->prefix}mtti_courses c ON l.course_id = c.course_id
             LEFT JOIN {$wpdb->prefix}mtti_course_units u ON l.unit_id = u.unit_id
             WHERE l.content_type = 'html_interactive' AND l.created_by = %d
             ORDER BY l.created_at DESC",
            get_current_user_id()
        ));

        if ($my_uploads) :
            $pending  = count(array_filter($my_uploads, fn($r) => $r->status === 'Pending Review'));
            $approved = count(array_filter($my_uploads, fn($r) => $r->status === 'Published'));
            $rejected = count(array_filter($my_uploads, fn($r) => $r->status === 'Rejected'));
        ?>
        <div class="mtti-card" style="margin-top:24px;">
            <h3 style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin:0 0 14px;">
                MY UPLOADED INTERACTIVES
                <?php if ($pending): ?>
                <span style="background:#ff8f00;color:white;font-size:10px;padding:2px 8px;border-radius:20px;margin-left:6px;">
                    <?php echo $pending; ?> awaiting approval
                </span>
                <?php endif; ?>
            </h3>

            <div style="display:flex;gap:14px;margin-bottom:14px;flex-wrap:wrap;">
                <?php foreach ([
                    ['📤', count($my_uploads), 'Total Uploaded', 'var(--text-secondary)'],
                    ['⏳', $pending,  'Pending Review', '#ff8f00'],
                    ['✅', $approved, 'Approved',       '#2e7d32'],
                    ['❌', $rejected, 'Rejected',       '#d32f2f'],
                ] as $s): ?>
                <div style="background:var(--bg-subtle);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 16px;min-width:100px;">
                    <div style="font-size:18px;font-weight:700;color:<?php echo $s[3]; ?>"><?php echo $s[0].' '.$s[1]; ?></div>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?php echo $s[2]; ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border);">
                        <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:11px;text-transform:uppercase;">Title</th>
                        <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:11px;text-transform:uppercase;">Course</th>
                        <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:11px;text-transform:uppercase;">Uploaded</th>
                        <th style="text-align:left;padding:6px 8px;color:var(--text-muted);font-size:11px;text-transform:uppercase;">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($my_uploads as $u):
                    $sc = match($u->status) {
                        'Published'      => ['#2e7d32', '✅ Approved — Live'],
                        'Pending Review' => ['#ff8f00', '⏳ Awaiting Approval'],
                        'Rejected'       => ['#d32f2f', '❌ Rejected'],
                        default          => ['#9e9e9e', $u->status],
                    };
                ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:8px;font-weight:600;">⚡ <?php echo esc_html($u->title); ?>
                        <?php if ($u->description): ?>
                        <div style="font-size:11px;color:var(--text-muted);font-weight:400;"><?php echo esc_html(substr($u->description,0,60)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px;font-size:12px;">
                        <strong><?php echo esc_html($u->course_code); ?></strong><br>
                        <span style="color:var(--text-muted);"><?php echo esc_html($u->course_name); ?></span>
                    </td>
                    <td style="padding:8px;font-size:11px;color:var(--text-muted);">
                        <?php echo date('d M Y', strtotime($u->created_at)); ?>
                    </td>
                    <td style="padding:8px;">
                        <span style="font-size:12px;font-weight:700;color:<?php echo $sc[0]; ?>;">
                            <?php echo $sc[1]; ?>
                        </span>
                        <?php if ($u->status === 'Pending Review'): ?>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Admin will review shortly</div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── LESSON FILE UPLOAD (PDF, Video URL, Document) ── -->
        <div class="mtti-card" style="margin-top:24px;">
          <h3 style="font-size:14px;font-weight:700;margin:0 0 4px;">📎 Upload Lesson Materials</h3>
          <p style="font-size:12px;color:var(--text-muted);margin:0 0 16px;">Share PDFs, documents, or video links with students through the Lessons tab.</p>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <!-- Left — file or URL -->
            <div>
              <!-- Toggle: File vs URL -->
              <div style="display:flex;gap:0;margin-bottom:12px;border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;">
                <button type="button" id="lm-tab-file" onclick="lcMaterialTab('file')"
                  style="flex:1;padding:8px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--mtti-primary);color:#fff;">
                  📄 Upload File
                </button>
                <button type="button" id="lm-tab-url" onclick="lcMaterialTab('url')"
                  style="flex:1;padding:8px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--bg-subtle);color:var(--text-secondary);">
                  🔗 Video / URL
                </button>
              </div>

              <div id="lm-file-panel">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Choose file (PDF, DOCX, PPTX, MP4 — max 20MB)</label>
                <input type="file" id="lm-file" accept=".pdf,.doc,.docx,.ppt,.pptx,.mp4,.mp3,.zip"
                  style="width:100%;padding:7px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:12px;box-sizing:border-box;"
                  onchange="lcMaterialFileChange(this)">
                <div id="lm-file-info" style="display:none;font-size:11px;color:var(--text-muted);margin-top:4px;"></div>
              </div>

              <div id="lm-url-panel" style="display:none;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">YouTube / Vimeo / Direct URL</label>
                <input type="text" id="lm-url" placeholder="https://youtube.com/watch?v=..."
                  style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;box-sizing:border-box;">
              </div>

              <div id="lm-prog-wrap" style="display:none;margin-top:10px;">
                <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
                  <div id="lm-prog-bar" style="height:100%;width:0%;background:var(--mtti-primary);transition:width .3s;"></div>
                </div>
                <div id="lm-prog-text" style="font-size:11px;color:var(--text-muted);margin-top:4px;"></div>
              </div>
            </div>

            <!-- Right — metadata -->
            <div>
              <div style="margin-bottom:10px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Title <span style="color:#EF9A9A">*</span></label>
                <input type="text" id="lm-title" placeholder="e.g. Week 3 Notes — Networking"
                  style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;box-sizing:border-box;">
              </div>
              <div style="margin-bottom:10px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Course <span style="color:#EF9A9A">*</span></label>
                <select id="lm-course" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;">
                  <option value="">— Select Course —</option>
                  <?php foreach ($courses as $c): ?>
                  <option value="<?php echo $c->course_id; ?>"><?php echo esc_html($c->course_code . ' — ' . $c->course_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="margin-bottom:10px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Content Type</label>
                <select id="lm-type" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;">
                  <option value="pdf">📕 PDF</option>
                  <option value="document">📘 Document (Word/PPT)</option>
                  <option value="video">🎬 Video</option>
                  <option value="audio">🎵 Audio</option>
                  <option value="file">📄 Other File</option>
                </select>
              </div>
              <div style="margin-bottom:12px;">
                <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Description</label>
                <textarea id="lm-desc" rows="2"
                  style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;resize:vertical;box-sizing:border-box;"
                  placeholder="Brief description (optional)"></textarea>
              </div>
              <div id="lm-error" style="display:none;background:rgba(198,40,40,.1);border:1px solid #C62828;border-radius:var(--radius-sm);padding:8px 10px;margin-bottom:10px;color:#EF9A9A;font-size:12px;"></div>
              <button type="button" id="lm-savebtn" onclick="lcMaterialSave()"
                style="width:100%;padding:12px;background:linear-gradient(135deg,#1565C0,#1976D2);border:none;border-radius:var(--radius-sm);color:white;font-size:14px;font-weight:700;cursor:pointer;">
                📤 Upload Material
              </button>
              <div id="lm-status" style="text-align:center;font-size:12px;color:var(--text-muted);min-height:18px;margin-top:6px;"></div>
            </div>
          </div>
        </div>

        <script>
        (function(){
          var lcMatNonce = '<?php echo esc_js($nonce); ?>';
          var lcMatAjax  = (typeof ajaxurl!=='undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';
          var lcMatMode  = 'file';

          window.lcMaterialTab = function(tab) {
            lcMatMode = tab;
            var isFile = tab === 'file';
            document.getElementById('lm-file-panel').style.display = isFile ? '' : 'none';
            document.getElementById('lm-url-panel').style.display  = isFile ? 'none' : '';
            document.getElementById('lm-tab-file').style.background = isFile ? 'var(--mtti-primary)' : 'var(--bg-subtle)';
            document.getElementById('lm-tab-file').style.color = isFile ? '#fff' : 'var(--text-secondary)';
            document.getElementById('lm-tab-url').style.background = !isFile ? 'var(--mtti-primary)' : 'var(--bg-subtle)';
            document.getElementById('lm-tab-url').style.color = !isFile ? '#fff' : 'var(--text-secondary)';
          };

          window.lcMaterialFileChange = function(inp) {
            if (!inp.files[0]) return;
            var f = inp.files[0];
            var ext = f.name.split('.').pop().toLowerCase();
            var typeMap = { pdf:'pdf', doc:'document', docx:'document', ppt:'document', pptx:'document', mp4:'video', mp3:'audio', zip:'file' };
            if (typeMap[ext]) document.getElementById('lm-type').value = typeMap[ext];
            document.getElementById('lm-file-info').style.display = '';
            document.getElementById('lm-file-info').textContent = f.name + ' · ' + (f.size/1024/1024).toFixed(2) + ' MB';
            if (!document.getElementById('lm-title').value) {
              document.getElementById('lm-title').value = f.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g,' ');
            }
          };

          window.lcMaterialSave = function() {
            var title   = document.getElementById('lm-title').value.trim();
            var courseId = document.getElementById('lm-course').value;
            var type    = document.getElementById('lm-type').value;
            var desc    = document.getElementById('lm-desc').value.trim();
            var errDiv  = document.getElementById('lm-error');
            var status  = document.getElementById('lm-status');

            errDiv.style.display = 'none';
            if (!title)    { errDiv.style.display=''; errDiv.textContent='Title is required.'; return; }
            if (!courseId) { errDiv.style.display=''; errDiv.textContent='Please select a course.'; return; }

            var fd = new FormData();
            fd.append('action',   'mtti_lecturer_upload_material');
            fd.append('nonce',    lcMatNonce);
            fd.append('title',    title);
            fd.append('course_id', courseId);
            fd.append('content_type', type);
            fd.append('description', desc);

            if (lcMatMode === 'file') {
              var fileInp = document.getElementById('lm-file');
              if (!fileInp.files[0]) { errDiv.style.display=''; errDiv.textContent='Please select a file.'; return; }
              if (fileInp.files[0].size > 20*1024*1024) { errDiv.style.display=''; errDiv.textContent='File too large. Max 20MB.'; return; }
              fd.append('material_file', fileInp.files[0]);
            } else {
              var url = document.getElementById('lm-url').value.trim();
              if (!url) { errDiv.style.display=''; errDiv.textContent='Please enter a URL.'; return; }
              fd.append('content_url', url);
            }

            document.getElementById('lm-savebtn').disabled = true;
            document.getElementById('lm-prog-wrap').style.display = '';
            status.textContent = 'Uploading…';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', lcMatAjax);
            xhr.upload.onprogress = function(e) {
              if (e.lengthComputable) {
                var pct = Math.round(e.loaded/e.total*100);
                document.getElementById('lm-prog-bar').style.width = pct + '%';
                document.getElementById('lm-prog-text').textContent = pct + '% uploaded';
              }
            };
            xhr.onload = function() {
              document.getElementById('lm-savebtn').disabled = false;
              try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                  status.style.color = 'var(--mtti-primary)';
                  status.textContent = '✅ Material saved!';
                  document.getElementById('lm-title').value = '';
                  document.getElementById('lm-desc').value  = '';
                  document.getElementById('lm-file').value  = '';
                  document.getElementById('lm-url').value   = '';
                  document.getElementById('lm-file-info').style.display = 'none';
                  document.getElementById('lm-prog-wrap').style.display = 'none';
                } else {
                  errDiv.style.display = '';
                  errDiv.textContent   = res.data || 'Upload failed.';
                  status.textContent   = '';
                }
              } catch(e) {
                errDiv.style.display = '';
                errDiv.textContent   = 'Server error. Please try again.';
                status.textContent   = '';
              }
            };
            xhr.send(fd);
          };
        })();
        </script>

        <?php
    } // end render_content_creator



    public function ajax_upload_html() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('Not logged in');

        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        // Verify user is staff or admin
        $is_admin = current_user_can('manage_options') || current_user_can('manage_mtti');
        if (!$is_admin) {
            $staff = $wpdb->get_row($wpdb->prepare(
                "SELECT staff_id FROM {$p}staff WHERE user_id=%d AND status='Active'",
                get_current_user_id()
            ));
            if (!$staff) wp_send_json_error('Staff access only');
        }

        $cid   = intval($_POST['course_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $desc  = sanitize_textarea_field($_POST['desc'] ?? '');

        if (!$cid)   wp_send_json_error('Course required');
        if (!$title) wp_send_json_error('Title required');

        // Read HTML from uploaded file
        $html = '';
        if (!empty($_FILES['html_file']['tmp_name']) && $_FILES['html_file']['error'] === UPLOAD_ERR_OK) {
            $html = file_get_contents($_FILES['html_file']['tmp_name']);
        }
        if (empty($html) && !empty($_POST['html'])) {
            $html = wp_unslash($_POST['html']);
        }
        if (strlen($html) < 50) wp_send_json_error('HTML file is empty or too short');
        if (strpos($html,'<html')===false && strpos($html,'<!DOCTYPE')===false && strpos($html,'<body')===false) {
            wp_send_json_error('Not a valid HTML file');
        }

        // Check for duplicate, update if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT lesson_id FROM {$p}lessons WHERE course_id=%d AND title=%s AND content_type='html_interactive' LIMIT 1",
            $cid, $title
        ));
        if ($existing) {
            $wpdb->update($wpdb->prefix.'mtti_lessons',
                array('content'=>$html,'description'=>$desc),
                array('lesson_id'=>$existing)
            );
            wp_send_json_success(array('id'=>$existing, 'chars'=>number_format(strlen($html)), 'updated'=>true));
        }

        $wpdb->insert($wpdb->prefix.'mtti_lessons', array(
            'course_id'    => $cid,
            'title'        => $title,
            'description'  => $desc,
            'content'      => $html,
            'content_type' => 'html_interactive',
            'status'       => 'Pending Review',
            'created_by'   => get_current_user_id(),
        ));

        if ($wpdb->insert_id) {
            wp_send_json_success(array('id'=>$wpdb->insert_id, 'chars'=>number_format(strlen($html)), 'pending'=>true));
        } else {
            wp_send_json_error('Database error: '.$wpdb->last_error);
        }
    }

    /* ══════════════════════════════════════════════════
     *  SESSION CODE — GENERATE & REVOKE
     * ══════════════════════════════════════════════════ */

    /**
     * Generate a 6-digit attendance session code (valid 20 min).
     * Only the lecturer who teaches the course may generate one.
     */
    public function ajax_generate_att_code() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        global $wpdb;
        $p         = $wpdb->prefix . 'mtti_';
        $course_id = intval($_POST['course_id'] ?? 0);
        $att_date  = sanitize_text_field($_POST['att_date'] ?? date('Y-m-d'));

        // Verify current user is a staff member assigned to this course
        $user_id = get_current_user_id();
        $staff   = $wpdb->get_row($wpdb->prepare(
            "SELECT s.staff_id FROM {$p}staff s
             INNER JOIN {$p}course_teachers ct ON ct.staff_id = s.staff_id
             WHERE s.user_id = %d AND ct.course_id = %d",
            $user_id, $course_id
        ));
        if (!$staff) {
            wp_send_json_error('Unauthorised.');
        }

        // Don't overwrite an existing live code — teacher must revoke first
        $code_key = 'mtti_att_code_' . $course_id . '_' . $att_date;
        if (get_transient($code_key)) {
            wp_send_json_error('A code is already active. Revoke it first.');
        }

        // Generate random 6-digit numeric code
        $code = str_pad(wp_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        set_transient($code_key, $code, 20 * MINUTE_IN_SECONDS); // 20 min TTL

        wp_send_json_success(array('code' => $code));
    }

    /**
     * Revoke (delete) the active session code for a course/date.
     */
    /* ══════════════════════════════════════════════════
     *  QUIZ BUILDER — build fill-in-blank / short-answer quizzes
     *  Generates MTTI-template HTML saved as html_interactive lesson
     * ══════════════════════════════════════════════════ */
    private function render_quiz_generator($staff) {
        global $wpdb;
        $p     = $wpdb->prefix . 'mtti_';
        $nonce = wp_create_nonce('mtti_lecturer_nonce');

        if ($staff->staff_id > 0) {
            $courses = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT c.course_id, c.course_code, c.course_name
                 FROM {$p}courses c
                 INNER JOIN {$p}course_teachers ct ON ct.course_id = c.course_id
                 WHERE ct.staff_id = %d AND c.status = 'Active'
                 ORDER BY c.course_name",
                $staff->staff_id
            ));
        } else {
            $courses = $wpdb->get_results(
                "SELECT course_id, course_code, course_name FROM {$p}courses WHERE status='Active' ORDER BY course_name"
            );
        }

        // My quizzes
        $my_quizzes = $wpdb->get_results($wpdb->prepare(
            "SELECT l.lesson_id, l.title, l.status, l.created_at, c.course_code
             FROM {$p}lessons l
             LEFT JOIN {$p}courses c ON l.course_id = c.course_id
             WHERE l.created_by = %d AND l.content_type = 'html_interactive' AND l.title LIKE '🧠 Quiz:%'
             ORDER BY l.created_at DESC LIMIT 30",
            get_current_user_id()
        ));

        echo '<h2 class="mtti-page-title">🧠 Quiz Builder</h2>';
        echo '<p style="color:var(--text-muted);margin-bottom:20px;">Build fill-in-blank and short-answer quizzes. They appear as Practice Quizzes in the student portal.</p>';
        ?>

        <div class="mtti-card">
          <!-- Step 1 — Quiz metadata -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
            <div>
              <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Quiz Title <span style="color:#EF9A9A">*</span></label>
              <input type="text" id="qb-title" placeholder="e.g. Chapter 3 — Networking Basics"
                style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Course <span style="color:#EF9A9A">*</span></label>
              <select id="qb-course" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;">
                <option value="">— Select Course —</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c->course_id; ?>"><?php echo esc_html($c->course_code . ' — ' . $c->course_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Instructions / intro text -->
          <div style="margin-bottom:20px;">
            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Instructions (optional)</label>
            <textarea id="qb-instructions" rows="2" placeholder="e.g. Fill in each blank with the correct term. Read each question carefully."
              style="width:100%;padding:9px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-subtle);color:var(--text-primary);font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>
          </div>

          <!-- Question builder -->
          <div style="margin-bottom:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
              <h3 style="font-size:13px;font-weight:700;margin:0;">Questions</h3>
              <div style="display:flex;gap:8px;">
                <button type="button" onclick="qbAddFIB()" style="padding:6px 14px;font-size:12px;font-weight:600;background:var(--mtti-primary);color:#fff;border:none;border-radius:var(--radius-sm);cursor:pointer;">+ Fill-in-Blank</button>
                <button type="button" onclick="qbAddSA()"  style="padding:6px 14px;font-size:12px;font-weight:600;background:#1565C0;color:#fff;border:none;border-radius:var(--radius-sm);cursor:pointer;">+ Short Answer</button>
              </div>
            </div>
            <div id="qb-questions" style="display:flex;flex-direction:column;gap:12px;min-height:60px;">
              <div id="qb-empty" style="text-align:center;padding:30px;color:var(--text-muted);border:2px dashed var(--border);border-radius:var(--radius-sm);font-size:13px;">
                Click a button above to add your first question
              </div>
            </div>
          </div>

          <div id="qb-error" style="display:none;background:rgba(198,40,40,.1);border:1px solid #C62828;border-radius:var(--radius-sm);padding:10px;margin-bottom:12px;color:#EF9A9A;font-size:13px;"></div>

          <div style="display:flex;gap:10px;align-items:center;">
            <button type="button" onclick="qbPreview()" style="padding:12px 24px;font-size:13px;font-weight:700;background:var(--bg-subtle);color:var(--text-primary);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;">👁 Preview</button>
            <button type="button" id="qb-savebtn" onclick="qbSave()" style="flex:1;padding:12px;font-size:15px;font-weight:700;background:linear-gradient(135deg,var(--mtti-dark),var(--mtti-primary));color:#fff;border:none;border-radius:var(--radius-sm);cursor:pointer;">💾 Save Quiz to Portal</button>
            <div id="qb-status" style="font-size:12px;color:var(--text-muted);min-width:120px;text-align:center;"></div>
          </div>
        </div>

        <!-- Preview modal -->
        <div id="qb-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:10px;width:90%;max-width:800px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
            <div style="background:var(--mtti-primary);color:#fff;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;">
              <span style="font-weight:700;">Quiz Preview</span>
              <button onclick="document.getElementById('qb-modal').style.display='none'" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">✕</button>
            </div>
            <iframe id="qb-preview-frame" style="flex:1;border:none;width:100%;min-height:500px;" sandbox="allow-scripts allow-forms"></iframe>
          </div>
        </div>

        <?php if (!empty($my_quizzes)): ?>
        <div class="mtti-card" style="margin-top:20px;">
          <h3 style="font-size:14px;font-weight:700;margin:0 0 12px;">My Quizzes (<?php echo count($my_quizzes); ?>)</h3>
          <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
              <tr style="background:var(--bg-subtle);">
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--text-muted);">Title</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--text-muted);">Course</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--text-muted);">Status</th>
                <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--text-muted);">Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($my_quizzes as $q): ?>
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:8px 10px;color:var(--text-primary);"><?php echo esc_html(preg_replace('/^🧠 Quiz:\s*/u','',$q->title)); ?></td>
                <td style="padding:8px 10px;color:var(--text-secondary);"><?php echo esc_html($q->course_code); ?></td>
                <td style="padding:8px 10px;">
                  <?php
                  $sc = ['Published'=>'#2E7D32','Pending Review'=>'#E65100','Rejected'=>'#C62828'];
                  $bg = $sc[$q->status] ?? '#666';
                  echo '<span style="background:'.$bg.';color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:600;">' . esc_html($q->status) . '</span>';
                  ?>
                </td>
                <td style="padding:8px 10px;color:var(--text-muted);"><?php echo date('d M Y', strtotime($q->created_at)); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <script>
        (function(){
          var qbNonce = '<?php echo esc_js($nonce); ?>';
          var qbAjax  = (typeof ajaxurl!=='undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';
          var qbQs    = []; // array of question objects
          var qbIdx   = 0;

          function qbRender() {
            var container = document.getElementById('qb-questions');
            document.getElementById('qb-empty').style.display = qbQs.length ? 'none' : '';
            // Remove existing question divs
            container.querySelectorAll('.qb-q').forEach(function(el){ el.remove(); });
            qbQs.forEach(function(q, i) {
              var div = document.createElement('div');
              div.className = 'qb-q';
              div.style.cssText = 'border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;background:var(--bg-subtle);position:relative;';
              var typeBadge = q.type === 'fib'
                ? '<span style="background:#2E7D32;color:#fff;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:700;">FILL-IN-BLANK</span>'
                : '<span style="background:#1565C0;color:#fff;font-size:10px;padding:2px 7px;border-radius:10px;font-weight:700;">SHORT ANSWER</span>';
              var hint = q.type === 'fib'
                ? '<small style="color:var(--text-muted);font-size:11px;">Use ___ for blank. Answer = the word(s) that fill the blank.</small>'
                : '<small style="color:var(--text-muted);font-size:11px;">Student writes a free-text answer. Enter keywords that must appear.</small>';
              div.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">'
                + typeBadge
                + '<span style="font-size:12px;font-weight:600;color:var(--text-muted);">Q' + (i+1) + '</span>'
                + '<button type="button" onclick="qbRemove('+i+')" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#C62828;font-size:16px;">🗑</button>'
                + '</div>'
                + '<div style="margin-bottom:6px;">'
                + '<input type="text" value="' + qbEsc(q.question) + '" placeholder="Question text' + (q.type==='fib'?' (use ___ for blank)':'') + '" oninput="qbQs['+i+'].question=this.value" '
                + 'style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;background:#fff;font-size:13px;box-sizing:border-box;">'
                + '</div>'
                + hint
                + '<div style="margin-top:8px;">'
                + '<input type="text" value="' + qbEsc(q.answer) + '" placeholder="' + (q.type==='fib'?'Correct answer (e.g. photosynthesis)':'Keywords (comma-separated, e.g. mitosis,cell division)') + '" oninput="qbQs['+i+'].answer=this.value" '
                + 'style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;background:#fff;font-size:13px;box-sizing:border-box;">'
                + '</div>'
                + '<div style="margin-top:8px;">'
                + '<input type="text" value="' + qbEsc(q.model) + '" placeholder="Model answer (shown after submission)" oninput="qbQs['+i+'].model=this.value" '
                + 'style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;background:#fff;font-size:13px;box-sizing:border-box;color:#555;">'
                + '</div>';
              container.appendChild(div);
            });
          }

          function qbEsc(s) { return (s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

          window.qbAddFIB = function() {
            qbQs.push({ type:'fib', question:'', answer:'', model:'' });
            qbRender();
          };
          window.qbAddSA = function() {
            qbQs.push({ type:'sa', question:'', answer:'', model:'' });
            qbRender();
          };
          window.qbRemove = function(i) {
            qbQs.splice(i,1);
            qbRender();
          };

          function qbBuildHTML(title, instructions, questions) {
            var fibQs = questions.filter(function(q){ return q.type==='fib'; });
            var saQs  = questions.filter(function(q){ return q.type==='sa'; });
            var total = questions.length;

            var fibSection = '';
            if (fibQs.length) {
              fibSection = '<div class="section"><h2>Part A — Fill in the Blanks</h2>';
              fibQs.forEach(function(q,i){
                var qtext = q.question.replace(/___/g, '<input class="fib-inp" data-answer="'+qbEsc(q.answer.trim().toLowerCase())+'" data-model="'+qbEsc(q.model)+'" type="text" placeholder="...">');
                fibSection += '<div class="q-block"><p class="q-num">Q'+(i+1)+'.</p><p class="q-text">'+qtext+'</p><div class="feedback" id="fb-fib-'+i+'"></div></div>';
              });
              fibSection += '</div>';
            }

            var saSection = '';
            if (saQs.length) {
              saSection = '<div class="section"><h2>Part B — Short Answer</h2>';
              saQs.forEach(function(q,i){
                var kws = q.answer.split(',').map(function(k){ return k.trim().toLowerCase(); }).filter(Boolean);
                saSection += '<div class="q-block"><p class="q-num">Q'+(fibQs.length+i+1)+'.</p>'
                  + '<p class="q-text">'+q.question+'</p>'
                  + '<textarea class="sa-inp" data-keywords=\''+JSON.stringify(kws)+'\' data-model="'+qbEsc(q.model)+'" rows="3" placeholder="Type your answer here..."></textarea>'
                  + '<div class="feedback" id="fb-sa-'+i+'"></div></div>';
              });
              saSection += '</div>';
            }

            return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
              + '<title>'+title+'</title>'
              + '<style>'
              + ':root{--pri:#0a5e2a;--acc:#f5a623;--ok:#2e7d32;--no:#c62828;}'
              + '*{box-sizing:border-box;margin:0;padding:0;}'
              + 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f6f4;color:#1a1a1a;}'
              + '.topbar{background:var(--pri);color:#fff;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10;}'
              + '.topbar h1{font-size:16px;font-weight:700;}'
              + '.score-badge{background:#fff;color:var(--pri);font-weight:700;font-size:13px;padding:4px 12px;border-radius:20px;}'
              + '.container{max-width:800px;margin:0 auto;padding:20px;}'
              + '.instructions{background:#fff;border-radius:8px;padding:16px 20px;margin-bottom:20px;border-left:4px solid var(--acc);font-size:14px;color:#555;}'
              + '.section{background:#fff;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);}'
              + '.section h2{font-size:14px;font-weight:700;color:var(--pri);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #e8f5e9;}'
              + '.q-block{margin-bottom:18px;}'
              + '.q-num{font-weight:700;color:var(--pri);font-size:13px;margin-bottom:4px;}'
              + '.q-text{font-size:14px;line-height:1.7;}'
              + '.fib-inp{border:none;border-bottom:2px solid var(--pri);background:transparent;font-size:14px;padding:2px 6px;min-width:120px;outline:none;color:#1a1a1a;}'
              + 'textarea.sa-inp{width:100%;margin-top:8px;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px;resize:vertical;font-family:inherit;}'
              + '.feedback{font-size:12px;margin-top:6px;padding:6px 10px;border-radius:4px;display:none;}'
              + '.feedback.ok{background:#E8F5E9;color:var(--ok);display:block;}'
              + '.feedback.no{background:#FFEBEE;color:var(--no);display:block;}'
              + '.submit-btn{display:block;width:100%;padding:14px;background:var(--pri);color:#fff;font-size:16px;font-weight:700;border:none;border-radius:8px;cursor:pointer;margin-top:10px;}'
              + '.result-card{background:#fff;border-radius:10px;padding:30px;text-align:center;margin-top:20px;box-shadow:0 2px 8px rgba(0,0,0,.1);}'
              + '.result-score{font-size:48px;font-weight:700;color:var(--pri);}'
              + '.result-grade{font-size:20px;font-weight:700;margin:8px 0;}'
              + '.retry-btn{margin-top:16px;padding:10px 30px;background:var(--acc);color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;}'
              + '</style>'
              + '</head><body>'
              + '<div class="topbar"><h1>🧠 '+title+'</h1><span class="score-badge" id="score-display">'+total+' Questions</span></div>'
              + '<div class="container">'
              + (instructions ? '<div class="instructions">📌 '+instructions+'</div>' : '')
              + fibSection + saSection
              + '<button class="submit-btn" onclick="submitQuiz()">✅ Submit Quiz</button>'
              + '<div id="result-area"></div>'
              + '</div>'
              + '<script>'
              + 'function submitQuiz(){'
              + 'var score=0,total='+total+';'
              + 'document.querySelectorAll(".fib-inp").forEach(function(inp,i){'
              + 'var ans=inp.value.trim().toLowerCase();'
              + 'var correct=inp.dataset.answer.trim().toLowerCase();'
              + 'var fb=document.getElementById("fb-fib-"+i);'
              + 'if(ans===correct){score++;inp.style.borderBottomColor="#2e7d32";fb.className="feedback ok";fb.textContent="✓ Correct!";}'
              + 'else{inp.style.borderBottomColor="#c62828";fb.className="feedback no";fb.textContent="✗ Correct: "+inp.dataset.model+" ("+inp.dataset.answer+")";}'
              + '});'
              + 'document.querySelectorAll(".sa-inp").forEach(function(ta,i){'
              + 'var ans=ta.value.trim().toLowerCase();'
              + 'var kws=JSON.parse(ta.dataset.keywords||"[]");'
              + 'var found=kws.filter(function(k){return ans.indexOf(k)>-1;});'
              + 'var pct=kws.length?found.length/kws.length:0;'
              + 'if(pct>=0.5){score++;ta.style.borderColor="#2e7d32";}'
              + 'else{ta.style.borderColor="#c62828";}'
              + 'var fb=document.getElementById("fb-sa-"+i);'
              + 'fb.className="feedback "+(pct>=0.5?"ok":"no");'
              + 'fb.textContent=(pct>=0.5?"✓ Good answer!":"✗ Model: "+(ta.dataset.model||kws.join(", ")));'
              + '});'
              + 'var pct=total>0?Math.round(score/total*100):0;'
              + 'var grade=pct>=80?"DISTINCTION":pct>=60?"CREDIT":pct>=50?"PASS":"REFER";'
              + 'document.getElementById("score-display").textContent=score+"/"+total;'
              + 'document.getElementById("result-area").innerHTML=\'<div class="result-card"><div class="result-score">\'+pct+\'%</div><div class="result-grade">\'+(pct>=50?"🎉 "+grade:"📚 "+grade)+\'</div><p>\'+score+" of "+total+" correct</p>"'
              + '+"<button class=\\"retry-btn\\" onclick=\\"location.reload()\\">🔁 Retry</button></div>";'
              + 'document.getElementById("result-area").scrollIntoView({behavior:"smooth"});'
              + 'try{if(window.parent&&window.parent.postMessage){window.parent.postMessage({type:"mtti_quiz_score",score:score,total:total,percent:pct},"*");}}'
              + 'catch(e){}'
              + '}'
              + '<\/script>'
              + '</body></html>';
          }

          window.qbPreview = function() {
            var title = document.getElementById('qb-title').value || 'Preview';
            var instr = document.getElementById('qb-instructions').value;
            var html  = qbBuildHTML(title, instr, qbQs);
            var frame = document.getElementById('qb-preview-frame');
            frame.srcdoc = html;
            document.getElementById('qb-modal').style.display = 'flex';
          };

          window.qbSave = function() {
            var title    = document.getElementById('qb-title').value.trim();
            var courseId = document.getElementById('qb-course').value;
            var instr    = document.getElementById('qb-instructions').value.trim();
            var errDiv   = document.getElementById('qb-error');
            var status   = document.getElementById('qb-status');

            errDiv.style.display = 'none';
            if (!title)    { errDiv.style.display=''; errDiv.textContent='Quiz title is required.'; return; }
            if (!courseId) { errDiv.style.display=''; errDiv.textContent='Please select a course.'; return; }
            if (!qbQs.length) { errDiv.style.display=''; errDiv.textContent='Add at least one question.'; return; }
            var incomplete = qbQs.find(function(q){ return !q.question.trim() || !q.answer.trim(); });
            if (incomplete) { errDiv.style.display=''; errDiv.textContent='All questions need question text and an answer/keywords.'; return; }

            var html = qbBuildHTML(title, instr, qbQs);

            var btn = document.getElementById('qb-savebtn');
            btn.disabled = true;
            status.textContent = 'Saving…';

            var fd = new FormData();
            fd.append('action',    'mtti_lecturer_save_quiz');
            fd.append('nonce',     qbNonce);
            fd.append('title',     title);
            fd.append('course_id', courseId);
            fd.append('html',      html);
            fd.append('questions', JSON.stringify(qbQs));

            fetch(qbAjax, { method:'POST', body: fd, credentials:'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(res) {
                btn.disabled = false;
                if (res.success) {
                  status.style.color = 'var(--mtti-primary)';
                  status.textContent = '✅ Saved! Pending review.';
                  qbQs = [];
                  qbRender();
                  document.getElementById('qb-title').value = '';
                  document.getElementById('qb-instructions').value = '';
                  setTimeout(function(){ location.reload(); }, 1500);
                } else {
                  errDiv.style.display = '';
                  errDiv.textContent   = res.data || 'Save failed.';
                  status.textContent   = '';
                }
              })
              .catch(function() {
                btn.disabled = false;
                errDiv.style.display = '';
                errDiv.textContent   = 'Network error. Please try again.';
                status.textContent   = '';
              });
          };
        })();
        </script>
        <?php
    }

    /**
     * AJAX — save quiz built with quiz builder
     */
    public function ajax_save_quiz() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('Not logged in');

        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $title     = sanitize_text_field($_POST['title']     ?? '');
        $course_id = intval($_POST['course_id']              ?? 0);
        $html      = $_POST['html']                          ?? '';
        $questions = json_decode(stripslashes($_POST['questions'] ?? '[]'), true);

        if (!$title || !$course_id || !$html) wp_send_json_error('Missing required fields');
        if (empty($questions) || !is_array($questions)) wp_send_json_error('No questions provided');

        $user_id  = get_current_user_id();
        $staff_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT staff_id FROM {$p}staff WHERE user_id=%d LIMIT 1", $user_id
        ));

        // Verify access
        if ($staff_id > 0 && !current_user_can('manage_options')) {
            $allowed = $wpdb->get_var($wpdb->prepare(
                "SELECT course_id FROM {$p}course_teachers WHERE staff_id=%d AND course_id=%d LIMIT 1",
                $staff_id, $course_id
            ));
            if (!$allowed) wp_send_json_error('Not assigned to this course');
        }

        $full_title = '🧠 Quiz: ' . $title;

        // Check for existing quiz with same title in same course (update instead of duplicate)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT lesson_id FROM {$p}lessons WHERE course_id=%d AND title=%s AND content_type='html_interactive' LIMIT 1",
            $course_id, $full_title
        ));

        if ($existing) {
            $wpdb->update(
                $p . 'lessons',
                ['content' => $html, 'status' => 'Pending Review', 'updated_at' => current_time('mysql')],
                ['lesson_id' => $existing],
                ['%s','%s','%s'],
                ['%d']
            );
            wp_send_json_success(['lesson_id' => $existing, 'updated' => true]);
        }

        $wpdb->insert(
            $p . 'lessons',
            [
                'course_id'    => $course_id,
                'title'        => $full_title,
                'description'  => count($questions) . ' questions — ' . count(array_filter($questions, fn($q) => $q['type']==='fib')) . ' fill-in-blank, ' . count(array_filter($questions, fn($q) => $q['type']==='sa')) . ' short answer',
                'content_type' => 'html_interactive',
                'content'      => $html,
                'status'       => 'Pending Review',
                'created_by'   => $user_id,
                'created_at'   => current_time('mysql'),
            ],
            ['%d','%s','%s','%s','%s','%s','%d','%s']
        );

        if (!$wpdb->insert_id) wp_send_json_error('DB error: ' . $wpdb->last_error);
        wp_send_json_success(['lesson_id' => $wpdb->insert_id]);
    }

    public function ajax_revoke_att_code() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        $course_id = intval($_POST['course_id'] ?? 0);
        $att_date  = sanitize_text_field($_POST['att_date'] ?? date('Y-m-d'));
        delete_transient('mtti_att_code_' . $course_id . '_' . $att_date);
        wp_send_json_success();
    }

    /**
     * AJAX — upload a lesson material (PDF, doc, video URL, etc.)
     */
    public function ajax_upload_material() {
        check_ajax_referer('mtti_lecturer_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('Not logged in');

        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $title        = sanitize_text_field($_POST['title']        ?? '');
        $course_id    = intval($_POST['course_id']                 ?? 0);
        $content_type = sanitize_text_field($_POST['content_type'] ?? 'file');
        $description  = sanitize_textarea_field($_POST['description'] ?? '');
        $content_url  = esc_url_raw($_POST['content_url']          ?? '');

        if (!$title || !$course_id) wp_send_json_error('Title and course are required.');

        // Resolve staff_id
        $user_id  = get_current_user_id();
        $staff_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT staff_id FROM {$p}staff WHERE user_id=%d LIMIT 1", $user_id
        ));

        // Verify lecturer has access to this course (skip for admin)
        if ($staff_id > 0 && !current_user_can('manage_options')) {
            $allowed = $wpdb->get_var($wpdb->prepare(
                "SELECT course_id FROM {$p}course_teachers WHERE staff_id=%d AND course_id=%d LIMIT 1",
                $staff_id, $course_id
            ));
            if (!$allowed) wp_send_json_error('You are not assigned to this course.');
        }

        $file_url  = $content_url;
        $file_size = 0;

        // Handle file upload
        if (!empty($_FILES['material_file']['name'])) {
            $file = $_FILES['material_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) wp_send_json_error('File upload error: ' . $file['error']);
            if ($file['size'] > 20 * 1024 * 1024) wp_send_json_error('File too large. Max 20MB.');

            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['pdf','doc','docx','ppt','pptx','mp4','mp3','zip'];
            if (!in_array($ext, $allowed_exts)) wp_send_json_error('File type not allowed.');

            // Use WordPress uploads directory
            $upload_dir = wp_upload_dir();
            $dest_dir   = $upload_dir['basedir'] . '/mtti-materials/';
            if (!file_exists($dest_dir)) wp_mkdir_p($dest_dir);

            $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            $dest_path = $dest_dir . $safe_name;

            if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
                wp_send_json_error('Failed to save file. Check server permissions.');
            }

            $file_url  = $upload_dir['baseurl'] . '/mtti-materials/' . $safe_name;
            $file_size = $file['size'];

            // Auto-detect content type from extension if not set clearly
            $ext_type_map = ['pdf'=>'pdf','doc'=>'document','docx'=>'document','ppt'=>'document','pptx'=>'document','mp4'=>'video','mp3'=>'audio','zip'=>'file'];
            if (isset($ext_type_map[$ext])) $content_type = $ext_type_map[$ext];
        }

        if (!$file_url) wp_send_json_error('No file or URL provided.');

        // Insert into lessons table with appropriate content_type
        $insert = $wpdb->insert(
            $p . 'lessons',
            [
                'course_id'    => $course_id,
                'title'        => $title,
                'description'  => $description,
                'content_type' => $content_type,
                'content_url'  => $file_url,
                'file_size'    => $file_size,
                'status'       => 'Pending Review',
                'created_by'   => $user_id,
                'created_at'   => current_time('mysql'),
            ],
            ['%d','%s','%s','%s','%s','%d','%s','%d','%s']
        );

        if (!$insert) wp_send_json_error('Database error: ' . $wpdb->last_error);

        wp_send_json_success([
            'lesson_id' => $wpdb->insert_id,
            'file_url'  => $file_url,
            'message'   => 'Material uploaded and pending admin review.',
        ]);
    }

}

// Register on init - same approach as learner portal, correct WordPress hook for shortcodes
add_action('init', function() {
    MTTI_MIS_Lecturer_Portal::get_instance();
}, 10);
