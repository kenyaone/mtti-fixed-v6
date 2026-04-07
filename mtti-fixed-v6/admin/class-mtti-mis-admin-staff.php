<?php
/**
 * Staff (Teacher) Management Admin Page
 * Create teachers, assign WordPress user accounts, view their courses
 */
if (!defined('WPINC')) die;

class MTTI_MIS_Admin_Staff {

    public function display() {
        global $wpdb;
        $p      = $wpdb->prefix . 'mtti_';
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $id     = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;
        $msg    = '';

        /* ── SAVE ── */
        if (isset($_POST['mtti_staff_submit']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mtti_staff_action')) {
            $form_action = sanitize_key($_POST['form_action'] ?? 'add');

            // Handle WordPress user creation / linking
            $user_id = intval($_POST['user_id'] ?? 0);
            $create_user = !empty($_POST['create_wp_user']);
            if ($create_user && !$user_id) {
                $email    = sanitize_email($_POST['wp_email'] ?? '');
                $username = sanitize_user($_POST['wp_username'] ?? '');
                $fname    = sanitize_text_field($_POST['first_name'] ?? '');
                $lname    = sanitize_text_field($_POST['last_name'] ?? '');
                if ($email && $username) {
                    $new_uid = wp_create_user($username, wp_generate_password(12), $email);
                    if (!is_wp_error($new_uid)) {
                        wp_update_user(['ID' => $new_uid, 'first_name' => $fname, 'last_name' => $lname,
                                        'display_name' => trim($fname . ' ' . $lname) ?: $username,
                                        'role' => 'mtti_teacher']);
                        $user_id = $new_uid;
                        // Email credentials
                        wp_mail($email,
                            'Your MTTI Staff Login',
                            "Hello {$fname},\n\nYour lecturer account has been created.\nUsername: {$username}\nPlease log in and set your password at: " . wp_login_url() . "\n\nMTTI"
                        );
                    } else {
                        $msg = '<div class="notice notice-error"><p>Could not create user: ' . esc_html($new_uid->get_error_message()) . '</p></div>';
                    }
                }
            }

            $data = [
                'user_id'        => $user_id ?: null,
                'staff_number'   => sanitize_text_field($_POST['staff_number'] ?? ''),
                'id_number'      => sanitize_text_field($_POST['id_number'] ?? ''),
                'department'     => sanitize_text_field($_POST['department'] ?? ''),
                'position'       => sanitize_text_field($_POST['position'] ?? 'Lecturer'),
                'specialization' => sanitize_textarea_field($_POST['specialization'] ?? ''),
                'hire_date'      => sanitize_text_field($_POST['hire_date'] ?? date('Y-m-d')),
                'status'         => sanitize_key($_POST['status'] ?? 'Active'),
            ];

            if ($form_action === 'edit' && $id) {
                $wpdb->update($p . 'staff', $data, ['staff_id' => $id]);
                $msg = '<div class="notice notice-success is-dismissible"><p>Staff member updated.</p></div>';
            } else {
                if (empty($msg)) {
                    if (empty($data['staff_number'])) {
                        // Auto-generate staff number
                        $count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$p}staff")) + 1;
                        $data['staff_number'] = 'MTTI/STF/' . date('Y') . '/' . str_pad($count, 3, '0', STR_PAD_LEFT);
                    }
                    $wpdb->insert($p . 'staff', $data);
                    $msg = '<div class="notice notice-success is-dismissible"><p>Staff member added successfully. Staff No: <strong>' . esc_html($data['staff_number']) . '</strong></p></div>';
                }
            }
            $action = 'list';
        }

        /* ── DELETE ── */
        if ($action === 'delete' && $id && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_staff_' . $id)) {
            // Soft delete staff
            $staff_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}staff WHERE staff_id = %d", $id), ARRAY_A);
            if ($staff_data) {
                require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
                MTTI_MIS_Admin_Trash::create_table();
                $trash = new MTTI_MIS_Admin_Trash();
                $trash->soft_delete('staff', $id, $staff_data['full_name'] ?? 'Staff #' . $id, $staff_data);
            }
            $wpdb->delete($p . 'staff', ['staff_id' => $id]);
            wp_safe_redirect(admin_url('admin.php?page=mtti-mis-staff&deleted=1'));
            exit;
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">👩‍🏫 Staff / Teachers</h1>';
        if ($action === 'list') {
            echo ' <a href="' . admin_url('admin.php?page=mtti-mis-staff&action=add') . '" class="page-title-action">+ Add Teacher</a>';
        }
        echo '<hr class="wp-header-end">';
        if ($msg) echo $msg;
        if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Staff member deleted.</p></div>';

        /* ── LIST ── */
        if ($action === 'list') {
            $staff = $wpdb->get_results(
                "SELECT s.*, u.display_name, u.user_email
                 FROM {$p}staff s
                 LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                 ORDER BY u.display_name ASC, s.staff_number ASC"
            );

            if (!$staff) {
                echo '<div class="notice notice-info"><p>No staff members yet. <a href="' . admin_url('admin.php?page=mtti-mis-staff&action=add') . '">Add your first teacher</a>.</p></div>';
            } else {
                $no_login_count = count(array_filter($staff, fn($s) => empty($s->user_id)));
                if ($no_login_count > 0) {
                    echo '<div class="notice notice-error"><p>';
                    echo '⚠ <strong>' . $no_login_count . ' teacher' . ($no_login_count>1?'s have':' has') . ' no WordPress login</strong> — ';
                    echo 'they cannot access the Lecturer Portal until you <strong>Edit</strong> their record and create or link a login account.';
                    echo '</p></div>';
                }
                echo '<table class="widefat striped">
                    <thead><tr>
                        <th>Staff No</th><th>Name</th><th>Email</th>
                        <th>Position</th><th>Department</th>
                        <th>Courses Assigned</th><th>Status</th><th>Actions</th>
                    </tr></thead><tbody>';

                foreach ($staff as $s) {
                    // Count courses assigned via course_teachers table
                    $course_count = intval($wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$p}course_teachers WHERE staff_id=%d",
                        $s->staff_id
                    )));
                    $has_login    = !empty($s->user_id) && !empty($s->display_name);
                    $status_color = $s->status === 'Active' ? '#2e7d32' : '#d32f2f';
                    $edit_url     = admin_url('admin.php?page=mtti-mis-staff&action=edit&staff_id=' . $s->staff_id);
                    $del_url      = wp_nonce_url(admin_url('admin.php?page=mtti-mis-staff&action=delete&staff_id=' . $s->staff_id), 'delete_staff_' . $s->staff_id);

                    echo '<tr' . (!$has_login ? ' style="background:#fff8f8;"' : '') . '>';
                    echo '<td><strong>' . esc_html($s->staff_number) . '</strong></td>';

                    // Name + warning if no WP login
                    echo '<td>' . esc_html($s->display_name ?: '⚠ No name') ;
                    if (!$has_login) {
                        echo '<br><span style="color:#d32f2f;font-size:11px;font-weight:700;">⚠ No WordPress login — cannot access portal</span>';
                        echo '<br><a href="' . esc_url($edit_url) . '" style="font-size:11px;">Link or create account →</a>';
                    }
                    echo '</td>';

                    echo '<td>' . esc_html($s->user_email ?: '—') . '</td>';
                    echo '<td>' . esc_html($s->position ?: 'Lecturer') . '</td>';
                    echo '<td>' . esc_html($s->department ?: '—') . '</td>';
                    echo '<td>';
                    if ($course_count > 0) {
                        echo '<a href="' . admin_url('admin.php?page=mtti-mis-enrollments') . '" style="font-weight:700;">' . $course_count . ' course' . ($course_count!=1?'s':'') . '</a>';
                    } else {
                        echo '<span style="color:#d32f2f;font-size:12px;">None assigned</span>';
                    }
                    echo '</td>';
                    echo '<td><span style="color:' . $status_color . ';font-weight:700;">' . esc_html($s->status) . '</span></td>';
                    echo '<td>
                        <a href="' . esc_url($edit_url) . '" class="button button-small">✏ Edit</a>
                        <a href="' . esc_url($del_url) . '" class="button button-small" style="color:#d63638;" onclick="return confirm(\'Delete this staff member?\')">🗑</a>
                    </td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            // ── HOW TO ASSIGN TEACHER TO A CLASS ──
            echo '<div class="card" style="margin-top:24px;padding:20px;max-width:700px;border-left:4px solid #1976D2;">';
            echo '<h3 style="margin-top:0;">📌 How to Assign a Teacher to a Course</h3>';
            echo '<ol style="margin:0;padding-left:20px;line-height:1.9;">';
            echo '<li>Add the teacher here first using <strong>+ Add Teacher</strong> above</li>';
            echo '<li>Go to <a href="' . admin_url('admin.php?page=mtti-mis-enrollments') . '"><strong>📋 Teacher → Course</strong></a></li>';
            echo '<li>Find the course, select the teacher from the dropdown, click <strong>Assign</strong></li>';
            echo '<li>The teacher will see that course in their <strong>Lecturer Portal</strong> immediately</li>';
            echo '</ol>';
            echo '<a href="' . admin_url('admin.php?page=mtti-mis-enrollments') . '" class="button button-primary" style="margin-top:10px;">📋 Assign Teachers to Courses →</a>';
            echo '</div>';
        }

        /* ── ADD / EDIT FORM ── */
        elseif ($action === 'add' || $action === 'edit') {
            $s = null;
            $linked_user = null;
            if ($action === 'edit' && $id) {
                $s = $wpdb->get_row($wpdb->prepare(
                    "SELECT s.*, u.display_name, u.user_email FROM {$p}staff s
                     LEFT JOIN {$wpdb->users} u ON s.user_id=u.ID WHERE s.staff_id=%d", $id
                ));
                if ($s && $s->user_id) {
                    $linked_user = get_userdata($s->user_id);
                }
            }

            // Get all WordPress users for linking
            $wp_users = get_users(['role__in' => ['administrator','editor','mtti_teacher','mtti_systems_admin','subscriber'], 'number' => 200]);

            echo '<div style="max-width:700px;">';
            echo '<form method="post">';
            wp_nonce_field('mtti_staff_action');
            echo '<input type="hidden" name="mtti_staff_submit" value="1">';
            echo '<input type="hidden" name="form_action" value="' . $action . '">';
            if ($id) echo '<input type="hidden" name="staff_id" value="' . $id . '">';

            echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">👤 ' . ($action === 'edit' ? 'Edit Staff Member' : 'Add New Teacher') . '</h2></div>';
            echo '<div class="inside">';

            echo '<table class="form-table">';

            // WordPress account linking
            echo '<tr><th>WordPress Login Account</th><td>';
            echo '<select name="user_id" id="user_id_select" style="min-width:280px;">';
            echo '<option value="">— No account linked —</option>';
            foreach ($wp_users as $u) {
                $sel = ($s && $s->user_id == $u->ID) ? 'selected' : '';
                echo '<option value="' . $u->ID . '" ' . $sel . '>' . esc_html($u->display_name . ' (' . $u->user_email . ')') . '</option>';
            }
            echo '</select>';
            echo '<p class="description">Link to existing WordPress user, or create a new one below.</p>';
            echo '</td></tr>';

            // Create new user option
            if ($action === 'add') {
                echo '<tr><th></th><td>';
                echo '<label><input type="checkbox" name="create_wp_user" id="create_wp_user" onchange="document.getElementById(\'new_user_fields\').style.display=this.checked?\'table-row-group\':\' none\'"> Create a new WordPress account for this teacher</label>';
                echo '</td></tr>';
                echo '<tbody id="new_user_fields" style="display:none;">';
                echo '<tr><th>First Name</th><td><input type="text" name="first_name" class="regular-text" placeholder="First name"></td></tr>';
                echo '<tr><th>Last Name</th><td><input type="text" name="last_name" class="regular-text" placeholder="Last name"></td></tr>';
                echo '<tr><th>Username</th><td><input type="text" name="wp_username" class="regular-text" placeholder="e.g. john.doe"></td></tr>';
                echo '<tr><th>Email</th><td><input type="email" name="wp_email" class="regular-text" placeholder="teacher@email.com"><p class="description">Login credentials will be emailed to this address.</p></td></tr>';
                echo '</tbody>';
            }

            // Staff fields
            $fields = [
                'staff_number'   => ['Staff Number', 'text', 'Auto-generated if left blank (e.g. MTTI/STF/2026/001)'],
                'id_number'      => ['National ID No', 'text', ''],
                'position'       => ['Position', 'text', 'e.g. Lecturer, HOD, Principal'],
                'department'     => ['Department', 'text', 'e.g. ICT, Business, Engineering'],
                'hire_date'      => ['Hire Date', 'date', ''],
                'specialization' => ['Specialization', 'textarea', 'Subjects / skills they teach'],
            ];
            foreach ($fields as $fname => [$label, $type, $desc]) {
                $val = $s ? esc_attr($s->$fname ?? '') : '';
                echo '<tr><th>' . $label . '</th><td>';
                if ($type === 'textarea') {
                    echo '<textarea name="' . $fname . '" rows="2" class="large-text" style="max-width:400px;">' . $val . '</textarea>';
                } else {
                    echo '<input type="' . $type . '" name="' . $fname . '" value="' . $val . '" class="regular-text">';
                }
                if ($desc) echo '<p class="description">' . $desc . '</p>';
                echo '</td></tr>';
            }

            // Status
            echo '<tr><th>Status</th><td><select name="status">';
            foreach (['Active', 'Inactive', 'On Leave'] as $st) {
                $sel = ($s && $s->status === $st) ? 'selected' : ($st === 'Active' && !$s ? 'selected' : '');
                echo '<option ' . $sel . '>' . $st . '</option>';
            }
            echo '</select></td></tr>';

            echo '</table>';
            submit_button($action === 'edit' ? 'Update Staff Member' : 'Add Staff Member');
            echo '<a href="' . admin_url('admin.php?page=mtti-mis-staff') . '" class="button">Cancel</a>';
            echo '</div></div></form></div>';
        }

        /* ── COURSES VIEW ── */
        elseif ($action === 'courses') {
            $s = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, u.display_name FROM {$p}staff s LEFT JOIN {$wpdb->users} u ON s.user_id=u.ID WHERE s.staff_id=%d", $id
            ));
            if (!$s) { echo '<div class="notice notice-error"><p>Staff not found.</p></div>'; }
            else {
                echo '<h2>' . esc_html($s->display_name ?: $s->staff_number) . ' — Assigned Courses</h2>';

                $courses = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.course_id, c.course_code, c.course_name, c.duration_weeks,
                            COUNT(DISTINCT e.student_id) as student_count,
                            COUNT(DISTINCT sw.week_id) as weeks_planned,
                            SUM(sw.status='Completed') as weeks_done
                     FROM {$p}enrollments e
                     JOIN {$p}courses c ON e.course_id=c.course_id
                     LEFT JOIN {$p}scheme_of_work sw ON sw.course_id=c.course_id
                     WHERE e.staff_id=%d AND e.status IN ('Active','Enrolled','In Progress')
                     GROUP BY c.course_id",
                    $id
                ));

                if (!$courses) {
                    echo '<div class="notice notice-info"><p>No courses assigned yet. Assign via <a href="' . admin_url('admin.php?page=mtti-mis-enrollments') . '">Enrollments</a>.</p></div>';
                } else {
                    echo '<table class="widefat striped"><thead><tr>
                        <th>Course</th><th>Students</th><th>Duration</th><th>Scheme Progress</th><th>Actions</th>
                    </tr></thead><tbody>';
                    foreach ($courses as $c) {
                        $pct = $c->weeks_planned > 0 ? round(($c->weeks_done / max($c->weeks_planned, $c->duration_weeks)) * 100) : 0;
                        $bar_color = $pct >= 80 ? '#2e7d32' : ($pct >= 40 ? '#f57c00' : '#9e9e9e');
                        echo '<tr>';
                        echo '<td><strong>' . esc_html($c->course_code) . '</strong><br><span style="font-size:12px;">' . esc_html($c->course_name) . '</span></td>';
                        echo '<td>' . $c->student_count . '</td>';
                        echo '<td>' . $c->duration_weeks . ' wks</td>';
                        echo '<td style="min-width:160px;">';
                        echo '<div style="display:flex;align-items:center;gap:8px;">';
                        echo '<div style="flex:1;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;"><div style="height:100%;width:' . $pct . '%;background:' . $bar_color . ';"></div></div>';
                        echo '<span style="font-size:12px;font-weight:700;color:' . $bar_color . ';">' . $pct . '%</span>';
                        echo '</div>';
                        echo '<div style="font-size:11px;color:#666;">' . $c->weeks_done . '/' . max($c->weeks_planned, $c->duration_weeks) . ' weeks done</div>';
                        echo '</td>';
                        echo '<td><a href="' . admin_url('admin.php?page=mtti-mis-scheme&action=view&course_id=' . $c->course_id) . '" class="button button-small">📋 Scheme</a></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }
                echo '<p><a href="' . admin_url('admin.php?page=mtti-mis-staff') . '">&larr; Back to Staff</a></p>';
            }
        }

        echo '</div>'; // .wrap
    }
}
