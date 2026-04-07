<?php
/**
 * Notice Board Admin Class - Complete Implementation
 */
class MTTI_MIS_Admin_Notice_Board {
    
    private $db;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        if (isset($_POST['mtti_notice_submit'])) {
            check_admin_referer('mtti_notice_action', 'mtti_notice_nonce');
            $this->handle_form_submission();
            return;
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $notice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        switch ($action) {
            case 'add':
                $this->display_add_form();
                break;
            case 'edit':
                $this->display_edit_form($notice_id);
                break;
            case 'delete':
                $this->delete_notice($notice_id);
                break;
            default:
                $this->display_list();
        }
    }
    
    private function display_list() {
        global $wpdb;
        $table = $this->db->get_table_name('notices');
        
        $notices = $wpdb->get_results(
            "SELECT n.*, u.display_name as author_name
             FROM {$table} n
             LEFT JOIN {$wpdb->users} u ON n.created_by = u.ID
             ORDER BY n.priority DESC, n.created_at DESC"
        );
        ?>
        <div class="wrap">
            <h1>Notice Board <a href="?page=mtti-mis-notice-board&action=add" class="page-title-action">Post Notice</a></h1>
            
            <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php 
                    if ($_GET['message'] == 'created') echo 'Notice posted successfully!';
                    if ($_GET['message'] == 'updated') echo 'Notice updated successfully!';
                    if ($_GET['message'] == 'deleted') echo 'Notice deleted successfully!';
                    ?>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="mtti-notice-board">
                <?php if ($notices) : foreach ($notices as $notice) : ?>
                <div class="mtti-notice-item mtti-notice-<?php echo strtolower($notice->priority); ?>">
                    <div class="mtti-notice-header">
                        <h3>
                            <?php if ($notice->priority == 'High') : ?>
                            <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                            <?php endif; ?>
                            <?php echo esc_html($notice->title); ?>
                        </h3>
                        <div class="mtti-notice-meta">
                            <span class="mtti-notice-category mtti-category-<?php echo strtolower($notice->category); ?>">
                                <?php echo esc_html($notice->category); ?>
                            </span>
                            <span class="mtti-notice-date">
                                <?php echo date('M j, Y', strtotime($notice->created_at)); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mtti-notice-content">
                        <?php echo wpautop(esc_html($notice->content)); ?>
                    </div>
                    
                    <div class="mtti-notice-footer">
                        <span class="mtti-notice-author">Posted by: <?php echo esc_html($notice->author_name); ?></span>
                        <span class="mtti-notice-audience">
                            <span class="dashicons dashicons-groups"></span>
                            <?php echo esc_html($notice->target_audience); ?>
                        </span>
                        <span class="mtti-notice-status">
                            <span class="mtti-status mtti-status-<?php echo strtolower($notice->status); ?>">
                                <?php echo esc_html($notice->status); ?>
                            </span>
                        </span>
                    </div>
                    
                    <div class="mtti-notice-actions">
                        <a href="?page=mtti-mis-notice-board&action=edit&id=<?php echo $notice->notice_id; ?>" class="button button-small">Edit</a>
                        <a href="?page=mtti-mis-notice-board&action=delete&id=<?php echo $notice->notice_id; ?>" 
                           class="button button-small" 
                           onclick="return confirm('Are you sure you want to delete this notice?');"
                           style="color: #dc3232;">Delete</a>
                    </div>
                </div>
                <?php endforeach; else : ?>
                <div class="mtti-notice-empty">
                    <p>No notices posted yet. <a href="?page=mtti-mis-notice-board&action=add">Post first notice</a></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .mtti-notice-board {
            margin-top: 20px;
        }
        .mtti-notice-item {
            background: white;
            border-left: 4px solid #2E7D32;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .mtti-notice-high {
            border-left-color: #dc3232;
            background: #fff5f5;
        }
        .mtti-notice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .mtti-notice-header h3 {
            margin: 0;
            color: #2E7D32;
        }
        .mtti-notice-meta {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .mtti-notice-category {
            background: #FF9800;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .mtti-category-academic { background: #1976D2; }
        .mtti-category-administrative { background: #FF9800; }
        .mtti-category-event { background: #9C27B0; }
        .mtti-category-urgent { background: #dc3232; }
        .mtti-notice-date {
            color: #666;
            font-size: 13px;
        }
        .mtti-notice-content {
            margin: 15px 0;
            line-height: 1.6;
        }
        .mtti-notice-footer {
            display: flex;
            gap: 20px;
            align-items: center;
            padding-top: 10px;
            border-top: 1px solid #eee;
            font-size: 13px;
            color: #666;
        }
        .mtti-notice-actions {
            margin-top: 10px;
        }
        .mtti-notice-empty {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border: 2px dashed #ddd;
        }
        </style>
        <?php
    }
    
    private function display_add_form() {
        ?>
        <div class="wrap">
            <h1>Post New Notice</h1>
            <form method="post">
                <?php wp_nonce_field('mtti_notice_action', 'mtti_notice_nonce'); ?>
                <input type="hidden" name="action" value="add">
                
                <table class="form-table">
                    <tr>
                        <th><label for="title">Notice Title *</label></th>
                        <td>
                            <input type="text" name="title" id="title" class="large-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="content">Content *</label></th>
                        <td>
                            <textarea name="content" id="content" rows="10" class="large-text" required></textarea>
                            <p class="description">Write the full notice content</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="category">Category *</label></th>
                        <td>
                            <select name="category" id="category" class="regular-text" required>
                                <option value="Academic">Academic</option>
                                <option value="Administrative">Administrative</option>
                                <option value="Event">Event/Activity</option>
                                <option value="Urgent">Urgent</option>
                                <option value="General">General</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="priority">Priority *</label></th>
                        <td>
                            <select name="priority" id="priority" class="regular-text" required>
                                <option value="Normal">Normal</option>
                                <option value="High">High Priority</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="target_audience">Target Audience *</label></th>
                        <td>
                            <select name="target_audience" id="target_audience" class="regular-text" required>
                                <option value="All">Everyone (Students, Staff, Admin)</option>
                                <option value="Students">Students Only</option>
                                <option value="Staff">Staff Only</option>
                                <option value="Admin">Admin Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="expiry_date">Expiry Date</label></th>
                        <td>
                            <input type="date" name="expiry_date" id="expiry_date" class="regular-text">
                            <p class="description">Optional: Notice will auto-archive after this date</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status *</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text" required>
                                <option value="Active">Active (Visible)</option>
                                <option value="Draft">Draft (Not visible)</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_notice_submit" class="button button-primary" value="Post Notice">
                    <a href="?page=mtti-mis-notice-board" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function display_edit_form($notice_id) {
        global $wpdb;
        $table = $this->db->get_table_name('notices');
        
        $notice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE notice_id = %d", $notice_id));
        
        if (!$notice) wp_die('Notice not found');
        ?>
        <div class="wrap">
            <h1>Edit Notice</h1>
            <form method="post">
                <?php wp_nonce_field('mtti_notice_action', 'mtti_notice_nonce'); ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="notice_id" value="<?php echo $notice_id; ?>">
                
                <table class="form-table">
                    <tr>
                        <th><label for="title">Notice Title *</label></th>
                        <td>
                            <input type="text" name="title" id="title" class="large-text" required value="<?php echo esc_attr($notice->title); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="content">Content *</label></th>
                        <td>
                            <textarea name="content" id="content" rows="10" class="large-text" required><?php echo esc_textarea($notice->content); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="category">Category *</label></th>
                        <td>
                            <select name="category" id="category" class="regular-text" required>
                                <option value="Academic" <?php selected($notice->category, 'Academic'); ?>>Academic</option>
                                <option value="Administrative" <?php selected($notice->category, 'Administrative'); ?>>Administrative</option>
                                <option value="Event" <?php selected($notice->category, 'Event'); ?>>Event/Activity</option>
                                <option value="Urgent" <?php selected($notice->category, 'Urgent'); ?>>Urgent</option>
                                <option value="General" <?php selected($notice->category, 'General'); ?>>General</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="priority">Priority *</label></th>
                        <td>
                            <select name="priority" id="priority" class="regular-text" required>
                                <option value="Normal" <?php selected($notice->priority, 'Normal'); ?>>Normal</option>
                                <option value="High" <?php selected($notice->priority, 'High'); ?>>High Priority</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="target_audience">Target Audience *</label></th>
                        <td>
                            <select name="target_audience" id="target_audience" class="regular-text" required>
                                <option value="All" <?php selected($notice->target_audience, 'All'); ?>>Everyone</option>
                                <option value="Students" <?php selected($notice->target_audience, 'Students'); ?>>Students Only</option>
                                <option value="Staff" <?php selected($notice->target_audience, 'Staff'); ?>>Staff Only</option>
                                <option value="Admin" <?php selected($notice->target_audience, 'Admin'); ?>>Admin Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="expiry_date">Expiry Date</label></th>
                        <td>
                            <input type="date" name="expiry_date" id="expiry_date" class="regular-text" value="<?php echo esc_attr($notice->expiry_date); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status *</label></th>
                        <td>
                            <select name="status" id="status" class="regular-text" required>
                                <option value="Active" <?php selected($notice->status, 'Active'); ?>>Active (Visible)</option>
                                <option value="Draft" <?php selected($notice->status, 'Draft'); ?>>Draft (Not visible)</option>
                                <option value="Archived" <?php selected($notice->status, 'Archived'); ?>>Archived</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="mtti_notice_submit" class="button button-primary" value="Update Notice">
                    <a href="?page=mtti-mis-notice-board" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private function handle_form_submission() {
        global $wpdb;
        $table = $this->db->get_table_name('notices');
        
        $action = $_POST['action'];
        
        $data = array(
            'title' => sanitize_text_field($_POST['title']),
            'content' => sanitize_textarea_field($_POST['content']),
            'category' => sanitize_text_field($_POST['category']),
            'priority' => sanitize_text_field($_POST['priority']),
            'target_audience' => sanitize_text_field($_POST['target_audience']),
            'expiry_date' => !empty($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null,
            'status' => sanitize_text_field($_POST['status'])
        );
        
        if ($action == 'add') {
            $data['created_by'] = get_current_user_id();
            $wpdb->insert($table, $data);
            wp_redirect(admin_url('admin.php?page=mtti-mis-notice-board&message=created'));
        } else {
            $notice_id = intval($_POST['notice_id']);
            $wpdb->update($table, $data, array('notice_id' => $notice_id));
            wp_redirect(admin_url('admin.php?page=mtti-mis-notice-board&message=updated'));
        }
        exit;
    }
    
    private function delete_notice($notice_id) {
        global $wpdb;
        $table = $this->db->get_table_name('notices');
        
        // Soft delete notice
        $notice_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE notice_id = %d", $notice_id), ARRAY_A);
        if ($notice_data) {
            require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
            MTTI_MIS_Admin_Trash::create_table();
            $trash = new MTTI_MIS_Admin_Trash();
            $trash->soft_delete('notice', $notice_id, $notice_data['title'] ?: 'Notice #' . $notice_id, $notice_data);
        }
        $wpdb->delete($table, array('notice_id' => $notice_id));
        
        wp_redirect(admin_url('admin.php?page=mtti-mis-notice-board&message=deleted'));
        exit;
    }
}
