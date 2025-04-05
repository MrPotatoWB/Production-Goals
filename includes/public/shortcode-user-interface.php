<?php
/**
 * User Interface Shortcode for Production Goals - Improved Version
 * 
 * Consolidated UI/UX improvements for the [my_projects] shortcode:
 * - Single integrated interface for all project contribution management
 * - Shows all projects in dropdown with search/filter capability
 * - Provides functionality to view/edit/delete submissions for active projects
 * - Shows read-only history for completed goals
 * - Displays user statistics and totals
 * - Allows contributions to recent goals (within 2 weeks of completion)
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Production_Goals_User_Interface {
    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode('my_projects', array($this, 'my_projects_shortcode'));
    }
    
    /**
     * Render the My Projects interface
     */
    public function my_projects_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<div class="pg-login-required">You must be logged in to view your projects.</div>';
        }
        
        $user_id = get_current_user_id();
        
        // Get user's lifetime contributions
        $user_data = Production_Goals_DB::get_user_lifetime_contributions($user_id);
        
        // Get group lifetime contributions
        $group_data = Production_Goals_DB::get_group_lifetime_contributions();
        
        // Calculate contribution percentage
        $contribution_percentage = $group_data['total_parts'] > 0 ? 
            round(($user_data['total_parts'] / $group_data['total_parts']) * 100, 2) : 0;
        
        // Get all projects
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        $projects_table = $wpdb->prefix . "production_projects";
        
        // Get ALL projects from the database
        $all_db_projects = $wpdb->get_results(
            "SELECT id, name FROM $projects_table ORDER BY name ASC"
        );
        
        // Get active projects
        $active_projects = $wpdb->get_results(
            "SELECT DISTINCT pr.id, pr.name 
             FROM $projects_table pr
             JOIN $parts_table p ON p.project_id = pr.id
             WHERE p.start_date IS NOT NULL
             ORDER BY pr.name ASC"
        );
        
        // Get projects with user contributions
        $user_projects = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT pr.id, pr.name 
             FROM $submissions_table s
             JOIN $parts_table p ON s.part_id = p.id
             JOIN $projects_table pr ON p.project_id = pr.id
             WHERE s.user_id = %d
             ORDER BY pr.name ASC",
            $user_id
        ));
        
        // Get recently completed projects (within last 2 weeks)
        $two_weeks_ago = date('Y-m-d H:i:s', strtotime('-2 weeks'));
        $completed_projects = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.project_id, c.project_name as name, c.completed_date
             FROM {$wpdb->prefix}production_completed c
             WHERE c.completed_date >= %s
             ORDER BY c.completed_date DESC",
            $two_weeks_ago
        ));
        
        // Create a map of project statuses for efficient lookup
        $project_status_map = array();
        
        // Add active projects to status map
        foreach ($active_projects as $project) {
            $project_status_map[$project->id] = 'active';
        }
        
        // Add recently completed projects to status map
        $completed_dates = array();
        foreach ($completed_projects as $project) {
            $project_status_map[$project->project_id] = 'recent';
            $completed_dates[$project->project_id] = $project->completed_date;
        }
        
        // Add user projects to status map (if not already set)
        foreach ($user_projects as $project) {
            if (!isset($project_status_map[$project->id])) {
                $project_status_map[$project->id] = 'completed';
            }
        }

        // Build the all_projects array using ALL projects from database
        $all_projects = array();
        foreach ($all_db_projects as $project) {
            $project_status = isset($project_status_map[$project->id]) ? $project_status_map[$project->id] : 'inactive';
            
            $project_data = array(
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project_status
            );
            
            // Add completed date if available
            if ($project_status === 'recent' && isset($completed_dates[$project->id])) {
                $project_data['completed_date'] = $completed_dates[$project->id];
            }
            
            $all_projects[] = $project_data;
        }
        
        // Get selected project from URL param or use first available
        $selected_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
        
        if ($selected_project_id == 0 && !empty($all_projects)) {
            // Prioritize active projects if available
            foreach ($all_projects as $project) {
                if ($project['status'] === 'active') {
                    $selected_project_id = $project['id'];
                    break;
                }
            }
            
            // If no active projects, use the first one
            if ($selected_project_id == 0) {
                $selected_project_id = $all_projects[0]['id'];
            }
        }
        
        // Get selected project details
        $selected_project = null;
        foreach ($all_projects as $project) {
            if ($project['id'] == $selected_project_id) {
                $selected_project = $project;
                break;
            }
        }
        
        // Start output buffering
        ob_start();
        ?>
        <div class="pg-my-projects-container">
            <div class="pg-section pg-summary-section">
                <div class="pg-contribution-card">
                    <div class="pg-contribution-section">
                        <h3>My Totals</h3>
                        <p><strong>Total Parts:</strong> <?php echo number_format($user_data['total_parts']); ?></p>
                        <p><strong>Total Length:</strong> <?php echo Production_Goals_Utilities::format_length($user_data['total_length']); ?></p>
                        <p><strong>Total Weight:</strong> <?php echo Production_Goals_Utilities::format_weight($user_data['total_weight']); ?></p>
                    </div>
                    
                    <div class="pg-contribution-section">
                        <h3>Group Totals</h3>
                        <p><strong>Total Parts:</strong> <?php echo number_format($group_data['total_parts']); ?></p>
                        <p><strong>Total Length:</strong> <?php echo Production_Goals_Utilities::format_length($group_data['total_length']); ?></p>
                        <p><strong>Total Weight:</strong> <?php echo Production_Goals_Utilities::format_weight($group_data['total_weight']); ?></p>
                    </div>
                    
                    <div class="pg-contribution-section">
                        <h3>My Contribution</h3>
                        <div class="pg-contribution-percentage"><?php echo $contribution_percentage; ?>%</div>
                        <p>of total group production</p>
                    </div>
                </div>
            </div>
            
            <div class="pg-section pg-project-section">
                <h2 class="pg-section-title">Project Dashboard</h2>
                
                <?php if (empty($all_projects)): ?>
                    <div class="pg-empty-message">
                        <p>No projects available yet.</p>
                    </div>
                <?php else: ?>
                    <!-- Project Search and Selection -->
                    <div class="pg-project-search-wrapper">
                        <div class="pg-project-search">
                            <label for="project-search-input"><strong>Search Projects:</strong></label>
                            <div class="pg-search-input-container">
                                <input type="text" id="project-search-input" placeholder="Type to search projects..." autocomplete="off">
                                <button type="button" id="project-search-button" class="pg-search-button">🔍 Search</button>
                            </div>
                        </div>
                        <div class="pg-project-selector">
                            <label for="project-select"><strong>Select Project:</strong></label>
                            <select id="project-select" class="pg-select">
                                <?php foreach ($all_projects as $project): ?>
                                    <option value="<?php echo esc_attr($project['id']); ?>" <?php selected($selected_project_id, $project['id']); ?> data-status="<?php echo esc_attr($project['status']); ?>">
                                        <?php 
                                        echo esc_html($project['name']);
                                        if ($project['status'] === 'active') {
                                            echo ' (Active)';
                                        } elseif ($project['status'] === 'recent') {
                                            echo ' (Recently Completed)';
                                        }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <?php if ($selected_project): ?>
                        <div class="pg-project-content">
                            <?php
                            if ($selected_project['status'] === 'active') {
                                echo '<div class="pg-project-status pg-status-active">Active Project</div>';
                            } elseif ($selected_project['status'] === 'recent') {
                                echo '<div class="pg-project-status pg-status-recent">Recently Completed: ' . date('M j, Y', strtotime($selected_project['completed_date'])) . '</div>';
                            } elseif ($selected_project['status'] === 'completed') {
                                echo '<div class="pg-project-status pg-status-completed">Completed Project</div>';
                            } else {
                                echo '<div class="pg-project-status pg-status-inactive">Inactive Project</div>';
                            }
                            ?>
                            
                            <div class="pg-tabs">
                                <div class="pg-tabs-nav">
                                    <button class="pg-tab-button pg-active" data-tab="parts-tab">Parts & Submissions</button>
                                    <button class="pg-tab-button" data-tab="submissions-tab">My Submissions</button>
                                    <button class="pg-tab-button" data-tab="statistics-tab">Statistics</button>
                                </div>
                                
                                <div id="parts-tab" class="pg-tab pg-active">
                                    <?php $this->render_parts_tab($selected_project_id, $user_id, $selected_project['status']); ?>
                                </div>
                                
                                <div id="submissions-tab" class="pg-tab">
                                    <?php $this->render_submissions_tab($selected_project_id, $user_id, $selected_project['status']); ?>
                                </div>
                                
                                <div id="statistics-tab" class="pg-tab">
                                    <?php $this->render_statistics_tab($selected_project_id, $user_id); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            /* Project-specific styles */
            .pg-my-projects-container {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                max-width: 1000px;
                margin: 0 auto 30px;
                background-color: #fff;
                border-radius: 0.5rem;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 1.5rem;
                color: #333 !important;
            }
            
            /* Project search and selection */
            .pg-project-search-wrapper {
                margin-bottom: 20px;
            }
            
            .pg-project-search {
                margin-bottom: 10px;
            }
            
            .pg-project-search label {
                display: block;
                margin-bottom: 5px;
                color: #333;
                font-size: 16px;
            }
            
            .pg-search-input-container {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            #project-search-input {
                flex: 1;
                padding: 10px;
                border: 1px solid #0057b7;
                border-radius: 5px;
                font-size: 16px;
                color: #333;
                background-color: #fff;
            }
            
            .pg-search-button {
                background-color: #0057b7;
                color: white;
                border: none;
                border-radius: 5px;
                padding: 10px 15px;
                font-size: 16px;
                cursor: pointer;
                height: 42px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .pg-search-button:hover {
                background-color: #003b7e;
            }
            
            .pg-project-selector {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .pg-select {
                flex-grow: 1;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
                color: #333;
                background-color: #fff;
            }
            
            /* UI Autocomplete styles */
            .ui-autocomplete {
                max-height: 200px;
                overflow-y: auto;
                overflow-x: hidden;
                background-color: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                z-index: 1000 !important;
            }
            
            .ui-menu-item {
                padding: 8px 10px;
                cursor: pointer;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .ui-menu-item:hover,
            .ui-menu-item.ui-state-focus {
                background-color: #f8f9fa;
            }
            
            .ui-menu-item .ui-menu-item-wrapper.ui-state-active {
                background-color: #0057b7 !important; /* Ukraine blue */
                color: white !important;
                border: none !important;
                margin: 0 !important;
            }
            
            .ui-helper-hidden-accessible {
                display: none;
            }
            
            .pg-project-status {
                display: inline-block;
                padding: 5px 10px;
                margin-bottom: 15px;
                border-radius: 4px;
                font-weight: 500;
            }
            
            .pg-status-active {
                background-color: #0057b7; /* Ukraine blue */
                color: white !important;
            }
            
            .pg-status-recent {
                background-color: #ffd700; /* Ukraine yellow */
                color: #333 !important;
            }
            
            .pg-status-completed {
                background-color: #6c757d;
                color: white !important;
            }
            
            .pg-status-inactive {
                background-color: #e9ecef;
                color: #333 !important;
            }
            
            .pg-submission-item {
                background-color: #f8f9fa;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 15px;
                margin-bottom: 10px;
            }
            
            .pg-submission-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
            }
            
            .pg-submission-part {
                font-weight: 600;
                color: #333;
            }
            
            .pg-submission-date {
                color: #6c757d;
                font-size: 0.875rem;
            }
            
            .pg-submission-quantity {
                font-size: 1.25rem;
                color: #0057b7; /* Ukraine blue */
                font-weight: 600;
                margin: 10px 0;
            }
            
            .pg-submission-actions {
                margin-top: 10px;
            }
            
            .pg-notification {
                padding: 10px 15px;
                margin-bottom: 15px;
                border-radius: 4px;
                font-size: 0.875rem;
            }
            
            .pg-notification-info {
                background-color: #cce5ff;
                color: #004085;
            }
            
            /* Part list items */
            .pg-part-item {
                display: flex;
                flex-direction: column;
                background-color: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .pg-part-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }
            
            .pg-part-name {
                font-size: 1.1rem;
                font-weight: 600;
                color: #333;
            }
            
            .pg-part-progress {
                display: flex;
                flex-direction: column;
                margin: 5px 0 10px;
            }
            
            .pg-progress-info {
                display: flex;
                justify-content: space-between;
                font-size: 0.9rem;
                margin-bottom: 5px;
            }
            
            .pg-contribution-form {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 10px;
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #eee;
            }
            
            .pg-input-group {
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .pg-input-group label {
                font-weight: 500;
                color: #333;
                margin-bottom: 0;
            }
            
            .pg-input-group input {
                width: 80px;
                padding: 5px 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                text-align: center;
            }
            
            /* Editable submission form */
            .pg-edit-form {
                background-color: #f8f9fa;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 15px;
                margin-top: 10px;
                display: none;
            }
            
            .pg-edit-form .pg-form-row {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            
            /* Stats section */
            .pg-stats-section {
                margin-bottom: 20px;
            }
            
            .pg-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .pg-stats-card {
                background-color: #f8f9fa;
                border-radius: 5px;
                padding: 15px;
                text-align: center;
            }
            
            .pg-stats-value {
                font-size: 24px;
                font-weight: 600;
                color: #0057b7; /* Ukraine blue */
                margin: 10px 0;
            }
            
            .pg-stats-label {
                color: #6c757d;
            }

            /* Contribution card styles with better contrast */
            .pg-contribution-card {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                margin-bottom: 30px;
                background: #fff;
                color: #333 !important;
            }
            
            .pg-contribution-section {
                flex: 1;
                min-width: 200px;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                text-align: center;
                color: #333 !important;
            }
            
            .pg-contribution-section:nth-child(1) {
                background-color: #e3f2fd;
            }
            
            .pg-contribution-section:nth-child(2) {
                background-color: #e8f5e9;
            }
            
            .pg-contribution-section:nth-child(3) {
                background-color: #fff8e1;
            }
            
            .pg-contribution-section h3 {
                margin: 0 0 15px 0;
                font-size: 1.3rem;
                font-weight: bold;
                color: #0057b7 !important; /* Ukraine blue */
            }
            
            .pg-contribution-section:nth-child(2) h3 {
                color: #1b5e20 !important;
            }
            
            .pg-contribution-section:nth-child(3) h3 {
                color: #947600 !important; /* Dark yellow */
            }
            
            .pg-contribution-section p {
                margin: 5px 0;
                color: #333 !important;
            }
            
            .pg-contribution-section p strong {
                color: #333 !important;
                font-weight: bold;
            }
            
            .pg-contribution-percentage {
                font-size: 2.5em;
                font-weight: bold;
                margin: 10px 0;
                color: #947600 !important; /* Dark yellow */
            }
            
            /* Tabs styling */
            .pg-tabs {
                margin: 20px 0;
                background: #fff;
            }
            
            .pg-tabs-nav {
                display: flex;
                flex-wrap: wrap;
                border-bottom: 1px solid #dee2e6;
                padding-left: 0;
                margin-bottom: 0;
                list-style: none;
            }
            
            .pg-tab-button {
                padding: 0.5rem 1rem;
                margin-bottom: -1px;
                background: none;
                border: 1px solid transparent;
                border-top-left-radius: 0.25rem;
                border-top-right-radius: 0.25rem;
                cursor: pointer;
                color: #0057b7; /* Ukraine blue */
                text-decoration: none;
                transition: color 0.15s ease-in-out;
            }
            
            .pg-tab-button:hover {
                color: #003b7e; /* Darker blue */
                border-color: #e9ecef #e9ecef #dee2e6;
            }
            
            .pg-tab-button.pg-active {
                color: #495057;
                background-color: #fff;
                border-color: #dee2e6 #dee2e6 #fff;
            }
            
            .pg-tab {
                display: none;
                padding: 1rem;
                border: 1px solid #dee2e6;
                border-top: none;
                border-bottom-left-radius: 0.25rem;
                border-bottom-right-radius: 0.25rem;
                background: #fff;
                color: #333;
            }
            
            .pg-tab.pg-active {
                display: block;
            }
            
            /* Table styling */
            .pg-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 1rem;
                color: #333;
            }
            
            .pg-table th,
            .pg-table td {
                padding: 0.75rem;
                vertical-align: top;
                border-top: 1px solid #dee2e6;
            }
            
            .pg-table thead th {
                vertical-align: bottom;
                border-bottom: 2px solid #dee2e6;
                background-color: #0057b7; /* Ukraine blue */
                color: white;
            }
            
            .pg-table tbody tr:nth-of-type(odd) {
                background-color: rgba(0, 0, 0, 0.05);
            }
            
            /* Button styling */
            .pg-button {
                display: inline-block;
                font-weight: 400;
                text-align: center;
                white-space: nowrap;
                vertical-align: middle;
                user-select: none;
                border: 1px solid transparent;
                padding: 0.375rem 0.75rem;
                font-size: 1rem;
                line-height: 1.5;
                border-radius: 0.25rem;
                color: #fff;
                background-color: #0057b7; /* Ukraine blue */
                transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out;
                cursor: pointer;
            }
            
            .pg-button:hover {
                background-color: #003b7e; /* Darker blue */
            }
            
            .pg-button-small {
                padding: 0.25rem 0.5rem;
                font-size: 0.875rem;
                border-radius: 0.2rem;
            }
            
            .pg-button-danger {
                background-color: #555; /* Neutral gray instead of red */
            }
            
            .pg-button-danger:hover {
                background-color: #444; /* Darker gray */
            }
            
            .pg-button-secondary {
                background-color: #6c757d;
            }
            
            .pg-button-secondary:hover {
                background-color: #5a6268;
            }
            
            /* Login required */
            .pg-login-required {
                background-color: #f8d7da;
                color: #721c24;
                padding: 0.75rem 1.25rem;
                border-radius: 0.25rem;
                border: 1px solid #f5c6cb;
                text-align: center;
                margin: 1rem 0;
            }
            
            /* Messages */
            .pg-success-message {
                background-color: #0057b7; /* Ukraine blue */
                color: #fff;
                padding: 0.75rem 1.25rem;
                margin: 1rem 0;
                border-radius: 0.25rem;
                border: 1px solid #003b7e;
            }
            
            .pg-error-message {
                background-color: #555; /* Neutral gray */
                color: #fff;
                padding: 0.75rem 1.25rem;
                margin: 1rem 0;
                border-radius: 0.25rem;
                border: 1px solid #444;
            }
            
            /* Responsive design adjustments */
            @media (max-width: 768px) {
                .pg-contribution-card {
                    flex-direction: column;
                }
                
                .pg-contribution-section {
                    margin-bottom: 15px;
                }
                
                .pg-tabs-nav {
                    flex-wrap: wrap;
                }
                
                .pg-tab-button {
                    margin-bottom: 5px;
                }
                
                .pg-stats-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* Fix for submission message display */
            .pg-submission-message {
                margin-top: 10px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Define autocomplete initialization function
            function initializeAutocomplete() {
                // Extract project data for autocomplete
                var projects = [];
                $('#project-select option').each(function() {
                    projects.push({
                        id: $(this).val(),
                        label: $(this).text(),
                        status: $(this).data('status')
                    });
                });
                
                // Initialize autocomplete with custom rendering
                $('#project-search-input').autocomplete({
                    source: projects,
                    minLength: 1, // Reduced from 2 to 1 for better usability
                    delay: 100,    // Quicker response time
                    select: function(event, ui) {
                        // Set the dropdown value and trigger change
                        $('#project-select').val(ui.item.id).trigger('change');
                        return false;
                    }
                });
                
                // Add search button functionality
                $('#project-search-button').on('click', function() {
                    // If there are matches in the autocomplete, select the first one
                    var input = $('#project-search-input');
                    var term = input.val();
                    
                    if (!term) return; // Do nothing if empty
                    
                    // Find matching projects
                    var matches = projects.filter(function(project) {
                        return project.label.toLowerCase().indexOf(term.toLowerCase()) !== -1;
                    });
                    
                    if (matches.length > 0) {
                        // Select the first match
                        $('#project-select').val(matches[0].id).trigger('change');
                    }
                });
                
                // Also trigger search on Enter key
                $('#project-search-input').on('keypress', function(e) {
                    if (e.which === 13) {
                        $('#project-search-button').click();
                        e.preventDefault();
                    }
                }).autocomplete("instance")._renderItem = function(ul, item) {
                    // Custom rendering of items with status indicators
                    var statusClass = '';
                    var statusText = '';
                    
                    switch(item.status) {
                        case 'active':
                            statusClass = 'pg-status-active';
                            statusText = 'Active';
                            break;
                        case 'recent':
                            statusClass = 'pg-status-recent';
                            statusText = 'Recent';
                            break;
                        case 'completed':
                            statusClass = 'pg-status-completed';
                            statusText = 'Completed';
                            break;
                        default:
                            statusClass = 'pg-status-inactive';
                            statusText = 'Inactive';
                    }
                    
                    // Create element with status indicator
                    var $div = $("<div>")
                        .text(item.label.replace(/\s\([^)]*\)$/, '')) // Remove status from label
                        .append($("<span>").addClass(statusClass + " menu-status-indicator").text(statusText));
                    
                    return $("<li>")
                        .append($div)
                        .appendTo(ul);
                };
                
                // Style the status indicators in the dropdown
                $("<style>")
                    .text(`
                        .menu-status-indicator {
                            display: inline-block;
                            margin-left: 10px;
                            padding: 2px 6px;
                            border-radius: 3px;
                            font-size: 12px;
                        }
                        .ui-menu .ui-menu-item .ui-menu-item-wrapper {
                            padding: 8px 10px;
                        }
                        .ui-autocomplete {
                            z-index: 9999 !important;
                        }
                    `)
                    .appendTo("head");
            }
            
            // Load jQuery UI Autocomplete if not already loaded
            if (typeof $.ui === 'undefined' || !$.ui.autocomplete) {
                var script = document.createElement('script');
                script.src = 'https://code.jquery.com/ui/1.13.2/jquery-ui.min.js';
                script.integrity = 'sha256-lSjKY0/srUM9BE3dPm+c4fBo1dky2v27Gdjm2uoZaL0=';
                script.crossOrigin = 'anonymous';
                document.head.appendChild(script);
                
                var style = document.createElement('link');
                style.rel = 'stylesheet';
                style.href = 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.min.css';
                document.head.appendChild(style);
                
                // Wait for jQuery UI to load
                script.onload = function() {
                    initializeAutocomplete();
                };
            } else {
                // jQuery UI is already loaded
                initializeAutocomplete();
            }
            
            // Project selection with form submission
            $('#project-select').on('change', function() {
                const projectId = $(this).val();
                // Create a form to preserve the current page URL
                const form = $('<form>')
                    .attr('method', 'get')
                    .attr('action', window.location.pathname);
                
                // Add the project_id parameter
                $('<input>')
                    .attr('type', 'hidden')
                    .attr('name', 'project_id')
                    .attr('value', projectId)
                    .appendTo(form);
                
                // Maintain any other query parameters except project_id
                const params = new URLSearchParams(window.location.search);
                params.delete('project_id');
                params.forEach(function(value, key) {
                    $('<input>')
                        .attr('type', 'hidden')
                        .attr('name', key)
                        .attr('value', value)
                        .appendTo(form);
                });
                
                // Submit the form
                form.appendTo('body').submit();
            });
            
            // Tab functionality
            $('.pg-tab-button').on('click', function() {
                const tabId = $(this).data('tab');
                
                $('.pg-tab-button').removeClass('pg-active');
                $(this).addClass('pg-active');
                
                $('.pg-tab').removeClass('pg-active');
                $('#' + tabId).addClass('pg-active');
            });
            
            // Toggle edit form
            $('.pg-edit-button').on('click', function() {
                $(this).closest('.pg-submission-item').find('.pg-edit-form').slideToggle();
            });
            
            // Cancel edit
            $('.edit-cancel-button').on('click', function() {
                $(this).closest('.pg-edit-form').slideUp();
            });
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Render a submission form for my_projects page
     */
    private function render_submission_form($part, $project_id, $user_id, $project_status) {
        // Don't render forms for inactive projects
        if ($project_status !== 'active' && $project_status !== 'recent') {
            return '';
        }
        
        $part_id = $part->id;
        $output = '<form class="pg-contribution-form" data-part-id="' . esc_attr($part_id) . '">';
        $output .= '<input type="hidden" name="project_id" value="' . esc_attr($project_id) . '">';
        $output .= '<input type="hidden" name="part_id" value="' . esc_attr($part_id) . '">';
        
        $output .= '<div class="pg-input-group">';
        $output .= '<label for="quantity-' . esc_attr($part_id) . '">Add: </label>';
        $output .= '<input type="number" id="quantity-' . esc_attr($part_id) . '" name="quantity" min="1" value="1">';
        $output .= '</div>';
        
        $output .= '<button type="submit" class="pg-button pg-button-small">Submit</button>';
        
        // Add a message container for this specific form
        $output .= '<div class="pg-submission-message"></div>';
        
        $output .= '</form>';
        
        return $output;
    }

    /**
     * Render a single submission item with improved forms
     */
    private function render_submission_item($submission, $is_active) {
        $output = '<div class="pg-submission-item">';
        $output .= '<div class="pg-submission-header">';
        $output .= '<span class="pg-submission-part">' . esc_html($submission->part_name) . '</span>';
        $output .= '<span class="pg-submission-date">' . date('M j, Y, g:i a', strtotime($submission->created_at)) . '</span>';
        $output .= '</div>';
        
        $output .= '<div class="pg-submission-quantity">' . esc_html($submission->quantity) . ' parts submitted</div>';
        
        // Show edit/delete actions for active submissions only
        if ($is_active) {
            $output .= '<div class="pg-submission-actions">';
            $output .= '<button type="button" class="pg-button pg-button-small pg-edit-button">Edit</button> ';
            $output .= '<button type="button" class="pg-button pg-button-small pg-button-danger pg-delete-button" data-submission-id="' . esc_attr($submission->id) . '">Delete</button>';
            $output .= '</div>';
            
            // Hidden edit form with improved structure
            $output .= '<form class="pg-edit-form" style="display: none;" data-submission-id="' . esc_attr($submission->id) . '">';
            $output .= '<div class="pg-form-row">';
            $output .= '<label for="edit-quantity-' . esc_attr($submission->id) . '">New Quantity:</label>';
            $output .= '<input type="number" id="edit-quantity-' . esc_attr($submission->id) . '" name="quantity" min="1" value="' . esc_attr($submission->quantity) . '">';
            $output .= '</div>';
            
            $output .= '<button type="submit" class="pg-button pg-button-small">Update</button> ';
            $output .= '<button type="button" class="pg-button pg-button-small pg-button-secondary edit-cancel-button">Cancel</button>';
            $output .= '</form>';
        }
        
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Render the Parts tab content with improved form handling
     */
    private function render_parts_tab($project_id, $user_id, $project_status) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        $completed_table = $wpdb->prefix . "production_completed";
        
        // Get all parts for the project
        $parts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $parts_table WHERE project_id = %d ORDER BY name ASC",
            $project_id
        ));
        
        if (empty($parts)) {
            echo '<div class="pg-empty-message">No parts found for this project.</div>';
            return;
        }
        
        // For recently completed projects, get the completed project data to show parts
        $completed_parts = array();
        if ($project_status === 'recent') {
            $completed_project = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $completed_table WHERE project_id = %d ORDER BY completed_date DESC LIMIT 1",
                $project_id
            ));
            
            if ($completed_project) {
                $contributions = json_decode($completed_project->user_contributions, true);
                foreach ($contributions as $part_contribution) {
                    $completed_parts[$part_contribution['part_name']] = array(
                        'goal' => $part_contribution['goal'],
                        'progress' => $part_contribution['progress']
                    );
                }
            }
        }
        
        // Separate active and inactive parts
        $active_parts = array();
        $inactive_parts = array();
        
        foreach ($parts as $part) {
            // For recently completed projects, treat all parts as active
            if ($project_status === 'recent' || $part->start_date) {
                $active_parts[] = $part;
            } else {
                $inactive_parts[] = $part;
            }
        }
        
        // Show active parts first
        if (!empty($active_parts)) {
            echo '<h3>Active Parts</h3>';
            
            foreach ($active_parts as $part) {
                // Get user's contribution to this part
                $since_date = $part->start_date ?: date('Y-m-d H:i:s', strtotime('-2 weeks'));
                $user_contribution = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(quantity) FROM $submissions_table 
                     WHERE part_id = %d AND user_id = %d AND created_at >= %s",
                    $part->id, $user_id, $since_date
                )) ?: 0;
                
                // Get part data for recently completed projects
                $goal = $part->goal;
                $progress = $part->progress;
                
                // For recently completed projects, use data from completion record
                if ($project_status === 'recent' && !empty($completed_parts[$part->name])) {
                    $goal = $completed_parts[$part->name]['goal'];
                    $progress = $completed_parts[$part->name]['progress'];
                }
                
                // Calculate percentage
                $percentage = ($goal > 0) ? min(100, ($progress / $goal) * 100) : 0;
                
                echo '<div class="pg-part-item" data-part-id="' . esc_attr($part->id) . '">';
                echo '<div class="pg-part-header">';
                echo '<span class="pg-part-name">' . esc_html($part->name) . '</span>';
                echo '</div>';
                
                echo '<div class="pg-part-progress">';
                echo '<div class="pg-progress-info">';
                echo '<span>Progress: <span class="pg-progress-value">' . esc_html($progress) . '</span> / <span class="pg-goal-value">' . esc_html($goal) . '</span></span>';
                echo '<span class="pg-percentage-value">' . round($percentage, 1) . '%</span>';
                echo '</div>';
                
                echo '<div class="pg-progress-bar-container">';
                echo '<div class="pg-progress-bar" style="width: ' . esc_attr($percentage) . '%"></div>';
                echo '</div>';
                echo '</div>';
                
                echo '<div class="pg-part-contribution">';
                echo '<div class="pg-contribution-info">Your Contribution: <strong class="pg-user-contribution">' . esc_html($user_contribution) . '</strong></div>';
                
                // Always show contribution form for active or recent projects
                if ($project_status === 'active' || $project_status === 'recent') {
                    echo $this->render_submission_form($part, $project_id, $user_id, $project_status);
                }
                
                echo '</div>';
                echo '</div>';
            }
        } else if ($project_status === 'recent') {
            // For recent projects with no parts found, show specific message
            echo '<div class="pg-notification pg-notification-info">This project was recently completed, but part information is not available. You can still contribute by selecting a different project.</div>';
        }
        
        // Show inactive parts
        if (!empty($inactive_parts)) {
            echo '<h3>' . (empty($active_parts) ? '' : 'Inactive ') . 'Parts</h3>';
            
            foreach ($inactive_parts as $part) {
                // Get user's lifetime contribution to this part
                $user_contribution = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(quantity) FROM $submissions_table WHERE part_id = %d AND user_id = %d",
                    $part->id, $user_id
                )) ?: 0;
                
                echo '<div class="pg-part-item">';
                echo '<div class="pg-part-header">';
                echo '<span class="pg-part-name">' . esc_html($part->name) . '</span>';
                echo '</div>';
                
                echo '<div class="pg-part-stats">';
                echo '<div>Goal: ' . esc_html($part->goal) . '</div>';
                echo '<div>Your Lifetime Contribution: <strong>' . esc_html($user_contribution) . '</strong></div>';
                echo '</div>';
                echo '</div>';
            }
        }
    }

    /**
     * Render the Submissions tab content
     * Enhanced to include both viewing and editing functionality
     */
    private function render_submissions_tab($project_id, $user_id, $project_status) {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        $projects_table = $wpdb->prefix . "production_projects";
        
        // Get the project status
        $project = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $projects_table WHERE id = %d",
            $project_id
        ));
        
        // Check if project has active parts
        $active_project = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $parts_table WHERE project_id = %d AND start_date IS NOT NULL",
            $project_id
        ));
        
        // Check if project was recently completed
        $recent_completion = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}production_completed 
             WHERE project_id = %d AND completed_date >= DATE_SUB(NOW(), INTERVAL 2 WEEK)
             ORDER BY completed_date DESC LIMIT 1",
            $project_id
        ));
        
        $is_active_or_recent = ($active_project > 0 || !empty($recent_completion));
        
        // Get all user submissions for this project
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.name as part_name, p.start_date, p.goal, p.progress 
             FROM $submissions_table s
             JOIN $parts_table p ON s.part_id = p.id
             WHERE p.project_id = %d AND s.user_id = %d
             ORDER BY s.created_at DESC
             LIMIT 50",
            $project_id, $user_id
        ));
        
        if (empty($submissions)) {
            echo '<div class="pg-empty-message">You have not made any contributions to this project yet.</div>';
            return;
        }
        
        // Group submissions - active and completed
        $active_submissions = array();
        $completed_submissions = array();
        
        foreach ($submissions as $submission) {
            // Active if it was submitted after the part's start_date
            if ($submission->start_date && strtotime($submission->created_at) >= strtotime($submission->start_date)) {
                $active_submissions[] = $submission;
            } else {
                $completed_submissions[] = $submission;
            }
        }
        
        // Display notification about edit/delete capabilities
        if ($is_active_or_recent) {
            echo '<div class="pg-notification pg-notification-info">
                    <strong>Note:</strong> You can edit or delete any of your submissions while this project is active.
                  </div>';
        } else {
            echo '<div class="pg-notification pg-notification-info">
                    <strong>Note:</strong> This project is no longer active. Submissions cannot be edited or deleted.
                  </div>';
        }
        
        // Display active submissions first
        if (!empty($active_submissions)) {
            echo '<h3>Current Goal Submissions</h3>';
            
            foreach ($active_submissions as $submission) {
                echo $this->render_submission_item($submission, $is_active_or_recent);
            }
        }
        
        // Display completed submissions
        if (!empty($completed_submissions)) {
            echo '<h3>' . (empty($active_submissions) ? '' : 'Previous ') . 'Goal Submissions</h3>';
            
            foreach ($completed_submissions as $submission) {
                // For completed submissions, they can only be edited if they belong to a recently completed project
                echo $this->render_submission_item($submission, $is_active_or_recent && !empty($recent_completion));
            }
        }
    }
    
    /**
     * Render the Statistics tab content
     */
    private function render_statistics_tab($project_id, $user_id) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        $projects_table = $wpdb->prefix . "production_projects";
        
        // Get project info
        $project = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $projects_table WHERE id = %d", 
            $project_id
        ));
        
        if (!$project) {
            echo '<div class="pg-empty-message">Project not found.</div>';
            return;
        }
        
        // Calculate project statistics
        $project_stats = $this->calculate_project_statistics($project_id, $user_id);
        
        // Summary stats in cards
        echo '<div class="pg-stats-section">';
        echo '<h3>Your Contribution Summary</h3>';
        
        echo '<div class="pg-stats-grid">';
        
        // Parts Card
        echo '<div class="pg-stats-card">';
        echo '<h4>Parts Contributed</h4>';
        echo '<div class="pg-stats-value">' . number_format($project_stats['user_total_parts']) . '</div>';
        echo '<div class="pg-stats-label">Total Parts</div>';
        echo '</div>';
        
        // Length Card
        echo '<div class="pg-stats-card">';
        echo '<h4>Filament Length</h4>';
        echo '<div class="pg-stats-value">' . Production_Goals_Utilities::format_length($project_stats['user_total_length']) . '</div>';
        echo '<div class="pg-stats-label">Total Length</div>';
        echo '</div>';
        
        // Weight Card
        echo '<div class="pg-stats-card">';
        echo '<h4>Material Weight</h4>';
        echo '<div class="pg-stats-value">' . Production_Goals_Utilities::format_weight($project_stats['user_total_weight']) . '</div>';
        echo '<div class="pg-stats-label">Total Weight</div>';
        echo '</div>';
        
        // Percentage Card
        echo '<div class="pg-stats-card">';
        echo '<h4>Project Contribution</h4>';
        echo '<div class="pg-stats-value">' . $project_stats['user_percentage'] . '%</div>';
        echo '<div class="pg-stats-label">of Project Total</div>';
        echo '</div>';
        
        echo '</div>'; // End stats-grid
        echo '</div>'; // End stats-section
        
        // Group totals
        echo '<div class="pg-stats-section">';
        echo '<h3>Project Group Totals</h3>';
        
        echo '<table class="pg-table">';
        echo '<tr>';
        echo '<th>Metric</th>';
        echo '<th>Value</th>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td>Total Parts Contributed</td>';
        echo '<td>' . number_format($project_stats['group_total_parts']) . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td>Total Filament Length</td>';
        echo '<td>' . Production_Goals_Utilities::format_length($project_stats['group_total_length']) . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td>Total Material Weight</td>';
        echo '<td>' . Production_Goals_Utilities::format_weight($project_stats['group_total_weight']) . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td>Active Contributors</td>';
        echo '<td>' . $project_stats['contributor_count'] . '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>';
        
        // Part details
        if (!empty($project_stats['part_details'])) {
            echo '<div class="pg-stats-section">';
            echo '<h3>Part Details</h3>';
            
            echo '<table class="pg-table">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Part Name</th>';
            echo '<th>Your Quantity</th>';
            echo '<th>Group Total</th>';
            echo '<th>Your Contribution %</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($project_stats['part_details'] as $part) {
                echo '<tr>';
                echo '<td>' . esc_html($part['name']) . '</td>';
                echo '<td>' . number_format($part['user_quantity']) . '</td>';
                echo '<td>' . number_format($part['group_quantity']) . '</td>';
                echo '<td>' . $part['user_percentage'] . '%</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
    }

    /**
     * Calculate statistics for a project
     */
    private function calculate_project_statistics($project_id, $user_id) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        
        // Get all parts for the project
        $parts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $parts_table WHERE project_id = %d",
            $project_id
        ));
        
        // Initialize counters
        $user_total_parts = 0;
        $user_total_length = 0;
        $user_total_weight = 0;
        
        $group_total_parts = 0;
        $group_total_length = 0;
        $group_total_weight = 0;
        
        $part_details = array();
        $contributors = array();
        
        // Calculate statistics for each part
        foreach ($parts as $part) {
            // User's contribution to this part
            $user_quantity = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0) FROM $submissions_table WHERE part_id = %d AND user_id = %d",
                $part->id, $user_id
            ));
            
            // Group's contribution to this part
            $group_quantity = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0) FROM $submissions_table WHERE part_id = %d",
                $part->id
            ));
            
            // Get unique contributors for this part
            $part_contributors = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM $submissions_table WHERE part_id = %d",
                $part->id
            ));
            
            $contributors = array_merge($contributors, $part_contributors);
            
            // Update totals
            $user_total_parts += $user_quantity;
            $user_total_length += $user_quantity * $part->estimated_length;
            $user_total_weight += $user_quantity * $part->estimated_weight;
            
            $group_total_parts += $group_quantity;
            $group_total_length += $group_quantity * $part->estimated_length;
            $group_total_weight += $group_quantity * $part->estimated_weight;
            
            // Calculate percentage
            $user_percentage = $group_quantity > 0 ? round(($user_quantity / $group_quantity) * 100, 1) : 0;
            
            // Add to part details
            $part_details[] = array(
                'id' => $part->id,
                'name' => $part->name,
                'user_quantity' => $user_quantity,
                'group_quantity' => $group_quantity,
                'user_percentage' => $user_percentage,
                'estimated_length' => $part->estimated_length,
                'estimated_weight' => $part->estimated_weight
            );
        }
        
        // Calculate overall percentage
        $user_percentage = $group_total_parts > 0 ? round(($user_total_parts / $group_total_parts) * 100, 1) : 0;
        
        // Unique contributor count
        $contributor_count = count(array_unique($contributors));
        
        return array(
            'user_total_parts' => $user_total_parts,
            'user_total_length' => $user_total_length,
            'user_total_weight' => $user_total_weight,
            'group_total_parts' => $group_total_parts,
            'group_total_length' => $group_total_length,
            'group_total_weight' => $group_total_weight,
            'user_percentage' => $user_percentage,
            'contributor_count' => $contributor_count,
            'part_details' => $part_details
        );
    }
}

// Initialize the class
new Production_Goals_User_Interface();