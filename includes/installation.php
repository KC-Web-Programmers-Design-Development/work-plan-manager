<?php
/**
 * Installation and Setup for Work Plan Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WPM_Installation {
    
    public static function activate() {
        // Set up default capabilities
        self::setup_capabilities();
        
        // Create export directory
        self::create_export_directory();
        
        // Schedule cleanup cron
        self::schedule_cleanup();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Set activation flag
        update_option('wpm_activated', true);
        update_option('wpm_version', WPM_VERSION);
        
        // Log installation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Work Plan Manager] Plugin activated successfully');
        }
    }
    
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('wpm_cleanup_exports');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Work Plan Manager] Plugin deactivated');
        }
    }
    
    public static function uninstall() {
        // Remove plugin data if user chooses to
        if (get_option('wpm_delete_data_on_uninstall', false)) {
            self::remove_plugin_data();
        }
    }
    
    private static function setup_capabilities() {
        $capabilities = array(
            'manage_workplans',
            'edit_workplans',
            'edit_others_workplans',
            'publish_workplans',
            'read_private_workplans',
            'delete_workplans',
            'delete_private_workplans',
            'delete_published_workplans',
            'delete_others_workplans',
            'edit_private_workplans',
            'edit_published_workplans',
        );
        
        // Add all capabilities to administrator role (full access)
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach ($capabilities as $cap) {
                $admin_role->add_cap($cap);
            }
        }
        
        // Add limited capabilities to editor role (group-restricted access)
        $editor_role = get_role('editor');
        if ($editor_role) {
            $editor_caps = array(
                'manage_workplans',     // Can access the plugin interface
                'edit_workplans',       // Can create/edit workplans
                'publish_workplans',    // Can publish workplans
                'delete_workplans'      // Can delete goals/objectives
                // Note: 'edit_others_workplans' NOT included - restricts to group access only
            );
            
            foreach ($editor_caps as $cap) {
                $editor_role->add_cap($cap);
            }
        }
    }
    
    private static function create_export_directory() {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/workplan-exports/';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
            
            // Create .htaccess file to prevent direct access
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<Files *.php>\n";
            $htaccess_content .= "deny from all\n";
            $htaccess_content .= "</Files>\n";
            
            file_put_contents($export_dir . '.htaccess', $htaccess_content);
            
            // Create index.php file
            file_put_contents($export_dir . 'index.php', '<?php // Silence is golden');
        }
    }
    
    private static function schedule_cleanup() {
        if (!wp_next_scheduled('wpm_cleanup_exports')) {
            wp_schedule_event(time(), 'hourly', 'wpm_cleanup_exports');
        }
    }
    
    private static function remove_plugin_data() {
        // Remove custom capabilities
        $roles = array('administrator', 'editor');
        $capabilities = array(
            'manage_workplans', 'edit_workplans', 'edit_others_workplans',
            'publish_workplans', 'read_private_workplans', 'delete_workplans',
            'delete_private_workplans', 'delete_published_workplans',
            'delete_others_workplans', 'edit_private_workplans', 'edit_published_workplans'
        );
        
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }
        
        // Remove plugin options
        delete_option('wpm_activated');
        delete_option('wpm_version');
        delete_option('wpm_delete_data_on_uninstall');
        
        // Remove export directory
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/workplan-exports/';
        if (file_exists($export_dir)) {
            self::remove_directory($export_dir);
        }
        
        // Clear scheduled events
        wp_clear_scheduled_hook('wpm_cleanup_exports');
    }
    
    private static function remove_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
    
    /**
     * Check if plugin requirements are met
     */
    public static function check_requirements() {
        $requirements = array();
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $requirements[] = sprintf(
                __('Work Plan Manager requires PHP 7.4 or higher. You are running PHP %s.', 'work-plan-manager'),
                PHP_VERSION
            );
        }
        
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            $requirements[] = sprintf(
                __('Work Plan Manager requires WordPress 5.0 or higher. You are running WordPress %s.', 'work-plan-manager'),
                get_bloginfo('version')
            );
        }
        
        // Check if ACF is active
        if (!function_exists('get_field')) {
            $requirements[] = __('Work Plan Manager requires Advanced Custom Fields (ACF) to be installed and activated.', 'work-plan-manager');
        }
        
        // Check if required post types exist
        if (!post_type_exists('workplan')) {
            $requirements[] = __('Work Plan Manager requires the "workplan" post type to be registered. Please ensure your ACF configuration includes this post type.', 'work-plan-manager');
        }
        
        if (!post_type_exists('workplan-goal')) {
            $requirements[] = __('Work Plan Manager requires the "workplan-goal" post type to be registered. Please ensure your ACF configuration includes this post type.', 'work-plan-manager');
        }
        
        if (!post_type_exists('workplan-objective')) {
            $requirements[] = __('Work Plan Manager requires the "workplan-objective" post type to be registered. Please ensure your ACF configuration includes this post type.', 'work-plan-manager');
        }
        
        // Check if required taxonomies exist
        if (!taxonomy_exists('group')) {
            $requirements[] = __('Work Plan Manager requires the "group" taxonomy to be registered. Please ensure your configuration includes this taxonomy.', 'work-plan-manager');
        }
        
        if (!taxonomy_exists('grant-year')) {
            $requirements[] = __('Work Plan Manager requires the "grant-year" taxonomy to be registered. Please ensure your configuration includes this taxonomy.', 'work-plan-manager');
        }
        
        return $requirements;
    }
    
    /**
     * Display admin notice for requirements
     */
    public static function requirements_notice() {
        $requirements = self::check_requirements();
        
        if (!empty($requirements)) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . __('Work Plan Manager:', 'work-plan-manager') . '</strong><br>';
            echo implode('<br>', $requirements);
            echo '</p></div>';
        }
    }
    
    /**
     * Run upgrade routines when plugin is updated
     */
    public static function upgrade() {
        $current_version = get_option('wmp_version', '0.0.0');
        
        if (version_compare($current_version, WPM_VERSION, '<')) {
            // Run upgrade routines here if needed
            
            // Update version
            update_option('wpm_version', WPM_VERSION);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[Work Plan Manager] Upgraded from {$current_version} to " . WPM_VERSION);
            }
        }
    }
}

// Hook into WordPress
register_activation_hook(WPM_PLUGIN_PATH . 'work-plan-manager.php', array('WPM_Installation', 'activate'));
register_deactivation_hook(WPM_PLUGIN_PATH . 'work-plan-manager.php', array('WPM_Installation', 'deactivate'));
register_uninstall_hook(WPM_PLUGIN_PATH . 'work-plan-manager.php', array('WPM_Installation', 'uninstall'));

// Check requirements on admin pages
add_action('admin_notices', array('WPM_Installation', 'requirements_notice'));

// Run upgrade check
add_action('admin_init', array('WPM_Installation', 'upgrade'));