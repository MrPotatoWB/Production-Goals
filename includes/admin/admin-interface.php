<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('pg_debug_log')) {
    function pg_debug_log($message) {
        if (get_option('pg_enable_debug_log')) {
            if (is_array($message) || is_object($message)) {
                error_log('PG_DEBUG: ' . print_r($message, true));
            } else {
                error_log('PG_DEBUG: ' . $message);
            }
        }
    }
}

class Production_Goals_Admin {
    private $production_file_handler;
    private $plugin_slug = 'production-goals';

    public function __construct() {
        global $production_file_handler;
        if (!$production_file_handler instanceof Production_File_Handler) {
             error_log('Production Goals Error: $production_file_handler not initialized correctly.');
             add_action('admin_notices', function() {
                 echo '<div class="notice notice-error"><p>Production Goals Error: File Handler component failed to load. File operations may not work.</p></div>';
             });
             $this->production_file_handler = new stdClass();
        } else {
            $this->production_file_handler = $production_file_handler;
        }

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings')); // Register settings
        add_action('admin_enqueue_scripts', array($this, 'register_admin_assets'));
        add_action('wp_ajax_pg_add_project', array($this, 'ajax_add_project'));
        add_action('wp_ajax_pg_edit_project', array($this, 'ajax_edit_project'));
        add_action('wp_ajax_pg_delete_project', array($this, 'ajax_delete_project'));
        add_action('wp_ajax_pg_add_part', array($this, 'ajax_add_part'));
        add_action('wp_ajax_pg_edit_part', array($this, 'ajax_edit_part'));
        add_action('wp_ajax_pg_delete_part', array($this, 'ajax_delete_part'));
        add_action('wp_ajax_pg_start_project', array($this, 'ajax_start_project'));
        add_action('wp_ajax_pg_complete_project', array($this, 'ajax_complete_project'));
        add_action('wp_ajax_pg_delete_completed', array($this, 'ajax_delete_completed'));
        add_action('wp_ajax_pg_delete_download_log', array($this, 'ajax_delete_download_log'));
    }

    public function register_settings() {
        register_setting('production_goals_settings_group', 'pg_enable_debug_log', 'intval');

        // Assuming 'pg_settings_section' is the ID used in your Production_Goals_Settings class
        // If Production_Goals_Settings handles its own fields, this might need adjustment
        // or ideally be placed within that class's registration logic.
        add_settings_section(
            'pg_settings_debug_section',
            'Debugging',
            null, // Optional callback for section description
            'production-goals-settings' // Page slug
        );

        add_settings_field(
            'pg_enable_debug_log',
            'Enable Debug Logging',
            array($this, 'render_debug_log_checkbox'),
            'production-goals-settings', // Page slug
            'pg_settings_debug_section' // Section ID
        );
    }

    public function render_debug_log_checkbox() {
        $option = get_option('pg_enable_debug_log');
        echo '<input type="checkbox" id="pg_enable_debug_log" name="pg_enable_debug_log" value="1" ' . checked(1, $option, false) . ' />';
        echo '<label for="pg_enable_debug_log"> Enable detailed logging to the PHP error log for debugging purposes.</label>';
        echo '<p class="description">Warning: Debug logs might contain sensitive information. Only enable when necessary.</p>';
    }


    public function register_admin_assets($hook) {
        if (strpos($hook, 'production-goals') === false) {
            return;
        }

        wp_enqueue_style('production-goals-admin', PRODUCTION_GOALS_URL . 'assets/css/admin.css', array(), PRODUCTION_GOALS_VERSION);
        wp_enqueue_script('production-goals-admin', PRODUCTION_GOALS_URL . 'assets/js/admin.js', array('jquery', 'jquery-ui-core', 'jquery-ui-dialog'), PRODUCTION_GOALS_VERSION, true);
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();

        wp_localize_script('production-goals-admin', 'productionGoalsAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('production-goals-admin'),
            'deleteConfirm' => __('Are you sure you want to delete this item?', 'production-goals'),
            'deleteLogConfirm' => __('Are you sure you want to delete this download log entry?', 'production-goals'),
            'formProcessing' => __('Processing...', 'production-goals'),
            'successMessage' => __('Operation completed successfully!', 'production-goals'),
            'errorMessage' => __('An error occurred. Please try again.', 'production-goals')
        ));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Production Goals',
            'Production Goals',
            'manage_options',
            'production-goals',
            array($this, 'render_manage_projects_page'),
            'dashicons-chart-line',
            30
        );

        add_submenu_page(
            'production-goals',
            'Manage Projects',
            'Manage Projects',
            'manage_options',
            'production-goals',
            array($this, 'render_manage_projects_page')
        );

        add_submenu_page(
            'production-goals',
            'Goals & Submissions',
            'Goals & Submissions',
            'manage_options',
            'production-goals-goals',
            array($this, 'render_goals_page')
        );

        add_submenu_page(
            'production-goals',
            'Add New Project',
            'Add New Project',
            'manage_options',
            'production-goals-new',
            array($this, 'render_new_project_page')
        );

        add_submenu_page(
            'production-goals',
            'Settings',
            'Settings',
            'manage_options',
            'production-goals-settings',
            array($this, 'render_settings_page')
        );
    }

    private function get_security_levels() {
        if (is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'get_security_levels')) {
            $levels = $this->production_file_handler->get_security_levels();
        } else {
             $levels = array(
                 'wb1' => 'WB1 (Allows WB1, WB2, WB3)',
                 'wb2' => 'WB2 (Allows WB2, WB3)',
                 'wb3' => 'WB3 (Allows WB3 only)'
             );
        }
        return apply_filters('production_goals_security_levels', $levels);
    }

    private function render_security_level_dropdown($current_level = 'wb1') {
        echo '<div class="pg-form-row">';
        echo '<label for="security_level"><strong>Security Level:</strong></label>';
        echo '<select id="security_level" name="security_level" class="pg-select">';

        $security_levels = $this->get_security_levels();

        foreach ($security_levels as $level_key => $level_name) {
            $selected = ($current_level == $level_key) ? 'selected' : '';
            echo '<option value="' . esc_attr($level_key) . '" ' . $selected . '>' . esc_html($level_name) . '</option>';
        }

        echo '</select>';
        echo '<p class="pg-form-help">Select the minimum WB role required to download. WBAdmin and Administrators can always download.</p>';
        echo '</div>';
    }

    public function render_manage_projects_page() {
        global $wpdb;

        $projects_table = $wpdb->prefix . "production_projects";
        $parts_table = $wpdb->prefix . "production_parts";

        $projects = Production_Goals_DB::get_projects();
        $selected_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : (isset($projects[0]) ? $projects[0]->id : 0);
        $selected_project = $selected_project_id ? Production_Goals_DB::get_project($selected_project_id) : null;
        $parts = Production_Goals_DB::get_project_parts($selected_project_id);
        $active_parts = Production_Goals_DB::get_active_project_parts($selected_project_id);
        $active_project = !empty($active_parts);
        $materials = Production_Goals_DB::get_project_materials($selected_project_id);

        $project_file = null;
        if (is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'get_project_file')) {
            $project_file = $this->production_file_handler->get_project_file($selected_project_id);
        }

        echo '<div class="wrap pg-admin-wrap">';
        echo '<h1 class="wp-heading-inline">Manage Projects</h1>';

        echo '<div class="pg-project-selector-container">';
        echo '<form method="GET" id="project-selector-form">';
        echo '<input type="hidden" name="page" value="production-goals">';
        echo '<select name="project_id" id="project-selector" class="pg-select">';

        if (empty($projects)) {
            echo '<option value="0">No projects found</option>';
        } else {
            foreach ($projects as $project) {
                $is_active = $project->id == $selected_project_id ? 'selected' : '';
                $is_monthly = isset($project->is_monthly) && $project->is_monthly ? ' (Monthly)' : '';
                echo '<option value="' . esc_attr($project->id) . '" ' . $is_active . '>' . esc_html($project->name) . $is_monthly . '</option>';
            }
        }

        echo '</select>';
        echo '<button type="submit" class="button">Select Project</button>';
        echo '</form>';
        echo '</div>';

        if ($selected_project_id && $selected_project) {
            echo '<div class="pg-card pg-project-details-card">';
            echo '<div class="pg-card-header">';
            echo '<h2>Project Details: ' . esc_html($selected_project->name) . '</h2>';
            echo '<div class="pg-card-actions">';
            if ($active_project) {
                echo '<span class="pg-status-label pg-status-active">Active</span>';
            } else {
                echo '<span class="pg-status-label pg-status-inactive">Inactive</span>';
            }
            // Show monthly indicator if this is a monthly project
            if (isset($selected_project->is_monthly) && $selected_project->is_monthly) {
                echo ' <span class="pg-status-label pg-status-monthly">Monthly Recurring</span>';
            }
            echo '</div>';
            echo '</div>';

            echo '<div class="pg-card-content">';
            echo '<div class="pg-form-container">';
            echo '<form id="edit-project-form" enctype="multipart/form-data">';
            echo '<input type="hidden" name="project_id" value="' . esc_attr($selected_project_id) . '">';

            echo '<div class="pg-form-row">';
            echo '<label for="edit_project_name">Project Name:</label>';
            echo '<input type="text" id="edit_project_name" name="project_name" value="' . esc_attr($selected_project->name) . '" required>';
            echo '</div>';

            echo '<div class="pg-form-row">';
            echo '<label for="edit_project_url">Project URL:</label>';
            echo '<input type="url" id="edit_project_url" name="project_url" value="' . esc_attr($selected_project->url) . '" required>';
            echo '</div>';

            echo '<div class="pg-form-row">';
            echo '<label for="edit_project_material">Materials:</label>';
            echo '<input type="text" id="edit_project_material" name="project_material" value="' . esc_attr(implode(', ', $materials)) . '" placeholder="e.g., PLA, ABS">';
            echo '<p class="pg-form-help">Separate multiple materials with commas (e.g., PLA, ABS)</p>';
            echo '</div>';

            // Monthly recurring checkbox
            echo '<div class="pg-form-row">';
            echo '<label for="edit_is_monthly">Monthly Recurring Goal:</label>';
            echo '<input type="checkbox" id="edit_is_monthly" name="is_monthly" value="1" ' . (isset($selected_project->is_monthly) && $selected_project->is_monthly ? 'checked' : '') . '>';
            echo '<p class="pg-form-help">If checked, this project will automatically complete at the end of each month and start again.</p>';
            echo '</div>';

            echo '<div class="pg-form-row">';
            echo '<label for="edit_project_file">Project File (ZIP):</label>';
            echo '<input type="file" id="edit_project_file" name="project_file" accept=".zip">';

            if ($project_file) {
                $display_filename = !empty($project_file->original_filename) ?
                    esc_html($project_file->original_filename) :
                    esc_html($project_file->file_name);

                echo '<p class="pg-current-file">Current file: <strong>' . $display_filename . '</strong>';
                if (!empty($project_file->original_filename) && $project_file->file_name !== $project_file->original_filename) {
                    echo ' <span class="pg-file-note">(Stored securely with encryption)</span>';
                }
                echo '</p>';

                // Show encryption status
                $encryption_status = isset($project_file->encryption_status) ? $project_file->encryption_status : 'complete';
                
                $status_text = '';
                $status_class = '';
                
                switch ($encryption_status) {
                    case 'pending':
                        $status_text = 'Queued for encryption';
                        $status_class = 'pg-status-pending';
                        break;
                        
                    case 'processing':
                        $status_text = 'Encryption in progress';
                        $status_class = 'pg-status-processing';
                        break;
                        
                    case 'failed':
                        $status_text = 'Encryption failed';
                        $status_class = 'pg-status-failed';
                        break;
                        
                    case 'complete':
                    default:
                        $status_text = 'Ready for download';
                        $status_class = 'pg-status-complete';
                        break;
                }
                
                echo '<p class="pg-encryption-status ' . esc_attr($status_class) . '">Status: ' . esc_html($status_text) . '</p>';
                
                // Only show download links if encryption is complete
                if ($encryption_status === 'complete' && is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'get_download_url')) {
                    $download_url = $this->production_file_handler->get_download_url($project_file);
                    $shortcode = $this->production_file_handler->get_download_shortcode($project_file);

                    if ($download_url && $shortcode) {
                        echo '<div class="pg-file-actions">';
                        echo '<button type="button" class="button copy-to-clipboard" data-clipboard="' . esc_attr($download_url) . '">Copy Download URL</button>';
                        echo '<button type="button" class="button copy-to-clipboard" data-clipboard="' . esc_attr($shortcode) . '">Copy Shortcode</button>';
                        echo '<a href="' . esc_url($download_url) . '" target="_blank" class="button button-primary">Test Download</a>';
                        echo '</div>';
                    } else {
                         echo '<p class="pg-error-message">Could not generate download links. File handler might be misconfigured.</p>';
                    }
                } else if ($encryption_status === 'pending' || $encryption_status === 'processing') {
                    echo '<p class="pg-encryption-message">Download links will be available once encryption is complete.</p>';
                    
                    // Add a JavaScript refresh for status update
                    echo '<script>
                    jQuery(document).ready(function($) {
                        setTimeout(function() {
                            location.reload();
                        }, 10000); // Refresh page every 10 seconds to update status
                    });
                    </script>';
                } else if ($encryption_status === 'failed') {
                    echo '<p class="pg-error-message">Encryption failed. Please try uploading the file again or contact support.</p>';
                }
            } elseif ($project_file) {
                 echo '<p class="pg-error-message">File record exists, but download links cannot be generated. File handler might be misconfigured.</p>';
            }
            echo '</div>';

            $current_security_level = 'wb1';
            if ($project_file && !empty($project_file->allowed_roles) && is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'roles_to_security_level')) {
                $current_security_level = $this->production_file_handler->roles_to_security_level($project_file->allowed_roles);
            }

            if (get_option('pg_enable_debug_log') && $project_file) {
                echo '<div class="pg-debug-info" style="background: #f8f8f8; padding: 10px; margin: 10px 0; border-left: 4px solid #007cba; font-family: monospace;">';
                echo '<p><strong>Debug Info (Logging Enabled):</strong></p>';
                echo '<p>File ID: ' . esc_html($project_file->id ?? 'N/A') . '</p>';
                echo '<p>Token: ' . esc_html($project_file->random_token ?? 'N/A') . '</p>';
                echo '<p>Allowed Roles: ' . esc_html($project_file->allowed_roles ?? 'N/A') . '</p>';
                echo '<p>Inferred Security Level: ' . esc_html($current_security_level) . '</p>';
                echo '</div>';
            }

            $this->render_security_level_dropdown($current_security_level);

            echo '<div class="pg-form-actions">';
            echo '<button type="submit" class="button button-primary">Update Project</button>';
            echo '<button type="button" id="delete-project-btn" class="button button-danger">Delete Project</button>';
            echo '</div>';

            echo '</form>';
            echo '</div>';

            if ($project_file && is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'get_file_download_logs')) {
                echo '<div class="pg-section pg-stats-section">';
                echo '<h3>Download Statistics</h3>';
                echo '<div class="pg-stats-grid">';

                echo '<div class="pg-stat-item">';
                echo '<span class="pg-stat-label">Total Downloads:</span>';
                echo '<span class="pg-stat-value">' . esc_html($project_file->download_count ?? 0) . '</span>';
                echo '</div>';

                echo '<div class="pg-stat-item">';
                echo '<span class="pg-stat-label">Last Downloaded:</span>';
                echo '<span class="pg-stat-value">' . ($project_file->last_download ? esc_html(date('Y-m-d H:i', strtotime($project_file->last_download))) : 'Never') . '</span>';
                echo '</div>';

                echo '</div>';

                $logs = $this->production_file_handler->get_file_download_logs($project_file->id, 10);

                if (!empty($logs)) {
                    echo '<div class="pg-recent-downloads">';
                    echo '<h4>Recent Downloads</h4>';
                    echo '<table class="wp-list-table widefat fixed striped pg-download-logs-table">';
                    echo '<thead><tr><th>User</th><th>Date</th><th>Time</th><th>Actions</th></tr></thead>';
                    echo '<tbody>';

                    foreach ($logs as $log) {
                        $user_info = get_userdata($log->user_id);
                        $user_login = $user_info ? $user_info->user_login : 'Guest/Unknown (' . $log->user_id . ')';
                        $download_date = new DateTime($log->download_date);

                        echo '<tr data-log-id="' . esc_attr($log->id) . '">';
                        echo '<td>' . esc_html($user_login) . '</td>';
                        echo '<td>' . esc_html($download_date->format('Y-m-d')) . '</td>';
                        echo '<td>' . esc_html($download_date->format('H:i:s')) . '</td>';
                        echo '<td class="pg-actions-cell">';
                        echo '<button type="button" class="button button-small button-danger delete-download-log-btn" data-log-id="' . esc_attr($log->id) . '">Delete</button>';
                        echo '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                    echo '</div>';
                } else {
                    echo '<p>No recent download logs found.</p>';
                }
            }

            echo '</div>';
            echo '</div>';

            echo '<div class="pg-card pg-parts-card">';
            echo '<div class="pg-card-header">';
            echo '<h2>Parts Management</h2>';
            echo '<div class="pg-card-actions">';

            if (!$active_project) {
                echo '<form id="start-project-form" class="pg-inline-form">';
                echo '<input type="hidden" name="project_id" value="' . esc_attr($selected_project_id) . '">';
                echo '<button type="submit" class="button button-primary">Start Project</button>';
                echo '</form>';
            } else {
                echo '<form id="complete-project-form" class="pg-inline-form">';
                echo '<input type="hidden" name="project_id" value="' . esc_attr($selected_project_id) . '">';
                echo '<button type="submit" class="button button-primary">Mark as Complete</button>';
                echo '</form>';
            }

            echo '</div>';
            echo '</div>';

            echo '<div class="pg-card-content">';

            if (empty($parts)) {
                echo '<div class="pg-empty-message">No parts defined for this project. Add a part below.</div>';
            } else {
                echo '<div class="pg-parts-table-container">';
                echo '<table class="wp-list-table widefat fixed striped pg-parts-table">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>Part Name</th>';
                echo '<th>Goal</th>';
                echo '<th>Progress</th>';
                echo '<th>Length (m)</th>';
                echo '<th>Weight (g)</th>';
                echo '<th>Status</th>';
                echo '<th>Actions</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($parts as $part) {
                    $is_active = $part->start_date ? true : false;
                    $percentage = $part->goal > 0 ? min(100, round(($part->progress / $part->goal) * 100, 1)) : 0;

                    echo '<tr data-part-id="' . esc_attr($part->id) . '">';
                    echo '<td>' . esc_html($part->name) . '</td>';
                    echo '<td>' . esc_html($part->goal) . '</td>';
                    echo '<td class="pg-progress-cell">';
                    echo '<div class="pg-progress-text">' . esc_html($part->progress) . ' / ' . esc_html($part->goal) . ' (' . $percentage . '%)</div>';
                    echo '<div class="pg-progress-bar-container"><div class="pg-progress-bar" style="width:' . esc_attr($percentage) . '%;"></div></div>';
                    echo '</td>';
                    echo '<td>' . esc_html($part->estimated_length) . '</td>';
                    echo '<td>' . esc_html($part->estimated_weight) . '</td>';
                    echo '<td>' . ($is_active ? '<span class="pg-status-label pg-status-active">Active</span>' : '<span class="pg-status-label pg-status-inactive">Inactive</span>') . '</td>';
                    echo '<td class="pg-actions-cell">';
                    echo '<button type="button" class="button button-small edit-part-btn" data-part-id="' . esc_attr($part->id) . '" data-part-name="' . esc_attr($part->name) . '" data-part-goal="' . esc_attr($part->goal) . '" data-part-length="' . esc_attr($part->estimated_length) . '" data-part-weight="' . esc_attr($part->estimated_weight) . '">Edit</button>';
                    echo '<button type="button" class="button button-small button-danger delete-part-btn" data-part-id="' . esc_attr($part->id) . '">Delete</button>';

                    if ($is_active) {
                        echo '<button type="button" class="button button-small view-contributions-btn" data-part-id="' . esc_attr($part->id) . '">View Contributions</button>';
                    }

                    echo '</td>';
                    echo '</tr>';

                    if ($is_active) {
                        echo '<tr class="pg-contributions-row" id="contributions-' . esc_attr($part->id) . '" style="display:none;">';
                        echo '<td colspan="7">';
                        echo '<div class="pg-contributions-container">';
                        echo '<h4>User Contributions for Current Goal</h4>';

                        $user_contributions = $wpdb->get_results($wpdb->prepare(
                            "SELECT user_id, SUM(quantity) as total
                             FROM {$wpdb->prefix}production_submissions
                             WHERE part_id = %d AND created_at >= %s
                             GROUP BY user_id
                             ORDER BY total DESC",
                            $part->id, $part->start_date ?: '1970-01-01 00:00:00'
                        ));

                        if (!empty($user_contributions)) {
                            echo '<table class="pg-contributions-table">';
                            echo '<thead><tr><th>User</th><th>Total Contributions</th></tr></thead>';
                            echo '<tbody>';

                            foreach ($user_contributions as $contribution) {
                                $user_info = get_userdata($contribution->user_id);
                                if ($user_info) {
                                    echo '<tr>';
                                    echo '<td>' . esc_html($user_info->user_login) . '</td>';
                                    echo '<td>' . esc_html($contribution->total) . '</td>';
                                    echo '</tr>';
                                }
                            }

                            echo '</tbody></table>';
                        } else {
                            echo '<p>No contributions yet.</p>';
                        }

                        echo '</div>';
                        echo '</td>';
                        echo '</tr>';
                    }
                }

                echo '</tbody>';
                echo '</table>';
                echo '</div>';
            }

            echo '<div class="pg-form-container pg-add-part-container">';
            echo '<h3>Add New Part</h3>';
            echo '<form id="add-part-form">';
            echo '<input type="hidden" name="project_id" value="' . esc_attr($selected_project_id) . '">';

            echo '<div class="pg-form-row">';
            echo '<label for="part_name">Part Name:</label>';
            echo '<input type="text" id="part_name" name="part_name" required>';
            echo '</div>';

            echo '<div class="pg-form-row">';
            echo '<label for="part_goal">Goal:</label>';
            echo '<input type="number" id="part_goal" name="part_goal" min="0" value="0" required>';
            echo '</div>';

            echo '<div class="pg-form-row">';
            echo '<label for="estimated_length">Estimated Length (m):</label>';
            echo '<input type="number" id="estimated_length" name="estimated_length" step="0.01" min="0" value="0" required>';
            echo '</div>';

            echo '<div class="pg-form-row">';
            echo '<label for="estimated_weight">Estimated Weight (g):</label>';
            echo '<input type="number" id="estimated_weight" name="estimated_weight" step="0.01" min="0" value="0" required>';
            echo '</div>';

            echo '<div class="pg-form-actions">';
            echo '<button type="submit" class="button button-primary">Add Part</button>';
            echo '</div>';

            echo '</form>';
            echo '</div>';

            echo '</div>';
            echo '</div>';

            echo '<div class="pg-card pg-shortcodes-card">';
            echo '<div class="pg-card-header">';
            echo '<h2>Available Shortcodes</h2>';
            echo '</div>';

            echo '<div class="pg-card-content">';
            echo '<div class="pg-shortcodes-grid">';

            echo '<div class="pg-shortcode-item">';
            echo '<h4>[production_goal id="' . esc_attr($selected_project_id) . '"]</h4>';
            echo '<p>Displays this project\'s progress and allows user submissions.</p>';
            echo '<button type="button" class="button copy-to-clipboard" data-clipboard="[production_goal id="' . esc_attr($selected_project_id) . '"]">Copy</button>';
            echo '</div>';

            $common_shortcodes = array(
                '[my_projects]' => 'Displays a user interface for managing contributions',
                '[all_projects]' => 'Displays all projects with search and pagination',
                '[most_unfulfilled_goals]' => 'Displays projects needing contributions most'
            );

            foreach ($common_shortcodes as $shortcode => $description) {
                echo '<div class="pg-shortcode-item">';
                echo '<h4>' . esc_html($shortcode) . '</h4>';
                echo '<p>' . esc_html($description) . '</p>';
                echo '<button type="button" class="button copy-to-clipboard" data-clipboard="' . esc_attr($shortcode) . '">Copy</button>';
                echo '</div>';
            }

            echo '</div>';
            echo '</div>';
            echo '</div>';

        } else {
            echo '<div class="pg-card pg-empty-card">';
            echo '<div class="pg-card-content pg-centered-content">';

            if (empty($projects)) {
                echo '<p>No projects found. Please create a new project.</p>';
                echo '<a href="' . admin_url('admin.php?page=production-goals-new') . '" class="button button-primary">Create New Project</a>';
            } else {
                echo '<p>Please select a project from the dropdown above.</p>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '<div id="edit-part-modal" class="pg-modal" style="display: none;">';
        echo '<div class="pg-modal-content">';
        echo '<span class="pg-modal-close">×</span>';
        echo '<h3>Edit Part</h3>';

        echo '<form id="edit-part-form">';
        echo '<input type="hidden" name="part_id" id="edit_part_id">';

        echo '<div class="pg-form-row">';
        echo '<label for="edit_part_name">Part Name:</label>';
        echo '<input type="text" id="edit_part_name" name="part_name" required>';
        echo '</div>';

        echo '<div class="pg-form-row">';
        echo '<label for="edit_part_goal">Goal:</label>';
        echo '<input type="number" id="edit_part_goal" name="part_goal" min="0" required>';
        echo '</div>';

        echo '<div class="pg-form-row">';
        echo '<label for="edit_estimated_length">Estimated Length (m):</label>';
        echo '<input type="number" id="edit_estimated_length" name="estimated_length" step="0.01" min="0" required>';
        echo '</div>';

        echo '<div class="pg-form-row">';
        echo '<label for="edit_estimated_weight">Estimated Weight (g):</label>';
        echo '<input type="number" id="edit_estimated_weight" name="estimated_weight" step="0.01" min="0" required>';
        echo '</div>';

        echo '<div class="pg-form-actions">';
        echo '<button type="submit" class="button button-primary">Save Changes</button>';
        echo '<button type="button" class="button pg-modal-cancel">Cancel</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '</div>';

        echo '<style>
        .pg-file-note { font-style: italic; color: #6c757d; font-size: 0.9em; }
        .pg-debug-info { font-size: 12px; line-height: 1.4; }
        .pg-debug-info p { margin: 5px 0; }
        .pg-error-message { color: red; font-weight: bold; }
        .pg-encryption-status {
            font-weight: bold;
            padding: 8px;
            border-radius: 4px;
            display: inline-block;
            margin: 10px 0;
        }
        .pg-status-pending {
            background-color: #fffde7;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .pg-status-processing {
            background-color: #e3f2fd;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .pg-status-complete {
            background-color: #e8f5e9;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .pg-status-failed {
            background-color: #ffebee;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .pg-encryption-message {
            font-style: italic;
            color: #0c5460;
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            padding: 8px;
            border-radius: 4px;
        }
        .pg-status-monthly {
            background-color: #ffd700; /* Ukraine yellow */
            color: #333;
            border: 1px solid #e6c200;
        }
        </style>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#project-selector').on('change', function() {
                $('#project-selector-form').submit();
            });

            $('#edit-project-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const formData = new FormData(form[0]);
                formData.append('action', 'pg_edit_project');
                formData.append('nonce', productionGoalsAdmin.nonce);
                
                // Handle checkbox properly for is_monthly
                if (!form.find('#edit_is_monthly').is(':checked')) {
                    formData.set('is_monthly', '0');
                }

                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    beforeSend: function() {
                        form.find('button[type="submit"]').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || productionGoalsAdmin.successMessage, 'success');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            form.find('button[type="submit"]').prop('disabled', false).text('Update Project');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        form.find('button[type="submit"]').prop('disabled', false).text('Update Project');
                    }
                });
            });

            $('#delete-project-btn').on('click', function() {
                if (!confirm(productionGoalsAdmin.deleteConfirm)) return;
                const projectId = $('#edit-project-form input[name="project_id"]').val();
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: { action: 'pg_delete_project', nonce: productionGoalsAdmin.nonce, project_id: projectId },
                    beforeSend: function() {
                        $('#delete-project-btn').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Project deleted successfully!', 'success');
                            setTimeout(function() { location.href = 'admin.php?page=production-goals'; }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            $('#delete-project-btn').prop('disabled', false).text('Delete Project');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        $('#delete-project-btn').prop('disabled', false).text('Delete Project');
                    }
                });
            });

            $('#add-part-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const formData = $(this).serialize();
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData + '&action=pg_add_part&nonce=' + productionGoalsAdmin.nonce,
                    beforeSend: function() {
                        form.find('button[type="submit"]').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Part added successfully!', 'success');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            form.find('button[type="submit"]').prop('disabled', false).text('Add Part');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        form.find('button[type="submit"]').prop('disabled', false).text('Add Part');
                    }
                });
            });

            $('.edit-part-btn').on('click', function() {
                $('#edit_part_id').val($(this).data('part-id'));
                $('#edit_part_name').val($(this).data('part-name'));
                $('#edit_part_goal').val($(this).data('part-goal'));
                $('#edit_estimated_length').val($(this).data('part-length'));
                $('#edit_estimated_weight').val($(this).data('part-weight'));
                $('#edit-part-modal').show();
            });

            $('#edit-part-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const formData = $(this).serialize();
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData + '&action=pg_edit_part&nonce=' + productionGoalsAdmin.nonce,
                    beforeSend: function() {
                        form.find('button[type="submit"]').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Part updated successfully!', 'success');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            form.find('button[type="submit"]').prop('disabled', false).text('Save Changes');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        form.find('button[type="submit"]').prop('disabled', false).text('Save Changes');
                    },
                    complete: function() { $('#edit-part-modal').hide(); }
                });
            });

            $('.delete-part-btn').on('click', function() {
                if (!confirm(productionGoalsAdmin.deleteConfirm)) return;
                const button = $(this);
                const partId = button.data('part-id');
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: { action: 'pg_delete_part', nonce: productionGoalsAdmin.nonce, part_id: partId },
                    beforeSend: function() {
                        button.prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Part deleted successfully!', 'success');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            button.prop('disabled', false).text('Delete');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        button.prop('disabled', false).text('Delete');
                    }
                });
            });

            $('#start-project-form').on('submit', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to start this project? This will set the start date for all parts with goals.')) return;
                const form = $(this);
                const formData = form.serialize();
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData + '&action=pg_start_project&nonce=' + productionGoalsAdmin.nonce,
                    beforeSend: function() {
                        form.find('button[type="submit"]').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Project started successfully!', 'success');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            form.find('button[type="submit"]').prop('disabled', false).text('Start Project');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        form.find('button[type="submit"]').prop('disabled', false).text('Start Project');
                    }
                });
            });

            $('#complete-project-form').on('submit', function(e) {
                e.preventDefault();
                if (!confirm('Are you sure you want to mark this project as complete? This will reset progress for current goals.')) return;
                const form = $(this);
                const formData = form.serialize();
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData + '&action=pg_complete_project&nonce=' + productionGoalsAdmin.nonce,
                    beforeSend: function() {
                        form.find('button[type="submit"]').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Project marked as complete successfully!', 'success');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            form.find('button[type="submit"]').prop('disabled', false).text('Mark as Complete');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        form.find('button[type="submit"]').prop('disabled', false).text('Mark as Complete');
                    }
                });
            });

            $('.view-contributions-btn').on('click', function() {
                const partId = $(this).data('part-id');
                $('#contributions-' + partId).toggle();
                $(this).text($('#contributions-' + partId).is(':visible') ? 'Hide Contributions' : 'View Contributions');
            });

            $('.pg-download-logs-table').on('click', '.delete-download-log-btn', function() {
                if (!confirm(productionGoalsAdmin.deleteLogConfirm)) return;
                const button = $(this);
                const logId = button.data('log-id');
                const row = button.closest('tr');

                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: { action: 'pg_delete_download_log', nonce: productionGoalsAdmin.nonce, log_id: logId },
                    beforeSend: function() {
                        button.prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Log entry deleted successfully!', 'success');
                            row.fadeOut('slow', function() {
                                $(this).remove();
                                if ($('.pg-download-logs-table tbody tr').length === 0) {
                                    $('.pg-recent-downloads').html('<p>No recent download logs found.</p>');
                                }
                            });
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            button.prop('disabled', false).text('Delete');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        button.prop('disabled', false).text('Delete');
                    }
                });
            });

            $('.pg-modal-close, .pg-modal-cancel').on('click', function() { $('.pg-modal').hide(); });
            $(window).on('click', function(event) { if ($(event.target).hasClass('pg-modal')) $('.pg-modal').hide(); });

            $('.copy-to-clipboard').on('click', function() {
                const textToCopy = $(this).data('clipboard');
                const tempTextarea = document.createElement('textarea');
                tempTextarea.value = textToCopy;
                document.body.appendChild(tempTextarea);
                tempTextarea.select();
                document.execCommand('copy');
                document.body.removeChild(tempTextarea);
                const originalText = $(this).text();
                $(this).text('Copied!');
                setTimeout(() => { $(this).text(originalText); }, 2000);
            });

            function showNotice(message, type) {
                $('.pg-admin-notice').remove();
                let noticeClass = 'pg-admin-notice notice notice-' + type;
                let noticeContent = message;
                if (type === 'processing') {
                    noticeClass = 'pg-admin-notice notice notice-info pg-processing-notice';
                    noticeContent = '<span class="pg-spinner"></span> Processing...';
                }
                const noticeElement = $('<div class="' + noticeClass + '"><p>' + noticeContent + '</p></div>');
                $('.pg-admin-wrap').prepend(noticeElement);
                if (type === 'success' || type === 'error') { // Keep error messages visible longer
                    setTimeout(function() { noticeElement.fadeOut('slow', function() { $(this).remove(); }); }, 5000);
                }
            }
        });
        </script>
        <?php
    }

    public function render_new_project_page() {
        echo '<div class="wrap pg-admin-wrap">';
        echo '<h1 class="wp-heading-inline">Add New Project</h1>';

        echo '<div class="pg-card">';
        echo '<div class="pg-card-header">';
        echo '<h2>Project Details</h2>';
        echo '</div>';

        echo '<div class="pg-card-content">';
        echo '<form id="add-project-form" enctype="multipart/form-data">';

        echo '<div class="pg-form-row">';
        echo '<label for="project_name">Project Name:</label>';
        echo '<input type="text" id="project_name" name="project_name" placeholder="New Project Name" required>';
        echo '</div>';

        echo '<div class="pg-form-row">';
        echo '<label for="project_url">Project URL:</label>';
        echo '<input type="url" id="project_url" name="project_url" placeholder="https://example.com/project-page" required>';
        echo '</div>';

        echo '<div class="pg-form-row">';
        echo '<label for="project_material">Materials:</label>';
        echo '<input type="text" id="project_material" name="project_material" placeholder="Material (e.g., PLA, ABS)" required>';
        echo '<p class="pg-form-help">Separate multiple materials with commas (e.g., PLA, ABS)</p>';
        echo '</div>';
        
        // Monthly recurring checkbox
        echo '<div class="pg-form-row">';
        echo '<label for="is_monthly">Monthly Recurring Goal:</label>';
        echo '<input type="checkbox" id="is_monthly" name="is_monthly" value="1">';
        echo '<p class="pg-form-help">If checked, this project will automatically complete at the end of each month and start again.</p>';
        echo '</div>';

        echo '<div class="pg-form-row">';
        echo '<label for="project_file">Project File (ZIP):</label>';
        echo '<input type="file" id="project_file" name="project_file" accept=".zip">';
        echo '<p class="pg-form-help">Upload a ZIP file containing project files. The original filename will be preserved for downloads.</p>';
        echo '</div>';

        $this->render_security_level_dropdown('wb1');

        echo '<div class="pg-form-actions">';
        echo '<button type="submit" class="button button-primary">Create Project</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="pg-card pg-info-card">';
        echo '<div class="pg-card-header">';
        echo '<h2>Adding Project Parts</h2>';
        echo '</div>';

        echo '<div class="pg-card-content">';
        echo '<p>After creating your project, you\'ll be redirected to the project management page where you can:</p>';
        echo '<ul class="pg-info-list">';
        echo '<li>Add parts with specific goals</li>';
        echo '<li>Set estimated length and weight values for each part</li>';
        echo '<li>Activate the project to make it available for contributions</li>';
        echo '<li>Track user submissions and progress</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#add-project-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const formData = new FormData(form[0]);
                formData.append('action', 'pg_add_project');
                formData.append('nonce', productionGoalsAdmin.nonce);
                
                // Handle checkbox properly for is_monthly
                if (!form.find('#is_monthly').is(':checked')) {
                    formData.append('is_monthly', '0');
                }

                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    beforeSend: function() {
                        form.find('button[type="submit"]').prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Project created successfully!', 'success');
                            setTimeout(function() {
                                location.href = 'admin.php?page=production-goals&project_id=' + response.data.project_id;
                            }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            form.find('button[type="submit"]').prop('disabled', false).text('Create Project');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        form.find('button[type="submit"]').prop('disabled', false).text('Create Project');
                    }
                });
            });

            function showNotice(message, type) {
                $('.pg-admin-notice').remove();
                let noticeClass = 'pg-admin-notice notice notice-' + type;
                let noticeContent = message;
                 if (type === 'processing') {
                    noticeClass = 'pg-admin-notice notice notice-info pg-processing-notice';
                    noticeContent = '<span class="pg-spinner"></span> Processing...';
                }
                const noticeElement = $('<div class="' + noticeClass + '"><p>' + noticeContent + '</p></div>');
                $('.pg-admin-wrap').prepend(noticeElement);
                 if (type === 'success' || type === 'error') {
                    setTimeout(function() { noticeElement.fadeOut('slow', function() { $(this).remove(); }); }, 5000);
                }
            }
        });
        </script>
        <?php
    }

    public function render_goals_page() {
        global $wpdb;

        $completed_table = $wpdb->prefix . "production_completed";
        $completed_goals = $wpdb->get_results("SELECT * FROM $completed_table ORDER BY completed_date DESC");

        echo '<div class="wrap pg-admin-wrap">';
        echo '<h1 class="wp-heading-inline">Goals & Submissions</h1>';

        echo '<div class="pg-card">';
        echo '<div class="pg-card-header">';
        echo '<h2>Completed Goals</h2>';
        echo '</div>';

        echo '<div class="pg-card-content">';

        if (empty($completed_goals)) {
            echo '<p>No completed goals found. When you mark a project as complete, it will appear here.</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Project</th>';
            echo '<th>Completed Date</th>';
            echo '<th>Actions</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($completed_goals as $goal) {
                echo '<tr data-id="' . esc_attr($goal->id) . '">';
                echo '<td>' . esc_html($goal->project_name) . '</td>';
                echo '<td>' . esc_html(date('Y-m-d H:i', strtotime($goal->completed_date))) . '</td>';
                echo '<td class="pg-actions-cell">';
                echo '<button type="button" class="button button-small view-completed-btn" data-id="' . esc_attr($goal->id) . '">View Details</button>';
                echo '<button type="button" class="button button-small button-danger delete-completed-btn" data-id="' . esc_attr($goal->id) . '">Delete Record</button>';
                echo '</td>';
                echo '</tr>';

                echo '<tr class="pg-completed-row" id="completed-' . esc_attr($goal->id) . '" style="display:none;">';
                echo '<td colspan="3">';
                echo '<div class="pg-completed-container">';

                $user_contributions = json_decode($goal->user_contributions, true);

                if (!empty($user_contributions)) {
                    foreach ($user_contributions as $part_data) {
                        echo '<div class="pg-completed-part">';
                        echo '<h4>' . esc_html($part_data['part_name'] ?? 'Unknown Part') . '</h4>';
                        echo '<p>Goal: ' . esc_html($part_data['goal'] ?? 'N/A') . ', Progress: ' . esc_html($part_data['progress'] ?? 'N/A') . '</p>';

                        if (!empty($part_data['contributions'])) {
                            echo '<table class="pg-contributions-table">';
                            echo '<thead><tr><th>User</th><th>Contributions</th></tr></thead>';
                            echo '<tbody>';

                            foreach ($part_data['contributions'] as $contribution) {
                                echo '<tr>';
                                echo '<td>' . esc_html($contribution['user'] ?? 'Unknown User') . '</td>';
                                echo '<td>' . esc_html($contribution['total'] ?? 'N/A') . '</td>';
                                echo '</tr>';
                            }

                            echo '</tbody></table>';
                        } else {
                            echo '<p>No user contributions recorded for this part.</p>';
                        }

                        echo '</div>';
                    }
                } else {
                    echo '<p>No detailed contribution information available for this completed goal.</p>';
                }

                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        echo '</div>';
        echo '</div>';

        echo '</div>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.view-completed-btn').on('click', function() {
                const id = $(this).data('id');
                $('#completed-' + id).toggle();
                $(this).text($('#completed-' + id).is(':visible') ? 'Hide Details' : 'View Details');
            });

            $('.delete-completed-btn').on('click', function() {
                if (!confirm(productionGoalsAdmin.deleteConfirm)) return;
                const button = $(this);
                const id = button.data('id');
                $.ajax({
                    url: productionGoalsAdmin.ajaxUrl,
                    type: 'POST',
                    data: { action: 'pg_delete_completed', nonce: productionGoalsAdmin.nonce, completed_id: id },
                    beforeSend: function() {
                        button.prop('disabled', true).text(productionGoalsAdmin.formProcessing);
                        showNotice('', 'processing');
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotice(response.data.message || 'Record deleted successfully!', 'success');
                            setTimeout(function() {
                                $('tr[data-id="' + id + '"], #completed-' + id).fadeOut('slow', function() {
                                    $(this).remove();
                                    if ($('tr[data-id]').length === 0) {
                                        $('.pg-card-content').html('<p>No completed goals found. When you mark a project as complete, it will appear here.</p>');
                                    }
                                });
                            }, 1000);
                        } else {
                            showNotice(response.data.message || productionGoalsAdmin.errorMessage, 'error');
                            button.prop('disabled', false).text('Delete Record');
                        }
                    },
                    error: function() {
                        showNotice(productionGoalsAdmin.errorMessage, 'error');
                        button.prop('disabled', false).text('Delete Record');
                    }
                });
            });

            function showNotice(message, type) {
                $('.pg-admin-notice').remove();
                let noticeClass = 'pg-admin-notice notice notice-' + type;
                let noticeContent = message;
                 if (type === 'processing') {
                    noticeClass = 'pg-admin-notice notice notice-info pg-processing-notice';
                    noticeContent = '<span class="pg-spinner"></span> Processing...';
                }
                const noticeElement = $('<div class="' + noticeClass + '"><p>' + noticeContent + '</p></div>');
                $('.pg-admin-wrap').prepend(noticeElement);
                 if (type === 'success' || type === 'error') {
                    setTimeout(function() { noticeElement.fadeOut('slow', function() { $(this).remove(); }); }, 5000);
                }
            }
        });
        </script>
        <?php
    }

    public function render_settings_page() {
        echo '<div class="wrap pg-admin-wrap">';
        echo '<h1>Production Goals Settings</h1>';

        if (!class_exists('Production_Goals_Settings')) {
             $settings_file = PRODUCTION_GOALS_DIR . 'includes/admin/settings.php';
             if (file_exists($settings_file)) {
                 require_once $settings_file;
             } else {
                 echo '<div class="notice notice-error"><p>Error: Settings file not found.</p></div>';
                 echo '</div>';
                 return;
             }
        }

        if (class_exists('Production_Goals_Settings')) {
            $settings_instance = new Production_Goals_Settings();

            echo '<form method="post" action="options.php">';

            settings_fields('production_goals_settings_group');

            echo '<h2>Main Settings</h2>';
            do_settings_sections('production-goals-settings');

            echo '<h2>Debugging</h2>';
            do_settings_sections('production-goals-settings'); // Render our debug section

            submit_button();

            echo '</form>';

            // If the original class has its own render method, we might call it here
            // But the standard way is using settings_fields and do_settings_sections
            // $settings_instance->render_settings_page(); // This might duplicate fields if not careful

        } else {
             echo '<div class="notice notice-error"><p>Error: Settings class could not be loaded.</p></div>';
        }

        echo '</div>';
    }


    public function ajax_add_project() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        $materials_table = $wpdb->prefix . 'project_materials';

        $project_name = isset($_POST['project_name']) ? sanitize_text_field($_POST['project_name']) : '';
        $project_url = isset($_POST['project_url']) ? esc_url_raw($_POST['project_url']) : '';
        $materials_raw = isset($_POST['project_material']) ? array_map('trim', explode(',', sanitize_text_field($_POST['project_material']))) : [];
        $is_monthly = isset($_POST['is_monthly']) ? 1 : 0;

        if (empty($project_name) || empty($project_url)) {
             wp_send_json_error(array('message' => 'Project Name and URL are required.'));
        }

        $insert_result = $wpdb->insert($projects_table, array(
            'name' => $project_name,
            'url' => $project_url,
            'is_monthly' => $is_monthly,
            'created_at' => current_time('mysql')
        ));

        if ($insert_result === false) {
            wp_send_json_error(array('message' => 'Database error adding project: ' . $wpdb->last_error));
        }
        $project_id = $wpdb->insert_id;

        foreach ($materials_raw as $material) {
            if (!empty($material)) {
                $wpdb->insert($materials_table, array(
                    'project_id' => $project_id,
                    'material' => $material
                ));
            }
        }

        if (isset($_POST['security_level'])) {
            $_POST['security_level'] = sanitize_text_field($_POST['security_level']);
        }

        do_action('production_project_saved', $project_id, false);

        wp_send_json_success(array(
            'message' => 'Project added successfully!',
            'project_id' => $project_id
        ));
    }

      public function ajax_edit_project() {
        pg_debug_log("--- AJAX: pg_edit_project started ---");
        pg_debug_log("AJAX POST data: " . print_r($_POST, true));
        pg_debug_log("AJAX FILES data: " . print_r($_FILES, true));

        pg_debug_log("AJAX: Checking nonce...");
        if (!check_ajax_referer('production-goals-admin', 'nonce', false)) {
             pg_debug_log("AJAX ERROR: Nonce check failed.");
             wp_send_json_error(array('message' => 'Nonce verification failed. Please refresh and try again.'));
             return;
        }
        pg_debug_log("AJAX: Nonce check passed.");

        pg_debug_log("AJAX: Checking permissions...");
        if (!current_user_can('manage_options')) {
            pg_debug_log("AJAX ERROR: Permission denied.");
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        pg_debug_log("AJAX: Permission check passed.");

        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        $materials_table = $wpdb->prefix . 'project_materials';

        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        pg_debug_log("AJAX: Processing Project ID: " . $project_id);
        if ($project_id <= 0) {
             pg_debug_log("AJAX ERROR: Invalid Project ID.");
             wp_send_json_error(array('message' => 'Invalid Project ID.'));
             return;
        }

        $project_name = isset($_POST['project_name']) ? sanitize_text_field($_POST['project_name']) : '';
        $project_url = isset($_POST['project_url']) ? esc_url_raw($_POST['project_url']) : '';
        $materials_raw = isset($_POST['project_material']) ? array_map('trim', explode(',', sanitize_text_field($_POST['project_material']))) : [];
        $is_monthly = isset($_POST['is_monthly']) ? 1 : 0;

        pg_debug_log("AJAX: Processing Name: '$project_name', URL: '$project_url', Monthly: '$is_monthly'");

         if (empty($project_name) || empty($project_url)) {
             pg_debug_log("AJAX ERROR: Project Name or URL is empty.");
             wp_send_json_error(array('message' => 'Project Name and URL are required.'));
             return;
        }

        pg_debug_log("AJAX: Attempting project details update in DB...");
        $update_result = $wpdb->update($projects_table, array(
            'name' => $project_name,
            'url' => $project_url,
            'is_monthly' => $is_monthly
        ), array('id' => $project_id));

        if ($update_result === false) {
            pg_debug_log("AJAX ERROR: Database error updating project: " . $wpdb->last_error);
            wp_send_json_error(array('message' => 'Database error updating project: ' . $wpdb->last_error));
            return;
        }
        pg_debug_log("AJAX: Project details DB update result: " . $update_result);

        pg_debug_log("AJAX: Updating materials...");
        $wpdb->delete($materials_table, array('project_id' => $project_id));
        foreach ($materials_raw as $material) {
            if (!empty($material)) {
                $wpdb->insert($materials_table, array(
                    'project_id' => $project_id,
                    'material' => $material
                ));
            }
        }
        pg_debug_log("AJAX: Materials updated.");

        if (isset($_POST['security_level'])) {
            pg_debug_log("AJAX: Security level provided in POST: " . sanitize_text_field($_POST['security_level']));
        } else {
             pg_debug_log("AJAX: No security level provided in POST.");
        }

        pg_debug_log("AJAX: About to call do_action('production_project_saved') for Project ID: $project_id");
        do_action('production_project_saved', $project_id, true);
        pg_debug_log("AJAX: Returned from do_action('production_project_saved')");

        pg_debug_log("AJAX: Sending success response.");
        wp_send_json_success(array(
            'message' => 'Project updated successfully!'
        ));

        pg_debug_log("--- AJAX: pg_edit_project finished successfully ---");
    }

    public function ajax_add_part() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";

        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        $part_name = isset($_POST['part_name']) ? sanitize_text_field($_POST['part_name']) : '';
        $part_goal = isset($_POST['part_goal']) ? intval($_POST['part_goal']) : 0;
        $estimated_length = isset($_POST['estimated_length']) ? floatval($_POST['estimated_length']) : 0;
        $estimated_weight = isset($_POST['estimated_weight']) ? floatval($_POST['estimated_weight']) : 0;

        if ($project_id <= 0 || empty($part_name)) {
             wp_send_json_error(array('message' => 'Invalid Project ID or Part Name.'));
        }

        $insert_result = $wpdb->insert($parts_table, array(
            'project_id' => $project_id,
            'name' => $part_name,
            'goal' => $part_goal,
            'progress' => 0,
            'lifetime_total' => 0,
            'estimated_length' => $estimated_length,
            'estimated_weight' => $estimated_weight
        ));

        if ($insert_result === false) {
            wp_send_json_error(array('message' => 'Database error adding part: ' . $wpdb->last_error));
        }

        wp_send_json_success(array(
            'message' => 'Part added successfully!'
        ));
    }

    public function ajax_edit_part() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";

        $part_id = isset($_POST['part_id']) ? intval($_POST['part_id']) : 0;
        if ($part_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Part ID.'));
        }

        $part_name = isset($_POST['part_name']) ? sanitize_text_field($_POST['part_name']) : '';
        $part_goal = isset($_POST['part_goal']) ? intval($_POST['part_goal']) : 0;
        $estimated_length = isset($_POST['estimated_length']) ? floatval($_POST['estimated_length']) : 0;
        $estimated_weight = isset($_POST['estimated_weight']) ? floatval($_POST['estimated_weight']) : 0;

        if (empty($part_name)) {
             wp_send_json_error(array('message' => 'Part Name cannot be empty.'));
        }

        $update_result = $wpdb->update($parts_table, array(
            'name' => $part_name,
            'goal' => $part_goal,
            'estimated_length' => $estimated_length,
            'estimated_weight' => $estimated_weight
        ), array('id' => $part_id));

        if ($update_result === false) {
            wp_send_json_error(array('message' => 'Database error updating part: ' . $wpdb->last_error));
        }

        wp_send_json_success(array(
            'message' => 'Part updated successfully!'
        ));
    }

    public function ajax_complete_project() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        $projects_table = $wpdb->prefix . "production_projects";
        $completed_table = $wpdb->prefix . "production_completed";

        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        if ($project_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Project ID.'));
        }

        $project_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM $projects_table WHERE id = %d", $project_id
        ));
        if (!$project_name) {
             wp_send_json_error(array('message' => 'Project not found.'));
        }

        $parts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $parts_table WHERE project_id = %d", $project_id
        ));

        $user_contributions_data = array();
        foreach ($parts as $part) {
            $contributions = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, SUM(quantity) as total
                 FROM $submissions_table
                 WHERE part_id = %d AND created_at >= %s
                 GROUP BY user_id",
                $part->id, $part->start_date ?? '1970-01-01 00:00:00'
            ));

            $part_contributions = array();
            foreach ($contributions as $contribution) {
                $user_info = get_userdata($contribution->user_id);
                if ($user_info) {
                    $part_contributions[] = array(
                        'user' => $user_info->user_login,
                        'total' => $contribution->total
                    );
                }
            }

            $user_contributions_data[] = array(
                'part_name' => $part->name,
                'goal' => $part->goal,
                'progress' => $part->progress,
                'contributions' => $part_contributions
            );
        }

        $json_contributions = json_encode($user_contributions_data);

        $insert_result = $wpdb->insert($completed_table, array(
            'project_id' => $project_id,
            'project_name' => $project_name,
            'completed_date' => current_time('mysql'),
            'user_contributions' => $json_contributions
        ));

        if ($insert_result === false) {
            wp_send_json_error(array('message' => 'Database error saving completed record: ' . $wpdb->last_error));
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE $parts_table SET progress = 0, start_date = NULL WHERE project_id = %d", $project_id
        ));
        
        // Check if this is a monthly project and restart it if it is
        $is_monthly = $wpdb->get_var($wpdb->prepare(
            "SELECT is_monthly FROM $projects_table WHERE id = %d", $project_id
        ));
        
        if ($is_monthly) {
            // Restart the project automatically
            $start_date = current_time('mysql');
            $wpdb->query($wpdb->prepare(
                "UPDATE $parts_table SET start_date = %s WHERE project_id = %d AND goal > 0", 
                $start_date, $project_id
            ));
        }

        wp_send_json_success(array(
            'message' => 'Project marked as complete successfully!'
        ));
    }

    public function ajax_delete_project() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        $parts_table = $wpdb->prefix . "production_parts";
        $completed_table = $wpdb->prefix . "production_completed";
        $materials_table = $wpdb->prefix . 'project_materials';

        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        if ($project_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Project ID.'));
        }

        if (is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'delete_project_file')) {
            $this->production_file_handler->delete_project_file($project_id);
        }

        $wpdb->delete($parts_table, array('project_id' => $project_id));
        $wpdb->delete($materials_table, array('project_id' => $project_id));
        $wpdb->delete($completed_table, array('project_id' => $project_id));
        $delete_result = $wpdb->delete($projects_table, array('id' => $project_id));

        if ($delete_result === false) {
             wp_send_json_error(array('message' => 'Database error deleting project: ' . $wpdb->last_error));
        } elseif ($delete_result === 0) {
             wp_send_json_error(array('message' => 'Project not found or already deleted.'));
        }

        wp_send_json_success(array(
            'message' => 'Project deleted successfully!'
        ));
    }

    public function ajax_delete_part() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";

        $part_id = isset($_POST['part_id']) ? intval($_POST['part_id']) : 0;
        if ($part_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Part ID.'));
        }

        $delete_result = $wpdb->delete($parts_table, array('id' => $part_id));

        if ($delete_result === false) {
            wp_send_json_error(array('message' => 'Database error deleting part: ' . $wpdb->last_error));
        } elseif ($delete_result === 0) {
             wp_send_json_error(array('message' => 'Part not found or already deleted.'));
        }

        wp_send_json_success(array(
            'message' => 'Part deleted successfully!'
        ));
    }

    public function ajax_start_project() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";

        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        if ($project_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Project ID.'));
        }
        $start_date = current_time('mysql');

        $update_result = $wpdb->query($wpdb->prepare(
            "UPDATE $parts_table SET start_date = %s WHERE project_id = %d AND goal > 0 AND start_date IS NULL",
            $start_date, $project_id
        ));

        if ($update_result === false) {
            wp_send_json_error(array('message' => 'Database error starting project: ' . $wpdb->last_error));
        }

        wp_send_json_success(array(
            'message' => 'Project started successfully!' . ($update_result > 0 ? '' : ' (No inactive parts with goals found to update)')
        ));
    }

    public function ajax_delete_completed() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        global $wpdb;
        $completed_table = $wpdb->prefix . "production_completed";

        $completed_id = isset($_POST['completed_id']) ? intval($_POST['completed_id']) : 0;
        if ($completed_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Completed Goal ID.'));
        }

        $delete_result = $wpdb->delete($completed_table, array('id' => $completed_id));

        if ($delete_result === false) {
            wp_send_json_error(array('message' => 'Database error deleting completed goal: ' . $wpdb->last_error));
        } elseif ($delete_result === 0) {
             wp_send_json_error(array('message' => 'Completed goal not found or already deleted.'));
        }

        wp_send_json_success(array(
            'message' => 'Completed goal deleted successfully!'
        ));
    }

    public function ajax_delete_download_log() {
        check_ajax_referer('production-goals-admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        if ($log_id <= 0) {
             wp_send_json_error(array('message' => 'Invalid Log ID.'));
        }

        if (is_object($this->production_file_handler) && method_exists($this->production_file_handler, 'delete_download_log')) {
            $deleted = $this->production_file_handler->delete_download_log($log_id);
            if ($deleted) {
                wp_send_json_success(array('message' => 'Download log entry deleted successfully!'));
            } else {
                wp_send_json_error(array('message' => 'Failed to delete download log entry. It might have already been deleted or a database error occurred.'));
            }
        } else {
            wp_send_json_error(array('message' => 'File handler is not available or does not support log deletion.'));
        }
    }
}

new Production_Goals_Admin();
