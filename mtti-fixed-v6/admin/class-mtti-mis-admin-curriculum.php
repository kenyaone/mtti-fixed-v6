<?php
/**
 * Curriculum Monitor
 * Shows admin: all courses → their units → whether lessons/interactives exist per unit
 * Simple at-a-glance view: is every unit in the curriculum covered by lessons?
 */
if (!defined('WPINC')) die;

class MTTI_MIS_Admin_Curriculum {

    public function display() {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        $action    = isset($_GET['action'])    ? sanitize_key($_GET['action'])    : 'overview';
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id'])       : 0;

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">📋 Curriculum Monitor</h1>';
        echo '<hr class="wp-header-end">';

        // Tabs
        $tabs = [
            'overview' => '🏫 All Courses',
            'course'   => '📚 Course Detail',
        ];
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
        foreach ($tabs as $t => $label) {
            $url = admin_url('admin.php?page=mtti-mis-scheme&action=' . $t . ($course_id && $t==='course' ? '&course_id='.$course_id : ''));
            $cls = ($action === $t || ($action === 'overview' && $t === 'overview') || ($action === 'course' && $t === 'course')) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . $cls . '">' . $label . '</a>';
        }
        echo '</nav>';

        if ($action === 'course' && $course_id) {
            $this->show_course_detail($p, $wpdb, $course_id);
        } else {
            $this->show_overview($p, $wpdb);
        }

        echo '</div>';
    }

    /* ─────────────────────────────────────────────
     * OVERVIEW: All active courses, coverage at a glance
     * ───────────────────────────────────────────── */
    private function show_overview($p, $wpdb) {

        $courses = $wpdb->get_results(
            "SELECT c.*,
                    COUNT(DISTINCT cu.unit_id) as unit_count,
                    COUNT(DISTINCT CASE WHEN l.lesson_id IS NOT NULL AND l.status='Published' THEN cu.unit_id END) as units_with_lessons,
                    COUNT(DISTINCT CASE WHEN l.status='Published' THEN l.lesson_id END) as total_lessons,
                    COUNT(DISTINCT CASE WHEN l.content_type='html_interactive' AND l.status='Published' THEN l.lesson_id END) as total_interactives,
                    COUNT(DISTINCT CASE WHEN l.status='Pending Review' THEN l.lesson_id END) as pending_review,
                    COUNT(DISTINCT e.student_id) as student_count
             FROM {$p}courses c
             LEFT JOIN {$p}course_units cu ON cu.course_id = c.course_id AND cu.status = 'Active'
             LEFT JOIN {$p}lessons l ON l.unit_id = cu.unit_id
             LEFT JOIN {$p}enrollments e ON e.course_id = c.course_id AND e.status IN ('Active','Enrolled','In Progress')
             WHERE c.status = 'Active'
             GROUP BY c.course_id
             ORDER BY c.course_name ASC"
        );

        if (!$courses) {
            echo '<div class="notice notice-info"><p>No active courses found. <a href="' . admin_url('admin.php?page=mtti-mis-courses') . '">Add courses first</a>.</p></div>';
            return;
        }

        // Summary totals
        $total_courses   = count($courses);
        $gap_courses     = count(array_filter($courses, fn($c) => $c->unit_count > 0 && $c->units_with_lessons < $c->unit_count));
        $full_courses    = count(array_filter($courses, fn($c) => $c->unit_count > 0 && $c->units_with_lessons >= $c->unit_count && $c->unit_count > 0));
        $no_units        = count(array_filter($courses, fn($c) => $c->unit_count == 0));

        echo '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:24px;">';
        foreach ([
            ['🏫', $total_courses, 'Active Courses',   '#1976D2'],
            ['✅', $full_courses,  'Fully Covered',    '#2e7d32'],
            ['⚠',  $gap_courses,  'Missing Lessons',  '#f57c00'],
            ['❌', $no_units,     'No Units Set Up',  '#d32f2f'],
        ] as $s) {
            echo '<div style="background:#fff;border:1px solid #ddd;border-top:4px solid '.$s[3].';border-radius:4px;padding:14px 20px;min-width:130px;box-shadow:0 1px 3px rgba(0,0,0,.06);">';
            echo '<div style="font-size:26px;font-weight:700;color:'.$s[3].'">'.$s[0].' '.$s[1].'</div>';
            echo '<div style="font-size:12px;color:#666;margin-top:2px;">'.$s[2].'</div></div>';
        }
        echo '</div>';

        if ($gap_courses || $no_units) {
            echo '<div class="notice notice-warning" style="margin-bottom:16px;"><p>';
            if ($no_units)    echo '<strong>' . $no_units . ' course' . ($no_units>1?'s have':' has') . ' no units defined</strong> — go to <a href="' . admin_url('admin.php?page=mtti-mis-units') . '">Course Units</a> to add them. ';
            if ($gap_courses) echo '<strong>' . $gap_courses . ' course' . ($gap_courses>1?'s are':' is') . ' missing lessons</strong> for some units — go to <a href="' . admin_url('admin.php?page=mtti-mis-lessons') . '">Lessons</a> to upload content.';
            echo '</p></div>';
        }

        // Course table
        echo '<table class="widefat striped">';
        echo '<thead><tr>
            <th>Course</th>
            <th style="width:80px;text-align:center;">Students</th>
            <th style="width:110px;text-align:center;">Units</th>
            <th style="width:90px;text-align:center;">Lessons</th>
            <th style="width:90px;text-align:center;">Practicals</th>
            <th style="width:100px;text-align:center;">⏳ Pending</th>
            <th>Coverage</th>
            <th style="width:90px;">Status</th>
            <th style="width:80px;"></th>
        </tr></thead><tbody>';

        foreach ($courses as $c) {
            $pct     = $c->unit_count > 0 ? round(($c->units_with_lessons / $c->unit_count) * 100) : 0;
            $missing = $c->unit_count - $c->units_with_lessons;

            if ($c->unit_count == 0) {
                $status = '<span style="color:#d32f2f;font-weight:700;">❌ No Units</span>';
                $bar_col = '#d32f2f';
            } elseif ($pct == 100) {
                $status = '<span style="color:#2e7d32;font-weight:700;">✅ Complete</span>';
                $bar_col = '#2e7d32';
            } elseif ($pct >= 50) {
                $status = '<span style="color:#f57c00;font-weight:700;">⚠ Partial</span>';
                $bar_col = '#f57c00';
            } else {
                $status = '<span style="color:#d32f2f;font-weight:700;">⚠ Gaps</span>';
                $bar_col = '#d32f2f';
            }

            $detail_url = admin_url('admin.php?page=mtti-mis-scheme&action=course&course_id=' . $c->course_id);

            echo '<tr>';
            echo '<td><strong>' . esc_html($c->course_code) . '</strong><br><span style="font-size:12px;color:#555;">' . esc_html($c->course_name) . '</span></td>';
            echo '<td style="text-align:center;">' . $c->student_count . '</td>';
            echo '<td style="text-align:center;">' . $c->units_with_lessons . ' / ' . $c->unit_count . '<br><span style="font-size:11px;color:#999;">have lessons</span></td>';
            echo '<td style="text-align:center;">' . $c->total_lessons . '</td>';
            echo '<td style="text-align:center;">' . $c->total_interactives . '</td>';
            $pending = intval($c->pending_review ?? 0);
            echo '<td style="text-align:center;">';
            if ($pending > 0) {
                $review_url = admin_url('admin.php?page=mtti-mis-interactive&sub=review');
                echo '<a href="'.esc_url($review_url).'" style="font-weight:700;color:#ff8f00;">⏳ '.$pending.'</a>';
            } else {
                echo '<span style="color:#9e9e9e;">—</span>';
            }
            echo '</td>';
            echo '<td style="min-width:120px;">';
            echo '<div style="display:flex;align-items:center;gap:8px;">';
            echo '<div style="flex:1;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;">';
            echo '<div style="height:100%;width:' . $pct . '%;background:' . $bar_col . ';"></div></div>';
            echo '<span style="font-size:12px;font-weight:700;color:' . $bar_col . ';min-width:36px;">' . $pct . '%</span></div>';
            if ($missing > 0) echo '<div style="font-size:11px;color:#d32f2f;margin-top:2px;">⚠ ' . $missing . ' unit' . ($missing>1?'s':'') . ' without lessons</div>';
            echo '</td>';
            echo '<td>' . $status . '</td>';
            echo '<td><a href="' . esc_url($detail_url) . '" class="button button-small">View →</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Quick links
        echo '<div style="margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;">';
        echo '<a href="' . admin_url('admin.php?page=mtti-mis-units') . '" class="button">📑 Manage Units</a>';
        echo '<a href="' . admin_url('admin.php?page=mtti-mis-lessons') . '" class="button">📖 Upload Lessons</a>';
        echo '<a href="' . admin_url('admin.php?page=mtti-mis-interactive') . '" class="button">⚡ Upload Practicals</a>';
        echo '</div>';
    }

    /* ─────────────────────────────────────────────
     * COURSE DETAIL: Every unit with its lessons
     * ───────────────────────────────────────────── */
    private function show_course_detail($p, $wpdb, $course_id) {

        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}courses WHERE course_id=%d", $course_id));
        if (!$course) { echo '<div class="notice notice-error"><p>Course not found.</p></div>'; return; }

        // Course picker
        $all_courses = $wpdb->get_results("SELECT course_id, course_code, course_name FROM {$p}courses WHERE status='Active' ORDER BY course_name");
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap;">';
        echo '<strong>Course:</strong>';
        echo '<select onchange="location.href=\''.admin_url('admin.php?page=mtti-mis-scheme&action=course&course_id=').'\'+this.value" style="padding:6px 10px;min-width:260px;">';
        foreach ($all_courses as $c) {
            $sel = $c->course_id == $course_id ? 'selected' : '';
            echo '<option value="'.$c->course_id.'" '.$sel.'>'.esc_html($c->course_code.' — '.$c->course_name).'</option>';
        }
        echo '</select>';
        echo '<a href="' . admin_url('admin.php?page=mtti-mis-units&course_id='.$course_id) . '" class="button">📑 Add/Edit Units</a>';
        echo '<a href="' . admin_url('admin.php?page=mtti-mis-lessons') . '" class="button">📖 Upload Lessons</a>';
        echo '<a href="' . admin_url('admin.php?page=mtti-mis-interactive') . '" class="button">⚡ Upload Practicals</a>';
        echo '</div>';

        // Units with lessons breakdown
        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT cu.*,
                    COUNT(DISTINCT CASE WHEN l.status='Published' THEN l.lesson_id END) as lesson_count,
                    COUNT(DISTINCT CASE WHEN l.content_type='html_interactive' AND l.status='Published' THEN l.lesson_id END) as interactive_count,
                    COUNT(DISTINCT CASE WHEN l.content_type='video' AND l.status='Published' THEN l.lesson_id END) as video_count,
                    COUNT(DISTINCT CASE WHEN l.content_type NOT IN ('html_interactive','video') AND l.status='Published' THEN l.lesson_id END) as other_count,
                    COUNT(DISTINCT CASE WHEN l.status='Pending Review' THEN l.lesson_id END) as pending_count
             FROM {$p}course_units cu
             LEFT JOIN {$p}lessons l ON l.unit_id = cu.unit_id
             WHERE cu.course_id = %d AND cu.status = 'Active'
             GROUP BY cu.unit_id
             ORDER BY cu.order_number ASC, cu.unit_code ASC",
            $course_id
        ));

        if (!$units) {
            echo '<div class="notice notice-warning"><p>No units defined for this course. ';
            echo '<a href="' . admin_url('admin.php?page=mtti-mis-units') . '">Go to Course Units</a> to add the curriculum topics.</p></div>';
            return;
        }

        // Summary
        $total_units  = count($units);
        $with_lessons = count(array_filter($units, fn($u) => $u->lesson_count > 0));
        $missing      = $total_units - $with_lessons;
        $pct          = $total_units > 0 ? round(($with_lessons / $total_units) * 100) : 0;
        $total_enrolled = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT student_id) FROM {$p}enrollments WHERE course_id=%d AND status IN ('Active','Enrolled','In Progress')", $course_id
        )));

        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:20px;">';
        echo '<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">';
        echo '<div><span style="font-size:11px;color:#666;text-transform:uppercase;font-weight:700;letter-spacing:.06em;">'.esc_html($course->course_code).'</span>';
        echo '<h2 style="margin:2px 0 0;font-size:17px;">'.esc_html($course->course_name).'</h2></div>';
        foreach ([
            ['👥', $total_enrolled . ' students', '#1976D2'],
            ['📑', $total_units . ' units',        '#546e7a'],
            ['✅', $with_lessons . ' covered',     '#2e7d32'],
            ['⚠',  $missing . ' missing',         $missing > 0 ? '#d32f2f' : '#9e9e9e'],
        ] as $s) {
            echo '<div style="font-size:13px;"><span style="font-size:18px;">'.$s[0].'</span> <strong style="color:'.$s[2].'">'.$s[1].'</strong></div>';
        }
        echo '</div>';
        // Progress bar
        $bar_col = $pct==100 ? '#2e7d32' : ($pct>=50 ? '#f57c00' : '#d32f2f');
        echo '<div style="display:flex;align-items:center;gap:10px;">';
        echo '<div style="flex:1;height:10px;background:#e0e0e0;border-radius:5px;overflow:hidden;">';
        echo '<div style="height:100%;width:'.$pct.'%;background:'.$bar_col.';transition:width .3s;"></div></div>';
        echo '<span style="font-size:13px;font-weight:700;color:'.$bar_col.'">'.$pct.'% curriculum covered</span></div>';
        echo '</div>';

        // Unit-by-unit table
        echo '<table class="widefat">';
        echo '<thead><tr style="background:#f9f9f9;">
            <th style="width:50px;">#</th>
            <th>Unit Code</th>
            <th>Unit Name / Topic</th>
            <th style="width:70px;text-align:center;">Hours</th>
            <th style="text-align:center;">📖 Lessons</th>
            <th style="text-align:center;">⚡ Practicals</th>
            <th style="text-align:center;">🎬 Videos</th>
            <th style="text-align:center;">⏳ Pending</th>
            <th style="width:110px;">Status</th>
            <th style="width:80px;"></th>
        </tr></thead><tbody>';

        foreach ($units as $i => $u) {
            $has = $u->lesson_count > 0;
            $bg  = $has ? '' : 'background:#fff8f8;';
            $row_border = !$has ? 'border-left:3px solid #ef9a9a;' : ($u->interactive_count > 0 ? 'border-left:3px solid #a5d6a7;' : 'border-left:3px solid #90caf9;');

            if (!$has) {
                $status = '<span style="color:#d32f2f;font-weight:700;font-size:11px;">❌ NO LESSONS</span>';
            } elseif ($u->interactive_count > 0) {
                $status = '<span style="color:#2e7d32;font-weight:700;font-size:11px;">✅ READY</span>';
            } else {
                $status = '<span style="color:#1976D2;font-weight:700;font-size:11px;">📖 TEXT ONLY</span>';
            }

            $add_lesson_url = admin_url('admin.php?page=mtti-mis-lessons&unit_id='.$u->unit_id);
            $add_interac_url = admin_url('admin.php?page=mtti-mis-interactive');

            echo '<tr style="'.$bg.$row_border.'">';
            echo '<td style="color:#999;font-size:13px;text-align:center;">'.($i+1).'</td>';
            echo '<td><strong style="font-family:monospace;">'.esc_html($u->unit_code).'</strong></td>';
            echo '<td>'.esc_html($u->unit_name);
            if ($u->description) echo '<br><span style="font-size:11px;color:#777;">'.esc_html(substr($u->description,0,80)).(strlen($u->description)>80?'…':'').'</span>';
            echo '</td>';
            echo '<td style="text-align:center;font-size:13px;">'.($u->duration_hours ? $u->duration_hours.'h' : '—').'</td>';
            echo '<td style="text-align:center;font-size:14px;font-weight:700;color:'.($u->lesson_count > 0 ? '#2e7d32' : '#d32f2f').'">'.$u->lesson_count.'</td>';
            echo '<td style="text-align:center;font-size:14px;font-weight:700;color:'.($u->interactive_count > 0 ? '#2e7d32' : '#9e9e9e').'">'.$u->interactive_count.'</td>';
            echo '<td style="text-align:center;font-size:14px;font-weight:700;color:'.($u->video_count > 0 ? '#1976D2' : '#9e9e9e').'">'.$u->video_count.'</td>';
            $pc = intval($u->pending_count ?? 0);
            echo '<td style="text-align:center;">';
            if ($pc > 0) {
                $review_url = admin_url('admin.php?page=mtti-mis-interactive&sub=review');
                echo '<a href="'.esc_url($review_url).'" style="font-weight:700;color:#ff8f00;font-size:13px;">⏳ '.$pc.'</a>';
            } else {
                echo '<span style="color:#ccc;font-size:12px;">—</span>';
            }
            echo '</td>';
            echo '<td>'.$status.'</td>';
            echo '<td>';
            if (!$has) {
                echo '<a href="'.esc_url($add_lesson_url).'" class="button button-primary button-small">+ Add</a>';
            } else {
                echo '<a href="'.esc_url($add_lesson_url).'" class="button button-small">Edit</a>';
            }
            echo '</td>';
            echo '</tr>';

            // If this unit has lessons, show them inline
            if ($u->lesson_count > 0) {
                $lessons = $wpdb->get_results($wpdb->prepare(
                    "SELECT lesson_id, title, content_type, duration_minutes, view_count, order_number
                     FROM {$p}lessons
                     WHERE unit_id=%d AND status='Published'
                     ORDER BY order_number ASC, lesson_id ASC",
                    $u->unit_id
                ));
                foreach ($lessons as $l) {
                    $type_icons = ['html_interactive'=>'⚡','video'=>'🎬','pdf'=>'📕','document'=>'📘','text'=>'📝','audio'=>'🎵','file'=>'📄'];
                    $licon = $type_icons[$l->content_type] ?? '📄';
                    echo '<tr style="background:#f9f9f9;'.$row_border.'">';
                    echo '<td></td>';
                    echo '<td colspan="2" style="padding-left:24px;font-size:12px;color:#444;">'.esc_html('└ ').'<span style="margin-right:4px;">'.$licon.'</span>'.esc_html($l->title).'</td>';
                    echo '<td style="text-align:center;font-size:11px;color:#666;">'.($l->duration_minutes ? $l->duration_minutes.'min' : '—').'</td>';
                    echo '<td colspan="4" style="font-size:11px;color:#888;text-align:center;">👁 '.$l->view_count.' views</td>';
                    echo '<td colspan="2"></td>';
                    echo '</tr>';
                }
            }
        }
        echo '</tbody></table>';

        // What to do if gaps
        if ($missing > 0) {
            echo '<div style="margin-top:20px;background:#fff3e0;border:1px solid #ffcc80;border-radius:4px;padding:16px 20px;">';
            echo '<h3 style="margin:0 0 8px;color:#e65100;">⚠ '.$missing.' unit'.($missing>1?'s':'').' missing lesson content</h3>';
            echo '<p style="margin:0 0 10px;font-size:13px;color:#555;">Students cannot study these units yet. Upload lessons or practicals for each unit:</p>';
            echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
            echo '<a href="'.admin_url('admin.php?page=mtti-mis-lessons').'" class="button button-primary">📖 Upload Text/PDF Lessons</a>';
            echo '<a href="'.admin_url('admin.php?page=mtti-mis-interactive').'" class="button button-primary">⚡ Upload Interactive Practicals</a>';
            echo '</div></div>';
        }
    }
}
