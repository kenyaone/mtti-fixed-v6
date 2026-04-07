<?php
/**
 * MTTI MIS Recycle Bin — Soft Delete & Recovery System
 * 
 * Instead of permanently deleting records, this system moves them to a trash table
 * where they can be reviewed and restored by admins.
 * 
 * @package MTTI_MIS
 * @since 7.2.0
 */

if (!defined('ABSPATH')) exit;

class MTTI_MIS_Admin_Trash {
    
    private $trash_table;
    
    public function __construct() {
        global $wpdb;
        $this->trash_table = $wpdb->prefix . 'mtti_trash';
    }
    
    /**
     * Create the trash table if it doesn't exist
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'mtti_trash';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            trash_id bigint(20) NOT NULL AUTO_INCREMENT,
            record_type varchar(50) NOT NULL COMMENT 'student, payment, lesson, material, assignment, unit, notice, staff, scheme_week',
            record_id bigint(20) NOT NULL COMMENT 'Original primary key ID',
            record_label varchar(255) NOT NULL COMMENT 'Human-readable label (name, title, etc.)',
            record_data longtext NOT NULL COMMENT 'Full JSON of the deleted record',
            related_data longtext NULL COMMENT 'JSON of related records deleted together',
            deleted_by bigint(20) UNSIGNED NOT NULL COMMENT 'WP user ID who deleted',
            deleted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NULL COMMENT 'Auto-purge date (NULL = keep forever)',
            PRIMARY KEY (trash_id),
            KEY record_type (record_type),
            KEY deleted_at (deleted_at),
            KEY expires_at (expires_at)
        ) {$charset};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Soft-delete a record: move it to trash instead of deleting permanently.
     * 
     * @param string $type    Record type (e.g., 'student', 'payment', 'lesson')
     * @param int    $id      Record primary key
     * @param string $label   Human-readable label
     * @param array  $data    The full record data
     * @param array  $related Related records being deleted (optional)
     * @param int    $days    Days to keep in trash before auto-purge (default: 90, 0 = forever)
     * @return int|false      Trash ID or false on failure
     */
    public function soft_delete($type, $id, $label, $data, $related = array(), $days = 90) {
        global $wpdb;
        
        $expires = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;
        
        $result = $wpdb->insert($this->trash_table, array(
            'record_type'  => $type,
            'record_id'    => $id,
            'record_label' => $label,
            'record_data'  => wp_json_encode($data),
            'related_data' => !empty($related) ? wp_json_encode($related) : null,
            'deleted_by'   => get_current_user_id(),
            'deleted_at'   => current_time('mysql'),
            'expires_at'   => $expires,
        ));
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Restore a record from trash back to its original table.
     * 
     * @param int $trash_id
     * @return array ['success' => bool, 'message' => string]
     */
    public function restore($trash_id) {
        global $wpdb;
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->trash_table} WHERE trash_id = %d", $trash_id
        ));
        
        if (!$item) {
            return array('success' => false, 'message' => 'Trash record not found.');
        }
        
        $data = json_decode($item->record_data, true);
        if (!$data) {
            return array('success' => false, 'message' => 'Could not decode record data.');
        }
        
        // Determine target table
        $table_map = array(
            'student'     => 'mtti_students',
            'payment'     => 'mtti_payments',
            'lesson'      => 'mtti_lessons',
            'material'    => 'mtti_materials',
            'assignment'  => 'mtti_assignments',
            'unit'        => 'mtti_course_units',
            'notice'      => 'mtti_notices',
            'staff'       => 'mtti_staff',
            'scheme_week' => 'mtti_scheme_of_work',
            'enrollment'  => 'mtti_enrollments',
            'course'      => 'mtti_courses',
        );
        
        $table_name = isset($table_map[$item->record_type]) 
            ? $wpdb->prefix . $table_map[$item->record_type] 
            : null;
        
        if (!$table_name) {
            return array('success' => false, 'message' => 'Unknown record type: ' . $item->record_type);
        }
        
        // Check table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            return array('success' => false, 'message' => 'Table does not exist: ' . $table_name);
        }
        
        // Get valid columns for this table
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
        
        // Filter data to only include valid columns
        $insert_data = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $columns)) {
                $insert_data[$key] = $value;
            }
        }
        
        if (empty($insert_data)) {
            return array('success' => false, 'message' => 'No valid columns to restore.');
        }
        
        // Insert back
        $result = $wpdb->insert($table_name, $insert_data);
        
        if (!$result) {
            return array('success' => false, 'message' => 'Database insert failed: ' . $wpdb->last_error);
        }
        
        // Restore related records if any
        if ($item->related_data) {
            $related = json_decode($item->related_data, true);
            if (is_array($related)) {
                foreach ($related as $rel) {
                    if (isset($rel['_table']) && isset($rel['_data'])) {
                        $rel_table = $wpdb->prefix . $rel['_table'];
                        if ($wpdb->get_var("SHOW TABLES LIKE '{$rel_table}'")) {
                            $rel_columns = $wpdb->get_col("SHOW COLUMNS FROM {$rel_table}", 0);
                            $rel_insert = array();
                            foreach ($rel['_data'] as $k => $v) {
                                if (in_array($k, $rel_columns)) $rel_insert[$k] = $v;
                            }
                            if (!empty($rel_insert)) $wpdb->insert($rel_table, $rel_insert);
                        }
                    }
                }
            }
        }
        
        // Remove from trash
        $wpdb->delete($this->trash_table, array('trash_id' => $trash_id));
        
        return array('success' => true, 'message' => ucfirst($item->record_type) . ' "' . $item->record_label . '" restored successfully.');
    }
    
    /**
     * Permanently delete a record from trash.
     */
    public function purge($trash_id) {
        global $wpdb;
        return $wpdb->delete($this->trash_table, array('trash_id' => $trash_id));
    }
    
    /**
     * Auto-purge expired trash records (called on cron or admin_init).
     */
    public function auto_purge_expired() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$this->trash_table} WHERE expires_at IS NOT NULL AND expires_at < NOW()");
    }
    
    /**
     * Get trash items with optional filtering.
     */
    public function get_items($type = '', $search = '', $limit = 50) {
        global $wpdb;
        
        $where = "1=1";
        $params = array();
        
        if ($type) {
            $where .= " AND record_type = %s";
            $params[] = $type;
        }
        if ($search) {
            $where .= " AND record_label LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $sql = "SELECT t.*, u.display_name as deleted_by_name 
                FROM {$this->trash_table} t 
                LEFT JOIN {$wpdb->users} u ON t.deleted_by = u.ID
                WHERE {$where} 
                ORDER BY deleted_at DESC 
                LIMIT %d";
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Count items in trash by type.
     */
    public function count_by_type() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT record_type, COUNT(*) as cnt FROM {$this->trash_table} GROUP BY record_type ORDER BY cnt DESC"
        );
    }
    
    /**
     * Render the Recycle Bin admin page.
     */
    public function display_page() {
        $this->auto_purge_expired();
        
        // Handle actions
        if (isset($_GET['trash_action'])) {
            $action = $_GET['trash_action'];
            $trash_id = intval($_GET['trash_id'] ?? 0);
            
            if ($action === 'restore' && $trash_id && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'restore_trash_' . $trash_id)) {
                $result = $this->restore($trash_id);
                $msg_type = $result['success'] ? 'success' : 'error';
                echo '<div class="notice notice-' . $msg_type . ' is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
            }
            
            if ($action === 'purge' && $trash_id && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'purge_trash_' . $trash_id)) {
                $this->purge($trash_id);
                echo '<div class="notice notice-success is-dismissible"><p>Record permanently deleted.</p></div>';
            }
            
            if ($action === 'empty_all' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'empty_all_trash')) {
                global $wpdb;
                $wpdb->query("TRUNCATE TABLE {$this->trash_table}");
                echo '<div class="notice notice-success is-dismissible"><p>Recycle bin emptied.</p></div>';
            }
        }
        
        $filter_type = sanitize_text_field($_GET['type'] ?? '');
        $search = sanitize_text_field($_GET['s'] ?? '');
        $items = $this->get_items($filter_type, $search);
        $counts = $this->count_by_type();
        $total = array_sum(array_column($counts, 'cnt'));
        
        $type_labels = array(
            'student' => '👨‍🎓 Students', 'payment' => '💳 Payments', 'lesson' => '📖 Lessons',
            'material' => '📥 Materials', 'assignment' => '📝 Assignments', 'unit' => '📑 Units',
            'notice' => '🔔 Notices', 'staff' => '👨‍💼 Staff', 'scheme_week' => '📋 Scheme',
            'enrollment' => '📚 Enrollments', 'course' => '📚 Courses',
        );
        
        echo '<div class="wrap">';
        echo '<h1 style="display:flex;align-items:center;gap:10px;">🗑️ Recycle Bin <span style="background:#f0f0f0;padding:2px 10px;border-radius:20px;font-size:14px;">' . $total . ' items</span></h1>';
        echo '<p>Deleted records are kept for <strong>90 days</strong> before automatic permanent deletion. You can restore or permanently delete them here.</p>';
        
        // Type filter tabs
        $base_url = admin_url('admin.php?page=mtti-mis-trash');
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:15px 0;">';
        $active_all = !$filter_type ? 'background:#2271b1;color:white;' : '';
        echo '<a href="' . esc_url($base_url) . '" class="button" style="' . $active_all . '">All (' . $total . ')</a>';
        foreach ($counts as $c) {
            $label = $type_labels[$c->record_type] ?? ucfirst($c->record_type);
            $active = ($filter_type === $c->record_type) ? 'background:#2271b1;color:white;' : '';
            echo '<a href="' . esc_url(add_query_arg('type', $c->record_type, $base_url)) . '" class="button" style="' . $active . '">' . $label . ' (' . $c->cnt . ')</a>';
        }
        echo '</div>';
        
        // Search
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="page" value="mtti-mis-trash">';
        if ($filter_type) echo '<input type="hidden" name="type" value="' . esc_attr($filter_type) . '">';
        echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search deleted records..." style="width:300px;">';
        echo ' <button type="submit" class="button">Search</button>';
        echo '</form>';
        
        if (empty($items)) {
            echo '<div style="background:white;padding:40px;text-align:center;border-radius:8px;border:1px solid #ddd;">';
            echo '<span style="font-size:48px;display:block;margin-bottom:10px;">🗑️</span>';
            echo '<h3>Recycle Bin is Empty</h3>';
            echo '<p style="color:#666;">No deleted records found.</p>';
            echo '</div>';
        } else {
            // Empty all button
            $empty_url = wp_nonce_url(add_query_arg(array('trash_action' => 'empty_all'), $base_url), 'empty_all_trash');
            echo '<div style="text-align:right;margin-bottom:10px;">';
            echo '<a href="' . esc_url($empty_url) . '" class="button" style="color:#d63638;" onclick="return confirm(\'Permanently delete ALL items in the recycle bin? This cannot be undone!\')">🗑️ Empty Recycle Bin</a>';
            echo '</div>';
            
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th width="30">Type</th><th>Name / Label</th><th>Details</th><th width="140">Deleted</th><th width="120">By</th><th width="80">Expires</th><th width="180">Actions</th></tr></thead><tbody>';
            
            foreach ($items as $item) {
                $data = json_decode($item->record_data, true);
                $type_icon = array(
                    'student' => '👨‍🎓', 'payment' => '💳', 'lesson' => '📖', 'material' => '📥',
                    'assignment' => '📝', 'unit' => '📑', 'notice' => '🔔', 'staff' => '👨‍💼',
                    'scheme_week' => '📋', 'enrollment' => '📚', 'course' => '📚',
                );
                $icon = $type_icon[$item->record_type] ?? '📄';
                
                // Build detail snippet from data
                $details = array();
                if (isset($data['admission_number'])) $details[] = $data['admission_number'];
                if (isset($data['course_code'])) $details[] = $data['course_code'];
                if (isset($data['amount'])) $details[] = 'KES ' . number_format($data['amount'], 2);
                if (isset($data['receipt_number'])) $details[] = '#' . $data['receipt_number'];
                if (isset($data['unit_code'])) $details[] = $data['unit_code'];
                $detail_str = implode(' · ', array_slice($details, 0, 3));
                
                $restore_url = wp_nonce_url(add_query_arg(array('trash_action' => 'restore', 'trash_id' => $item->trash_id), $base_url), 'restore_trash_' . $item->trash_id);
                $purge_url = wp_nonce_url(add_query_arg(array('trash_action' => 'purge', 'trash_id' => $item->trash_id), $base_url), 'purge_trash_' . $item->trash_id);
                
                $days_left = $item->expires_at ? max(0, intval((strtotime($item->expires_at) - time()) / 86400)) : '∞';
                
                echo '<tr>';
                echo '<td style="font-size:20px;">' . $icon . '</td>';
                echo '<td><strong>' . esc_html($item->record_label) . '</strong></td>';
                echo '<td style="color:#666;font-size:12px;">' . esc_html($detail_str) . '</td>';
                echo '<td style="font-size:12px;">' . esc_html(date('M j, Y g:i A', strtotime($item->deleted_at))) . '</td>';
                echo '<td style="font-size:12px;">' . esc_html($item->deleted_by_name ?: 'System') . '</td>';
                echo '<td style="font-size:12px;">' . ($days_left === '∞' ? '∞' : $days_left . 'd') . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url($restore_url) . '" class="button button-small" style="color:#2e7d32;border-color:#2e7d32;" title="Restore">♻️ Restore</a> ';
                echo '<a href="' . esc_url($purge_url) . '" class="button button-small" style="color:#d63638;" onclick="return confirm(\'Permanently delete this record? This cannot be undone!\')" title="Delete Forever">✕</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        // Help section
        echo '<div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;margin-top:20px;">';
        echo '<h3 style="margin-top:0;">ℹ️ How Soft Delete Works</h3>';
        echo '<ul style="list-style:disc;padding-left:20px;color:#333;">';
        echo '<li>When you delete a record (student, payment, lesson, etc.), it is <strong>moved to this Recycle Bin</strong> instead of being permanently removed.</li>';
        echo '<li>Deleted records are kept for <strong>90 days</strong>, then automatically purged.</li>';
        echo '<li>Click <strong>♻️ Restore</strong> to put a record back exactly where it was.</li>';
        echo '<li>Click <strong>✕</strong> to permanently delete a record with no recovery.</li>';
        echo '<li>Related records (e.g., enrollments, balances) are also saved and restored together.</li>';
        echo '</ul></div>';
        
        echo '</div>';
    }
}
