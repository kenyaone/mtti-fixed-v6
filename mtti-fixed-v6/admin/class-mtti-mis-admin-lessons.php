<?php
/**
 * Lessons Admin Class
 * 
 * Allows teachers/instructors to upload lessons for their courses.
 * Lessons can include notes, videos, PDFs, and other learning materials.
 * Students can view lessons in the learner portal.
 * 
 * @version 4.3.0
 */
class MTTI_MIS_Admin_Lessons {
    
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        // Form submissions are handled early in mtti-mis.php to avoid headers error
        // This method only displays the UI
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'add':
                $this->display_add_form();
                break;
            case 'edit':
                $this->display_edit_form(intval($_GET['lesson_id']));
                break;
            case 'view':
                $this->display_lesson_view(intval($_GET['lesson_id']));
                break;
            default:
                $this->display_list();
        }
    }
    
    private function process_lesson_form() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_lessons';
        
        $form_action = sanitize_text_field($_POST['form_action']);
        
        // Handle file upload
        $content_url = '';
        $content_type = 'text';
        $file_size = 0;
        
        // Check if video URL provided
        if (!empty($_POST['video_url'])) {
            $content_url = esc_url_raw($_POST['video_url']);
            $content_type = 'video';
        }
        // Check if file uploaded
        elseif (!empty($_FILES['lesson_file']['name'])) {
            $upload = wp_handle_upload($_FILES['lesson_file'], array('test_form' => false));
            if (isset($upload['url'])) {
                $content_url = $upload['url'];
                $ext = strtolower(pathinfo($upload['file'], PATHINFO_EXTENSION));
                $file_size = filesize($upload['file']);
                
                // Determine content type
                if (in_array($ext, array('mp4', 'webm', 'ogg', 'mov'))) {
                    $content_type = 'video';
                } elseif (in_array($ext, array('pdf'))) {
                    $content_type = 'pdf';
                } elseif (in_array($ext, array('doc', 'docx'))) {
                    $content_type = 'document';
                } elseif (in_array($ext, array('ppt', 'pptx'))) {
                    $content_type = 'presentation';
                } elseif (in_array($ext, array('mp3', 'wav', 'ogg'))) {
                    $content_type = 'audio';
                } else {
                    $content_type = 'file';
                }
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
            $new_lesson_id = $wpdb->insert_id;
            $message = 'created';
            // Notify enrolled students if published immediately
            if (($data['status'] ?? '') === 'Published' && $new_lesson_id) {
                do_action('mtti_lesson_published', $new_lesson_id, $data['course_id'], $data['title'], $data['content_type'] ?? 'text');
            }
        } else {
            $lesson_id = intval($_POST['lesson_id']);

            // Keep existing URL if no new file uploaded
            if (empty($content_url)) {
                unset($data['content_url']);
                unset($data['content_type']);
                unset($data['file_size']);
            }

            // Check if status is changing to Published — fire notification
            $old_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE lesson_id=%d", $lesson_id));
            $wpdb->update($table, $data, array('lesson_id' => $lesson_id));
            $message = 'updated';

            if (($data['status'] ?? '') === 'Published' && $old_status !== 'Published') {
                $lesson_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE lesson_id=%d", $lesson_id));
                if ($lesson_row) {
                    do_action('mtti_lesson_published', $lesson_id, $lesson_row->course_id, $lesson_row->title, $lesson_row->content_type ?? 'text');
                }
            }
        } // end else (update)

        // Use JavaScript redirect to avoid headers already sent error
        $redirect_url = admin_url('admin.php?page=mtti-mis-lessons&message=' . $message);
        echo '<script>window.location.href = "' . esc_url($redirect_url) . '";</script>';
        echo '<p>Redirecting... <a href="' . esc_url($redirect_url) . '">Click here if not redirected</a></p>';
        exit;
    }
    
    private function process_delete() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_lessons';
        $lesson_id = intval($_GET['lesson_id']);
        
        // Soft delete: save to trash first
        $lesson = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE lesson_id = %d", $lesson_id), ARRAY_A);
        if ($lesson) {
            require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
            MTTI_MIS_Admin_Trash::create_table();
            $trash = new MTTI_MIS_Admin_Trash();
            $trash->soft_delete('lesson', $lesson_id, $lesson['title'] ?: 'Lesson #' . $lesson_id, $lesson);
        }
        
        $wpdb->delete($table, array('lesson_id' => $lesson_id));
        // Also delete any orphaned lesson_files rows for this lesson
        $wpdb->delete($wpdb->prefix . 'mtti_lesson_files', array('lesson_id' => $lesson_id));
        
        // If deleting a quiz, return to quiz tab; otherwise back to lessons
        $was_quiz  = isset($_GET['quiz']) && $_GET['quiz'] == '1';
        $base_url  = admin_url('admin.php?page=mtti-mis-lessons&message=deleted');
        $redirect_url = $was_quiz ? $base_url . '&quiz=1' : $base_url;
        echo '<script>window.location.href = "' . esc_url($redirect_url) . '";</script>';
        echo '<p>Redirecting... <a href="' . esc_url($redirect_url) . '">Click here if not redirected</a></p>';
        exit;
    }
    
    private function display_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_lessons';
        $courses_table = $wpdb->prefix . 'mtti_courses';
        $units_table = $wpdb->prefix . 'mtti_course_units';
        
        // Filter by course
        $course_filter = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
        
        // Quiz filter — ?quiz=1 shows only AI practice quizzes, otherwise hide them from main list
        $quiz_filter = isset($_GET['quiz']) && $_GET['quiz'] == '1';

        if ($quiz_filter) {
            // Show ONLY quiz lessons
            $where = "WHERE l.title LIKE '🤖 Quiz:%'";
        } else {
            // Normal view: exclude interactives AND quiz lessons
            $where = "WHERE l.content_type != 'html_interactive' AND l.title NOT LIKE '🤖 Quiz:%'";
        }

        if ($course_filter) {
            $where .= $wpdb->prepare(" AND l.course_id = %d", $course_filter);
        }
        
        // Type filter
        $type_filter = isset($_GET['content_type']) ? sanitize_text_field($_GET['content_type']) : '';
        if (!$quiz_filter && $type_filter) {
            $where .= $wpdb->prepare(" AND l.content_type = %s", $type_filter);
        }

        // Search
        if (!empty($_GET['s'])) {
            $search = '%' . $wpdb->esc_like($_GET['s']) . '%';
            $where .= $wpdb->prepare(" AND (l.title LIKE %s OR l.description LIKE %s)", $search, $search);
        }
        
        $lessons = $wpdb->get_results(
            "SELECT l.*, c.course_name, c.course_code, cu.unit_name, cu.unit_code,
                    u.display_name as created_by_name
             FROM {$table} l
             LEFT JOIN {$courses_table} c ON l.course_id = c.course_id
             LEFT JOIN {$units_table} cu ON l.unit_id = cu.unit_id
             LEFT JOIN {$wpdb->users} u ON l.created_by = u.ID
             {$where}
             ORDER BY l.course_id, l.unit_id, l.order_number ASC"
        );
        
        $courses = $wpdb->get_results("SELECT * FROM {$courses_table} WHERE status = 'Active' ORDER BY course_name");
        
        // Stats
        $total_lessons = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE title NOT LIKE '🤖 Quiz:%' AND content_type != 'html_interactive'");
        $video_lessons = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE content_type = 'video'");
        $total_views   = $wpdb->get_var("SELECT SUM(view_count) FROM {$table} WHERE title NOT LIKE '🤖 Quiz:%'");
        $total_quizzes = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE title LIKE '🤖 Quiz:%'");
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php echo $quiz_filter ? '🤖 Practice Quizzes' : '📖 Lessons'; ?>
            </h1>
            <?php if (!$quiz_filter) : ?>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons&action=add'); ?>" class="page-title-action">Add New Lesson</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <!-- Tab switcher -->
            <div style="display:flex;gap:8px;margin-bottom:16px;">
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons'); ?>"
                   style="padding:6px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;<?php echo !$quiz_filter ? 'background:#0a5e2a;color:#fff;' : 'background:#f0f0f0;color:#555;'; ?>">
                   📖 Lessons
                </a>
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons&quiz=1'); ?>"
                   style="padding:6px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;<?php echo $quiz_filter ? 'background:#7B1FA2;color:#fff;' : 'background:#f0f0f0;color:#555;'; ?>">
                   🤖 Practice Quizzes <?php if ($total_quizzes > 0) echo '<span style="background:rgba(0,0,0,.15);padding:1px 7px;border-radius:10px;font-size:11px;margin-left:4px;">' . intval($total_quizzes) . '</span>'; ?>
                </a>
            </div>
            
            <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo $quiz_filter ? 'Quiz' : 'Lesson'; ?> <?php echo esc_html($_GET['message']); ?> successfully!</p>
            </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="mtti-stats-row" style="display: flex; gap: 20px; margin: 20px 0;">
                <div style="background: white; padding: 20px; border-radius: 8px; flex: 1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #2E7D32;"><?php echo $quiz_filter ? intval($total_quizzes) : intval($total_lessons); ?></div>
                    <div style="color: #666;">Total Lessons</div>
                </div>
                <div style="background: white; padding: 20px; border-radius: 8px; flex: 1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #1976D2;"><?php echo intval($video_lessons); ?></div>
                    <div style="color: #666;">Video Lessons</div>
                </div>
                <div style="background: white; padding: 20px; border-radius: 8px; flex: 1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #FF9800;"><?php echo number_format(intval($total_views)); ?></div>
                    <div style="color: #666;">Total Views</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="mtti-mis-lessons">
                    <?php if ($quiz_filter) : ?>
                    <input type="hidden" name="quiz" value="1">
                    <?php endif; ?>
                    <select name="course_id">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $c) : ?>
                        <option value="<?php echo $c->course_id; ?>" <?php selected($course_filter, $c->course_id); ?>>
                            <?php echo esc_html($c->course_code . ' - ' . $c->course_name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$quiz_filter) : ?>
                    <select name="content_type">
                        <option value="">All Types</option>
                        <?php foreach (['video'=>'🎬 Video','pdf'=>'📕 PDF','document'=>'📘 Document','presentation'=>'📙 Presentation','audio'=>'🎵 Audio','text'=>'📝 Text','file'=>'📄 File'] as $val=>$label): ?>
                        <option value="<?php echo $val; ?>" <?php selected($type_filter, $val); ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input type="search" name="s" value="<?php echo esc_attr($_GET['s'] ?? ''); ?>" placeholder="<?php echo $quiz_filter ? 'Search quizzes...' : 'Search lessons...'; ?>">
                    <input type="submit" class="button" value="Filter">
                </form>
                <?php if (!$quiz_filter) : ?>
                <p style="margin:6px 0 0;font-size:12px;color:#888;">ℹ️ Interactive HTML lessons are managed under <a href="<?php echo admin_url('admin.php?page=mtti-mis-interactive'); ?>">Interactive Content</a>. AI Practice Quizzes are under the 🤖 tab above.</p>
                <?php else : ?>
                <p style="margin:6px 0 0;font-size:12px;color:#888;">ℹ️ These are AI-generated practice quizzes. They are <strong>not recorded</strong> in student results or transcripts. Delete any quiz to remove it from the student portal immediately.</p>
                <?php endif; ?>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <?php if ($quiz_filter) : ?>
                        <th>Quiz Title</th>
                        <th>Course</th>
                        <th>Created</th>
                        <th>Views</th>
                        <th>Actions</th>
                        <?php else : ?>
                        <th style="width: 50px;">#</th>
                        <th>Title</th>
                        <th>Course / Unit</th>
                        <th>Type</th>
                        <th>Duration</th>
                        <th>Views</th>
                        <th>Status</th>
                        <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lessons) : foreach ($lessons as $l) : ?>
                    <tr <?php echo $quiz_filter ? 'style="background:#fdf6ff;"' : ''; ?>>
                        <?php if ($quiz_filter) : ?>
                        <td>
                            <strong style="color:#7B1FA2;">🤖 <?php echo esc_html(preg_replace('/^🤖 Quiz:\s*/u', '', $l->title)); ?></strong>
                            <?php if ($l->description) : ?>
                            <br><small style="color:#888;"><?php echo esc_html(wp_trim_words($l->description, 12)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background:#e3f2fd;padding:2px 8px;border-radius:3px;font-size:12px;"><?php echo esc_html($l->course_code); ?></span>
                            <br><small style="color:#666;"><?php echo esc_html($l->course_name); ?></small>
                        </td>
                        <td><?php echo date('d M Y', strtotime($l->created_at)); ?></td>
                        <td><?php echo number_format(intval($l->view_count)); ?></td>
                        <td>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mtti-mis-lessons&quiz=1&action=delete&lesson_id=' . $l->lesson_id), 'delete_lesson_' . $l->lesson_id); ?>"
                               onclick="return confirm('Delete this practice quiz? Students will no longer see it.');"
                               style="color:#dc3232;font-weight:600;">🗑 Delete Quiz</a>
                        </td>
                        <?php else : ?>
                        <td><?php echo intval($l->order_number); ?></td>
                        <td>
                            <strong><?php echo esc_html($l->title); ?></strong>
                            <?php if ($l->is_free_preview) : ?>
                            <span style="background: #4CAF50; color: white; font-size: 10px; padding: 2px 6px; border-radius: 3px; margin-left: 5px;">FREE</span>
                            <?php endif; ?>
                            <?php if ($l->description) : ?>
                            <br><small style="color: #666;"><?php echo esc_html(wp_trim_words($l->description, 10)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="background: #e3f2fd; padding: 2px 8px; border-radius: 3px; font-size: 12px;"><?php echo esc_html($l->course_code); ?></span>
                            <?php if ($l->unit_name) : ?>
                            <br><small style="color: #666;"><?php echo esc_html($l->unit_code . ' - ' . $l->unit_name); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $type_icons = array(
                                'video' => '🎬',
                                'pdf' => '📕',
                                'document' => '📘',
                                'presentation' => '📙',
                                'audio' => '🎵',
                                'text' => '📝',
                                'file' => '📄'
                            );
                            echo ($type_icons[$l->content_type] ?? '📄') . ' ' . ucfirst($l->content_type);
                            ?>
                        </td>
                        <td><?php echo $l->duration_minutes ? $l->duration_minutes . ' min' : '-'; ?></td>
                        <td><?php echo number_format(intval($l->view_count)); ?></td>
                        <td>
                            <span class="mtti-status mtti-status-<?php echo strtolower($l->status); ?>">
                                <?php echo esc_html($l->status); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons&action=view&lesson_id=' . $l->lesson_id); ?>">View</a> |
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons&action=edit&lesson_id=' . $l->lesson_id); ?>">Edit</a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mtti-mis-lessons&action=delete&lesson_id=' . $l->lesson_id), 'delete_lesson_' . $l->lesson_id); ?>" 
                               onclick="return confirm('Delete this lesson?');" style="color: #dc3232;">Delete</a> |
                            <a href="#" onclick="mttiGenQuiz(<?php echo intval($l->lesson_id); ?>, <?php echo intval($l->course_id); ?>, '<?php echo esc_js($l->title); ?>', '<?php echo esc_js(wp_trim_words($l->description ?? '', 60)); ?>'); return false;" style="color:#7B1FA2;">🤖 Gen Quiz</a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="<?php echo $quiz_filter ? '5' : '8'; ?>" style="text-align: center; padding: 40px;">
                            <span style="font-size: 48px; display: block; margin-bottom: 10px;"><?php echo $quiz_filter ? '🤖' : '📖'; ?></span>
                            <?php if ($quiz_filter) : ?>
                            <p>No practice quizzes posted yet. Go to <strong>Lessons</strong> or <strong>Interactive Content</strong> and click <strong>🤖 Gen Quiz</strong> to create one.</p>
                            <?php else : ?>
                            <p>No lessons found. <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons&action=add'); ?>">Add your first lesson</a>.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ── AI QUIZ MODAL ───────────────────────────────────── -->
        <div id="mtti-quiz-modal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:680px;width:94%;max-height:88vh;overflow-y:auto;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.3);">
                <button onclick="document.getElementById('mtti-quiz-modal').style.display='none';" style="position:absolute;top:14px;right:18px;background:none;border:none;font-size:22px;cursor:pointer;color:#666;">✕</button>
                <h2 style="margin:0 0 4px;font-size:18px;">🤖 AI Quiz Generator</h2>
                <p id="mtti-quiz-lesson-name" style="color:#666;font-size:13px;margin:0 0 18px;"></p>

                <div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                    <label style="font-size:12px;font-weight:700;color:#555;">Questions:
                        <select id="mtti-quiz-count" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;">
                            <option value="5">5</option>
                            <option value="8" selected>8</option>
                            <option value="10">10</option>
                        </select>
                    </label>
                    <label style="font-size:12px;font-weight:700;color:#555;">Types:
                        <select id="mtti-quiz-types" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;">
                            <option value="mixed" selected>Mixed (MCQ + Fill-in + Short Answer)</option>
                            <option value="mcq">Multiple Choice only</option>
                            <option value="fib">Fill-in-the-Blank only</option>
                            <option value="short">Short Answer only</option>
                        </select>
                    </label>
                    <label style="font-size:12px;font-weight:700;color:#555;">Difficulty:
                        <select id="mtti-quiz-diff" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;">
                            <option value="easy">Easy</option>
                            <option value="medium" selected>Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </label>
                </div>

                <div id="mtti-quiz-extra-context" style="margin-bottom:14px;">
                    <label style="font-size:12px;font-weight:700;color:#555;display:block;margin-bottom:4px;">Extra context / key points (optional):</label>
                    <textarea id="mtti-quiz-context" rows="3" placeholder="Paste key points, notes, or topic details to guide question generation..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:13px;resize:vertical;"></textarea>
                </div>

                <button id="mtti-quiz-generate-btn" onclick="mttiDoGenerateQuiz()" style="background:#7B1FA2;color:#fff;border:none;border-radius:7px;padding:10px 24px;font-size:14px;font-weight:700;cursor:pointer;width:100%;margin-bottom:14px;">
                    ✨ Generate Questions
                </button>

                <div id="mtti-quiz-spinner" style="display:none;text-align:center;padding:24px;color:#888;font-size:13px;">
                    ⏳ Generating interactive quiz — please wait…
                </div>

                <div id="mtti-quiz-result" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                        <h3 style="margin:0;font-size:15px;">Questions Preview</h3>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button onclick="mttiPostQuizToCourse()" id="mtti-quiz-post-btn"
                                style="background:#0a5e2a;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">
                                📤 Post to Course
                            </button>
                            <a id="mtti-quiz-download-btn" href="#" target="_blank"
                               style="background:#1565C0;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;">
                               ⬇ Download HTML
                            </a>
                            <button onclick="mttiDoGenerateQuiz()" style="background:#555;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">🔄 Regenerate</button>
                        </div>
                    </div>
                    <div id="mtti-quiz-questions-wrap" style="border:1px solid #e0e0e0;border-radius:8px;padding:14px;background:#fafafa;max-height:320px;overflow-y:auto;font-size:13px;line-height:1.7;"></div>
                    <div id="mtti-quiz-status" style="margin-top:8px;font-size:12px;font-weight:700;color:#2E7D32;"></div>
                </div>
            </div>
        </div>

        <script>
        var mttiCurrentLessonId   = 0;
        var mttiCurrentCourseId   = 0;
        var mttiCurrentLessonTitle = '';
        var mttiCurrentFileUrl    = '';
        var mttiCurrentHtmlContent = '';

        function mttiGenQuiz(lessonId, courseId, title, description) {
            mttiCurrentLessonId    = lessonId;
            mttiCurrentCourseId    = courseId;
            mttiCurrentLessonTitle = title;
            mttiCurrentFileUrl     = '';
            mttiCurrentHtmlContent = '';
            document.getElementById('mtti-quiz-lesson-name').innerText      = '📖 ' + title;
            document.getElementById('mtti-quiz-context').value              = description || '';
            document.getElementById('mtti-quiz-result').style.display       = 'none';
            document.getElementById('mtti-quiz-spinner').style.display      = 'none';
            document.getElementById('mtti-quiz-generate-btn').style.display = 'block';
            document.getElementById('mtti-quiz-status').innerText           = '';
            document.getElementById('mtti-quiz-post-btn').disabled          = true;
            document.getElementById('mtti-quiz-post-btn').style.opacity     = '.5';
            document.getElementById('mtti-quiz-modal').style.display        = 'flex';
        }

        function mttiDoGenerateQuiz() {
            document.getElementById('mtti-quiz-generate-btn').style.display = 'none';
            document.getElementById('mtti-quiz-result').style.display       = 'none';
            document.getElementById('mtti-quiz-spinner').style.display      = 'block';
            document.getElementById('mtti-quiz-status').innerText           = '';

            jQuery.ajax({
                url: ajaxurl, method: 'POST',
                data: {
                    action:     'mtti_ai_generate_quiz',
                    nonce:      '<?php echo wp_create_nonce('mtti_ai_quiz'); ?>',
                    lesson_id:  mttiCurrentLessonId,
                    course_id:  mttiCurrentCourseId,
                    title:      mttiCurrentLessonTitle,
                    context:    document.getElementById('mtti-quiz-context').value,
                    count:      document.getElementById('mtti-quiz-count').value,
                    types:      document.getElementById('mtti-quiz-types').value,
                    difficulty: document.getElementById('mtti-quiz-diff').value
                },
                success: function(r) {
                    document.getElementById('mtti-quiz-spinner').style.display      = 'none';
                    document.getElementById('mtti-quiz-generate-btn').style.display = 'block';
                    if (r.success) {
                        mttiCurrentFileUrl     = r.data.file_url;
                        mttiCurrentHtmlContent = r.data.html_content || '';
                        document.getElementById('mtti-quiz-questions-wrap').innerHTML = r.data.html;
                        document.getElementById('mtti-quiz-download-btn').href        = r.data.file_url;
                        document.getElementById('mtti-quiz-download-btn').download    = r.data.filename;
                        document.getElementById('mtti-quiz-status').innerText         =
                            '✅ ' + r.data.q_count + ' questions ready. Click "📤 Post to Course" to make it live for students.';
                        document.getElementById('mtti-quiz-post-btn').disabled    = false;
                        document.getElementById('mtti-quiz-post-btn').style.opacity = '1';
                        document.getElementById('mtti-quiz-result').style.display = 'block';
                    } else {
                        alert('Error: ' + (r.data || 'Check Claude API key in Settings → MTTI Think Sharp.'));
                    }
                },
                error: function() {
                    document.getElementById('mtti-quiz-spinner').style.display      = 'none';
                    document.getElementById('mtti-quiz-generate-btn').style.display = 'block';
                    alert('Network error. Please try again.');
                }
            });
        }

        function mttiPostQuizToCourse() {
            if (!mttiCurrentCourseId || !mttiCurrentFileUrl) return;
            var btn = document.getElementById('mtti-quiz-post-btn');
            var status = document.getElementById('mtti-quiz-status');
            btn.disabled = true;
            btn.innerText = '⏳ Posting…';
            status.innerText = '';

            jQuery.ajax({
                url: ajaxurl, method: 'POST',
                data: {
                    action:     'mtti_ai_post_quiz_to_course',
                    nonce:      '<?php echo wp_create_nonce('mtti_ai_quiz'); ?>',
                    course_id:  mttiCurrentCourseId,
                    lesson_id:  mttiCurrentLessonId,
                    title:      mttiCurrentLessonTitle,
                    file_url:   mttiCurrentFileUrl
                },
                success: function(r) {
                    btn.innerText = '📤 Post to Course';
                    if (r.success) {
                        btn.innerText      = '✅ Posted!';
                        btn.style.background = '#2E7D32';
                        status.style.color = '#2E7D32';
                        status.innerHTML   = r.data;
                    } else {
                        btn.disabled = false;
                        status.style.color = '#C62828';
                        status.innerText   = '❌ ' + (r.data || 'Could not post. Try again.');
                    }
                },
                error: function() {
                    btn.disabled = false;
                    btn.innerText = '📤 Post to Course';
                    status.style.color = '#C62828';
                    status.innerText = '❌ Network error.';
                }
            });
        }
        </script>
        <?php
    }

    private function display_add_form() {
        $this->display_form(null, 'add');
    }
    
    private function display_edit_form($lesson_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_lessons';
        $lesson = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE lesson_id = %d", $lesson_id));
        
        if (!$lesson) {
            wp_die('Lesson not found.');
        }
        
        $this->display_form($lesson, 'edit');
    }
    
    private function display_form($lesson, $action) {
        global $wpdb;
        $courses_table = $wpdb->prefix . 'mtti_courses';
        $units_table = $wpdb->prefix . 'mtti_course_units';
        
        $courses = $wpdb->get_results("SELECT * FROM {$courses_table} WHERE status = 'Active' ORDER BY course_name");
        $units = $wpdb->get_results("SELECT * FROM {$units_table} WHERE status = 'Active' ORDER BY course_id, order_number");
        
        // Group units by course
        $units_by_course = array();
        foreach ($units as $u) {
            $units_by_course[$u->course_id][] = $u;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $action === 'add' ? 'Add New Lesson' : 'Edit Lesson'; ?></h1>
            
            <form method="post" enctype="multipart/form-data" style="max-width: 800px;">
                <?php wp_nonce_field('mtti_lesson_action', 'mtti_lesson_nonce'); ?>
                <input type="hidden" name="form_action" value="<?php echo $action; ?>">
                <?php if ($lesson) : ?>
                <input type="hidden" name="lesson_id" value="<?php echo $lesson->lesson_id; ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="title">Lesson Title *</label></th>
                        <td>
                            <input type="text" name="title" id="title" class="large-text" required
                                   value="<?php echo $lesson ? esc_attr($lesson->title) : ''; ?>"
                                   placeholder="e.g., Introduction to Microsoft Word">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="course_id">Course *</label></th>
                        <td>
                            <select name="course_id" id="course_id" class="regular-text" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $c) : ?>
                                <option value="<?php echo $c->course_id; ?>" <?php selected($lesson ? $lesson->course_id : '', $c->course_id); ?>>
                                    <?php echo esc_html($c->course_code . ' - ' . $c->course_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="unit_id">Unit (Optional)</label></th>
                        <td>
                            <select name="unit_id" id="unit_id" class="regular-text">
                                <option value="">General / All Units</option>
                                <?php foreach ($units as $u) : ?>
                                <option value="<?php echo $u->unit_id; ?>" 
                                        data-course="<?php echo $u->course_id; ?>"
                                        <?php selected($lesson ? $lesson->unit_id : '', $u->unit_id); ?>>
                                    <?php echo esc_html($u->unit_code . ' - ' . $u->unit_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Link this lesson to a specific course unit</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="description">Short Description</label></th>
                        <td>
                            <textarea name="description" id="description" rows="2" class="large-text"
                                      placeholder="Brief description of what this lesson covers"><?php echo $lesson ? esc_textarea($lesson->description) : ''; ?></textarea>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="content">Lesson Content</label></th>
                        <td>
                            <?php 
                            wp_editor(
                                $lesson ? $lesson->content : '', 
                                'content',
                                array(
                                    'textarea_name' => 'content',
                                    'textarea_rows' => 15,
                                    'media_buttons' => true,
                                    'teeny' => false,
                                )
                            );
                            ?>
                            <p class="description">Write your lesson notes, explanations, and instructions here. You can add images and formatting.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="video_url">Video URL (YouTube/Vimeo)</label></th>
                        <td>
                            <input type="url" name="video_url" id="video_url" class="large-text"
                                   value="<?php echo ($lesson && $lesson->content_type == 'video') ? esc_url($lesson->content_url) : ''; ?>"
                                   placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                            <p class="description">Paste a YouTube or Vimeo video URL</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="lesson_file">Or Upload File</label></th>
                        <td>
                            <input type="file" name="lesson_file" id="lesson_file" 
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.mp4,.mp3,.zip">
                            <p class="description">Upload PDF, Word, PowerPoint, Video (MP4), Audio (MP3), or ZIP file (Max 100MB)</p>
                            <?php if ($lesson && $lesson->content_url && $lesson->content_type != 'video') : ?>
                            <p style="margin-top: 10px;">
                                <strong>Current file:</strong> 
                                <a href="<?php echo esc_url($lesson->content_url); ?>" target="_blank">
                                    <?php echo esc_html(basename($lesson->content_url)); ?>
                                </a>
                                <?php if ($lesson->file_size) : ?>
                                (<?php echo size_format($lesson->file_size); ?>)
                                <?php endif; ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="duration_minutes">Duration (minutes)</label></th>
                        <td>
                            <input type="number" name="duration_minutes" id="duration_minutes" class="small-text" min="1"
                                   value="<?php echo $lesson ? intval($lesson->duration_minutes) : ''; ?>"
                                   placeholder="e.g., 30">
                            <p class="description">Estimated time to complete this lesson</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="order_number">Order Number</label></th>
                        <td>
                            <input type="number" name="order_number" id="order_number" class="small-text" min="0"
                                   value="<?php echo $lesson ? intval($lesson->order_number) : '1'; ?>">
                            <p class="description">Lessons are displayed in this order (1, 2, 3...)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="is_free_preview">Free Preview</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_free_preview" id="is_free_preview" value="1"
                                       <?php checked($lesson && $lesson->is_free_preview, 1); ?>>
                                Allow non-enrolled students to view this lesson
                            </label>
                            <p class="description">Use this for introductory or promotional lessons</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text">
                                <option value="Published" <?php selected($lesson ? $lesson->status : '', 'Published'); ?>>Published</option>
                                <option value="Draft" <?php selected($lesson ? $lesson->status : '', 'Draft'); ?>>Draft</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_lesson_submit" class="button button-primary" 
                           value="<?php echo $action === 'add' ? 'Create Lesson' : 'Update Lesson'; ?>">
                    <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Filter units by selected course
            $('#course_id').on('change', function() {
                var courseId = $(this).val();
                $('#unit_id option').each(function() {
                    var optCourse = $(this).data('course');
                    if (!optCourse || optCourse == courseId) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
                $('#unit_id').val('');
            });
            
            // Trigger on load
            $('#course_id').trigger('change');
        });
        </script>
        <?php
    }
    
    private function display_lesson_view($lesson_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_lessons';
        $courses_table = $wpdb->prefix . 'mtti_courses';
        $units_table = $wpdb->prefix . 'mtti_course_units';
        
        $lesson = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, c.course_name, c.course_code, cu.unit_name, cu.unit_code, u.display_name as created_by_name
             FROM {$table} l
             LEFT JOIN {$courses_table} c ON l.course_id = c.course_id
             LEFT JOIN {$units_table} cu ON l.unit_id = cu.unit_id
             LEFT JOIN {$wpdb->users} u ON l.created_by = u.ID
             WHERE l.lesson_id = %d",
            $lesson_id
        ));
        
        if (!$lesson) {
            wp_die('Lesson not found.');
        }
        
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons'); ?>" style="text-decoration: none;">← Lessons</a>
            </h1>
            
            <div style="background: white; border-radius: 8px; padding: 30px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <!-- Header -->
                <div style="border-bottom: 1px solid #eee; padding-bottom: 20px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <span style="background: #e3f2fd; padding: 4px 12px; border-radius: 4px; font-size: 12px; color: #1976D2;">
                                <?php echo esc_html($lesson->course_code); ?>
                            </span>
                            <?php if ($lesson->unit_name) : ?>
                            <span style="background: #f3e5f5; padding: 4px 12px; border-radius: 4px; font-size: 12px; color: #7B1FA2; margin-left: 5px;">
                                <?php echo esc_html($lesson->unit_code); ?>
                            </span>
                            <?php endif; ?>
                            
                            <h2 style="margin: 15px 0 5px; font-size: 28px;"><?php echo esc_html($lesson->title); ?></h2>
                            
                            <?php if ($lesson->description) : ?>
                            <p style="color: #666; margin: 0;"><?php echo esc_html($lesson->description); ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-lessons&action=edit&lesson_id=' . $lesson->lesson_id); ?>" 
                               class="button button-primary">Edit Lesson</a>
                        </div>
                    </div>
                    
                    <!-- Meta info -->
                    <div style="display: flex; gap: 20px; margin-top: 15px; font-size: 13px; color: #666;">
                        <?php if ($lesson->duration_minutes) : ?>
                        <span>⏱️ <?php echo intval($lesson->duration_minutes); ?> minutes</span>
                        <?php endif; ?>
                        <span>👁️ <?php echo number_format(intval($lesson->view_count)); ?> views</span>
                        <span>📅 Created <?php echo date('M j, Y', strtotime($lesson->created_at)); ?></span>
                        <?php if ($lesson->created_by_name) : ?>
                        <span>👤 By <?php echo esc_html($lesson->created_by_name); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Video embed -->
                <?php if ($lesson->content_type == 'video' && $lesson->content_url) : ?>
                <div style="margin-bottom: 30px;">
                    <?php echo $this->embed_video($lesson->content_url); ?>
                </div>
                <?php endif; ?>
                
                <!-- File download -->
                <?php if ($lesson->content_url && $lesson->content_type != 'video') : ?>
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px;">
                    <span style="font-size: 40px;">
                        <?php
                        $type_icons = array('pdf' => '📕', 'document' => '📘', 'presentation' => '📙', 'audio' => '🎵', 'file' => '📄');
                        echo $type_icons[$lesson->content_type] ?? '📄';
                        ?>
                    </span>
                    <div style="flex: 1;">
                        <strong><?php echo esc_html(basename($lesson->content_url)); ?></strong>
                        <?php if ($lesson->file_size) : ?>
                        <br><small style="color: #666;"><?php echo size_format($lesson->file_size); ?></small>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo esc_url($lesson->content_url); ?>" class="button" target="_blank" download>
                        📥 Download
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Lesson content -->
                <?php if ($lesson->content) : ?>
                <div class="lesson-content" style="line-height: 1.8; font-size: 15px;">
                    <?php echo wp_kses_post($lesson->content); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Embed video from YouTube or Vimeo URL
     */
    private function embed_video($url) {
        // YouTube
        if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $url, $matches) ||
            preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $video_id = $matches[1];
            return '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%; border-radius: 8px;">
                <iframe src="https://www.youtube.com/embed/' . esc_attr($video_id) . '" 
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;" 
                        frameborder="0" allowfullscreen></iframe>
            </div>';
        }
        
        // Vimeo
        if (preg_match('/vimeo\.com\/([0-9]+)/', $url, $matches)) {
            $video_id = $matches[1];
            return '<div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; max-width: 100%; border-radius: 8px;">
                <iframe src="https://player.vimeo.com/video/' . esc_attr($video_id) . '" 
                        style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;" 
                        frameborder="0" allowfullscreen></iframe>
            </div>';
        }
        
        // Direct video file
        return '<video controls style="width: 100%; max-width: 100%; border-radius: 8px;">
            <source src="' . esc_url($url) . '" type="video/mp4">
            Your browser does not support the video tag.
        </video>';
    }
}
