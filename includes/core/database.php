<?php
/**
 * Database handler functions for Production Goals
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Production_Goals_DB {
    /**
     * Get all projects
     */
    public static function get_projects() {
        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        
        return $wpdb->get_results("SELECT * FROM {$projects_table} ORDER BY name ASC");
    }
    
    /**
     * Get a single project by ID
     */
    public static function get_project($project_id) {
        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$projects_table} WHERE id = %d",
            $project_id
        ));
    }
    
    /**
     * Get project parts
     */
    public static function get_project_parts($project_id) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$parts_table} WHERE project_id = %d ORDER BY name ASC",
            $project_id
        ));
    }
    
    /**
     * Get active project parts (those with start_date set)
     */
    public static function get_active_project_parts($project_id) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$parts_table} WHERE project_id = %d AND start_date IS NOT NULL ORDER BY name ASC",
            $project_id
        ));
    }
    
    /**
     * Get a single part by ID
     */
    public static function get_part($part_id) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$parts_table} WHERE id = %d",
            $part_id
        ));
    }
    
    /**
     * Get user submissions for a part
     */
    public static function get_user_submissions_for_part($part_id, $user_id) {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        
        // Get the start date for the part
        $start_date = $wpdb->get_var($wpdb->prepare(
            "SELECT start_date FROM {$parts_table} WHERE id = %d",
            $part_id
        ));
        
        // If no start date, return all submissions
        if (!$start_date) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$submissions_table} WHERE part_id = %d AND user_id = %d ORDER BY created_at DESC",
                $part_id, $user_id
            ));
        }
        
        // Return submissions since the start date
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$submissions_table} WHERE part_id = %d AND user_id = %d AND created_at >= %s ORDER BY created_at DESC",
            $part_id, $user_id, $start_date
        ));
    }
    
    /**
     * Get user submissions for a project
     */
    public static function get_user_submissions_for_project($project_id, $user_id) {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.name as part_name, p.goal as part_goal, p.progress as part_progress, p.start_date
             FROM {$submissions_table} s
             JOIN {$parts_table} p ON s.part_id = p.id
             WHERE p.project_id = %d AND s.user_id = %d
             ORDER BY s.created_at DESC",
            $project_id, $user_id
        ));
    }
    
    /**
     * Get active submissions for a user (submissions for parts with start_date set)
     */
    public static function get_user_active_submissions($user_id) {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        $projects_table = $wpdb->prefix . "production_projects";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.name as part_name, p.goal as part_goal, p.progress as part_progress, p.start_date,
                    pr.id as project_id, pr.name as project_name
             FROM {$submissions_table} s
             JOIN {$parts_table} p ON s.part_id = p.id
             JOIN {$projects_table} pr ON p.project_id = pr.id
             WHERE p.start_date IS NOT NULL AND s.user_id = %d AND s.created_at >= p.start_date
             ORDER BY s.created_at DESC",
            $user_id
        ));
    }
    
    /**
     * Get completed projects for a user
     */
    public static function get_user_completed_projects($user_id) {
        global $wpdb;
        $completed_table = $wpdb->prefix . "production_completed";
        
        $completed_projects = $wpdb->get_results(
            "SELECT * FROM {$completed_table} ORDER BY completed_date DESC"
        );
        
        // Filter projects where the user has contributed
        $user_projects = array();
        foreach ($completed_projects as $project) {
            $contributions = json_decode($project->user_contributions, true);
            
            $user_contributed = false;
            foreach ($contributions as $part) {
                foreach ($part['contributions'] as $contribution) {
                    $user_obj = get_user_by('login', $contribution['user']);
                    if ($user_obj && $user_obj->ID == $user_id) {
                        $user_contributed = true;
                        break 2;
                    }
                }
            }
            
            if ($user_contributed) {
                $user_projects[] = $project;
            }
        }
        
        return $user_projects;
    }
    
    /**
     * Get active projects (projects with parts that have start_date set)
     */
    public static function get_active_projects() {
        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        $parts_table = $wpdb->prefix . "production_parts";
        
        return $wpdb->get_results(
            "SELECT DISTINCT p.* FROM {$projects_table} p
             JOIN {$parts_table} pt ON p.id = pt.project_id
             WHERE pt.start_date IS NOT NULL
             ORDER BY p.name ASC"
        );
    }
    
    /**
     * Get project materials
     */
    public static function get_project_materials($project_id) {
        global $wpdb;
        $materials_table = $wpdb->prefix . "project_materials";
        
        $materials = $wpdb->get_col($wpdb->prepare(
            "SELECT material FROM {$materials_table} WHERE project_id = %d ORDER BY material ASC",
            $project_id
        ));
        
        return $materials;
    }
    
    /**
     * Get projects with unfulfilled goals
     */
    public static function get_unfulfilled_projects() {
        global $wpdb;
        $projects_table = $wpdb->prefix . "production_projects";
        $parts_table = $wpdb->prefix . "production_parts";
        
        return $wpdb->get_results(
            "SELECT p.id, p.name AS project_name, p.url AS project_url, 
                    (SELECT GROUP_CONCAT(DISTINCT m.material ORDER BY m.material ASC SEPARATOR ', ') 
                     FROM {$wpdb->prefix}project_materials m 
                     WHERE m.project_id = p.id) AS materials, 
                    SUM(CASE WHEN pt.goal > pt.progress THEN pt.goal - pt.progress ELSE 0 END) AS total_remaining, 
                    ROUND((SUM(CASE WHEN pt.goal > 0 THEN pt.progress ELSE 0 END) / NULLIF(SUM(CASE WHEN pt.goal > 0 THEN pt.goal ELSE 0 END), 0)) * 100, 2) AS percentage_complete
             FROM {$projects_table} p
             INNER JOIN {$parts_table} pt ON p.id = pt.project_id
             WHERE pt.start_date IS NOT NULL
             GROUP BY p.id, p.name, p.url
             HAVING SUM(CASE WHEN pt.goal > 0 THEN pt.goal ELSE 0 END) > 0
             ORDER BY percentage_complete ASC"
        );
    }
    
    /**
     * Get user lifetime contributions
     */
    public static function get_user_lifetime_contributions($user_id) {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        
        // Get all parts the user has contributed to
        $parts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.name, p.estimated_length, p.estimated_weight, SUM(s.quantity) as total_quantity
             FROM {$parts_table} p
             JOIN {$submissions_table} s ON s.part_id = p.id
             WHERE s.user_id = %d
             GROUP BY p.id",
            $user_id
        ));
        
        // Calculate totals
        $total_parts = 0;
        $total_length = 0;
        $total_weight = 0;
        
        foreach ($parts as $part) {
            $total_parts += $part->total_quantity;
            $total_length += ($part->estimated_length * $part->total_quantity);
            $total_weight += ($part->estimated_weight * $part->total_quantity);
        }
        
        return array(
            'parts' => $parts,
            'total_parts' => $total_parts,
            'total_length' => $total_length,
            'total_weight' => $total_weight
        );
    }
    
    /**
     * Get group lifetime contributions
     */
    public static function get_group_lifetime_contributions() {
        global $wpdb;
        $parts_table = $wpdb->prefix . "production_parts";
        $submissions_table = $wpdb->prefix . "production_submissions";
        
        // Get all parts with submissions
        $parts = $wpdb->get_results(
            "SELECT p.id, p.name, p.estimated_length, p.estimated_weight, SUM(s.quantity) as total_quantity
             FROM {$parts_table} p
             JOIN {$submissions_table} s ON s.part_id = p.id
             GROUP BY p.id"
        );
        
        // Calculate totals
        $total_parts = 0;
        $total_length = 0;
        $total_weight = 0;
        
        foreach ($parts as $part) {
            $total_parts += $part->total_quantity;
            $total_length += ($part->estimated_length * $part->total_quantity);
            $total_weight += ($part->estimated_weight * $part->total_quantity);
        }
        
        return array(
            'parts' => $parts,
            'total_parts' => $total_parts,
            'total_length' => $total_length,
            'total_weight' => $total_weight
        );
    }

    /**
     * Get top contributors for a project
     */
    public static function get_project_top_contributors($project_id, $limit = 3) {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        
        // Get the earliest start date for the project's parts
        $start_date = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(start_date) FROM {$parts_table} WHERE project_id = %d AND start_date IS NOT NULL",
            $project_id
        ));
        
        if (!$start_date) {
            return array();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, SUM(quantity) as total_parts 
             FROM {$submissions_table} 
             WHERE part_id IN (
                 SELECT id FROM {$parts_table} WHERE project_id = %d AND start_date IS NOT NULL
             )
             AND created_at >= %s
             GROUP BY user_id 
             ORDER BY total_parts DESC 
             LIMIT %d",
            $project_id, $start_date, $limit
        ));
    }
    
    /**
     * Get a single submission by ID
     */
    public static function get_submission($submission_id) {
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$submissions_table} WHERE id = %d",
            $submission_id
        ));
    }
}
