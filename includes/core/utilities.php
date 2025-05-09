<?php
/**
 * Utility functions for Production Goals
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Production_Goals_Utilities {
    /**
     * Format file size in human-readable form
     */
    public static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Format date in human-readable form
     */
    public static function format_date($date_string) {
        $date = new DateTime($date_string);
        return $date->format('F j, Y, g:i a');
    }
    
    /**
     * Format time elapsed since a given date
     */
    public static function time_elapsed($datetime) {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        
        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;
        
        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        );
        
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }
        
        if (!$string) {
            return 'just now';
        }
        
        return array_shift($string) . ' ago';
    }
    
    /**
     * Get the featured image from a URL
     */
    public static function get_featured_image_from_url($url) {
        if (!$url) {
            return false;
        }
        
        // Convert the URL to a WordPress post ID
        $post_id = url_to_postid($url);
        
        // Return the featured image URL for the post
        if (!$post_id) {
            return false;
        }
        
        $image_id = get_post_thumbnail_id($post_id);
        return $image_id ? wp_get_attachment_url($image_id) : false;
    }
    
    /**
     * Format weight (grams to kg if needed)
     */
    public static function format_weight($weight) {
        if ($weight >= 1000) {
            return number_format($weight / 1000, 2) . ' kg';
        } else {
            return number_format($weight, 2) . ' g';
        }
    }
    
    /**
     * Format length (m to km if needed)
     */
    public static function format_length($length) {
        if ($length >= 1000) {
            return number_format($length / 1000, 2) . ' km';
        } else {
            return number_format($length, 2) . ' m';
        }
    }
    
    /**
     * Create a progress bar with specified percentage
     */
    public static function progress_bar($percentage, $color_empty = '#ccc', $color_filled = 'green') {
        // Ensure percentage is between 0 and 100
        $percentage = max(0, min(100, $percentage));
        
        $output = '<div class="pg-progress-bar-container" style="background-color: ' . esc_attr($color_empty) . '; border-radius: 5px; height: 20px; width: 100%; position: relative;">';
        $output .= '<div class="pg-progress-bar" style="background-color: ' . esc_attr($color_filled) . '; height: 100%; border-radius: 5px; width: ' . esc_attr($percentage) . '%;"></div>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Get user role display names
     */
    public static function get_user_roles() {
        $roles = wp_roles()->roles;
        $role_names = array();
        
        foreach ($roles as $role_key => $role) {
            $role_names[$role_key] = $role['name'];
        }
        
        return $role_names;
    }
    
    /**
     * Sanitize and validate a quantity value
     */
    public static function sanitize_quantity($quantity) {
        $quantity = absint($quantity);
        return max(0, $quantity);
    }
    
    /**
     * Check if a user is allowed to edit a submission
     */
    public static function can_user_edit_submission($submission_id, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        global $wpdb;
        $submissions_table = $wpdb->prefix . "production_submissions";
        $parts_table = $wpdb->prefix . "production_parts";
        
        // Get the submission
        $submission = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.start_date 
             FROM {$submissions_table} s
             JOIN {$parts_table} p ON s.part_id = p.id
             WHERE s.id = %d",
            $submission_id
        ));
        
        if (!$submission) {
            return false;
        }
        
        // Check if user owns the submission
        if ($submission->user_id != $user_id) {
            return false;
        }
        
        // Check if the submission is for an active part (has start_date)
        if (!$submission->start_date) {
            return false;
        }
        
        // Check if the submission was made after the part was started
        if (strtotime($submission->created_at) < strtotime($submission->start_date)) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if a user has permission to view a project
     * Uses the same security level logic as file downloads
     *
     * @param int $project_id Project ID to check
     * @param int $user_id User ID to check (defaults to current user)
     * @return bool True if user has permission, false otherwise
     */
    public static function can_user_view_project($project_id, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Admin and WBAdmin users can view all projects
        if (current_user_can('administrator') || current_user_can('wbadmin')) {
            return true;
        }
        
        // Get the file associated with this project
        global $wpdb;
        $file_table = $wpdb->prefix . "file_downloads";
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $file_table WHERE project_id = %d ORDER BY id DESC LIMIT 1",
            $project_id
        ));
        
        // If no file is associated with this project, default to allowing view
        if (!$file || empty($file->allowed_roles)) {
            return true;
        }
        
        // Get user roles
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $user_roles = array_map('strtolower', (array) $user->roles);
        $allowed_roles = array_map('strtolower', explode(',', $file->allowed_roles));
        
        // Check if the project has any security level set
        $has_wb1 = in_array('wb1', $allowed_roles);
        $has_wb2 = in_array('wb2', $allowed_roles);
        $has_wb3 = in_array('wb3', $allowed_roles);
        
        // Now check based on the security level hierarchy
        // If user is WB1, they can see WB1 projects
        if (in_array('wb1', $user_roles) && $has_wb1) {
            return true;
        }
        
        // If user is WB2, they can see WB1 or WB2 projects
        if (in_array('wb2', $user_roles) && ($has_wb1 || $has_wb2)) {
            return true;
        }
        
        // If user is WB3, they can see WB1, WB2, or WB3 projects
        if (in_array('wb3', $user_roles)) {
            return true;
        }
        
        // Administrator and wbadmin were already handled earlier
        return false;
    }
    
    /**
     * Create a simple table from data array
     */
    public static function create_table($headers, $data, $class = '') {
        $output = '<table class="pg-table ' . esc_attr($class) . '">';
        
        // Headers
        $output .= '<thead><tr>';
        foreach ($headers as $header) {
            $output .= '<th>' . esc_html($header) . '</th>';
        }
        $output .= '</tr></thead>';
        
        // Body
        $output .= '<tbody>';
        foreach ($data as $row) {
            $output .= '<tr>';
            foreach ($row as $cell) {
                $output .= '<td>' . $cell . '</td>';
            }
            $output .= '</tr>';
        }
        $output .= '</tbody>';
        
        $output .= '</table>';
        
        return $output;
    }
}
