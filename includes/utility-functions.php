<?php
/**
 * Utility Functions for Work Plan Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if ACF is active
 */
function wpm_is_acf_active() {
    return function_exists('get_field') && function_exists('acf_add_local_field_group');
}

/**
 * Check if PublishPress Permissions is active
 */
function wpm_is_publishpress_active() {
    return function_exists('pp_get_groups_for_user');
}

/**
 * Get filtered workplans for current user
 */
function wpm_get_user_workplans($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $args = array(
        'post_type' => 'workplan',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC'
    );
    
    // Administrators can see all workplans
    if (user_can($user_id, 'edit_others_workplans')) {
        return get_posts($args);
    }
    
    // For non-administrators, apply group filtering
    $wpm = new WorkPlanManager();
    $accessible_groups = $wpm->get_accessible_groups($user_id);
    
    if (!empty($accessible_groups)) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'group',
                'field' => 'slug',  // Changed to slug
                'terms' => $accessible_groups,
                'operator' => 'IN'
            )
        );
    } else {
        // If no accessible groups, show only user's own posts
        $args['author'] = $user_id;
    }
    
    return get_posts($args);
}

/**
 * Get goals for a workplan
 */
function wpm_get_workplan_goals($workplan_id) {
    $goal_ids = get_field('related_work_plan_goals', $workplan_id) ?: array();
    
    if (empty($goal_ids)) {
        return array();
    }
    
    return get_posts(array(
        'post_type' => 'workplan-goal',
        'post__in' => $goal_ids,
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'post__in'
    ));
}

/**
 * Get objectives for a goal
 */
function wpm_get_goal_objectives($goal_id) {
    $objective_ids = get_field('work_plan_objectives', $goal_id) ?: array();
    
    if (empty($objective_ids)) {
        return array();
    }
    
    return get_posts(array(
        'post_type' => 'workplan-objective',
        'post__in' => $objective_ids,
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'post__in'
    ));
}

/**
 * Get next available goal letter for a workplan
 */
function wpm_get_next_goal_letter($workplan_id) {
    $existing_goals = wpm_get_workplan_goals($workplan_id);
    $used_letters = array();
    
    foreach ($existing_goals as $goal) {
        $letter = get_field('goal_letter', $goal->ID);
        if ($letter) {
            $used_letters[] = $letter;
        }
    }
    
    // Find first available letter
    for ($i = 65; $i <= 90; $i++) { // A-Z
        $letter = chr($i);
        if (!in_array($letter, $used_letters)) {
            return $letter;
        }
    }
    
    return 'A'; // Fallback
}

/**
 * Get next available objective number for a goal
 */
function wpm_get_next_objective_number($goal_id) {
    $existing_objectives = wpm_get_goal_objectives($goal_id);
    $used_numbers = array();
    
    foreach ($existing_objectives as $objective) {
        $number = get_field('objective_number', $objective->ID);
        if ($number) {
            $used_numbers[] = intval($number);
        }
    }
    
    if (empty($used_numbers)) {
        return 1;
    }
    
    sort($used_numbers);
    
    // Find first available number
    for ($i = 1; $i <= 100; $i++) {
        if (!in_array($i, $used_numbers)) {
            return $i;
        }
    }
    
    return count($used_numbers) + 1; // Fallback
}

/**
 * Validate user permissions for workplan
 */
function wpm_user_can_edit_workplan($workplan_id, $user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Check if user can edit workplans at all
    if (!user_can($user_id, 'edit_workplans')) {
        return false;
    }
    
    // Administrators can edit all workplans
    if (user_can($user_id, 'edit_others_workplans')) {
        return true;
    }
    
    // Check if user is the author
    $workplan = get_post($workplan_id);
    if ($workplan && intval($workplan->post_author) === intval($user_id)) {
        return true;
    }
    
    // Check group permissions using the new method
    $wpm = new WorkPlanManager();
    $accessible_groups = $wpm->get_accessible_groups($user_id);
    
    if (!empty($accessible_groups)) {
        $workplan_groups = wp_get_post_terms($workplan_id, 'group', array('fields' => 'slugs'));
        
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WPM Debug] Checking permissions for workplan ' . $workplan_id);
            error_log('[WPM Debug] User accessible groups: ' . print_r($accessible_groups, true));
            error_log('[WPM Debug] Workplan groups: ' . print_r($workplan_groups, true));
        }
        
        foreach ($workplan_groups as $group_slug) {
            if (in_array($group_slug, $accessible_groups)) {
                // Debug logging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WPM Debug] Permission granted: user can access group "' . $group_slug . '"');
                }
                return true;
            }
        }
    }
    
    // Debug logging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WPM Debug] Permission denied for user ' . $user_id . ' on workplan ' . $workplan_id);
    }
    
    return false;
}

/**
 * Get workplan completion status
 */
function wpm_get_workplan_completion_status($workplan_id) {
    $goals = wpm_get_workplan_goals($workplan_id);
    $total_goals = count($goals);
    $completed_goals = 0;
    $total_objectives = 0;
    $completed_objectives = 0;
    
    foreach ($goals as $goal) {
        $goal_letter = get_field('goal_letter', $goal->ID);
        $goal_description = get_field('goal_description', $goal->ID);
        
        if (!empty($goal_letter) && !empty($goal_description)) {
            $completed_goals++;
        }
        
        $objectives = wpm_get_goal_objectives($goal->ID);
        $total_objectives += count($objectives);
        
        foreach ($objectives as $objective) {
            $obj_number = get_field('objective_number', $objective->ID);
            $obj_description = get_field('objective_description', $objective->ID);
            $timeline_description = get_field('timeline_description', $objective->ID);
            $measureable_outcomes = get_field('measureable_outcomes', $objective->ID);
            
            // Consider objective complete if it has number, description, and either timeline or measurable outcomes
            if (!empty($obj_number) && !empty($obj_description) && !empty($timeline_description)) {
                $completed_objectives++;
            }
        }
    }
    
    return array(
        'goals' => array(
            'total' => $total_goals,
            'completed' => $completed_goals,
            'percentage' => $total_goals > 0 ? round(($completed_goals / $total_goals) * 100) : 0
        ),
        'objectives' => array(
            'total' => $total_objectives,
            'completed' => $completed_objectives,
            'percentage' => $total_objectives > 0 ? round(($completed_objectives / $total_objectives) * 100) : 0
        ),
        'overall_percentage' => $total_goals > 0 && $total_objectives > 0 ? 
            round(((($completed_goals / $total_goals) + ($completed_objectives / $total_objectives)) / 2) * 100) : 0
    );
}

/**
 * Clean up temporary export files (run via cron)
 */
function wpm_cleanup_export_files() {
    $upload_dir = wp_upload_dir();
    $export_dir = $upload_dir['basedir'] . '/workplan-exports/';
    
    if (!is_dir($export_dir)) {
        return;
    }
    
    $files = glob($export_dir . '*');
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file)) {
            // Delete files older than 2 hours
            if ($now - filemtime($file) >= 2 * HOUR_IN_SECONDS) {
                unlink($file);
            }
        }
    }
}

// Schedule cleanup if not already scheduled
if (!wp_next_scheduled('wpm_cleanup_exports')) {
    wp_schedule_event(time(), 'hourly', 'wpm_cleanup_exports');
}
add_action('wpm_cleanup_exports', 'wpm_cleanup_export_files');

/**
 * Format workplan data for display
 */
function wpm_format_workplan_for_display($workplan_id) {
    $workplan = get_post($workplan_id);
    
    if (!$workplan) {
        return false;
    }
    
    $formatted_data = array(
        'workplan' => array(
            'id' => $workplan->ID,
            'title' => $workplan->post_title,
            'author' => get_the_author_meta('display_name', $workplan->post_author),
            'date' => get_the_date('F j, Y', $workplan->ID),
            'internal_status' => get_field('internal_status', $workplan->ID),
            'group' => wp_get_post_terms($workplan->ID, 'group', array('fields' => 'names')),
            'grant_year' => wp_get_post_terms($workplan->ID, 'grant-year', array('fields' => 'names')),
        ),
        'completion_status' => wpm_get_workplan_completion_status($workplan_id),
        'goals' => array()
    );
    
    $goals = wpm_get_workplan_goals($workplan_id);
    
    foreach ($goals as $goal) {
        $goal_data = array(
            'id' => $goal->ID,
            'title' => $goal->post_title,
            'letter' => get_field('goal_letter', $goal->ID),
            'description' => get_field('goal_description', $goal->ID),
            'objectives' => array()
        );
        
        $objectives = wpm_get_goal_objectives($goal->ID);
        
        foreach ($objectives as $objective) {
            $outputs = get_field('objective_outputs', $objective->ID) ?: array();
            
            $goal_data['objectives'][] = array(
                'id' => $objective->ID,
                'title' => $objective->post_title,
                'number' => get_field('objective_number', $objective->ID),
                'description' => get_field('objective_description', $objective->ID),
                'timeline_description' => get_field('timeline_description', $objective->ID),
                'outputs' => $outputs
            );
        }
        
        $formatted_data['goals'][] = $goal_data;
    }
    
    return $formatted_data;
}

/**
 * Debug function to log workplan data
 */
function wpm_debug_log($message, $data = null) {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $log_message = '[WPM Debug] ' . $message;
        
        if ($data !== null) {
            $log_message .= ' | Data: ' . print_r($data, true);
        }
        
        error_log($log_message);
    }
}

/**
 * Check system requirements
 */
function wpm_check_system_requirements() {
    $requirements = array(
        'php_version' => array(
            'required' => '7.4',
            'current' => PHP_VERSION,
            'check' => version_compare(PHP_VERSION, '7.4', '>=')
        ),
        'wp_version' => array(
            'required' => '5.0',
            'current' => get_bloginfo('version'),
            'check' => version_compare(get_bloginfo('version'), '5.0', '>=')
        ),
        'acf_active' => array(
            'required' => 'Active',
            'current' => wpm_is_acf_active() ? 'Active' : 'Inactive',
            'check' => wpm_is_acf_active()
        )
    );
    
    return $requirements;
}

/**
 * Display admin notices for system requirements
 */
function wpm_admin_notices() {
    // Only show on relevant admin pages
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, array('plugins', 'toplevel_page_work-plan-manager'))) {
        return;
    }
    
    try {
        $requirements = wpm_check_system_requirements();
        $has_errors = false;
        
        foreach ($requirements as $requirement) {
            if (!$requirement['check']) {
                $has_errors = true;
                break;
            }
        }
        
        if ($has_errors) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>Work Plan Manager:</strong> Some requirements are not met. ';
            echo 'Please check the plugin requirements and ensure all dependencies are active.';
            echo '</p></div>';
        }
        
        // Show success message on fresh activation
        if (get_option('wpm_activated') && get_option('wpm_activation_time')) {
            $activation_time = get_option('wpm_activation_time');
            if ($activation_time && (current_time('timestamp') - $activation_time) < 300) { // 5 minutes
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo '<strong>Work Plan Manager:</strong> Plugin activated successfully!';
                echo '</p></div>';
                delete_option('wpm_activation_time');
            }
        }
        
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Work Plan Manager] Admin notice error: ' . $e->getMessage());
        }
    }
}
add_action('admin_notices', 'wpm_admin_notices');
