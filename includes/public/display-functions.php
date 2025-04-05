<?php
/**
 * Display functions for Production Goals
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Production_Goals_Display {
    /**
     * Constructor
     */
    public function __construct() {
        // Register hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        wp_enqueue_style('production-goals-public', PRODUCTION_GOALS_URL . 'assets/css/public.css', array(), PRODUCTION_GOALS_VERSION);
        wp_enqueue_script('production-goals-public', PRODUCTION_GOALS_URL . 'assets/js/public.js', array('jquery'), PRODUCTION_GOALS_VERSION, true);
        
        // Get current user ID to pass to JavaScript
        $user_id = get_current_user_id();
        
        // Pass variables to script
        wp_localize_script('production-goals-public', 'productionGoals', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('production-goals-public'),
            'textLoading' => __('Loading...', 'production-goals'),
            'textSuccess' => __('Success!', 'production-goals'),
            'textError' => __('Error', 'production-goals')
        ));
        
        // Pass user ID data to the script to prevent duplicate submissions
        wp_localize_script('production-goals-public', 'pgUserData', array(
            'userId' => $user_id
        ));
    }
    
    /**
     * Render a progress bar
     */
    public static function render_progress_bar($progress, $goal, $show_percentage = true) {
        $percentage = ($goal > 0) ? min(100, ($progress / $goal) * 100) : 0;
        
        $output = '<div class="pg-progress-wrapper">';
        $output .= '<div class="pg-progress-numbers">';
        $output .= '<span class="pg-progress-current">' . esc_html($progress) . '</span> / ';
        $output .= '<span class="pg-progress-goal">' . esc_html($goal) . '</span>';
        
        if ($show_percentage) {
            $output .= ' <span class="pg-progress-percentage">(' . round($percentage, 2) . '%)</span>';
        }
        
        $output .= '</div>';
        $output .= '<div class="pg-progress-bar-container">';
        $output .= '<div class="pg-progress-bar" style="width: ' . esc_attr($percentage) . '%;"></div>';
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render a project card
     */
    public static function render_project_card($project, $show_link = true) {
        // Get featured image
        $featured_image = Production_Goals_Utilities::get_featured_image_from_url($project->project_url);
        if (!$featured_image) {
            $featured_image = PRODUCTION_GOALS_URL . 'assets/images/default-placeholder.png';
        }
        
        // Calculate percentage complete
        $percentage = isset($project->percentage_complete) ? $project->percentage_complete : 0;
        
        $output = '<div class="pg-project-card">';
        
        // Image container
        $output .= '<div class="pg-project-image">';
        if ($show_link) {
            $output .= '<a href="' . esc_url($project->project_url) . '">';
            $output .= '<img src="' . esc_url($featured_image) . '" alt="' . esc_attr($project->project_name) . '">';
            $output .= '</a>';
        } else {
            $output .= '<img src="' . esc_url($featured_image) . '" alt="' . esc_attr($project->project_name) . '">';
        }
        $output .= '</div>';
        
        // Project details
        $output .= '<div class="pg-project-details">';
        
        // Project title
        $output .= '<h3 class="pg-project-title">';
        if ($show_link) {
            $output .= '<a href="' . esc_url($project->project_url) . '">' . esc_html($project->project_name) . '</a>';
        } else {
            $output .= esc_html($project->project_name);
        }
        $output .= '</h3>';
        
        // Project materials
        if (isset($project->materials)) {
            $output .= '<p class="pg-project-materials">';
            $output .= '<span class="pg-materials-label">Materials:</span> ';
            $output .= '<span>' . esc_html($project->materials ? $project->materials : 'N/A') . '</span>';
            $output .= '</p>';
        }
        
        // Project progress
        if (isset($project->total_remaining)) {
            $output .= '<p class="pg-project-remaining">' . esc_html($project->total_remaining) . ' parts remaining</p>';
            $output .= '<p class="pg-project-percentage">' . esc_html(number_format($percentage, 2)) . '% complete</p>';
            $output .= '<div class="pg-progress-bar-container">';
            $output .= '<div class="pg-progress-bar" style="width: ' . esc_attr($percentage) . '%;"></div>';
            $output .= '</div>';
        }
        
        $output .= '</div>'; // Close project details
        $output .= '</div>'; // Close project card
        
        return $output;
    }
    
    /**
     * Render a user contribution card
     */
    public static function render_user_contribution_card($user_id) {
        $user_data = Production_Goals_DB::get_user_lifetime_contributions($user_id);
        $group_data = Production_Goals_DB::get_group_lifetime_contributions();
        
        // Calculate contribution percentage
        $contribution_percentage = $group_data['total_parts'] > 0 ? round(($user_data['total_parts'] / $group_data['total_parts']) * 100, 2) : 0;
        
        $output = '<div class="pg-contribution-card">';
        
        // User totals
        $output .= '<div class="pg-contribution-section">';
        $output .= '<h3>My Totals</h3>';
        $output .= '<p><strong>Total Parts:</strong> ' . esc_html($user_data['total_parts']) . '</p>';
        $output .= '<p><strong>Total Length:</strong> ' . esc_html(Production_Goals_Utilities::format_length($user_data['total_length'])) . '</p>';
        $output .= '<p><strong>Total Weight:</strong> ' . esc_html(Production_Goals_Utilities::format_weight($user_data['total_weight'])) . '</p>';
        $output .= '</div>';
        
        // Group totals
        $output .= '<div class="pg-contribution-section">';
        $output .= '<h3>Group Totals</h3>';
        $output .= '<p><strong>Total Parts:</strong> ' . esc_html($group_data['total_parts']) . '</p>';
        $output .= '<p><strong>Total Length:</strong> ' . esc_html(Production_Goals_Utilities::format_length($group_data['total_length'])) . '</p>';
        $output .= '<p><strong>Total Weight:</strong> ' . esc_html(Production_Goals_Utilities::format_weight($group_data['total_weight'])) . '</p>';
        $output .= '</div>';
        
        // Contribution percentage
        $output .= '<div class="pg-contribution-section">';
        $output .= '<h3>My Contribution</h3>';
        $output .= '<div class="pg-contribution-percentage">' . esc_html($contribution_percentage) . '%</div>';
        $output .= '<p>of total group production</p>';
        $output .= '</div>';
        
        $output .= '</div>'; // Close contribution card
        
        return $output;
    }
    
    /**
     * Render a submission form
     */
    public static function render_submission_form($project_id, $part_id = 0) {
        if (!is_user_logged_in()) {
            return '<p class="pg-login-required">You must be logged in to make contributions.</p>';
        }
        
        $output = '<form class="pg-submission-form" data-project-id="' . esc_attr($project_id) . '">';
        
        if ($part_id) {
            $output .= '<input type="hidden" name="part_id" value="' . esc_attr($part_id) . '">';
        } else {
            // Get active parts for the project
            $parts = Production_Goals_DB::get_active_project_parts($project_id);
            
            if (empty($parts)) {
                return '<p class="pg-no-parts">No active parts available for contribution.</p>';
            }
            
            $output .= '<div class="pg-form-row">';
            $output .= '<label for="part_id">Select Part:</label>';
            $output .= '<select name="part_id" id="part_id" required>';
            $output .= '<option value="">-- Select Part --</option>';
            
            foreach ($parts as $part) {
                $output .= '<option value="' . esc_attr($part->id) . '">' . esc_html($part->name) . '</option>';
            }
            
            $output .= '</select>';
            $output .= '</div>';
        }
        
        $output .= '<div class="pg-form-row">';
        $output .= '<label for="quantity">Quantity:</label>';
        $output .= '<input type="number" name="quantity" id="quantity" min="1" value="1" required>';
        $output .= '</div>';
        
        $output .= '<div class="pg-form-row">';
        $output .= '<button type="submit" class="pg-button">Submit Contribution</button>';
        $output .= '</div>';
        
        $output .= '<div class="pg-submission-message"></div>';
        
        $output .= '</form>';
        
        return $output;
    }
    
    /**
     * Render tabs
     */
    public static function render_tabs($tabs, $container_class = 'pg-tabs', $tab_class = 'pg-tab') {
        $output = '<div class="' . esc_attr($container_class) . '">';
        
        // Tab navigation
        $output .= '<div class="pg-tabs-nav">';
        $first = true;
        
        foreach ($tabs as $id => $title) {
            $active_class = $first ? 'pg-active' : '';
            $output .= '<button class="pg-tab-button ' . $active_class . '" data-tab="' . esc_attr($id) . '">' . esc_html($title) . '</button>';
            $first = false;
        }
        
        $output .= '</div>';
        
        // Tab content
        $first = true;
        foreach ($tabs as $id => $title) {
            $active_class = $first ? 'pg-active' : '';
            $output .= '<div id="' . esc_attr($id) . '" class="' . esc_attr($tab_class) . ' ' . $active_class . '">';
            $output .= '<div class="pg-tab-content-placeholder" data-tab="' . esc_attr($id) . '"></div>';
            $output .= '</div>';
            $first = false;
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render a card element
     */
    public static function render_card($title, $content, $class = '') {
        $output = '<div class="pg-card ' . esc_attr($class) . '">';
        
        if ($title) {
            $output .= '<div class="pg-card-header">';
            $output .= '<h3 class="pg-card-title">' . esc_html($title) . '</h3>';
            $output .= '</div>';
        }
        
        $output .= '<div class="pg-card-content">';
        $output .= $content;
        $output .= '</div>';
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render a table
     */
    public static function render_table($headers, $rows, $class = '') {
        $output = '<div class="pg-table-wrapper">';
        $output .= '<table class="pg-table ' . esc_attr($class) . '">';
        
        // Headers
        $output .= '<thead><tr>';
        foreach ($headers as $header) {
            $output .= '<th>' . esc_html($header) . '</th>';
        }
        $output .= '</tr></thead>';
        
        // Body
        $output .= '<tbody>';
        if (empty($rows)) {
            $output .= '<tr><td colspan="' . count($headers) . '">No data available</td></tr>';
        } else {
            foreach ($rows as $row) {
                $output .= '<tr>';
                foreach ($row as $cell) {
                    $output .= '<td>' . $cell . '</td>';
                }
                $output .= '</tr>';
            }
        }
        $output .= '</tbody>';
        
        $output .= '</table>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render a message
     */
    public static function render_message($message, $type = 'info', $dismissible = false) {
        $output = '<div class="pg-message pg-message-' . esc_attr($type) . '">';
        $output .= '<p>' . esc_html($message) . '</p>';
        
        if ($dismissible) {
            $output .= '<button type="button" class="pg-message-close" aria-label="Close">&times;</button>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render a button
     */
    public static function render_button($text, $url = '', $class = '', $attributes = array()) {
        $attr_string = '';
        
        foreach ($attributes as $key => $value) {
            $attr_string .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }
        
        if ($url) {
            $output = '<a href="' . esc_url($url) . '" class="pg-button ' . esc_attr($class) . '"' . $attr_string . '>' . esc_html($text) . '</a>';
        } else {
            $output = '<button type="button" class="pg-button ' . esc_attr($class) . '"' . $attr_string . '>' . esc_html($text) . '</button>';
        }
        
        return $output;
    }
    
    /**
     * Render a loader
     */
    public static function render_loader($text = 'Loading...') {
        $output = '<div class="pg-loader">';
        $output .= '<div class="pg-loader-spinner"></div>';
        $output .= '<p class="pg-loader-text">' . esc_html($text) . '</p>';
        $output .= '</div>';
        
        return $output;
    }
}

// Initialize the class
new Production_Goals_Display();