<?php
/**
 * Export Handler for Work Plan Manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WPM_Export_Handler {
    
    public function __construct() {
        add_action('wp_ajax_export_workplan', array($this, 'handle_export_request'));
        add_action('init', array($this, 'handle_download_request'));
    }
    
    /**
     * Handle AJAX export request
     */
    public function handle_export_request() {
        check_ajax_referer('wpm_nonce', 'nonce');
        
        if (!current_user_can('manage_workplans')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $workplan_id = intval($_POST['workplan_id']);
        $format = sanitize_text_field($_POST['format']);
        
        if (!$workplan_id) {
            wp_send_json_error('Invalid work plan ID');
        }
        
        try {
            $file_info = $this->generate_export_file($workplan_id, $format);
            wp_send_json_success($file_info);
        } catch (Exception $e) {
            wp_send_json_error('Export failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle direct download request
     */
    public function handle_download_request() {
        if (!isset($_GET['wpm_download']) || !isset($_GET['token'])) {
            return;
        }
        
        $token = sanitize_text_field($_GET['token']);
        $file_info = get_transient('wpm_export_' . $token);
        
        if (!$file_info) {
            wp_die('Invalid or expired download link');
        }
        
        $file_path = $file_info['file_path'];
        
        if (!file_exists($file_path)) {
            wp_die('Export file not found');
        }
        
        // Set headers for file download
        header('Content-Type: ' . $file_info['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file_info['filename'] . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0, max-age=1');
        header('Pragma: public');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file_path)) . ' GMT');
        
        // Output file and clean up
        readfile($file_path);
        unlink($file_path);
        delete_transient('wpm_export_' . $token);
        exit;
    }
    
    /**
     * Generate export file
     */
    private function generate_export_file($workplan_id, $format) {
        $workplan_data = $this->get_workplan_export_data($workplan_id);
        
        if (empty($workplan_data)) {
            throw new Exception('No data found for export');
        }
        
        // Create uploads directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/workplan-exports/';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        // Generate unique filename
        $timestamp = date('Y-m-d-H-i-s');
        $workplan_title = sanitize_file_name($workplan_data['workplan']['title']);
        $filename = "workplan-{$workplan_title}-{$timestamp}";
        
        switch ($format) {
            case 'excel':
                return $this->generate_excel_xml_file($workplan_data, $export_dir, $filename);
            case 'csv':
                return $this->generate_csv_file($workplan_data, $export_dir, $filename);
            default:
                throw new Exception('Unsupported export format');
        }
    }
    
    /**
     * Get workplan data for export
     */
    private function get_workplan_export_data($workplan_id) {
        $workplan = get_post($workplan_id);
        
        if (!$workplan) {
            return false;
        }
        
        // Build bimonthly references cache
        $bm_cache = $this->build_bimonthly_cache();

        // Get workplan data
        $workplan_data = array(
            'workplan' => array(
                'id' => $workplan->ID,
                'title' => $workplan->post_title,
                'author' => get_the_author_meta('display_name', $workplan->post_author),
                'date' => $workplan->post_date,
                'internal_status' => get_field('internal_status', $workplan->ID),
                'group' => $this->get_taxonomy_names($workplan->ID, 'group'),
                'grant_year' => $this->get_taxonomy_names($workplan->ID, 'grant-year'),
            ),
            'goals' => array()
        );
        
        // Get goals using the relationship field
        $goal_ids = get_field('related_work_plan_goals', $workplan_id) ?: array();
        
        foreach ($goal_ids as $goal_id) {
            $goal = get_post($goal_id);
            if (!$goal) continue;
            
            $goal_data = array(
                'id' => $goal->ID,
                'title' => $goal->post_title,
                'letter' => get_field('goal_letter', $goal->ID),
                'description' => get_field('goal_description', $goal->ID),
                'objectives' => array()
            );
            
            // Get objectives for this goal using the relationship field
            $objective_ids = get_field('work_plan_objectives', $goal->ID) ?: array();
            
            foreach ($objective_ids as $objective_id) {
                $objective = get_post($objective_id);
                if (!$objective) continue;
                
                $outputs = get_field('objective_outputs', $objective->ID) ?: array();
                
                // Enrich each output with bimonthly references
                foreach ($outputs as $idx => &$output) {
                    $key = intval($objective->ID) . '_' . $idx;
                    $output['bimonthly_refs'] = isset($bm_cache[$key]) ? $bm_cache[$key] : array();
                }
                unset($output);
                
                $objective_data = array(
                    'id' => $objective->ID,
                    'title' => $objective->post_title,
                    'number' => get_field('objective_number', $objective->ID),
                    'description' => get_field('objective_description', $objective->ID),
                    'timeline_description' => get_field('timeline_description', $objective->ID),
                    //'measureable_outcomes' => get_field('measureable_outcomes', $objective->ID),
                    'outputs' => $outputs
                );
                
                $goal_data['objectives'][] = $objective_data;
            }
            
            // Sort objectives by number
            usort($goal_data['objectives'], function($a, $b) {
                return intval($a['number']) - intval($b['number']);
            });
            
            $workplan_data['goals'][] = $goal_data;
        }
        
        // Sort goals by letter
        usort($workplan_data['goals'], function($a, $b) {
            return strcmp($a['letter'], $b['letter']);
        });
        
        return $workplan_data;
    }

    /**
     * Build a cache of bimonthly references keyed by objectiveId_outputIndex
     */
    private function build_bimonthly_cache() {
        $cache = array();
        $bm_posts = get_posts(array('post_type' => 'bimonthly', 'posts_per_page' => -1, 'post_status' => 'publish'));

        foreach ($bm_posts as $bp) {
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

                    $pt_obj = get_post_type_object($p->post_type);
                    $pt_label = $pt_obj ? $pt_obj->labels->singular_name : $p->post_type;

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

                    $summary = '';
                    if ($p->post_type === 'bimonthly-highlight' && function_exists('get_field')) {
                        $summary = get_field('highlight_text', $p->ID) ?: '';
                    } else {
                        $summary = function_exists('get_field') ? (get_field('internal_reporting_summary', $p->ID) ?: '') : (get_post_meta($p->ID, 'internal_reporting_summary', true) ?: '');
                    }

                    $ref = array(
                        'post_title' => $p->post_title,
                        'post_type_label' => $pt_label,
                        'author' => get_the_author_meta('display_name', $p->post_author),
                        'date' => $date,
                        'summary' => $summary,
                    );

                    $outputs = isset($item['outputs']) && is_array($item['outputs']) ? $item['outputs'] : array();
                    if (empty($outputs) && !empty($item['objective_id'])) {
                        $outputs = array(array('objective_id' => $item['objective_id'], 'output_index' => isset($item['output_index']) ? $item['output_index'] : 0));
                    }

                    foreach ($outputs as $out) {
                        $key = intval($out['objective_id']) . '_' . intval($out['output_index']);
                        if (!isset($cache[$key])) $cache[$key] = array();
                        $cache[$key][] = $ref;
                    }
                }
            }
        }

        return $cache;
    }

    /**
     * Format bimonthly references as text for export
     */
    private function format_refs_for_export($refs) {
        if (empty($refs)) return '';
        $lines = array();
        foreach ($refs as $ref) {
            $line = $ref['post_type_label'] . ': ' . $ref['post_title'] . ' — ' . $ref['author'] . ', ' . $ref['date'];
            if (!empty($ref['summary'])) {
                $summary = $ref['summary'];
                if (strlen($summary) > 150) $summary = substr($summary, 0, 147) . '...';
                $line .= ' | ' . $summary;
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
    
    /**
     * Generate Excel-compatible XML file (SpreadsheetML format)
     */
    private function generate_excel_xml_file($data, $export_dir, $filename) {
        // Use .xml extension for SpreadsheetML format that Excel can open
        $filename .= '.xml';
        $file_path = $export_dir . $filename;
        
        // Create XML content for Excel file (SpreadsheetML format)
        $xml_content = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml_content .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml_content .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        $xml_content .= '    xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        $xml_content .= '    xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        $xml_content .= '    xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        $xml_content .= '    xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        
        // Add document properties
        $xml_content .= '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">' . "\n";
        $xml_content .= '<Title>' . htmlspecialchars($data['workplan']['title']) . '</Title>' . "\n";
        $xml_content .= '<Author>' . htmlspecialchars($data['workplan']['author']) . '</Author>' . "\n";
        $xml_content .= '<Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>' . "\n";
        $xml_content .= '</DocumentProperties>' . "\n";
        
        // Add styles
        $xml_content .= '<Styles>' . "\n";
        $xml_content .= '<Style ss:ID="Default" ss:Name="Normal">' . "\n";
        $xml_content .= '<Alignment ss:Vertical="Top"/>' . "\n";
        $xml_content .= '<Font ss:FontName="Calibri" ss:Size="11"/>' . "\n";
        $xml_content .= '</Style>' . "\n";
        $xml_content .= '<Style ss:ID="Header">' . "\n";
        $xml_content .= '<Font ss:Bold="1" ss:Size="11"/>' . "\n";
        $xml_content .= '<Interior ss:Color="#E0E0E0" ss:Pattern="Solid"/>' . "\n";
        $xml_content .= '<Alignment ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
        $xml_content .= '</Style>' . "\n";
        $xml_content .= '<Style ss:ID="WrapText">' . "\n";
        $xml_content .= '<Alignment ss:Vertical="Top" ss:WrapText="1"/>' . "\n";
        $xml_content .= '</Style>' . "\n";
        $xml_content .= '</Styles>' . "\n";
        
        // Add worksheet
        $xml_content .= '<Worksheet ss:Name="Work Plan">' . "\n";
        $xml_content .= '<Table>' . "\n";
        
        // Add column widths
        $xml_content .= '<Column ss:Width="80"/>' . "\n";  // Goal
        $xml_content .= '<Column ss:Width="200"/>' . "\n"; // Goal Description
        $xml_content .= '<Column ss:Width="80"/>' . "\n";  // Objective
        $xml_content .= '<Column ss:Width="200"/>' . "\n"; // Objective Description
        $xml_content .= '<Column ss:Width="150"/>' . "\n"; // Timeline
        //$xml_content .= '<Column ss:Width="150"/>' . "\n"; // Measurable Outcomes
        $xml_content .= '<Column ss:Width="200"/>' . "\n"; // Outputs
        
        // Add metadata rows
        $xml_content .= '<Row>' . "\n";
        $xml_content .= '<Cell ss:StyleID="Header"><Data ss:Type="String">Work Plan:</Data></Cell>' . "\n";
        $xml_content .= '<Cell ss:MergeAcross="5"><Data ss:Type="String">' . htmlspecialchars($data['workplan']['title']) . '</Data></Cell>' . "\n";
        $xml_content .= '</Row>' . "\n";
        
        $xml_content .= '<Row>' . "\n";
        $xml_content .= '<Cell ss:StyleID="Header"><Data ss:Type="String">Group:</Data></Cell>' . "\n";
        $xml_content .= '<Cell><Data ss:Type="String">' . htmlspecialchars($data['workplan']['group']) . '</Data></Cell>' . "\n";
        $xml_content .= '<Cell ss:StyleID="Header"><Data ss:Type="String">Grant Year:</Data></Cell>' . "\n";
        $xml_content .= '<Cell><Data ss:Type="String">' . htmlspecialchars($data['workplan']['grant_year']) . '</Data></Cell>' . "\n";
        $xml_content .= '<Cell ss:StyleID="Header"><Data ss:Type="String">Status:</Data></Cell>' . "\n";
        $xml_content .= '<Cell><Data ss:Type="String">' . htmlspecialchars($data['workplan']['internal_status']) . '</Data></Cell>' . "\n";
        $xml_content .= '</Row>' . "\n";
        
        // Empty row
        $xml_content .= '<Row></Row>' . "\n";
        
        // Add header row
        $xml_content .= '<Row>' . "\n";
        //$headers = array('Goal', 'Goal Description', 'Objective', 'Objective Description', 'Timeline', 'Measurable Outcomes', 'Outputs');
        $headers = array('Goal', 'Goal Description', 'Objective', 'Objective Description', 'Timeline', 'Outputs/Activities', 'Bi-Monthly References');

        foreach ($headers as $header) {
            $xml_content .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
        }
        $xml_content .= '</Row>' . "\n";
        
        // Add data rows
        foreach ($data['goals'] as $goal) {
            if (!empty($goal['objectives'])) {
                foreach ($goal['objectives'] as $objective) {
                    $outputs_text = '';
                    $refs_text = '';
                    if (!empty($objective['outputs'])) {
                        $output_strings = array();
                        $ref_strings = array();
                        foreach ($objective['outputs'] as $output) {
                            $letter = strtoupper($output['output_letter']);
                            $output_strings[] = $letter . '. ' . $output['output_description'];
                            $output_refs = $this->format_refs_for_export(isset($output['bimonthly_refs']) ? $output['bimonthly_refs'] : array());
                            if ($output_refs) {
                                $ref_strings[] = $letter . '. ' . $output_refs;
                            }
                        }
                        $outputs_text = implode("\n", $output_strings);
                        $refs_text = implode("\n\n", $ref_strings);
                    }
                    
                    $xml_content .= '<Row>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($goal['letter'] . '. ' . $goal['title']) . '</Data></Cell>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($goal['description']) . '</Data></Cell>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($objective['number'] . '. ' . $objective['title']) . '</Data></Cell>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($objective['description']) . '</Data></Cell>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($objective['timeline_description']) . '</Data></Cell>' . "\n";
                    //$xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($objective['measureable_outcomes']) . '</Data></Cell>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($outputs_text) . '</Data></Cell>' . "\n";
                    $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($refs_text) . '</Data></Cell>' . "\n";
                    $xml_content .= '</Row>' . "\n";
                }
            } else {
                $xml_content .= '<Row>' . "\n";
                $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($goal['letter'] . '. ' . $goal['title']) . '</Data></Cell>' . "\n";
                $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">' . htmlspecialchars($goal['description']) . '</Data></Cell>' . "\n";
                $xml_content .= '<Cell ss:StyleID="WrapText"><Data ss:Type="String">No objectives defined</Data></Cell>' . "\n";
                //$xml_content .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml_content .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml_content .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml_content .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml_content .= '<Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml_content .= '</Row>' . "\n";
            }
        }
        
        $xml_content .= '</Table>' . "\n";
        $xml_content .= '</Worksheet>' . "\n";
        $xml_content .= '</Workbook>';
        
        // Write file
        file_put_contents($file_path, $xml_content);
        
        return $this->create_download_link($file_path, $filename, 'application/vnd.ms-excel');
    }
    
    /**
     * Generate CSV file
     */
    private function generate_csv_file($data, $export_dir, $filename) {
        $filename .= '.csv';
        $file_path = $export_dir . $filename;
        
        $csv_handle = fopen($file_path, 'w');
        
        if (!$csv_handle) {
            throw new Exception('Could not create CSV file');
        }
        
        // Add BOM for UTF-8
        fwrite($csv_handle, "\xEF\xBB\xBF");
        
        // Add metadata rows
        fputcsv($csv_handle, array('Work Plan:', $data['workplan']['title']));
        fputcsv($csv_handle, array('Group:', $data['workplan']['group'], 'Grant Year:', $data['workplan']['grant_year']));
        fputcsv($csv_handle, array('Status:', $data['workplan']['internal_status'], 'Author:', $data['workplan']['author']));
        fputcsv($csv_handle, array()); // Empty row
        
        // Add header row
        //$headers = array('Goal', 'Goal Description', 'Objective', 'Objective Description', 'Timeline', 'Measurable Outcomes', 'Outputs');
        $headers = array('Goal', 'Goal Description', 'Objective', 'Objective Description', 'Timeline', 'Outputs/Activities', 'Bi-Monthly References');
        fputcsv($csv_handle, $headers);
        
        // Add data rows
        foreach ($data['goals'] as $goal) {
            if (!empty($goal['objectives'])) {
                foreach ($goal['objectives'] as $objective) {
                    $outputs_text = '';
                    $refs_text = '';
                    if (!empty($objective['outputs'])) {
                        $output_strings = array();
                        $ref_strings = array();
                        foreach ($objective['outputs'] as $output) {
                            $letter = strtoupper($output['output_letter']);
                            $output_strings[] = $letter . '. ' . $output['output_description'];
                            $output_refs = $this->format_refs_for_export(isset($output['bimonthly_refs']) ? $output['bimonthly_refs'] : array());
                            if ($output_refs) {
                                $ref_strings[] = $letter . '. ' . $output_refs;
                            }
                        }
                        $outputs_text = implode('; ', $output_strings);
                        $refs_text = implode(' | ', $ref_strings);
                    }
                    
                    $row = array(
                        $goal['letter'] . '. ' . $goal['title'],
                        $goal['description'],
                        $objective['number'] . '. ' . $objective['title'],
                        $objective['description'],
                        $objective['timeline_description'],
                        //$objective['measureable_outcomes'],
                        $outputs_text,
                        $refs_text
                    );
                    
                    fputcsv($csv_handle, $row);
                }
            } else {
                $row = array(
                    $goal['letter'] . '. ' . $goal['title'],
                    $goal['description'],
                    'No objectives defined',
                    //'',
                    '',
                    '',
                    '',
                    ''
                );
                
                fputcsv($csv_handle, $row);
            }
        }
        
        fclose($csv_handle);
        
        return $this->create_download_link($file_path, $filename, 'text/csv');
    }
    
    /**
     * Create download link
     */
    private function create_download_link($file_path, $filename, $mime_type) {
        $token = wp_generate_password(32, false);
        
        $file_info = array(
            'file_path' => $file_path,
            'filename' => $filename,
            'mime_type' => $mime_type
        );
        
        // Store file info in transient (expires in 1 hour)
        set_transient('wpm_export_' . $token, $file_info, HOUR_IN_SECONDS);
        
        $download_url = add_query_arg(array(
            'wpm_download' => '1',
            'token' => $token
        ), home_url());
        
        return array(
            'download_url' => $download_url,
            'filename' => $filename,
            'file_size' => filesize($file_path)
        );
    }
    
    /**
     * Get taxonomy names as string
     */
    private function get_taxonomy_names($post_id, $taxonomy) {
        $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
        return is_wp_error($terms) ? '' : implode(', ', $terms);
    }
}

// Initialize the export handler
new WPM_Export_Handler();