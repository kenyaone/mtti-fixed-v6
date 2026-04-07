<?php
/**
 * Teacher → Course Assignment
 * Simple page: pick a course, pick a teacher. Done.
 * Uses mtti_course_teachers junction table.
 */
if (!defined('WPINC')) die;

class MTTI_MIS_Admin_Teacher_Courses {

    public function display() {
        global $wpdb;
        $p = $wpdb->prefix . 'mtti_';

        // Handle assign/unassign actions
        if (isset($_POST['mtti_tc_action']) && check_admin_referer('mtti_tc_nonce', 'mtti_tc_nonce')) {
            $course_id = intval($_POST['course_id'] ?? 0);
            $staff_id  = intval($_POST['staff_id'] ?? 0);
            $action    = sanitize_key($_POST['mtti_tc_action']);

            if ($action === 'assign' && $course_id && $staff_id) {
                // Remove any existing teacher for this course first (one teacher per course)
                $wpdb->delete($p . 'course_teachers', ['course_id' => $course_id]);
                // Assign new teacher
                $wpdb->insert($p . 'course_teachers', [
                    'course_id'   => $course_id,
                    'staff_id'    => $staff_id,
                    'assigned_at' => current_time('mysql'),
                ]);
                echo '<div class="notice notice-success is-dismissible"><p>✅ Teacher assigned successfully.</p></div>';
            } elseif ($action === 'unassign' && $course_id) {
                $wpdb->delete($p . 'course_teachers', ['course_id' => $course_id]);
                echo '<div class="notice notice-success is-dismissible"><p>Teacher removed from course.</p></div>';
            }
        }

        // Load data
        $courses = $wpdb->get_results(
            "SELECT c.course_id, c.course_code, c.course_name, c.status,
                    ct.staff_id as assigned_staff_id,
                    s.staff_number, u.display_name as teacher_name,
                    COUNT(DISTINCT e.student_id) as student_count
             FROM {$p}courses c
             LEFT JOIN {$p}course_teachers ct ON ct.course_id = c.course_id
             LEFT JOIN {$p}staff s ON s.staff_id = ct.staff_id
             LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
             LEFT JOIN {$p}enrollments e ON e.course_id = c.course_id AND e.status IN ('Active','Enrolled','In Progress')
             WHERE c.status = 'Active'
             GROUP BY c.course_id
             ORDER BY c.course_name ASC"
        );

        $all_staff = $wpdb->get_results(
            "SELECT s.staff_id, s.staff_number, u.display_name, s.department, s.specialization
             FROM {$p}staff s
             LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id
             WHERE s.status = 'Active'
             ORDER BY u.display_name ASC"
        );

        $assigned   = count(array_filter($courses, fn($c) => $c->assigned_staff_id));
        $unassigned = count($courses) - $assigned;

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">👩‍🏫 Assign Teachers to Courses</h1>';
        echo '<hr class="wp-header-end">';

        if (!$all_staff) {
            echo '<div class="notice notice-warning"><p>';
            echo '⚠ No teachers found. <a href="' . admin_url('admin.php?page=mtti-mis-staff') . '">Add teachers first →</a>';
            echo '</p></div>';
        }

        // Summary strip
        echo '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px;">';
        foreach ([
            ['🏫', count($courses),  'Active Courses',   '#546e7a'],
            ['✅', $assigned,        'Have a Teacher',   '#2e7d32'],
            ['⚠',  $unassigned,     'No Teacher Yet',   $unassigned > 0 ? '#d32f2f' : '#9e9e9e'],
            ['👩‍🏫', count($all_staff), 'Teachers',        '#1976D2'],
        ] as $s) {
            echo '<div style="background:#fff;border:1px solid #ddd;border-top:4px solid '.$s[3].';border-radius:4px;padding:12px 18px;min-width:120px;box-shadow:0 1px 3px rgba(0,0,0,.06);">';
            echo '<div style="font-size:24px;font-weight:700;color:'.$s[3].'">'.$s[0].' '.$s[1].'</div>';
            echo '<div style="font-size:12px;color:#666;margin-top:2px;">'.$s[2].'</div>';
            echo '</div>';
        }
        echo '</div>';

        if ($unassigned > 0) {
            echo '<div class="notice notice-warning" style="margin-bottom:16px;"><p>';
            echo '<strong>'.$unassigned.' course'.($unassigned>1?'s have':' has').' no teacher assigned.</strong> ';
            echo 'Students in these courses will not see a teacher in their portal.</p></div>';
        }

        // Course table
        echo '<table class="widefat" style="border-collapse:collapse;">';
        echo '<thead><tr style="background:#f9f9f9;">
            <th style="padding:12px;">Course</th>
            <th style="padding:12px;width:80px;text-align:center;">Students</th>
            <th style="padding:12px;">Assigned Teacher</th>
            <th style="padding:12px;width:320px;">Change Assignment</th>
        </tr></thead><tbody>';

        foreach ($courses as $c) {
            $has = !empty($c->assigned_staff_id);
            $row_border = $has ? '' : 'border-left:3px solid #ef9a9a;';
            echo '<tr style="border-bottom:1px solid #eee;'.$row_border.'">';

            // Course
            echo '<td style="padding:12px;">';
            echo '<strong style="font-size:13px;">'.esc_html($c->course_code).'</strong>';
            echo '<div style="font-size:12px;color:#555;margin-top:2px;">'.esc_html($c->course_name).'</div>';
            echo '</td>';

            // Students
            echo '<td style="padding:12px;text-align:center;font-weight:700;color:#1976D2;">'.intval($c->student_count).'</td>';

            // Current teacher
            echo '<td style="padding:12px;">';
            if ($has) {
                echo '<div style="display:flex;align-items:center;gap:8px;">';
                echo '<div style="width:32px;height:32px;border-radius:50%;background:#e8f5e9;display:flex;align-items:center;justify-content:center;font-size:16px;">👩‍🏫</div>';
                echo '<div>';
                echo '<div style="font-size:13px;font-weight:600;color:#2e7d32;">'.esc_html($c->teacher_name).'</div>';
                echo '<div style="font-size:11px;color:#999;">'.esc_html($c->staff_number ?? '').'</div>';
                echo '</div></div>';
            } else {
                echo '<span style="color:#d32f2f;font-size:13px;">❌ Not assigned</span>';
            }
            echo '</td>';

            // Assign form
            echo '<td style="padding:10px;">';
            echo '<form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
            wp_nonce_field('mtti_tc_nonce', 'mtti_tc_nonce');
            echo '<input type="hidden" name="course_id" value="'.$c->course_id.'">';

            echo '<select name="staff_id" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;flex:1;min-width:150px;">';
            echo '<option value="">— Select Teacher —</option>';
            foreach ($all_staff as $t) {
                $sel = ($t->staff_id == $c->assigned_staff_id) ? 'selected' : '';
                $label = esc_html($t->display_name);
                if ($t->specialization) $label .= ' (' . esc_html($t->specialization) . ')';
                echo '<option value="'.$t->staff_id.'" '.$sel.'>'.$label.'</option>';
            }
            echo '</select>';

            echo '<button type="submit" name="mtti_tc_action" value="assign"
                style="background:#2e7d32;color:white;border:none;padding:7px 14px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;">
                ✅ Assign
            </button>';

            if ($has) {
                echo '<button type="submit" name="mtti_tc_action" value="unassign"
                    onclick="return confirm(\'Remove teacher from this course?\')"
                    style="background:#d32f2f;color:white;border:none;padding:7px 10px;border-radius:4px;cursor:pointer;font-size:12px;">
                    ✕
                </button>';
            }
            echo '</form>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';

        // Add teacher link
        echo '<div style="margin-top:16px;display:flex;gap:10px;align-items:center;">';
        echo '<a href="'.admin_url('admin.php?page=mtti-mis-staff').'" class="button">👩‍🏫 Manage Teachers</a>';
        echo '<span style="color:#666;font-size:13px;">Need to add a teacher first? Go to Teachers page to create their account.</span>';
        echo '</div>';

        echo '</div>'; // .wrap
    }
}
