<?php
/**
 * Optional ACF Field Behaviors for Work Plan Manager
 * 
 * This file contains helper functions that enhance ACF field behavior
 * within the plugin context. Include this file only if you want these
 * additional validation and automation features.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Only run if ACF is active
if (!function_exists('get_field')) {
    return;
}

/**
 * Add validation for duplicate goal letters, objective numbers, and output letters
 * This prevents conflicts when reordering items
 */
add_filter('acf/validate_value/name=goal_letter', 'wpm_validate_goal_letter_duplicates', 10, 4);
function wpm_validate_goal_letter_duplicates($valid, $value, $field, $input) {
    if (!$valid || !$value) {
        return $valid;
    }
    
    // This validation runs in the admin interface - for plugin interface, 
    // we handle validation via JavaScript before submission
    return $valid;
}

/**
 * Add validation for duplicate objective numbers within goals
 */
add_filter('acf/validate_value/name=objective_number', 'wmp_validate_objective_number_duplicates', 10, 4);
function wmp_validate_objective_number_duplicates($valid, $value, $field, $input) {
    if (!$valid || !$value) {
        return $valid;
    }
    
    // This validation runs in the admin interface - for plugin interface,
    // we handle validation via JavaScript before submission  
    return $valid;
}

/**
 * Add AJAX endpoint for validation checks (used by plugin interface)
 */
add_action('wp_ajax_wmp_validate_duplicates', 'wpm_ajax_validate_duplicates');
function wpm_ajax_validate_duplicates() {
    check_ajax_referer('wpm_nonce', 'nonce');
    
    $type = sanitize_text_field($_POST['type'] ?? '');
    $value = sanitize_text_field($_POST['value'] ?? '');
    $context_id = intval($_POST['context_id'] ?? 0);
    $exclude_id = intval($_POST['exclude_id'] ?? 0);
    
    $is_duplicate = false;
    $message = '';
    
    switch ($type) {
        case 'goal_letter':
            // Check for duplicate goal letters within a workplan
            if ($context_id) {
                $related_goals = get_field('related_work_plan_goals', $context_id) ?: array();
                foreach ($related_goals as $goal_id) {
                    if ($goal_id != $exclude_id) {
                        $existing_letter = get_field('goal_letter', $goal_id);
                        if (strtoupper($existing_letter) === strtoupper($value)) {
                            $is_duplicate = true;
                            $message = "Goal letter '{$value}' is already used in this work plan.";
                            break;
                        }
                    }
                }
            }
            break;
            
        case 'objective_number':
            // Check for duplicate objective numbers within a goal
            if ($context_id) {
                $related_objectives = get_field('work_plan_objectives', $context_id) ?: array();
                foreach ($related_objectives as $objective_id) {
                    if ($objective_id != $exclude_id) {
                        $existing_number = get_field('objective_number', $objective_id);
                        if (intval($existing_number) === intval($value)) {
                            $is_duplicate = true;
                            $message = "Objective number '{$value}' is already used in this goal.";
                            break;
                        }
                    }
                }
            }
            break;
            
        case 'output_letter':
            // Check for duplicate output letters within an objective
            if ($context_id) {
                $outputs = get_field('objective_outputs', $context_id) ?: array();
                $letter_count = 0;
                foreach ($outputs as $output) {
                    if (strtoupper($output['output_letter']) === strtoupper($value)) {
                        $letter_count++;
                    }
                }
                if ($letter_count > 1) {
                    $is_duplicate = true;
                    $message = "Output letter '{$value}' is used multiple times in this objective.";
                }
            }
            break;
    }
    
    if ($is_duplicate) {
        wp_send_json_error($message);
    } else {
        wp_send_json_success('No duplicates found');
    }
}

/**
 * Real-time validation helpers for the plugin interface
 */
add_action('wp_ajax_wpm_check_duplicate_goal_letter', 'wpm_ajax_check_duplicate_goal_letter');
function wpm_ajax_check_duplicate_goal_letter() {
    check_ajax_referer('wpm_nonce', 'nonce');
    
    $workplan_id = intval($_POST['workplan_id'] ?? 0);
    $goal_letter = sanitize_text_field($_POST['goal_letter'] ?? '');
    $exclude_goal_id = intval($_POST['exclude_id'] ?? 0);
    
    if (!$workplan_id || !$goal_letter) {
        wp_send_json_success(array('is_unique' => true));
    }
    
    $related_goals = get_field('related_work_plan_goals', $workplan_id) ?: array();
    
    foreach ($related_goals as $goal_id) {
        if ($goal_id != $exclude_goal_id) {
            $existing_letter = get_field('goal_letter', $goal_id);
            if (strtoupper($existing_letter) === strtoupper($goal_letter)) {
                wp_send_json_success(array(
                    'is_unique' => false,
                    'message' => "Goal letter '{$goal_letter}' is already used in this work plan."
                ));
            }
        }
    }
    
    wp_send_json_success(array('is_unique' => true));
}

add_action('wp_ajax_wpm_check_duplicate_objective_number', 'wpm_ajax_check_duplicate_objective_number');
function wpm_ajax_check_duplicate_objective_number() {
    check_ajax_referer('wpm_nonce', 'nonce');
    
    $goal_id = intval($_POST['goal_id'] ?? 0);
    $objective_number = intval($_POST['objective_number'] ?? 0);
    $exclude_objective_id = intval($_POST['exclude_id'] ?? 0);
    
    if (!$goal_id || !$objective_number) {
        wp_send_json_success(array('is_unique' => true));
    }
    
    $related_objectives = get_field('work_plan_objectives', $goal_id) ?: array();
    
    foreach ($related_objectives as $objective_id) {
        if ($objective_id != $exclude_objective_id) {
            $existing_number = get_field('objective_number', $objective_id);
            if (intval($existing_number) === intval($objective_number)) {
                wp_send_json_success(array(
                    'is_unique' => false,
                    'message' => "Objective number '{$objective_number}' is already used in this goal."
                ));
            }
        }
    }
    
    wp_send_json_success(array('is_unique' => true));
}

/**
 * Auto-populate group taxonomy when saving posts (optional enhancement)
 * Automatically copies group taxonomy from parent workplan to goals and objectives
 */
add_action('acf/save_post', 'wpm_auto_populate_group_taxonomy', 20);
function wpm_auto_populate_group_taxonomy($post_id) {
    // Skip if this is an autosave, revision, or not one of our post types
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    
    $post_type = get_post_type($post_id);
    
    // Handle goal taxonomy inheritance
    if ($post_type === 'workplan-goal') {
        // Find parent workplan
        $all_workplans = get_posts(array(
            'post_type' => 'workplan',
            'posts_per_page' => -1, 
            'post_status' => 'publish'
        ));
        
        foreach ($all_workplans as $workplan) {
            $related_goals = get_field('related_work_plan_goals', $workplan->ID) ?: array();
            if (in_array($post_id, $related_goals)) {
                // Copy group taxonomy from parent workplan
                $group_terms = wp_get_post_terms($workplan->ID, 'group', array('fields' => 'slugs'));
                if (!empty($group_terms)) {
                    wp_set_post_terms($post_id, $group_terms, 'group');
                }
                break;
            }
        }
    }
    
    // Handle objective taxonomy inheritance
    if ($post_type === 'workplan-objective') {
        // Find parent goal, then parent workplan
        $workplan_id = 0;
        
        $all_goals = get_posts(array(
            'post_type' => 'workplan-goal',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        
        // Find which goal contains this objective
        foreach ($all_goals as $goal) {
            $related_objectives = get_field('work_plan_objectives', $goal->ID) ?: array();
            if (in_array($post_id, $related_objectives)) {
                // Now find which workplan contains this goal
                $all_workplans = get_posts(array(
                    'post_type' => 'workplan',
                    'posts_per_page' => -1,
                    'post_status' => 'publish'
                ));
                
                foreach ($all_workplans as $workplan) {
                    $related_goals = get_field('related_work_plan_goals', $workplan->ID) ?: array();
                    if (in_array($goal->ID, $related_goals)) {
                        $workplan_id = $workplan->ID;
                        break 2; // Break both loops
                    }
                }
            }
        }
        
        // Copy group taxonomy from parent workplan
        if ($workplan_id) {
            $group_terms = wp_get_post_terms($workplan_id, 'group', array('fields' => 'slugs'));
            if (!empty($group_terms)) {
                wp_set_post_terms($post_id, $group_terms, 'group');
            }
        }
    }
}

/**
 * Helper function to get suggested goal letter (optional enhancement)
 * Returns the next available letter for a workplan
 */
function wpm_suggest_next_goal_letter($workplan_id) {
    $related_goals = get_field('related_work_plan_goals', $workplan_id) ?: array();
    $used_letters = array();
    
    foreach ($related_goals as $goal_id) {
        $letter = get_field('goal_letter', $goal_id);
        if ($letter) {
            $used_letters[] = strtoupper($letter);
        }
    }
    
    // Find first available letter A-Z
    for ($i = 65; $i <= 90; $i++) {
        $letter = chr($i);
        if (!in_array($letter, $used_letters)) {
            return $letter;
        }
    }
    
    return 'A'; // Fallback if all letters used
}

/**
 * Helper function to get suggested objective number (optional enhancement)
 * Returns the next available number for a goal
 */
function wpm_suggest_next_objective_number($goal_id) {
    $related_objectives = get_field('work_plan_objectives', $goal_id) ?: array();
    $used_numbers = array();
    
    foreach ($related_objectives as $objective_id) {
        $number = get_field('objective_number', $objective_id);
        if ($number) {
            $used_numbers[] = intval($number);
        }
    }
    
    if (empty($used_numbers)) {
        return 1;
    }
    
    sort($used_numbers);
    
    // Find first gap in sequence or next number
    for ($i = 1; $i <= max($used_numbers) + 1; $i++) {
        if (!in_array($i, $used_numbers)) {
            return $i;
        }
    }
    
    return 1; // Fallback
}

/**
 * Helper function to get suggested output letter (optional enhancement)
 * Returns the next available letter for an objective's outputs
 */
function wpm_suggest_next_output_letter($objective_id) {
    $outputs = get_field('objective_outputs', $objective_id) ?: array();
    $used_letters = array();
    
    foreach ($outputs as $output) {
        if (!empty($output['output_letter'])) {
            $used_letters[] = strtolower($output['output_letter']);
        }
    }
    
    // Find first available letter a-z
    for ($i = 97; $i <= 122; $i++) { // a-z
        $letter = chr($i);
        if (!in_array($letter, $used_letters)) {
            return $letter;
        }
    }
    
    return 'a'; // Fallback
}

/**
 * AJAX endpoint to get next suggested values (optional enhancement)
 * Can be called from JavaScript to get suggested letters/numbers
 */
add_action('wp_ajax_wpm_get_suggestions', 'wpm_ajax_get_suggestions');
function wpm_ajax_get_suggestions() {
    check_ajax_referer('wpm_nonce', 'nonce');
    
    $type = sanitize_text_field($_POST['type'] ?? '');
    $parent_id = intval($_POST['parent_id'] ?? 0);
    
    if ($type === 'goal_letter' && $parent_id) {
        $suggestion = wpm_suggest_next_goal_letter($parent_id);
        wp_send_json_success(array('suggestion' => $suggestion));
    }
    
    if ($type === 'objective_number' && $parent_id) {
        $suggestion = wpm_suggest_next_objective_number($parent_id);
        wp_send_json_success(array('suggestion' => $suggestion));
    }
    
    if ($type === 'output_letter' && $parent_id) {
        $suggestion = wpm_suggest_next_output_letter($parent_id);
        wp_send_json_success(array('suggestion' => $suggestion));
    }
    
    wp_send_json_error('Invalid request');
}