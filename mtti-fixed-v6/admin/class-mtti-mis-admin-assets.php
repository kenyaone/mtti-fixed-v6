<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MTTI_MIS_Admin_Assets {

    private $plugin_name;
    private $version;
    private $table;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        global $wpdb;
        $this->table = $wpdb->prefix . 'mtti_assets';
    }

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'mtti_assets';
        $charset = $wpdb->get_charset_collate();
        if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table ) {
            $sql = "CREATE TABLE $table (
                asset_id         BIGINT NOT NULL AUTO_INCREMENT,
                asset_code       VARCHAR(50) NOT NULL,
                name             VARCHAR(200) NOT NULL,
                category         VARCHAR(100) NOT NULL,
                description      TEXT DEFAULT NULL,
                serial_number    VARCHAR(100) DEFAULT NULL,
                purchase_date    DATE DEFAULT NULL,
                purchase_price   DECIMAL(10,2) DEFAULT 0.00,
                supplier         VARCHAR(200) DEFAULT NULL,
                location         VARCHAR(200) DEFAULT NULL,
                assigned_to      VARCHAR(200) DEFAULT NULL,
                condition_status VARCHAR(50) NOT NULL DEFAULT 'Good',
                warranty_expiry  DATE DEFAULT NULL,
                notes            TEXT DEFAULT NULL,
                photo            VARCHAR(300) DEFAULT NULL,
                recorded_by      BIGINT UNSIGNED DEFAULT NULL,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (asset_id),
                UNIQUE KEY asset_code (asset_code),
                KEY category (category),
                KEY condition_status (condition_status)
            ) $charset;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }
    }

    private function generate_code( $category ) {
        global $wpdb;
        $prefix = strtoupper( substr( preg_replace('/[^a-zA-Z]/','', $category), 0, 3 ) );
        $count  = intval( $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}") ) + 1;
        return $prefix . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function handle_actions() {
        global $wpdb;
        if ( ! isset( $_POST['mtti_asset_nonce'] ) ||
             ! wp_verify_nonce( $_POST['mtti_asset_nonce'], 'mtti_asset_action' ) ) return;

        $action = sanitize_text_field( $_POST['action_type'] ?? '' );

        if ( $action === 'add' || $action === 'edit' ) {
            $data = [
                'name'             => sanitize_text_field( $_POST['name'] ),
                'category'         => sanitize_text_field( $_POST['category'] ),
                'description'      => sanitize_textarea_field( $_POST['description'] ),
                'serial_number'    => sanitize_text_field( $_POST['serial_number'] ),
                'purchase_date'    => sanitize_text_field( $_POST['purchase_date'] ) ?: null,
                'purchase_price'   => floatval( $_POST['purchase_price'] ),
                'supplier'         => sanitize_text_field( $_POST['supplier'] ),
                'location'         => sanitize_text_field( $_POST['location'] ),
                'assigned_to'      => sanitize_text_field( $_POST['assigned_to'] ),
                'condition_status' => sanitize_text_field( $_POST['condition_status'] ),
                'warranty_expiry'  => sanitize_text_field( $_POST['warranty_expiry'] ) ?: null,
                'notes'            => sanitize_textarea_field( $_POST['notes'] ),
                'recorded_by'      => get_current_user_id(),
            ];
            if ( $action === 'add' ) {
                $data['asset_code'] = $this->generate_code( $data['category'] );
                $wpdb->insert( $this->table, $data );
                echo '<div class="notice notice-success"><p>✅ Asset added. Code: <strong>' . esc_html($data['asset_code']) . '</strong></p></div>';
            } else {
                $wpdb->update( $this->table, $data, [ 'asset_id' => intval($_POST['asset_id']) ] );
                echo '<div class="notice notice-success"><p>✅ Asset updated.</p></div>';
            }
        }
        if ( $action === 'delete' ) {
            $wpdb->delete( $this->table, [ 'asset_id' => intval($_POST['asset_id']) ] );
            echo '<div class="notice notice-warning"><p>🗑️ Asset deleted.</p></div>';
        }
    }

    private function condition_color( $c ) {
        $map = [
            'Good'         => '#3d6318',
            'Fair'         => '#FF9700',
            'Poor'         => '#d63638',
            'Under Repair' => '#9b59b6',
            'Disposed'     => '#888',
        ];
        return $map[$c] ?? '#555';
    }

    public function display() {
        self::create_table();
        $this->handle_actions();
        global $wpdb;

        $where = 'WHERE 1=1';
        if ( ! empty( $_GET['cat'] ) )  $where .= $wpdb->prepare(' AND category = %s', $_GET['cat']);
        if ( ! empty( $_GET['cond'] ) ) $where .= $wpdb->prepare(' AND condition_status = %s', $_GET['cond']);
        if ( ! empty( $_GET['s'] ) )    $where .= $wpdb->prepare(' AND (name LIKE %s OR asset_code LIKE %s)', '%'.$_GET['s'].'%', '%'.$_GET['s'].'%');

        $assets    = $wpdb->get_results("SELECT * FROM {$this->table} $where ORDER BY category, name");
        $total_val = $wpdb->get_var("SELECT SUM(purchase_price) FROM {$this->table} $where");
        $total_all = $wpdb->get_var("SELECT SUM(purchase_price) FROM {$this->table}");
        $total_cnt = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $cats      = $wpdb->get_col("SELECT DISTINCT category FROM {$this->table} ORDER BY category");

        $edit = null;
        if ( ! empty( $_GET['edit_id'] ) )
            $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE asset_id = %d", intval($_GET['edit_id'])));

        $categories = ['Furniture','Electronics','Computers','Lab Equipment','Kitchen','Vehicle','Books','Tools','Medical','Other'];
        $conditions = ['Good','Fair','Poor','Under Repair','Disposed'];
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            🏢 Asset Register
            <button class="button button-primary" onclick="var f=document.getElementById('mtti-asset-form');f.style.display=f.style.display==='none'?'block':'none'">+ Add Asset</button>
        </h1>

        <div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;">
            <div style="background:#fff;border-left:4px solid #3d6318;padding:15px 25px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:160px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Total Assets</div>
                <div style="font-size:26px;font-weight:700;color:#3d6318;"><?php echo $total_cnt; ?></div>
            </div>
            <div style="background:#fff;border-left:4px solid #FF9700;padding:15px 25px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:220px;">
                <div style="font-size:11px;color:#777;text-transform:uppercase;">Total Value (All)</div>
                <div style="font-size:26px;font-weight:700;color:#FF9700;">KES <?php echo number_format(floatval($total_all),2); ?></div>
            </div>
        </div>

        <form method="GET" style="margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="page" value="mtti-mis-assets">
            <input type="text" name="s" placeholder="Search name / code…" value="<?php echo esc_attr($_GET['s']??''); ?>" style="height:34px;padding:0 8px;width:200px;">
            <select name="cat" style="height:34px;">
                <option value="">All Categories</option>
                <?php foreach($cats as $c): ?><option value="<?php echo esc_attr($c); ?>" <?php selected($_GET['cat']??'',$c); ?>><?php echo esc_html($c); ?></option><?php endforeach; ?>
            </select>
            <select name="cond" style="height:34px;">
                <option value="">All Conditions</option>
                <?php foreach($conditions as $c): ?><option value="<?php echo $c; ?>" <?php selected($_GET['cond']??'',$c); ?>><?php echo $c; ?></option><?php endforeach; ?>
            </select>
            <button class="button">Filter</button>
            <a href="?page=mtti-mis-assets" class="button">Clear</a>
        </form>

        <div id="mtti-asset-form" style="display:<?php echo $edit?'block':'none'; ?>;background:#fff;padding:20px;border:1px solid #ddd;border-radius:4px;margin-bottom:20px;">
            <h2><?php echo $edit?'✏️ Edit Asset':'➕ New Asset'; ?></h2>
            <form method="POST">
                <?php wp_nonce_field('mtti_asset_action','mtti_asset_nonce'); ?>
                <input type="hidden" name="action_type" value="<?php echo $edit?'edit':'add'; ?>">
                <?php if($edit): ?><input type="hidden" name="asset_id" value="<?php echo $edit->asset_id; ?>"><?php endif; ?>
                <table class="form-table" style="max-width:700px;">
                    <tr><th>Name *</th><td><input type="text" name="name" required value="<?php echo esc_attr($edit->name??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Category *</th><td><select name="category" required><?php foreach($categories as $c): ?><option value="<?php echo $c; ?>" <?php selected($edit->category??'',$c); ?>><?php echo $c; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Description</th><td><textarea name="description" rows="2" class="regular-text"><?php echo esc_textarea($edit->description??''); ?></textarea></td></tr>
                    <tr><th>Serial Number</th><td><input type="text" name="serial_number" value="<?php echo esc_attr($edit->serial_number??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Purchase Date</th><td><input type="date" name="purchase_date" value="<?php echo esc_attr($edit->purchase_date??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Purchase Price (KES)</th><td><input type="number" name="purchase_price" step="0.01" min="0" value="<?php echo esc_attr($edit->purchase_price??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Supplier</th><td><input type="text" name="supplier" value="<?php echo esc_attr($edit->supplier??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Location</th><td><input type="text" name="location" placeholder="e.g. ICT Lab, Room 201" value="<?php echo esc_attr($edit->location??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Assigned To</th><td><input type="text" name="assigned_to" value="<?php echo esc_attr($edit->assigned_to??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Condition *</th><td><select name="condition_status" required><?php foreach($conditions as $c): ?><option value="<?php echo $c; ?>" <?php selected($edit->condition_status??'Good',$c); ?>><?php echo $c; ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Warranty Expiry</th><td><input type="date" name="warranty_expiry" value="<?php echo esc_attr($edit->warranty_expiry??''); ?>" class="regular-text"></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="2" class="regular-text"><?php echo esc_textarea($edit->notes??''); ?></textarea></td></tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php echo $edit?'Update Asset':'Save Asset'; ?></button> <a href="?page=mtti-mis-assets" class="button">Cancel</a></p>
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead><tr>
                <th width="90">Code</th><th>Name</th><th width="110">Category</th>
                <th width="100">Condition</th><th width="120">Location</th>
                <th width="130">Assigned To</th>
                <th width="120" style="text-align:right;">Value (KES)</th><th width="100">Actions</th>
            </tr></thead>
            <tbody>
            <?php if(empty($assets)): ?>
                <tr><td colspan="8" style="text-align:center;color:#777;padding:30px;">No assets found.</td></tr>
            <?php else: foreach($assets as $a): ?>
                <tr>
                    <td><code style="font-size:11px;"><?php echo esc_html($a->asset_code); ?></code></td>
                    <td><strong><?php echo esc_html($a->name); ?></strong><?php if($a->serial_number): ?><br><small style="color:#777;">S/N: <?php echo esc_html($a->serial_number); ?></small><?php endif; ?></td>
                    <td><?php echo esc_html($a->category); ?></td>
                    <td><span style="background:<?php echo $this->condition_color($a->condition_status); ?>;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;"><?php echo esc_html($a->condition_status); ?></span></td>
                    <td><?php echo esc_html($a->location); ?></td>
                    <td><?php echo esc_html($a->assigned_to); ?></td>
                    <td style="text-align:right;font-weight:600;"><?php echo number_format($a->purchase_price,2); ?></td>
                    <td>
                        <a href="?page=mtti-mis-assets&edit_id=<?php echo $a->asset_id; ?>" class="button button-small">Edit</a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this asset?')">
                            <?php wp_nonce_field('mtti_asset_action','mtti_asset_nonce'); ?>
                            <input type="hidden" name="action_type" value="delete">
                            <input type="hidden" name="asset_id" value="<?php echo $a->asset_id; ?>">
                            <button type="submit" class="button button-small" style="color:#d63638;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="6" style="text-align:right;font-weight:700;padding:10px;">TOTAL VALUE:</td>
                <td style="text-align:right;font-weight:700;font-size:15px;color:#FF9700;">KES <?php echo number_format(floatval($total_val),2); ?></td>
                <td></td>
            </tr></tfoot>
        </table>
        </div>
        <?php
    }
}
