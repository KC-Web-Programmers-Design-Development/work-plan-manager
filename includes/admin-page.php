<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$current_user_id = get_current_user_id();
$wpm = new WorkPlanManager();
$accessible_groups = $wpm->get_accessible_groups($current_user_id);

// Get existing workplans for the current user's groups
$workplans_query_args = array(
    'post_type' => 'workplan',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'orderby' => 'title',
    'order' => 'ASC'
);

// Only add group filtering for non-administrators
if (!current_user_can('edit_others_workplans')) {
    if (!empty($accessible_groups)) {
        // accessible_groups now contains SLUGS, not names
        $workplans_query_args['tax_query'] = array(
            array(
                'taxonomy' => 'group',
                'field' => 'slug',  // Changed from 'name' to 'slug'
                'terms' => $accessible_groups,  // Now these are slugs
                'operator' => 'IN'
            )
        );
    } else {
        // If user has no accessible groups and isn't admin, show only their own posts
        $workplans_query_args['author'] = $current_user_id;
    }
}

$existing_workplans = get_posts($workplans_query_args);

// Debug logging to help troubleshoot
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[WPM Debug] Current user ID: ' . $current_user_id);
    error_log('[WPM Debug] Can edit others workplans: ' . (current_user_can('edit_others_workplans') ? 'Yes' : 'No'));
    error_log('[WPM Debug] Accessible groups: ' . print_r($accessible_groups, true));
    error_log('[WPM Debug] Query args: ' . print_r($workplans_query_args, true));
    error_log('[WPM Debug] Found workplans: ' . count($existing_workplans));
}

// Get grant year terms (no quarters)
$grant_years = get_terms(array(
    'taxonomy' => 'grant-year',
    'hide_empty' => false,
));

// Get group terms
$group_terms = get_terms(array(
    'taxonomy' => 'group',
    'hide_empty' => false,
));
?>

<div class="wrap wpm-container">
    <h1><?php _e('Work Plan Manager', 'work-plan-manager'); ?></h1>
    
    <div class="wpm-main-content">
        <!-- Workplan Selection/Creation Section -->
        <div class="wpm-section wmp-workplan-section">
            <h2><?php _e('Work Plan', 'work-plan-manager'); ?></h2>
            
            <div class="wpm-workplan-selector">
                <div class="wpm-form-row">
                    <div class="wpm-form-group">
                        <!--<label for="existing-workplan"><?php _e('Select Existing Work Plan:', 'work-plan-manager'); ?></label>-->
                        <select id="existing-workplan" name="existing_workplan">
                            <option value=""><?php _e('-- Select Existing Work Plan --', 'work-plan-manager'); ?></option>
                            <?php if (!empty($existing_workplans)): ?>
                                <?php foreach ($existing_workplans as $workplan): ?>
                                    <option value="<?php echo $workplan->ID; ?>">
                                        <?php echo esc_html($workplan->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option disabled><?php _e('No work plans available', 'work-plan-manager'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="wpm-form-group call-workplan">
                        <button type="button" id="load-workplan" class="button button-secondary" <?php echo empty($existing_workplans) ? 'disabled' : ''; ?>>
                            <?php _e('Load Work Plan', 'work-plan-manager'); ?>
                        </button>
                        <button type="button" id="new-workplan" class="button button-primary">
                            <?php _e('Add New Work Plan', 'work-plan-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="wpm-workplan-form" id="workplan-form" style="display: none;">
                <input type="hidden" id="workplan-id" value="0">
                
                <div class="wpm-form-row">
                    <div class="wpm-form-group wpm-form-group-full">
                        <label for="workplan-title"><?php _e('Work Plan Title:', 'work-plan-manager'); ?></label>
                        <input type="text" id="workplan-title" name="workplan_title" required>
                    </div>
                </div>
                
                <div class="wpm-form-row">
                    <div class="wpm-form-group">
                        <label for="workplan-author"><?php _e('Author:', 'work-plan-manager'); ?></label>
                        <input type="text" id="workplan-author" value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>" readonly>
                    </div>
                    <div class="wpm-form-group">
                        <label for="workplan-date"><?php _e('Publish Date:', 'work-plan-manager'); ?></label>
                        <input type="date" id="workplan-date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <div class="wpm-form-row">
                    <div class="wpm-form-group">
                        <label for="grant-year"><?php _e('Grant Year:', 'work-plan-manager'); ?></label>
                        <select id="grant-year" name="grant_year">
                            <option value=""><?php _e('-- Select Year --', 'work-plan-manager'); ?></option>
                            <?php foreach ($grant_years as $year): ?>
                                <option value="<?php echo $year->slug; ?>">
                                    <?php echo esc_html($year->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="wpm-form-group">
                        <label for="internal-status"><?php _e('Internal Status:', 'work-plan-manager'); ?></label>
                        <select id="internal-status" name="internal_status">
                            <option value="Draft"><?php _e('Draft', 'work-plan-manager'); ?></option>
                            <option value="Submitted"><?php _e('Submitted', 'work-plan-manager'); ?></option>
                            <option value="Approved"><?php _e('Approved', 'work-plan-manager'); ?></option>
                            <option value="Locked"><?php _e('Locked', 'work-plan-manager'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="wpm-form-row">
                    <div class="wpm-form-group wpm-form-group-full">
                        <label for="workplan-group"><?php _e('Group:', 'work-plan-manager'); ?></label>
                        <select id="workplan-group" name="workplan_group">
                            <option value=""><?php _e('-- Select Group --', 'work-plan-manager'); ?></option>
                            <?php foreach ($group_terms as $group): ?>
                                <?php 
                                // Show all groups to administrators, only accessible groups to others
                                $can_access_group = current_user_can('edit_others_workplans') || 
                                                empty($accessible_groups) || 
                                                in_array($group->slug, $accessible_groups); // Changed to check slug
                                ?>
                                <?php if ($can_access_group): ?>
                                    <option value="<?php echo $group->term_id; ?>">
                                        <?php echo esc_html($group->name); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="wpm-form-actions">
                    <button type="button" id="save-workplan" class="button button-primary">
                        <?php _e('Save Work Plan', 'work-plan-manager'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Goals Section -->
        <div class="wpm-section wpm-goals-section" id="goals-section" style="display: none;">
            <div class="wpm-section-header">
                <h2><?php _e('Work Plan Goals', 'work-plan-manager'); ?></h2>
                <button type="button" id="add-goal" class="button button-secondary">
                    <?php _e('Add New Goal', 'work-plan-manager'); ?>
                </button>
            </div>
            
            <div id="goals-container"></div>
        </div>
        
        <!-- Preview Section -->
        <div class="wpm-section wpm-preview-section" id="preview-section" style="display: none;">
            <div class="wpm-section-header">
                <h2><?php _e('Work Plan Preview', 'work-plan-manager'); ?></h2>
                <div class="wpm-preview-actions">
                    <button type="button" id="export-excel" class="button button-secondary">
                        <?php _e('Export to XML', 'work-plan-manager'); ?>
                    </button>
                    <button type="button" id="export-csv" class="button button-secondary">
                        <?php _e('Export to CSV', 'work-plan-manager'); ?>
                    </button>
                </div>
            </div>
            
            <div id="workplan-preview"></div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="wpm-loading" class="wpm-loading" style="display: none;">
        <div class="wpm-loading-spinner"></div>
        <div class="wpm-loading-text"><?php _e('Processing...', 'work-plan-manager'); ?></div>
    </div>
</div>

<!-- Goal Template -->
<script type="text/template" id="goal-template">
    <div class="wpm-goal-item" data-goal-id="{{goal_id}}">
        <div class="wpm-goal-header">
            <div class="wpm-header-title">
                <h3>
                    <?php _e('Goal&nbsp;', 'work-plan-manager'); ?> 
                    <span class="goal-letter">{{goal_letter}}</span>
                    <span class="wpm-header-subtitle">{{title_suffix}}</span>
                </h3>
                <button type="button" class="wpm-collapse-toggle">Collapse</button>
            </div>
            <div class="wpm-goal-actions">
                <button type="button" class="button button-small duplicate-goal"><?php _e('Duplicate', 'work-plan-manager'); ?></button>
                <button type="button" class="button button-small delete-goal"><?php _e('Delete', 'work-plan-manager'); ?></button>
            </div>
        </div>
        
        <div class="wpm-goal-form">
            <input type="hidden" class="goal-id" value="{{goal_id}}">
            
            <div class="wpm-form-row">
                <div class="wpm-form-group wpm-form-group-full">
                    <label><?php _e('Goal Title:', 'work-plan-manager'); ?></label>
                    <input type="text" class="goal-title" value="{{goal_title}}" required>
                </div>
            </div>
            
            <div class="wpm-form-row">
                <div class="wpm-form-group">
                    <label><?php _e('Goal Letter:', 'work-plan-manager'); ?></label>
                    <select class="goal-letter-select">
                        <?php for ($i = 65; $i <= 90; $i++): ?>
                            <option value="<?php echo chr($i); ?>"><?php echo chr($i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <div class="wpm-form-row">
                <div class="wpm-form-group wpm-form-group-full">
                    <label><?php _e('Goal Description:', 'work-plan-manager'); ?></label>
                    <textarea class="goal-description" rows="3">{{goal_description}}</textarea>
                </div>
            </div>
            
            <div class="wpm-form-actions">
                <button type="button" class="button button-primary save-goal">
                    <?php _e('Save Goal', 'work-plan-manager'); ?>
                </button>
                <button type="button" class="button button-secondary add-objective">
                    <?php _e('Add Objective', 'work-plan-manager'); ?>
                </button>
            </div>
            
            <div class="wpm-objectives-container"></div>
        </div>
    </div>
</script>

<!-- Objective Template -->
<script type="text/template" id="objective-template">
    <div class="wpm-objective-item" data-objective-id="{{objective_id}}">
        <div class="wpm-objective-header">
            <div class="wpm-header-title">
                <h4>
                    <?php _e('Objective&nbsp;', 'work-plan-manager'); ?> 
                    <span class="objective-number">{{objective_number}}</span>
                    <span class="wpm-header-subtitle">{{title_suffix}}</span>
                </h4>
                <button type="button" class="wpm-collapse-toggle">Collapse</button>
            </div>
            <div class="wpm-objective-actions">
                <button type="button" class="button button-small duplicate-objective"><?php _e('Duplicate', 'work-plan-manager'); ?></button>
                <button type="button" class="button button-small delete-objective"><?php _e('Delete', 'work-plan-manager'); ?></button>
            </div>
        </div>
        
        <div class="wpm-objective-form">

        <input type="hidden" class="objective-id" value="{{objective_id}}">
            
            <div class="wpm-form-row">
                <div class="wpm-form-group">
                    <label><?php _e('Objective Title:', 'work-plan-manager'); ?></label>
                    <input type="text" class="objective-title" value="{{objective_title}}" required>
                </div>
                <div class="wpm-form-group">
                    <label><?php _e('Objective Number:', 'work-plan-manager'); ?></label>
                    <input type="number" class="objective-number-input" value="{{objective_number}}" min="1">
                </div>
            </div>
            
            <div class="wpm-form-row">
                <div class="wpm-form-group wpm-form-group-full">
                    <label><?php _e('Timeline Description:', 'work-plan-manager'); ?></label>
                    <input type="text" class="timeline-description" value="{{timeline_description}}">
                </div>
            </div>
            
            <div class="wpm-form-row">
                <div class="wpm-form-group wpm-form-group-full">
                    <label><?php _e('Objective Description:', 'work-plan-manager'); ?></label>
                    <textarea class="objective-description" rows="3">{{objective_description}}</textarea>
                </div>
            </div>
            
            <!--removed for vers. 1.2.2 
            <div class="wpm-form-row">
                <div class="wpm-form-group wpm-form-group-full">
                    <label><?php _e('Measurable Outcomes:', 'work-plan-manager'); ?></label>
                    <textarea class="measureable-outcomes" rows="3">{{measureable_outcomes}}</textarea>
                </div>
            </div>
            //end removed -->
            
            <div class="wpm-outputs-section">
                <div class="wpm-outputs-header">
                    <label><?php _e('Outputs/Activities:', 'work-plan-manager'); ?></label>
                    <button type="button" class="button button-small add-output"><?php _e('Add Output', 'work-plan-manager'); ?></button>
                </div>
                <div class="wpm-outputs-container"></div>
            </div>
            
            <div class="wpm-form-actions">
                <button type="button" class="button button-primary save-objective">
                    <?php _e('Save Objective', 'work-plan-manager'); ?>
                </button>
            </div>
        </div>
    </div>
</script>

<!-- Output Template -->
<script type="text/template" id="output-template">
    <div class="wpm-output-row">
        <div class="wpm-form-group">
            <label><?php _e('Output/Activity Letter:', 'work-plan-manager'); ?></label>
            <input type="text" class="output-letter" value="{{output_letter}}" maxlength="1" style="text-transform: uppercase;">
        </div>
        <div class="wpm-form-group wpm-form-group-full">
            <label><?php _e('Output/Activity Description:', 'work-plan-manager'); ?></label>
            <textarea class="output-description" rows="2">{{output_description}}</textarea>
        </div>
        <div class="wpm-form-group">
            <button type="button" class="button button-small remove-output"><?php _e('Remove', 'work-plan-manager'); ?></button>
        </div>
        <div class="wpm-bimonthly-refs" style="display:none;">
            <label><?php _e('Bi-Monthly Update References:', 'work-plan-manager'); ?></label>
            <div class="wpm-refs-list"></div>
        </div>
    </div>
</script>
