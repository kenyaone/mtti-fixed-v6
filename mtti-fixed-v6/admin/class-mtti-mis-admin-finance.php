<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MTTI_MIS_Admin_Finance {

    private $plugin_name;
    private $version;
    private $exp_table;
    private $inc_table;
    private $pay_table;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        global $wpdb;
        $this->exp_table = $wpdb->prefix . 'mtti_expenses';
        $this->inc_table = $wpdb->prefix . 'mtti_income';
        $this->pay_table = $wpdb->prefix . 'mtti_payments';
    }

    /* ─── Create tables ────────────────────────────────────────────── */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $exp = $wpdb->prefix . 'mtti_expenses';
        if ( $wpdb->get_var("SHOW TABLES LIKE '$exp'") !== $exp ) {
            dbDelta("CREATE TABLE $exp (
                expense_id     BIGINT NOT NULL AUTO_INCREMENT,
                expense_date   DATE NOT NULL,
                category       VARCHAR(100) NOT NULL,
                description    VARCHAR(300) NOT NULL,
                amount         DECIMAL(10,2) NOT NULL,
                paid_to        VARCHAR(200) DEFAULT NULL,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
                reference      VARCHAR(100) DEFAULT NULL,
                recorded_by    BIGINT UNSIGNED DEFAULT NULL,
                notes          TEXT DEFAULT NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (expense_id),
                KEY expense_date (expense_date),
                KEY category (category)
            ) $charset;");
        }

        $inc = $wpdb->prefix . 'mtti_income';
        if ( $wpdb->get_var("SHOW TABLES LIKE '$inc'") !== $inc ) {
            dbDelta("CREATE TABLE $inc (
                income_id      BIGINT NOT NULL AUTO_INCREMENT,
                income_date    DATE NOT NULL,
                category       VARCHAR(100) NOT NULL,
                description    VARCHAR(300) NOT NULL,
                amount         DECIMAL(10,2) NOT NULL,
                received_from  VARCHAR(200) DEFAULT NULL,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
                reference      VARCHAR(100) DEFAULT NULL,
                recorded_by    BIGINT UNSIGNED DEFAULT NULL,
                notes          TEXT DEFAULT NULL,
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (income_id),
                KEY income_date (income_date),
                KEY category (category)
            ) $charset;");
        }
    }

    /* ─── Handle POST ──────────────────────────────────────────────── */
    private function handle_actions() {
        global $wpdb;
        if ( empty($_POST['mtti_finance_nonce']) ||
             ! wp_verify_nonce($_POST['mtti_finance_nonce'], 'mtti_finance_action') ) return;

        $action = sanitize_text_field($_POST['action_type'] ?? '');
        $type   = sanitize_text_field($_POST['entry_type'] ?? ''); // 'income' or 'expense'

        if ( $action === 'add_income' || $action === 'edit_income' ) {
            $data = [
                'income_date'    => sanitize_text_field($_POST['income_date']),
                'category'       => sanitize_text_field($_POST['category']),
                'description'    => sanitize_text_field($_POST['description']),
                'amount'         => floatval($_POST['amount']),
                'received_from'  => sanitize_text_field($_POST['received_from']),
                'payment_method' => sanitize_text_field($_POST['payment_method']),
                'reference'      => sanitize_text_field($_POST['reference']),
                'notes'          => sanitize_textarea_field($_POST['notes']),
                'recorded_by'    => get_current_user_id(),
            ];
            if ( $action === 'add_income' ) {
                $wpdb->insert($this->inc_table, $data);
                echo '<div class="notice notice-success"><p>✅ Income recorded.</p></div>';
            } else {
                $wpdb->update($this->inc_table, $data, ['income_id' => intval($_POST['entry_id'])]);
                echo '<div class="notice notice-success"><p>✅ Income updated.</p></div>';
            }
        }

        if ( $action === 'delete_income' ) {
            $wpdb->delete($this->inc_table, ['income_id' => intval($_POST['entry_id'])]);
            echo '<div class="notice notice-warning"><p>🗑️ Income entry deleted.</p></div>';
        }

        if ( $action === 'add_expense' || $action === 'edit_expense' ) {
            $data = [
                'expense_date'   => sanitize_text_field($_POST['expense_date']),
                'category'       => sanitize_text_field($_POST['category']),
                'description'    => sanitize_text_field($_POST['description']),
                'amount'         => floatval($_POST['amount']),
                'paid_to'        => sanitize_text_field($_POST['paid_to']),
                'payment_method' => sanitize_text_field($_POST['payment_method']),
                'reference'      => sanitize_text_field($_POST['reference']),
                'notes'          => sanitize_textarea_field($_POST['notes']),
                'recorded_by'    => get_current_user_id(),
            ];
            if ( $action === 'add_expense' ) {
                $wpdb->insert($this->exp_table, $data);
                echo '<div class="notice notice-success"><p>✅ Expense recorded.</p></div>';
            } else {
                $wpdb->update($this->exp_table, $data, ['expense_id' => intval($_POST['entry_id'])]);
                echo '<div class="notice notice-success"><p>✅ Expense updated.</p></div>';
            }
        }

        if ( $action === 'delete_expense' ) {
            $wpdb->delete($this->exp_table, ['expense_id' => intval($_POST['entry_id'])]);
            echo '<div class="notice notice-warning"><p>🗑️ Expense deleted.</p></div>';
        }
    }

    /* ─── Main display ─────────────────────────────────────────────── */
    public function display() {
        self::create_tables();
        $this->handle_actions();
        global $wpdb;

        $month = sanitize_text_field($_GET['month'] ?? date('Y-m'));
        $tab   = sanitize_text_field($_GET['tab']   ?? 'pl');  // pl | income | expenses

        // ── Totals (always from same tables, same month filter) ──────
        $exp_total = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$this->exp_table}
             WHERE DATE_FORMAT(expense_date,'%%Y-%%m') = %s", $month
        )));

        $inc_manual = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$this->inc_table}
             WHERE DATE_FORMAT(income_date,'%%Y-%%m') = %s", $month
        )));

        // Student fee payments (Completed only)
        $fee_total = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$this->pay_table}
             WHERE status='Completed' AND DATE_FORMAT(payment_date,'%%Y-%%m') = %s", $month
        )));
        $fee_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->pay_table}
             WHERE status='Completed' AND DATE_FORMAT(payment_date,'%%Y-%%m') = %s", $month
        )));

        $inc_total = $inc_manual + $fee_total;
        $profit    = $inc_total - $exp_total;
        $pcolor    = $profit >= 0 ? '#3d6318' : '#d63638';
        $plabel    = $profit >= 0 ? '✅ Net Profit' : '⚠️ Net Loss';
        $month_label = date('F Y', strtotime($month . '-01'));

        // Edit modes
        $edit_income  = null;
        $edit_expense = null;
        if ( ! empty($_GET['edit_income']) )
            $edit_income = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->inc_table} WHERE income_id=%d", intval($_GET['edit_income'])));
        if ( ! empty($_GET['edit_expense']) )
            $edit_expense = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->exp_table} WHERE expense_id=%d", intval($_GET['edit_expense'])));

        // Activate correct tab if editing
        if ( $edit_income )  $tab = 'income';
        if ( $edit_expense ) $tab = 'expenses';

        $inc_cats = ['Tuition Fees','Registration Fees','Exam Fees','Short Course','Grant','Donation','Rental','Other'];
        $exp_cats = ['Rent','Utilities','Salaries','Marketing','Supplies','Equipment','Transport','Internet','Repairs','Other'];
        $methods  = ['Cash','M-Pesa','Bank Transfer','Cheque','Card','NCBA Paybill'];

        $base_url = '?page=mtti-mis-finance&month=' . urlencode($month);
        ?>
        <div class="wrap">
        <h1>📊 Finance — <?php echo esc_html($month_label); ?></h1>

        <!-- Month picker -->
        <form method="GET" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;">
            <input type="hidden" name="page" value="mtti-mis-finance">
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <label style="font-weight:600;">Month:</label>
            <input type="month" name="month" value="<?php echo esc_attr($month); ?>" style="height:34px;padding:0 8px;">
            <button class="button button-primary">Go</button>
        </form>

        <!-- P&L Summary (always visible) -->
        <div style="display:flex;gap:12px;margin-bottom:25px;flex-wrap:wrap;">
            <div style="background:#fff;border-left:4px solid #0073aa;padding:14px 20px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Student Fees (<?php echo $fee_count; ?>)</div>
                <div style="font-size:20px;font-weight:700;color:#0073aa;">KES <?php echo number_format($fee_total,2); ?></div>
            </div>
            <div style="background:#fff;border-left:4px solid #FF9700;padding:14px 20px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Other Income</div>
                <div style="font-size:20px;font-weight:700;color:#FF9700;">KES <?php echo number_format($inc_manual,2); ?></div>
            </div>
            <div style="background:#fff;border-left:4px solid #3d6318;padding:14px 20px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Total Income</div>
                <div style="font-size:20px;font-weight:700;color:#3d6318;">KES <?php echo number_format($inc_total,2); ?></div>
            </div>
            <div style="background:#fff;border-left:4px solid #d63638;padding:14px 20px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Total Expenses</div>
                <div style="font-size:20px;font-weight:700;color:#d63638;">KES <?php echo number_format($exp_total,2); ?></div>
            </div>
            <div style="background:<?php echo $profit>=0?'#f0faf0':'#fff5f5'; ?>;border-left:4px solid <?php echo $pcolor; ?>;padding:14px 20px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:180px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;"><?php echo $plabel; ?></div>
                <div style="font-size:24px;font-weight:700;color:<?php echo $pcolor; ?>;">KES <?php echo number_format(abs($profit),2); ?></div>
            </div>
        </div>

        <!-- Tabs -->
        <div style="border-bottom:2px solid #ddd;margin-bottom:20px;">
            <a href="<?php echo $base_url; ?>&tab=pl" style="display:inline-block;padding:8px 18px;font-weight:600;text-decoration:none;<?php echo $tab==='pl'?'border-bottom:3px solid #3d6318;color:#3d6318;margin-bottom:-2px;':'color:#555;'; ?>">📈 P&L Breakdown</a>
            <a href="<?php echo $base_url; ?>&tab=income" style="display:inline-block;padding:8px 18px;font-weight:600;text-decoration:none;<?php echo $tab==='income'?'border-bottom:3px solid #3d6318;color:#3d6318;margin-bottom:-2px;':'color:#555;'; ?>">💰 Income</a>
            <a href="<?php echo $base_url; ?>&tab=expenses" style="display:inline-block;padding:8px 18px;font-weight:600;text-decoration:none;<?php echo $tab==='expenses'?'border-bottom:3px solid #3d6318;color:#3d6318;margin-bottom:-2px;':'color:#555;'; ?>">💸 Expenses</a>
        </div>

        <?php if ( $tab === 'pl' ): ?>
        <!-- ═══════════════════════════════ P&L BREAKDOWN ═════════════════════════════ -->
        <?php
        // Income by category
        $inc_by_cat = $wpdb->get_results($wpdb->prepare(
            "SELECT category, SUM(amount) as total FROM {$this->inc_table}
             WHERE DATE_FORMAT(income_date,'%%Y-%%m') = %s GROUP BY category ORDER BY total DESC", $month
        ));
        // Expense by category
        $exp_by_cat = $wpdb->get_results($wpdb->prepare(
            "SELECT category, SUM(amount) as total FROM {$this->exp_table}
             WHERE DATE_FORMAT(expense_date,'%%Y-%%m') = %s GROUP BY category ORDER BY total DESC", $month
        ));
        ?>
        <div style="display:flex;gap:30px;flex-wrap:wrap;">
            <!-- Income breakdown -->
            <div style="flex:1;min-width:280px;">
                <h3 style="color:#3d6318;margin-top:0;">💰 Income Breakdown</h3>
                <table class="wp-list-table widefat fixed" style="font-size:13px;">
                    <thead><tr><th>Category</th><th style="text-align:right;">Amount (KES)</th><th style="text-align:right;">%</th></tr></thead>
                    <tbody>
                    <?php if($fee_total > 0): ?>
                    <tr style="background:#f0f7ff;">
                        <td><strong>Student Fee Payments</strong><br><small style="color:#777;"><?php echo $fee_count; ?> payments via Payments module</small></td>
                        <td style="text-align:right;font-weight:600;"><?php echo number_format($fee_total,2); ?></td>
                        <td style="text-align:right;color:#0073aa;"><?php echo $inc_total>0?round($fee_total/$inc_total*100,1):0; ?>%</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach($inc_by_cat as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->category); ?></td>
                        <td style="text-align:right;font-weight:600;"><?php echo number_format($r->total,2); ?></td>
                        <td style="text-align:right;color:#777;"><?php echo $inc_total>0?round($r->total/$inc_total*100,1):0; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($inc_by_cat) && $fee_total==0): ?>
                    <tr><td colspan="3" style="color:#777;text-align:center;padding:15px;">No income this month</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot><tr>
                        <td style="font-weight:700;">TOTAL</td>
                        <td style="text-align:right;font-weight:700;color:#3d6318;">KES <?php echo number_format($inc_total,2); ?></td>
                        <td style="text-align:right;">100%</td>
                    </tr></tfoot>
                </table>
            </div>

            <!-- Expense breakdown -->
            <div style="flex:1;min-width:280px;">
                <h3 style="color:#d63638;margin-top:0;">💸 Expense Breakdown</h3>
                <table class="wp-list-table widefat fixed" style="font-size:13px;">
                    <thead><tr><th>Category</th><th style="text-align:right;">Amount (KES)</th><th style="text-align:right;">%</th></tr></thead>
                    <tbody>
                    <?php if(empty($exp_by_cat)): ?>
                    <tr><td colspan="3" style="color:#777;text-align:center;padding:15px;">No expenses this month</td></tr>
                    <?php else: foreach($exp_by_cat as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->category); ?></td>
                        <td style="text-align:right;font-weight:600;"><?php echo number_format($r->total,2); ?></td>
                        <td style="text-align:right;color:#777;"><?php echo $exp_total>0?round($r->total/$exp_total*100,1):0; ?>%</td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot><tr>
                        <td style="font-weight:700;">TOTAL</td>
                        <td style="text-align:right;font-weight:700;color:#d63638;">KES <?php echo number_format($exp_total,2); ?></td>
                        <td style="text-align:right;">100%</td>
                    </tr></tfoot>
                </table>
            </div>
        </div>

        <!-- Net summary box -->
        <div style="margin-top:25px;background:<?php echo $profit>=0?'#f0faf0':'#fff5f5'; ?>;border:1px solid <?php echo $pcolor; ?>;border-radius:6px;padding:20px;max-width:400px;">
            <table style="width:100%;font-size:14px;">
                <tr><td>Total Income</td><td style="text-align:right;color:#3d6318;font-weight:600;">KES <?php echo number_format($inc_total,2); ?></td></tr>
                <tr><td>Total Expenses</td><td style="text-align:right;color:#d63638;font-weight:600;">KES <?php echo number_format($exp_total,2); ?></td></tr>
                <tr style="border-top:2px solid <?php echo $pcolor; ?>;"><td style="padding-top:8px;font-weight:700;font-size:16px;"><?php echo $plabel; ?></td>
                <td style="text-align:right;font-weight:700;font-size:18px;color:<?php echo $pcolor; ?>;padding-top:8px;">KES <?php echo number_format(abs($profit),2); ?></td></tr>
            </table>
        </div>

        <?php elseif ( $tab === 'income' ): ?>
        <!-- ═══════════════════════════════ INCOME TAB ════════════════════════════════ -->
        <div style="margin-bottom:15px;">
            <button class="button button-primary" onclick="var f=document.getElementById('inc-form');f.style.display=f.style.display==='none'?'block':'none'">+ Add Income</button>
        </div>

        <!-- Income form -->
        <div id="inc-form" style="display:<?php echo $edit_income?'block':'none'; ?>;background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;margin-bottom:20px;">
            <h3 style="margin-top:0;"><?php echo $edit_income?'✏️ Edit Income':'➕ Record Income'; ?></h3>
            <form method="POST">
                <?php wp_nonce_field('mtti_finance_action','mtti_finance_nonce'); ?>
                <input type="hidden" name="action_type" value="<?php echo $edit_income?'edit_income':'add_income'; ?>">
                <?php if($edit_income): ?><input type="hidden" name="entry_id" value="<?php echo $edit_income->income_id; ?>"><?php endif; ?>
                <table class="form-table" style="max-width:680px;">
                    <tr><th>Date *</th><td><input type="date" name="income_date" required value="<?php echo esc_attr($edit_income->income_date??date('Y-m-d')); ?>" class="regular-text"></td></tr>
                    <tr><th>Category *</th><td><select name="category" required><?php foreach($inc_cats as $c): ?><option value="<?php echo $c; ?>" <?php selected($edit_income->category??'',$c); ?>><?php echo $c; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Description *</th><td><input type="text" name="description" required value="<?php echo esc_attr($edit_income->description??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Amount (KES) *</th><td><input type="number" name="amount" required step="0.01" min="0" value="<?php echo esc_attr($edit_income->amount??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Received From</th><td><input type="text" name="received_from" value="<?php echo esc_attr($edit_income->received_from??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Method</th><td><select name="payment_method"><?php foreach($methods as $m): ?><option value="<?php echo $m; ?>" <?php selected($edit_income->payment_method??'Cash',$m); ?>><?php echo $m; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Reference</th><td><input type="text" name="reference" value="<?php echo esc_attr($edit_income->reference??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="2" class="regular-text"><?php echo esc_textarea($edit_income->notes??''); ?></textarea></td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php echo $edit_income?'Update':'Save'; ?></button> <a href="<?php echo $base_url; ?>&tab=income" class="button">Cancel</a></p>
            </form>
        </div>

        <?php
        $incomes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->inc_table} WHERE DATE_FORMAT(income_date,'%%Y-%%m')=%s ORDER BY income_date DESC", $month
        ));
        ?>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead><tr>
                <th width="90">Date</th><th width="130">Category</th><th>Description</th>
                <th width="120">Received From</th><th width="90">Method</th>
                <th width="100">Ref</th><th width="120" style="text-align:right;">Amount (KES)</th>
                <th width="100">Actions</th>
            </tr></thead>
            <tbody>
            <?php if(empty($incomes)): ?>
                <tr><td colspan="8" style="text-align:center;color:#777;padding:20px;">No manual income entries. Student fees are auto-included in P&L from the Payments module.</td></tr>
            <?php else: foreach($incomes as $r): ?>
                <tr>
                    <td><?php echo esc_html($r->income_date); ?></td>
                    <td><span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:3px;font-size:11px;"><?php echo esc_html($r->category); ?></span></td>
                    <td><?php echo esc_html($r->description); ?></td>
                    <td><?php echo esc_html($r->received_from); ?></td>
                    <td><?php echo esc_html($r->payment_method); ?></td>
                    <td><?php echo esc_html($r->reference); ?></td>
                    <td style="text-align:right;font-weight:600;color:#3d6318;"><?php echo number_format($r->amount,2); ?></td>
                    <td>
                        <a href="<?php echo $base_url; ?>&tab=income&edit_income=<?php echo $r->income_id; ?>" class="button button-small">Edit</a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?')">
                            <?php wp_nonce_field('mtti_finance_action','mtti_finance_nonce'); ?>
                            <input type="hidden" name="action_type" value="delete_income">
                            <input type="hidden" name="entry_id" value="<?php echo $r->income_id; ?>">
                            <button class="button button-small" style="color:#d63638;">Del</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($incomes)): ?>
            <tfoot><tr>
                <td colspan="6" style="text-align:right;font-weight:700;">SUBTOTAL (manual):</td>
                <td style="text-align:right;font-weight:700;color:#3d6318;">KES <?php echo number_format($inc_manual,2); ?></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ( $tab === 'expenses' ): ?>
        <!-- ═══════════════════════════════ EXPENSES TAB ══════════════════════════════ -->
        <div style="margin-bottom:15px;">
            <button class="button button-primary" onclick="var f=document.getElementById('exp-form');f.style.display=f.style.display==='none'?'block':'none'">+ Add Expense</button>
        </div>

        <!-- Expense form -->
        <div id="exp-form" style="display:<?php echo $edit_expense?'block':'none'; ?>;background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;margin-bottom:20px;">
            <h3 style="margin-top:0;"><?php echo $edit_expense?'✏️ Edit Expense':'➕ Record Expense'; ?></h3>
            <form method="POST">
                <?php wp_nonce_field('mtti_finance_action','mtti_finance_nonce'); ?>
                <input type="hidden" name="action_type" value="<?php echo $edit_expense?'edit_expense':'add_expense'; ?>">
                <?php if($edit_expense): ?><input type="hidden" name="entry_id" value="<?php echo $edit_expense->expense_id; ?>"><?php endif; ?>
                <table class="form-table" style="max-width:680px;">
                    <tr><th>Date *</th><td><input type="date" name="expense_date" required value="<?php echo esc_attr($edit_expense->expense_date??date('Y-m-d')); ?>" class="regular-text"></td></tr>
                    <tr><th>Category *</th><td><select name="category" required><?php foreach($exp_cats as $c): ?><option value="<?php echo $c; ?>" <?php selected($edit_expense->category??'',$c); ?>><?php echo $c; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Description *</th><td><input type="text" name="description" required value="<?php echo esc_attr($edit_expense->description??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Amount (KES) *</th><td><input type="number" name="amount" required step="0.01" min="0" value="<?php echo esc_attr($edit_expense->amount??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Paid To</th><td><input type="text" name="paid_to" value="<?php echo esc_attr($edit_expense->paid_to??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Method</th><td><select name="payment_method"><?php foreach($methods as $m): ?><option value="<?php echo $m; ?>" <?php selected($edit_expense->payment_method??'Cash',$m); ?>><?php echo $m; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Reference</th><td><input type="text" name="reference" value="<?php echo esc_attr($edit_expense->reference??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="2" class="regular-text"><?php echo esc_textarea($edit_expense->notes??''); ?></textarea></td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php echo $edit_expense?'Update':'Save'; ?></button> <a href="<?php echo $base_url; ?>&tab=expenses" class="button">Cancel</a></p>
            </form>
        </div>

        <?php
        $expenses = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->exp_table} WHERE DATE_FORMAT(expense_date,'%%Y-%%m')=%s ORDER BY expense_date DESC", $month
        ));
        ?>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead><tr>
                <th width="90">Date</th><th width="110">Category</th><th>Description</th>
                <th width="120">Paid To</th><th width="90">Method</th>
                <th width="100">Ref</th><th width="120" style="text-align:right;">Amount (KES)</th>
                <th width="100">Actions</th>
            </tr></thead>
            <tbody>
            <?php if(empty($expenses)): ?>
                <tr><td colspan="8" style="text-align:center;color:#777;padding:20px;">No expenses this month.</td></tr>
            <?php else: foreach($expenses as $r): ?>
                <tr>
                    <td><?php echo esc_html($r->expense_date); ?></td>
                    <td><span style="background:#fdecea;color:#c62828;padding:2px 8px;border-radius:3px;font-size:11px;"><?php echo esc_html($r->category); ?></span></td>
                    <td><?php echo esc_html($r->description); ?></td>
                    <td><?php echo esc_html($r->paid_to); ?></td>
                    <td><?php echo esc_html($r->payment_method); ?></td>
                    <td><?php echo esc_html($r->reference); ?></td>
                    <td style="text-align:right;font-weight:600;color:#d63638;"><?php echo number_format($r->amount,2); ?></td>
                    <td>
                        <a href="<?php echo $base_url; ?>&tab=expenses&edit_expense=<?php echo $r->expense_id; ?>" class="button button-small">Edit</a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?')">
                            <?php wp_nonce_field('mtti_finance_action','mtti_finance_nonce'); ?>
                            <input type="hidden" name="action_type" value="delete_expense">
                            <input type="hidden" name="entry_id" value="<?php echo $r->expense_id; ?>">
                            <button class="button button-small" style="color:#d63638;">Del</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($expenses)): ?>
            <tfoot><tr>
                <td colspan="6" style="text-align:right;font-weight:700;">TOTAL:</td>
                <td style="text-align:right;font-weight:700;color:#d63638;">KES <?php echo number_format($exp_total,2); ?></td>
                <td></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>

        <?php endif; ?>
        </div>
        <?php
    }
}
