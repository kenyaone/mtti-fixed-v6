<?php
/**
 * Fired during plugin deactivation
 */
class MTTI_MIS_Deactivator {

    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Note: We don't delete data on deactivation
        // Data is only removed if user explicitly deletes the plugin
    }
}
