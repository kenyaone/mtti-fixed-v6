<?php
/**
 * Payments Admin Class
 * Updated v4.2.5 - Added payment audit trail (admin-only visibility)
 */
class MTTI_MIS_Admin_Payments {
    private $db;
    private $redirect_url = null;
    private $redirect_message = null;
    
    public function __construct($plugin_name, $version) {
        $this->db = MTTI_MIS_Database::get_instance();
    }
    
    public function display() {
        // Handle form submission FIRST (before any output)
        if (isset($_POST['mtti_payment_submit'])) {
            if (wp_verify_nonce($_POST['mtti_payment_nonce'], 'mtti_payment_action')) {
                $this->handle_form_submission();
                // If redirect is set, output JavaScript redirect
                if ($this->redirect_url) {
                    echo '<script>window.location.href = "' . esc_js($this->redirect_url) . '";</script>';
                    echo '<p>Redirecting... <a href="' . esc_url($this->redirect_url) . '">Click here if not redirected</a></p>';
                    return;
                }
            }
        }
        
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_payment_' . $_GET['id'])) {
                $this->delete_payment(intval($_GET['id']));
                echo '<script>window.location.href = "' . esc_js(admin_url('admin.php?page=mtti-mis-payments&message=deleted')) . '";</script>';
                echo '<p>Redirecting... <a href="' . esc_url(admin_url('admin.php?page=mtti-mis-payments&message=deleted')) . '">Click here</a></p>';
                return;
            }
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        if ($action == 'receipt') {
            // Validate payment ID
            if (!isset($_GET['id']) || empty($_GET['id'])) {
                wp_die('Error: No payment ID provided. <a href="' . admin_url('admin.php?page=mtti-mis-payments') . '">Return to payments</a>');
            }
            
            $payment_id = intval($_GET['id']);
            
            if ($payment_id <= 0) {
                wp_die('Error: Invalid payment ID format. Received: ' . esc_html($_GET['id']) . '. <a href="' . admin_url('admin.php?page=mtti-mis-payments') . '">Return to payments</a>');
            }
            
            $this->print_receipt($payment_id);
        } elseif ($action == 'add') {
            $this->display_form();
        } elseif ($action == 'edit') {
            if (!isset($_GET['id']) || empty($_GET['id'])) {
                wp_die('Error: No payment ID provided for editing.');
            }
            $this->display_form(intval($_GET['id']));
        } elseif ($action == 'audit-trail') {
            // Admin-only audit trail view
            if (!current_user_can('manage_options')) {
                wp_die('You do not have permission to view the audit trail.');
            }
            $this->display_audit_trail();
        } elseif ($action == 'arrears-report') {
            $this->display_arrears_report();
        } else {
            $this->display_list();
        }
    }
    
    /**
     * Log payment action to audit trail
     */
    private function log_audit_trail($payment_id, $action, $old_data = null, $new_data = null) {
        global $wpdb;
        $audit_table = $wpdb->prefix . 'mtti_payment_audit';
        
        $current_user = wp_get_current_user();
        
        // Get student info
        $student_name = '';
        $receipt_number = '';
        $student_id = null;
        $old_amount = null;
        $new_amount = null;
        
        if ($new_data) {
            $student_id = isset($new_data['student_id']) ? $new_data['student_id'] : null;
            $new_amount = isset($new_data['amount']) ? $new_data['amount'] : null;
            $receipt_number = isset($new_data['receipt_number']) ? $new_data['receipt_number'] : '';
            
            // Get student name
            if ($student_id) {
                $student = $this->db->get_student($student_id);
                if ($student) {
                    $student_name = $student->display_name;
                }
            }
        }
        
        if ($old_data) {
            $old_amount = isset($old_data['amount']) ? $old_data['amount'] : null;
            if (!$student_id && isset($old_data['student_id'])) {
                $student_id = $old_data['student_id'];
                $student = $this->db->get_student($student_id);
                if ($student) {
                    $student_name = $student->display_name;
                }
            }
            if (!$receipt_number && isset($old_data['receipt_number'])) {
                $receipt_number = $old_data['receipt_number'];
            }
        }
        
        $audit_data = array(
            'payment_id' => $payment_id,
            'action' => $action,
            'student_id' => $student_id,
            'student_name' => $student_name,
            'receipt_number' => $receipt_number,
            'old_amount' => $old_amount,
            'new_amount' => $new_amount,
            'old_data' => $old_data ? json_encode($old_data) : null,
            'new_data' => $new_data ? json_encode($new_data) : null,
            'changed_by' => $current_user->ID,
            'changed_by_name' => $current_user->display_name,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
            'created_at' => current_time('mysql')
        );
        
        $wpdb->insert($audit_table, $audit_data);
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }
    
    /**
     * Display audit trail (Admin only)
     */
    private function display_audit_trail() {
        global $wpdb;
        $audit_table = $wpdb->prefix . 'mtti_payment_audit';
        
        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table}");
        $total_pages = ceil($total_items / $per_page);
        
        // Get audit logs
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$audit_table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        ?>
        <div class="wrap">
            <h1>🔒 Payment Audit Trail 
                <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments'); ?>" class="page-title-action">← Back to Payments</a>
            </h1>
            
            <div style="background:#fff3e0;padding:15px;margin:20px 0;border-left:4px solid #FF9800;border-radius:4px;">
                <strong>🔐 Admin Only:</strong> This audit trail is only visible to administrators. It tracks all payment changes including who made them, when, and from which IP address.
            </div>
            
            <div style="background:#e8f5e9;padding:15px;margin:20px 0;border-left:4px solid #4CAF50;border-radius:4px;">
                <strong>Total Records:</strong> <?php echo number_format($total_items); ?> audit entries
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:140px;">Date/Time</th>
                        <th style="width:80px;">Action</th>
                        <th>Receipt #</th>
                        <th>Student</th>
                        <th style="width:120px;">Old Amount</th>
                        <th style="width:120px;">New Amount</th>
                        <th>Changed By</th>
                        <th style="width:120px;">IP Address</th>
                        <th style="width:80px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs) : foreach ($logs as $log) : 
                        $action_color = '#666';
                        $action_bg = '#f5f5f5';
                        if ($log->action == 'CREATE') {
                            $action_color = '#fff';
                            $action_bg = '#4CAF50';
                        } elseif ($log->action == 'UPDATE') {
                            $action_color = '#fff';
                            $action_bg = '#2196F3';
                        } elseif ($log->action == 'DELETE') {
                            $action_color = '#fff';
                            $action_bg = '#f44336';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo date('M j, Y', strtotime($log->created_at)); ?></strong><br>
                            <span style="color:#666;font-size:11px;"><?php echo date('g:i:s A', strtotime($log->created_at)); ?></span>
                        </td>
                        <td>
                            <span style="background:<?php echo $action_bg; ?>;color:<?php echo $action_color; ?>;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:bold;">
                                <?php echo esc_html($log->action); ?>
                            </span>
                        </td>
                        <td><code><?php echo esc_html($log->receipt_number); ?></code></td>
                        <td>
                            <?php echo esc_html($log->student_name); ?>
                            <?php if ($log->student_id) : ?>
                            <br><span style="color:#666;font-size:11px;">ID: <?php echo $log->student_id; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log->old_amount !== null) : ?>
                            <span style="color:#666;">KES <?php echo number_format($log->old_amount, 2); ?></span>
                            <?php else : ?>
                            <span style="color:#ccc;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log->new_amount !== null) : ?>
                            <strong style="color:#2E7D32;">KES <?php echo number_format($log->new_amount, 2); ?></strong>
                            <?php else : ?>
                            <span style="color:#ccc;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($log->changed_by_name); ?></strong><br>
                            <span style="color:#666;font-size:11px;">User ID: <?php echo $log->changed_by; ?></span>
                        </td>
                        <td><code style="font-size:11px;"><?php echo esc_html($log->ip_address); ?></code></td>
                        <td>
                            <button type="button" class="button button-small" onclick="toggleDetails(<?php echo $log->audit_id; ?>)">View</button>
                            <div id="details-<?php echo $log->audit_id; ?>" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border-radius:8px;box-shadow:0 5px 30px rgba(0,0,0,0.3);z-index:10000;max-width:600px;max-height:80vh;overflow:auto;">
                                <h3 style="margin-top:0;">Audit Details #<?php echo $log->audit_id; ?></h3>
                                <p><strong>Action:</strong> <?php echo esc_html($log->action); ?></p>
                                <p><strong>Date:</strong> <?php echo date('F j, Y g:i:s A', strtotime($log->created_at)); ?></p>
                                <p><strong>User Agent:</strong><br><small><?php echo esc_html($log->user_agent); ?></small></p>
                                <?php if ($log->old_data) : ?>
                                <p><strong>Old Data:</strong></p>
                                <pre style="background:#ffebee;padding:10px;border-radius:4px;font-size:11px;overflow:auto;"><?php echo esc_html(json_encode(json_decode($log->old_data), JSON_PRETTY_PRINT)); ?></pre>
                                <?php endif; ?>
                                <?php if ($log->new_data) : ?>
                                <p><strong>New Data:</strong></p>
                                <pre style="background:#e8f5e9;padding:10px;border-radius:4px;font-size:11px;overflow:auto;"><?php echo esc_html(json_encode(json_decode($log->new_data), JSON_PRETTY_PRINT)); ?></pre>
                                <?php endif; ?>
                                <button type="button" class="button" onclick="toggleDetails(<?php echo $log->audit_id; ?>)">Close</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:30px;">No audit records found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format($total_items); ?> items</span>
                    <span class="pagination-links">
                        <?php if ($current_page > 1) : ?>
                        <a class="button" href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=audit-trail&paged=1'); ?>">«</a>
                        <a class="button" href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=audit-trail&paged=' . ($current_page - 1)); ?>">‹</a>
                        <?php endif; ?>
                        <span class="paging-input">
                            <span class="current-page"><?php echo $current_page; ?></span> of <span class="total-pages"><?php echo $total_pages; ?></span>
                        </span>
                        <?php if ($current_page < $total_pages) : ?>
                        <a class="button" href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=audit-trail&paged=' . ($current_page + 1)); ?>">›</a>
                        <a class="button" href="<?php echo admin_url('admin.php?page=mtti-mis-payments&action=audit-trail&paged=' . $total_pages); ?>">»</a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <script>
            function toggleDetails(id) {
                var el = document.getElementById('details-' + id);
                if (el.style.display === 'none') {
                    // Close all others first
                    document.querySelectorAll('[id^="details-"]').forEach(function(e) {
                        e.style.display = 'none';
                    });
                    el.style.display = 'block';
                } else {
                    el.style.display = 'none';
                }
            }
            // Close on escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('[id^="details-"]').forEach(function(el) {
                        el.style.display = 'none';
                    });
                }
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Delete a payment and update student balance
     */
    private function delete_payment($payment_id) {
        global $wpdb;
        $payments_table = $this->db->get_table_name('payments');
        
        // Get the payment first to reverse balance and log audit
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$payments_table} WHERE payment_id = %d",
            $payment_id
        ));
        
        if ($payment) {
            // Log to audit trail BEFORE deleting
            $old_data = array(
                'payment_id' => $payment->payment_id,
                'student_id' => $payment->student_id,
                'receipt_number' => $payment->receipt_number,
                'amount' => $payment->amount,
                'discount' => $payment->discount,
                'payment_method' => $payment->payment_method,
                'payment_date' => $payment->payment_date,
                'status' => $payment->status
            );
            $this->log_audit_trail($payment_id, 'DELETE', $old_data, null);
            
            if ($payment->enrollment_id) {
                // Reverse the payment from balance
                $discount = isset($payment->discount) ? floatval($payment->discount) : 0;
                $this->reverse_payment_balance($payment->enrollment_id, $payment->amount, $discount);
            }
        }
        
        // Delete the payment — SOFT DELETE: save to trash first
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-trash.php';
        MTTI_MIS_Admin_Trash::create_table();
        $trash = new MTTI_MIS_Admin_Trash();
        $label = 'Payment #' . ($payment->receipt_number ?: $payment_id) . ' — KES ' . number_format($payment->amount, 2);
        $trash->soft_delete('payment', $payment_id, $label, (array)$payment);
        
        $wpdb->delete($payments_table, array('payment_id' => $payment_id));
    }
    
    private function display_list() {
        // Get search parameter
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // Get payments with optional search filter
        $args = array();
        if (!empty($search)) {
            $args['search'] = $search;
        }
        
        $payments = $this->db->get_payments($args);
        ?>
        <div class="wrap">
            <h1>Payments 
                <a href="?page=mtti-mis-payments&action=add" class="page-title-action">Record Payment</a>
                <a href="?page=mtti-mis-payments&action=arrears-report" class="page-title-action" style="background:#dc3545;color:#fff;border-color:#c82333;">📊 Arrears Report</a>
                <?php if (current_user_can('manage_options')) : ?>
                <a href="?page=mtti-mis-payments&action=audit-trail" class="page-title-action" style="background:#1976D2;color:#fff;border-color:#1565C0;">🔒 Audit Trail</a>
                <?php endif; ?>
            </h1>
            
            <?php if (isset($_GET['message'])) : ?>
                <?php if ($_GET['message'] == 'created') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Payment has been recorded successfully.</p>
                </div>
                <?php elseif ($_GET['message'] == 'updated') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Payment has been updated successfully.</p>
                </div>
                <?php elseif ($_GET['message'] == 'deleted') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Payment has been deleted and student balance updated.</p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Search Form -->
            <form method="get" class="search-form" style="margin: 20px 0;">
                <input type="hidden" name="page" value="mtti-mis-payments">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="Search by receipt number, student, or transaction..." 
                           class="regular-text" style="width: 400px;">
                    <input type="submit" value="Search Payments" class="button">
                    <?php if (!empty($search)) : ?>
                        <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments'); ?>" class="button">Clear Search</a>
                    <?php endif; ?>
                </p>
            </form>
            
            <?php if (!empty($search)) : ?>
            <div class="notice notice-info">
                <p><strong>Search results for:</strong> "<?php echo esc_html($search); ?>" 
                   (<?php echo count($payments); ?> payment<?php echo count($payments) != 1 ? 's' : ''; ?> found)</p>
            </div>
            <?php endif; ?>
            
            <?php 
            // Calculate totals for summary
            $total_collected = 0;
            $total_discounts = 0;
            $completed_count = 0;
            $pending_count = 0;
            
            if (!empty($payments)) {
                foreach ($payments as $p) {
                    if ($p->status == 'Completed') {
                        $total_collected += $p->amount;
                        $completed_count++;
                    } else {
                        $pending_count++;
                    }
                    $total_discounts += isset($p->discount) ? $p->discount : 0;
                }
            }
            ?>
            
            <!-- Payment Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: linear-gradient(135deg, #4CAF50, #2E7D32); color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase;">Total Collected</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px;">KES <?php echo number_format($total_collected, 2); ?></div>
                    <div style="font-size: 11px; margin-top: 5px; opacity: 0.8;"><?php echo $completed_count; ?> completed payments</div>
                </div>
                <div style="background: linear-gradient(135deg, #2196F3, #1565C0); color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase;">Total Discounts Given</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px;">KES <?php echo number_format($total_discounts, 2); ?></div>
                    <div style="font-size: 11px; margin-top: 5px; opacity: 0.8;">Across all payments</div>
                </div>
                <div style="background: linear-gradient(135deg, #FF9800, #E65100); color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase;">Pending Verification</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px;"><?php echo $pending_count; ?></div>
                    <div style="font-size: 11px; margin-top: 5px; opacity: 0.8;">Payments to verify</div>
                </div>
                <div style="background: linear-gradient(135deg, #9C27B0, #6A1B9A); color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 12px; opacity: 0.9; text-transform: uppercase;">Total Transactions</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px;"><?php echo count($payments); ?></div>
                    <div style="font-size: 11px; margin-top: 5px; opacity: 0.8;">All time records</div>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Receipt No</th>
                        <th>Student</th>
                        <th>Amount Paid</th>
                        <th>Discount</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)) : ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px;">No payments found.</td>
                    </tr>
                    <?php else : ?>
                    <?php foreach ($payments as $payment) : 
                        $discount = isset($payment->discount) ? $payment->discount : 0;
                        $delete_url = wp_nonce_url(
                            admin_url('admin.php?page=mtti-mis-payments&action=delete&id=' . $payment->payment_id),
                            'delete_payment_' . $payment->payment_id
                        );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($payment->receipt_number); ?></strong></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mtti-mis-students&action=view&id=' . $payment->student_id); ?>" 
                               title="View Student Profile">
                                <?php echo esc_html($payment->admission_number); ?>
                            </a>
                        </td>
                        <td><strong>KES <?php echo number_format($payment->amount, 2); ?></strong></td>
                        <td><?php echo $discount > 0 ? '<span style="color: #2E7D32;">KES ' . number_format($discount, 2) . '</span>' : '-'; ?></td>
                        <td><?php echo esc_html($payment->payment_method); ?></td>
                        <td><?php echo date('M j, Y', strtotime($payment->payment_date)); ?></td>
                        <td><span class="mtti-status mtti-status-<?php echo strtolower($payment->status); ?>">
                            <?php echo $payment->status; ?>
                        </span></td>
                        <td>
                            <a href="?page=mtti-mis-payments&action=edit&id=<?php echo $payment->payment_id; ?>" 
                               class="button button-small" title="Edit Payment">
                                <span class="dashicons dashicons-edit"></span>
                            </a>
                            <a href="?page=mtti-mis-payments&action=receipt&id=<?php echo $payment->payment_id; ?>" 
                               target="_blank" class="button button-small" title="Print Receipt">
                                <span class="dashicons dashicons-media-text"></span>
                            </a>
                            <a href="<?php echo esc_url($delete_url); ?>" 
                               class="button button-small" 
                               style="color: #a00;" 
                               title="Delete Payment"
                               onclick="return confirm('Are you sure you want to delete this payment?\n\nThis will update the student balance accordingly.');">
                                <span class="dashicons dashicons-trash"></span>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($payments)) : ?>
                <tfoot style="background: #f5f5f5;">
                    <tr>
                        <td colspan="2" style="text-align: right;"><strong>PAGE TOTAL:</strong></td>
                        <td><strong style="color: #2E7D32;">KES <?php echo number_format($total_collected, 2); ?></strong></td>
                        <td><strong style="color: #1976D2;">KES <?php echo number_format($total_discounts, 2); ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }
    
    private function display_form($payment_id = null) {
        global $wpdb;
        $students = $this->db->get_students(array('status' => 'Active'));
        
        // If editing, fetch payment details
        $payment = null;
        $is_edit = false;
        if ($payment_id) {
            $is_edit = true;
            $payments_table = $this->db->get_table_name('payments');
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT p.*, s.admission_number, s.student_id 
                 FROM {$payments_table} p
                 LEFT JOIN {$this->db->get_table_name('students')} s ON p.student_id = s.student_id
                 WHERE p.payment_id = %d",
                $payment_id
            ));
            
            if (!$payment) {
                wp_die('Payment not found. <a href="' . admin_url('admin.php?page=mtti-mis-payments') . '">Return to payments</a>');
            }
        }
        
        // Get all active courses for enrollment
        $courses = $this->db->get_courses(array('status' => 'Active'));
        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit Payment' : 'Record Payment'; ?></h1>
            
            <?php if ($is_edit) : ?>
            <div class="notice notice-warning">
                <p><strong>Warning:</strong> Editing a payment will recalculate student balances. Be careful when modifying payment amounts.</p>
            </div>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field('mtti_payment_action', 'mtti_payment_nonce'); ?>
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="payment_id" value="<?php echo $payment->payment_id; ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label>Student *</label></th>
                        <td>
                            <?php if ($is_edit) : ?>
                                <strong><?php echo esc_html($payment->admission_number); ?></strong>
                                <input type="hidden" name="student_id" value="<?php echo $payment->student_id; ?>">
                                <p class="description">Student cannot be changed when editing a payment</p>
                            <?php else : ?>
                            <select name="student_id" required class="regular-text" id="student-select">
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student) : ?>
                                <option value="<?php echo $student->student_id; ?>">
                                    <?php echo esc_html($student->admission_number . ' - ' . $student->display_name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="student-loading" style="display:none; margin-top: 10px;">
                                <span class="spinner is-active" style="float: none;"></span> Loading student details...
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Show when student is enrolled -->
                    <tr id="fee-info-row" style="display:none;">
                        <th><label>Enrolled Course(s)</label></th>
                        <td>
                            <div id="courses-container" style="background: #e8f5e9; border: 1px solid #4CAF50; padding: 15px; border-radius: 4px;">
                                <span id="course-name" style="font-size: 16px; font-weight: bold;">-</span>
                                <div id="courses-list" style="margin-top: 10px; display: none;">
                                    <!-- Will be populated by JS -->
                                </div>
                            </div>
                            <input type="hidden" name="enrollment_id_hidden" id="enrollment-id-hidden" value="">
                        </td>
                    </tr>

                    <!-- Course selector for multi-course students -->
                    <tr id="course-selector-row" style="display:none;">
                        <th><label for="payment-course-select"><strong>Payment For Which Course? *</strong></label></th>
                        <td>
                            <div style="background: #e3f2fd; border: 2px solid #1976D2; padding: 15px; border-radius: 4px;">
                                <strong style="color: #0D47A1;">📚 This student is enrolled in multiple courses.</strong><br>
                                <span style="font-size: 13px; color: #555;">Select the course this payment applies to:</span>
                                <br><br>
                                <select id="payment-course-select" class="regular-text" style="font-size: 15px; padding: 8px; width: 100%; border: 2px solid #1976D2;">
                                    <option value="">-- Select Course --</option>
                                </select>
                                <p class="description" style="margin-top: 8px; color: #1976D2;">
                                    The balance and fees shown below will update for the selected course.
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Show when student is NOT enrolled - allow selecting course -->
                    <tr id="enroll-course-row" style="display:none;">
                        <th><label>Select Course *</label></th>
                        <td>
                            <div style="background: #fff3e0; border: 2px solid #FF9800; padding: 15px; border-radius: 4px; margin-bottom: 10px;">
                                <strong style="color: #E65100;">⚠️ Student not enrolled in any course</strong><br>
                                <span style="font-size: 13px;">Select a course below to enroll and record payment.</span>
                            </div>
                            <select name="enroll_course_id" id="enroll-course-select" class="regular-text" style="font-size: 16px; padding: 8px;">
                                <option value="">-- Select Course to Enroll --</option>
                                <?php foreach ($courses as $course) : ?>
                                <option value="<?php echo $course->course_id; ?>" data-fee="<?php echo $course->fee; ?>">
                                    <?php echo esc_html($course->course_code . ' - ' . $course->course_name . ' (KES ' . number_format($course->fee, 2) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Student will be automatically enrolled when payment is recorded.</p>
                        </td>
                    </tr>
                    
                    <tr id="course-fee-row" style="display:none;">
                        <th><label>Total Course Fees (KES)</label></th>
                        <td>
                            <input type="number" id="course-fee" name="course_fee" class="regular-text" style="font-size: 18px; padding: 10px; background: #e8f5e9; font-weight: bold; border: 2px solid #4CAF50;" readonly>
                            <div id="fee-breakdown" style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px; font-size: 13px; display: none;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <tbody id="courses-fee-breakdown">
                                        <!-- Course fees will be inserted here by JS -->
                                    </tbody>
                                    <tr id="breakdown-tuition">
                                        <td style="padding: 5px 0;">Tuition Fee (All Courses):</td>
                                        <td style="padding: 5px 0; text-align: right;"><span id="tuition-fee-display">KES 0.00</span></td>
                                    </tr>
                                    <tr id="breakdown-admission" style="display: none;">
                                        <td style="padding: 5px 0;">Admission Fee:</td>
                                        <td style="padding: 5px 0; text-align: right;">KES 1,500.00</td>
                                    </tr>
                                    <tr style="border-top: 1px solid #ccc; font-weight: bold;">
                                        <td style="padding: 8px 0;">Total Fees:</td>
                                        <td style="padding: 8px 0; text-align: right; color: #2E7D32;"><span id="total-fee-display">KES 0.00</span></td>
                                    </tr>
                                </table>
                            </div>
                            <p class="description" id="fee-note" style="display: none; color: #666; margin-top: 5px;">
                                <em>* Admission Fee (KES 1,500) charged once for all courses</em>
                            </p>
                            <p class="description" id="fee-note-excluded" style="display: none; color: #E65100; margin-top: 5px;">
                                <em>* Computer Applications/Essentials - No additional fees (Admission Fee not applicable)</em>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Previously Paid -->
                    <tr id="previously-paid-row" style="display:none;">
                        <th><label>Previously Paid (KES)</label></th>
                        <td>
                            <div style="background: #e3f2fd; border: 2px solid #2196F3; padding: 15px; border-radius: 4px;">
                                <span id="previously-paid-display" style="font-size: 20px; font-weight: bold; color: #1565C0;">KES 0.00</span>
                                <p style="margin: 5px 0 0 0; font-size: 11px; color: #666;"><em>Total payments already made by this student</em></p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Outstanding Balance -->
                    <tr id="outstanding-balance-row" style="display:none;">
                        <th><label>Outstanding Balance (KES)</label></th>
                        <td>
                            <div id="outstanding-balance-box" style="background: #fff3e0; border: 3px solid #FF9800; padding: 20px; border-radius: 4px;">
                                <span id="outstanding-balance-display" style="font-size: 28px; font-weight: bold; color: #E65100;">KES 0.00</span>
                                <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;"><em>= Total Fees - Previously Paid</em></p>
                            </div>
                        </td>
                    </tr>
                    
                    <tr id="discount-row" style="display:none;">
                        <th><label>Less: Discount (KES)</label></th>
                        <td>
                            <input type="number" name="discount" id="discount-given" class="regular-text" step="0.01" min="0" value="<?php echo $is_edit ? esc_attr($payment->discount) : '0'; ?>" style="font-size: 16px; padding: 8px;">
                            <p class="description">Enter discount amount for this payment (if any)</p>
                        </td>
                    </tr>
                    <tr id="amount-paid-row" style="display:none;">
                        <th><label>Amount Paying Now (KES) *</label></th>
                        <td>
                            <input type="number" name="amount" id="amount-paid" required class="regular-text" step="0.01" min="0" value="<?php echo $is_edit ? esc_attr($payment->amount) : ''; ?>" style="font-size: 18px; padding: 10px; border: 2px solid #2196F3;">
                            <p class="description">Enter the amount the learner is paying now</p>
                        </td>
                    </tr>
                    <tr id="balance-row" style="display:none;">
                        <th><label>New Balance After Payment</label></th>
                        <td>
                            <div style="background: #ffebee; border: 3px solid #f44336; padding: 20px; border-radius: 4px;" id="balance-box">
                                <span id="balance-display" style="font-size: 28px; font-weight: bold; color: #D32F2F;">KES 0.00</span>
                                <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;"><em>= Outstanding Balance - Discount - Amount Paying</em></p>
                            </div>
                        </td>
                    </tr>
                    
                    <tr id="payment-method-row" style="display:none;">
                        <th><label>Payment Method *</label></th>
                        <td>
                            <select name="payment_method" required class="regular-text">
                                <option value="M-Pesa" <?php echo ($is_edit && $payment->payment_method == 'M-Pesa') ? 'selected' : ''; ?>>M-Pesa</option>
                                <option value="Cash" <?php echo ($is_edit && $payment->payment_method == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="Bank Transfer" <?php echo ($is_edit && $payment->payment_method == 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="Cheque" <?php echo ($is_edit && $payment->payment_method == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="transaction-ref-row" style="display:none;">
                        <th><label>Transaction Reference</label></th>
                        <td>
                            <input type="text" name="transaction_reference" class="regular-text" 
                                   value="<?php echo $is_edit ? esc_attr($payment->transaction_reference) : ''; ?>">
                        </td>
                    </tr>
                    <tr id="payment-date-row" style="display:none;">
                        <th><label>Payment Date *</label></th>
                        <td>
                            <input type="date" name="payment_date" required class="regular-text" 
                                   value="<?php echo $is_edit ? esc_attr($payment->payment_date) : date('Y-m-d'); ?>">
                        </td>
                    </tr>
                    <tr id="payment-for-row" style="display:none;">
                        <th><label>Payment For *</label></th>
                        <td>
                            <select name="payment_for" id="payment-for-select" required class="regular-text">
                                <option value="Tuition Fee" <?php echo ($is_edit && $payment->payment_for == 'Tuition Fee') ? 'selected' : ''; ?>>Tuition Fee</option>
                                <option value="Admission Fee" class="additional-fee-option" <?php echo ($is_edit && $payment->payment_for == 'Admission Fee') ? 'selected' : ''; ?>>Admission Fee (KES 1,500)</option>
                                <option value="Tuition + Admission + ID" class="additional-fee-option" <?php echo ($is_edit && $payment->payment_for == 'Tuition + Admission + ID') ? 'selected' : ''; ?>>Tuition + Admission + ID (Full Package)</option>
                                <option value="Other" <?php echo ($is_edit && $payment->payment_for == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                            <p class="description" id="payment-for-note" style="display:none; color: #E65100;">
                                <strong>Note:</strong> Admission Fee (KES 1,500) is NOT applicable for Computer Applications and Computer Essentials courses.
                            </p>
                        </td>
                    </tr>
                    <tr id="payment-status-row" style="display:none;">
                        <th><label>Payment Status *</label></th>
                        <td>
                            <select name="payment_status" required class="regular-text">
                                <option value="Completed" <?php echo ($is_edit && $payment->status == 'Completed') ? 'selected' : ''; ?>>Completed (Verified & Confirmed)</option>
                                <option value="Pending Verification" <?php echo ($is_edit && $payment->status == 'Pending Verification') ? 'selected' : ''; ?>>Pending Verification</option>
                                <option value="Pending" <?php echo ($is_edit && $payment->status == 'Pending') ? 'selected' : ''; ?>>Pending (Awaiting Confirmation)</option>
                                <option value="Failed" <?php echo ($is_edit && $payment->status == 'Failed') ? 'selected' : ''; ?>>Failed</option>
                                <option value="Refunded" <?php echo ($is_edit && $payment->status == 'Refunded') ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                            <p class="description">
                                <strong>Completed:</strong> Payment verified and confirmed<br>
                                <strong>Pending Verification:</strong> Payment needs to be verified (e.g., bank transfer)<br>
                                <strong>Pending:</strong> Payment initiated but not confirmed (e.g., M-Pesa pending)<br>
                                <strong>Failed:</strong> Payment attempt failed<br>
                                <strong>Refunded:</strong> Payment was refunded to student
                            </p>
                        </td>
                    </tr>
                    <tr id="notes-row" style="display:none;">
                        <th><label>Notes</label></th>
                        <td>
                            <textarea name="notes" rows="3" class="large-text"><?php echo $is_edit ? esc_textarea($payment->notes) : ''; ?></textarea>
                        </td>
                    </tr>
                </table>
                <p class="mtti-notice info" style="background: #e3f2fd; border-left-color: #2196F3;">
                    <strong>💡 Example:</strong> If Course Fee is KES 4,000, Discount is KES 1,500, and Amount Paid is KES 2,500:<br>
                    Balance = 4,000 - 1,500 - 2,500 = <strong>KES 0 (Fully Paid)</strong>
                </p>
                <p class="submit">
                    <input type="submit" name="mtti_payment_submit" class="button button-primary" value="<?php echo $is_edit ? 'Update Payment' : 'Record Payment'; ?>">
                    <a href="?page=mtti-mis-payments" class="button">Cancel</a>
                </p>
            </form>
            
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                var courseFee = 0;
                var previouslyPaid = 0;
                var outstandingBalance = 0;
                
                // Format number as currency
                function formatCurrency(num) {
                    return 'KES ' + parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
                }
                
                // Calculate and update balance after this payment
                function calculateBalance() {
                    var discount = parseFloat($('#discount-given').val()) || 0;
                    var amountPaid = parseFloat($('#amount-paid').val()) || 0;
                    
                    // New Balance = Outstanding Balance - Discount - Amount Paying Now
                    var newBalance = outstandingBalance - discount - amountPaid;
                    if (newBalance < 0) newBalance = 0;
                    
                    // Update displays
                    $('#balance-display').text(formatCurrency(newBalance));
                    
                    // Change balance box color based on balance
                    if (newBalance <= 0) {
                        $('#balance-box').css({
                            'background': '#e8f5e9',
                            'border-color': '#4CAF50'
                        });
                        $('#balance-display').css('color', '#2E7D32').text(formatCurrency(0) + ' (FULLY PAID)');
                    } else {
                        $('#balance-box').css({
                            'background': '#ffebee',
                            'border-color': '#f44336'
                        });
                        $('#balance-display').css('color', '#D32F2F');
                    }
                }
                
                // Show payment fields for ENROLLED student
                function showEnrolledFields() {
                    $('#fee-info-row').show();
                    $('#enroll-course-row').hide();
                    $('#course-fee-row, #previously-paid-row, #outstanding-balance-row').show();
                    $('#discount-row, #amount-paid-row, #balance-row').show();
                    $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').show();
                    $('#payment-status-row, #notes-row').show();
                }
                
                // Show payment fields for NON-ENROLLED student (needs course selection)
                function showNotEnrolledFields() {
                    $('#fee-info-row').hide();
                    $('#enroll-course-row').show();
                    $('#course-fee-row, #previously-paid-row, #outstanding-balance-row').hide();
                    $('#discount-row, #amount-paid-row, #balance-row').hide();
                    $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').hide();
                    $('#payment-status-row, #notes-row').hide();
                }
                
                // Hide all payment fields
                function hideAllFields() {
                    $('#fee-info-row, #enroll-course-row, #course-fee-row, #course-selector-row').hide();
                    $('#previously-paid-row, #outstanding-balance-row').hide();
                    $('#discount-row, #amount-paid-row, #balance-row').hide();
                    $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').hide();
                    $('#payment-status-row, #notes-row').hide();
                    $('#payment-course-select').empty().append('<option value="">-- Select Course --</option>');
                    $('#enrollment-id-hidden').val('');
                }
                
                // Function to check if course requires additional fees
                function courseRequiresAdditionalFees(courseName) {
                    if (!courseName) return true;
                    var name = courseName.toLowerCase();
                    var excludedCourses = ['computer applications', 'computer essentials', 'computer & online essentials'];
                    for (var i = 0; i < excludedCourses.length; i++) {
                        if (name.indexOf(excludedCourses[i]) !== -1) {
                            return false;
                        }
                    }
                    return true;
                }
                
                // Function to update Payment For dropdown options
                function updatePaymentForOptions(courseName) {
                    var requiresAdditional = courseRequiresAdditionalFees(courseName);
                    
                    if (requiresAdditional) {
                        $('.additional-fee-option').show();
                        $('#payment-for-note').hide();
                    } else {
                        $('.additional-fee-option').hide();
                        $('#payment-for-note').show();
                        var currentVal = $('#payment-for-select').val();
                        if (currentVal === 'Admission Fee' || currentVal === 'Tuition + Admission + ID') {
                            $('#payment-for-select').val('Tuition Fee');
                        }
                    }
                }
                
                // Function to update fee breakdown display for multiple courses
                function updateFeeBreakdown(data) {
                    var tuitionFee = parseFloat(data.tuition_fee) || 0;
                    var admissionFee = parseFloat(data.admission_fee) || 0;
                    var totalFee = parseFloat(data.total_fee) || 0;
                    var requiresAdditional = data.requires_additional_fees;
                    
                    // Clear and build courses breakdown if multiple courses
                    var coursesBreakdown = $('#courses-fee-breakdown');
                    coursesBreakdown.empty();
                    
                    if (data.courses_list && data.courses_list.length > 1) {
                        // Multiple courses - show each course fee
                        data.courses_list.forEach(function(course, index) {
                            coursesBreakdown.append(
                                '<tr style="color: #666;">' +
                                '<td style="padding: 3px 0; font-size: 12px;">  ' + (index + 1) + '. ' + course.course_name + ':</td>' +
                                '<td style="padding: 3px 0; text-align: right; font-size: 12px;">KES ' + parseFloat(course.fee).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,') + '</td>' +
                                '</tr>'
                            );
                        });
                        coursesBreakdown.append('<tr><td colspan="2" style="border-bottom: 1px dashed #ccc; padding: 5px 0;"></td></tr>');
                    }
                    
                    $('#tuition-fee-display').text(formatCurrency(tuitionFee));
                    $('#total-fee-display').text(formatCurrency(totalFee));
                    
                    if (requiresAdditional) {
                        $('#breakdown-admission').show();
                        $('#fee-note').show();
                        $('#fee-note-excluded').hide();
                    } else {
                        $('#breakdown-admission').hide();
                        $('#fee-note').hide();
                        $('#fee-note-excluded').show();
                    }
                    
                    $('#fee-breakdown').show();
                }
                
                // Function to display courses list with full details
                function displayCoursesList(data) {
                    var numCourses = data.num_courses || 1;
                    var coursesList = $('#courses-list');
                    coursesList.empty();
                    
                    if (numCourses >= 1 && data.courses_list) {
                        // Show all courses with their individual fees
                        coursesList.append('<div style="margin-bottom: 10px;"><strong style="color: #2E7D32;">' + numCourses + ' Course(s) Enrolled:</strong></div>');
                        
                        var html = '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
                        html += '<thead><tr style="background: #f5f5f5;">';
                        html += '<th style="text-align: left; padding: 6px; border: 1px solid #ddd;">Course</th>';
                        html += '<th style="text-align: right; padding: 6px; border: 1px solid #ddd;">Fee</th>';
                        html += '<th style="text-align: right; padding: 6px; border: 1px solid #ddd;">Paid</th>';
                        html += '<th style="text-align: right; padding: 6px; border: 1px solid #ddd;">Balance</th>';
                        html += '</tr></thead><tbody>';
                        
                        var totalFee = 0;
                        var totalPaid = 0;
                        var totalBalance = 0;
                        
                        data.courses_list.forEach(function(course, index) {
                            var courseFee = parseFloat(course.fee) || 0;
                            var coursePaid = parseFloat(course.paid) || 0;
                            var courseBalance = (course.balance !== undefined && course.balance !== null) ? parseFloat(course.balance) : Math.max(0, courseFee - coursePaid);
                            
                            totalFee += courseFee;
                            totalPaid += coursePaid;
                            totalBalance += courseBalance;
                            
                            var balanceColor = courseBalance > 0 ? '#E65100' : '#2E7D32';
                            
                            html += '<tr>';
                            html += '<td style="padding: 6px; border: 1px solid #ddd;">' + course.course_name + '</td>';
                            html += '<td style="text-align: right; padding: 6px; border: 1px solid #ddd;">KES ' + formatNumber(courseFee) + '</td>';
                            html += '<td style="text-align: right; padding: 6px; border: 1px solid #ddd; color: #1565C0;">KES ' + formatNumber(coursePaid) + '</td>';
                            html += '<td style="text-align: right; padding: 6px; border: 1px solid #ddd; color: ' + balanceColor + '; font-weight: bold;">KES ' + formatNumber(courseBalance) + '</td>';
                            html += '</tr>';
                        });
                        
                        // Totals row
                        var totalBalanceColor = totalBalance > 0 ? '#E65100' : '#2E7D32';
                        html += '<tr style="background: #e3f2fd; font-weight: bold;">';
                        html += '<td style="padding: 6px; border: 1px solid #ddd;">TOTAL</td>';
                        html += '<td style="text-align: right; padding: 6px; border: 1px solid #ddd;">KES ' + formatNumber(totalFee) + '</td>';
                        html += '<td style="text-align: right; padding: 6px; border: 1px solid #ddd; color: #1565C0;">KES ' + formatNumber(totalPaid) + '</td>';
                        html += '<td style="text-align: right; padding: 6px; border: 1px solid #ddd; color: ' + totalBalanceColor + ';">KES ' + formatNumber(totalBalance) + '</td>';
                        html += '</tr>';
                        
                        html += '</tbody></table>';
                        coursesList.append(html);
                        
                        if (numCourses > 1) {
                            $('#course-name').html('<span style="color: #2E7D32;">📚 Multiple Courses (' + numCourses + ')</span>');
                        } else {
                            $('#course-name').text(data.course_name);
                        }
                        coursesList.show();
                    } else {
                        // Single course - simple display
                        $('#course-name').text(data.course_name || 'Course');
                        coursesList.hide();
                    }
                }
                
                // Helper function to format numbers
                function formatNumber(num) {
                    return parseFloat(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                
                // When course is selected for non-enrolled student
                $('#enroll-course-select').on('change', function() {
                    var selectedOption = $(this).find('option:selected');
                    var tuitionFee = parseFloat(selectedOption.data('fee')) || 0;
                    var courseName = selectedOption.text();
                    
                    if (tuitionFee > 0) {
                        var requiresAdditional = courseRequiresAdditionalFees(courseName);
                        var admissionFee = requiresAdditional ? 1500 : 0;
                        var totalFee = tuitionFee + admissionFee;
                        
                        courseFee = totalFee;
                        previouslyPaid = 0;
                        outstandingBalance = totalFee;
                        
                        $('#course-fee').val(totalFee);
                        $('#previously-paid-display').text(formatCurrency(0));
                        $('#outstanding-balance-display').text(formatCurrency(totalFee));
                        
                        // Update fee breakdown
                        updateFeeBreakdown({
                            tuition_fee: tuitionFee,
                            admission_fee: admissionFee,
                            total_fee: totalFee,
                            requires_additional_fees: requiresAdditional
                        });
                        
                        $('#course-fee-row, #previously-paid-row, #outstanding-balance-row').show();
                        $('#discount-row, #amount-paid-row, #balance-row').show();
                        $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').show();
                        $('#payment-status-row, #notes-row').show();
                        calculateBalance();
                        updatePaymentForOptions(courseName);
                    } else {
                        $('#course-fee-row, #previously-paid-row, #outstanding-balance-row').hide();
                        $('#discount-row, #amount-paid-row, #balance-row').hide();
                        $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').hide();
                        $('#payment-status-row, #notes-row').hide();
                        $('#fee-breakdown').hide();
                        updatePaymentForOptions('');
                    }
                });
                
                // Fetch student fee information when student is selected
                $('#student-select').on('change', function() {
                    var studentId = $(this).val();
                    
                    if (!studentId) {
                        hideAllFields();
                        return;
                    }
                    
                    $('#student-loading').show();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'get_student_fee_info',
                            student_id: studentId,
                            nonce: '<?php echo wp_create_nonce('student_fee_info_nonce'); ?>'
                        },
                        success: function(response) {
                            $('#student-loading').hide();
                            console.log('AJAX Response:', response);
                            
                            if (response && response.success) {
                                var data = response.data;

                                // Display courses summary table
                                displayCoursesList(data);

                                if (data.num_courses > 1) {
                                    // ── Multi-course: show course selector, hide totals until course chosen ──
                                    var $sel = $('#payment-course-select');
                                    $sel.empty().append('<option value="">-- Select Course --</option>');

                                    data.courses_list.forEach(function(course) {
                                        var label = course.course_name +
                                            ' | Fee: KES ' + parseFloat(course.fee).toLocaleString('en-US', {minimumFractionDigits:2}) +
                                            ' | Paid: KES ' + parseFloat(course.paid).toLocaleString('en-US', {minimumFractionDigits:2}) +
                                            ' | Balance: KES ' + parseFloat(course.balance).toLocaleString('en-US', {minimumFractionDigits:2});
                                        $sel.append(
                                            $('<option>', {
                                                value: course.enrollment_id,
                                                text: label,
                                                'data-fee': course.fee,
                                                'data-paid': course.paid,
                                                'data-balance': course.balance,
                                                'data-name': course.course_name_raw || course.course_name
                                            })
                                        );
                                    });

                                    $('#course-selector-row').show();
                                    $('#enrollment-id-hidden').val('');

                                    // Hide fee details until a course is chosen
                                    $('#course-fee-row, #previously-paid-row, #outstanding-balance-row').hide();
                                    $('#discount-row, #amount-paid-row, #balance-row').hide();
                                    $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').hide();
                                    $('#payment-status-row, #notes-row').hide();

                                    // When admin picks a course, load that course's fee/balance
                                    $('#payment-course-select').off('change.multicourse').on('change.multicourse', function() {
                                        var $opt = $(this).find('option:selected');
                                        var enrollId = $(this).val();
                                        if (!enrollId) {
                                            $('#course-fee-row, #previously-paid-row, #outstanding-balance-row').hide();
                                            $('#discount-row, #amount-paid-row, #balance-row').hide();
                                            $('#payment-method-row, #transaction-ref-row, #payment-date-row, #payment-for-row').hide();
                                            $('#payment-status-row, #notes-row').hide();
                                            return;
                                        }

                                        var selFee     = parseFloat($opt.data('fee'))     || 0;
                                        var selPaid    = parseFloat($opt.data('paid'))    || 0;
                                        var selBalance = parseFloat($opt.data('balance')) || 0;
                                        var selName    = $opt.data('name') || $opt.text();

                                        courseFee          = selFee;
                                        previouslyPaid     = selPaid;
                                        outstandingBalance = selBalance;

                                        $('#enrollment-id-hidden').val(enrollId);
                                        $('#course-fee').val(selFee);
                                        $('#previously-paid-display').text(formatCurrency(selPaid));

                                        if (selBalance <= 0) {
                                            $('#outstanding-balance-box').css({'background':'#e8f5e9','border-color':'#4CAF50'});
                                            $('#outstanding-balance-display').css('color','#2E7D32').text(formatCurrency(0) + ' (FULLY PAID)');
                                        } else {
                                            $('#outstanding-balance-box').css({'background':'#fff3e0','border-color':'#FF9800'});
                                            $('#outstanding-balance-display').css('color','#E65100').text(formatCurrency(selBalance));
                                        }

                                        updateFeeBreakdown({
                                            tuition_fee: selFee,
                                            admission_fee: 0,
                                            total_fee: selFee,
                                            requires_additional_fees: false
                                        });

                                        updatePaymentForOptions(selName);
                                        showEnrolledFields();
                                        calculateBalance();
                                    });

                                } else {
                                    // ── Single course: original flow ──
                                    $('#course-selector-row').hide();

                                    courseFee          = parseFloat(data.total_fee)  || 0;
                                    previouslyPaid     = parseFloat(data.total_paid) || 0;
                                    outstandingBalance = parseFloat(data.balance)    || 0;

                                    $('#course-fee').val(courseFee);
                                    $('#enrollment-id-hidden').val(data.enrollment_id);
                                    $('#previously-paid-display').text(formatCurrency(previouslyPaid));

                                    if (outstandingBalance <= 0) {
                                        $('#outstanding-balance-box').css({'background':'#e8f5e9','border-color':'#4CAF50'});
                                        $('#outstanding-balance-display').css('color','#2E7D32').text(formatCurrency(0) + ' (FULLY PAID)');
                                    } else {
                                        $('#outstanding-balance-box').css({'background':'#fff3e0','border-color':'#FF9800'});
                                        $('#outstanding-balance-display').css('color','#E65100').text(formatCurrency(outstandingBalance));
                                    }

                                    updateFeeBreakdown(data);
                                    showEnrolledFields();
                                    calculateBalance();
                                    updatePaymentForOptions(data.course_name_raw || data.course_name);
                                }
                            } else {
                                // Student NOT enrolled
                                console.log('Student not enrolled, showing course selection');
                                $('#enrollment-id-hidden').val('');
                                $('#enroll-course-select').val('');
                                $('#fee-breakdown').hide();
                                showNotEnrolledFields();
                                updatePaymentForOptions('');
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#student-loading').hide();
                            console.log('AJAX Error:', status, error, xhr.responseText);
                            $('#enrollment-id-hidden').val('');
                            $('#enroll-course-select').val('');
                            $('#fee-breakdown').hide();
                            showNotEnrolledFields();
                            updatePaymentForOptions('');
                        }
                    });
                });
                
                // Recalculate when discount or amount paid changes
                $('#discount-given, #amount-paid').on('input', calculateBalance);
                
                <?php if ($is_edit) : ?>
                // EDIT MODE: Load existing payment data AND all active courses
                (function() {
                    var studentId = '<?php echo $payment->student_id; ?>';
                    var enrollmentId = '<?php echo $payment->enrollment_id; ?>';
                    
                    // First, load all active courses for this student via AJAX
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'get_student_fee_info',
                            student_id: studentId,
                            nonce: '<?php echo wp_create_nonce('student_fee_info_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response && response.success) {
                                var data = response.data;
                                
                                // Store values for balance calculation
                                courseFee = parseFloat(data.total_fee) || 0;
                                previouslyPaid = parseFloat(data.total_paid) || 0;
                                outstandingBalance = parseFloat(data.balance) || 0;
                                
                                // Display courses
                                displayCoursesList(data);
                                
                                // Display previously paid and outstanding balance
                                $('#previously-paid-display').text(formatCurrency(previouslyPaid));
                                $('#outstanding-balance-display').text(formatCurrency(outstandingBalance));
                                
                                if (outstandingBalance <= 0) {
                                    $('#outstanding-balance-box').css({
                                        'background': '#e8f5e9',
                                        'border-color': '#4CAF50'
                                    });
                                    $('#outstanding-balance-display').css('color', '#2E7D32').text(formatCurrency(0) + ' (FULLY PAID)');
                                } else {
                                    $('#outstanding-balance-box').css({
                                        'background': '#fff3e0',
                                        'border-color': '#FF9800'
                                    });
                                    $('#outstanding-balance-display').css('color', '#E65100');
                                }
                                
                                updateFeeBreakdown(data);
                                showEnrolledFields();
                                calculateBalance();
                            }
                        }
                    });
                    
                    <?php 
                    // Get enrollment and course info for edit mode
                    $edit_enrollment = null;
                    $edit_course_fee = 0;
                    if ($payment->enrollment_id) {
                        $edit_enrollment = $wpdb->get_row($wpdb->prepare(
                            "SELECT e.*, c.course_name, c.course_code, c.fee as course_fee,
                                    b.total_fee, b.discount_amount, b.total_paid, b.balance
                             FROM {$this->db->get_table_name('enrollments')} e
                             LEFT JOIN {$this->db->get_table_name('courses')} c ON e.course_id = c.course_id
                             LEFT JOIN {$this->db->get_table_name('student_balances')} b ON e.enrollment_id = b.enrollment_id
                             WHERE e.enrollment_id = %d",
                            $payment->enrollment_id
                        ));
                        if ($edit_enrollment) {
                            // Use fee locked in at enrollment time (student_balances), fall back to live course fee
                            $edit_course_fee = $edit_enrollment->total_fee ?: $edit_enrollment->course_fee;
                            
                            $edit_requires_additional = true;
                            $edit_course_name_lower = strtolower($edit_enrollment->course_name);
                            $edit_excluded_courses = array('computer applications', 'computer essentials', 'computer & online essentials');
                            foreach ($edit_excluded_courses as $excluded) {
                                if (strpos($edit_course_name_lower, $excluded) !== false) {
                                    $edit_requires_additional = false;
                                    break;
                                }
                            }
                            
                            $edit_admission_fee = $edit_requires_additional ? 1500 : 0;
                            $edit_total_fee = floatval($edit_course_fee) + $edit_admission_fee;
                        }
                    }
                    ?>
                    
                    <?php if ($edit_enrollment) : ?>
                    $('#enrollment-id-hidden').val('<?php echo $payment->enrollment_id; ?>');
                    updatePaymentForOptions('<?php echo esc_js($edit_enrollment->course_name); ?>');
                    <?php else : ?>
                    $('#course-fee').val(0);
                    showEnrolledFields();
                    <?php endif; ?>
                })();
                <?php else : ?>
                calculateBalance();
                <?php endif; ?>
            });
            </script>
        </div>
        <?php
    }
    
    private function handle_form_submission() {
        global $wpdb;
        
        $is_edit = isset($_POST['payment_id']) && !empty($_POST['payment_id']);
        $payment_id = $is_edit ? intval($_POST['payment_id']) : null;
        $student_id = intval($_POST['student_id']);
        
        // Check if we need to create enrollment first
        $enrollment_id = null;
        $course_fee = 0;
        
        if (!empty($_POST['enrollment_id_hidden'])) {
            // Student already enrolled — use locked-in fee from student_balances
            $enrollment_id = intval($_POST['enrollment_id_hidden']);
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT e.*, c.fee as course_fee, sb.total_fee as locked_fee
                 FROM {$this->db->get_table_name('enrollments')} e
                 LEFT JOIN {$this->db->get_table_name('courses')} c ON e.course_id = c.course_id
                 LEFT JOIN {$this->db->get_table_name('student_balances')} sb ON e.enrollment_id = sb.enrollment_id
                 WHERE e.enrollment_id = %d",
                $enrollment_id
            ));
            if ($enrollment) {
                // Prefer locked-in fee from enrollment time, fallback to live course fee
                $course_fee = floatval($enrollment->locked_fee ?: $enrollment->course_fee);
            }
        } elseif (!empty($_POST['enroll_course_id'])) {
            // Need to create enrollment
            $course_id = intval($_POST['enroll_course_id']);
            
            // Get course info - ALWAYS use course fee from database
            $course = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db->get_table_name('courses')} WHERE course_id = %d",
                $course_id
            ));
            
            if ($course) {
                // Use course fee from database as the base
                $course_fee = floatval($course->fee);
                
                // Create enrollment (fee is stored in student_balances, not enrollments)
                $enrollment_data = array(
                    'student_id' => $student_id,
                    'course_id' => $course_id,
                    'enrollment_date' => current_time('mysql'),
                    'start_date' => date('Y-m-d'),
                    'expected_end_date' => date('Y-m-d', strtotime('+' . ($course->duration_weeks ?: 12) . ' weeks')),
                    'status' => 'Enrolled'
                );
                
                $wpdb->insert($this->db->get_table_name('enrollments'), $enrollment_data);
                $enrollment_id = $wpdb->insert_id;
                
                // Create balance record for this enrollment
                if ($enrollment_id) {
                    $balances_table = $this->db->get_table_name('student_balances');
                    $wpdb->insert(
                        $balances_table,
                        array(
                            'student_id' => $student_id,
                            'enrollment_id' => $enrollment_id,
                            'total_fee' => $course_fee,
                            'discount_amount' => 0,
                            'total_paid' => 0,
                            'balance' => $course_fee
                        ),
                        array('%d', '%d', '%f', '%f', '%f', '%f')
                    );
                }
            }
        }
        
        if (!$enrollment_id) {
            // Try to get existing enrollment
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db->get_table_name('enrollments')} WHERE student_id = %d ORDER BY enrollment_id DESC LIMIT 1",
                $student_id
            ));
            if ($enrollment) {
                $enrollment_id = $enrollment->enrollment_id;
            }
        }
        
        // Amount paid and discount
        $amount_paid = floatval($_POST['amount']);
        $discount = isset($_POST['discount']) ? floatval($_POST['discount']) : 0;
        
        // Ensure values are not negative
        if ($amount_paid < 0) $amount_paid = 0;
        if ($discount < 0) $discount = 0;
        
        $data = array(
            'student_id' => intval($_POST['student_id']),
            'enrollment_id' => $enrollment_id,
            'amount' => $amount_paid,
            'gross_amount' => $amount_paid, // Same as amount (no longer gross - discount)
            'discount' => $discount,
            'payment_method' => sanitize_text_field($_POST['payment_method']),
            'transaction_reference' => sanitize_text_field($_POST['transaction_reference']),
            'payment_date' => sanitize_text_field($_POST['payment_date']),
            'payment_for' => sanitize_text_field($_POST['payment_for']),
            'status' => isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : 'Completed',
            'received_by' => get_current_user_id(),
            'notes' => sanitize_textarea_field($_POST['notes'])
        );
        
        if ($is_edit) {
            // Get old payment to reverse its balance effect and for audit
            $payments_table = $this->db->get_table_name('payments');
            $old_payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$payments_table} WHERE payment_id = %d",
                $payment_id
            ));
            
            // Prepare old data for audit
            $old_data = null;
            if ($old_payment) {
                $old_data = array(
                    'payment_id' => $old_payment->payment_id,
                    'student_id' => $old_payment->student_id,
                    'receipt_number' => $old_payment->receipt_number,
                    'amount' => $old_payment->amount,
                    'discount' => $old_payment->discount,
                    'payment_method' => $old_payment->payment_method,
                    'transaction_reference' => $old_payment->transaction_reference,
                    'payment_date' => $old_payment->payment_date,
                    'payment_for' => $old_payment->payment_for,
                    'status' => $old_payment->status,
                    'notes' => $old_payment->notes
                );
            }
            
            // Update payment
            $result = $wpdb->update(
                $payments_table,
                $data,
                array('payment_id' => $payment_id)
            );
            
            // Log audit trail for UPDATE
            $new_data = array_merge($data, array(
                'payment_id' => $payment_id,
                'receipt_number' => $old_payment ? $old_payment->receipt_number : ''
            ));
            $this->log_audit_trail($payment_id, 'UPDATE', $old_data, $new_data);
            
            // Recalculate balance if enrollment exists
            if ($enrollment_id && $old_payment) {
                // Reverse old payment effect (including old discount)
                $old_discount = isset($old_payment->discount) ? floatval($old_payment->discount) : 0;
                $this->reverse_payment_balance($enrollment_id, $old_payment->amount, $old_discount);
                // Apply new payment with new discount
                $this->update_student_balance($enrollment_id, $amount_paid, $discount);
            }
            
            $this->redirect_url = admin_url('admin.php?page=mtti-mis-payments&message=updated');
        } else {
            // Receipt number is auto-generated
            $data['receipt_number'] = '';
            
            $payment_id = $this->db->create_payment($data);
            
            // Get the generated receipt number for audit
            $new_payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db->get_table_name('payments')} WHERE payment_id = %d",
                $payment_id
            ));
            
            // Log audit trail for CREATE
            $new_data = array_merge($data, array(
                'payment_id' => $payment_id,
                'receipt_number' => $new_payment ? $new_payment->receipt_number : ''
            ));
            $this->log_audit_trail($payment_id, 'CREATE', null, $new_data);
            
            // Automatically update student balance if enrollment exists
            if ($enrollment_id && $payment_id) {
                $this->update_student_balance($enrollment_id, $amount_paid, $discount);
            }
            
            $this->redirect_url = admin_url('admin.php?page=mtti-mis-payments&message=created');
        }
    }
    
    /**
     * Reverse payment balance (used when editing/deleting payments)
     * Balance = Total Fee - Discount Amount - Total Paid
     */
    private function reverse_payment_balance($enrollment_id, $payment_amount, $discount_amount = 0) {
        global $wpdb;
        $balances_table = $this->db->get_table_name('student_balances');
        
        // First get current values
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$balances_table} WHERE enrollment_id = %d",
            $enrollment_id
        ));
        
        if ($current) {
            // Use actual course fee, not stale stored value
            $enrollments_table = $this->db->get_table_name('enrollments');
            $courses_table     = $this->db->get_table_name('courses');
            $actual_fee = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT c.fee FROM {$enrollments_table} e
                 JOIN {$courses_table} c ON e.course_id = c.course_id
                 WHERE e.enrollment_id = %d",
                $enrollment_id
            )));
            if ($actual_fee <= 0) $actual_fee = floatval($current->total_fee);

            $new_total_paid = max(0, $current->total_paid - $payment_amount);
            $new_discount   = max(0, (isset($current->discount_amount) ? $current->discount_amount : 0) - $discount_amount);
            $new_balance    = max(0, $actual_fee - $new_discount - $new_total_paid);

            $wpdb->update(
                $balances_table,
                array(
                    'total_fee'      => $actual_fee,
                    'total_paid'     => $new_total_paid,
                    'discount_amount'=> $new_discount,
                    'balance'        => $new_balance
                ),
                array('enrollment_id' => $enrollment_id)
            );
        }
    }
    
    /**
     * Update student balance after payment
     * Balance = Total Fee - Discount Amount - Total Paid
     */
    private function update_student_balance($enrollment_id, $payment_amount, $discount_amount = 0) {
        global $wpdb;
        $balances_table = $this->db->get_table_name('student_balances');
        
        // Check if balance record exists
        $balance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$balances_table} WHERE enrollment_id = %d",
            $enrollment_id
        ));
        
        if ($balance) {
            // Always use the actual course fee from the courses table to avoid stale data
            $enrollments_table = $this->db->get_table_name('enrollments');
            $courses_table     = $this->db->get_table_name('courses');
            $actual_fee = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT c.fee FROM {$enrollments_table} e
                 JOIN {$courses_table} c ON e.course_id = c.course_id
                 WHERE e.enrollment_id = %d",
                $enrollment_id
            )));
            // Fall back to stored total_fee only if course lookup fails
            if ($actual_fee <= 0) {
                $actual_fee = floatval($balance->total_fee);
            }

            // Recalculate total_paid from payments table for accuracy
            $payments_table_name = $this->db->get_table_name('payments');
            $real_total_paid = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table_name}
                 WHERE enrollment_id = %d AND status = 'Completed'",
                $enrollment_id
            ))) + $payment_amount;

            $new_discount = (isset($balance->discount_amount) ? floatval($balance->discount_amount) : 0) + $discount_amount;
            $new_balance  = max(0, $actual_fee - $new_discount - $real_total_paid);

            $wpdb->update(
                $balances_table,
                array(
                    'total_fee'        => $actual_fee,
                    'total_paid'       => $real_total_paid,
                    'discount_amount'  => $new_discount,
                    'balance'          => $new_balance,
                    'last_payment_date'=> current_time('mysql', false)
                ),
                array('balance_id' => $balance->balance_id)
            );

            // Fire notification hook — get student_id from balance row
            $payment_id_for_notif = $wpdb->get_var($wpdb->prepare(
                "SELECT payment_id FROM {$payments_table_name} WHERE enrollment_id=%d ORDER BY payment_id DESC LIMIT 1",
                $enrollment_id
            ));
            do_action('mtti_payment_recorded', intval($payment_id_for_notif), intval($balance->student_id), $payment_amount, $enrollment_id);
        } else {
            // Create new balance record if it doesn't exist
            // Get enrollment details
            $enrollment = $this->db->get_enrollments(array('enrollment_id' => $enrollment_id));
            
            if (!empty($enrollment)) {
                $total_fee = floatval($enrollment[0]->fee);
                $new_balance = $total_fee - $discount_amount - $payment_amount;
                
                // Ensure balance doesn't go negative
                if ($new_balance < 0) $new_balance = 0;
                
                $wpdb->insert(
                    $balances_table,
                    array(
                        'student_id' => $enrollment[0]->student_id,
                        'enrollment_id' => $enrollment_id,
                        'total_fee' => $total_fee,
                        'discount_amount' => $discount_amount,
                        'total_paid' => $payment_amount,
                        'balance' => $new_balance,
                        'last_payment_date' => current_time('mysql', false)
                    )
                );
            }
        }
    }
    
    private function print_receipt($payment_id) {
        global $wpdb;
        
        // Prevent WordPress from loading admin template
        define('IFRAME_REQUEST', true);
        
        // Get payment details - DIRECT query first
        $payments_table = $this->db->get_table_name('payments');
        
        // Step 1: Get the payment record directly (no joins that could fail)
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$payments_table} WHERE payment_id = %d",
            $payment_id
        ));
        
        if (!$payment) {
            // Payment doesn't exist at all
            $all_payment_ids = $wpdb->get_col("SELECT payment_id FROM {$payments_table} ORDER BY payment_id DESC LIMIT 20");
            
            $debug_info = '<h2>Payment Not Found - Debug Information</h2>';
            $debug_info .= '<p><strong>Requested Payment ID:</strong> ' . esc_html($payment_id) . '</p>';
            $debug_info .= '<p style="color: red;"><strong>This payment ID does not exist in the database.</strong></p>';
            $debug_info .= '<p><strong>Recent Payment IDs in database:</strong></p><ul>';
            foreach ($all_payment_ids as $pid) {
                $debug_info .= '<li>Payment ID: ' . esc_html($pid) . '</li>';
            }
            $debug_info .= '</ul>';
            $debug_info .= '<p><a href="' . admin_url('admin.php?page=mtti-mis-payments') . '" class="button button-primary">Return to Payments List</a></p>';
            
            wp_die($debug_info, 'Payment Not Found');
        }
        
        // Step 2: Get student information (separate query)
        $students_table = $this->db->get_table_name('students');
        $student = null;
        if ($payment->student_id) {
            $student = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, u.display_name, u.user_email 
                 FROM {$students_table} s
                 LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                 WHERE s.student_id = %d",
                $payment->student_id
            ));
        }
        
        // Step 3: Get enrollment and course information (separate query)
        $courses_table = $this->db->get_table_name('courses');
        $enrollments_table = $this->db->get_table_name('enrollments');
        $enrollment = null;
        $course = null;
        
        if ($payment->enrollment_id) {
            $enrollment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$enrollments_table} WHERE enrollment_id = %d",
                $payment->enrollment_id
            ));
            
            if ($enrollment && $enrollment->course_id) {
                $course = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$courses_table} WHERE course_id = %d",
                    $enrollment->course_id
                ));
            }
        }
        
        // Step 4: Get receiver information (separate query)
        $receiver = null;
        if ($payment->received_by) {
            $receiver = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name FROM {$wpdb->users} WHERE ID = %d",
                $payment->received_by
            ));
        }
        
        // Step 5: Build complete payment object with safe defaults
        $payment->admission_number = $student ? ($student->admission_number ?? 'N/A') : 'N/A';
        $payment->student_name = $student ? ($student->display_name ?? $student->admission_number ?? 'Unknown Student') : 'Unknown Student';
        $payment->id_number = $student ? ($student->id_number ?? 'N/A') : 'N/A';
        $payment->phone = $student ? ($student->phone ?? $student->emergency_phone ?? 'N/A') : 'N/A';
        $payment->course_name = $course ? $course->course_name : 'Course Not Specified';
        $payment->course_code = $course ? $course->course_code : 'N/A';
        $payment->received_by_name = $receiver ? $receiver->display_name : 'Admin';
        
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        $settings = get_option('mtti_mis_settings', array());
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt - <?php echo esc_html($payment->receipt_number); ?></title>
            <style>
                /* Hide all WordPress admin elements */
                #wpadminbar,
                #adminmenumain,
                #adminmenuback,
                #adminmenuwrap,
                #wpfooter,
                .update-nag,
                .notice,
                #wpbody-content > .wrap > h1,
                #wpbody-content > .wrap > .page-title-action,
                #screen-meta,
                #screen-meta-links,
                .wp-heading-inline,
                #wp-admin-bar-root-default,
                #collapse-menu {
                    display: none !important;
                }
                
                /* Make content full width */
                #wpcontent,
                #wpbody,
                #wpbody-content {
                    margin-left: 0 !important;
                    padding-left: 0 !important;
                }
                
                html, body {
                    margin: 0 !important;
                    padding: 0 !important;
                    background: white !important;
                }
                
                /* Receipt styles */
                @page { size: A5; margin: 10mm; }
                body { 
                    font-family: Arial, sans-serif; 
                    font-size: 12px;
                    margin: 0;
                    padding: 20px;
                }
                .receipt {
                    border: 2px solid #2E7D32;
                    padding: 20px;
                }
                .header {
                    text-align: center;
                    border-bottom: 2px solid #FF9800;
                    padding-bottom: 15px;
                    margin-bottom: 20px;
                }
                .logo { width: 60px; }
                h1 { font-size: 20px; color: #2E7D32; margin: 10px 0; }
                h2 { font-size: 16px; color: #333; margin: 5px 0; }
                .receipt-number {
                    background: #2E7D32;
                    color: white;
                    padding: 8px 15px;
                    display: inline-block;
                    font-size: 14px;
                    font-weight: bold;
                    margin: 10px 0;
                }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                td { padding: 8px 0; }
                .label { font-weight: bold; width: 40%; }
                .amount-section {
                    background: #f5f5f5;
                    padding: 15px;
                    margin: 20px 0;
                    border-left: 4px solid #FF9800;
                }
                .amount { 
                    font-size: 24px; 
                    font-weight: bold; 
                    color: #2E7D32;
                }
                .footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 1px solid #ddd;
                    font-size: 10px;
                    color: #666;
                }
                .signature {
                    margin-top: 40px;
                }
                .signature-line {
                    width: 200px;
                    border-top: 1px solid #333;
                    margin-top: 40px;
                }
                @media print {
                    body { padding: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body onload="window.print()">
            <div class="receipt">
                <div class="header">
                    <img src="<?php echo $logo_url; ?>" alt="MTTI Logo" class="logo">
                    <h1>MASOMOTELE TECHNICAL TRAINING INSTITUTE</h1>
                    <h2>PAYMENT RECEIPT</h2>
                    <p><?php echo esc_html($settings['institute_address'] ?? 'Sagaas Center, Eldoret, Kenya'); ?></p>
                    <p>Tel: <?php echo esc_html($settings['institute_phone'] ?? '+254 700 000 000'); ?> | Email: <?php echo esc_html($settings['institute_email'] ?? 'info@mtti.ac.ke'); ?></p>
                </div>
                
                <div class="receipt-number">
                    Receipt No: <?php echo esc_html($payment->receipt_number); ?>
                </div>
                
                <table>
                    <tr>
                        <td class="label">Student Name:</td>
                        <td><?php echo esc_html($payment->student_name); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Admission Number:</td>
                        <td><?php echo esc_html($payment->admission_number); ?></td>
                    </tr>
                    <tr>
                        <td class="label">ID Number:</td>
                        <td><?php echo esc_html($payment->id_number); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Phone:</td>
                        <td><?php echo esc_html($payment->phone); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Course:</td>
                        <td><?php echo esc_html($payment->course_name . ' (' . $payment->course_code . ')'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Payment For:</td>
                        <td><?php echo esc_html($payment->payment_for); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Payment Method:</td>
                        <td><?php echo esc_html($payment->payment_method); ?></td>
                    </tr>
                    <?php if ($payment->transaction_reference) : ?>
                    <tr>
                        <td class="label">Transaction Ref:</td>
                        <td><?php echo esc_html($payment->transaction_reference); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="label">Payment Date:</td>
                        <td><?php echo date('F j, Y', strtotime($payment->payment_date)); ?></td>
                    </tr>
                </table>
                
                <div class="amount-section">
                    <table>
                        <?php if ($payment->discount > 0) : ?>
                        <tr>
                            <td class="label">Fees Paid:</td>
                            <td><strong>KES <?php echo number_format($payment->gross_amount, 2); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="label">Discount Given:</td>
                            <td><strong style="color: #2E7D32;">- KES <?php echo number_format($payment->discount, 2); ?></strong></td>
                        </tr>
                        <tr style="border-top: 2px solid #FF9800;">
                            <td class="label">Net Amount Paid:</td>
                            <td class="amount">KES <?php echo number_format($payment->amount, 2); ?></td>
                        </tr>
                        <?php else : ?>
                        <tr>
                            <td class="label">Fees Paid:</td>
                            <td class="amount">KES <?php echo number_format($payment->amount, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    <p style="margin: 10px 0 0 0;">
                        <strong>Amount in Words:</strong> 
                        <?php echo $this->number_to_words($payment->amount); ?> Shillings Only
                    </p>
                </div>
                
                <?php 
                // Get fee summary if enrollment exists
                if ($payment->enrollment_id) :
                    $enrollments_table = $this->db->get_table_name('enrollments');
                    $courses_table_name = $this->db->get_table_name('courses');
                    $payments_table_name = $this->db->get_table_name('payments');
                    $balances_table = $this->db->get_table_name('student_balances');

                    // Always use stored total_fee (locked at enrollment) — fall back to current course fee only if missing
                    $actual_course_fee = floatval($wpdb->get_var($wpdb->prepare(
                        "SELECT c.fee FROM {$enrollments_table} e
                         JOIN {$courses_table_name} c ON e.course_id = c.course_id
                         WHERE e.enrollment_id = %d",
                        $payment->enrollment_id
                    )));

                    $balance_info = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$balances_table} WHERE enrollment_id = %d",
                        $payment->enrollment_id
                    ));

                    $display_fee      = ($balance_info && floatval($balance_info->total_fee) > 0) ? floatval($balance_info->total_fee) : $actual_course_fee;
                    $display_discount = $balance_info ? floatval($balance_info->discount_amount) : 0;
                    $display_paid     = floatval($wpdb->get_var($wpdb->prepare(
                        "SELECT COALESCE(SUM(amount), 0) FROM {$payments_table_name}
                         WHERE enrollment_id = %d AND status = 'Completed'",
                        $payment->enrollment_id
                    )));
                    $display_balance  = max(0, $display_fee - $display_discount - $display_paid);

                    if ($display_fee > 0) :
                ?>
                ?>
                <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">Fee Summary</h3>
                    <table style="width: 100%;">
                        <tr>
                            <td class="label">Total Fees Payable:</td>
                            <td><strong>KES <?php echo number_format($display_fee, 2); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="label">Fees Paid (including this payment):</td>
                            <td><strong style="color: #1976D2;">KES <?php echo number_format($display_paid, 2); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="label">Discount Given:</td>
                            <td><strong style="color: #2E7D32;">KES <?php echo number_format($display_discount, 2); ?></strong></td>
                        </tr>
                        <tr style="border-top: 1px solid #FF9800;">
                            <td class="label" style="font-size: 14px; padding-top: 8px;">Balance:</td>
                            <td style="padding-top: 8px;">
                                <strong style="color: <?php echo $display_balance <= 0 ? '#2E7D32' : '#D32F2F'; ?>; font-size: 16px;">
                                    KES <?php echo number_format($display_balance, 2); ?>
                                    <?php if ($display_balance <= 0) echo '<br><span style="font-size: 12px;">(FULLY PAID)</span>'; ?>
                                </strong>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php 
                    endif;
                endif; 
                ?>
                
                <?php if ($payment->notes) : ?>
                <p><strong>Notes:</strong> <?php echo esc_html($payment->notes); ?></p>
                <?php endif; ?>
                
                <div class="signature">
                    <p><strong>Received By:</strong> <?php echo esc_html($payment->received_by_name); ?></p>
                    <div class="signature-line"></div>
                    <p>Authorized Signature</p>
                </div>
                
                <div class="footer">
                    <p><strong>"Start Learning, Start Earning"</strong></p>
                    <p>This is a computer-generated receipt and is valid without signature.</p>
                    <p>Generated on: <?php echo date('F j, Y g:i A'); ?></p>
                </div>
            </div>
            
            <script>
            // Forcefully hide all WordPress admin elements
            document.addEventListener('DOMContentLoaded', function() {
                // Hide admin bar
                var adminBar = document.getElementById('wpadminbar');
                if (adminBar) adminBar.style.display = 'none';
                
                // Hide admin menu
                var adminMenu = document.getElementById('adminmenumain');
                if (adminMenu) adminMenu.style.display = 'none';
                
                var adminMenuBack = document.getElementById('adminmenuback');
                if (adminMenuBack) adminMenuBack.style.display = 'none';
                
                var adminMenuWrap = document.getElementById('adminmenuwrap');
                if (adminMenuWrap) adminMenuWrap.style.display = 'none';
                
                // Remove margin from content
                var wpContent = document.getElementById('wpcontent');
                if (wpContent) {
                    wpContent.style.marginLeft = '0';
                    wpContent.style.paddingLeft = '0';
                }
                
                var wpBody = document.getElementById('wpbody');
                if (wpBody) {
                    wpBody.style.marginLeft = '0';
                    wpBody.style.paddingLeft = '0';
                }
                
                // Hide all WordPress UI elements
                var elementsToHide = [
                    'wpfooter',
                    'screen-meta',
                    'screen-meta-links',
                    'collapse-menu'
                ];
                
                elementsToHide.forEach(function(id) {
                    var elem = document.getElementById(id);
                    if (elem) elem.style.display = 'none';
                });
                
                // Hide all notices and nav elements
                var notices = document.querySelectorAll('.update-nag, .notice, .wp-heading-inline, .page-title-action');
                notices.forEach(function(notice) {
                    notice.style.display = 'none';
                });
                
                // Set body background to white
                document.body.style.background = 'white';
                document.body.style.margin = '0';
                document.body.style.padding = '20px';
                
                // Auto-print after a short delay
                setTimeout(function() {
                    window.print();
                }, 500);
            });
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    private function number_to_words($number) {
        $ones = array('', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 
                     'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 
                     'Eighteen', 'Nineteen');
        $tens = array('', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety');
        
        $number = floor($number);
        
        if ($number < 20) {
            return $ones[$number];
        } elseif ($number < 100) {
            return $tens[floor($number / 10)] . ' ' . $ones[$number % 10];
        } elseif ($number < 1000) {
            return $ones[floor($number / 100)] . ' Hundred ' . $this->number_to_words($number % 100);
        } elseif ($number < 1000000) {
            return $this->number_to_words(floor($number / 1000)) . ' Thousand ' . $this->number_to_words($number % 1000);
        } else {
            return $this->number_to_words(floor($number / 1000000)) . ' Million ' . $this->number_to_words($number % 1000000);
        }
    }
    
    /**
     * Display Payment Arrears Report
     * Shows students who haven't paid full amount by second week
     */
    private function display_arrears_report() {
        global $wpdb;
        
        $students_table = $wpdb->prefix . 'mtti_students';
        $enrollments_table = $wpdb->prefix . 'mtti_enrollments';
        $balances_table = $wpdb->prefix . 'mtti_student_balances';
        $payments_table = $wpdb->prefix . 'mtti_payments';
        $courses_table = $wpdb->prefix . 'mtti_courses';
        
        // Get filter parameters
        $filter_course = isset($_GET['course']) ? intval($_GET['course']) : 0;
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'arrears';
        $weeks_overdue = isset($_GET['weeks']) ? intval($_GET['weeks']) : 2;
        
        // Calculate date threshold (students enrolled more than X weeks ago)
        $threshold_date = date('Y-m-d', strtotime("-{$weeks_overdue} weeks"));
        
        // Build query for students with outstanding balances
        $sql = "SELECT 
                    s.student_id,
                    s.admission_number,
                    u.display_name,
                    u.user_email,
                    s.phone,
                    c.course_name,
                    c.course_code,
                    e.enrollment_date,
                    DATEDIFF(NOW(), e.enrollment_date) as days_enrolled,
                    FLOOR(DATEDIFF(NOW(), e.enrollment_date) / 7) as weeks_enrolled,
                    c.fee as total_fee,
                    COALESCE((SELECT SUM(discount) FROM {$payments_table} WHERE student_id = s.student_id AND status = 'Completed'), 0) as discount,
                    COALESCE((SELECT SUM(amount) FROM {$payments_table} WHERE student_id = s.student_id AND status = 'Completed'), 0) as total_paid,
                    GREATEST(0, c.fee - COALESCE((SELECT SUM(discount) FROM {$payments_table} WHERE student_id = s.student_id AND status = 'Completed'), 0) - COALESCE((SELECT SUM(amount) FROM {$payments_table} WHERE student_id = s.student_id AND status = 'Completed'), 0)) as balance
                FROM {$students_table} s
                LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                LEFT JOIN {$enrollments_table} e ON s.student_id = e.student_id
                LEFT JOIN {$courses_table} c ON e.course_id = c.course_id
                LEFT JOIN {$balances_table} b ON e.enrollment_id = b.enrollment_id
                WHERE s.status = 'Active'
                AND e.status IN ('Enrolled', 'Active')";
        
        if ($filter_course > 0) {
            $sql .= $wpdb->prepare(" AND e.course_id = %d", $filter_course);
        }
        
        if ($filter_status === 'arrears') {
            // Only students with balance > 0 and enrolled more than X weeks
            $sql .= $wpdb->prepare(" AND e.enrollment_date <= %s", $threshold_date);
            $sql .= " HAVING balance > 0";
        } elseif ($filter_status === 'cleared') {
            $sql .= " HAVING balance <= 0";
        }
        
        $sql .= " ORDER BY balance DESC, weeks_enrolled DESC";
        
        $students = $wpdb->get_results($sql);
        
        // Get courses for filter
        $courses = $wpdb->get_results("SELECT course_id, course_name, course_code FROM {$courses_table} WHERE status = 'Active' ORDER BY course_name");
        
        // Calculate totals
        $total_arrears = 0;
        $total_students_arrears = 0;
        foreach ($students as $s) {
            if ($s->balance > 0) {
                $total_arrears += $s->balance;
                $total_students_arrears++;
            }
        }
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-chart-bar" style="vertical-align: middle;"></span>
                Payment Arrears Report
            </h1>
            <a href="<?php echo admin_url('admin.php?page=mtti-mis-payments'); ?>" class="page-title-action">← Back to Payments</a>
            <hr class="wp-header-end">
            
            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
                <div style="background: #fff; border-left: 4px solid #dc3545; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 2rem; font-weight: bold; color: #dc3545;">KES <?php echo number_format($total_arrears); ?></div>
                    <div style="color: #666;">Total Outstanding</div>
                </div>
                <div style="background: #fff; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 2rem; font-weight: bold; color: #ffc107;"><?php echo $total_students_arrears; ?></div>
                    <div style="color: #666;">Students with Arrears</div>
                </div>
                <div style="background: #fff; border-left: 4px solid #17a2b8; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 2rem; font-weight: bold; color: #17a2b8;"><?php echo $weeks_overdue; ?>+ weeks</div>
                    <div style="color: #666;">Overdue Threshold</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div style="background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd;">
                <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
                    <input type="hidden" name="page" value="mtti-mis-payments">
                    <input type="hidden" name="action" value="arrears-report">
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Course</label>
                        <select name="course" style="min-width: 200px;">
                            <option value="0">All Courses</option>
                            <?php foreach ($courses as $c) : ?>
                            <option value="<?php echo $c->course_id; ?>" <?php selected($filter_course, $c->course_id); ?>>
                                <?php echo esc_html($c->course_code . ' - ' . $c->course_name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Status</label>
                        <select name="status">
                            <option value="arrears" <?php selected($filter_status, 'arrears'); ?>>With Arrears Only</option>
                            <option value="cleared" <?php selected($filter_status, 'cleared'); ?>>Fully Paid Only</option>
                            <option value="all" <?php selected($filter_status, 'all'); ?>>All Students</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">Weeks Enrolled</label>
                        <select name="weeks">
                            <option value="1" <?php selected($weeks_overdue, 1); ?>>1+ week</option>
                            <option value="2" <?php selected($weeks_overdue, 2); ?>>2+ weeks</option>
                            <option value="3" <?php selected($weeks_overdue, 3); ?>>3+ weeks</option>
                            <option value="4" <?php selected($weeks_overdue, 4); ?>>4+ weeks</option>
                        </select>
                    </div>
                    
                    <div>
                        <button type="submit" class="button button-primary">Filter</button>
                        <button type="button" class="button" onclick="window.print();">🖨️ Print Report</button>
                    </div>
                </form>
            </div>
            
            <!-- Results Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 12%;">Admission No.</th>
                        <th style="width: 18%;">Student Name</th>
                        <th style="width: 12%;">Phone</th>
                        <th style="width: 15%;">Course</th>
                        <th style="width: 8%;">Weeks</th>
                        <th style="width: 10%; text-align: right;">Total Fee</th>
                        <th style="width: 10%; text-align: right;">Paid</th>
                        <th style="width: 10%; text-align: right;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students) : $i = 0; ?>
                        <?php foreach ($students as $s) : $i++; ?>
                        <tr style="<?php echo $s->balance > 0 ? 'background: #fff3cd;' : ''; ?>">
                            <td><?php echo $i; ?></td>
                            <td><strong><?php echo esc_html($s->admission_number); ?></strong></td>
                            <td><?php echo esc_html($s->display_name); ?></td>
                            <td><?php echo esc_html($s->phone ?: '-'); ?></td>
                            <td><?php echo esc_html($s->course_code); ?></td>
                            <td>
                                <span style="background: <?php echo $s->weeks_enrolled >= 4 ? '#dc3545' : ($s->weeks_enrolled >= 2 ? '#ffc107' : '#28a745'); ?>; color: <?php echo $s->weeks_enrolled >= 2 ? '#000' : '#fff'; ?>; padding: 2px 8px; border-radius: 10px; font-size: 0.85em;">
                                    <?php echo $s->weeks_enrolled; ?> wks
                                </span>
                            </td>
                            <td style="text-align: right;"><?php echo number_format($s->total_fee - $s->discount); ?></td>
                            <td style="text-align: right; color: #28a745;"><?php echo number_format($s->total_paid); ?></td>
                            <td style="text-align: right; font-weight: bold; color: <?php echo $s->balance > 0 ? '#dc3545' : '#28a745'; ?>;">
                                <?php echo $s->balance > 0 ? number_format($s->balance) : '✓ Cleared'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 30px;">
                                <?php if ($filter_status === 'arrears') : ?>
                                    ✅ No students with outstanding balances found.
                                <?php else : ?>
                                    No students found matching the criteria.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($students && $filter_status !== 'cleared') : ?>
                <tfoot>
                    <tr style="background: #f1f1f1; font-weight: bold;">
                        <td colspan="6" style="text-align: right;">TOTALS:</td>
                        <td style="text-align: right;"><?php echo number_format(array_sum(array_column($students, 'total_fee')) - array_sum(array_column($students, 'discount'))); ?></td>
                        <td style="text-align: right; color: #28a745;"><?php echo number_format(array_sum(array_column($students, 'total_paid'))); ?></td>
                        <td style="text-align: right; color: #dc3545;"><?php echo number_format($total_arrears); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            
            <style>
                @media print {
                    .page-title-action, form, .wp-header-end { display: none !important; }
                    .wrap { padding: 0 !important; }
                    table { font-size: 11px !important; }
                }
            </style>
        </div>
        <?php
    }
}
