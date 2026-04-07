<?php
/**
 * Scheme of Work Admin Module
 * Week-by-week course plan with AI-powered syllabus import
 * @version 7.1.0
 */
class MTTI_MIS_Admin_Scheme {

    public function __construct() {
        // Constructor intentionally empty.
        // AJAX hooks are registered via register_ajax_hooks() called from plugins_loaded.
    }

    /**
     * Register AJAX hooks — called once from the main plugin file via plugins_loaded
     */
    public static function register_ajax_hooks() {
        $instance = new self();
        add_action('wp_ajax_mtti_ai_save_generated_scheme',    array($instance, 'ajax_save_generated_scheme'));
        add_action('wp_ajax_mtti_generate_scheme_from_units',  array($instance, 'ajax_generate_from_units'));
    }

    /* ==========================================================
     * MAIN DISPLAY
     * ========================================================== */
    public function display() {
        global $wpdb;
        $courses_table = $wpdb->prefix . 'mtti_courses';
        $scheme_table  = $wpdb->prefix . 'mtti_scheme_of_work';
        $units_table   = $wpdb->prefix . 'mtti_course_units';

        $action    = isset($_GET['action'])    ? sanitize_key($_GET['action'])    : 'list';
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id'])       : 0;
        $week_id   = isset($_GET['week_id'])   ? intval($_GET['week_id'])         : 0;
        $msg       = '';

        /* SAVE WEEK */
        if (isset($_POST['mtti_scheme_submit']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mtti_scheme_action')) {
            $cid          = intval($_POST['course_id']);
            $week_num     = intval($_POST['week_number']);
            $topic        = sanitize_text_field($_POST['topic']);
            $objectives   = sanitize_textarea_field($_POST['objectives']);
            $method       = sanitize_text_field($_POST['teaching_method']);
            $resources    = sanitize_textarea_field($_POST['resources']);
            $duration_hrs = floatval($_POST['duration_hours']);
            $unit_id      = intval($_POST['unit_id']);
            $status       = sanitize_key($_POST['status'] ?? 'Pending');
            if (!$cid || !$topic) {
                $msg = '<div class="notice notice-error"><p>Course and Topic are required.</p></div>';
            } else {
                $form_action = sanitize_key($_POST['form_action'] ?? 'add');
                if ($form_action === 'edit' && $week_id) {
                    $wpdb->update($scheme_table, compact('week_number','topic','objectives','method','resources','duration_hrs','unit_id','status'), array('week_id' => $week_id));
                    $msg = '<div class="notice notice-success is-dismissible"><p>Week updated.</p></div>';
                } else {
                    $wpdb->insert($scheme_table, array(
                        'course_id'=>$cid,'week_number'=>$week_num,'topic'=>$topic,
                        'objectives'=>$objectives,'teaching_method'=>$method,'resources'=>$resources,
                        'duration_hours'=>$duration_hrs,'unit_id'=>$unit_id ?: null,'status'=>$status,
                    ));
                    $msg = '<div class="notice notice-success is-dismissible"><p>Week added.</p></div>';
                }
                $action = 'view';
            }
        }

        /* DELETE WEEK */
        if ($action === 'delete' && $week_id && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_week_' . $week_id)) {
            $wpdb->delete($scheme_table, array('week_id' => $week_id));
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=' . $course_id . '&deleted=1'));
            exit;
        }

        /* MARK STATUS */
        if (isset($_GET['mark_status']) && $week_id && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mark_week_' . $week_id)) {
            $wpdb->update($scheme_table, array('status' => sanitize_key($_GET['mark_status'])), array('week_id' => $week_id));
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=' . $course_id));
            exit;
        }

        /* CLEAR ALL */
        if ($action === 'clear' && $course_id && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'clear_scheme_' . $course_id)) {
            $wpdb->delete($scheme_table, array('course_id' => $course_id));
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=' . $course_id . '&cleared=1'));
            exit;
        }

        $courses = $wpdb->get_results("SELECT course_id, course_code, course_name, duration_weeks FROM {$courses_table} WHERE status='Active' ORDER BY course_name");

        echo '<div class="wrap"><h1 class="wp-heading-inline">📋 Scheme of Work</h1><hr class="wp-header-end">';

        // Top tabs
        $tabs = array(
            'list'    => '📝 Create / Import',
            'view'    => '📚 View by Course',
            'monitor' => '👁 Teacher Monitor',
        );
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
        foreach ($tabs as $t => $label) {
            $url = admin_url('admin.php?page=mtti-mis-scheme&action=' . $t);
            $cls = ($action === $t || ($action === 'edit' && $t === 'view') || ($action === 'list' && $t === 'list')) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . $cls . '">' . $label . '</a>';
        }
        echo '</nav>';

        if ($msg) echo $msg;
        if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Week deleted.</p></div>';
        if (isset($_GET['cleared']))  echo '<div class="notice notice-success is-dismissible"><p>Scheme cleared.</p></div>';

        /* ====================================================
         * LIST VIEW — with AI Import section at top
         * ==================================================== */
        if ($action === 'list') {
            // No AI - manual scheme entry only
            $nonce    = wp_create_nonce('mtti_scheme_ai_nonce');

            // QUICK IMPORT PANEL
            $all_courses = $wpdb->get_results("SELECT course_id, course_code, course_name FROM {$wpdb->prefix}mtti_courses WHERE status='Active' ORDER BY course_name");
            ?>

            <!-- GENERATE FROM UNITS (No AI) -->
            <div class="card" style="padding:20px;margin-bottom:16px;max-width:700px;border-left:4px solid #2e7d32;">
                <h2 style="margin-top:0;color:#2e7d32;">⚡ Generate Scheme from Course Units</h2>
                <p class="description">Instantly creates a full scheme of work — 2 weeks per unit (Theory + Practical) plus a final assessment week. No AI needed.</p>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;">
                    <select id="gfu-course" style="padding:7px 10px;min-width:260px;">
                        <option value="">— Select Course —</option>
                        <?php foreach ($all_courses as $qc): ?>
                        <option value="<?php echo $qc->course_id; ?>"><?php echo esc_html($qc->course_code.' — '.$qc->course_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="gfu-hours" value="3" min="1" max="30" style="width:70px;padding:7px;" title="Hours per week">
                    <span class="description">hrs/week</span>
                    <label style="font-size:13px;"><input type="checkbox" id="gfu-overwrite" style="margin-right:4px;"> Clear existing weeks first</label>
                    <button type="button" onclick="gfuGenerate()" class="button button-primary" style="background:#2e7d32;border-color:#1b5e20;">⚡ Generate Now</button>
                    <span id="gfu-status" style="font-size:13px;font-weight:600;"></span>
                </div>
            </div>

            <!-- MANUAL PASTE PANEL -->
            <div class="card" style="padding:20px;margin-bottom:20px;max-width:700px;">
                <h2 style="margin-top:0;">📋 Or Paste Weekly Topics Manually</h2>
                <p class="description">Type or paste one topic per line. Each line becomes one week in the scheme. You can edit each week individually after importing.</p>
                <textarea id="qi-topics" rows="8" style="width:100%;padding:10px;font-family:monospace;font-size:13px;border:1px solid #8c8f94;border-radius:4px;resize:vertical;box-sizing:border-box;"
                    placeholder="Introduction to the Course and Overview&#10;Topic 2&#10;Topic 3&#10;Topic 4&#10;Topic 5&#10;Practical / Lab Session&#10;Revision and Assessment"></textarea>
                <p class="description" style="margin:6px 0 10px;">One topic per line. Select a course before importing.</p>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <select id="qi-course" style="padding:7px 10px;min-width:260px;">
                        <option value="">— Select Course —</option>
                        <?php foreach ($all_courses as $qc): ?>
                        <option value="<?php echo $qc->course_id; ?>"><?php echo esc_html($qc->course_code.' — '.$qc->course_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" id="qi-hours" value="3" min="1" max="30" style="width:70px;padding:7px;" title="Hours per week">
                    <span class="description">hrs/week</span>
                    <button type="button" onclick="qiImport()" class="button button-primary">⬇ Create Scheme Weeks</button>
                    <span id="qi-status" style="font-size:13px;"></span>
                </div>
            </div>
            <?php
                        if ($action === 'edit' && $week_id) {
                $week = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$scheme_table} WHERE week_id=%d", $week_id));
            }
            $units = $wpdb->get_results($wpdb->prepare(
                "SELECT unit_id, unit_code, unit_name FROM {$units_table} WHERE course_id=%d AND status='Active' ORDER BY order_number",
                $course_id
            ));
            $existing_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$scheme_table} WHERE course_id=%d", $course_id)));

            echo '<div class="card" style="max-width:700px;padding:24px;margin-top:20px;">';
            echo '<h2>' . ($action === 'edit' ? 'Edit Week ' . $week->week_number : 'Add New Week') . '</h2>';
            echo '<form method="post">';
            wp_nonce_field('mtti_scheme_action');
            echo '<input type="hidden" name="mtti_scheme_submit" value="1">
                  <input type="hidden" name="form_action" value="' . $action . '">
                  <input type="hidden" name="course_id" value="' . $course_id . '">
                  <table class="form-table">';

            $max_wk = $course->duration_weeks ?? 12;
            $wn_val = $week->week_number ?? ($existing_count + 1);
            echo "<tr><th>Week Number *</th><td>
                <input type='number' name='week_number' value='{$wn_val}' min='1' max='{$max_wk}' class='small-text' required>
                <span class='description'>of {$max_wk} total weeks</span></td></tr>";

            echo "<tr><th>Course Unit</th><td><select name='unit_id'><option value=''>— General —</option>";
            foreach ($units as $u) {
                $sel = ($week->unit_id ?? 0) == $u->unit_id ? 'selected' : '';
                echo "<option value='{$u->unit_id}' {$sel}>" . esc_html($u->unit_code . ' — ' . $u->unit_name) . "</option>";
            }
            echo "</select></td></tr>";

            echo "<tr><th>Topic *</th><td><input type='text' name='topic' value='" . esc_attr($week->topic ?? '') . "' class='regular-text' required></td></tr>";
            echo "<tr><th>Learning Objectives</th><td><textarea name='objectives' rows='3' class='large-text'>" . esc_textarea($week->objectives ?? '') . "</textarea></td></tr>";

            $methods = ['Lecture','Demonstration','Practical/Lab','Group Work','Discussion','Assignment','Field Work','Assessment/Test'];
            echo "<tr><th>Teaching Method</th><td><select name='teaching_method'>";
            foreach ($methods as $m) {
                $sel = ($week->teaching_method ?? 'Lecture') === $m ? 'selected' : '';
                echo "<option {$sel}>{$m}</option>";
            }
            echo "</select></td></tr>";

            echo "<tr><th>Resources</th><td><textarea name='resources' rows='2' class='large-text'>" . esc_textarea($week->resources ?? '') . "</textarea></td></tr>";
            echo "<tr><th>Duration (hours)</th><td><input type='number' name='duration_hours' value='" . ($week->duration_hours ?? 3) . "' min='0.5' max='40' step='0.5' class='small-text'></td></tr>";

            $statuses = ['Pending','In Progress','Completed','Skipped'];
            echo "<tr><th>Status</th><td><select name='status'>";
            foreach ($statuses as $s) {
                $sel = ($week->status ?? 'Pending') === $s ? 'selected' : '';
                echo "<option {$sel}>{$s}</option>";
            }
            echo "</select></td></tr></table>";
            submit_button($action === 'edit' ? 'Update Week' : 'Add Week');
            echo "<a href='" . admin_url("admin.php?page=mtti-mis-scheme&action=view&course_id={$course_id}") . "' class='button'>Cancel</a>";
            echo "</form></div></div>";

        // Quick Import JS
        ?>
        <script>
        (function(){
        var qiAjax  = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        var qiNonce = '<?php echo esc_js(wp_create_nonce('mtti_scheme_ai_nonce')); ?>';

        window.gfuGenerate = function() {
            var cid      = document.getElementById('gfu-course').value;
            var hours    = parseFloat(document.getElementById('gfu-hours').value) || 3;
            var overwrite= document.getElementById('gfu-overwrite').checked ? 1 : 0;
            var status   = document.getElementById('gfu-status');

            if (!cid) { status.textContent = '❌ Select a course first.'; status.style.color='#d63638'; return; }

            status.textContent = '⏳ Generating...';
            status.style.color = '#646970';

            jQuery.post(qiAjax, {
                action:    'mtti_generate_scheme_from_units',
                nonce:     qiNonce,
                course_id: cid,
                hours:     hours,
                overwrite: overwrite,
            }, function(res) {
                if (res.success) {
                    status.textContent = '✅ ' + res.data.saved + ' weeks created from ' + res.data.units + ' units! Redirecting...';
                    status.style.color = '#1d6b1e';
                    setTimeout(function(){
                        location.href = '<?php echo esc_js(admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=')); ?>' + cid;
                    }, 1500);
                } else {
                    status.textContent = '❌ ' + (res.data || 'Error');
                    status.style.color = '#d63638';
                }
            }).fail(function(xhr) {
                status.textContent = '❌ Request failed (HTTP ' + xhr.status + ')';
                status.style.color = '#d63638';
            });
        };

        window.qiImport = function() {
            var topics  = document.getElementById('qi-topics').value.trim();
            var cid     = document.getElementById('qi-course').value;
            var hours   = parseFloat(document.getElementById('qi-hours').value) || 3;
            var status  = document.getElementById('qi-status');

            if (!topics) { status.textContent = '❌ Enter at least one topic.'; status.style.color='#d63638'; return; }
            if (!cid)    { status.textContent = '❌ Select a course first.';    status.style.color='#d63638'; return; }

            var lines = topics.split('\n').map(function(l){ return l.trim(); }).filter(function(l){ return l.length > 0; });
            if (!lines.length) { status.textContent = '❌ No topics found.'; return; }

            var weeks = lines.map(function(topic, i) {
                return {
                    week_number:     i + 1,
                    topic:           topic,
                    objectives:      'Students will understand and apply key concepts related to ' + topic + '.',
                    teaching_method: (i % 4 === 2) ? 'Practical/Lab' : (i === lines.length - 1 ? 'Assessment/Test' : 'Lecture'),
                    resources:       'Textbooks, handouts, classroom materials',
                    duration_hours:  hours
                };
            });

            status.textContent = 'Saving ' + weeks.length + ' weeks...';
            status.style.color = '#646970';

            jQuery.post(qiAjax, {
                action:     'mtti_ai_save_generated_scheme',
                nonce:      qiNonce,
                course_id:  cid,
                weeks_json: JSON.stringify(weeks),
            }, function(res) {
                if (res.success) {
                    status.textContent = '✅ ' + res.data.saved + ' weeks created! Reloading...';
                    status.style.color = '#1d6b1e';
                    setTimeout(function(){
                        location.href = '<?php echo esc_js(admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=')); ?>' + cid;
                    }, 1200);
                } else {
                    status.textContent = '❌ ' + (res.data || 'Error saving');
                    status.style.color = '#d63638';
                }
            }).fail(function(xhr) {
                status.textContent = '❌ Request failed (HTTP ' + xhr.status + ')';
                status.style.color = '#d63638';
            });
        };
        })();
        </script>
        <?php

        } // end action === list

        /* ====================================================
         * VIEW — Browse scheme by course with week table
         * ==================================================== */
        elseif ($action === 'view' || $action === 'edit') {
            global $wpdb;
            $p = $wpdb->prefix . 'mtti_';

            // Course picker
            echo '<div style="margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
            echo '<strong>Select Course:</strong>';
            echo '<select onchange="location.href=\''.admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=').'\'+this.value" style="padding:6px 10px;min-width:260px;">';
            echo '<option value="">— Choose a course —</option>';
            foreach ($courses as $c) {
                $sel = ($course_id === intval($c->course_id)) ? 'selected' : '';
                echo '<option value="'.$c->course_id.'" '.$sel.'>'.esc_html($c->course_code.' — '.$c->course_name).'</option>';
            }
            echo '</select>';
            if ($course_id) {
                $add_url = admin_url('admin.php?page=mtti-mis-scheme&action=list&course_id='.$course_id);
                $clr_url = wp_nonce_url(admin_url('admin.php?page=mtti-mis-scheme&action=clear&course_id='.$course_id), 'clear_scheme_'.$course_id);
                echo '<a href="'.esc_url($add_url).'" class="button button-primary">+ Add Week</a>';
                echo '<a href="'.esc_url($clr_url).'" class="button" style="color:#d63638;border-color:#d63638;" onclick="return confirm(\'Clear entire scheme for this course?\')">🗑 Clear All</a>';
            }
            echo '</div>';

            if (!$course_id) {
                echo '<div class="notice notice-info"><p>Select a course above to view its scheme of work.</p></div>';
            } else {
                $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}courses WHERE course_id=%d", $course_id));
                $weeks  = $wpdb->get_results($wpdb->prepare(
                    "SELECT s.*, u.unit_name, u.unit_code FROM {$p}scheme_of_work s
                     LEFT JOIN {$p}course_units u ON s.unit_id=u.unit_id
                     WHERE s.course_id=%d ORDER BY s.week_number ASC",
                    $course_id
                ));

                if (!$weeks) {
                    echo '<div class="notice notice-warning"><p>No weeks in scheme yet. <a href="'.admin_url('admin.php?page=mtti-mis-scheme&action=list').'">Create the scheme</a>.</p></div>';
                } else {
                    $done    = count(array_filter($weeks, function($w){ return $w->status === 'Completed'; }));
                    $in_prog = count(array_filter($weeks, function($w){ return $w->status === 'In Progress'; }));
                    $total_w = intval($course->duration_weeks ?? count($weeks));
                    $pct     = $total_w > 0 ? round(($done / $total_w) * 100) : 0;

                    // Summary bar
                    echo '<div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">';
                    foreach ([
                        ['📚', count($weeks), 'Weeks Planned', '#1976D2'],
                        ['✅', $done,          'Completed',     '#2e7d32'],
                        ['▶',  $in_prog,       'In Progress',   '#f57c00'],
                        ['⏳', count($weeks)-$done-$in_prog, 'Pending', '#9e9e9e'],
                    ] as $s) {
                        echo '<div style="background:#f9f9f9;border:1px solid #ddd;border-left:4px solid '.$s[3].';border-radius:4px;padding:10px 16px;min-width:110px;">';
                        echo '<div style="font-size:22px;font-weight:700;color:'.$s[3].'">'.$s[0].' '.$s[1].'</div>';
                        echo '<div style="font-size:11px;color:#666;">'.$s[2].'</div></div>';
                    }
                    echo '</div>';
                    echo '<div style="margin-bottom:16px;background:#e8f5e9;height:10px;border-radius:5px;overflow:hidden;">';
                    echo '<div style="height:100%;width:'.$pct.'%;background:#2e7d32;border-radius:5px;transition:width .4s;"></div></div>';
                    echo '<p style="font-size:12px;color:#666;margin-bottom:16px;">'.$pct.'% of course covered ('.$done.' of '.$total_w.' weeks)</p>';

                    // Week table
                    echo '<table class="widefat striped"><thead><tr>
                        <th style="width:60px;">Week</th>
                        <th>Topic</th>
                        <th>Unit</th>
                        <th style="width:90px;">Method</th>
                        <th style="width:70px;">Hours</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:120px;">Actions</th>
                    </tr></thead><tbody>';

                    $status_colors = [
                        'Completed'   => ['#2e7d32', '#e8f5e9'],
                        'In Progress' => ['#f57c00', '#fff3e0'],
                        'Pending'     => ['#9e9e9e', '#fafafa'],
                        'Skipped'     => ['#bdbdbd', '#fafafa'],
                    ];
                    foreach ($weeks as $w) {
                        [$col, $bg] = $status_colors[$w->status] ?? ['#9e9e9e','#fafafa'];
                        $edit_url  = admin_url('admin.php?page=mtti-mis-scheme&action=edit&course_id='.$course_id.'&week_id='.$w->week_id);
                        $del_url   = wp_nonce_url(admin_url('admin.php?page=mtti-mis-scheme&action=delete&course_id='.$course_id.'&week_id='.$w->week_id), 'delete_week_'.$w->week_id);
                        echo '<tr style="background:'.$bg.';">';
                        echo '<td style="font-weight:700;font-size:15px;color:'.$col.';">'.$w->week_number.'</td>';
                        echo '<td><strong>'.esc_html($w->topic).'</strong>';
                        if ($w->objectives) echo '<br><span style="font-size:11px;color:#666;">'.esc_html(substr($w->objectives,0,80)).(strlen($w->objectives)>80?'…':'').'</span>';
                        echo '</td>';
                        echo '<td style="font-size:12px;">'.($w->unit_name ? esc_html($w->unit_code.' — '.$w->unit_name) : '—').'</td>';
                        echo '<td style="font-size:12px;">'.esc_html($w->teaching_method ?? '—').'</td>';
                        echo '<td style="font-size:12px;">'.($w->duration_hours ? number_format($w->duration_hours,1).' h' : '—').'</td>';
                        echo '<td><span style="display:inline-block;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;color:'.$col.';background:'.str_replace('e8f5e9','fff','#e8f5e9').';border:1px solid '.$col.';">'.esc_html($w->status).'</span></td>';
                        echo '<td><a href="'.esc_url($edit_url).'" class="button button-small">✏ Edit</a> ';
                        echo '<a href="'.esc_url($del_url).'" class="button button-small" style="color:#d63638;" onclick="return confirm(\'Delete week '.intval($w->week_number).'?\')">🗑</a></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
            }
        }

        /* ====================================================
         * MONITOR — Admin view of all teachers' progress
         * ==================================================== */
        elseif ($action === 'monitor') {
            global $wpdb;
            $p = $wpdb->prefix . 'mtti_';

            // Get all active courses with scheme data and assigned teacher
            $course_data = $wpdb->get_results(
                "SELECT c.course_id, c.course_code, c.course_name, c.duration_weeks,
                        COUNT(DISTINCT s.week_id) as weeks_planned,
                        SUM(s.status='Completed') as weeks_done,
                        SUM(s.status='In Progress') as weeks_inprog,
                        SUM(s.status='Pending') as weeks_pending,
                        SUM(s.duration_hours) as total_hours,
                        SUM(CASE WHEN s.status='Completed' THEN s.duration_hours ELSE 0 END) as hours_done,
                        MAX(CASE WHEN s.status='In Progress' THEN s.topic END) as current_topic,
                        MAX(CASE WHEN s.status='In Progress' THEN s.week_number END) as current_week,
                        u.display_name as teacher_name
                 FROM {$p}courses c
                 LEFT JOIN {$p}scheme_of_work s ON s.course_id=c.course_id
                 LEFT JOIN {$p}enrollments e ON e.course_id=c.course_id AND e.status IN ('Active','Enrolled','In Progress')
                 LEFT JOIN {$p}staff st ON st.staff_id=e.staff_id
                 LEFT JOIN {$wpdb->users} u ON u.ID=st.user_id
                 WHERE c.status='Active'
                 GROUP BY c.course_id, u.display_name
                 ORDER BY c.course_name ASC"
            );

            // Overall summary
            $total_courses  = count($course_data);
            $with_scheme    = count(array_filter($course_data, function($r){ return $r->weeks_planned > 0; }));
            $total_weeks    = array_sum(array_column($course_data, 'weeks_planned'));
            $completed_weeks= array_sum(array_column($course_data, 'weeks_done'));

            echo '<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">';
            foreach ([
                ['🏫', $total_courses,   'Active Courses',   '#1976D2'],
                ['📋', $with_scheme,     'Have Scheme',      '#2e7d32'],
                ['✅', $completed_weeks, 'Weeks Completed',  '#2e7d32'],
                ['📅', $total_weeks,     'Weeks Planned',    '#546e7a'],
            ] as $s) {
                echo '<div style="background:white;border:1px solid #ddd;border-top:4px solid '.$s[3].';border-radius:4px;padding:14px 20px;min-width:130px;box-shadow:0 1px 4px rgba(0,0,0,.06);">';
                echo '<div style="font-size:28px;font-weight:700;color:'.$s[3].'">'.$s[0].' '.$s[1].'</div>';
                echo '<div style="font-size:12px;color:#666;margin-top:2px;">'.$s[2].'</div></div>';
            }
            echo '</div>';

            if (!$course_data) {
                echo '<div class="notice notice-info"><p>No active courses found.</p></div>';
            } else {
                // Per-course monitoring cards
                foreach ($course_data as $row) {
                    $planned  = intval($row->weeks_planned);
                    $done     = intval($row->weeks_done);
                    $in_prog  = intval($row->weeks_inprog);
                    $total_w  = intval($row->duration_weeks ?: $planned);
                    $pct      = $total_w > 0 ? round(($done / $total_w) * 100) : 0;
                    $hrs_done = round($row->hours_done ?? 0, 1);
                    $hrs_total= round($row->total_hours ?? 0, 1);
                    $bar_color = $pct >= 80 ? '#2e7d32' : ($pct >= 40 ? '#f57c00' : '#d32f2f');

                    // Count enrolled students
                    $student_count = intval($wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT student_id) FROM {$p}enrollments WHERE course_id=%d AND status IN ('Active','Enrolled','In Progress')",
                        $row->course_id
                    )));

                    // Recent completed weeks (last 3)
                    $recent = $wpdb->get_results($wpdb->prepare(
                        "SELECT week_number, topic, teaching_method, duration_hours FROM {$p}scheme_of_work
                         WHERE course_id=%d AND status='Completed' ORDER BY week_number DESC LIMIT 3",
                        $row->course_id
                    ));

                    // Next pending week
                    $next = $wpdb->get_row($wpdb->prepare(
                        "SELECT week_number, topic, teaching_method, duration_hours FROM {$p}scheme_of_work
                         WHERE course_id=%d AND status='Pending' ORDER BY week_number ASC LIMIT 1",
                        $row->course_id
                    ));

                    echo '<div style="background:white;border:1px solid #ddd;border-radius:6px;margin-bottom:20px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">';

                    // Card header
                    echo '<div style="background:#1a237e;color:white;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">';
                    echo '<div>';
                    echo '<span style="font-size:11px;opacity:.7;letter-spacing:.06em;text-transform:uppercase;">'.esc_html($row->course_code).'</span>';
                    echo '<h3 style="margin:2px 0 0;font-size:15px;font-weight:700;">'.esc_html($row->course_name).'</h3>';
                    echo '</div>';
                    echo '<div style="display:flex;gap:16px;align-items:center;">';
                    echo '<div style="text-align:center;"><div style="font-size:20px;font-weight:700;">'.$student_count.'</div><div style="font-size:10px;opacity:.75;">Students</div></div>';
                    echo '<div style="text-align:center;"><div style="font-size:20px;font-weight:700;">'.$total_w.'</div><div style="font-size:10px;opacity:.75;">Wks Total</div></div>';
                    echo '<div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:#a5d6a7;">'.$pct.'%</div><div style="font-size:10px;opacity:.75;">Covered</div></div>';
                    echo '</div></div>';

                    // Progress bar
                    echo '<div style="height:8px;background:#e0e0e0;">';
                    echo '<div style="height:100%;width:'.$pct.'%;background:'.$bar_color.';transition:width .4s;"></div></div>';

                    echo '<div style="padding:14px 18px;">';
                    echo '<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px;">';

                    // Stats row
                    foreach ([
                        ['✅ Done', $done, '#2e7d32'],
                        ['▶ In Progress', $in_prog, '#f57c00'],
                        ['⏳ Pending', $planned-$done-$in_prog, '#9e9e9e'],
                        ['⏱ Hours Covered', $hrs_done.'/'.$hrs_total.' h', '#1976D2'],
                        ['👤 Teacher', $row->teacher_name ?: '—', '#546e7a'],
                    ] as $stat) {
                        echo '<div style="font-size:12px;"><span style="color:#666;">'.$stat[0].':</span> <strong style="color:'.$stat[2].'">'.$stat[1].'</strong></div>';
                    }
                    echo '</div>';

                    // Currently covering
                    if ($row->current_topic) {
                        echo '<div style="background:#e3f2fd;border-left:3px solid #1976D2;padding:8px 12px;border-radius:3px;margin-bottom:10px;font-size:12px;">';
                        echo '<strong style="color:#1976D2;">▶ Currently Covering (Week '.$row->current_week.'):</strong> '.esc_html($row->current_topic);
                        echo '</div>';
                    }

                    // Recent completed
                    if ($recent) {
                        echo '<div style="margin-bottom:10px;">';
                        echo '<div style="font-size:11px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Recently Completed</div>';
                        foreach ($recent as $r) {
                            echo '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f5f5f5;font-size:12px;">';
                            echo '<span style="color:#2e7d32;font-weight:700;min-width:60px;">Week '.$r->week_number.'</span>';
                            echo '<span style="flex:1;">'.esc_html($r->topic).'</span>';
                            echo '<span style="color:#888;white-space:nowrap;">'.esc_html($r->teaching_method ?? '').' · '.($r->duration_hours?number_format($r->duration_hours,1).'h':'').'</span>';
                            echo '</div>';
                        }
                        echo '</div>';
                    }

                    // Next up
                    if ($next) {
                        echo '<div style="background:#f9fbe7;border-left:3px solid #9ccc65;padding:8px 12px;border-radius:3px;font-size:12px;">';
                        echo '<strong style="color:#558b2f;">⏭ Next Up (Week '.$next->week_number.'):</strong> '.esc_html($next->topic);
                        if ($next->teaching_method) echo ' <span style="color:#888;">('.$next->teaching_method.')</span>';
                        echo '</div>';
                    }

                    // No scheme yet
                    if (!$planned) {
                        echo '<div style="background:#fff3e0;border-left:3px solid #f57c00;padding:8px 12px;border-radius:3px;font-size:12px;color:#e65100;">';
                        echo '⚠ No scheme of work created yet for this course.';
                        echo ' <a href="'.admin_url('admin.php?page=mtti-mis-scheme&action=list').'" style="color:#1976D2;">Create now →</a>';
                        echo '</div>';
                    }

                    // View full scheme link
                    echo '<div style="margin-top:10px;text-align:right;">';
                    echo '<a href="'.admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id='.$row->course_id).'" class="button button-small">📋 Full Scheme</a>';
                    echo '</div>';

                    echo '</div></div>';
                }
            }
        }

        echo '</div>'; // end .wrap
    }

    /* ==========================================================
     * AJAX: Save Generated Weeks
     * ========================================================== */
    public function ajax_save_generated_scheme() {
        check_ajax_referer('mtti_scheme_ai_nonce', 'nonce');
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;
        $course_id  = intval($_POST['course_id']);
        $weeks      = json_decode(stripslashes($_POST['weeks_json'] ?? '[]'), true);

        if (!$course_id || empty($weeks)) {
            wp_send_json_error('No data to save.');
        }

        $scheme_table = $wpdb->prefix . 'mtti_scheme_of_work';
        $saved = 0;
        foreach ($weeks as $w) {
            $wpdb->insert($scheme_table, array(
                'course_id'       => $course_id,
                'week_number'     => intval($w['week_number']),
                'topic'           => sanitize_text_field($w['topic']),
                'objectives'      => sanitize_textarea_field($w['objectives']),
                'teaching_method' => sanitize_text_field($w['teaching_method'] ?? 'Lecture'),
                'resources'       => sanitize_textarea_field($w['resources']),
                'duration_hours'  => floatval($w['duration_hours'] ?? 3),
                'status'          => 'Pending',
            ));
            if ($wpdb->insert_id) $saved++;
        }
        wp_send_json_success(array('saved' => $saved));
    }

    /* ==========================================================
     * AJAX: Generate Scheme of Work from Course Units (No AI)
     * Creates one week per unit, auto-assigns topics from unit names
     * ========================================================== */
    public function ajax_generate_from_units() {
        check_ajax_referer('mtti_scheme_ai_nonce', 'nonce');
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;
        $course_id     = intval($_POST['course_id'] ?? 0);
        $hours_per_wk  = floatval($_POST['hours'] ?? 3);
        $overwrite     = !empty($_POST['overwrite']);

        if (!$course_id) {
            wp_send_json_error('Select a course first.');
        }

        // Get course units
        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT unit_id, unit_code, unit_name, description FROM {$wpdb->prefix}mtti_course_units
             WHERE course_id = %d AND status = 'Active' ORDER BY order_number ASC, unit_code ASC",
            $course_id
        ));

        if (empty($units)) {
            wp_send_json_error('No active units found for this course. Add units first under Course Units.');
        }

        $scheme_table = $wpdb->prefix . 'mtti_scheme_of_work';

        // Optionally clear existing scheme
        if ($overwrite) {
            $wpdb->delete($scheme_table, array('course_id' => $course_id));
        }

        // Get starting week number
        $last_week = intval($wpdb->get_var($wpdb->prepare(
            "SELECT MAX(week_number) FROM {$scheme_table} WHERE course_id = %d", $course_id
        )));
        $week_num = $last_week + 1;

        $saved = 0;
        $methods = array('Lecture', 'Demonstration', 'Practical/Lab', 'Lecture', 'Group Work', 'Practical/Lab', 'Discussion', 'Assessment/Test');
        $i = 0;

        foreach ($units as $unit) {
            // Week 1 of unit: Introduction/theory
            $wpdb->insert($scheme_table, array(
                'course_id'      => $course_id,
                'unit_id'        => $unit->unit_id,
                'week_number'    => $week_num++,
                'topic'          => $unit->unit_name . ' — Introduction & Theory',
                'objectives'     => 'Students will understand the theory and concepts of ' . $unit->unit_name . '.',
                'teaching_method'=> 'Lecture',
                'resources'      => 'Textbooks, handouts, projector',
                'duration_hours' => $hours_per_wk,
                'status'         => 'Pending',
            ));
            $saved++;

            // Week 2 of unit: Practical/Lab
            $wpdb->insert($scheme_table, array(
                'course_id'      => $course_id,
                'unit_id'        => $unit->unit_id,
                'week_number'    => $week_num++,
                'topic'          => $unit->unit_name . ' — Practical & Exercises',
                'objectives'     => 'Students will apply practical skills and complete hands-on exercises for ' . $unit->unit_name . '.',
                'teaching_method'=> 'Practical/Lab',
                'resources'      => 'Computers, worksheets, practical tools',
                'duration_hours' => $hours_per_wk,
                'status'         => 'Pending',
            ));
            $saved++;

            $i++;
        }

        // Add final assessment week
        $wpdb->insert($scheme_table, array(
            'course_id'      => $course_id,
            'unit_id'        => null,
            'week_number'    => $week_num,
            'topic'          => 'Course Revision & Final Assessment',
            'objectives'     => 'Students will revise all units and demonstrate overall course competency.',
            'teaching_method'=> 'Assessment/Test',
            'resources'      => 'Past papers, revision notes',
            'duration_hours' => $hours_per_wk,
            'status'         => 'Pending',
        ));
        $saved++;

        wp_send_json_success(array('saved' => $saved, 'units' => count($units)));
    }

    /* ==========================================================
     * HELPERS: Text Extraction
     * ========================================================== */
    private function extract_pdf_text($filepath) {
        // Try pdftotext first (poppler-utils — most accurate)
        $esc  = escapeshellarg($filepath);
        $text = shell_exec("pdftotext {$esc} - 2>/dev/null");
        if (!empty(trim($text))) return $text;

        // PHP fallback: parse text streams from raw PDF bytes
        $raw = file_get_contents($filepath);
        if ($raw === false) return '';
        preg_match_all('/BT[\s\S]*?ET/', $raw, $blocks);
        $text = '';
        foreach ($blocks[0] as $b) {
            preg_match_all('/\(([^)]*)\)\s*T[jJ]/', $b, $tj);
            foreach ($tj[1] as $t) $text .= $t . ' ';
        }
        if (empty(trim($text))) {
            preg_match_all('/\(([^\)]{3,})\)/', $raw, $strings);
            foreach ($strings[1] as $s) {
                if (preg_match('/[a-zA-Z]{3,}/', $s)) $text .= $s . ' ';
            }
        }
        return trim($text) ?: '';
    }

    private function extract_docx_text($filepath) {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) return '';
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) return '';
        $text = strip_tags(str_replace(
            array('</w:p>', '</w:tr>', '<w:br/>'),
            array("\n",      "\n",      "\n"),
            $xml
        ));
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        return trim($text);
    }
}
