<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MTTI_MIS_Admin_Expenses {

    private $plugin_name;
    private $version;
    private $table;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        global $wpdb;
        $this->table = $wpdb->prefix . 'mtti_expenses';
    }

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'mtti_expenses';
        $charset = $wpdb->get_charset_collate();
        if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table ) {
            $sql = "CREATE TABLE $table (
                expense_id     BIGINT NOT NULL AUTO_INCREMENT,
                expense_date   DATE NOT NULL,
                category       VARCHAR(100) NOT NULL,
                description    VARCHAR(300) NOT NULL,
                amount         DECIMAL(10,2) NOT NULL,
                paid_to        VARCHAR(200) DEFAULT NULL,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
                reference      VARCHAR(100) DEFAULT NULL,
                recorded_by    BIGINT UNSIGNED DEFAULT NULL,
                attachment     VARCHAR(300) DEFAULT NULL,
                notes          TEXT DEFAULT NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (expense_id),
                KEY expense_date (expense_date),
                KEY category (category),
                KEY recorded_by (recorded_by)
            ) $charset;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }
    }

    private function handle_actions() {
        global $wpdb;
        if ( ! isset( $_POST['mtti_expense_nonce'] ) ||
             ! wp_verify_nonce( $_POST['mtti_expense_nonce'], 'mtti_expense_action' ) ) return;

        $action = sanitize_text_field( $_POST['action_type'] ?? '' );

        if ( $action === 'add' || $action === 'edit' ) {
            $data = [
                'expense_date'   => sanitize_text_field( $_POST['expense_date'] ),
                'category'       => sanitize_text_field( $_POST['category'] ),
                'description'    => sanitize_text_field( $_POST['description'] ),
                'amount'         => floatval( $_POST['amount'] ),
                'paid_to'        => sanitize_text_field( $_POST['paid_to'] ),
                'payment_method' => sanitize_text_field( $_POST['payment_method'] ),
                'reference'      => sanitize_text_field( $_POST['reference'] ),
                'notes'          => sanitize_textarea_field( $_POST['notes'] ),
                'recorded_by'    => get_current_user_id(),
            ];
            if ( $action === 'add' ) {
                $wpdb->insert( $this->table, $data );
                echo '<div class="notice notice-success"><p>✅ Expense recorded.</p></div>';
            } else {
                $wpdb->update( $this->table, $data, [ 'expense_id' => intval($_POST['expense_id']) ] );
                echo '<div class="notice notice-success"><p>✅ Expense updated.</p></div>';
            }
        }
        if ( $action === 'delete' ) {
            $wpdb->delete( $this->table, [ 'expense_id' => intval($_POST['expense_id']) ] );
            echo '<div class="notice notice-warning"><p>🗑️ Expense deleted.</p></div>';
        }
    }

    public function display() {
        self::create_table();
        $this->handle_actions();
        global $wpdb;

        $where = 'WHERE 1=1';
        if ( ! empty( $_GET['cat'] ) )   $where .= $wpdb->prepare(' AND category = %s', $_GET['cat']);
        if ( ! empty( $_GET['month'] ) ) $where .= $wpdb->prepare(' AND DATE_FORMAT(expense_date,"%%Y-%%m") = %s', $_GET['month']);

        $expenses = $wpdb->get_results("SELECT * FROM {$this->table} $where ORDER BY expense_date DESC");
        $total    = $wpdb->get_var("SELECT SUM(amount) FROM {$this->table} $where");
        $cats     = $wpdb->get_col("SELECT DISTINCT category FROM {$this->table} ORDER BY category");

        $edit = null;
        if ( ! empty( $_GET['edit_id'] ) )
            $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE expense_id = %d", intval($_GET['edit_id'])));

        $categories = ['Rent','Utilities','Salaries','Marketing','Supplies','Equipment','Transport','Internet','Repairs','Other'];
        $methods    = ['Cash','M-Pesa','Bank Transfer','Cheque','Card'];
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            💸 Expenses
            <button class="button button-primary" onclick="var f=document.getElementById('mtti-exp-form');f.style.display=f.style.display==='none'?'block':'none'">+ Add Expense</button>
        </h1>

        <div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;">
            <div style="background:#fff;border-left:4px solid #d63638;padding:15px 25px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Total Shown</div>
                <div style="font-size:26px;font-weight:700;color:#d63638;">KES <?php echo number_format(floatval($total),2); ?></div>
            </div>
            <div style="background:#fff;border-left:4px solid #3d6318;padding:15px 25px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Records</div>
                <div style="font-size:26px;font-weight:700;color:#3d6318;"><?php echo count($expenses); ?></div>
            </div>
        </div>

        <form method="GET" style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="page" value="mtti-mis-expenses">
            <select name="cat" style="height:34px;">
                <option value="">All Categories</option>
                <?php foreach($cats as $c): ?><option value="<?php echo esc_attr($c); ?>" <?php selected($_GET['cat']??'',$c); ?>><?php echo esc_html($c); ?></option><?php endforeach; ?>
            </select>
            <input type="month" name="month" value="<?php echo esc_attr($_GET['month']??''); ?>" style="height:34px;padding:0 8px;">
            <button class="button">Filter</button>
            <a href="?page=mtti-mis-expenses" class="button">Clear</a>
        </form>

        <div id="mtti-exp-form" style="display:<?php echo $edit?'block':'none'; ?>;background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;margin-bottom:20px;">
            <h2><?php echo $edit?'✏️ Edit Expense':'➕ New Expense'; ?></h2>
            <form method="POST">
                <?php wp_nonce_field('mtti_expense_action','mtti_expense_nonce'); ?>
                <input type="hidden" name="action_type" value="<?php echo $edit?'edit':'add'; ?>">
                <?php if($edit): ?><input type="hidden" name="expense_id" value="<?php echo $edit->expense_id; ?>"><?php endif; ?>
                <table class="form-table" style="max-width:700px;">
                    <tr><th>Date *</th><td><input type="date" name="expense_date" required value="<?php echo esc_attr($edit->expense_date??date('Y-m-d')); ?>" class="regular-text"></td></tr>
                    <tr><th>Category *</th><td><select name="category" required><?php foreach($categories as $c): ?><option value="<?php echo $c; ?>" <?php selected($edit->category??'',$c); ?>><?php echo $c; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Description *</th><td><input type="text" name="description" required value="<?php echo esc_attr($edit->description??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Amount (KES) *</th><td><input type="number" name="amount" required step="0.01" min="0" value="<?php echo esc_attr($edit->amount??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Paid To</th><td><input type="text" name="paid_to" value="<?php echo esc_attr($edit->paid_to??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Payment Method</th><td><select name="payment_method"><?php foreach($methods as $m): ?><option value="<?php echo $m; ?>" <?php selected($edit->payment_method??'Cash',$m); ?>><?php echo $m; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Reference / Receipt</th><td><input type="text" name="reference" value="<?php echo esc_attr($edit->reference??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="3" class="regular-text"><?php echo esc_textarea($edit->notes??''); ?></textarea></td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php echo $edit?'Update Expense':'Save Expense'; ?></button> <a href="?page=mtti-mis-expenses" class="button">Cancel</a></p>
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead><tr>
                <th width="90">Date</th><th width="110">Category</th><th>Description</th>
                <th width="110">Paid To</th><th width="90">Method</th><th width="110">Reference</th>
                <th width="120" style="text-align:right;">Amount (KES)</th><th width="100">Actions</th>
            </tr></thead>
            <tbody>
            <?php if(empty($expenses)): ?>
                <tr><td colspan="8" style="text-align:center;color:#777;padding:30px;">No expenses found.</td></tr>
            <?php else: foreach($expenses as $e): ?>
                <tr>
                    <td><?php echo esc_html($e->expense_date); ?></td>
                    <td><span style="background:#f0f0f0;padding:2px 8px;border-radius:3px;font-size:11px;"><?php echo esc_html($e->category); ?></span></td>
                    <td><?php echo esc_html($e->description); ?><?php if($e->notes): ?><br><small style="color:#777;"><?php echo esc_html(substr($e->notes,0,60)); ?></small><?php endif; ?></td>
                    <td><?php echo esc_html($e->paid_to); ?></td>
                    <td><?php echo esc_html($e->payment_method); ?></td>
                    <td><?php echo esc_html($e->reference); ?></td>
                    <td style="text-align:right;font-weight:600;"><?php echo number_format($e->amount,2); ?></td>
                    <td>
                        <a href="?page=mtti-mis-expenses&edit_id=<?php echo $e->expense_id; ?>" class="button button-small">Edit</a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense?')">
                            <?php wp_nonce_field('mtti_expense_action','mtti_expense_nonce'); ?>
                            <input type="hidden" name="action_type" value="delete">
                            <input type="hidden" name="expense_id" value="<?php echo $e->expense_id; ?>">
                            <button type="submit" class="button button-small" style="color:#d63638;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="6" style="text-align:right;font-weight:700;padding:10px;">TOTAL:</td>
                <td style="text-align:right;font-weight:700;font-size:15px;color:#d63638;">KES <?php echo number_format(floatval($total),2); ?></td>
                <td></td>
            </tr></tfoot>
        </table>
        </div>
        <?php
    }
}
