<?php
/**
 * Shortcodes for Production Goals
 * 
 * This file contains all the shortcodes for the Production Goals plugin except for 
 * the [my_projects] shortcode which is defined in shortcode-user-interface.php.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Production_Goals_Shortcodes {
    /**
     * Constructor
     */
    public function __construct() {
        // Register all shortcodes
        add_shortcode('production_goal', array($this, 'production_goal_shortcode'));
        add_shortcode('all_projects', array($this, 'all_projects_shortcode'));
        add_shortcode('all_projects_nolink', array($this, 'all_projects_nolink_shortcode'));
        add_shortcode('most_unfulfilled_goals', array($this, 'most_unfulfilled_goals_shortcode'));
        add_shortcode('static_unfulfilled_projects', array($this, 'static_unfulfilled_projects_shortcode'));
        add_shortcode('production_goal_ticker', array($this, 'production_goal_ticker_shortcode'));
        // Note: group_totals, user_lifetime_contributions, historical_submissions, and corrections
        // shortcodes have been removed as they're now included in [my_projects]
    }
    
    /**
     * Production Goal Shortcode
     * Displays a project's progress and allows submissions
     */
    public function production_goal_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts);
        
        $project_id = intval($atts['id']);
        if ($project_id === 0) {
            return '<p>Invalid project ID.</p>';
        }
        
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        
        // Get project parts
        $parts = Production_Goals_DB::get_active_project_parts($project_id);
        
        if (empty($parts)) {
            return '<p>No active parts for this project.</p>';
        }
        
        // Check if user is logged in
        $user_id = get_current_user_id();
        $user_info = get_userdata($user_id);
        $username = $user_info ? $user_info->user_login : 'User';
        
        // Start output buffering
        ob_start();
        
        // Styles - Updated with Ukraine colors (blue and yellow) and improvements
        ?>
        <style>
            .production-progress {
                width: 100%;
                max-width: 800px;
                margin: auto;
                padding: 15px;
                background: white;
                border-radius: 8px;
                box-shadow: 0px 2px 10px rgba(0, 0, 0, 0.2);
                color: black;
                font-family: Arial, sans-serif;
            }
            .production-progress h2 {
                text-align: center;
                margin-bottom: 10px;
                color: #333;
                font-size: 22px;
                font-weight: bold;
            }
            .success-message {
                background: #0057b7; /* Ukraine blue */
                color: white;
                padding: 10px;
                border-radius: 5px;
                text-align: center;
                font-weight: bold;
                margin-bottom: 15px;
            }
            .error-message {
                background: #555; /* Neutral gray instead of red */
                color: white;
                padding: 10px;
                border-radius: 5px;
                text-align: center;
                font-weight: bold;
                margin-bottom: 15px;
            }
            .progress-table {
                width: 100%;
                border-collapse: collapse;
                background: white;
                margin-bottom: 15px;
            }
            .progress-table th, .progress-table td {
                border: 1px solid #ddd;
                padding: 10px;
                text-align: center;
                color: black;
                font-size: 14px;
            }
            .progress-table th {
                background: #0057b7; /* Ukraine blue */
                color: white;
                font-size: 16px;
                font-weight: bold;
            }
            .progress-table td {
                background: #f9f9f9;
            }
            .progress-table input {
                width: 60px;
                text-align: center;
                padding: 6px;
                border-radius: 4px;
                border: 1px solid #aaa;
                font-size: 14px;
                color: black !important;
                background: #fff;
            }
            .progress-table input::placeholder {
                color: gray;
                opacity: 0.8;
            }
            .submit-button {
                display: block;
                width: 100%;
                background: #0057b7; /* Ukraine blue */
                color: white;
                padding: 12px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 18px;
                font-weight: bold;
                margin-top: 15px;
                transition: background 0.2s ease-in-out;
            }
            .submit-button:hover {
                background: #003b7e; /* Darker blue */
            }
            .submit-button:disabled {
                background: #6c757d; /* Gray when disabled */
                cursor: not-allowed;
            }
            .progress-bar-container {
                background-color: #ddd;
                border-radius: 5px;
                height: 10px;
                width: 100%;
                position: relative;
                margin-top: 5px;
            }
            .progress-bar {
                background-color: #ffd700; /* Ukraine yellow */
                height: 100%;
                border-radius: 5px;
                width: 0%;
                transition: width 0.3s ease-in-out;
            }
            .submission-result {
                margin-top: 15px;
            }
            .submission-spinner {
                display: none;
                text-align: center;
                margin: 10px 0;
            }
            .submission-spinner::after {
                content: "";
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid #0057b7;
                border-radius: 50%;
                border-top-color: transparent;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            @media (max-width: 600px) {
                .progress-table th, .progress-table td {
                    font-size: 13px;
                    padding: 8px;
                }
                .progress-table input {
                    width: 50px;
                    font-size: 13px;
                }
                .submit-button {
                    font-size: 16px;
                    padding: 10px;
                }
            }
        </style>
        
        <div class="production-progress" id="production-progress-<?php echo esc_attr($project_id); ?>">
            <?php if (!$user_id): ?>
                <div class="error-message">
                    You must be logged in to contribute!
                </div>
            <?php endif; ?>
            
            <h2>Project Progress</h2>
            
            <form method="POST" id="production-goal-form" data-project-id="<?php echo esc_attr($project_id); ?>">
                <input type="hidden" name="project_id" value="<?php echo esc_attr($project_id); ?>">
                <table class="progress-table">
                    <thead>
                        <tr>
                            <th>Part Name</th>
                            <th>Progress / Goal</th>
                            <th>Your Contributions</th>
                            <th>New Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parts as $part): 
                            // Get user's contribution to this part
                            $user_submissions = Production_Goals_DB::get_user_submissions_for_part($part->id, $user_id);
                            $user_total = 0;
                            
                            foreach ($user_submissions as $submission) {
                                $user_total += $submission->quantity;
                            }
                            
                            // Calculate percentage for progress bar
                            $percentage = ($part->goal > 0) ? ($part->progress / $part->goal) * 100 : 0;
                        ?>
                            <tr data-part-id="<?php echo esc_attr($part->id); ?>">
                                <td><?php echo esc_html($part->name); ?></td>
                                <td>
                                    <div class="part-progress"><?php echo esc_html($part->progress); ?> / <?php echo esc_html($part->goal); ?></div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: <?php echo esc_attr(min(100, $percentage)); ?>%"></div>
                                    </div>
                                </td>
                                <td class="user-contribution"><?php echo esc_html($user_total); ?></td>
                                <td>
                                    <input type="number" name="quantity" min="0" placeholder="Qty" <?php echo !$user_id ? 'disabled' : ''; ?>>
                                    <input type="hidden" name="part_id" value="<?php echo esc_attr($part->id); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="submission-spinner" id="submission-spinner"></div>
                <button type="submit" class="submit-button" name="submit_production" <?php echo !$user_id ? 'disabled' : ''; ?>>Submit</button>
            </form>
            
            <div id="submission-result" class="submission-result"></div>
        </div>
        
        <script>
 jQuery(document).ready(function($) {
    // Generate a unique form ID to prevent duplicate submissions
    const formUniqueId = 'form-' + Math.random().toString(36).substring(2, 15);
    const form = $('#production-goal-form');
    
    // Mark the form as already initialized to prevent duplicate handlers
    form.attr({
        'data-form-id': formUniqueId,
        'data-initialized': 'true'
    });
    
    // Don't add another submit handler here - the one in public.js will handle it
    
    // Create countdown elements for each row if they don't exist
    form.find('tr').each(function() {
        const row = $(this);
        const partId = row.find('input[name="part_id"]').val();
        
        if (partId && !row.find('.pg-countdown').length) {
            $('<div class="pg-countdown" data-part-id="' + partId + '"></div>')
                .css({
                    'display': 'none',
                    'color': '#E91E63',
                    'font-weight': 'bold',
                    'margin-top': '5px',
                    'text-align': 'center'
                })
                .appendTo(row.find('td:last'));
        }
    });
});
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * All Projects Shortcode
     * Displays all projects with search and pagination
     */
    public function all_projects_shortcode($atts) {
        global $wpdb;
        $projects_table = $wpdb->prefix . 'production_projects';

        // Get search query if available
        $search_query = isset($_GET['project_search']) ? sanitize_text_field($_GET['project_search']) : '';

        // Set items per page (9 for desktop, 6 for mobile)
        $projects_per_page = (wp_is_mobile()) ? 6 : 9;

        // Get current page number from query param
        $current_page = isset($_GET['project_page']) ? max(1, intval($_GET['project_page'])) : 1;
        $offset = ($current_page - 1) * $projects_per_page;

        // Modify query for search functionality
        $where_clause = !empty($search_query) ? "WHERE name LIKE %s" : "";
        $search_param = !empty($search_query) ? '%' . $wpdb->esc_like($search_query) . '%' : '';

        // Get total projects count
        $total_projects = !empty($search_query) 
            ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $projects_table $where_clause", $search_param))
            : $wpdb->get_var("SELECT COUNT(*) FROM $projects_table");

        $total_pages = max(1, ceil($total_projects / $projects_per_page));

        // Fetch paginated projects
        $query = "SELECT id, name AS project_name, url AS project_url 
                  FROM $projects_table 
                  $where_clause
                  ORDER BY name ASC 
                  LIMIT %d OFFSET %d";

        $projects = !empty($search_query)
            ? $wpdb->get_results($wpdb->prepare($query, $search_param, $projects_per_page, $offset))
            : $wpdb->get_results($wpdb->prepare($query, $projects_per_page, $offset));

        // Generate pagination URLs
        $base_url = remove_query_arg(['project_page']);
        $prev_page_url = ($current_page > 1) ? esc_url(add_query_arg(['project_page' => $current_page - 1, 'project_search' => $search_query], $base_url)) : '#';
        $next_page_url = ($current_page < $total_pages) ? esc_url(add_query_arg(['project_page' => $current_page + 1, 'project_search' => $search_query], $base_url)) : '#';

        // Render the HTML output
        ob_start();
        ?>
        <!-- Search Bar -->
        <div class="project-search-container">
            <input type="text" id="project-search" placeholder="Search projects..." value="<?php echo esc_attr($search_query); ?>" autocomplete="off">
            <button id="search-button">🔍 Search</button>
        </div>

        <div class="all-projects-grid">
            <?php if (empty($projects)): ?>
                <p>No projects found.</p>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <div class="project-tile">
                        <div class="image-container">
                            <?php
                            // Fetch the featured image
                            $featured_image = Production_Goals_Utilities::get_featured_image_from_url($project->project_url);
                            if (!$featured_image) {
                                $featured_image = plugin_dir_url(PRODUCTION_GOALS_BASENAME) . 'assets/images/default-placeholder.png';
                            }
                            ?>
                            <a href="<?php echo esc_url($project->project_url); ?>">
                                <img src="<?php echo esc_url($featured_image); ?>" alt="Project Image">
                            </a>
                        </div>
                        <div class="details">
                            <h3 class="project-title">
                                <a href="<?php echo esc_url($project->project_url); ?>">
                                    <?php echo esc_html($project->project_name); ?>
                                </a>
                            </h3>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination Buttons -->
        <div class="pagination-container">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo $prev_page_url; ?>" class="pagination-button">← Previous</a>
            <?php endif; ?>

            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo $next_page_url; ?>" class="pagination-button">Next →</a>
            <?php endif; ?>
        </div>

        <style>
        /* Search Bar Container */
        .project-search-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            height: 44px;
        }

        /* Search Input Field */
        #project-search {
            padding: 10px;
            width: 60%;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            height: 44px;
            color: black !important;
            box-sizing: border-box;
        }

        /* Placeholder Text */
        #project-search::placeholder {
            color: black !important;
            opacity: 1;
        }

        /* Search Button */
        #search-button {
            height: 44px;
            padding: 10px 18px;
            font-size: 16px;
            background: #1E90FF;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            box-sizing: border-box;
        }

        #search-button:hover {
            background: #0056b3;
        }

        /* Projects Grid Layout */
        .all-projects-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            justify-items: center;
        }

        /* Individual Project Tile */
        .project-tile {
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            background-color: #fff;
            width: 100%;
            max-width: 320px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
        }

        .project-tile img {
            width: 100%;
            height: auto;
            max-height: 250px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        /* Project Titles */
        .project-title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            margin-top: 5px;
        }

        .project-title a {
            color: black !important;
            text-decoration: none;
        }

        .project-title a:hover {
            color: #333 !important;
        }

        /* Pagination Buttons */
        .pagination-container {
            text-align: center;
            margin-top: 20px;
        }

        .pagination-button {
            display: inline-block;
            background: #1E90FF;
            color: white;
            padding: 15px 25px;
            font-size: 18px;
            border-radius: 5px;
            text-decoration: none;
            margin: 10px;
        }

        .pagination-button:hover {
            background: #0056b3;
        }

        /* Responsive Adjustments */
        @media (max-width: 1024px) {
            .all-projects-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .all-projects-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .all-projects-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        </style>

        <script>
            document.getElementById('search-button').addEventListener('click', function() {
                let searchQuery = document.getElementById('project-search').value;
                let newUrl = new URL(window.location.href);
                newUrl.searchParams.set('project_search', searchQuery);
                newUrl.searchParams.delete('project_page');
                window.location.href = newUrl.toString();
            });

            document.getElementById('project-search').addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    document.getElementById('search-button').click();
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * All Projects No Link Shortcode
     * Similar to all_projects but without linking to project pages
     */
    public function all_projects_nolink_shortcode($atts) {
        global $wpdb;
        $projects_table = $wpdb->prefix . 'production_projects';

        // Get search query if available
        $search_query = isset($_GET['project_search']) ? sanitize_text_field($_GET['project_search']) : '';

        // Set items per page (9 for desktop, 6 for mobile)
        $projects_per_page = (wp_is_mobile()) ? 6 : 9;

        // Get current page number from query param
        $current_page = isset($_GET['project_page']) ? max(1, intval($_GET['project_page'])) : 1;
        $offset = ($current_page - 1) * $projects_per_page;

        // Modify query for search functionality
        $where_clause = !empty($search_query) ? "WHERE name LIKE %s" : "";
        $search_param = !empty($search_query) ? '%' . $wpdb->esc_like($search_query) . '%' : '';

        // Get total projects count
        $total_projects = !empty($search_query) 
            ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $projects_table $where_clause", $search_param))
            : $wpdb->get_var("SELECT COUNT(*) FROM $projects_table");

        $total_pages = max(1, ceil($total_projects / $projects_per_page));

        // Fetch paginated projects
        $query = "SELECT id, name AS project_name, url AS project_url 
                  FROM $projects_table 
                  $where_clause
                  ORDER BY name ASC 
                  LIMIT %d OFFSET %d";

        $projects = !empty($search_query)
            ? $wpdb->get_results($wpdb->prepare($query, $search_param, $projects_per_page, $offset))
            : $wpdb->get_results($wpdb->prepare($query, $projects_per_page, $offset));

        // Generate pagination URLs
        $base_url = remove_query_arg(['project_page']);
        $prev_page_url = ($current_page > 1) ? esc_url(add_query_arg(['project_page' => $current_page - 1, 'project_search' => $search_query], $base_url)) : '#';
        $next_page_url = ($current_page < $total_pages) ? esc_url(add_query_arg(['project_page' => $current_page + 1, 'project_search' => $search_query], $base_url)) : '#';

        // Render the HTML output
        ob_start();
        ?>
        <!-- Search Bar -->
        <div class="project-search-container">
            <input type="text" id="project-search" placeholder="Search projects..." value="<?php echo esc_attr($search_query); ?>" autocomplete="off">
            <button id="search-button">🔍 Search</button>
        </div>

        <div class="all-projects-grid">
            <?php if (empty($projects)): ?>
                <p>No projects found.</p>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <div class="project-tile">
                        <div class="image-container">
                            <?php
                            // Fetch the featured image
                            $featured_image = Production_Goals_Utilities::get_featured_image_from_url($project->project_url);
                            if (!$featured_image) {
                                $featured_image = plugin_dir_url(PRODUCTION_GOALS_BASENAME) . 'assets/images/default-placeholder.png';
                            }
                            ?>
                            <img src="<?php echo esc_url($featured_image); ?>" alt="Project Image">
                        </div>
                        <div class="details">
                            <h3 class="project-title">
                                <?php echo esc_html($project->project_name); ?>
                            </h3>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination Buttons with Page Numbers -->
        <div class="pagination-container">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo $prev_page_url; ?>" class="pagination-button">← Previous</a>
            <?php endif; ?>

            <?php
            // Display page numbers
            for ($i = 1; $i <= $total_pages; $i++): 
                $page_url = esc_url(add_query_arg(['project_page' => $i, 'project_search' => $search_query], $base_url));
                $active_class = ($i == $current_page) ? 'active-page' : '';
            ?>
                <a href="<?php echo $page_url; ?>" class="pagination-button <?php echo $active_class; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo $next_page_url; ?>" class="pagination-button">Next →</a>
            <?php endif; ?>
        </div>

        <style>
        /* Active page number styling */
        .active-page {
            background-color: #0056b3 !important;
            color: white !important;
            font-weight: bold;
            border: 2px solid white;
            padding: 5px 12px !important;
            font-size: 14px !important;
        }

        /* Force project title text to be black */
        .project-title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            margin-top: 5px;
            color: black !important;
        }

        /* If text is inside a span or another element, enforce black color */
        .project-title * {
            color: black !important;
        }

        /* Search Bar Container */
        .project-search-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            height: 44px;
        }

        /* Search Input Field */
        #project-search {
            padding: 10px;
            width: 60%;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            height: 44px;
            color: black !important;
            box-sizing: border-box;
        }

        /* Placeholder Text */
        #project-search::placeholder {
            color: black !important;
            opacity: 1;
        }

        /* Search Button */
        #search-button {
            height: 44px;
            padding: 10px 18px;
            font-size: 16px;
            background: #1E90FF;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            box-sizing: border-box;
        }

        #search-button:hover {
            background: #0056b3;
        }

        /* Projects Grid Layout */
        .all-projects-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            justify-items: center;
        }

        /* Individual Project Tile */
        .project-tile {
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            background-color: #fff;
            width: 100%;
            max-width: 320px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
        }

        .project-tile img {
            width: 100%;
            height: auto;
            max-height: 250px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        /* Pagination Container */
        .pagination-container {
            text-align: center;
            margin-top: 20px;
        }

        /* Large Previous & Next Buttons */
        .pagination-button {
            display: inline-block;
            background: #1E90FF;
            color: white;
            padding: 15px 25px;
            font-size: 18px;
            border-radius: 5px;
            text-decoration: none;
            margin: 10px;
        }

        /* Smaller Page Numbers */
        .pagination-button:not(.active-page):not(:first-child):not(:last-child) {
            padding: 5px 12px;
            font-size: 14px;
        }

        /* Hover Effect */
        .pagination-button:hover {
            background: #0056b3;
        }

        /* Responsive Adjustments */
        @media (max-width: 1024px) {
            .all-projects-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .all-projects-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .all-projects-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        </style>

        <script>
            document.getElementById('search-button').addEventListener('click', function() {
                let searchQuery = document.getElementById('project-search').value;
                let newUrl = new URL(window.location.href);
                newUrl.searchParams.set('project_search', searchQuery);
                newUrl.searchParams.delete('project_page');
                window.location.href = newUrl.toString();
            });

            document.getElementById('project-search').addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    document.getElementById('search-button').click();
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Most Unfulfilled Goals Shortcode
     * Displays a carousel of projects with unfulfilled goals
     */
    public function most_unfulfilled_goals_shortcode($atts) {
        global $wpdb;
        
        // Fetch projects with active goals
        $projects = Production_Goals_DB::get_unfulfilled_projects();
        
        // Handle no projects found
        if (empty($projects)) {
            return '<p>No projects found with unfulfilled active goals.</p>';
        }
        
        // Render the HTML output
        ob_start();
        ?>
        <style>
            .most-unfulfilled-goals-container {
                position: relative;
                overflow: hidden;
            }

            /* Grid container */
            .most-unfulfilled-goals-grid {
                display: flex;
                transition: transform 0.5s ease-in-out;
            }

            /* Project tile styles */
            .project-tile {
                flex: 0 0 calc(33.333% - 20px);
                margin: 0 10px;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 5px;
                text-align: center;
                background-color: #fff;
            }

            /* Title text styles */
            .project-tile h3 {
                margin: 10px 0;
                font-size: 18px;
                font-style: italic;
                color: #1E90FF !important;
            }

            .project-tile h3 a {
                text-decoration: none;
                color: inherit !important;
            }

            /* Image styles */
            .project-tile img {
                width: 100%;
                height: auto;
                border-radius: 5px;
            }

            /* Parts remaining and percentage text */
            .project-tile p.parts-remaining,
            .project-tile p.percentage-complete {
                font-size: 16px;
                color: #000 !important;
            }

            /* Progress bar container */
            .progress-bar-container {
                margin-top: 10px;
                height: 20px;
                background-color: #1E90FF;
                border-radius: 10px;
                overflow: hidden;
                position: relative;
            }

            /* Progress bar fill */
            .progress-bar {
                height: 100%;
                background-color: #FFD700;
                position: absolute;
                top: 0;
                left: 0;
            }

            /* Navigation arrows */
            .carousel-nav {
                position: absolute;
                top: 50%;
                transform: translateY(-50%);
                background-color: rgba(0, 0, 0, 0.5);
                color: #fff;
                padding: 10px;
                cursor: pointer;
                z-index: 10;
            }

            .carousel-nav.left {
                left: 0;
            }

            .carousel-nav.right {
                right: 0;
            }
            
            .project-tile p.materials {
                font-size: 14px;
                color: #555;
                margin-top: 5px;
                color: #000 !important;
            }

            /* Mobile adjustments */
            @media (max-width: 768px) {
                .project-tile {
                    flex: 0 0 calc(50% - 20px);
                }
            }
        </style>
        
        <div class="most-unfulfilled-goals-container">
            <div class="carousel-nav left">&lt;</div>
            <div class="carousel-nav right">&gt;</div>
            <div class="most-unfulfilled-goals-grid">
                <?php foreach ($projects as $project): ?>
                    <div class="project-tile">
                        <div class="image-container" style="margin-bottom: 10px;">
                            <?php
                            // Fetch the featured image
                            $featured_image = Production_Goals_Utilities::get_featured_image_from_url($project->project_url);
                            if ($featured_image): ?>
                                <a href="<?php echo esc_url($project->project_url); ?>">
                                    <img src="<?php echo esc_url($featured_image); ?>" alt="Project Image">
                                </a>
                            <?php else: ?>
                                <p style="font-size: 14px; color: #888;">No image available</p>
                            <?php endif; ?>
                        </div>
                        <div class="details">
                            <h3>
                                <a href="<?php echo esc_url($project->project_url); ?>">
                                    <?php echo esc_html($project->project_name); ?>
                                </a>
                            </h3>
                            <p class="parts-remaining">
                                <?php echo intval($project->total_remaining); ?> parts remaining
                            </p>
                            <p class="materials">
                                <span class="materials-label">Materials:</span>
                                <?php echo esc_html($project->materials ? $project->materials : 'N/A'); ?>
                            </p>
                            <p class="percentage-complete">
                                <?php echo number_format($project->percentage_complete, 2); ?>% complete
                            </p>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo esc_attr($project->percentage_complete); ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
            (function () {
                const container = document.querySelector('.most-unfulfilled-goals-container');
                const grid = container.querySelector('.most-unfulfilled-goals-grid');
                const tiles = container.querySelectorAll('.project-tile');
                const leftNav = container.querySelector('.carousel-nav.left');
                const rightNav = container.querySelector('.carousel-nav.right');

                let currentIndex = 0;
                const itemsPerView = window.innerWidth <= 768 ? 2 : 3;
                const totalBatches = Math.ceil(tiles.length / itemsPerView);

                function updateGridPosition() {
                    grid.style.transform = `translateX(-${currentIndex * 100}%)`;
                }

                function showNextBatch() {
                    currentIndex = (currentIndex + 1) % totalBatches;
                    updateGridPosition();
                }

                function showPrevBatch() {
                    currentIndex = (currentIndex - 1 + totalBatches) % totalBatches;
                    updateGridPosition();
                }

                // Attach event listeners for navigation
                rightNav.addEventListener('click', showNextBatch);
                leftNav.addEventListener('click', showPrevBatch);

                // Automatic cycling
                setInterval(showNextBatch, 10000);
            })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Static Unfulfilled Projects Shortcode
     * Displays a static grid of projects with unfulfilled goals
     */
    public function static_unfulfilled_projects_shortcode($atts) {
        global $wpdb;
        
        // Fetch projects with active unfulfilled goals
        $projects = Production_Goals_DB::get_unfulfilled_projects();
        
        // Render the HTML output
        ob_start();
        ?>
        <div class="static-unfulfilled-projects-grid">
            <?php if (empty($projects)): ?>
                <p>No projects found with active unfulfilled goals.</p>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <div class="project-tile">
                        <div class="image-container">
                            <?php
                            $featured_image = Production_Goals_Utilities::get_featured_image_from_url($project->project_url);
                            if ($featured_image): ?>
                                <a href="<?php echo esc_url($project->project_url); ?>">
                                    <img src="<?php echo esc_url($featured_image); ?>" alt="Project Image">
                                </a>
                            <?php else: ?>
                                <p style="font-size: 14px; color: #888;">No image available</p>
                            <?php endif; ?>
                        </div>
                        <div class="details">
                            <h3>
                                <a href="<?php echo esc_url($project->project_url); ?>">
                                    <?php echo esc_html($project->project_name); ?>
                                </a>
                            </h3>
                            <p class="parts-remaining">
                                <?php echo intval($project->total_remaining); ?> parts remaining
                            </p>
                           <p class="materials">
                                <span class="materials-label" style="color: black;">Materials:</span> 
                                <span style="color: black;"><?php echo esc_html($project->materials ? $project->materials : 'N/A'); ?></span>
                            </p>
                            <p class="percentage-complete">
                                <?php echo number_format($project->percentage_complete, 2); ?>% complete
                            </p>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo esc_attr($project->percentage_complete); ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <style>
            .static-unfulfilled-projects-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }

            .project-tile {
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 5px;
                text-align: center;
                background-color: #fff;
            }

            .project-tile h3 {
                margin: 10px 0;
                font-size: 18px;
                font-style: italic;
                color: #1E90FF;
            }

            .project-tile h3 a {
                text-decoration: none;
                color: #1E90FF;
            }
            
            .materials-label {
                color: black;
            }

            .project-tile p.materials span {
                color: black !important;
            }

            .project-tile img {
                width: 100%;
                height: auto;
                border-radius: 5px;
                margin-bottom: 10px;
            }

            .project-tile .progress-bar-container {
                background: #1E90FF;
                width: 100%;
                height: 20px;
                position: relative;
                margin-top: 10px;
                border-radius: 5px;
                overflow: hidden;
            }

            .project-tile .progress-bar {
                background: #FFD700;
                height: 100%;
                position: absolute;
                top: 0;
                left: 0;
            }

            .project-tile p.parts-remaining,
            .project-tile p.percentage-complete {
                font-size: 16px;
                color: #000 !important;
            }

            @media (max-width: 768px) {
                .static-unfulfilled-projects-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }

            @media (max-width: 480px) {
                .static-unfulfilled-projects-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Production Goal Ticker Shortcode
     * Displays a scrolling ticker of active projects
     */
    public function production_goal_ticker_shortcode($atts) {
        global $wpdb;
        
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        $projects_table = $wpdb->prefix . "production_projects";

        // Fetch active projects (Only projects with active goals)
        $active_projects = $wpdb->get_results("
            SELECT id, name, url FROM $projects_table 
            WHERE id IN (SELECT DISTINCT project_id FROM $parts_table WHERE start_date IS NOT NULL)
        ");

        if (!$active_projects) {
            return '<div class="production-ticker-container"><div class="production-ticker"><div class="ticker-item">No active projects.</div></div></div>';
        }

        // Default fallback image
        $fallback_image = PRODUCTION_GOALS_URL . 'assets/images/default-placeholder.png';

        // Start the ticker container
        $output = '<div class="production-ticker-wrapper">';
        $output .= '<div class="production-ticker-container">';
        $output .= '<div class="production-ticker" id="ticker-content">';

        // Duplicate content for seamless looping
        for ($i = 0; $i < 2; $i++) {
            foreach ($active_projects as $project) {
                // Extract "Project #xx" and remove the name after ":"
                $project_number = explode(':', $project->name)[0];

                // Fetch featured image
                $featured_image = Production_Goals_Utilities::get_featured_image_from_url($project->url);
                if (!$featured_image) {
                    $featured_image = $fallback_image;
                }

                // Fetch the top 3 contributors (by Bee ID)
                $top_contributors = Production_Goals_DB::get_project_top_contributors($project->id, 3);

                // Tile Layout
                $output .= '<div class="ticker-item">';
                $output .= '<div class="ticker-tile">'; // Tile container with white background
                $output .= '<img src="' . esc_url($featured_image) . '" alt="Project Image" class="ticker-project-image">'; // Project Image
                $output .= '<div class="ticker-project-title"><strong>' . esc_html($project_number) . '</strong></div>'; // Project Title

                // Contributors list (Stacked below, format "Bee #X: Y parts")
                $output .= '<div class="ticker-contributors">';
                if (!empty($top_contributors)) {
                    foreach ($top_contributors as $contributor) {
                        $output .= '<div class="ticker-user">Bee #' . esc_html($contributor->user_id) . ': ' . esc_html($contributor->total_parts) . ' parts</div>';
                    }
                } else {
                    $output .= '<div class="ticker-user">No contributions yet.</div>';
                }
                $output .= '</div>'; // Close contributors div

                $output .= '</div>'; // Close ticker-tile
                $output .= '</div>'; // Close ticker-item
            }
        }

        $output .= '</div>'; // Close ticker-content
        $output .= '</div></div>'; // Close all containers

        // Updated CSS
        $output .= '<style>
            .production-ticker-wrapper {
                width: 100%;
                display: flex;
                justify-content: center;
                overflow: hidden;
            }

            .production-ticker-container {
                width: 100%;
                max-width: 1080px;
                margin: 0 auto;
                background: white;
                padding: 12px;
                border-radius: 5px;
                position: relative;
                display: flex;
                align-items: center;
                overflow: hidden;
            }

            .production-ticker {
                display: flex;
                align-items: center;
                flex-wrap: nowrap;
                gap: 20px;
                width: max-content;
                animation: ticker-scroll 20s linear infinite;
            }

            @keyframes ticker-scroll {
                from { transform: translateX(0); }
                to { transform: translateX(-50%); }
            }

            .ticker-item {
                flex: 0 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                text-align: center;
                min-width: 180px;
            }

            .ticker-tile {
                background: white;
                padding: 10px;
                text-align: center;
                border-radius: 5px;
                box-shadow: 0px 0px 5px rgba(0, 0, 0, 0.1);
            }

            .ticker-project-image {
                height: 90px;
                width: 90px;
                object-fit: cover;
                border-radius: 5px;
                margin-bottom: 5px;
            }

            .ticker-project-title {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 5px;
                color: black !important;
            }

            .ticker-contributors {
                font-size: 14px;
                color: #555;
            }

            .ticker-user {
                font-size: 13px;
                margin: 0.25rem 0;
                color: #333;
            }
        </style>'; 

        return $output;
    }
}

// Initialize the class
new Production_Goals_Shortcodes();