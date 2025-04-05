<?php
/*
Plugin Name: Production Goals
Description: Track and manage production goals with user contributions
Version: 6.3
Author: Mr. Potato
Text Domain: production-goals
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('PRODUCTION_GOALS_VERSION', '4.0');
define('PRODUCTION_GOALS_DIR', plugin_dir_path(__FILE__));
define('PRODUCTION_GOALS_URL', plugin_dir_url(__FILE__));
define('PRODUCTION_GOALS_BASENAME', plugin_basename(__FILE__));

class Production_Goals {
    /**
     * The single instance of the class
     */
    private static $instance = null;

    /**
     * Main Production_Goals Instance
     * 
     * Ensures only one instance of Production_Goals is loaded or can be loaded
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
        $this->includes();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'public_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_production_goals_submit', array($this, 'ajax_submit_production'));
        add_action('wp_ajax_production_goals_edit_submission', array($this, 'ajax_edit_submission'));
        add_action('wp_ajax_production_goals_delete_submission', array($this, 'ajax_delete_submission'));
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core functionality
        require_once PRODUCTION_GOALS_DIR . 'includes/core/database.php';
        require_once PRODUCTION_GOALS_DIR . 'includes/core/file-handler.php';
        require_once PRODUCTION_GOALS_DIR . 'includes/core/utilities.php';
        
        // Admin functionality
        if (is_admin()) {
            require_once PRODUCTION_GOALS_DIR . 'includes/admin/admin-interface.php';
            require_once PRODUCTION_GOALS_DIR . 'includes/admin/settings.php';
        }
        
        // Public functionality
        require_once PRODUCTION_GOALS_DIR . 'includes/public/shortcodes.php';
        require_once PRODUCTION_GOALS_DIR . 'includes/public/shortcode-user-interface.php';
        require_once PRODUCTION_GOALS_DIR . 'includes/public/display-functions.php';
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
        // Update database schema for duplicate prevention
        $this->update_database_schema();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up any pending locks
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pg_lock_%'");
        
        flush_rewrite_rules();
    }

    /**
     * Update database schema to add fields for duplicate prevention
     */
    private function update_database_schema() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $submissions_table = $wpdb->prefix . "production_submissions";
        
        // Check if updated_at field already exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$submissions_table} LIKE 'updated_at'");
        if (empty($column_exists)) {
            // Add updated_at field
            $wpdb->query("ALTER TABLE {$submissions_table} ADD COLUMN updated_at DATETIME DEFAULT NULL");
            // Update all existing rows to have the same value as created_at
            $wpdb->query("UPDATE {$submissions_table} SET updated_at = created_at");
        }
        
        // Check if deleted flag already exists (for soft delete functionality)
        $deleted_exists = $wpdb->get_results("SHOW COLUMNS FROM {$submissions_table} LIKE 'deleted'");
        if (empty($deleted_exists)) {
            // Add deleted flag field
            $wpdb->query("ALTER TABLE {$submissions_table} ADD COLUMN deleted TINYINT(1) DEFAULT 0");
        }
        
        // Check if deleted_at timestamp already exists
        $deleted_at_exists = $wpdb->get_results("SHOW COLUMNS FROM {$submissions_table} LIKE 'deleted_at'");
        if (empty($deleted_at_exists)) {
            // Add deleted_at field
            $wpdb->query("ALTER TABLE {$submissions_table} ADD COLUMN deleted_at DATETIME DEFAULT NULL");
        }
        
        // Add indexes to improve query performance
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_user_part ON {$submissions_table} (user_id, part_id)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_created_at ON {$submissions_table} (created_at)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_updated_at ON {$submissions_table} (updated_at)");
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $projects_table = $wpdb->prefix . "production_projects";
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        $completed_table = $wpdb->prefix . "production_completed";
        $materials_table = $wpdb->prefix . "project_materials";
        $files_table = $wpdb->prefix . "file_downloads";
        $logs_table = $wpdb->prefix . "file_download_logs";

        // Create projects table
        $sql1 = "CREATE TABLE IF NOT EXISTS $projects_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            url VARCHAR(255) DEFAULT NULL,
            material VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        // Create parts table
        $sql2 = "CREATE TABLE IF NOT EXISTS $parts_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            goal INT UNSIGNED DEFAULT 0,
            progress INT UNSIGNED DEFAULT 0,
            lifetime_total INT UNSIGNED DEFAULT 0,
            estimated_length FLOAT DEFAULT 0,
            estimated_weight FLOAT DEFAULT 0,
            start_date DATETIME DEFAULT NULL
        ) $charset_collate;";

        // Create submissions table (with updated fields for duplicate prevention)
        $sql3 = "CREATE TABLE IF NOT EXISTS $submissions_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            part_id INT NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            username VARCHAR(60) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            deleted TINYINT(1) DEFAULT 0,
            deleted_at DATETIME DEFAULT NULL
        ) $charset_collate;";

        // Create completed projects table
        $sql4 = "CREATE TABLE IF NOT EXISTS $completed_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            completed_date DATETIME NOT NULL,
            user_contributions TEXT NOT NULL
        ) $charset_collate;";

        // Create materials table
        $sql5 = "CREATE TABLE IF NOT EXISTS $materials_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            material VARCHAR(255) NOT NULL
        ) $charset_collate;";

        // Create files table
        $sql6 = "CREATE TABLE IF NOT EXISTS $files_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_name VARCHAR(255) NOT NULL,
            file_url VARCHAR(255) NOT NULL,
            download_count INT DEFAULT 0,
            last_download DATETIME,
            random_token VARCHAR(10) UNIQUE,
            allowed_roles TEXT,
            project_id INT NULL
        ) $charset_collate;";

        // Create download logs table
        $sql7 = "CREATE TABLE IF NOT EXISTS $logs_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            user_id INT NULL,
            download_date DATETIME
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        dbDelta($sql5);
        dbDelta($sql6);
        dbDelta($sql7);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts() {
        wp_enqueue_style('production-goals-admin', PRODUCTION_GOALS_URL . 'assets/css/admin.css', array(), PRODUCTION_GOALS_VERSION);
        wp_enqueue_script('production-goals-admin', PRODUCTION_GOALS_URL . 'assets/js/admin.js', array('jquery'), PRODUCTION_GOALS_VERSION, true);
        
        // Pass variables to JS
        wp_localize_script('production-goals-admin', 'productionGoalsAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('production-goals-admin'),
            'deleteConfirm' => __('Are you sure you want to delete this item?', 'production-goals')
        ));
    }

    /**
     * Enqueue public scripts and styles
     */
    public function public_scripts() {
        wp_enqueue_style('production-goals-public', PRODUCTION_GOALS_URL . 'assets/css/public.css', array(), PRODUCTION_GOALS_VERSION);
        wp_enqueue_script('production-goals-public', PRODUCTION_GOALS_URL . 'assets/js/public.js', array('jquery'), PRODUCTION_GOALS_VERSION, true);
        
        // Pass variables to JS
        wp_localize_script('production-goals-public', 'productionGoals', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('production-goals-public'),
            'successMsg' => __('Submission successful!', 'production-goals'),
            'errorMsg' => __('There was an error. Please try again.', 'production-goals')
        ));
        
        // Add user ID for duplicate submission prevention
        $user_id = get_current_user_id();
        wp_localize_script('production-goals-public', 'pgUserData', array(
            'userId' => $user_id
        ));
    }
    
    /**
     * AJAX handler for submitting production
     * Improved to prevent duplicates but allow rapid sequential submissions
     */
    public function ajax_submit_production() {
        check_ajax_referer('production-goals-public', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $user_info = get_userdata($user_id);
        $username = $user_info ? $user_info->user_login : 'User';
        
        $part_id = isset($_POST['part_id']) ? intval($_POST['part_id']) : 0;
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
        $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        
        // Get submission ID for duplicate prevention
        $submission_id = isset($_POST['submission_id']) ? sanitize_text_field($_POST['submission_id']) : '';
        
        if (!$part_id || !$quantity || $quantity <= 0) {
            wp_send_json_error(array('message' => 'Invalid data provided.'));
        }

        // Verify part exists and is active
        $parts_table = $wpdb->prefix . "production_parts";
        $part = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $parts_table WHERE id = %d AND project_id = %d",
            $part_id, $project_id
        ));
        
        if (!$part) {
            wp_send_json_error(array('message' => 'Part not found.'));
        }

        // Check submission ID in transient (shorter duration to allow sequential submissions)
        if (!empty($submission_id)) {
            $transient_key = 'pg_submission_' . md5($user_id . '_' . $part_id . '_' . $submission_id);
            if (get_transient($transient_key)) {
                // This is a duplicate submission
                wp_send_json_error(array(
                    'message' => 'This submission has already been processed.',
                    'duplicate' => true
                ));
            }
        }
        
        // Check if exact same submission was made in last 10 seconds (prevents rapid duplicates but allows different quantities)
        $submissions_table = $wpdb->prefix . "production_submissions";
        $recent_exact_submission = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $submissions_table 
             WHERE user_id = %d 
             AND part_id = %d 
             AND quantity = %d 
             AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)",
            $user_id, $part_id, $quantity
        ));
        
        if ($recent_exact_submission > 0) {
            // This is a duplicate of exact same quantity
            wp_send_json_error(array(
                'message' => 'This exact quantity was just submitted. Please wait a moment or use a different quantity.',
                'duplicate' => true
            ));
        }
        
        try {
            // Use transaction for data consistency
            $wpdb->query('START TRANSACTION');
            
            // Insert submission
            $result = $wpdb->insert($submissions_table, array(
                'user_id' => $user_id,
                'part_id' => $part_id,
                'quantity' => $quantity,
                'username' => $username,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
            
            if (!$result) {
                throw new Exception('Failed to submit contribution.');
            }
            
            // Update part progress
            $wpdb->query($wpdb->prepare(
                "UPDATE $parts_table SET progress = progress + %d, lifetime_total = lifetime_total + %d WHERE id = %d",
                $quantity, $quantity, $part_id
            ));
            
            // Store submission ID in transient (short expiration to allow sequential submissions)
            if (!empty($submission_id)) {
                set_transient($transient_key, true, 60); // Expires after 1 minute
            }
            
            // Store ID in user meta for longer-term deduplication of exact duplicates
            $this->store_submission_id_in_meta($user_id, $submission_id);
            
            // Get part goal for response
            $part_goal = $part->goal;
            
            // Check if project is completed
            $this->check_project_completion($project_id);
            
            // Get updated progress
            $updated_progress = $wpdb->get_var($wpdb->prepare(
                "SELECT progress FROM $parts_table WHERE id = %d",
                $part_id
            ));
            
            // Get user's total contribution to this part
            $user_contribution = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(quantity) FROM $submissions_table WHERE part_id = %d AND user_id = %d AND created_at >= %s",
                $part_id, $user_id, $part->start_date ?: '1970-01-01 00:00:00'
            ));
            
            // Commit the transaction
            $wpdb->query('COMMIT');
            
            wp_send_json_success(array(
                'message' => 'Contribution submitted successfully!',
                'partId' => $part_id,
                'newProgress' => $updated_progress,
                'goal' => $part_goal,
                'userContribution' => $user_contribution,
                'submissionId' => $submission_id
            ));
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for editing a submission
     * Improved to prevent duplicates while allowing rapid sequential edits
     */
    public function ajax_edit_submission() {
        check_ajax_referer('production-goals-public', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        $new_quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
        
        // Get edit ID for duplicate prevention
        $edit_id = isset($_POST['edit_id']) ? sanitize_text_field($_POST['edit_id']) : '';
        
        if (!$submission_id || $new_quantity <= 0) {
            wp_send_json_error(array('message' => 'Invalid data provided.'));
        }
        
        // Check edit ID in transient
        if (!empty($edit_id)) {
            $transient_key = 'pg_edit_' . md5($user_id . '_' . $submission_id . '_' . $edit_id);
            if (get_transient($transient_key)) {
                // This is a duplicate edit
                wp_send_json_error(array(
                    'message' => 'This edit has already been processed.',
                    'duplicate' => true
                ));
            }
        }
        
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        
        // Start transaction for data consistency
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get current submission
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $submissions_table WHERE id = %d AND user_id = %d FOR UPDATE",
                $submission_id, $user_id
            ));
            
            if (!$submission) {
                throw new Exception('Submission not found or you do not have permission.');
            }
            
            // Check for very recent edit to same quantity (anti-rapid-duplicate)
            $recent_edit = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $submissions_table 
                 WHERE id = %d AND user_id = %d AND quantity = %d 
                 AND updated_at > DATE_SUB(NOW(), INTERVAL 3 SECOND)",
                $submission_id, $user_id, $new_quantity
            ));
            
            if ($recent_edit > 0) {
                throw new Exception('This edit was just processed. Please wait a moment.');
            }
            
            // Calculate difference
            $quantity_diff = $new_quantity - $submission->quantity;
            
            // Update part progress
            $wpdb->query($wpdb->prepare(
                "UPDATE $parts_table SET progress = progress + %d, lifetime_total = lifetime_total + %d WHERE id = %d",
                $quantity_diff, $quantity_diff, $submission->part_id
            ));
            
            // Update submission with current timestamp
            $wpdb->update($submissions_table, 
                array(
                    'quantity' => $new_quantity,
                    'updated_at' => current_time('mysql')
                ), 
                array(
                    'id' => $submission_id
                )
            );
            
            // Get project ID
            $project_id = $wpdb->get_var($wpdb->prepare(
                "SELECT project_id FROM $parts_table WHERE id = %d",
                $submission->part_id
            ));
            
            // Get part info
            $part = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $parts_table WHERE id = %d",
                $submission->part_id
            ));
            
            // Check if project is completed
            $this->check_project_completion($project_id);
            
            // Get updated progress
            $updated_progress = $wpdb->get_var($wpdb->prepare(
                "SELECT progress FROM $parts_table WHERE id = %d",
                $submission->part_id
            ));
            
            // Store edit ID in transient for deduplication
            if (!empty($edit_id)) {
                set_transient($transient_key, true, 60); // Expires after 1 minute
                
                // Also store in user meta for longer-term records
                $this->store_edit_id_in_meta($user_id, $edit_id);
            }
            
            // Commit the transaction
            $wpdb->query('COMMIT');
            
            wp_send_json_success(array(
                'message' => 'Submission updated successfully!',
                'partId' => $submission->part_id,
                'newProgress' => $updated_progress,
                'goal' => $part->goal,
                'editId' => $edit_id
            ));
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for deleting a submission
     * Improved to prevent duplicate deletions
     */
    public function ajax_delete_submission() {
        check_ajax_referer('production-goals-public', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        
        // Get delete ID for duplicate prevention
        $delete_id = isset($_POST['delete_id']) ? sanitize_text_field($_POST['delete_id']) : '';
        
        if (!$submission_id) {
            wp_send_json_error(array('message' => 'Invalid submission ID.'));
        }
        
        // Check delete ID in transient
        if (!empty($delete_id)) {
            $transient_key = 'pg_delete_' . md5($user_id . '_' . $submission_id . '_' . $delete_id);
            if (get_transient($transient_key)) {
                // This is a duplicate deletion
                wp_send_json_error(array(
                    'message' => 'This deletion has already been processed.',
                    'duplicate' => true
                ));
            }
        }
        
        // Check if the submission exists
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        
        $submission_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $submissions_table WHERE id = %d AND user_id = %d",
            $submission_id, $user_id
        ));
        
        if ($submission_exists === '0') {
            // Submission already deleted or doesn't exist
            wp_send_json_error(array(
                'message' => 'This submission appears to have already been deleted.',
                'duplicate' => true
            ));
        }
        
        // Start transaction to ensure data consistency
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get submission with FOR UPDATE to lock the row
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $submissions_table WHERE id = %d AND user_id = %d FOR UPDATE",
                $submission_id, $user_id
            ));
            
            if (!$submission) {
                throw new Exception('Submission not found or you do not have permission.');
            }
            
            // Update part progress
            $wpdb->query($wpdb->prepare(
                "UPDATE $parts_table SET progress = progress - %d, lifetime_total = lifetime_total - %d WHERE id = %d",
                $submission->quantity, $submission->quantity, $submission->part_id
            ));
            
            // Delete submission (or mark as deleted if using soft deletes)
            $wpdb->delete($submissions_table, array('id' => $submission_id));
            
            // Get part info
            $part = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $parts_table WHERE id = %d",
                $submission->part_id
            ));
            
            // Get updated progress
            $updated_progress = $wpdb->get_var($wpdb->prepare(
                "SELECT progress FROM $parts_table WHERE id = %d",
                $submission->part_id
            ));
            
            // Store delete ID in transient for deduplication
            if (!empty($delete_id)) {
                set_transient($transient_key, true, 60); // Expires after 1 minute
                
                // Also store in user meta for longer-term records
                $this->store_delete_id_in_meta($user_id, $delete_id);
            }
            
            // Commit the transaction
            $wpdb->query('COMMIT');
            
            wp_send_json_success(array(
                'message' => 'Submission deleted successfully!',
                'partId' => $submission->part_id,
                'newProgress' => $updated_progress,
                'goal' => $part->goal,
                'deleteId' => $delete_id
            ));
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Store submission ID in user meta
     */
    private function store_submission_id_in_meta($user_id, $submission_id) {
        if (empty($submission_id)) {
            return;
        }
        
        $processed_submissions = get_user_meta($user_id, 'pg_processed_submissions', true);
        if (empty($processed_submissions) || !is_array($processed_submissions)) {
            $processed_submissions = array();
        }
        
        // Add the new submission ID and keep only the most recent 100
        array_unshift($processed_submissions, $submission_id);
        if (count($processed_submissions) > 100) {
            $processed_submissions = array_slice($processed_submissions, 0, 100);
        }
        
        update_user_meta($user_id, 'pg_processed_submissions', $processed_submissions);
    }
    
    /**
     * Store edit ID in user meta
     */
    private function store_edit_id_in_meta($user_id, $edit_id) {
        if (empty($edit_id)) {
            return;
        }
        
        $processed_edits = get_user_meta($user_id, 'pg_processed_edits', true);
        if (empty($processed_edits) || !is_array($processed_edits)) {
            $processed_edits = array();
        }
        
        // Add the new edit ID and keep only the most recent 100
        array_unshift($processed_edits, $edit_id);
        if (count($processed_edits) > 100) {
            $processed_edits = array_slice($processed_edits, 0, 100);
        }
        
        update_user_meta($user_id, 'pg_processed_edits', $processed_edits);
    }
    
    /**
     * Store delete ID in user meta
     */
    private function store_delete_id_in_meta($user_id, $delete_id) {
        if (empty($delete_id)) {
            return;
        }
        
        $processed_deletions = get_user_meta($user_id, 'pg_processed_deletions', true);
        if (empty($processed_deletions) || !is_array($processed_deletions)) {
            $processed_deletions = array();
        }
        
        // Add the new delete ID and keep only the most recent 100
        array_unshift($processed_deletions, $delete_id);
        if (count($processed_deletions) > 100) {
            $processed_deletions = array_slice($processed_deletions, 0, 100);
        }
        
        update_user_meta($user_id, 'pg_processed_deletions', $processed_deletions);
    }
    
    /**
     * Check if a project is completed and mark it as such if needed
     */
    private function check_project_completion($project_id) {
        global $wpdb;
        
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        $projects_table = $wpdb->prefix . "production_projects";
        $completed_table = $wpdb->prefix . "production_completed";
        
        // Check if all parts have reached their goals
        $all_parts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $parts_table WHERE project_id = %d AND start_date IS NOT NULL",
            $project_id
        ));
        
        if (empty($all_parts)) {
            return false;
        }
        
        $all_completed = true;
        foreach ($all_parts as $part) {
            if ($part->progress < $part->goal) {
                $all_completed = false;
                break;
            }
        }
        
        if (!$all_completed) {
            return false;
        }
        
        // Get project name
        $project_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM $projects_table WHERE id = %d",
            $project_id
        ));
        
        // Prepare user contributions data
        $user_contributions = array();
        foreach ($all_parts as $part) {
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
                $part_contributions[] = array(
                    'user' => $user_info->user_login,
                    'total' => $contribution->total
                );
            }
            
            $user_contributions[] = array(
                'part_name' => $part->name,
                'goal' => $part->goal,
                'progress' => $part->progress,
                'contributions' => $part_contributions
            );
        }
        
        // Insert the completed project
        $wpdb->insert($completed_table, array(
            'project_id' => $project_id,
            'project_name' => $project_name,
            'completed_date' => current_time('mysql'),
            'user_contributions' => json_encode($user_contributions)
        ));
        
        // Reset all parts progress
        $wpdb->query($wpdb->prepare(
            "UPDATE $parts_table SET progress = 0, start_date = NULL WHERE project_id = %d",
            $project_id
        ));
        
        return true;
    }
}

/**
 * Cleanup function for locks
 */
function pg_cleanup_lock($lock_key) {
    delete_option($lock_key);
}
add_action('pg_cleanup_lock', 'pg_cleanup_lock');

// Start the plugin
function Production_Goals() {
    return Production_Goals::instance();
}

// Initialize the plugin
Production_Goals();