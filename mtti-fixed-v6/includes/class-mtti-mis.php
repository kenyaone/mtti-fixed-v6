<?php
/**
 * The core plugin class.
 */
class MTTI_MIS {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        $this->version = MTTI_MIS_VERSION;
        $this->plugin_name = 'mtti-mis';

        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function load_dependencies() {
        // Core classes
        require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-loader.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'includes/class-mtti-mis-database.php';
        
        // Admin classes
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-students.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-courses.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-payments.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-enrollments.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-assignments.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-live-classes.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-certificates.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-units.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-notice-board.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'admin/class-mtti-mis-admin-scheme.php';
        
        // Public classes
        require_once MTTI_MIS_PLUGIN_DIR . 'public/class-mtti-mis-public.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'public/class-mtti-mis-shortcodes.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'public/class-mtti-mis-learner-portal.php';
        require_once MTTI_MIS_PLUGIN_DIR . 'public/class-mtti-mis-lecturer-portal.php';

        $this->loader = new MTTI_MIS_Loader();
    }

    private function define_admin_hooks() {
        $plugin_admin = new MTTI_MIS_Admin($this->get_plugin_name(), $this->get_version());
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');
        // Auto-repair stale fee balances on every admin load
        $db = new MTTI_MIS_Database();
        // repair_all_balances removed from auto-run — fee changes must not affect existing enrollments
    }

    private function define_public_hooks() {
        $plugin_public = new MTTI_MIS_Public($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
        
        // Register shortcodes
        $shortcodes = new MTTI_MIS_Shortcodes();
        $this->loader->add_action('init', $shortcodes, 'register_shortcodes');
    }

    public function run() {
        $this->loader->run();
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_loader() {
        return $this->loader;
    }

    public function get_version() {
        return $this->version;
    }
}
