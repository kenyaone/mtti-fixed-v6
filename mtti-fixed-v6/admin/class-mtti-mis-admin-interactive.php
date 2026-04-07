<?php
/**
 * Interactive Content Manager — Upload & Library
 * No AI. Upload HTML files, manage by course, serve to students.
 */
if (!defined('WPINC')) die;

class MTTI_MIS_Admin_Interactive {

    public static function register_hooks() {
        $inst = new self();
        add_action('wp_ajax_mtti_admin_save_html',           array($inst, 'ajax_save_html'));
        add_action('wp_ajax_mtti_admin_delete_interactive',  array($inst, 'ajax_delete'));
        add_action('wp_ajax_mtti_admin_edit_interactive',    array($inst, 'ajax_edit'));
        add_action('wp_ajax_mtti_interactive_approve',       array($inst, 'ajax_approve'));
        add_action('wp_ajax_mtti_interactive_reject',        array($inst, 'ajax_reject'));
    }

    /* ── MAIN PAGE ──────────────────────────────────── */
    public function display() {
        global $wpdb;
        $p       = $wpdb->prefix . 'mtti_';
        $sub     = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : 'review';
        $nonce   = wp_create_nonce('mtti_interactive_admin_nonce');
        $courses = $wpdb->get_results(
            "SELECT course_id, course_code, course_name FROM {$p}courses WHERE status='Active' ORDER BY course_name"
        );

        echo '<div class="wrap"><h1 class="wp-heading-inline">📁 Interactive Content Manager</h1>';
        echo '<hr class="wp-header-end">';

        $tabs = array('review'=>'🔔 Review Queue', 'upload'=>'📤 Upload HTML', 'library'=>'📚 Library');
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
        foreach ($tabs as $k => $label) {
            // Show badge count on review tab
            if ($k === 'review') {
                $pending_count = intval($wpdb->get_var(
                    "SELECT COUNT(*) FROM {$p}lessons WHERE content_type='html_interactive' AND status='Pending Review'"
                ));
                if ($pending_count > 0) {
                    $label .= ' <span style="background:#d63638;color:white;font-size:11px;padding:1px 7px;border-radius:20px;margin-left:4px;">'.$pending_count.'</span>';
                }
            }
            $cls = $sub === $k ? ' nav-tab-active' : '';
            echo '<a href="'.admin_url("admin.php?page=mtti-mis-interactive&sub={$k}").'" class="nav-tab'.$cls.'">'.$label.'</a>';
        }
        echo '</nav>';

        if ($sub === 'review')  $this->tab_review($p, $wpdb, $nonce);
        elseif ($sub === 'upload') $this->tab_upload($courses, $nonce);
        else                   $this->tab_library($courses, $p, $wpdb, $nonce);
        echo '</div>';
    }

    /* ── TAB: UPLOAD ────────────────────────────────── */
    private function tab_upload($courses, $nonce) {
        ?>
        <div style="max-width:820px;">
        <div class="postbox"><div class="postbox-header"><h2 class="hndle">📤 Upload Interactive HTML File</h2></div>
        <div class="inside">

          <p>Upload any self-contained <strong>.html</strong> file — quizzes, lessons, simulations, games, exercises. All CSS and JavaScript must be inside the file (no external dependencies except Google Fonts).</p>

          <!-- Drop zone -->
          <div id="ul-dropzone"
            ondragover="event.preventDefault();this.style.borderColor='#2271b1';this.style.background='#f0f6fc'"
            ondragleave="this.style.borderColor='#8c8f94';this.style.background='#f9f9f9'"
            ondrop="ulDrop(event)"
            onclick="document.getElementById('ul-fileinput').click()"
            style="border:3px dashed #8c8f94;border-radius:8px;padding:50px 20px;text-align:center;cursor:pointer;background:#f9f9f9;transition:all .2s;margin-bottom:16px;">
            <div style="font-size:64px;margin-bottom:10px;">📄</div>
            <p style="font-size:18px;font-weight:700;margin:0 0 6px;color:#1d2327;">Drop your HTML file here</p>
            <p class="description" style="margin:0 0 10px;">or click to browse</p>
            <span style="background:#e6f4ea;color:#1d6b1e;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;">.html files only · max 5MB</span>
          </div>
          <input type="file" id="ul-fileinput" accept=".html,.htm" style="display:none;" onchange="ulPickFile(this.files[0])">

          <!-- File info + preview -->
          <div id="ul-fileinfo" style="display:none;background:#f0f6fc;border:1px solid #c2d5ee;border-radius:6px;padding:14px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
              <span style="font-size:28px;">✅</span>
              <div style="flex:1;">
                <div id="ul-fname" style="font-weight:700;font-size:14px;"></div>
                <div id="ul-fsize" style="font-size:12px;color:#666;"></div>
              </div>
              <button type="button" onclick="ulClear()" style="background:none;border:none;cursor:pointer;color:#666;font-size:18px;padding:4px;">✕</button>
            </div>
            <div style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden;">
              <div style="background:#f6f7f7;padding:4px 10px;font-size:11px;color:#646970;border-bottom:1px solid #dcdcde;">PREVIEW</div>
              <iframe id="ul-preview" style="width:100%;height:300px;border:none;" sandbox="allow-scripts"></iframe>
            </div>
          </div>

          <!-- Form fields -->
          <table class="form-table" style="margin-top:0;">
            <tr>
              <th style="width:120px;">Title <span style="color:#d63638">*</span></th>
              <td><input type="text" id="ul-title" class="regular-text" style="width:100%;max-width:420px;" placeholder="e.g. Computer Networks — Quiz 1"></td>
            </tr>
            <tr>
              <th>Course <span style="color:#d63638">*</span></th>
              <td>
                <select id="ul-course" style="min-width:300px;">
                  <option value="">— Select Course —</option>
                  <?php foreach ($courses as $c): ?>
                  <option value="<?php echo $c->course_id; ?>"><?php echo esc_html($c->course_code.' — '.$c->course_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
            <tr>
              <th>Description</th>
              <td><textarea id="ul-desc" rows="2" class="large-text" style="max-width:500px;" placeholder="Brief description (optional)"></textarea></td>
            </tr>
          </table>

          <div id="ul-error" style="display:none;background:#fce9e9;border-left:4px solid #d63638;padding:10px 14px;margin-bottom:14px;color:#d63638;font-size:13px;border-radius:2px;"></div>

          <button type="button" id="ul-savebtn" onclick="ulSave()"
            style="background:#1d6b1e;color:#fff;border:none;padding:12px 32px;border-radius:4px;font-size:15px;font-weight:700;cursor:pointer;">
            💾 Upload &amp; Save
          </button>
          <span id="ul-status" style="margin-left:14px;font-size:13px;"></span>

          <hr style="margin:24px 0;">
          <h3 style="margin-top:0;">Supported file types &amp; tips</h3>
          <table class="widefat striped" style="max-width:600px;">
            <thead><tr><th>Tool</th><th>How to export as HTML</th></tr></thead>
            <tbody>
              <tr><td>📊 <strong>iSpring / Articulate</strong></td><td>Publish → HTML5 → Package as single file</td></tr>
              <tr><td>🎮 <strong>Genially / H5P</strong></td><td>Share → Embed → Download standalone HTML</td></tr>
              <tr><td>📝 <strong>Google Forms quiz</strong></td><td>Use <em>Form to HTML converter</em> online tools</td></tr>
              <tr><td>💻 <strong>Hand-coded HTML</strong></td><td>Any .html file with embedded CSS + JS works</td></tr>
              <tr><td>🤖 <strong>ChatGPT / Gemini</strong></td><td>Ask: "Create a quiz on [topic] as a single HTML file"</td></tr>
            </tbody>
          </table>
        </div></div></div>

        <script>
        var ulNonce  = '<?php echo esc_js($nonce); ?>';
        var ulAjax   = (typeof ajaxurl!=='undefined') ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        var ulHTML   = '';

        function ulDrop(e) {
            e.preventDefault();
            document.getElementById('ul-dropzone').style.borderColor='#8c8f94';
            document.getElementById('ul-dropzone').style.background='#f9f9f9';
            ulPickFile(e.dataTransfer.files[0]);
        }

        function ulPickFile(file) {
            if (!file) return;
            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'html' && ext !== 'htm') {
                ulErr('Only .html files are supported.'); return;
            }
            if (file.size > 5 * 1024 * 1024) {
                ulErr('File too large. Maximum size is 5MB.'); return;
            }
            document.getElementById('ul-error').style.display = 'none';
            var r = new FileReader();
            r.onload = function(e) {
                ulHTML = e.target.result || '';
                document.getElementById('ul-fname').textContent = file.name;
                document.getElementById('ul-fsize').textContent = (file.size/1024).toFixed(1)+' KB · '+ulHTML.length.toLocaleString()+' characters';
                document.getElementById('ul-preview').srcdoc = ulHTML;
                document.getElementById('ul-fileinfo').style.display = 'block';
                // Auto-fill title from filename
                if (!document.getElementById('ul-title').value) {
                    document.getElementById('ul-title').value = file.name
                        .replace(/\.html?$/i, '').replace(/[-_]/g, ' ')
                        .replace(/\b\w/g, function(c){ return c.toUpperCase(); });
                }
            };
            r.readAsText(file);
        }

        function ulClear() {
            ulHTML = '';
            document.getElementById('ul-fileinput').value = '';
            document.getElementById('ul-fileinfo').style.display = 'none';
        }

        function ulErr(msg) {
            var el = document.getElementById('ul-error');
            el.textContent = '❌ ' + msg;
            el.style.display = 'block';
        }

        function ulSave() {
            if (!ulHTML)   { ulErr('Select an HTML file first.'); return; }
            var title = document.getElementById('ul-title').value.trim();
            var cid   = document.getElementById('ul-course').value;
            if (!title) { ulErr('Enter a title.'); return; }
            if (!cid)   { ulErr('Select a course.'); return; }
            document.getElementById('ul-error').style.display = 'none';

            var btn = document.getElementById('ul-savebtn');
            btn.disabled = true; btn.textContent = '⏳ Saving...';
            document.getElementById('ul-status').textContent = '';

            // Send HTML as file upload (FormData) to avoid POST encoding issues
            var fd = new FormData();
            fd.append('action',    'mtti_admin_save_html');
            fd.append('nonce',     ulNonce);
            fd.append('course_id', cid);
            fd.append('title',     title);
            fd.append('desc',      document.getElementById('ul-desc').value);
            fd.append('html_file', new Blob([ulHTML], {type:'text/html'}), 'interactive.html');

            jQuery.ajax({
                url: ulAjax, type: 'POST', data: fd,
                processData: false, contentType: false,
                success: function(res) {
                    btn.disabled = false; btn.textContent = '💾 Upload & Save';
                    if (res.success) {
                        var st = document.getElementById('ul-status');
                        st.innerHTML = '✅ Saved! <strong>' + res.data.chars + '</strong> chars. <a href="<?php echo esc_js(admin_url('admin.php?page=mtti-mis-interactive&sub=library')); ?>">View in Library →</a>';
                        st.style.color = '#1d6b1e';
                        ulClear();
                        document.getElementById('ul-title').value = '';
                        document.getElementById('ul-desc').value = '';
                        document.getElementById('ul-course').value = '';
                    } else {
                        ulErr(res.data || 'Upload failed.');
                    }
                },
                error: function(xhr) {
                    btn.disabled = false; btn.textContent = '💾 Upload & Save';
                    var code = xhr.status;
                    ulErr('Request failed (HTTP ' + code + ').' +
                        (code===413 ? ' File too large for server — ask host to increase upload_max_filesize.' :
                         code===500 ? ' Server error — check PHP error log.' : ''));
                    if (xhr.responseText) console.error('Response:', xhr.responseText.substring(0, 400));
                }
            });
        }
        </script>
        <?php
    }

    /* ── TAB: LIBRARY ───────────────────────────────── */
    private function tab_library($courses, $p, $wpdb, $nonce) {
        $fc   = isset($_GET['fc']) ? intval($_GET['fc']) : 0;
        $base = admin_url('admin.php?page=mtti-mis-interactive&sub=library');

        // Filter + add button row
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">';
        echo '<select onchange="location.href=\''.esc_js($base.'&fc=').'\'+this.value" style="padding:6px 10px;">';
        echo '<option value="">All Courses</option>';
        foreach ($courses as $c) {
            $sel = $fc === intval($c->course_id) ? 'selected' : '';
            echo '<option value="'.$c->course_id.'" '.$sel.'>'.esc_html($c->course_code.' — '.$c->course_name).'</option>';
        }
        echo '</select>';
        echo '<a href="'.admin_url('admin.php?page=mtti-mis-interactive&sub=upload').'" class="button button-primary">+ Upload New</a>';
        echo '</div>';

        $where = $fc ? $wpdb->prepare(' AND l.course_id=%d', $fc) : '';
        $items = $wpdb->get_results(
            "SELECT l.lesson_id, l.title, l.description, l.content, l.created_at, l.view_count, l.status,
                    c.course_code, c.course_name,
                    wu.display_name as uploader_name
             FROM {$p}lessons l
             LEFT JOIN {$p}courses c ON l.course_id=c.course_id
             LEFT JOIN {$wpdb->users} wu ON wu.ID = l.created_by
             WHERE l.content_type='html_interactive' {$where}
             ORDER BY FIELD(l.status,'Pending Review','Rejected','Published'), l.created_at DESC"
        );

        if (!$items) {
            echo '<div class="postbox" style="max-width:480px;"><div class="inside" style="text-align:center;padding:40px;">';
            echo '<p style="font-size:48px;margin:0 0 12px;">📭</p>';
            echo '<h3>No interactives yet</h3>';
            echo '<p class="description">Upload HTML files using the Upload tab.</p>';
            echo '<a href="'.admin_url('admin.php?page=mtti-mis-interactive&sub=upload').'" class="button button-primary" style="margin-top:10px;">+ Upload Your First Interactive</a>';
            echo '</div></div>';
            return;
        }

        // Stats bar
        $total = count($items);
        $total_views = array_sum(array_column($items, 'view_count'));
        echo '<div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">';
        echo '<div style="background:#f0f6fc;border:1px solid #c2d5ee;border-radius:6px;padding:10px 18px;font-size:13px;">';
        echo '<strong>'.$total.'</strong> interactive'.($total!==1?'s':'').' saved</div>';
        echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 18px;font-size:13px;">';
        echo '<strong>'.number_format($total_views).'</strong> total student views</div>';
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;">';
        foreach ($items as $it) {
            $del_nonce = wp_create_nonce('del_interactive_'.$it->lesson_id);
            $size_kb   = round(strlen($it->content) / 1024, 1);
            $status_cfg = [
                'Published'      => ['#2e7d32', '#e8f5e9', '✅ Published'],
                'Pending Review' => ['#ff8f00', '#fff8e1', '⏳ Pending Review'],
                'Rejected'       => ['#d63638', '#fff0f0', '❌ Rejected'],
            ];
            [$scol, $sbg, $slabel] = $status_cfg[$it->status] ?? ['#9e9e9e', '#fafafa', $it->status];
            $border_style = $it->status !== 'Published' ? 'border-top:3px solid '.$scol.';' : '';

            echo '<div class="postbox" style="margin:0;'.$border_style.'">
              <div class="postbox-header" style="background:'.$sbg.';">
                <h2 class="hndle" style="font-size:13px;padding:10px 12px 10px;flex:1;">⚡ '.esc_html($it->title).'</h2>
                <span style="margin-right:12px;font-size:11px;font-weight:700;color:'.$scol.';white-space:nowrap;">'.$slabel.'</span>
              </div>
              <div class="inside" style="padding:12px;">';

            echo '<p style="margin:0 0 6px;font-size:11px;color:#646970;">
                <strong>'.esc_html($it->course_code).'</strong> — '.esc_html($it->course_name).'
                '.($it->uploader_name ? ' &middot; By: '.esc_html($it->uploader_name) : '').'</p>';
            echo '<p style="margin:0 0 8px;font-size:11px;color:#9ca3af;">
                '.date('d M Y, g:ia', strtotime($it->created_at)).' &middot; '.$size_kb.' KB &middot; '.number_format(intval($it->view_count)).' views</p>';

            if ($it->description) {
                echo '<p style="font-size:12px;color:#555;margin:0 0 10px;line-height:1.4;">'.esc_html($it->description).'</p>';
            }

            // Thumbnail preview (non-interactive, pointer-events:none)
            echo '<div style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden;margin-bottom:12px;position:relative;height:160px;background:#f9f9f9;">
                <iframe srcdoc="'.esc_attr($it->content).'" style="width:166%;height:266px;border:none;pointer-events:none;transform:scale(0.6);transform-origin:0 0;" sandbox="allow-scripts"></iframe>
                <div style="position:absolute;top:0;left:0;right:0;bottom:0;cursor:pointer;" onclick="libPreview('.$it->lesson_id.')"></div>
              </div>';

            // Action buttons
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" onclick="libPreview('.$it->lesson_id.')" class="button button-primary button-small">👁 Full Preview</button>
                <button type="button" onclick="libDownload('.$it->lesson_id.')" class="button button-small">⬇ Download</button>
                <button type="button" onclick="mttiIxGenQuiz('.$it->lesson_id.','.$it->course_id.',\''.esc_js($it->title).'\')" class="button button-small" style="color:#7B1FA2;border-color:#7B1FA2;">🤖 Gen Quiz</button>
                <button type="button" onclick="libDelete('.$it->lesson_id.',\''.$del_nonce.'\')" class="button button-small" style="color:#d63638;border-color:#d63638;">🗑 Delete</button>
              </div>';

            echo '<script>window.libC'.$it->lesson_id.'='.json_encode($it->content).';</script>';
            echo '</div></div>';
        }
        echo '</div>';

        // Full-screen preview modal
        ?>
        <div id="lib-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.8);z-index:100000;padding:16px;">
          <div style="background:#fff;border-radius:8px;height:100%;display:flex;flex-direction:column;overflow:hidden;">
            <div style="background:#1d6b1e;color:#fff;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
              <strong id="lib-modal-title">Preview</strong>
              <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" onclick="libModalDownload()" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px;">⬇ Download</button>
                <button type="button" onclick="document.getElementById('lib-modal').style.display='none'" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;line-height:1;padding:0 4px;">✕</button>
              </div>
            </div>
            <iframe id="lib-modal-frame" style="flex:1;border:none;" sandbox="allow-scripts allow-forms"></iframe>
          </div>
        </div>

        <script>
        var libModalId = 0;
        var libAjax    = (typeof ajaxurl!=='undefined') ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        var libNonce   = '<?php echo esc_js($nonce); ?>';

        function libPreview(id) {
            var c = window['libC'+id]; if (!c) return;
            document.getElementById('lib-modal-title').textContent = 'Interactive Preview';
            document.getElementById('lib-modal-frame').srcdoc = c;
            libModalId = id;
            document.getElementById('lib-modal').style.display = 'block';
        }
        function libModalDownload() { libDownload(libModalId); }
        function libDownload(id) {
            var c = window['libC'+id]; if (!c) return;
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([c], {type:'text/html'}));
            a.download = 'MTTI-Interactive-'+id+'.html';
            a.click();
        }
        function libDelete(id, dn) {
            if (!confirm('Delete this interactive? Students will no longer be able to access it.')) return;
            jQuery.post(libAjax, {action:'mtti_admin_delete_interactive', nonce:dn, id:id}, function(res) {
                if (res.success) location.reload();
                else alert('Delete failed: '+(res.data||'unknown error'));
            });
        }
        </script>
        <?php
    }

    /* ── AJAX: Save HTML ────────────────────────────── */
    public function ajax_save_html() {
        check_ajax_referer('mtti_interactive_admin_nonce', 'nonce');
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;
        $cid   = intval($_POST['course_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $desc  = sanitize_textarea_field($_POST['desc'] ?? '');

        if (!$cid)   wp_send_json_error('Course is required.');
        if (!$title) wp_send_json_error('Title is required.');

        // Read from uploaded file (preserves exact bytes, no encoding corruption)
        $html = '';
        if (!empty($_FILES['html_file']['tmp_name']) && $_FILES['html_file']['error'] === UPLOAD_ERR_OK) {
            $html = file_get_contents($_FILES['html_file']['tmp_name']);
        }
        // Fallback: raw POST (backwards compat)
        if (empty($html) && !empty($_POST['html'])) {
            $html = wp_unslash($_POST['html']);
        }

        if (strlen($html) < 50) {
            wp_send_json_error('HTML content is empty or too short. The file may not have uploaded correctly.');
        }
        if (strpos($html,'<html')===false && strpos($html,'<!DOCTYPE')===false && strpos($html,'<body')===false) {
            wp_send_json_error('This does not look like a valid HTML file. First 80 chars: '.substr(trim($html),0,80));
        }

        // Check for existing record with same title + course to avoid duplicates
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT lesson_id FROM {$wpdb->prefix}mtti_lessons WHERE course_id=%d AND title=%s AND content_type='html_interactive' LIMIT 1",
            $cid, $title
        ));
        if ($existing) {
            // Update instead of duplicate
            $wpdb->update($wpdb->prefix.'mtti_lessons',
                array('content'=>$html, 'description'=>$desc),
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
            'status'       => 'Published',
            'created_by'   => get_current_user_id(),
        ));

        if ($wpdb->insert_id) {
            wp_send_json_success(array('id'=>$wpdb->insert_id, 'chars'=>number_format(strlen($html)), 'updated'=>false));
        } else {
            wp_send_json_error('Database error: '.$wpdb->last_error);
        }
    }

    /* ── AJAX: Delete ───────────────────────────────── */
    public function ajax_delete() {
        $id = intval($_POST['id'] ?? 0);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'del_interactive_'.$id)) {
            wp_send_json_error('Security check failed');
        }
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        global $wpdb;
        $wpdb->delete($wpdb->prefix.'mtti_lessons', array('lesson_id'=>$id, 'content_type'=>'html_interactive'));
        wp_send_json_success();
    }

    /* ── AJAX: Edit title/desc ──────────────────────── */
    public function ajax_edit() {
        check_ajax_referer('mtti_interactive_admin_nonce', 'nonce');
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        global $wpdb;
        $id    = intval($_POST['id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $desc  = sanitize_textarea_field($_POST['desc'] ?? '');
        if (!$id || !$title) wp_send_json_error('Missing data');
        $wpdb->update($wpdb->prefix.'mtti_lessons',
            array('title'=>$title, 'description'=>$desc),
            array('lesson_id'=>$id, 'content_type'=>'html_interactive')
        );
        wp_send_json_success();
    }

    /* ── AJAX: Approve interactive ───────────────────── */
    public function ajax_approve() {
        check_ajax_referer('mtti_interactive_admin_nonce', 'nonce');
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Missing ID');
        $wpdb->update(
            $wpdb->prefix . 'mtti_lessons',
            array('status' => 'Published'),
            array('lesson_id' => $id, 'content_type' => 'html_interactive')
        );
        wp_send_json_success(array('status' => 'Published'));
    }

    /* ── AJAX: Reject interactive ───────────────────── */
    public function ajax_reject() {
        check_ajax_referer('mtti_interactive_admin_nonce', 'nonce');
        if (!current_user_can('manage_mtti') && !current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        global $wpdb;
        $id     = intval($_POST['id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        if (!$id) wp_send_json_error('Missing ID');
        $wpdb->update(
            $wpdb->prefix . 'mtti_lessons',
            array('status' => 'Rejected', 'description' => $reason ?: null),
            array('lesson_id' => $id, 'content_type' => 'html_interactive')
        );
        wp_send_json_success(array('status' => 'Rejected'));
    }

    /* ── TAB: REVIEW QUEUE ──────────────────────────── */
    private function tab_review($p, $wpdb, $nonce) {

        $pending = $wpdb->get_results(
            "SELECT l.lesson_id, l.title, l.description, l.content, l.created_at,
                    c.course_code, c.course_name,
                    u.unit_name, u.unit_code,
                    wu.display_name as uploader_name
             FROM {$p}lessons l
             LEFT JOIN {$p}courses c ON l.course_id = c.course_id
             LEFT JOIN {$p}course_units u ON l.unit_id = u.unit_id
             LEFT JOIN {$wpdb->users} wu ON wu.ID = l.created_by
             WHERE l.content_type = 'html_interactive' AND l.status = 'Pending Review'
             ORDER BY l.created_at ASC"
        );

        $rejected = $wpdb->get_results(
            "SELECT l.lesson_id, l.title, l.description, l.created_at,
                    c.course_code, c.course_name,
                    wu.display_name as uploader_name
             FROM {$p}lessons l
             LEFT JOIN {$p}courses c ON l.course_id = c.course_id
             LEFT JOIN {$wpdb->users} wu ON wu.ID = l.created_by
             WHERE l.content_type = 'html_interactive' AND l.status = 'Rejected'
             ORDER BY l.created_at DESC LIMIT 20"
        );

        if (!$pending && !$rejected) {
            echo '<div style="text-align:center;padding:60px 20px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;">';
            echo '<div style="font-size:56px;margin-bottom:12px;">✅</div>';
            echo '<h2>All clear — nothing to review</h2>';
            echo '<p style="color:#666;">When teachers upload interactives, they will appear here for your approval before students can see them.</p>';
            echo '</div>';
            return;
        }

        if ($pending) {
            echo '<h2 style="margin-top:0;color:#d63638;">⏳ Pending Approval (' . count($pending) . ')</h2>';
            echo '<p style="color:#666;margin-bottom:20px;">These were uploaded by teachers and are <strong>not visible to students</strong> until you approve them.</p>';

            foreach ($pending as $it) {
                $size_kb = round(strlen($it->content) / 1024, 1);
                echo '<div style="background:#fff;border:1px solid #ddd;border-left:4px solid #ff8f00;border-radius:4px;padding:0;margin-bottom:20px;overflow:hidden;">';

                // Header bar
                echo '<div style="background:#fff8e1;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">';
                echo '<div>';
                echo '<div style="font-size:15px;font-weight:700;">⚡ ' . esc_html($it->title) . '</div>';
                echo '<div style="font-size:12px;color:#666;margin-top:2px;">';
                echo '<strong>' . esc_html($it->course_code) . '</strong> — ' . esc_html($it->course_name);
                if ($it->unit_name) echo ' &middot; Unit: ' . esc_html($it->unit_code . ' ' . $it->unit_name);
                echo ' &middot; Uploaded by: <strong>' . esc_html($it->uploader_name ?: 'Unknown') . '</strong>';
                echo ' &middot; ' . date('d M Y, g:ia', strtotime($it->created_at));
                echo ' &middot; ' . $size_kb . ' KB';
                echo '</div></div>';
                echo '<span style="background:#ff8f00;color:white;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;">⏳ PENDING</span>';
                echo '</div>';

                // Preview iframe
                echo '<div style="position:relative;background:#f9f9f9;border-top:1px solid #eee;border-bottom:1px solid #eee;">';
                echo '<div style="padding:6px 16px;background:#f1f1f1;font-size:11px;color:#666;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">PREVIEW</div>';
                echo '<div style="position:relative;overflow:hidden;height:320px;">';
                echo '<iframe srcdoc="' . esc_attr($it->content) . '" style="width:100%;height:320px;border:none;" sandbox="allow-scripts allow-same-origin"></iframe>';
                echo '</div></div>';

                // Action bar
                echo '<div style="padding:14px 16px;display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">';

                // Approve button
                echo '<button type="button" onclick="rqApprove(' . $it->lesson_id . ', this)"
                    style="background:#2e7d32;color:white;border:none;padding:10px 20px;border-radius:4px;font-size:14px;font-weight:700;cursor:pointer;">
                    ✅ Approve — Publish to Students
                </button>';

                // Reject section
                echo '<div style="flex:1;min-width:240px;">';
                echo '<input type="text" id="rq-reason-' . $it->lesson_id . '" placeholder="Rejection reason (optional)..."
                    style="width:100%;padding:9px;border:1px solid #ddd;border-radius:4px;font-size:13px;box-sizing:border-box;margin-bottom:6px;">';
                echo '<button type="button" onclick="rqReject(' . $it->lesson_id . ', this)"
                    style="background:#d63638;color:white;border:none;padding:10px 20px;border-radius:4px;font-size:14px;font-weight:700;cursor:pointer;">
                    ❌ Reject
                </button>';
                echo '</div>';

                echo '<div id="rq-msg-' . $it->lesson_id . '" style="align-self:center;font-size:13px;font-weight:600;"></div>';
                echo '</div></div>';
            }
        }

        if ($rejected) {
            echo '<h2 style="margin-top:30px;color:#666;">❌ Recently Rejected (' . count($rejected) . ')</h2>';
            echo '<table class="widefat striped" style="margin-top:8px;">';
            echo '<thead><tr><th>Title</th><th>Course</th><th>Uploaded By</th><th>Rejection Reason</th><th>Date</th><th></th></tr></thead><tbody>';
            foreach ($rejected as $it) {
                echo '<tr>';
                echo '<td>⚡ <strong>' . esc_html($it->title) . '</strong></td>';
                echo '<td>' . esc_html($it->course_code . ' — ' . $it->course_name) . '</td>';
                echo '<td>' . esc_html($it->uploader_name ?: '—') . '</td>';
                echo '<td style="color:#d63638;font-size:12px;">' . esc_html($it->description ?: '—') . '</td>';
                echo '<td style="font-size:12px;">' . date('d M Y', strtotime($it->created_at)) . '</td>';
                echo '<td><button type="button" onclick="rqApprove(' . $it->lesson_id . ', this)" style="background:#2e7d32;color:white;border:none;padding:5px 12px;border-radius:3px;cursor:pointer;font-size:12px;">✅ Approve</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        ?>
        <script>
        (function(){
        var rqNonce = '<?php echo esc_js($nonce); ?>';
        var rqAjax  = (typeof ajaxurl!=='undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';

        window.rqApprove = function(id, btn) {
            if (!confirm('Approve this interactive? It will become visible to students immediately.')) return;
            btn.disabled = true; btn.textContent = '⏳ Approving...';
            var msg = document.getElementById('rq-msg-'+id);
            jQuery.post(rqAjax, {action:'mtti_interactive_approve', nonce:rqNonce, id:id}, function(res) {
                if (res.success) {
                    if (msg) { msg.textContent = '✅ Approved and published!'; msg.style.color='#2e7d32'; }
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    btn.disabled = false; btn.textContent = '✅ Approve';
                    alert('Error: ' + (res.data || 'Unknown error'));
                }
            });
        };

        window.rqReject = function(id, btn) {
            var reason = document.getElementById('rq-reason-'+id).value.trim();
            if (!confirm('Reject this interactive?' + (reason ? '\nReason: '+reason : ''))) return;
            btn.disabled = true; btn.textContent = '⏳ Rejecting...';
            var msg = document.getElementById('rq-msg-'+id);
            jQuery.post(rqAjax, {action:'mtti_interactive_reject', nonce:rqNonce, id:id, reason:reason}, function(res) {
                if (res.success) {
                    if (msg) { msg.textContent = '❌ Rejected.'; msg.style.color='#d63638'; }
                    setTimeout(function(){ location.reload(); }, 800);
                } else {
                    btn.disabled = false; btn.textContent = '❌ Reject';
                    alert('Error: ' + (res.data || 'Unknown error'));
                }
            });
        };
        })();
        </script>

        <!-- AI Quiz Modal for Interactives -->
        <div id="mtti-quiz-modal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:680px;width:94%;max-height:88vh;overflow-y:auto;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.3);">
                <button onclick="document.getElementById('mtti-quiz-modal').style.display='none';" style="position:absolute;top:14px;right:18px;background:none;border:none;font-size:22px;cursor:pointer;color:#666;">✕</button>
                <h2 style="margin:0 0 4px;font-size:18px;">🤖 AI Quiz Generator</h2>
                <p id="mtti-quiz-lesson-name" style="color:#666;font-size:13px;margin:0 0 18px;"></p>
                <div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                    <label style="font-size:12px;font-weight:700;color:#555;">Questions: <select id="mtti-quiz-count" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;"><option value="5">5</option><option value="8" selected>8</option><option value="10">10</option></select></label>
                    <label style="font-size:12px;font-weight:700;color:#555;">Types: <select id="mtti-quiz-types" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;"><option value="mixed" selected>Mixed</option><option value="mcq">MCQ only</option><option value="fib">Fill-in-Blank</option><option value="short">Short Answer</option></select></label>
                    <label style="font-size:12px;font-weight:700;color:#555;">Difficulty: <select id="mtti-quiz-diff" style="margin-left:6px;padding:4px 8px;border-radius:5px;border:1px solid #ddd;"><option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option></select></label>
                </div>
                <div style="margin-bottom:14px;"><label style="font-size:12px;font-weight:700;color:#555;display:block;margin-bottom:4px;">Extra context / key points (optional):</label><textarea id="mtti-quiz-context" rows="3" placeholder="Paste key points, learning outcomes, or topic notes..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-size:13px;resize:vertical;"></textarea></div>
                <button id="mtti-quiz-generate-btn" onclick="mttiDoGenerateQuiz()" style="background:#7B1FA2;color:#fff;border:none;border-radius:7px;padding:10px 24px;font-size:14px;font-weight:700;cursor:pointer;width:100%;margin-bottom:14px;">✨ Generate Questions</button>
                <div id="mtti-quiz-spinner" style="display:none;text-align:center;padding:24px;color:#888;font-size:13px;">⏳ Generating interactive quiz — please wait…</div>
                <div id="mtti-quiz-result" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                        <h3 style="margin:0;font-size:15px;">Questions Preview</h3>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button onclick="mttiPostQuizToCourse()" id="mtti-quiz-post-btn" style="background:#0a5e2a;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;opacity:.5;" disabled>📤 Post to Course</button>
                            <a id="mtti-quiz-download-btn" href="#" target="_blank" style="background:#1565C0;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;">⬇ Download HTML</a>
                            <button onclick="mttiDoGenerateQuiz()" style="background:#555;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;">🔄 Regenerate</button>
                        </div>
                    </div>
                    <div id="mtti-quiz-questions-wrap" style="border:1px solid #e0e0e0;border-radius:8px;padding:14px;background:#fafafa;max-height:320px;overflow-y:auto;font-size:13px;line-height:1.7;"></div>
                    <div id="mtti-quiz-status" style="margin-top:8px;font-size:12px;font-weight:700;color:#2E7D32;"></div>
                </div>
            </div>
        </div>
        <script>
        var mttiCurrentLessonId=0,mttiCurrentCourseId=0,mttiCurrentLessonTitle='',mttiCurrentFileUrl='';
        var mttiQuizNonce='<?php echo wp_create_nonce('mtti_ai_quiz'); ?>';
        function mttiIxGenQuiz(lid,cid,title){
            mttiCurrentLessonId=lid;mttiCurrentCourseId=cid;mttiCurrentLessonTitle=title;mttiCurrentFileUrl='';
            document.getElementById('mtti-quiz-lesson-name').innerText='⚡ '+title;
            document.getElementById('mtti-quiz-context').value='';
            document.getElementById('mtti-quiz-result').style.display='none';
            document.getElementById('mtti-quiz-spinner').style.display='none';
            document.getElementById('mtti-quiz-generate-btn').style.display='block';
            document.getElementById('mtti-quiz-status').innerText='';
            document.getElementById('mtti-quiz-post-btn').disabled=true;
            document.getElementById('mtti-quiz-post-btn').style.opacity='.5';
            document.getElementById('mtti-quiz-modal').style.display='flex';
        }
        function mttiDoGenerateQuiz(){
            document.getElementById('mtti-quiz-generate-btn').style.display='none';
            document.getElementById('mtti-quiz-result').style.display='none';
            document.getElementById('mtti-quiz-spinner').style.display='block';
            document.getElementById('mtti-quiz-status').innerText='';
            jQuery.ajax({url:ajaxurl,method:'POST',data:{action:'mtti_ai_generate_quiz',nonce:mttiQuizNonce,lesson_id:mttiCurrentLessonId,course_id:mttiCurrentCourseId,title:mttiCurrentLessonTitle,context:document.getElementById('mtti-quiz-context').value,count:document.getElementById('mtti-quiz-count').value,types:document.getElementById('mtti-quiz-types').value,difficulty:document.getElementById('mtti-quiz-diff').value},
            success:function(r){
                document.getElementById('mtti-quiz-spinner').style.display='none';
                document.getElementById('mtti-quiz-generate-btn').style.display='block';
                if(r.success){
                    mttiCurrentFileUrl=r.data.file_url;
                    document.getElementById('mtti-quiz-questions-wrap').innerHTML=r.data.html;
                    document.getElementById('mtti-quiz-download-btn').href=r.data.file_url;
                    document.getElementById('mtti-quiz-download-btn').download=r.data.filename;
                    document.getElementById('mtti-quiz-status').innerText='✅ '+r.data.q_count+' questions ready. Click Post to Course.';
                    document.getElementById('mtti-quiz-post-btn').disabled=false;
                    document.getElementById('mtti-quiz-post-btn').style.opacity='1';
                    document.getElementById('mtti-quiz-result').style.display='block';
                } else { alert('Error: '+(r.data||'Check Claude API key in Settings → MTTI Think Sharp')); }
            },error:function(){document.getElementById('mtti-quiz-spinner').style.display='none';document.getElementById('mtti-quiz-generate-btn').style.display='block';alert('Network error.');}});
        }
        function mttiPostQuizToCourse(){
            if(!mttiCurrentCourseId||!mttiCurrentFileUrl) return;
            var btn=document.getElementById('mtti-quiz-post-btn');
            var status=document.getElementById('mtti-quiz-status');
            btn.disabled=true;btn.innerText='⏳ Posting…';
            jQuery.ajax({url:ajaxurl,method:'POST',data:{action:'mtti_ai_post_quiz_to_course',nonce:mttiQuizNonce,course_id:mttiCurrentCourseId,lesson_id:mttiCurrentLessonId,title:mttiCurrentLessonTitle,file_url:mttiCurrentFileUrl},
            success:function(r){
                btn.innerText='📤 Post to Course';
                if(r.success){btn.innerText='✅ Posted!';btn.style.background='#2E7D32';status.style.color='#2E7D32';status.innerHTML=r.data;}
                else{btn.disabled=false;status.style.color='#C62828';status.innerText='❌ '+(r.data||'Could not post. Try again.');}
            },error:function(){btn.disabled=false;btn.innerText='📤 Post to Course';status.style.color='#C62828';status.innerText='❌ Network error.';}});
        }
        </script>
        <?php
    }
}
