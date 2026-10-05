<?php
/**
 * Plugin Name: Work Plan Manager
 * Description: A plugin to manage Work Plans, Goals, and Objectives with a streamlined interface
 * Version: 1.5.2
 * Author: KC Web Programmers
 * Text Domain: work-plan-manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WPM_VERSION', '1.5.2');

class WorkPlanManager {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_save_workplan', array($this, 'ajax_save_workplan'));
        add_action('wp_ajax_save_goal', array($this, 'ajax_save_goal'));
        add_action('wp_ajax_save_objective', array($this, 'ajax_save_objective'));
        add_action('wp_ajax_get_workplan_data', array($this, 'ajax_get_workplan_data'));
        add_action('wp_ajax_delete_goal', array($this, 'ajax_delete_goal'));
        add_action('wp_ajax_delete_objective', array($this, 'ajax_delete_objective'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Include required files
        $this->include_files();
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        require_once WPM_PLUGIN_PATH . 'includes/installation.php';
        require_once WPM_PLUGIN_PATH . 'includes/utility-functions.php';
        require_once WPM_PLUGIN_PATH . 'includes/export-handler.php';
        
        // Optional: Include ACF field behaviors for enhanced validation and automation
        // Uncomment the line below if you want additional ACF field enhancements
        // require_once WPM_PLUGIN_PATH . 'includes/acf-behaviors.php';
    }
    
    /**
     * Helper to get ACF field value whether it returns array or string
     */
    private function get_field_value($field_name, $post_id) {
        $value = get_field($field_name, $post_id);
        
        // If it's an array (ACF select field returning both value and label)
        if (is_array($value)) {
            return isset($value['value']) ? $value['value'] : '';
        }
        
        // If it's already a string, return as is
        return $value ?: '';
    }

    public function init() {
        $this->setup_capabilities();
    }
    
    public function setup_capabilities() {
        // Add all capabilities to administrator role (full access)
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_capabilities = array(
                'manage_workplans',
                'edit_workplans',
                'edit_others_workplans',  // This is the key capability that grants admin-level access
                'publish_workplans',
                'read_private_workplans',
                'delete_workplans',
                'delete_private_workplans',
                'delete_published_workplans',
                'delete_others_workplans',
                'edit_private_workplans',
                'edit_published_workplans'
            );
            
            foreach ($admin_capabilities as $cap) {
                $admin_role->add_cap($cap);
            }
        }
        
        // Handle ONLY the custom editor roles that are actually used
        // These should have limited capabilities (group-restricted access)
        $custom_editor_roles = array(
            'central_east_editor',
            'great_lakes_editor', 
            'mid_america_editor',
            'new_england_editor',
            'northeast_caribbean_editor',
            'southeast_editor',
            'mountain_plains_editor',
            'northwest_editor',
            'pacific_southwest_editor',
            'south_southwest_editor'
        );
        
        foreach ($custom_editor_roles as $role_name) {
            $custom_role = get_role($role_name);
            if ($custom_role) {
                // Add limited capabilities (NO edit_others_workplans)
                $limited_caps = array(
                    'manage_workplans',     // Can access the plugin interface
                    'edit_workplans',       // Can create/edit workplans in their groups
                    'publish_workplans',    // Can publish workplans
                    'delete_workplans'      // Can delete goals/objectives in their workplans
                );
                
                foreach ($limited_caps as $cap) {
                    $custom_role->add_cap($cap);
                }
                
                // Explicitly remove admin capability if it exists
                $custom_role->remove_cap('edit_others_workplans');
                
                // Remove any other admin-level capabilities that shouldn't be there
                $admin_only_caps = array(
                    'read_private_workplans',
                    'delete_private_workplans', 
                    'delete_published_workplans',
                    'delete_others_workplans',
                    'edit_private_workplans',
                    'edit_published_workplans'
                );
                
                foreach ($admin_only_caps as $cap) {
                    $custom_role->remove_cap($cap);
                }
                
                // Debug logging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WPM Debug] Set up limited capabilities for role: ' . $role_name);
                }
            }
        }
        
        // Also clean up the built-in 'editor' role in case it was modified
        $editor_role = get_role('editor');
        if ($editor_role) {
            // Remove all workplan capabilities from the built-in editor role
            $all_workplan_caps = array(
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
                'edit_published_workplans'
            );
            
            foreach ($all_workplan_caps as $cap) {
                $editor_role->remove_cap($cap);
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WPM Debug] Cleaned up built-in editor role - removed all workplan capabilities');
            }
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('Work Plan Manager', 'work-plan-manager'),
            __('Work Plan Manager', 'work-plan-manager'),
            'manage_workplans',
            'work-plan-manager',
            array($this, 'admin_page'),
            'dashicons-list-view',
            30
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_work-plan-manager') {
            return;
        }
        
        // Add error logging for debugging
        wp_enqueue_script(
            'work-plan-manager-js',
            WPM_PLUGIN_URL . 'js/work-plan-manager.js',
            array('wp-element', 'wp-components', 'wp-api-fetch'),
            WPM_VERSION,
            true
        );
        
        wp_enqueue_style(
            'work-plan-manager-css',
            WPM_PLUGIN_URL . 'css/work-plan-manager.css',
            array(),
            WPM_VERSION
        );
        
        wp_localize_script('work-plan-manager-js', 'wpm_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpm_nonce'),
            'current_user_id' => get_current_user_id(),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
        
        // Add inline script to catch and log JavaScript errors
        wp_add_inline_script('work-plan-manager-js', '
            window.addEventListener("error", function(e) {
                if (window.wpm_ajax && window.wpm_ajax.debug) {
                    console.log("WPM JavaScript Error:", e.error, e.filename, e.lineno);
                }
            });
            
            window.addEventListener("unhandledrejection", function(e) {
                if (window.wpm_ajax && window.wpm_ajax.debug) {
                    console.log("WPM Promise Rejection:", e.reason);
                }
            });
        ', 'before');
    }
    
    public function admin_page() {
        if (!current_user_can('manage_workplans')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        include WPM_PLUGIN_PATH . 'includes/admin-page.php';
    }
    
    // AJAX Handlers
    public function ajax_save_workplan() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('edit_workplans')) {
            wp_send_json_error('Insufficient permissions to edit workplans');
        }
        
        $workplan_id = intval($_POST['workplan_id']);
        $title = sanitize_text_field($_POST['title']);
        $grant_year = sanitize_text_field($_POST['grant_year']);
        $group = sanitize_text_field($_POST['group']);
        $internal_status = sanitize_text_field($_POST['internal_status']);
        $fiscal_year = sanitize_text_field($_POST['fiscal_year']);
        
        // Additional permission check for existing workplans
        if ($workplan_id > 0 && !wpm_user_can_edit_workplan($workplan_id)) {
            wp_send_json_error('Insufficient permissions to edit this workplan');
        }
        
        $post_data = array(
            'post_title' => $title,
            'post_type' => 'workplan',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        );
        
        if ($workplan_id > 0) {
            $post_data['ID'] = $workplan_id;
            $result = wp_update_post($post_data);
        } else {
            $result = wp_insert_post($post_data);
        }
        
        if ($result && !is_wp_error($result)) {
            // Set taxonomies - FIXED: Use term ID properly
            if (!empty($group)) {
                // Convert to integer to ensure we're using term ID, not name
                $group_term_id = intval($group);
                wp_set_object_terms($result, array($group_term_id), 'group', false);
                
                // Debug logging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WPM Debug] Set group taxonomy: term ID ' . $group_term_id . ' for workplan ' . $result);
                }
            } else {
                wp_set_object_terms($result, array(), 'group', false);
            }

            if (!empty($grant_year)) {
                // Grant year is saved as slug, so this is correct
                wp_set_object_terms($result, $grant_year, 'grant-year', false);
            } else {
                wp_set_object_terms($result, array(), 'grant-year', false);
            }
            
            // Set ACF fields
            if (function_exists('update_field')) {
                update_field('internal_status', $internal_status, $result);
                update_field('work_plan_calendar_year', $fiscal_year, $result);
            }
            
            wp_send_json_success(array('workplan_id' => $result));
        } else {
            wp_send_json_error('Failed to save work plan');
        }
    }
    
    public function ajax_save_goal() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('edit_workplans')) {
            wp_send_json_error('Insufficient permissions to edit workplans');
        }
        
        $goal_id = intval($_POST['goal_id']);
        $workplan_id = intval($_POST['workplan_id']);
        $title = sanitize_text_field($_POST['title']);
        $goal_letter = sanitize_text_field($_POST['goal_letter']);
        $goal_description = sanitize_textarea_field($_POST['goal_description']);
        
        // Check permission for parent workplan
        if (!wpm_user_can_edit_workplan($workplan_id)) {
            wp_send_json_error('Insufficient permissions to edit this workplan');
        }
        
        $post_data = array(
            'post_title' => $title,
            'post_type' => 'workplan-goal',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'post_parent' => $workplan_id,
        );
        
        if ($goal_id > 0) {
            $post_data['ID'] = $goal_id;
            $result = wp_update_post($post_data);
        } else {
            $result = wp_insert_post($post_data);
        }
        
        if ($result && !is_wp_error($result)) {
            // Copy group taxonomy from parent workplan - FIXED: Use term IDs properly
            $group_terms = wp_get_post_terms($workplan_id, 'group', array('fields' => 'ids'));
            if (!empty($group_terms)) {
                wp_set_object_terms($result, $group_terms, 'group', false);
                
                // Debug logging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WPM Debug] Copied group taxonomy IDs ' . implode(', ', $group_terms) . ' from workplan ' . $workplan_id . ' to goal ' . $result);
                }
            }
            
            // Set ACF fields
            if (function_exists('update_field')) {
                update_field('goal_letter', $goal_letter, $result);
                update_field('goal_description', $goal_description, $result);
                
                // Update the parent workplan's related_work_plan_goals field
                $existing_goals = get_field('related_work_plan_goals', $workplan_id) ?: array();
                if (!in_array($result, $existing_goals)) {
                    $existing_goals[] = $result;
                    update_field('related_work_plan_goals', $existing_goals, $workplan_id);
                }
            }
            
            wp_send_json_success(array('goal_id' => $result));
        } else {
            wp_send_json_error('Failed to save goal');
        }
    }
    
    public function ajax_save_objective() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('edit_workplans')) {
            wp_send_json_error('Insufficient permissions to edit workplans');
        }
        
        $objective_id = intval($_POST['objective_id']);
        $goal_id = intval($_POST['goal_id']);
        $workplan_id = intval($_POST['workplan_id']);
        $title = sanitize_text_field($_POST['title']);
        $objective_number = intval($_POST['objective_number']);
        $objective_description = sanitize_textarea_field($_POST['objective_description']);
        $timeline_description = sanitize_text_field($_POST['timeline_description']);
        //$measureable_outcomes = sanitize_textarea_field($_POST['measureable_outcomes']);
        $outputs = json_decode(stripslashes($_POST['outputs']), true);
        
        // Check permission for parent workplan
        if (!wpm_user_can_edit_workplan($workplan_id)) {
            wp_send_json_error('Insufficient permissions to edit this workplan');
        }
        
        $post_data = array(
            'post_title' => $title,
            'post_type' => 'workplan-objective',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'post_parent' => $goal_id,
        );
        
        if ($objective_id > 0) {
            $post_data['ID'] = $objective_id;
            $result = wp_update_post($post_data);
        } else {
            $result = wp_insert_post($post_data);
        }
        
        if ($result && !is_wp_error($result)) {
            // Copy group taxonomy from parent workplan - FIXED: Use term IDs properly
            $group_terms = wp_get_post_terms($workplan_id, 'group', array('fields' => 'ids'));
            if (!empty($group_terms)) {
                wp_set_object_terms($result, $group_terms, 'group', false);
                
                // Debug logging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WPM Debug] Copied group taxonomy IDs ' . implode(', ', $group_terms) . ' from workplan ' . $workplan_id . ' to objective ' . $result);
                }
            }
            
            // Set ACF fields
            if (function_exists('update_field')) {
                update_field('objective_number', $objective_number, $result);
                update_field('objective_description', $objective_description, $result);
                update_field('timeline_description', $timeline_description, $result);
                //update_field('measureable_outcomes', $measureable_outcomes, $result);
                update_field('objective_outputs', $outputs, $result);
                
                // Update the parent goal's work_plan_objectives field
                $existing_objectives = get_field('work_plan_objectives', $goal_id) ?: array();
                if (!in_array($result, $existing_objectives)) {
                    $existing_objectives[] = $result;
                    update_field('work_plan_objectives', $existing_objectives, $goal_id);
                }
            }
            
            wp_send_json_success(array('objective_id' => $result));
        } else {
            wp_send_json_error('Failed to save objective');
        }
    }
    
    /**
     * Reverse lookup: find all bimonthly report items that reference a given objective + output index.
     * Scans _brm_prior_items and _brm_ahead_items across all bimonthly posts.
     */
    private function get_bimonthly_references($objective_id, $output_index) {
        static $cache = null;

        // Build the cache once per request
        if ($cache === null) {
            $cache = array();
            $bimonthly_posts = get_posts(array(
                'post_type' => 'bimonthly',
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ));

            foreach ($bimonthly_posts as $bp) {
                foreach (array('_brm_prior_items', '_brm_ahead_items') as $meta_key) {
                    $items = get_post_meta($bp->ID, $meta_key, true);
                    if (empty($items)) continue;
                    $items = json_decode($items, true);
                    if (!is_array($items)) continue;

                    foreach ($items as $item) {
                        $post_id = isset($item['post_id']) ? intval($item['post_id']) : 0;
                        if (!$post_id) continue;

                        $p = get_post($post_id);
                        if (!$p) continue;

                        // Get date based on post type
                        $date = get_the_date('m/d/Y', $p->ID);
                        if ($p->post_type === 'event' && function_exists('get_field')) {
                            $raw = get_field('event_date_start', $p->ID);
                            if ($raw) $date = date('m/d/Y', strtotime($raw));
                        } elseif ($p->post_type === 'products_and_resourc' && function_exists('get_field')) {
                            $raw = get_field('publication_date', $p->ID);
                            if ($raw) $date = date('m/d/Y', strtotime($raw));
                        } elseif ($p->post_type === 'bimonthly-highlight' && function_exists('get_field')) {
                            $raw = get_field('highlight_date', $p->ID);
                            if ($raw) $date = date('m/d/Y', strtotime($raw));
                        }

                        // Get summary
                        $summary = '';
                        if ($p->post_type === 'bimonthly-highlight' && function_exists('get_field')) {
                            $summary = get_field('highlight_text', $p->ID) ?: '';
                        } else {
                            if (function_exists('get_field')) {
                                $summary = get_field('internal_reporting_summary', $p->ID) ?: '';
                            } else {
                                $summary = get_post_meta($p->ID, 'internal_reporting_summary', true) ?: '';
                            }
                        }

                        $pt_obj = get_post_type_object($p->post_type);
                        $pt_label = $pt_obj ? $pt_obj->labels->singular_name : $p->post_type;

                        $ref_data = array(
                            'post_title' => $p->post_title,
                            'post_type_label' => $pt_label,
                            'author' => get_the_author_meta('display_name', $p->post_author),
                            'date' => $date,
                            'summary' => $summary,
                        );

                        // Handle new multi-output format
                        $outputs = isset($item['outputs']) && is_array($item['outputs']) ? $item['outputs'] : array();

                        // Backwards compat: old flat format
                        if (empty($outputs) && !empty($item['objective_id'])) {
                            $outputs = array(array(
                                'objective_id' => $item['objective_id'],
                                'output_index' => isset($item['output_index']) ? $item['output_index'] : 0,
                            ));
                        }

                        foreach ($outputs as $out) {
                            $oid = intval($out['objective_id']);
                            $oidx = intval($out['output_index']);
                            $key = $oid . '_' . $oidx;
                            if (!isset($cache[$key])) $cache[$key] = array();
                            $cache[$key][] = $ref_data;
                        }
                    }
                }
            }
        }

        $key = intval($objective_id) . '_' . intval($output_index);
        return isset($cache[$key]) ? $cache[$key] : array();
    }

    public function ajax_get_workplan_data() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('edit_workplans')) {
            wp_send_json_error('Insufficient permissions to view workplans');
        }
        
        $workplan_id = intval($_POST['workplan_id']);
        $workplan = get_post($workplan_id);
        
        if (!$workplan) {
            wp_send_json_error('Work plan not found');
        }
        
        // Check if user can edit this specific workplan
        if (!wpm_user_can_edit_workplan($workplan_id)) {
            wp_send_json_error('Insufficient permissions to edit this workplan');
        }
        
        // Get workplan data
        $workplan_data = array(
            'id' => $workplan->ID,
            'title' => $workplan->post_title,
            'author' => get_the_author_meta('display_name', $workplan->post_author),
            'date' => $workplan->post_date,
            'internal_status' => $this->get_field_value('internal_status', $workplan->ID),
            'fiscal_year' => $this->get_field_value('work_plan_calendar_year', $workplan->ID),
            'group' => wp_get_post_terms($workplan->ID, 'group', array('fields' => 'names')),
            'grant_year' => wp_get_post_terms($workplan->ID, 'grant-year', array('fields' => 'names')),
        );
        
        // Get goals using the relationship field
        $goal_ids = get_field('related_work_plan_goals', $workplan_id) ?: array();
        
        $goals_data = array();
        foreach ($goal_ids as $goal_id) {
            $goal = get_post($goal_id);
            if (!$goal) continue;
            
            $goal_data = array(
                'id' => $goal->ID,
                'title' => $goal->post_title,
                'goal_letter' => get_field('goal_letter', $goal->ID),
                'goal_description' => get_field('goal_description', $goal->ID),
            );
            
            // Get objectives for this goal using the relationship field
            $objective_ids = get_field('work_plan_objectives', $goal->ID) ?: array();
            
            $objectives_data = array();
            foreach ($objective_ids as $objective_id) {
                $objective = get_post($objective_id);
                if (!$objective) continue;
                
                // Get outputs and enrich with bimonthly references
                $raw_outputs = get_field('objective_outputs', $objective->ID) ?: array();
                $enriched_outputs = array();
                foreach ($raw_outputs as $idx => $output) {
                    $output['bimonthly_refs'] = $this->get_bimonthly_references($objective->ID, $idx);
                    $enriched_outputs[] = $output;
                }

                $objectives_data[] = array(
                    'id' => $objective->ID,
                    'title' => $objective->post_title,
                    'objective_number' => get_field('objective_number', $objective->ID),
                    'objective_description' => get_field('objective_description', $objective->ID),
                    'timeline_description' => get_field('timeline_description', $objective->ID),
                    //'measureable_outcomes' => get_field('measureable_outcomes', $objective->ID),
                    'outputs' => $enriched_outputs,
                );
            }
            
            $goal_data['objectives'] = $objectives_data;
            $goals_data[] = $goal_data;
        }
        
        $workplan_data['goals'] = $goals_data;
        
        wp_send_json_success($workplan_data);
    }
    
    public function ajax_delete_goal() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('delete_workplans')) {
            wp_send_json_error('Insufficient permissions to delete goals');
        }
        
        $goal_id = intval($_POST['goal_id']);
        
        // Get the parent workplan to check permissions and update its relationship field
        $workplan_id = 0;
        $all_workplans = get_posts(array(
            'post_type' => 'workplan',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        
        foreach ($all_workplans as $workplan) {
            $related_goals = get_field('related_work_plan_goals', $workplan->ID) ?: array();
            if (in_array($goal_id, $related_goals)) {
                $workplan_id = $workplan->ID;
                break;
            }
        }
        
        // Check permission for parent workplan
        if ($workplan_id && !wpm_user_can_edit_workplan($workplan_id)) {
            wp_send_json_error('Insufficient permissions to edit this workplan');
        }
        
        // Delete associated objectives first and update goal's relationship field
        $objective_ids = get_field('work_plan_objectives', $goal_id) ?: array();
        foreach ($objective_ids as $objective_id) {
            wp_delete_post($objective_id, true);
        }
        
        // Update parent workplan's relationship field
        if ($workplan_id && function_exists('update_field')) {
            $related_goals = get_field('related_work_plan_goals', $workplan_id) ?: array();
            $related_goals = array_diff($related_goals, array($goal_id));
            update_field('related_work_plan_goals', array_values($related_goals), $workplan_id);
        }
        
        // Delete the goal
        $result = wp_delete_post($goal_id, true);
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete goal');
        }
    }
    
    public function ajax_delete_objective() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('delete_workplans')) {
            wp_send_json_error('Insufficient permissions to delete objectives');
        }
        
        $objective_id = intval($_POST['objective_id']);
        
        // Find the parent goal and workplan to check permissions and update relationship fields
        $goal_id = 0;
        $workplan_id = 0;
        
        $all_goals = get_posts(array(
            'post_type' => 'workplan-goal',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        
        foreach ($all_goals as $goal) {
            $related_objectives = get_field('work_plan_objectives', $goal->ID) ?: array();
            if (in_array($objective_id, $related_objectives)) {
                $goal_id = $goal->ID;
                break;
            }
        }
        
        // Find parent workplan through the goal
        if ($goal_id) {
            $all_workplans = get_posts(array(
                'post_type' => 'workplan',
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ));
            
            foreach ($all_workplans as $workplan) {
                $related_goals = get_field('related_work_plan_goals', $workplan->ID) ?: array();
                if (in_array($goal_id, $related_goals)) {
                    $workplan_id = $workplan->ID;
                    break;
                }
            }
        }
        
        // Check permission for parent workplan
        if ($workplan_id && !wpm_user_can_edit_workplan($workplan_id)) {
            wp_send_json_error('Insufficient permissions to edit this workplan');
        }
        
        // Update parent goal's relationship field
        if ($goal_id && function_exists('update_field')) {
            $related_objectives = get_field('work_plan_objectives', $goal_id) ?: array();
            $related_objectives = array_diff($related_objectives, array($objective_id));
            update_field('work_plan_objectives', array_values($related_objectives), $goal_id);
        }
        
        $result = wp_delete_post($objective_id, true);
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete objective');
        }
    }
    
    public function activate() {
        // Check basic requirements before activation
        if (!function_exists('get_field')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('Work Plan Manager requires Advanced Custom Fields (ACF) to be installed and activated.', 'work-plan-manager'),
                __('Plugin Activation Error', 'work-plan-manager'),
                array('back_link' => true)
            );
        }
        
        try {
            $this->setup_capabilities();
            $this->fix_existing_user_capabilities(); // Add this line
            flush_rewrite_rules();
            
            // Set activation flag
            update_option('wpm_activated', true);
            update_option('wpm_activation_time', current_time('timestamp'));
            
        } catch (Exception $e) {
            // Log the error if debug is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Work Plan Manager] Activation error: ' . $e->getMessage());
            }
            
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('Work Plan Manager could not be activated due to an error. Please check your error logs.', 'work-plan-manager'),
                __('Plugin Activation Error', 'work-plan-manager'),
                array('back_link' => true)
            );
        }
    }
    
    /**
     * Fix existing user capabilities that might be incorrectly assigned
     */
    public function fix_existing_user_capabilities() {
        // Get all users with any workplan-related capabilities
        $users = get_users(array(
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'wp_capabilities',
                    'value' => 'manage_workplans',
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => 'wp_capabilities',
                    'value' => 'edit_others_workplans', 
                    'compare' => 'LIKE'
                )
            )
        ));
        
        $custom_editor_roles = array(
            'central_east_editor',
            'great_lakes_editor', 
            'mid_america_editor',
            'new_england_editor',
            'northeast_caribbean_editor',
            'southeast_editor',
            'mountain_plains_editor',
            'northwest_editor',
            'pacific_southwest_editor',
            'south_southwest_editor'
        );
        
        foreach ($users as $user) {
            $user_roles = $user->roles;
            $is_admin = in_array('administrator', $user_roles);
            $is_custom_editor = !empty(array_intersect($user_roles, $custom_editor_roles));
            
            if ($is_admin) {
                // Administrators should have all capabilities - ensure they do
                $admin_caps = array(
                    'manage_workplans',
                    'edit_workplans', 
                    'edit_others_workplans',
                    'publish_workplans',
                    'delete_workplans'
                );
                
                foreach ($admin_caps as $cap) {
                    if (!$user->has_cap($cap)) {
                        $user->add_cap($cap);
                    }
                }
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WPM Debug] Ensured admin capabilities for user ' . $user->ID);
                }
                
            } elseif ($is_custom_editor) {
                // Custom editors should have limited capabilities only
                $allowed_caps = array(
                    'manage_workplans',
                    'edit_workplans',
                    'publish_workplans', 
                    'delete_workplans'
                );
                
                $prohibited_caps = array(
                    'edit_others_workplans',
                    'read_private_workplans',
                    'delete_private_workplans',
                    'delete_published_workplans',
                    'delete_others_workplans',
                    'edit_private_workplans',
                    'edit_published_workplans'
                );
                
                // Add allowed capabilities if missing
                foreach ($allowed_caps as $cap) {
                    if (!$user->has_cap($cap)) {
                        $user->add_cap($cap);
                    }
                }
                
                // Remove prohibited capabilities if present
                foreach ($prohibited_caps as $cap) {
                    if ($user->has_cap($cap)) {
                        $user->remove_cap($cap);
                        
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[WPM Debug] Removed ' . $cap . ' capability from user ' . $user->ID . ' (' . implode(', ', $user_roles) . ')');
                        }
                    }
                }
                
            } else {
                // Users with other roles shouldn't have any workplan capabilities
                $all_workplan_caps = array(
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
                    'edit_published_workplans'
                );
                
                foreach ($all_workplan_caps as $cap) {
                    if ($user->has_cap($cap)) {
                        $user->remove_cap($cap);
                        
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('[WPM Debug] Removed ' . $cap . ' capability from non-workplan user ' . $user->ID . ' (' . implode(', ', $user_roles) . ')');
                        }
                    }
                }
            }
        }
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    // Helper method to get user groups
    public function get_user_groups($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (function_exists('pp_get_groups_for_user')) {
            return pp_get_groups_for_user($user_id);
        }
        
        return array();
    }
    
    // Helper method to get accessible group terms
    public function get_accessible_groups($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Administrators can access all groups
        if (user_can($user_id, 'edit_others_workplans')) {
            return array(); // Empty array means no filtering - show all
        }
        
        $accessible_groups = array();
        
        // Get user roles
        $user = get_userdata($user_id);
        if ($user) {
            $user_roles = $user->roles;
            
            // Map roles to group term SLUGS (not names)
            $role_to_group_mapping = array(
                'central_east_editor' => 'central-east-attc',
                'great_lakes_editor' => 'great-lakes-attc',
                'mid_america_editor' => 'mid-america-attc',
                'new_england_editor' => 'new-england-attc',
                'northeast_caribbean_editor' => 'northeast-caribbean-attc',
                'southeast_editor' => 'southeast-attc',
                'mountain_plains_editor' => 'mountain-plains-attc',
                'northwest_editor' => 'northwest-attc',
                'pacific_southwest_editor' => 'pacific-southwest-attc',
                'south_southwest_editor' => 'south-southwest-attc',
            );
            
            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WPM Debug] User roles: ' . print_r($user_roles, true));
                error_log('[WPM Debug] Role mapping: ' . print_r($role_to_group_mapping, true));
            }
            
            foreach ($user_roles as $role) {
                if (isset($role_to_group_mapping[$role])) {
                    $accessible_groups[] = $role_to_group_mapping[$role];
                    
                    // Debug logging
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[WPM Debug] Mapped role "' . $role . '" to group "' . $role_to_group_mapping[$role] . '"');
                    }
                }
            }
        }
        
        // If using PublishPress Permissions, also check those groups
        if (function_exists('pp_get_groups_for_user') && empty($accessible_groups)) {
            $user_groups = pp_get_groups_for_user($user_id);
            
            if (!empty($user_groups)) {
                foreach ($user_groups as $user_group) {
                    // Try to get the slug from the group
                    if (!empty($user_group->slug)) {
                        $accessible_groups[] = $user_group->slug;
                    } elseif (!empty($user_group->metagroup_id) && isset($role_to_group_mapping[$user_group->metagroup_id])) {
                        $accessible_groups[] = $role_to_group_mapping[$user_group->metagroup_id];
                    }
                }
            }
        }
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WPM Debug] Final accessible groups for user ' . $user_id . ': ' . print_r($accessible_groups, true));
        }
        
        return array_unique($accessible_groups);
    }
}

// Initialize the plugin
new WorkPlanManager();