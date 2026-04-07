<?php
/**
 * Plugin Name: MTTI Management Information System
 * Plugin URI: https://mtti.ac.ke
 * Description: Complete Management Information System for Masomotele Technical Training Institute - Students, Courses, Course Units, Enrollments, Attendance, Assessments, Payments, Assignments, Live Classes, Certificates with Verification. Marks entry via Course Units only. Now with PWA support, Materials Download, Payment Audit Trail, Role Permissions Manager, Lessons for Teachers, and Admission Letters Generator!
 * Version: 7.3.0
 * Author: MTTI
 * Author URI: https://mtti.ac.ke
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: mtti-mis
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Plugin version
define('MTTI_MIS_VERSION', '7.3.0');
define('MTTI_MIS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MTTI_MIS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MTTI_MIS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load PWA Support
require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-pwa.php';

add_action('plugins_loaded', 'mtti_mis_check_upgrade', 1);

add_action('plugins_loaded', 'mtti_mis_register_scheme_hooks', 5);
function mtti_mis_register_scheme_hooks() {
    if (!class_exists('MTTI_MIS_Admin_Scheme')) {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-scheme.php';
    }
    MTTI_MIS_Admin_Scheme::register_ajax_hooks();
}

add_action('plugins_loaded', 'mtti_mis_register_interactive_hooks', 5);
function mtti_mis_register_interactive_hooks() {
    if (!class_exists('MTTI_MIS_Admin_Interactive')) {
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-interactive.php';
    }
    MTTI_MIS_Admin_Interactive::register_hooks();
}

function mtti_mis_check_upgrade() {
    $current_db_version = get_option('mtti_mis_db_version', '1.0.0');
    if (version_compare($current_db_version, '4.0.0', '<')) {
        require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-upgrader.php';
        MTTI_MIS_Upgrader::upgrade();
    }
}

add_action('init', 'mtti_mis_handle_document_output', 1);
function mtti_mis_handle_document_output() {
    if (!is_admin()) {
        return;
    }
    if (isset($_GET['page']) && $_GET['page'] === 'mtti-mis-lessons') {
        if (isset($_POST['mtti_lesson_submit']) && isset($_POST['mtti_lesson_nonce'])) {
            if (wp_verify_nonce($_POST['mtti_lesson_nonce'], 'mtti_lesson_action')) {
                mtti_mis_process_lesson_form();
                exit;
            }
        }
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['lesson_id'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_lesson_' . $_GET['lesson_id'])) {
                mtti_mis_process_lesson_delete();
                exit;
            }
        }
    }
    if (isset($_GET['page']) && $_GET['page'] === 'mtti-mis-admission-letters') {
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
        if (($action === 'preview' || $action === 'print') && $student_id > 0) {
            while (ob_get_level()) { ob_end_clean(); }
            mtti_mis_output_admission_letter($student_id);
            exit;
        }
    }
    if (!isset($_GET['page']) || $_GET['page'] !== 'mtti-mis-certificates') {
        return;
    }
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action === 'bulk-print-transcripts' && isset($_GET['items'])) {
        while (ob_get_level()) { ob_end_clean(); }
        mtti_mis_output_bulk_transcripts($_GET['items']);
        exit;
    }
    if ($action === 'bulk-print-certificates' && isset($_GET['items'])) {
        while (ob_get_level()) { ob_end_clean(); }
        $completion_date = isset($_GET['completion_date']) ? sanitize_text_field($_GET['completion_date']) : date('Y-m-d');
        mtti_mis_output_bulk_certificates($_GET['items'], $completion_date);
        exit;
    }
    if ($action === 'unit-transcript' && isset($_GET['unit_id']) && isset($_GET['student_id'])) {
        while (ob_get_level()) { ob_end_clean(); }
        mtti_mis_output_unit_transcript(intval($_GET['unit_id']), intval($_GET['student_id']));
        exit;
    }
    if ($action === 'transcript' && isset($_GET['student_id'])) {
        while (ob_get_level()) { ob_end_clean(); }
        mtti_mis_output_transcript(intval($_GET['student_id']));
        exit;
    }
    if (isset($_POST['certificate_submit'])) {
        if (isset($_POST['certificate_nonce']) && wp_verify_nonce($_POST['certificate_nonce'], 'generate_certificate')) {
            while (ob_get_level()) { ob_end_clean(); }
            mtti_mis_output_certificate();
            exit;
        }
    }
}

function mtti_mis_process_lesson_form() {
    global $wpdb;
    $table = $wpdb->prefix . 'mtti_lessons';
    $form_action = sanitize_text_field($_POST['form_action']);
    $content_url = '';
    $content_type = 'text';
    $file_size = 0;
    if (!empty($_POST['video_url'])) {
        $content_url = esc_url_raw($_POST['video_url']);
        $content_type = 'video';
    } elseif (!empty($_FILES['lesson_file']['name'])) {
        $upload = wp_handle_upload($_FILES['lesson_file'], array('test_form' => false));
        if (isset($upload['url'])) {
            $content_url = $upload['url'];
            $ext = strtolower(pathinfo($upload['file'], PATHINFO_EXTENSION));
            $file_size = filesize($upload['file']);
            if (in_array($ext, array('mp4', 'webm', 'ogg', 'mov'))) { $content_type = 'video'; }
            elseif (in_array($ext, array('pdf'))) { $content_type = 'pdf'; }
            elseif (in_array($ext, array('doc', 'docx'))) { $content_type = 'document'; }
            elseif (in_array($ext, array('ppt', 'pptx'))) { $content_type = 'presentation'; }
            elseif (in_array($ext, array('mp3', 'wav', 'ogg'))) { $content_type = 'audio'; }
            else { $content_type = 'file'; }
        }
    }
    $data = array(
        'course_id' => intval($_POST['course_id']),
        'unit_id' => !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : null,
        'title' => sanitize_text_field($_POST['title']),
        'description' => sanitize_textarea_field($_POST['description']),
        'content' => wp_kses_post($_POST['content']),
        'content_type' => $content_type,
        'content_url' => $content_url,
        'file_size' => $file_size,
        'duration_minutes' => !empty($_POST['duration_minutes']) ? intval($_POST['duration_minutes']) : null,
        'order_number' => intval($_POST['order_number']),
        'is_free_preview' => isset($_POST['is_free_preview']) ? 1 : 0,
        'status' => sanitize_text_field($_POST['status']),
        'created_by' => get_current_user_id()
    );
    if ($form_action === 'add') {
        $wpdb->insert($table, $data);
        $message = 'created';
    } else {
        $lesson_id = intval($_POST['lesson_id']);
        if (empty($content_url)) {
            unset($data['content_url']);
            unset($data['content_type']);
            unset($data['file_size']);
        }
        $wpdb->update($table, $data, array('lesson_id' => $lesson_id));
        $message = 'updated';
    }
    wp_safe_redirect(admin_url('admin.php?page=mtti-mis-lessons&message=' . $message));
    exit;
}

function mtti_mis_process_lesson_delete() {
    global $wpdb;
    $table = $wpdb->prefix . 'mtti_lessons';
    $lesson_id = intval($_GET['lesson_id']);
    $wpdb->delete($table, array('lesson_id' => $lesson_id));
    wp_safe_redirect(admin_url('admin.php?page=mtti-mis-lessons&message=deleted'));
    exit;
}

function mtti_mis_output_transcript($student_id) {
    global $wpdb;
    $students_table = $wpdb->prefix . 'mtti_students';
    $student = $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, u.display_name, u.user_email FROM {$students_table} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID WHERE s.student_id = %d",
        $student_id
    ));
    if (!$student) { wp_die('Student not found'); }
    $unit_results_table = $wpdb->prefix . 'mtti_unit_results';
    $units_table = $wpdb->prefix . 'mtti_course_units';
    $courses_table = $wpdb->prefix . 'mtti_courses';
    $unit_results = $wpdb->get_results($wpdb->prepare(
        "SELECT ur.*, cu.unit_name, cu.unit_code, cu.duration_hours, cu.unit_id, c.course_name, c.course_code, c.course_id
         FROM {$unit_results_table} ur
         LEFT JOIN {$units_table} cu ON ur.unit_id = cu.unit_id
         LEFT JOIN {$courses_table} c ON cu.course_id = c.course_id
         WHERE ur.student_id = %d ORDER BY c.course_name, cu.order_number, cu.unit_code",
        $student_id
    ));
    $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Student Record - <?php echo esc_html($student->display_name); ?></title>
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #333; background: #f0f0f0; padding: 20px; } .container { max-width: 900px; margin: 0 auto; background: white; padding: 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); } .header { text-align: center; border-bottom: 4px solid #2E7D32; padding-bottom: 20px; margin-bottom: 30px; } .logo { width: 80px; margin-bottom: 10px; } .header h1 { color: #2E7D32; font-size: 24px; } .header h2 { color: #1976D2; font-size: 20px; margin-top: 15px; } .section-title { color: #2E7D32; font-size: 16px; font-weight: bold; margin: 25px 0 15px; padding-bottom: 5px; border-bottom: 2px solid #2E7D32; } .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px; } .info-label { font-weight: bold; min-width: 140px; color: #555; } table { width: 100%; border-collapse: collapse; margin: 15px 0; } thead { background: #2E7D32; color: white; } th, td { padding: 10px 8px; text-align: left; border: 1px solid #ddd; } tbody tr:nth-child(even) { background: #f9f9f9; } .course-header { background: #e8f5e9 !important; } .course-header td { font-weight: bold; color: #1B5E20; border-top: 2px solid #2E7D32; } .passed { color: #2E7D32; font-weight: bold; } .failed { color: #D32F2F; font-weight: bold; } .cat-distinction { color: #fff; background: #2E7D32; font-weight: bold; text-align: center; } .cat-credit { color: #fff; background: #1976D2; font-weight: bold; text-align: center; } .cat-pass { color: #fff; background: #FF9800; font-weight: bold; text-align: center; } .cat-refer { color: #fff; background: #D32F2F; font-weight: bold; text-align: center; } .motto { color: #FF9800; font-style: italic; text-align: center; margin-top: 30px; font-weight: bold; } .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 10px; color: #666; text-align: center; } .print-btn { position: fixed; top: 20px; right: 20px; padding: 12px 24px; background: #2E7D32; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; } .transcript-btn { display: inline-block; padding: 6px 12px; background: #1976D2; color: white; text-decoration: none; border-radius: 4px; font-size: 11px; font-weight: bold; } @media print { .no-print { display: none !important; } }</style>
    </head><body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Print</button>
    <div class="container">
        <div class="header"><img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" class="logo"><h1>Masomotele Technical Training Institute</h1><p>Sagaas Center, Eldoret, Kenya</p><h2>Student Academic Record</h2></div>
        <div class="section-title">Student Information</div>
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Student Name:</span><span><?php echo esc_html($student->display_name); ?></span></div>
            <div class="info-item"><span class="info-label">Admission Number:</span><span><?php echo esc_html($student->admission_number); ?></span></div>
            <div class="info-item"><span class="info-label">Email:</span><span><?php echo esc_html($student->user_email); ?></span></div>
            <div class="info-item"><span class="info-label">Date Generated:</span><span><?php echo date('F j, Y'); ?></span></div>
        </div>
        <div class="section-title">Completed Units</div>
        <table><thead><tr><th>Unit Code</th><th>Unit Name</th><th>Hours</th><th>Grade</th><th>Category</th><th>Status</th><th class="no-print">Transcript</th></tr></thead>
        <tbody>
        <?php if (empty($unit_results)) : ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#666;">No unit results found.</td></tr>
        <?php else : $current_course = ''; foreach ($unit_results as $r) :
            if ($current_course != $r->course_name && !empty($r->course_name)) { $current_course = $r->course_name; ?>
            <tr class="course-header"><td colspan="7"><?php echo esc_html($r->course_code . ': ' . $r->course_name); ?></td></tr>
        <?php } $pct = isset($r->percentage) ? floatval($r->percentage) : 0;
            $grade_category = 'REFER'; $cat_class = 'cat-refer';
            if ($pct >= 80) { $grade_category = 'DISTINCTION'; $cat_class = 'cat-distinction'; }
            elseif ($pct >= 60) { $grade_category = 'CREDIT'; $cat_class = 'cat-credit'; }
            elseif ($pct >= 50) { $grade_category = 'PASS'; $cat_class = 'cat-pass'; } ?>
            <tr>
                <td><strong><?php echo esc_html($r->unit_code); ?></strong></td>
                <td><?php echo esc_html($r->unit_name); ?></td>
                <td><?php echo $r->duration_hours ? intval($r->duration_hours) : '-'; ?></td>
                <td><?php echo esc_html($r->grade); ?></td>
                <td class="<?php echo $cat_class; ?>"><?php echo $grade_category; ?></td>
                <td class="<?php echo $r->passed ? 'passed' : 'failed'; ?>"><?php echo $r->passed ? 'Passed' : 'Failed'; ?></td>
                <td class="no-print"><a href="<?php echo admin_url('admin.php?page=mtti-mis-certificates&action=unit-transcript&unit_id=' . $r->unit_id . '&student_id=' . $student_id); ?>" class="transcript-btn" target="_blank">📄 View</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
        <p class="motto">"Start Learning, Start Earning"</p>
        <div class="footer"><p>Masomotele Technical Training Institute | Generated: <?php echo date('F j, Y g:i A'); ?></p></div>
    </div></body></html><?php exit;
}

function mtti_mis_output_unit_transcript($unit_id, $student_id) {
    global $wpdb;
    $students_table = $wpdb->prefix . 'mtti_students';
    $student = $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, u.display_name, u.user_email FROM {$students_table} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID WHERE s.student_id = %d", $student_id
    ));
    if (!$student) { wp_die('Student not found'); }
    $unit_results_table = $wpdb->prefix . 'mtti_unit_results';
    $units_table = $wpdb->prefix . 'mtti_course_units';
    $courses_table = $wpdb->prefix . 'mtti_courses';
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT ur.*, cu.unit_name, cu.unit_code, cu.duration_hours, cu.description as unit_description, cu.credit_hours, c.course_name, c.course_code, c.duration_weeks as course_duration
         FROM {$unit_results_table} ur LEFT JOIN {$units_table} cu ON ur.unit_id = cu.unit_id LEFT JOIN {$courses_table} c ON cu.course_id = c.course_id
         WHERE ur.unit_id = %d AND ur.student_id = %d", $unit_id, $student_id
    ));
    if (!$result) { wp_die('Unit result not found'); }
    $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
    $transcript_number = 'MTTI/TR/' . date('Y') . '/' . str_pad($unit_id, 4, '0', STR_PAD_LEFT) . '/' . str_pad($student_id, 4, '0', STR_PAD_LEFT);
    $pct = isset($result->percentage) ? floatval($result->percentage) : 0;
    $grade_category = 'REFER'; $category_color = '#D32F2F';
    if ($pct >= 80) { $grade_category = 'DISTINCTION'; $category_color = '#2E7D32'; }
    elseif ($pct >= 60) { $grade_category = 'CREDIT'; $category_color = '#1976D2'; }
    elseif ($pct >= 50) { $grade_category = 'PASS'; $category_color = '#FF9800'; }
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Unit Transcript - <?php echo esc_html($result->unit_name); ?></title>
    <style>* { margin:0;padding:0;box-sizing:border-box; } @page{size:A4 portrait;margin:0;} @media print{.no-print{display:none!important;}html,body{background:white;print-color-adjust:exact;-webkit-print-color-adjust:exact;margin:0;padding:0;}.transcript-container{box-shadow:none;border:none;margin:10mm;width:calc(210mm - 20mm);height:calc(297mm - 20mm);}} body{font-family:Arial,sans-serif;font-size:12px;line-height:1.4;color:#333;background:#f0f0f0;padding:20px;} .transcript-container{width:190mm;height:277mm;margin:0 auto;background:white;padding:10mm;box-shadow:0 2px 15px rgba(0,0,0,0.15);border:2px solid #2E7D32;position:relative;overflow:hidden;} .watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:120px;color:rgba(46,125,50,0.04);font-weight:bold;pointer-events:none;z-index:0;} .content{position:relative;z-index:1;height:100%;display:flex;flex-direction:column;} .header{text-align:center;padding-bottom:10px;margin-bottom:10px;border-bottom:2px solid #2E7D32;} .logo{width:60px;margin-bottom:8px;} .header h1{color:#2E7D32;font-size:18px;} .header h2{color:#1976D2;font-size:15px;margin-top:8px;} .section{margin:8px 0;} .section-title{color:#2E7D32;font-size:11px;font-weight:bold;margin-bottom:5px;padding-bottom:3px;border-bottom:1px solid #ddd;text-transform:uppercase;} .info-row{display:flex;justify-content:space-between;padding:3px 0;font-size:11px;} .info-label{font-weight:bold;color:#555;} .unit-box{background:linear-gradient(135deg,#e8f5e9 0%,#c8e6c9 100%);border:1px solid #4CAF50;border-radius:6px;padding:12px;margin:12px 0;text-align:center;} .unit-name{font-size:18px;font-weight:bold;color:#1B5E20;margin:5px 0;} .grade-box{background:white;border:3px solid <?php echo $category_color; ?>;border-radius:8px;padding:18px 15px;margin:12px auto;max-width:240px;text-align:center;} .grade-category-main{font-size:28px;font-weight:bold;color:<?php echo $category_color; ?>;letter-spacing:3px;margin:6px 0;} .grade-score{font-size:15px;color:#555;margin:4px 0;} .status-badge{display:inline-block;padding:5px 15px;border-radius:15px;font-weight:bold;font-size:12px;margin-top:10px;} .status-passed{background:#e8f5e9;color:#2E7D32;border:1px solid #4CAF50;} .status-failed{background:#ffebee;color:#c62828;border:1px solid #ef5350;} .spacer{flex:1;} .signatures{display:flex;justify-content:space-around;margin-top:20px;} .signature{text-align:center;} .signature-line{border-top:1px solid #333;width:120px;margin:0 auto 5px auto;} .signature-title{font-size:10px;color:#666;} .motto{color:#FF9800;font-style:italic;text-align:center;margin-top:12px;font-weight:bold;font-size:12px;} .footer{margin-top:10px;padding-top:8px;border-top:1px solid #ddd;font-size:9px;color:#888;text-align:center;} .print-btn{position:fixed;top:20px;right:20px;padding:10px 20px;background:#2E7D32;color:white;border:none;border-radius:4px;cursor:pointer;font-size:14px;} .back-btn{position:fixed;top:20px;right:160px;padding:10px 20px;background:#1976D2;color:white;border:none;border-radius:4px;cursor:pointer;font-size:14px;text-decoration:none;}</style>
    </head><body>
    <a href="<?php echo admin_url('admin.php?page=mtti-mis-certificates&action=transcript&student_id=' . $student_id); ?>" class="back-btn no-print">← Back</a>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Print</button>
    <div class="transcript-container"><div class="watermark">MTTI</div>
    <div class="content">
        <div class="header"><img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" class="logo"><h1>MASOMOTELE TECHNICAL TRAINING INSTITUTE</h1><p>Sagaas Center, Fourth Floor, Eldoret, Kenya · TVETA Accredited</p><h2>Official Unit Transcript</h2></div>
        <div class="section"><div class="section-title">Student Information</div>
            <div class="info-row"><span class="info-label">Student Name:</span><span><strong><?php echo esc_html($student->display_name); ?></strong></span></div>
            <div class="info-row"><span class="info-label">Admission Number:</span><span><?php echo esc_html($student->admission_number); ?></span></div>
        </div>
        <div class="section"><div class="section-title">Course</div>
            <div class="info-row"><span class="info-label">Course:</span><span><strong><?php echo esc_html($result->course_name); ?></strong> (<?php echo esc_html($result->course_code); ?>)</span></div>
        </div>
        <div class="unit-box"><div style="font-size:10px;color:#666;"><?php echo esc_html($result->unit_code); ?></div><div class="unit-name"><?php echo esc_html($result->unit_name); ?></div><?php if ($result->duration_hours) : ?><div style="color:#666;font-size:11px;">Duration: <?php echo intval($result->duration_hours); ?> Hours</div><?php endif; ?></div>
        <div class="grade-box"><div style="font-size:9px;color:#666;text-transform:uppercase;letter-spacing:2px;">Final Grade Achieved</div><div class="grade-category-main"><?php echo esc_html($grade_category); ?></div><div class="grade-score">Score: <strong><?php echo number_format($result->percentage, 1); ?>%</strong></div><div class="status-badge <?php echo $result->passed ? 'status-passed' : 'status-failed'; ?>"><?php echo $result->passed ? '✓ PASSED' : '✗ NOT PASSED'; ?></div></div>
        <div class="section"><div class="section-title">Assessment Details</div>
            <div class="info-row"><span class="info-label">Assessment Date:</span><span><?php echo date('F j, Y', strtotime($result->result_date)); ?></span></div>
            <?php if ($result->remarks) : ?><div class="info-row"><span class="info-label">Remarks:</span><span><?php echo esc_html($result->remarks); ?></span></div><?php endif; ?>
        </div>
        <div class="spacer"></div>
        <div class="signatures"><div class="signature"><div class="signature-line"></div><p class="signature-title">Principal/Director</p></div><div class="signature"><div class="signature-line"></div><p class="signature-title">Registrar</p></div></div>
        <p class="motto">"Start Learning, Start Earning"</p>
        <div class="footer"><p>Ref: <?php echo esc_html($transcript_number); ?> | Generated: <?php echo date('F j, Y'); ?></p></div>
    </div></div></body></html><?php exit;
}

function mtti_mis_output_certificate() {
    global $wpdb;
    $student_id = intval($_POST['student_id']);
    $course_id = intval($_POST['course_id']);
    $grade = sanitize_text_field($_POST['grade']);
    $completion_date = sanitize_text_field($_POST['completion_date']);
    $students_table = $wpdb->prefix . 'mtti_students';
    $student = $wpdb->get_row($wpdb->prepare("SELECT s.*, u.display_name, u.user_email FROM {$students_table} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID WHERE s.student_id = %d", $student_id));
    $courses_table = $wpdb->prefix . 'mtti_courses';
    $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$courses_table} WHERE course_id = %d", $course_id));
    if (!$student || !$course) { wp_die('Invalid student or course'); }
    $cert_number = 'MTTI/CERT/' . date('Y') . '/' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $verification_code = '';
    for ($i = 0; $i < 3; $i++) { if ($i > 0) $verification_code .= '-'; for ($j = 0; $j < 4; $j++) $verification_code .= $chars[mt_rand(0, strlen($chars) - 1)]; }
    $cert_table = $wpdb->prefix . 'mtti_certificates';
    $wpdb->insert($cert_table, array('certificate_number' => $cert_number, 'verification_code' => $verification_code, 'student_id' => $student->student_id, 'student_name' => $student->display_name, 'admission_number' => $student->admission_number, 'course_id' => $course->course_id, 'course_name' => $course->course_name, 'course_code' => $course->course_code, 'grade' => $grade, 'completion_date' => $completion_date, 'issue_date' => current_time('mysql'), 'status' => 'Valid', 'created_at' => current_time('mysql')));
    $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Certificate - <?php echo esc_html($student->display_name); ?></title>
    <style>* { margin:0;padding:0;box-sizing:border-box; } @page{size:A4 landscape;margin:0;} @media print{html,body{margin:0;padding:0;width:297mm;height:210mm;}.no-print{display:none!important;}.certificate-wrapper{padding:5mm;width:297mm;height:210mm;}.certificate{height:calc(210mm - 10mm);}} body{font-family:Georgia,serif;background:#f0f0f0;padding:15px;-webkit-print-color-adjust:exact;print-color-adjust:exact;} .certificate-wrapper{width:297mm;height:210mm;max-width:100%;margin:0 auto;background:white;padding:5mm;} .certificate{background:white;border:12px solid #2E7D32;padding:10px;text-align:center;height:100%;display:flex;flex-direction:column;} .inner-border{border:2px solid #FF9800;padding:20px 40px;flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;} .logo{width:70px;margin-bottom:10px;} h1{color:#2E7D32;font-size:28px;margin:8px 0;} .cert-title{font-size:22px;color:#2E7D32;margin:15px 0;text-transform:uppercase;letter-spacing:3px;font-weight:bold;} .student-name{font-size:36px;color:#1976D2;margin:10px 0;font-weight:bold;border-bottom:2px solid #FF9800;display:inline-block;padding-bottom:5px;} .course-name{font-size:24px;color:#2E7D32;margin:8px 0;font-weight:bold;} .details{font-size:14px;color:#333;margin:15px 0;line-height:1.6;} .details strong{color:#FF9800;} .signatures{display:flex;justify-content:center;gap:120px;margin:20px 0;} .signature{text-align:center;} .signature-line{border-top:1px solid #333;width:150px;margin:0 auto 5px auto;} .signature-title{font-size:12px;color:#666;} .motto{color:#FF9800;font-style:italic;font-size:14px;margin:15px 0;font-weight:bold;} .cert-footer{display:flex;justify-content:space-between;align-items:center;font-size:10px;color:#666;padding-top:10px;border-top:1px solid #ddd;margin-top:10px;width:100%;} .print-btn{position:fixed;top:10px;right:10px;padding:10px 20px;background:#2E7D32;color:white;border:none;border-radius:4px;cursor:pointer;font-size:14px;} .print-instructions{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 20px;margin:0 auto 15px auto;max-width:297mm;text-align:center;font-family:Arial,sans-serif;font-size:14px;}</style>
    </head><body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Print</button>
    <div class="print-instructions no-print"><strong>⚠️ IMPORTANT:</strong> When printing, select <strong>LANDSCAPE</strong> orientation and margins <strong>None</strong>.</div>
    <div class="certificate-wrapper"><div class="certificate"><div class="inner-border">
        <img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" class="logo">
        <h1>Masomotele Technical Training Institute</h1><p style="color:#666;font-size:14px;">Sagaas Center, Eldoret, Kenya</p>
        <div class="cert-title">Certificate of Completion</div>
        <p style="font-size:14px;margin:12px 0 8px 0;">This is to certify that</p>
        <div class="student-name"><?php echo esc_html($student->display_name); ?></div>
        <p style="font-size:12px;color:#666;margin:5px 0 15px 0;">Admission Number: <?php echo esc_html($student->admission_number); ?></p>
        <p style="font-size:14px;margin-bottom:8px;">has successfully completed the course</p>
        <div class="course-name"><?php echo esc_html($course->course_name); ?></div>
        <p style="font-size:14px;color:#666;margin:5px 0 12px 0;">(<?php echo esc_html($course->course_code); ?>)</p>
        <div class="details">Grade Achieved: <strong><?php echo esc_html($grade); ?></strong> &nbsp;|&nbsp; Date of Completion: <strong><?php echo date('F j, Y', strtotime($completion_date)); ?></strong></div>
        <div class="signatures"><div class="signature"><div class="signature-line"></div><p class="signature-title">Director</p></div><div class="signature"><div class="signature-line"></div><p class="signature-title">Registrar</p></div></div>
        <p class="motto">"Start Learning, Start Earning"</p>
        <div class="cert-footer"><div><strong>Certificate No:</strong> <?php echo esc_html($cert_number); ?></div><div><strong>Verification Code:</strong> <?php echo esc_html($verification_code); ?></div><div><strong>Date Issued:</strong> <?php echo date('F j, Y'); ?></div></div>
    </div></div></div></body></html><?php exit;
}

function activate_mtti_mis() {
    require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-activator.php';
    MTTI_MIS_Activator::activate();
    require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-upgrader.php';
    MTTI_MIS_Upgrader::upgrade();
}

function deactivate_mtti_mis() {
    require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-deactivator.php';
    MTTI_MIS_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_mtti_mis');
register_deactivation_hook(__FILE__, 'deactivate_mtti_mis');

require MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis.php';

function run_mtti_mis() {
    $plugin = new MTTI_MIS();
    $plugin->run();
}
run_mtti_mis();

add_action('init', 'mtti_mis_register_verification_shortcode');
function mtti_mis_register_verification_shortcode() {
    if (!shortcode_exists('mtti_verify_certificate')) {
        add_shortcode('mtti_verify_certificate', 'mtti_mis_verify_certificate_shortcode');
    }
}

function mtti_mis_verify_certificate_shortcode($atts) {
    require_once MTTI_MIS_PLUGIN_DIR . 'public/class-mtti-mis-shortcodes.php';
    $shortcodes = new MTTI_MIS_Shortcodes();
    return $shortcodes->verify_certificate_shortcode($atts);
}

function mtti_mis_check_upgrades() {
    $current_db_version = get_option('mtti_mis_db_version', '1.0.0');
    if (version_compare($current_db_version, '3.9.8', '<')) {
        require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-upgrader.php';
        MTTI_MIS_Upgrader::upgrade();
    }
}
add_action('admin_init', 'mtti_mis_check_upgrades');

function mtti_mis_output_bulk_transcripts($items_string) {
    global $wpdb;
    $items = array_filter(explode(',', sanitize_text_field($items_string)));
    if (empty($items)) { wp_die('No items selected'); }
    $students_table = $wpdb->prefix . 'mtti_students';
    $unit_results_table = $wpdb->prefix . 'mtti_unit_results';
    $units_table = $wpdb->prefix . 'mtti_course_units';
    $courses_table = $wpdb->prefix . 'mtti_courses';
    $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Bulk Transcripts</title>
    <style>* { margin:0;padding:0;box-sizing:border-box; } @page{size:A4 portrait;margin:0;} @media print{.no-print{display:none!important;}.transcript{page-break-after:always;page-break-inside:avoid;margin:10mm;}.transcript:last-child{page-break-after:avoid;}html,body{background:white;margin:0;padding:0;}} body{font-family:Arial,sans-serif;font-size:11px;line-height:1.3;color:#333;background:#f0f0f0;} .print-controls{position:fixed;top:0;left:0;right:0;background:#2E7D32;color:white;padding:15px 20px;z-index:1000;display:flex;justify-content:space-between;align-items:center;} .print-controls button{padding:10px 25px;font-size:16px;cursor:pointer;border:none;border-radius:4px;background:white;color:#2E7D32;font-weight:bold;} .transcripts-container{padding:70px 20px 20px 20px;} .transcript{width:190mm;height:277mm;margin:0 auto 20px auto;background:white;padding:8mm;box-shadow:0 2px 10px rgba(0,0,0,0.1);border:2px solid #2E7D32;position:relative;overflow:hidden;} .watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:100px;color:rgba(46,125,50,0.04);font-weight:bold;pointer-events:none;} .content{position:relative;z-index:1;height:100%;display:flex;flex-direction:column;} .header{text-align:center;padding-bottom:8px;margin-bottom:8px;border-bottom:2px solid #2E7D32;} .logo{width:50px;margin-bottom:5px;} .header h1{color:#2E7D32;font-size:16px;} .header h2{color:#1976D2;font-size:14px;margin-top:5px;} .section{margin:6px 0;} .section-title{color:#2E7D32;font-size:10px;font-weight:bold;margin-bottom:4px;padding-bottom:2px;border-bottom:1px solid #ddd;text-transform:uppercase;} .info-row{display:flex;justify-content:space-between;padding:2px 0;font-size:10px;} .info-label{font-weight:bold;color:#555;} .unit-box{background:#e8f5e9;border:1px solid #4CAF50;border-radius:5px;padding:10px;margin:10px 0;text-align:center;} .unit-name{font-size:16px;font-weight:bold;color:#1B5E20;margin:3px 0;} .grade-box{background:white;border:3px solid #2E7D32;border-radius:8px;padding:12px;margin:10px auto;max-width:180px;text-align:center;} .grade-value{font-size:56px;font-weight:bold;color:#2E7D32;line-height:1;margin:5px 0;} .status-badge{display:inline-block;padding:4px 12px;border-radius:12px;font-weight:bold;font-size:11px;margin-top:5px;} .status-passed{background:#e8f5e9;color:#2E7D32;border:1px solid #4CAF50;} .status-failed{background:#ffebee;color:#c62828;border:1px solid #ef5350;} .spacer{flex:1;} .signatures{display:flex;justify-content:space-around;margin-top:15px;} .signature{text-align:center;} .signature-line{border-top:1px solid #333;width:100px;margin:0 auto 3px auto;} .signature-title{font-size:9px;color:#666;} .motto{color:#FF9800;font-style:italic;text-align:center;margin-top:10px;font-size:11px;font-weight:bold;} .footer{margin-top:8px;padding-top:5px;border-top:1px solid #ddd;font-size:8px;color:#888;text-align:center;}</style>
    </head><body>
    <div class="print-controls no-print"><div><strong>📄 Bulk Transcripts:</strong> <?php echo count($items); ?> document(s)</div><button onclick="window.print()">🖨️ Print All</button></div>
    <div class="transcripts-container">
    <?php foreach ($items as $item) {
        $parts = explode('_', $item);
        if (count($parts) !== 2) continue;
        $unit_id = intval($parts[0]); $student_id = intval($parts[1]);
        $student = $wpdb->get_row($wpdb->prepare("SELECT s.*, u.display_name FROM {$students_table} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID WHERE s.student_id = %d", $student_id));
        $result = $wpdb->get_row($wpdb->prepare("SELECT ur.*, cu.unit_name, cu.unit_code, cu.duration_hours, c.course_name, c.course_code FROM {$unit_results_table} ur LEFT JOIN {$units_table} cu ON ur.unit_id = cu.unit_id LEFT JOIN {$courses_table} c ON cu.course_id = c.course_id WHERE ur.unit_id = %d AND ur.student_id = %d", $unit_id, $student_id));
        if (!$student || !$result) continue;
        $grade_color = '#2E7D32';
        if (strpos($result->grade, 'B') === 0) $grade_color = '#1976D2';
        elseif (strpos($result->grade, 'C') === 0) $grade_color = '#FF9800';
        elseif (strpos($result->grade, 'D') === 0 || $result->grade === 'E') $grade_color = '#D32F2F';
        $transcript_number = 'MTTI/TR/' . date('Y') . '/' . str_pad($unit_id, 4, '0', STR_PAD_LEFT) . '/' . str_pad($student_id, 4, '0', STR_PAD_LEFT);
        ?>
        <div class="transcript"><div class="watermark">MTTI</div><div class="content">
            <div class="header"><img src="<?php echo esc_url($logo_url); ?>" alt="MTTI" class="logo"><h1>MASOMOTELE TECHNICAL TRAINING INSTITUTE</h1><p style="color:#666;font-size:9px;">Sagaas Center, Eldoret · TVETA Accredited</p><h2>Official Unit Transcript</h2></div>
            <div class="section"><div class="section-title">Student</div><div class="info-row"><span class="info-label">Name:</span><span><?php echo esc_html($student->display_name); ?></span></div><div class="info-row"><span class="info-label">Adm No:</span><span><?php echo esc_html($student->admission_number); ?></span></div></div>
            <div class="section"><div class="section-title">Course</div><div class="info-row"><span class="info-label">Course:</span><span><?php echo esc_html($result->course_name . ' (' . $result->course_code . ')'); ?></span></div></div>
            <div class="unit-box"><div style="font-size:10px;color:#666;"><?php echo esc_html($result->unit_code); ?></div><div class="unit-name"><?php echo esc_html($result->unit_name); ?></div><?php if ($result->duration_hours) : ?><div style="color:#666;font-size:10px;">Duration: <?php echo intval($result->duration_hours); ?> Hours</div><?php endif; ?></div>
            <div class="grade-box" style="border-color:<?php echo $grade_color; ?>;"><div style="font-size:9px;color:#666;text-transform:uppercase;letter-spacing:1px;">Final Grade</div><div class="grade-value" style="color:<?php echo $grade_color; ?>;"><?php echo esc_html($result->grade); ?></div><div class="status-badge <?php echo $result->passed ? 'status-passed' : 'status-failed'; ?>"><?php echo $result->passed ? '✓ PASSED' : '✗ NOT PASSED'; ?></div></div>
            <div class="section"><div class="info-row"><span class="info-label">Assessment Date:</span><span><?php echo date('F j, Y', strtotime($result->result_date)); ?></span></div></div>
            <div class="spacer"></div>
            <div class="signatures"><div class="signature"><div class="signature-line"></div><p class="signature-title">Principal</p></div><div class="signature"><div class="signature-line"></div><p class="signature-title">Registrar</p></div></div>
            <p class="motto">"Start Learning, Start Earning"</p>
            <div class="footer">Ref: <?php echo esc_html($transcript_number); ?> | Generated: <?php echo date('F j, Y'); ?></div>
        </div></div>
    <?php } ?>
    </div></body></html><?php exit;
}

function mtti_mis_output_bulk_certificates($items_string, $completion_date) {
    global $wpdb;
    $items = array_filter(explode(',', sanitize_text_field($items_string)));
    if (empty($items)) { wp_die('No items selected'); }
    $students_table = $wpdb->prefix . 'mtti_students';
    $courses_table = $wpdb->prefix . 'mtti_courses';
    $unit_results_table = $wpdb->prefix . 'mtti_unit_results';
    $units_table = $wpdb->prefix . 'mtti_course_units';
    $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Bulk Certificates</title>
    <style>* { margin:0;padding:0;box-sizing:border-box; } @page{size:A4 landscape;margin:10mm;} @media print{.no-print{display:none!important;}.certificate-page{page-break-after:always;}.certificate-page:last-child{page-break-after:avoid;}body{background:white;}} body{font-family:'Times New Roman',Georgia,serif;background:#f0f0f0;} .print-controls{position:fixed;top:0;left:0;right:0;background:#1976D2;color:white;padding:15px 20px;z-index:1000;display:flex;justify-content:space-between;align-items:center;} .print-controls button{padding:10px 25px;font-size:16px;cursor:pointer;border:none;border-radius:4px;background:white;color:#1976D2;font-weight:bold;} .certificates-container{padding:80px 20px 20px 20px;} .certificate-page{width:297mm;height:210mm;margin:0 auto 30px auto;background:white;box-shadow:0 2px 15px rgba(0,0,0,0.15);position:relative;overflow:hidden;} .certificate{width:100%;height:100%;padding:15mm;background:linear-gradient(135deg,#fffde7 0%,#fff8e1 50%,#fffde7 100%);border:3px solid #c9a227;position:relative;} .inner-border{border:2px solid #2E7D32;padding:20px;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;} .logo{width:80px;margin-bottom:10px;} h1{color:#c9a227;font-size:28px;margin:5px 0;letter-spacing:3px;} h2{color:#2E7D32;font-size:18px;margin:5px 0;} .student-name{font-size:32px;font-weight:bold;color:#1B5E20;margin:10px 0;} .course-name{font-size:24px;font-weight:bold;color:#2E7D32;margin:10px 0;} .details{margin:15px 0;font-size:13px;line-height:1.8;text-align:center;} .grade-highlight{font-size:20px;color:#FF9800;font-weight:bold;} .signatures{display:flex;justify-content:space-around;width:100%;margin-top:20px;} .signature{text-align:center;} .signature-line{border-top:1px solid #333;width:150px;margin-bottom:5px;} .signature-title{font-size:11px;color:#666;} .motto{color:#FF9800;font-style:italic;margin-top:15px;font-size:14px;} .cert-footer{position:absolute;bottom:25mm;left:20mm;right:20mm;display:flex;justify-content:space-between;font-size:10px;color:#666;}</style>
    </head><body>
    <div class="print-controls no-print"><div><strong>🎓 Bulk Certificates:</strong> <?php echo count($items); ?> certificate(s)</div><button onclick="window.print()">🖨️ Print All</button></div>
    <div class="certificates-container">
    <?php foreach ($items as $item) {
        $parts = explode('_', $item);
        if (count($parts) !== 2) continue;
        $student_id = intval($parts[0]); $course_id = intval($parts[1]);
        $student = $wpdb->get_row($wpdb->prepare("SELECT s.*, u.display_name FROM {$students_table} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID WHERE s.student_id = %d", $student_id));
        $course = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$courses_table} WHERE course_id = %d", $course_id));
        if (!$student || !$course) continue;
        $avg_score = $wpdb->get_var($wpdb->prepare("SELECT AVG(ur.score) FROM {$unit_results_table} ur INNER JOIN {$units_table} cu ON ur.unit_id = cu.unit_id WHERE ur.student_id = %d AND cu.course_id = %d", $student_id, $course_id));
        $grade = 'N/A';
        if ($avg_score) { $score = round($avg_score); if ($score >= 78) $grade = 'A'; elseif ($score >= 71) $grade = 'A-'; elseif ($score >= 64) $grade = 'B+'; elseif ($score >= 57) $grade = 'B'; elseif ($score >= 50) $grade = 'B-'; elseif ($score >= 43) $grade = 'C+'; elseif ($score >= 36) $grade = 'C'; elseif ($score >= 29) $grade = 'C-'; elseif ($score >= 22) $grade = 'D+'; elseif ($score >= 15) $grade = 'D'; elseif ($score >= 8) $grade = 'D-'; else $grade = 'E'; }
        $cert_number = 'MTTI/CERT/' . date('Y') . '/' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $verify_url = home_url('/verify-certificate/?code=' . urlencode($cert_number));
        $qr_url = 'https://chart.googleapis.com/chart?chs=100x100&cht=qr&chl=' . urlencode($verify_url) . '&choe=UTF-8';
        ?>
        <div class="certificate-page"><div class="certificate"><div class="inner-border">
            <img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo" class="logo">
            <h1>Certificate of Completion</h1><h2>Masomotele Technical Training Institute</h2>
            <p style="font-size:14px;color:#666;margin:15px 0 5px 0;">This is to certify that</p>
            <div class="student-name"><?php echo esc_html($student->display_name); ?></div>
            <p style="font-size:14px;color:#666;margin:10px 0;">has successfully completed the course</p>
            <div class="course-name"><?php echo esc_html($course->course_name); ?></div>
            <div class="details"><p><strong>Adm No:</strong> <?php echo esc_html($student->admission_number); ?> | <strong>Code:</strong> <?php echo esc_html($course->course_code); ?></p><p><strong>Grade:</strong> <span class="grade-highlight"><?php echo esc_html($grade); ?></span> | <strong>Completion:</strong> <?php echo date('F j, Y', strtotime($completion_date)); ?></p></div>
            <div class="signatures"><div class="signature"><div class="signature-line"></div><p class="signature-title">Principal/Director</p></div><div class="signature"><div class="signature-line"></div><p class="signature-title">Head of Department</p></div></div>
            <p class="motto">"Start Learning, Start Earning"</p>
            <div class="cert-footer"><span>Cert No: <?php echo esc_html($cert_number); ?></span><span style="text-align:center;"><img src="<?php echo esc_url($qr_url); ?>" alt="QR" style="width:60px;height:60px;margin-bottom:3px;"><br><span style="font-size:8px;">Scan to Verify</span></span><span>Issued: <?php echo date('F j, Y'); ?></span></div>
        </div></div></div>
    <?php } ?>
    </div></body></html><?php exit;
}

function mtti_mis_output_admission_letter($student_id) {
    global $wpdb;
    require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-database.php';
    $db = MTTI_MIS_Database::get_instance();
    $table_prefix = $wpdb->prefix . 'mtti_';
    $students_table = $table_prefix . 'students';
    $courses_table = $table_prefix . 'courses';
    $enrollments_table = $table_prefix . 'enrollments';
    $student = $wpdb->get_row($wpdb->prepare("SELECT s.*, u.display_name, u.user_email, u.user_login FROM {$students_table} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID WHERE s.student_id = %d", $student_id));
    if (!$student) { wp_die('Student not found. <a href="' . admin_url('admin.php?page=mtti-mis-admission-letters') . '">Go back</a>'); }
    $enrolled_courses = $wpdb->get_results($wpdb->prepare(
        "SELECT c.*, e.enrollment_date, e.status as enrollment_status, COALESCE(sb.discount_amount, 0) as discount_amount
         FROM {$enrollments_table} e JOIN {$courses_table} c ON e.course_id = c.course_id
         LEFT JOIN {$wpdb->prefix}mtti_student_balances sb ON sb.enrollment_id = e.enrollment_id
         WHERE e.student_id = %d ORDER BY e.enrollment_date DESC", $student_id
    ));
    if (empty($enrolled_courses) && $student->course_id) {
        $course = $wpdb->get_row($wpdb->prepare("SELECT *, 0 as discount_amount FROM {$courses_table} WHERE course_id = %d", $student->course_id));
        if ($course) { $enrolled_courses = array($course); }
    }
    $total_discount = 0;
    foreach ($enrolled_courses as $ec) { $total_discount += floatval($ec->discount_amount ?? 0); }
    $settings = get_option('mtti_mis_settings', array());
    $institute_name = $settings['institute_name'] ?? 'Masomotele Technical Training Institute';
    $institute_address = $settings['institute_address'] ?? 'Sagaas Center, Fourth Floor, Eldoret, Kenya';
    $institute_phone = $settings['institute_phone'] ?? '0712464936';
    $institute_email = $settings['institute_email'] ?? 'info@masomoteletraining.co.ke';
    $institute_website = $settings['institute_website'] ?? 'masomoteletraining.co.ke';
    $paybill_number = '880100'; $account_number = '219391';
    $institute_slogan = '"Start Learning, Start Earning"';
    $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
    $total_tuition = 0; $requires_additional_fees = true;
    $excluded_courses = array('computer applications', 'computer essentials', 'computer & online essentials');
    foreach ($enrolled_courses as $ec) {
        $total_tuition += floatval($ec->fee);
        $course_name_lower = strtolower($ec->course_name);
        foreach ($excluded_courses as $excluded) { if (strpos($course_name_lower, $excluded) !== false) { $requires_additional_fees = false; break; } }
    }
    $admission_fee = $requires_additional_fees ? 1500 : 0;
    $total_fee = $total_tuition + $admission_fee;
    $total_payable = $total_fee - $total_discount;
    $is_health_support = false;
    foreach ($enrolled_courses as $ec) { $cn = strtolower($ec->course_name); if (strpos($cn, 'health') !== false || strpos($cn, 'nursing') !== false || strpos($cn, 'healthcare') !== false) { $is_health_support = true; break; } }
    $course_names = array();
    foreach ($enrolled_courses as $ec) { $course_names[] = strtoupper($ec->course_name); }
    $courses_text = !empty($course_names) ? implode(', ', $course_names) : 'YOUR SELECTED COURSE(S)';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Admission Letter - <?php echo esc_html($student->admission_number); ?></title>
    <style>* { box-sizing:border-box;margin:0;padding:0; } body{font-family:'Segoe UI',Arial,sans-serif;font-size:11pt;line-height:1.6;color:#333;background:#f0f0f1;} @media print{body{margin:0;padding:0;background:white;-webkit-print-color-adjust:exact;print-color-adjust:exact;}.no-print{display:none!important;}.letter-container{box-shadow:none!important;border:none!important;margin:0!important;padding:15mm!important;}@page{size:A4;margin:10mm;}} @media screen{.page-wrapper{max-width:850px;margin:0 auto;padding:20px;}.letter-container{background:white;padding:40px 50px;box-shadow:0 2px 20px rgba(0,0,0,0.1);border:1px solid #ddd;}} .header{text-align:center;border-bottom:3px solid #1F4E79;padding-bottom:20px;margin-bottom:25px;} .header-content{display:flex;align-items:center;justify-content:center;gap:20px;} .header img{max-width:90px;height:auto;} .header h1{color:#1F4E79;font-size:22pt;margin:0 0 5px 0;} .header p{margin:3px 0;color:#555;font-size:10pt;} .header .motto{font-style:italic;color:#C00000;font-weight:bold;} .letter-title{text-align:center;font-size:16pt;font-weight:bold;color:#1F4E79;margin:25px 0;padding:10px;background:#f8f9fa;border-left:4px solid #1F4E79;} .section-title{color:#1F4E79;font-weight:bold;font-size:12pt;margin:25px 0 12px 0;padding:8px 12px;background:linear-gradient(90deg,#e3f2fd 0%,transparent 100%);border-left:4px solid #1F4E79;} table.details{width:100%;border-collapse:collapse;margin:10px 0 20px 0;font-size:10pt;} table.details th,table.details td{border:1px solid #ddd;padding:10px 12px;text-align:left;} table.details th{background:#f5f5f5;width:40%;font-weight:600;color:#444;} table.details .amount{text-align:right;font-weight:bold;} table.details .total-row{background:#e8f5e9;} table.details .total-row td,table.details .total-row th{font-size:12pt;color:#2E7D32;font-weight:bold;} table.details .discount-row{background:#fff3e0;} .payment-box{background:#e8f5e9;padding:15px 20px;border-radius:8px;margin:15px 0;border-left:4px solid #4CAF50;} .payment-box .value{font-size:14pt;color:#2E7D32;font-weight:bold;} .payment-note{background:#fff8e1;padding:12px 15px;border-left:4px solid #f9a825;margin:15px 0;font-size:10pt;} ul.info-list{margin:10px 0;padding-left:25px;list-style:none;} ul.info-list li{margin:8px 0;} .signature-area{margin-top:40px;display:flex;justify-content:space-between;align-items:flex-end;} .signature-line{border-bottom:1px solid #333;width:200px;margin:50px 0 5px 0;} .stamp-area{text-align:center;color:#999;border:2px dashed #ccc;padding:25px 20px;border-radius:5px;font-size:10pt;} .footer{margin-top:40px;padding-top:15px;border-top:2px solid #1F4E79;text-align:center;font-size:9pt;color:#666;} .footer .slogan{font-style:italic;color:#C00000;font-size:11pt;margin-top:10px;font-weight:bold;} .action-buttons{text-align:center;margin-bottom:20px;padding:20px;background:#1F4E79;border-radius:8px;} .action-buttons button,.action-buttons a{display:inline-block;padding:12px 25px;margin:5px 10px;border:none;border-radius:5px;cursor:pointer;text-decoration:none;font-size:14px;font-weight:500;} .btn-print{background:#4CAF50;color:white;} .btn-back{background:#fff;color:#333;}</style>
    </head><body><div class="page-wrapper">
    <div class="action-buttons no-print"><button class="btn-print" onclick="window.print()">🖨️ Print Letter</button><a href="<?php echo admin_url('admin.php?page=mtti-mis-admission-letters'); ?>" class="btn-back">← Back</a></div>
    <div class="letter-container">
        <div class="header"><div class="header-content"><img src="<?php echo esc_url($logo_url); ?>" alt="MTTI Logo"><div class="header-text"><h1><?php echo esc_html(strtoupper($institute_name)); ?></h1><p style="color:#2E7D32;font-weight:bold;">TVETA Accredited Institution</p><p><?php echo esc_html($institute_address); ?></p><p>Tel: <?php echo esc_html($institute_phone); ?> | Email: <?php echo esc_html($institute_email); ?></p><p class="motto"><?php echo $institute_slogan; ?></p></div></div></div>
        <div class="letter-title">📄 LETTER OF ADMISSION</div>
        <div style="margin-bottom:20px;"><p><strong>Ref:</strong> <strong style="color:#C00000;"><?php echo esc_html($student->admission_number); ?></strong></p><p><strong>Date:</strong> <?php echo date('F j, Y'); ?></p></div>
        <div style="margin-bottom:20px;padding:15px;background:#f8f9fa;border-radius:5px;"><p><strong style="color:#C00000;"><?php echo esc_html($student->display_name ?: 'Student'); ?></strong></p></div>
        <p>Dear <strong style="color:#C00000;"><?php echo esc_html($student->display_name ? explode(' ', $student->display_name)[0] : 'Student'); ?></strong>,</p>
        <p style="margin:15px 0;"><strong>RE: OFFER OF ADMISSION TO <span style="color:#1F4E79;text-decoration:underline;"><?php echo esc_html($courses_text); ?></span></strong></p>
        <p>We are pleased to inform you that your application has been <span style="color:#2E7D32;font-weight:bold;">SUCCESSFUL</span>. Congratulations on being accepted!</p>
        <h3 class="section-title">1. STUDENT INFORMATION</h3>
        <table class="details">
            <tr><th>Admission Number</th><td style="color:#C00000;font-weight:bold;"><?php echo esc_html($student->admission_number); ?></td></tr>
            <tr><th>Full Name</th><td><strong style="color:#C00000;"><?php echo esc_html($student->display_name ?: 'N/A'); ?></strong></td></tr>
            <?php if (!empty($student->id_number)) : ?><tr><th>ID/Passport</th><td><?php echo esc_html($student->id_number); ?></td></tr><?php endif; ?>
        </table>
        <?php if (!empty($enrolled_courses)) : ?>
        <h3 class="section-title">2. COURSE DETAILS</h3>
        <?php $course_num = 0; foreach ($enrolled_courses as $course) : $course_num++; ?>
        <?php if (count($enrolled_courses) > 1) : ?><p style="font-weight:bold;color:#1F4E79;margin:15px 0 5px 0;">Course <?php echo $course_num; ?>:</p><?php endif; ?>
        <table class="details"><tr><th>Course Name</th><td><strong><?php echo esc_html($course->course_name); ?></strong></td></tr><tr><th>Course Code</th><td><?php echo esc_html($course->course_code); ?></td></tr><tr><th>Duration</th><td><?php echo esc_html($course->duration_weeks); ?> Weeks</td></tr></table>
        <?php endforeach; ?>
        <h3 class="section-title">3. FEE STRUCTURE</h3>
        <table class="details">
            <?php if (count($enrolled_courses) > 1) : foreach ($enrolled_courses as $idx => $course) : ?><tr><th><?php echo esc_html($course->course_name); ?></th><td class="amount">KES <?php echo number_format($course->fee, 2); ?></td></tr><?php endforeach; ?><tr style="background:#e3f2fd;"><th>Sub-Total</th><td class="amount"><strong>KES <?php echo number_format($total_tuition, 2); ?></strong></td></tr><?php else : ?><tr><th>Tuition Fee</th><td class="amount">KES <?php echo number_format($total_tuition, 2); ?></td></tr><?php endif; ?>
            <?php if ($requires_additional_fees) : ?><tr><th>Admission Fee</th><td class="amount">KES <?php echo number_format($admission_fee, 2); ?></td></tr><?php endif; ?>
            <tr><th>Examination Fee</th><td class="amount">Included</td></tr>
            <?php if ($total_discount > 0) : ?><tr class="discount-row"><th>Discount</th><td class="amount">- KES <?php echo number_format($total_discount, 2); ?></td></tr><?php endif; ?>
            <tr class="total-row"><th>TOTAL PAYABLE</th><td class="amount">KES <?php echo number_format($total_payable, 2); ?></td></tr>
        </table>
        <?php endif; ?>
        <h3 class="section-title">4. PAYMENT INSTRUCTIONS</h3>
        <div class="payment-box"><table><tr><td style="width:40%;"><strong>📱 M-Pesa Paybill:</strong></td><td class="value"><?php echo esc_html($paybill_number); ?></td></tr><tr><td><strong>📝 Account Number:</strong></td><td class="value"><?php echo esc_html($account_number); ?></td></tr></table></div>
        <div class="payment-note"><strong>⚠️ Important:</strong> Use Account Number <strong><?php echo esc_html($account_number); ?></strong> for all payments.</div>
        <h3 class="section-title">5. IMPORTANT INFORMATION</h3>
        <ul class="info-list">
            <li>📅 Classes: <strong>Monday to Friday, 8:00 AM - 8:00 PM</strong></li>
            <li>🏢 Report to Administration Office on first day with this letter, National ID, and 2 passport photos</li>
            <li>💰 <strong>At least 50% of total fee must be paid during intake</strong></li>
            <li>📊 Maintain <strong>100% attendance</strong> to qualify for certification</li>
            <?php if ($is_health_support) : ?><li>📋 <strong>Health courses:</strong> Bring 1 Ream of Paper (A4)</li><?php endif; ?>
        </ul>
        <p style="margin-top:25px;">We look forward to welcoming you to <strong><?php echo esc_html($institute_name); ?></strong>. <strong style="color:#2E7D32;">🎉 Congratulations on your admission!</strong></p>
        <div class="signature-area"><div><p>Yours faithfully,</p><div class="signature-line"></div><p><strong>Principal</strong></p><p style="font-size:10pt;color:#666;"><?php echo esc_html($institute_name); ?></p></div><div class="stamp-area"><p>[OFFICIAL STAMP]</p></div></div>
        <div class="footer"><p style="font-weight:bold;color:#1F4E79;"><?php echo esc_html($institute_name); ?></p><p><?php echo esc_html($institute_address); ?></p><p>Tel: <?php echo esc_html($institute_phone); ?> | <?php echo esc_html($institute_email); ?></p><p class="slogan"><?php echo $institute_slogan; ?></p></div>
    </div></div>
    <script><?php if (isset($_GET['action']) && $_GET['action'] === 'print') : ?>window.onload = function() { window.print(); };<?php endif; ?></script>
    </body></html><?php exit;
}

add_action('wp_ajax_mtti_serve_interactive',        'mtti_mis_serve_interactive');
add_action('wp_ajax_nopriv_mtti_serve_interactive', 'mtti_mis_serve_interactive');
add_action('wp_ajax_mtti_save_quiz_score',          'mtti_mis_save_quiz_score');
add_action('wp_ajax_nopriv_mtti_save_quiz_score',   'mtti_mis_save_quiz_score');
add_action('wp_ajax_mtti_download_certificate',     'mtti_mis_download_certificate');

// ============================================================
// AI QUIZ GENERATOR — Generate questions from lesson content
// ============================================================
add_action('wp_ajax_mtti_ai_generate_quiz', 'mtti_ai_generate_quiz_handler');
function mtti_ai_generate_quiz_handler() {
    check_ajax_referer('mtti_ai_quiz', 'nonce');
    if (!current_user_can('manage_courses') && !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorised.');
    }

    $api_key = get_option('mtti_claude_api_key', '');
    if (empty($api_key)) {
        wp_send_json_error('Claude API key not configured. Go to Settings → MTTI Think Sharp to add it.');
    }

    global $wpdb;
    $lesson_id  = intval($_POST['lesson_id']  ?? 0);
    $course_id  = intval($_POST['course_id']  ?? 0);
    $title      = sanitize_text_field($_POST['title']     ?? '');
    $context    = sanitize_textarea_field($_POST['context'] ?? '');
    $count      = min(15, max(3, intval($_POST['count']   ?? 8)));
    $types      = sanitize_text_field($_POST['types']     ?? 'mixed');
    $difficulty = sanitize_text_field($_POST['difficulty'] ?? 'medium');

    $type_instruction = match($types) {
        'mcq'   => "ALL questions must be multiple-choice with 4 options (A, B, C, D). Mark the correct answer as Answer: A/B/C/D",
        'fib'   => "ALL questions must be fill-in-the-blank. Use _____ for each blank. Write Answer: [correct word/phrase]",
        'short' => "ALL questions must be short-answer. Write Model Answer: [2-3 sentence answer]",
        default => "Mix: ~40% MCQ (A/B/C/D with Answer:), ~30% fill-in-blank (with Answer:), ~30% short-answer (with Model Answer:)",
    };
    $difficulty_instruction = match($difficulty) {
        'easy'  => "Test basic recall (Bloom's level 1-2).",
        'hard'  => "Require analysis and application (Bloom's level 4-6).",
        default => "Test understanding (Bloom's level 2-3).",
    };

    // Pull lesson description/content as context
    $lesson_body = '';
    if ($lesson_id) {
        $lesson = $wpdb->get_row($wpdb->prepare(
            "SELECT title, description, content FROM {$wpdb->prefix}mtti_lessons WHERE lesson_id = %d", $lesson_id
        ));
        if ($lesson) $lesson_body = strip_tags($lesson->description . ' ' . ($lesson->content ?? ''));
    }
    $source_text = trim($context . ' ' . $lesson_body);
    $source_hint = $source_text
        ? "Base questions on this content:\n\"\"\"\n" . mb_substr($source_text, 0, 2500) . "\n\"\"\""
        : "Topic: \"$title\" — use standard Kenyan TVET/CBC Senior School curriculum knowledge.";

    $system = <<<SYS
You are an expert TVET educator creating quiz questions for Kenyan CBC Senior School / TVET learners.
Generate exactly {$count} questions about "{$title}".
{$type_instruction}
{$difficulty_instruction}
{$source_hint}

Output format — strictly follow this for EVERY question:
Q: [question text]
A) [option] B) [option] C) [option] D) [option]   ← only for MCQ
Answer: [answer]   OR   Model Answer: [answer]

Separate questions with a blank line. No numbering, no preamble, no markdown.
SYS;

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 45,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 2000,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => "Generate {$count} questions now."]],
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('API error: ' . $response->get_error_message());
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $raw  = trim($body['content'][0]['text'] ?? '');
    if (empty($raw)) wp_send_json_error('Empty AI response. Please try again.');

    // Parse questions into structured array
    $questions = mtti_parse_quiz_questions($raw);
    if (empty($questions)) wp_send_json_error('Could not parse questions. Please regenerate.');

    // Build the interactive HTML file
    $html_file = mtti_build_quiz_html($title, $questions, $difficulty);

    // Save to uploads directory so it can be deployed to LMS
    $upload_dir = wp_upload_dir();
    $save_dir   = $upload_dir['basedir'] . '/mtti-quizzes/';
    wp_mkdir_p($save_dir);
    $slug     = sanitize_title($title) . '-quiz-' . time();
    $filename = $slug . '.html';
    file_put_contents($save_dir . $filename, $html_file);

    $file_url = $upload_dir['baseurl'] . '/mtti-quizzes/' . $filename;

    // Return preview cards + download URL
    $preview_html = mtti_quiz_preview_html($questions);

    wp_send_json_success([
        'raw'         => $raw,
        'html'        => $preview_html,
        'file_url'    => $file_url,
        'filename'    => $filename,
        'q_count'     => count($questions),
    ]);
}

// ── POST QUIZ TO COURSE — insert as a published interactive lesson ──────────
add_action('wp_ajax_mtti_ai_post_quiz_to_course', 'mtti_ai_post_quiz_to_course_handler');
function mtti_ai_post_quiz_to_course_handler() {
    check_ajax_referer('mtti_ai_quiz', 'nonce');
    if (!current_user_can('manage_courses') && !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorised.');
    }

    global $wpdb;
    $course_id = intval($_POST['course_id'] ?? 0);
    $title     = sanitize_text_field($_POST['title']    ?? '');
    $file_url  = esc_url_raw($_POST['file_url']         ?? '');

    if (!$course_id || empty($title) || empty($file_url)) {
        wp_send_json_error('Missing required data.');
    }

    // Read the saved HTML file — serve_interactive echoes content column directly
    $upload_dir   = wp_upload_dir();
    $filename     = basename(parse_url($file_url, PHP_URL_PATH));
    $file_path    = $upload_dir['basedir'] . '/mtti-quizzes/' . $filename;
    $html_content = file_exists($file_path) ? file_get_contents($file_path) : '';

    if (empty($html_content)) {
        wp_send_json_error('Could not read the quiz file. Please regenerate and try again.');
    }

    // Next order number so quiz appears at the bottom of the lesson list
    $max_order = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(MAX(order_number), 0) FROM {$wpdb->prefix}mtti_lessons WHERE course_id = %d",
        $course_id
    )));

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'mtti_lessons',
        [
            'course_id'    => $course_id,
            'unit_id'      => null,
            'title'        => '🤖 Quiz: ' . $title,
            'description'  => 'Auto-generated AI quiz · ' . date('d M Y'),
            'content'      => $html_content,
            'content_type' => 'html_interactive',
            'content_url'  => $file_url,
            'order_number' => $max_order + 1,
            'status'       => 'Published',
            'created_by'   => get_current_user_id(),
            'created_at'   => current_time('mysql'),
        ],
        ['%d', null, '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s']
    );

    if ($inserted === false) {
        wp_send_json_error('Database error: ' . $wpdb->last_error);
    }

    $lesson_id = $wpdb->insert_id;

    $course = $wpdb->get_row($wpdb->prepare(
        "SELECT course_name, course_code FROM {$wpdb->prefix}mtti_courses WHERE course_id = %d",
        $course_id
    ));
    $course_label = $course
        ? esc_html($course->course_code . ' — ' . $course->course_name)
        : 'the course';

    wp_send_json_success(
        '✅ Quiz posted to <strong>' . $course_label . '</strong>. ' .
        'Students see it in their <em>Lessons &amp; Materials</em> tab right now. ' .
        '<a href="' . admin_url('admin.php?page=mtti-mis-lessons&action=edit&lesson_id=' . $lesson_id) . '" ' .
        'target="_blank" style="color:#1565C0;">Edit lesson →</a>'
    );
}

/**
 * Parse raw AI output into [{question, options[], answer, type}]
 */
function mtti_parse_quiz_questions(string $raw): array {
    $blocks    = preg_split('/\n{2,}/', trim($raw));
    $questions = [];
    foreach ($blocks as $block) {
        $block = trim($block);
        if (empty($block)) continue;
        $lines = array_map('trim', explode("\n", $block));
        $q     = ['question' => '', 'options' => [], 'answer' => '', 'type' => 'short'];
        foreach ($lines as $line) {
            if (preg_match('/^Q:\s*(.+)/i', $line, $m))                              { $q['question'] = $m[1]; }
            elseif (preg_match('/^([A-D])\)\s*(.+)/i', $line, $m))                  { $q['options'][$m[1]] = $m[2]; $q['type'] = 'mcq'; }
            elseif (preg_match('/^(Answer|Model Answer)\s*:\s*(.+)/i', $line, $m))  { $q['answer'] = $m[2]; }
        }
        // Also handle numbered format fallback
        if (empty($q['question'])) {
            $first = $lines[0] ?? '';
            if (preg_match('/^\d+[\.\)]\s+(.+)/', $first, $m)) $q['question'] = $m[1];
            elseif (!empty($first))                             $q['question'] = $first;
        }
        if (!empty($q['question'])) {
            if (strpos($q['question'], '_____') !== false) $q['type'] = 'fib';
            $questions[] = $q;
        }
    }
    return $questions;
}

/**
 * Build the MTTI interactive quiz HTML — matches the 10-slide green template.
 * Single-page quiz: auto-graded MCQ + FIB, shown one question at a time.
 */
function mtti_build_quiz_html(string $title, array $questions, string $difficulty): string {
    $q_json   = json_encode(array_values($questions), JSON_UNESCAPED_UNICODE);
    $total    = count($questions);
    $date_str = date('d M Y');
    $diff_lbl = ucfirst($difficulty);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quiz — {$title}</title>
<style>
:root{--pri:#0a5e2a;--acc:#f5a623;--ok:#2e7d32;--no:#c62828;--bg:#f0f4f0;--card:#fff;--radius:12px;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',sans-serif;}
body{background:var(--bg);min-height:100vh;display:flex;flex-direction:column;}
#top-bar{background:var(--pri);color:#fff;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;}
#top-bar h1{font-size:15px;font-weight:700;}
#top-bar .meta{font-size:11px;opacity:.8;}
#progress-bar{height:4px;background:rgba(255,255,255,.3);}
#progress-fill{height:4px;background:var(--acc);transition:width .4s;}
#stage{flex:1;display:flex;align-items:center;justify-content:center;padding:20px;}
.q-card{background:var(--card);border-radius:var(--radius);padding:28px 26px;max-width:640px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.1);}
.q-counter{font-size:11px;font-weight:700;color:var(--pri);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;}
.q-text{font-size:17px;font-weight:600;color:#1a1a1a;margin-bottom:22px;line-height:1.5;}
.opt-btn{display:block;width:100%;text-align:left;padding:12px 16px;border:2px solid #e0e0e0;border-radius:8px;background:#fff;font-size:14px;cursor:pointer;margin-bottom:10px;transition:all .2s;}
.opt-btn:hover{border-color:var(--pri);background:#f0f8f0;}
.opt-btn.selected{border-color:var(--pri);background:#e8f5e9;}
.opt-btn.correct{border-color:var(--ok);background:#e8f5e9;color:var(--ok);font-weight:700;}
.opt-btn.wrong{border-color:var(--no);background:#ffebee;color:var(--no);}
.fib-input{width:100%;padding:12px;border:2px solid #e0e0e0;border-radius:8px;font-size:15px;margin-bottom:14px;}
.fib-input:focus{outline:none;border-color:var(--pri);}
.short-input{width:100%;padding:12px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;min-height:80px;resize:vertical;margin-bottom:14px;}
.feedback{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;display:none;}
.feedback.correct{background:#e8f5e9;color:var(--ok);}
.feedback.wrong{background:#ffebee;color:var(--no);}
.feedback.info{background:#e3f2fd;color:#1565C0;}
.btn-next{background:var(--pri);color:#fff;border:none;border-radius:8px;padding:12px 28px;font-size:14px;font-weight:700;cursor:pointer;width:100%;margin-top:6px;}
.btn-next:hover{background:#0d7a36;}
#results{display:none;text-align:center;padding:32px 24px;background:var(--card);border-radius:var(--radius);max-width:480px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.1);}
#results .score-ring{font-size:56px;font-weight:900;color:var(--pri);}
#results h2{margin:12px 0 6px;font-size:20px;}
#results .sub{font-size:13px;color:#666;margin-bottom:20px;}
.btn-retry{background:var(--acc);color:#fff;border:none;border-radius:8px;padding:11px 26px;font-size:14px;font-weight:700;cursor:pointer;}
</style>
</head>
<body>
<div id="top-bar">
  <h1>📝 {$title}</h1>
  <span class="meta">{$diff_lbl} · {$total} questions · {$date_str}</span>
</div>
<div id="progress-bar"><div id="progress-fill" style="width:0%"></div></div>

<div id="stage">
  <div id="q-card-wrap"></div>
  <div id="results">
    <div class="score-ring" id="score-pct">0%</div>
    <h2 id="result-title"></h2>
    <p class="sub" id="result-sub"></p>
    <button class="btn-retry" onclick="initQuiz()">🔄 Try Again</button>
  </div>
</div>

<script>
const QUESTIONS = {$q_json};
let cur=0, score=0, answered=false;

function initQuiz(){
  cur=0; score=0; answered=false;
  document.getElementById('results').style.display='none';
  document.getElementById('q-card-wrap').style.display='block';
  showQ();
}

function showQ(){
  const q=QUESTIONS[cur];
  const pct=Math.round(cur/QUESTIONS.length*100);
  document.getElementById('progress-fill').style.width=pct+'%';
  const wrap=document.getElementById('q-card-wrap');

  let html=`<div class="q-card">
    <div class="q-counter">Question \${cur+1} of \${QUESTIONS.length}</div>
    <div class="q-text">\${escHtml(q.question)}</div>`;

  if(q.type==='mcq'){
    for(const [k,v] of Object.entries(q.options||{})){
      html+=`<button class="opt-btn" id="opt-\${k}" onclick="pickMCQ('\${k}')">\${k}) \${escHtml(v)}</button>`;
    }
  } else if(q.type==='fib'){
    html+=`<input class="fib-input" id="fib-in" type="text" placeholder="Type your answer…">`;
  } else {
    html+=`<textarea class="short-input" id="short-in" placeholder="Write your answer…"></textarea>`;
  }

  html+=`<div class="feedback" id="fb"></div>
    <button class="btn-next" id="btn-next" onclick="nextQ()">Check Answer ✓</button>
  </div>`;

  wrap.innerHTML=html;
  answered=false;
}

function pickMCQ(key){
  if(answered) return;
  document.querySelectorAll('.opt-btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById('opt-'+key).classList.add('selected');
}

function nextQ(){
  if(!answered){
    const q=QUESTIONS[cur];
    const fb=document.getElementById('fb');
    fb.style.display='block';

    if(q.type==='mcq'){
      const sel=document.querySelector('.opt-btn.selected');
      if(!sel){fb.className='feedback info';fb.innerText='Please select an option.';return;}
      const chosen=sel.id.replace('opt-','');
      const correct=q.answer.trim().toUpperCase()[0];
      if(chosen===correct){score++;sel.classList.add('correct');fb.className='feedback correct';fb.innerText='✅ Correct!';}
      else{sel.classList.add('wrong');fb.className='feedback wrong';fb.innerText='❌ Incorrect. Answer: '+correct+') '+((q.options||{})[correct]||q.answer);}
      document.querySelectorAll('.opt-btn').forEach(b=>b.style.pointerEvents='none');
    } else if(q.type==='fib'){
      const inp=document.getElementById('fib-in');
      if(!inp.value.trim()){fb.className='feedback info';fb.innerText='Please type an answer.';return;}
      const given=inp.value.trim().toLowerCase();
      const ans=q.answer.trim().toLowerCase();
      if(given===ans||ans.includes(given)){score++;fb.className='feedback correct';fb.innerText='✅ Correct! Answer: '+q.answer;}
      else{fb.className='feedback wrong';fb.innerText='❌ Model answer: '+q.answer;}
      inp.disabled=true;
    } else {
      const inp=document.getElementById('short-in');
      if(!inp.value.trim()){fb.className='feedback info';fb.innerText='Please write your answer.';return;}
      fb.className='feedback info';fb.innerText='📝 Model answer: '+q.answer;
      inp.disabled=true;
      score+=0.5; // partial credit for short answer attempts
    }

    document.getElementById('btn-next').innerText='Next →';
    answered=true;
    return;
  }

  cur++;
  if(cur>=QUESTIONS.length){ showResults(); return; }
  showQ();
}

function showResults(){
  document.getElementById('q-card-wrap').style.display='none';
  document.getElementById('progress-fill').style.width='100%';
  const res=document.getElementById('results');
  res.style.display='block';
  const maxScore=QUESTIONS.length;
  const pct=Math.round((score/maxScore)*100);
  document.getElementById('score-pct').innerText=pct+'%';
  let title,sub;
  if(pct>=80){title='Excellent! 🎉';sub='Outstanding performance. Well done!';}
  else if(pct>=60){title='Good effort! 👍';sub='You passed. Review the questions you missed.';}
  else if(pct>=40){title='Keep practising 📖';sub='Review the lesson and try again.';}
  else{title='Needs improvement 💪';sub='Go back to the lesson materials and try again.';}
  document.getElementById('result-title').innerText=title;
  document.getElementById('result-sub').innerText=sub+' ('+score.toFixed(1)+'/'+maxScore+')';

  // Report to MTTI LMS if available
  // Practice quiz — scores are not recorded in transcripts or unit results
}

function escHtml(t){
  return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

initQuiz();
</script>
</body>
</html>
HTML;
}

/**
 * Build preview cards for the admin modal (compact question list).
 */
function mtti_quiz_preview_html(array $questions): string {
    $html = '';
    foreach ($questions as $i => $q) {
        $n    = $i + 1;
        $type_label = match($q['type']) { 'mcq' => 'MCQ', 'fib' => 'Fill-in', default => 'Short Answer' };
        $type_color = match($q['type']) { 'mcq' => '#1565C0', 'fib' => '#6A1B9A', default => '#E65100' };
        $html .= '<div style="padding:10px 12px;border:1px solid #e8e8e8;border-radius:7px;margin-bottom:8px;background:#fff;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">';
        $html .= '<strong style="font-size:13px;color:#212121;">' . $n . '. ' . esc_html($q['question']) . '</strong>';
        $html .= '<span style="font-size:10px;font-weight:700;color:' . $type_color . ';white-space:nowrap;background:#f5f5f5;padding:2px 7px;border-radius:10px;">' . $type_label . '</span>';
        $html .= '</div>';
        if (!empty($q['answer'])) {
            $html .= '<div style="font-size:11px;color:#2E7D32;margin-top:5px;font-weight:600;">Answer: ' . esc_html($q['answer']) . '</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

function mtti_mis_serve_interactive() {
    $lesson_id = intval($_GET['lesson_id'] ?? 0);
    $nonce     = $_GET['nonce'] ?? '';
    if (!$lesson_id || !wp_verify_nonce($nonce, 'serve_interactive_' . $lesson_id)) {
        status_header(403);
        echo '<!DOCTYPE html><html><body><p>Access denied.</p></body></html>';
        exit;
    }
    global $wpdb;
    $lesson = $wpdb->get_row($wpdb->prepare(
        "SELECT content, content_type, status FROM {$wpdb->prefix}mtti_lessons WHERE lesson_id=%d", $lesson_id
    ));
    if (!$lesson || $lesson->status !== 'Published' || $lesson->content_type !== 'html_interactive') {
        status_header(404);
        echo '<!DOCTYPE html><html><body><p>Interactive not found.</p></body></html>';
        exit;
    }
    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Security-Policy: default-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://fonts.googleapis.com https://fonts.gstatic.com data: blob:;');
    echo $lesson->content;
    exit;
}

// ============================================================
// MTTI QUIZ SCORE — save attempt + postMessage bridge
// ============================================================

function mtti_mis_save_quiz_score() {
    // Accept score, total, percent, lesson_id posted from iframe via postMessage relay
    $lesson_id  = intval($_POST['lesson_id'] ?? 0);
    $score      = floatval($_POST['score']     ?? 0);
    $total      = floatval($_POST['total']     ?? 0);
    $percent    = floatval($_POST['percent']   ?? 0);

    if (!$lesson_id) { wp_send_json_error('Missing lesson_id'); }

    global $wpdb;

    // Resolve student_id from logged-in user
    $student_id = 0;
    if (is_user_logged_in()) {
        $student_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT student_id FROM {$wpdb->prefix}mtti_students WHERE user_id = %d LIMIT 1",
            get_current_user_id()
        ));
    }
    // Guest / unlinked user — still record attempt with student_id=0
    $wpdb->insert(
        $wpdb->prefix . 'mtti_quiz_attempts',
        [
            'lesson_id'    => $lesson_id,
            'student_id'   => $student_id,
            'score'        => $score,
            'total'        => $total,
            'percent'      => $percent,
            'attempted_at' => current_time('mysql'),
        ],
        ['%d', '%d', '%f', '%f', '%f', '%s']
    );
    wp_send_json_success(['attempt_id' => $wpdb->insert_id]);
}

// ============================================================
// MTTI CERTIFICATE DOWNLOAD — HTML-to-print certificate PDF
// ============================================================

function mtti_mis_download_certificate() {
    $student_id = intval($_GET['student_id'] ?? 0);
    $cert_no    = sanitize_text_field($_GET['cert_no'] ?? '');
    $nonce      = $_GET['nonce'] ?? '';

    if (!$student_id || !$cert_no || !wp_verify_nonce($nonce, 'mtti_cert_' . $student_id)) {
        wp_die('Access denied.', 403);
    }
    if (!is_user_logged_in()) {
        wp_die('Please log in to download your certificate.', 401);
    }

    global $wpdb;

    // Verify the certificate belongs to this student
    $cert = $wpdb->get_row($wpdb->prepare(
        "SELECT c.*, s.admission_number, u.display_name
         FROM {$wpdb->prefix}mtti_certificates c
         LEFT JOIN {$wpdb->prefix}mtti_students s ON c.student_id = s.student_id
         LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
         WHERE c.student_id = %d AND c.certificate_number = %s AND c.status = 'Valid'",
        $student_id, $cert_no
    ));

    if (!$cert) {
        wp_die('Certificate not found or invalid.', 404);
    }

    $verify_url = home_url('/verify-certificate/?code=' . urlencode($cert->verification_code));
    $logo_url   = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';

    nocache_headers();
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Certificate — <?php echo esc_html($cert->certificate_number); ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  @page { size: A4 landscape; margin: 0; }
  body { font-family: Georgia, "Times New Roman", serif; background:#fff; }
  .cert-page {
    width:297mm; height:210mm; position:relative; overflow:hidden;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    border:18px solid #2E7D32; padding:30px 50px; text-align:center;
  }
  .cert-border-inner {
    position:absolute; inset:24px; border:3px double #c8a84b; pointer-events:none;
  }
  .cert-logo { height:70px; margin-bottom:12px; }
  .cert-institute { font-size:13px; color:#555; letter-spacing:1px; text-transform:uppercase; margin-bottom:4px; }
  .cert-title { font-size:38px; color:#2E7D32; font-style:italic; margin:14px 0 8px; }
  .cert-presented { font-size:14px; color:#666; margin-bottom:6px; }
  .cert-name { font-size:34px; color:#1a1a1a; border-bottom:2px solid #c8a84b; display:inline-block; padding:0 40px 6px; margin:10px 0; }
  .cert-body { font-size:15px; color:#444; margin:12px 0 8px; line-height:1.7; }
  .cert-grade { display:inline-block; background:#2E7D32; color:#fff; font-size:16px; font-weight:bold; padding:5px 22px; border-radius:4px; margin:6px 0; }
  .cert-footer { display:flex; justify-content:space-between; align-items:flex-end; width:100%; margin-top:24px; }
  .cert-sig-line { border-top:1px solid #333; width:160px; margin-top:40px; font-size:11px; color:#666; padding-top:5px; }
  .cert-meta { font-size:10px; color:#999; }
  .cert-qr { font-size:9px; color:#bbb; margin-top:4px; }
  @media print {
    body { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
    .no-print { display:none !important; }
  }
</style>
</head>
<body>
<div class="no-print" style="background:#1B5E20;color:#fff;padding:12px 20px;text-align:center;font-family:sans-serif;font-size:14px;">
  🎓 Certificate ready — <button onclick="window.print()" style="background:#fff;color:#1B5E20;border:none;padding:6px 18px;border-radius:4px;font-weight:bold;cursor:pointer;margin-left:10px;">🖨️ Print / Save as PDF</button>
</div>
<div class="cert-page">
  <div class="cert-border-inner"></div>
  <img src="<?php echo esc_url($logo_url); ?>" class="cert-logo" alt="MTTI Logo">
  <div class="cert-institute">Masomotele Technical Training Institute</div>
  <div class="cert-institute" style="font-size:11px;">TVETA Accredited · Sagaas Centre, 4th Floor, Eldoret, Kenya</div>
  <div class="cert-title">Certificate of Completion</div>
  <div class="cert-presented">This is to certify that</div>
  <div class="cert-name"><?php echo esc_html($cert->display_name ?: $cert->student_name); ?></div>
  <div class="cert-body">
    has successfully completed the course<br>
    <strong><?php echo esc_html($cert->course_name); ?></strong>
    <?php if ($cert->completion_date): ?>
      on <strong><?php echo date('d F Y', strtotime($cert->completion_date)); ?></strong>
    <?php endif; ?>
  </div>
  <div class="cert-grade">Grade: <?php echo esc_html($cert->grade); ?></div>

  <div class="cert-footer">
    <div style="text-align:center;">
      <div class="cert-sig-line">Director, MTTI</div>
    </div>
    <div style="text-align:center;">
      <div class="cert-meta">Certificate No: <strong><?php echo esc_html($cert->certificate_number); ?></strong></div>
      <div class="cert-meta">Issued: <?php echo date('d M Y', strtotime($cert->issue_date)); ?></div>
      <div class="cert-qr">Verify at: <?php echo esc_html($verify_url); ?></div>
    </div>
    <div style="text-align:center;">
      <div class="cert-sig-line">Admission No: <?php echo esc_html($cert->admission_number); ?></div>
    </div>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ============================================================
// MTTI NOTIFICATIONS — central helper + auto-triggers
// ============================================================

/**
 * Insert a notification for a student.
 *
 * @param int    $student_id  mtti_students.student_id
 * @param string $type        info | success | warning | fee | lesson | quiz
 * @param string $title       Short heading
 * @param string $message     Body text
 * @param string $link        Optional URL
 */
function mtti_notify( $student_id, $type, $title, $message, $link = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'mtti_notifications';
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) return;
    $wpdb->insert( $table, [
        'student_id' => intval( $student_id ),
        'type'       => sanitize_key( $type ),
        'title'      => sanitize_text_field( $title ),
        'message'    => sanitize_textarea_field( $message ),
        'link'       => esc_url_raw( $link ),
        'is_read'    => 0,
        'created_at' => current_time( 'mysql' ),
    ], [ '%d','%s','%s','%s','%s','%d','%s' ] );
}

/**
 * Notify a student when a payment is recorded.
 * Hooked to a custom action fired from class-mtti-mis-admin-payments.php
 */
add_action( 'mtti_payment_recorded', function( $payment_id, $student_id, $amount, $enrollment_id ) {
    global $wpdb;
    $balance = floatval( $wpdb->get_var( $wpdb->prepare(
        "SELECT balance FROM {$wpdb->prefix}mtti_student_balances WHERE enrollment_id = %d",
        $enrollment_id
    ) ) );
    $receipt = sanitize_text_field( $wpdb->get_var( $wpdb->prepare(
        "SELECT receipt_number FROM {$wpdb->prefix}mtti_payments WHERE payment_id = %d",
        $payment_id
    ) ) );
    $portal_url = get_permalink( get_page_by_path('student-portal') ) ?: home_url('/student-portal/');
    $link = add_query_arg( 'portal_tab', 'payments', $portal_url );

    if ( $balance <= 0 ) {
        mtti_notify( $student_id, 'success',
            '✅ Fees Fully Cleared!',
            'Payment of KES ' . number_format( $amount, 2 ) . ' received (Ref: ' . $receipt . '). Your balance is now KES 0.00. You are eligible for your certificate!',
            $link
        );
    } else {
        mtti_notify( $student_id, 'fee',
            '💳 Payment Received',
            'KES ' . number_format( $amount, 2 ) . ' received (Ref: ' . $receipt . '). Remaining balance: KES ' . number_format( $balance, 2 ) . '.',
            $link
        );
    }
}, 10, 4 );

/**
 * Notify enrolled students when a lesson/note/quiz is published.
 * Hooked to a custom action fired when a lesson's status becomes 'Published'.
 */
add_action( 'mtti_lesson_published', function( $lesson_id, $course_id, $title, $content_type ) {
    global $wpdb;

    // Get all active students enrolled in this course
    $students = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT e.student_id
         FROM {$wpdb->prefix}mtti_enrollments e
         WHERE e.course_id = %d AND e.status IN ('Active','Enrolled','In Progress')",
        $course_id
    ) );
    if ( empty( $students ) ) return;

    $portal_url = get_permalink( get_page_by_path('student-portal') ) ?: home_url('/student-portal/');
    $link = add_query_arg( 'portal_tab', 'lessons', $portal_url );

    $is_quiz = ( strpos( $title, '🧠 Quiz:' ) === 0 || strpos( $title, '🤖 Quiz:' ) === 0 );
    $clean   = preg_replace( '/^(🧠|🤖) Quiz:\s*/u', '', $title );

    if ( $is_quiz ) {
        $notif_type  = 'quiz';
        $notif_title = '🧠 New Practice Quiz Available';
        $notif_msg   = 'A new practice quiz has been added: "' . $clean . '". Try it in the Lessons tab!';
    } elseif ( $content_type === 'html_interactive' ) {
        $notif_type  = 'lesson';
        $notif_title = '⚡ New Interactive Lesson';
        $notif_msg   = 'New interactive lesson posted: "' . $title . '". Open the Lessons tab to try it.';
    } elseif ( in_array( $content_type, [ 'pdf', 'document', 'presentation' ] ) ) {
        $notif_type  = 'lesson';
        $notif_title = '📄 New Course Material';
        $notif_msg   = 'New material uploaded: "' . $title . '". Download it from the Lessons tab.';
    } elseif ( $content_type === 'video' ) {
        $notif_type  = 'lesson';
        $notif_title = '🎬 New Video Lesson';
        $notif_msg   = 'A video lesson has been added: "' . $title . '". Watch it in the Lessons tab.';
    } else {
        $notif_type  = 'lesson';
        $notif_title = '📖 New Lesson Posted';
        $notif_msg   = 'New lesson posted: "' . $title . '". Check the Lessons tab.';
    }

    foreach ( $students as $sid ) {
        mtti_notify( intval( $sid ), $notif_type, $notif_title, $notif_msg, $link );
    }
}, 10, 4 );

/**
 * Notify a student when they have an outstanding fee balance (weekly cron).
 */
add_action( 'mtti_fee_reminder_cron', function() {
    global $wpdb;
    $portal_url = get_permalink( get_page_by_path('student-portal') ) ?: home_url('/student-portal/');
    $link = add_query_arg( 'portal_tab', 'payments', $portal_url );

    $owing = $wpdb->get_results(
        "SELECT sb.student_id, sb.balance, e.course_id, c.course_name
         FROM {$wpdb->prefix}mtti_student_balances sb
         JOIN {$wpdb->prefix}mtti_enrollments e ON sb.enrollment_id = e.enrollment_id
         JOIN {$wpdb->prefix}mtti_courses c ON e.course_id = c.course_id
         WHERE sb.balance > 0 AND e.status IN ('Active','Enrolled','In Progress')"
    );
    foreach ( $owing as $row ) {
        // Only send once per week — check if reminder sent in last 7 days
        $last_key = 'mtti_fee_remind_' . $row->student_id;
        if ( get_transient( $last_key ) ) continue;
        set_transient( $last_key, 1, WEEK_IN_SECONDS );
        mtti_notify( $row->student_id, 'fee',
            '⚠️ Fee Balance Reminder',
            'You have an outstanding balance of KES ' . number_format( $row->balance, 2 ) . ' for ' . $row->course_name . '. Please clear fees to remain enrolled.',
            $link
        );
    }
} );
if ( ! wp_next_scheduled( 'mtti_fee_reminder_cron' ) ) {
    wp_schedule_event( time(), 'weekly', 'mtti_fee_reminder_cron' );
}

// ============================================================
// MTTI THINK SHARP — AI COACH PROXY
// ============================================================

add_action('rest_api_init', function() {
    register_rest_route('mtti/v1', '/coach', [
        'methods'             => 'POST',
        'callback'            => 'mtti_coach_handler',
        'permission_callback' => '__return_true',
    ]);
});

function mtti_coach_handler(WP_REST_Request $request) {
    $origin  = $request->get_header('origin') ?: $request->get_header('referer') ?: '';
    $allowed = 'masomoteletraining.co.ke';
    if (!empty($origin) && strpos($origin, $allowed) === false) {
        return new WP_Error('forbidden', 'Unauthorised domain', ['status' => 403]);
    }
    $body  = $request->get_json_params();
    $token = sanitize_text_field($body['token'] ?? '');
    $valid_tokens = ['mttipilot2026', 'MTTIPILOT2026', 'MttiPilot2026'];
    if (!in_array($token, $valid_tokens)) {
        return new WP_Error('unauthorized', 'Invalid access token', ['status' => 401]);
    }
    $learner_id = sanitize_text_field($body['learner'] ?? 'unknown') . '_ch' . intval($body['chapter'] ?? 0);
    $rate_key   = 'mtti_coach_rate_' . md5($learner_id . date('Y-m-d'));
    $call_count = (int) get_transient($rate_key);
    if ($call_count >= 20) {
        return new WP_Error('rate_limited', 'Daily coaching limit reached.', ['status' => 429]);
    }
    set_transient($rate_key, $call_count + 1, DAY_IN_SECONDS);
    $api_key = get_option('mtti_claude_api_key', '');
    if (empty($api_key)) {
        return new WP_Error('config_error', 'AI coaching not configured. Contact MTTI.', ['status' => 500]);
    }
    $system   = sanitize_textarea_field($body['system'] ?? '');
    $messages = $body['messages'] ?? [];
    if (empty($system) || empty($messages)) {
        return new WP_Error('bad_request', 'Missing required fields', ['status' => 400]);
    }
    $clean_messages = [];
    foreach ($messages as $msg) {
        $role    = in_array($msg['role'], ['user', 'assistant']) ? $msg['role'] : 'user';
        $content = sanitize_textarea_field($msg['content'] ?? '');
        if (!empty($content)) {
            $clean_messages[] = ['role' => $role, 'content' => $content];
        }
    }
    if (count($clean_messages) > 12) {
        $clean_messages = array_slice($clean_messages, -12);
    }
    if (empty($clean_messages)) {
        return new WP_Error('bad_request', 'No valid messages', ['status' => 400]);
    }
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 30,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 350,
            'system'     => $system,
            'messages'   => $clean_messages,
        ]),
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('api_error', 'Connection failed. Please try again.', ['status' => 502]);
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['content'][0]['text'])) {
        return new WP_Error('api_error', 'Unexpected response. Please try again.', ['status' => 502]);
    }
    $log   = get_option('mtti_coach_usage_log', []);
    $log[] = ['learner' => sanitize_text_field($body['learner'] ?? 'unknown'), 'chapter' => intval($body['chapter'] ?? 0), 'date' => date('Y-m-d H:i:s')];
    if (count($log) > 500) $log = array_slice($log, -500);
    update_option('mtti_coach_usage_log', $log);
    return new WP_REST_Response(['reply' => $data['content'][0]['text']], 200);
}

add_action('admin_menu', function() {
    add_submenu_page('options-general.php', 'MTTI Think Sharp', 'MTTI Think Sharp', 'manage_options', 'mtti-think-sharp', 'mtti_think_sharp_settings_page');
});

add_action('admin_init', function() {
    register_setting('mtti_think_sharp_group', 'mtti_claude_api_key', ['sanitize_callback' => 'sanitize_text_field']);
});

function mtti_think_sharp_settings_page() {
    $api_key = get_option('mtti_claude_api_key', '');
    $log     = get_option('mtti_coach_usage_log', []);
    $today   = array_filter($log, fn($e) => str_starts_with($e['date'], date('Y-m-d')));
    ?>
    <div class="wrap">
        <h1>🎓 MTTI Think Sharp — AI Coach Settings</h1><hr>
        <form method="post" action="options.php">
            <?php settings_fields('mtti_think_sharp_group'); ?>
            <table class="form-table">
                <tr><th><label for="mtti_claude_api_key">Claude API Key</label></th>
                    <td><input type="password" name="mtti_claude_api_key" id="mtti_claude_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="sk-ant-api03-...">
                    <p class="description">Get from <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>. <strong>Never share this key.</strong></p></td></tr>
                <tr><th>Status</th><td><?php echo !empty($api_key) ? '<span style="color:green;font-weight:bold;">✓ Configured (ends: ...' . esc_html(substr($api_key, -6)) . ')</span>' : '<span style="color:red;font-weight:bold;">✗ Not configured — AI coaching will not work</span>'; ?></td></tr>
                <tr><th>Pilot URL</th><td><code>https://masomoteletraining.co.ke/wp-content/uploads/think-sharp/think-sharp-premium.html?token=mttipilot2026</code></td></tr>
                <tr><th>Today's Usage</th><td><strong><?php echo count($today); ?></strong> exchanges today (~$<?php echo number_format(count($today) * 0.003, 3); ?> USD)</td></tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>
        <hr><h2>📊 Recent Coaching Activity</h2>
        <?php if (!empty($log)) : ?>
        <table class="widefat striped" style="max-width:600px;"><thead><tr><th>Learner</th><th>Chapter</th><th>Date/Time</th></tr></thead><tbody>
        <?php foreach (array_reverse(array_slice($log, -20)) as $entry) : ?>
        <tr><td><?php echo esc_html($entry['learner']); ?></td><td><?php echo intval($entry['chapter']); ?></td><td><?php echo esc_html($entry['date']); ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php else : ?><p>No coaching sessions yet.</p><?php endif; ?>
    </div>
    <?php
}

// ============================================================
// MTTI THINK SHARP — PROGRESS REPORTING ENDPOINT
// Receives silent progress events from the HTML course
// ============================================================

add_action('rest_api_init', function() {
    register_rest_route('mtti/v1', '/progress', [
        'methods'             => 'POST',
        'callback'            => 'mtti_progress_handler',
        'permission_callback' => '__return_true',
    ]);
});

function mtti_progress_handler(WP_REST_Request $request) {
    $body  = $request->get_json_params();
    $token = sanitize_text_field($body['token'] ?? '');
    $valid = ['mttipilot2026', 'MTTIPILOT2026', 'MttiPilot2026'];
    if (!in_array($token, $valid)) {
        return new WP_Error('unauthorized', 'Invalid token', ['status' => 401]);
    }
    $log   = get_option('mtti_think_sharp_progress', []);
    $entry = [
        'event'          => sanitize_text_field($body['event']          ?? ''),
        'learner'        => sanitize_text_field($body['learner']        ?? ''),
        'email'          => sanitize_email($body['email']               ?? ''),
        'profile'        => sanitize_text_field($body['profile']        ?? ''),
        'chapter'        => intval($body['chapter']                     ?? 0),
        'completed'      => intval($body['chaptersCompleted']           ?? 0),
        'quizAvg'        => intval($body['quizAvg']                     ?? 0),
        'finalScore'     => $body['finalScore']  !== null ? intval($body['finalScore'])  : null,
        'finalTotal'     => $body['finalTotal']  !== null ? intval($body['finalTotal'])  : null,
        'baselineScore'  => intval($body['baselineScore']               ?? 0),
        'afterScore'     => intval($body['reflectionScore']             ?? 0),
        'growth'         => intval($body['growth']                      ?? 0),
        'date'           => sanitize_text_field($body['date']           ?? date('Y-m-d H:i:s')),
    ];
    $log[] = $entry;
    if (count($log) > 5000) $log = array_slice($log, -5000);
    update_option('mtti_think_sharp_progress', $log);
    return new WP_REST_Response(['ok' => true], 200);
}

// ============================================================
// LEARNER PROGRESS DASHBOARD — added to Think Sharp settings
// ============================================================

// Override the settings page to add progress tab
remove_action('admin_menu', '__return_false'); // safety
add_action('admin_menu', function() {
    add_submenu_page(
        'options-general.php',
        'MTTI Think Sharp — Learner Dashboard',
        'Think Sharp Dashboard',
        'manage_options',
        'mtti-think-sharp-dashboard',
        'mtti_think_sharp_dashboard_page'
    );
}, 20);

function mtti_think_sharp_dashboard_page() {
    $progress = get_option('mtti_think_sharp_progress', []);
    $api_key  = get_option('mtti_claude_api_key', '');

    // Build per-learner summary from events
    $learners = [];
    foreach ($progress as $e) {
        $key = $e['email'] ?: $e['learner'];
        if (!$key) continue;
        if (!isset($learners[$key])) {
            $learners[$key] = [
                'name'           => $e['learner'],
                'email'          => $e['email'],
                'profile'        => $e['profile'],
                'maxChapter'     => 0,
                'chaptersCompleted' => 0,
                'quizAvg'        => 0,
                'finalScore'     => null,
                'finalTotal'     => null,
                'baselineScore'  => 0,
                'afterScore'     => 0,
                'growth'         => 0,
                'hasCert'        => false,
                'lastSeen'       => '',
                'events'         => [],
            ];
        }
        $l = &$learners[$key];
        $l['events'][] = $e['event'];
        $l['lastSeen'] = $e['date'];
        if ($e['completed'] > $l['chaptersCompleted']) $l['chaptersCompleted'] = $e['completed'];
        if ($e['quizAvg']   > $l['quizAvg'])   $l['quizAvg']   = $e['quizAvg'];
        if ($e['baselineScore']) $l['baselineScore'] = $e['baselineScore'];
        if ($e['afterScore'])    $l['afterScore']    = $e['afterScore'];
        if ($e['growth'])        $l['growth']        = $e['growth'];
        if ($e['finalScore'] !== null) { $l['finalScore'] = $e['finalScore']; $l['finalTotal'] = $e['finalTotal']; }
        if ($e['event'] === 'certificate_issued') $l['hasCert'] = true;
        if (!$l['profile'] && $e['profile']) $l['profile'] = $e['profile'];
    }

    // Stats
    $total     = count($learners);
    $completed = count(array_filter($learners, fn($l) => $l['chaptersCompleted'] >= 6));
    $certified = count(array_filter($learners, fn($l) => $l['hasCert']));
    $avgGrowth = $total > 0 ? round(array_sum(array_column($learners,'growth')) / max($total,1), 1) : 0;
    $passRate  = $total > 0 ? round(($certified / max($total,1)) * 100) : 0;

    $profile_icons = ['A' => '🌱', 'B' => '📈', 'C' => '🚀'];
    $profile_names = ['A' => 'Just Starting Out', 'B' => 'Building My Career', 'C' => 'Leading & Growing'];
    ?>
    <div class="wrap">
    <style>
        .ts-dash { font-family: -apple-system, sans-serif; }
        .ts-stats { display: flex; gap: 16px; flex-wrap: wrap; margin: 20px 0; }
        .ts-stat { background: #1a2e0f; color: white; border-radius: 10px; padding: 20px 24px; min-width: 140px; text-align: center; }
        .ts-stat-num { font-size: 2.4rem; font-weight: 900; color: #FF9700; line-height: 1; }
        .ts-stat-lbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; color: rgba(255,255,255,0.45); margin-top: 6px; }
        .ts-table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin: 16px 0; }
        .ts-table th { background: #1a2e0f; color: #FF9700; padding: 12px 14px; text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .ts-table td { padding: 11px 14px; border-bottom: 1px solid #f0f0f0; font-size: 0.88rem; vertical-align: middle; }
        .ts-table tr:last-child td { border-bottom: none; }
        .ts-table tr:hover td { background: #f8f8f8; }
        .progress-bar { height: 8px; background: #e8f0d8; border-radius: 99px; overflow: hidden; min-width: 80px; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #3D6318, #FF9700); border-radius: 99px; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 0.72rem; font-weight: 700; }
        .badge-green { background: #e8f0d8; color: #3D6318; }
        .badge-orange { background: #fff3e0; color: #c67a00; }
        .badge-grey { background: #f5f5f5; color: #888; }
        .badge-cert { background: #3D6318; color: white; }
        .growth-num { font-weight: 800; }
        .growth-pos { color: #3D6318; }
        .growth-zero { color: #888; }
        .growth-neg { color: #FF9700; }
        .ts-section { margin: 28px 0 8px; font-size: 1rem; font-weight: 700; color: #1a2e0f; border-bottom: 2px solid #e8f0d8; padding-bottom: 8px; }
        .ts-empty { text-align: center; padding: 40px; color: #888; font-style: italic; }
        .ts-export { float: right; }
        .ts-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    </style>

    <div class="ts-dash">
        <div class="ts-header">
            <h1>🎓 Think Sharp — Learner Dashboard</h1>
            <a href="?page=mtti-think-sharp-dashboard&export=csv" class="button button-secondary ts-export">⬇ Export CSV</a>
        </div>

        <?php
        // Handle CSV export
        if (isset($_GET['export']) && $_GET['export'] === 'csv' && current_user_can('manage_options')) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="think-sharp-learners-' . date('Y-m-d') . '.csv"');
            echo "Name,Email,Profile,Chapters Completed,Quiz Avg,Final Score,Baseline Score,After Score,Growth,Certificate,Last Seen\n";
            foreach ($learners as $l) {
                $fs = $l['finalScore'] !== null ? $l['finalScore'].'/'.$l['finalTotal'].' ('.round($l['finalScore']/$l['finalTotal']*100).'%)' : '—';
                echo implode(',', [
                    '"'.esc_html($l['name']).'"',
                    '"'.esc_html($l['email']).'"',
                    '"'.($profile_names[$l['profile']]??$l['profile']).'"',
                    $l['chaptersCompleted'].'/6',
                    $l['quizAvg'].'%',
                    $fs,
                    $l['baselineScore'].'/12',
                    $l['afterScore'].'/12',
                    ($l['growth'] >= 0 ? '+' : '').$l['growth'],
                    $l['hasCert'] ? 'Yes' : 'No',
                    '"'.esc_html($l['lastSeen']).'"',
                ])."\n";
            }
            exit;
        }
        ?>

        <!-- STATS OVERVIEW -->
        <div class="ts-stats">
            <div class="ts-stat"><div class="ts-stat-num"><?php echo $total; ?></div><div class="ts-stat-lbl">Total Learners</div></div>
            <div class="ts-stat"><div class="ts-stat-num"><?php echo $completed; ?></div><div class="ts-stat-lbl">Completed Course</div></div>
            <div class="ts-stat"><div class="ts-stat-num"><?php echo $certified; ?></div><div class="ts-stat-lbl">Certificates Issued</div></div>
            <div class="ts-stat"><div class="ts-stat-num"><?php echo $passRate; ?>%</div><div class="ts-stat-lbl">Pass Rate</div></div>
            <div class="ts-stat"><div class="ts-stat-num"><?php echo ($avgGrowth >= 0 ? '+' : '').$avgGrowth; ?></div><div class="ts-stat-lbl">Avg Growth Score</div></div>
        </div>

        <!-- PROFILE BREAKDOWN -->
        <?php
        $byProfile = ['A'=>0,'B'=>0,'C'=>0];
        foreach ($learners as $l) { if (isset($byProfile[$l['profile']])) $byProfile[$l['profile']]++; }
        ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
            <?php foreach ($byProfile as $p => $cnt) : ?>
            <div style="background:white;border:1px solid #e8f0d8;border-radius:8px;padding:12px 18px;font-size:0.85rem;">
                <?php echo $profile_icons[$p]; ?> <strong><?php echo $profile_names[$p]; ?></strong> — <?php echo $cnt; ?> learner<?php echo $cnt!==1?'s':''; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- LEARNER TABLE -->
        <div class="ts-section">👥 All Learners</div>
        <?php if (empty($learners)) : ?>
            <div class="ts-empty">No learner data yet. Data appears here as learners progress through the course.</div>
        <?php else : ?>
        <table class="ts-table">
            <thead>
                <tr>
                    <th>Learner</th>
                    <th>Profile</th>
                    <th>Progress</th>
                    <th>Quiz Avg</th>
                    <th>Final Score</th>
                    <th>Thinking Growth</th>
                    <th>Certificate</th>
                    <th>Last Active</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_reverse($learners) as $l) :
                $chPct = round(($l['chaptersCompleted']/6)*100);
                $fs    = $l['finalScore'] !== null
                    ? $l['finalScore'].'/'.$l['finalTotal'].' <span style="color:'.($l['finalScore']/$l['finalTotal']>=0.7?'#3D6318':'#c67a00').'">('.round($l['finalScore']/$l['finalTotal']*100).'%)</span>'
                    : '—';
                $growth = $l['growth'];
                $gClass = $growth > 0 ? 'growth-pos' : ($growth < 0 ? 'growth-neg' : 'growth-zero');
                $gText  = ($growth > 0 ? '+' : '') . $growth . '/12';
                $lastSeen = $l['lastSeen'] ? date('d M Y', strtotime($l['lastSeen'])) : '—';
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($l['name']); ?></strong>
                    <?php if ($l['email']) : ?><br><span style="color:#888;font-size:0.78rem;"><?php echo esc_html($l['email']); ?></span><?php endif; ?>
                </td>
                <td><?php echo $profile_icons[$l['profile']] ?? ''; ?> <span style="font-size:0.8rem;"><?php echo $profile_names[$l['profile']] ?? $l['profile']; ?></span></td>
                <td>
                    <div style="margin-bottom:4px;font-size:0.8rem;"><?php echo $l['chaptersCompleted']; ?>/6 chapters</div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?php echo $chPct; ?>%"></div></div>
                </td>
                <td><?php echo $l['quizAvg'] ? $l['quizAvg'].'%' : '—'; ?></td>
                <td><?php echo $fs; ?></td>
                <td>
                    <?php if ($l['baselineScore'] && $l['afterScore']) : ?>
                        <span class="growth-num <?php echo $gClass; ?>"><?php echo $gText; ?></span>
                        <div style="font-size:0.72rem;color:#888;margin-top:2px;">Before: <?php echo $l['baselineScore']; ?> → After: <?php echo $l['afterScore']; ?></div>
                    <?php elseif ($l['baselineScore']) : ?>
                        <span style="color:#888;font-size:0.8rem;">Baseline: <?php echo $l['baselineScore']; ?>/12</span>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td><?php echo $l['hasCert'] ? '<span class="badge badge-cert">✓ Certified</span>' : '<span class="badge badge-grey">Pending</span>'; ?></td>
                <td style="color:#888;font-size:0.82rem;"><?php echo $lastSeen; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- DROP-OFF ANALYSIS -->
        <?php
        $chapterCounts = array_fill(0,7,0);
        foreach ($learners as $l) { $chapterCounts[min($l['chaptersCompleted'],6)]++; }
        ?>
        <div class="ts-section">📉 Where Learners Are (Chapter Distribution)</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;">
            <?php for ($i=0;$i<=6;$i++) : $cnt=$chapterCounts[$i]; if(!$cnt) continue; ?>
            <div style="background:white;border:1px solid #e8f0d8;border-radius:8px;padding:12px 18px;text-align:center;min-width:90px;">
                <div style="font-size:1.6rem;font-weight:900;color:#3D6318;"><?php echo $cnt; ?></div>
                <div style="font-size:0.72rem;color:#888;margin-top:4px;"><?php echo $i===6?'Completed':'At Ch '.($i+1); ?></div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- GROWTH INSIGHTS -->
        <?php
        $withGrowth = array_filter($learners, fn($l) => $l['baselineScore'] && $l['afterScore']);
        if (!empty($withGrowth)) :
            $avgBefore = round(array_sum(array_column(array_values($withGrowth),'baselineScore')) / count($withGrowth), 1);
            $avgAfter  = round(array_sum(array_column(array_values($withGrowth),'afterScore'))  / count($withGrowth), 1);
            $avgG      = round(array_sum(array_column(array_values($withGrowth),'growth'))      / count($withGrowth), 1);
        ?>
        <div class="ts-section">📊 Thinking Growth Summary (<?php echo count($withGrowth); ?> learners with before/after data)</div>
        <div class="ts-stats" style="margin-bottom:24px;">
            <div class="ts-stat"><div class="ts-stat-num"><?php echo $avgBefore; ?></div><div class="ts-stat-lbl">Avg Before Score /12</div></div>
            <div class="ts-stat"><div class="ts-stat-num"><?php echo $avgAfter; ?></div><div class="ts-stat-lbl">Avg After Score /12</div></div>
            <div class="ts-stat"><div class="ts-stat-num"><?php echo ($avgG>=0?'+':'').$avgG; ?></div><div class="ts-stat-lbl">Avg Growth</div></div>
            <div class="ts-stat">
                <div class="ts-stat-num"><?php echo count(array_filter($withGrowth,fn($l)=>$l['growth']>0)); ?></div>
                <div class="ts-stat-lbl">Improved</div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // end if learners ?>

        <!-- SETTINGS LINK -->
        <div style="margin-top:24px;padding:16px 20px;background:#f8f8f8;border-radius:8px;font-size:0.88rem;">
            ⚙️ <a href="<?php echo admin_url('options-general.php?page=mtti-think-sharp'); ?>">AI Coach Settings & API Key</a>
            &nbsp;|&nbsp;
            <strong>Pilot URL:</strong> <code>https://masomoteletraining.co.ke/wp-content/uploads/think-sharp/think-sharp-premium.html?token=mttipilot2026</code>
        </div>
    </div>
    </div>
    <?php
}

