<?php

if (!defined('ABSPATH')) {
    exit;
}

function pg_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG && WP_DEBUG_LOG) {
        if (is_array($message) || is_object($message)) {
            $log_message = print_r($message, true);
        } else {
            $log_message = (string) $message;
        }
        $timestamp = date('Y-m-d H:i:s');
        error_log('PG Debug (' . $timestamp . '): ' . $log_message);
    }
}

function pg_debug_memory() {
    $current = round(memory_get_usage() / 1024 / 1024, 2);
    $peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
    return "Memory: Current={$current}MB, Peak={$peak}MB";
}

class Production_File_Handler {
    private $file_table;
    private $log_table;
    private $plugin_slug = 'production-goals';
    private $upload_dir;
    private $temp_dir;
    private $processing_lock = false;

    public function __construct() {
        global $wpdb;
        $this->file_table = $wpdb->prefix . 'file_downloads';
        $this->log_table = $wpdb->prefix . 'file_download_logs';

        $this->upload_dir = '/var/www/project-files/';
        if (!is_dir($this->upload_dir) && !wp_mkdir_p($this->upload_dir)) {
            $upload_base = wp_upload_dir();
            if (!empty($upload_base['basedir'])) {
                $this->upload_dir = trailingslashit($upload_base['basedir']) . 'protected-pg-files/';
            } else {
                pg_debug_log("Warning: All primary directories failed. Falling back to wp-content/protected-files/");
                $this->upload_dir = trailingslashit(WP_CONTENT_DIR) . 'protected-files/';
            }
        }
        $this->temp_dir = trailingslashit($this->upload_dir) . 'temp/';
        pg_debug_log("Using Upload Dir: " . $this->upload_dir);
        pg_debug_log("Using Temp Dir: " . $this->temp_dir);

        $this->maybe_add_original_filename_column();
        $this->maybe_add_encryption_status_column();
        $this->ensure_directories();

        add_action('production_project_saved', array($this, 'handle_project_file_upload'), 10, 2);
        add_action('init', array($this, 'process_download'), 1);
        add_action('pg_process_pending_encryptions', array($this, 'process_pending_encryptions'));
        add_shortcode('download_counter', array($this, 'download_counter_shortcode'));
        
        // Register custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Schedule the encryption processor if not already scheduled
        if (!wp_next_scheduled('pg_process_pending_encryptions')) {
            wp_schedule_event(time(), 'pg_one_minute', 'pg_process_pending_encryptions');
        }
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['pg_one_minute'] = array(
            'interval' => 60, // 60 seconds
            'display'  => __('Every Minute', 'production-goals')
        );
        return $schedules;
    }

    private function ensure_directories() {
        $dirs_to_check = [$this->upload_dir, $this->temp_dir];
        foreach ($dirs_to_check as $dir) {
            if (!is_dir($dir)) {
                pg_debug_log("Directory does not exist, attempting to create: $dir");
                if (!wp_mkdir_p($dir)) {
                    pg_debug_log("FATAL: Failed to create directory: $dir. Check parent directory permissions (e.g., " . dirname($dir) . ").");
                } else {
                    pg_debug_log("Successfully created directory: $dir");
                    $this->protect_directory($dir);
                }
            } else {
                 pg_debug_log("Directory already exists: $dir");
                 $this->protect_directory($dir);
            }
            if (!is_writable($dir)) {
                 pg_debug_log("WARNING: Directory is not writable: $dir");
            } else {
                 pg_debug_log("Directory is writable: $dir");
            }
        }
    }

    private function protect_directory($dir) {
        $dir = trailingslashit($dir);
        $htaccess_path = $dir . '.htaccess';
        $index_path = $dir . 'index.php';

        if (!file_exists($htaccess_path)) {
            $content = "<IfModule mod_authz_core.c>\n";
            $content .= "    Require all denied\n";
            $content .= "</IfModule>\n";
            $content .= "<IfModule !mod_authz_core.c>\n";
            $content .= "    Deny from all\n";
            $content .= "</IfModule>\n";
            $content .= "Options -Indexes\n";

            if (@file_put_contents($htaccess_path, $content) === false) {
                 pg_debug_log("Failed to write .htaccess to: $htaccess_path. Check permissions on directory: $dir");
            } else {
                 pg_debug_log("Created/Updated .htaccess in: $dir");
            }
        }

        if (!file_exists($index_path)) {
            $content = "<?php\n// Silence is golden.\n";
             if (@file_put_contents($index_path, $content) === false) {
                 pg_debug_log("Failed to write index.php to: $index_path. Check permissions on directory: $dir");
             } else {
                  pg_debug_log("Created index.php in: $dir");
             }
        }
    }

    private function maybe_add_original_filename_column() {
        global $wpdb;
        $table_name = $this->file_table;
        $column_name = 'original_filename';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
             pg_debug_log("Table $table_name does not exist. Cannot check/add column '$column_name'.");
             return;
        }

        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table_name, $column_name
        ));

        if (empty($column_exists)) {
            pg_debug_log("Column '$column_name' not found in '$table_name'. Attempting to add.");
            $sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `allowed_roles`";
            $result = $wpdb->query($sql);

            if ($result === false) {
                pg_debug_log("Failed to add '$column_name' column to $table_name: " . $wpdb->last_error);
            } else {
                pg_debug_log("Successfully added '$column_name' column to $table_name. Attempting to backfill.");
                $backfill_sql = "UPDATE `{$table_name}` SET `{$column_name}` = `file_name` WHERE `{$column_name}` IS NULL OR `{$column_name}` = ''";
                $backfill_result = $wpdb->query($backfill_sql);
                 pg_debug_log("Backfill result for '$column_name': " . ($backfill_result === false ? 'Failed: '.$wpdb->last_error : $backfill_result . ' rows affected.'));
            }
        } else {
             pg_debug_log("Column '$column_name' already exists in '$table_name'.");
        }
    }
    
    private function maybe_add_encryption_status_column() {
        global $wpdb;
        $table_name = $this->file_table;
        $column_name = 'encryption_status';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
             pg_debug_log("Table $table_name does not exist. Cannot check/add column '$column_name'.");
             return;
        }

        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME, $table_name, $column_name
        ));

        if (empty($column_exists)) {
            pg_debug_log("Column '$column_name' not found in '$table_name'. Attempting to add.");
            $sql = "ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` VARCHAR(20) DEFAULT 'complete' AFTER `original_filename`";
            $result = $wpdb->query($sql);

            if ($result === false) {
                pg_debug_log("Failed to add '$column_name' column to $table_name: " . $wpdb->last_error);
            } else {
                pg_debug_log("Successfully added '$column_name' column to $table_name.");
            }
        } else {
             pg_debug_log("Column '$column_name' already exists in '$table_name'.");
        }
    }

    public function generate_random_token($length = 32) {
         $length = max(16, (int) $length);
         if ($length % 2 !== 0) {
             $length++;
         }
         $bytes_needed = $length / 2;

         try {
             if (function_exists('random_bytes')) {
                 return bin2hex(random_bytes($bytes_needed));
             }
             if (function_exists('openssl_random_pseudo_bytes')) {
                  $token = openssl_random_pseudo_bytes($bytes_needed);
                  if ($token !== false) {
                      return bin2hex($token);
                  }
                  pg_debug_log("openssl_random_pseudo_bytes failed.");
             }
         } catch (Exception $e) {
              pg_debug_log("Cryptographically secure random function failed: " . $e->getMessage());
         }

         pg_debug_log("WARNING: Using insecure fallback for token generation. Check PHP OpenSSL/random extensions.");
         $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
         $token = '';
         $char_len = strlen($characters);
         for ($i = 0; $i < $length; $i++) {
             $token .= $characters[mt_rand(0, $char_len - 1)];
         }
         return $token;
    }

    private function encrypt_file($source_file, $target_file) {
        $start_time = microtime(true);
        pg_debug_log("[ENCRYPT] Starting encryption - " . pg_debug_memory());
        pg_debug_log("[ENCRYPT] Source='$source_file', Target='$target_file', Slug='{$this->plugin_slug}'");
        pg_debug_log("[ENCRYPT] Source file size: " . (file_exists($source_file) ? filesize($source_file) . " bytes" : "FILE NOT FOUND"));
        pg_debug_log("[ENCRYPT] Target file path exists: " . (file_exists(dirname($target_file)) ? "Yes" : "No"));
        pg_debug_log("[ENCRYPT] Target dir writable: " . (is_writable(dirname($target_file)) ? "Yes" : "No"));

        if (!file_exists($source_file) || !is_readable($source_file)) {
            pg_debug_log("[ENCRYPT] ERROR: Source file not found or not readable: '$source_file'");
            return false;
        }

        pg_debug_log("[ENCRYPT] Checking for spudcryption_encrypt_file function");
        if (!function_exists('spudcryption_encrypt_file')) {
            pg_debug_log("[ENCRYPT] Function not found. Falling back to copy.");
            $result = @copy($source_file, $target_file);
            pg_debug_log("[ENCRYPT] Fallback copy result: " . ($result ? 'Success' : 'Failed'));
            return $result;
        }

        pg_debug_log("[ENCRYPT] Spudcryption function exists, preparing to encrypt with plugin_slug: {$this->plugin_slug}");
        pg_debug_log("[ENCRYPT] Memory before encryption: " . pg_debug_memory());
        
        try {
            $encryption_result = spudcryption_encrypt_file($source_file, $target_file, $this->plugin_slug);
            pg_debug_log("[ENCRYPT] Encryption call completed in " . round(microtime(true) - $start_time, 2) . " seconds");
            pg_debug_log("[ENCRYPT] Memory after encryption: " . pg_debug_memory());
            pg_debug_log("[ENCRYPT] spudcryption_encrypt_file result: " . var_export($encryption_result, true));
        } catch (Exception $e) {
            pg_debug_log("[ENCRYPT] EXCEPTION during encryption: " . $e->getMessage());
            pg_debug_log("[ENCRYPT] EXCEPTION trace: " . $e->getTraceAsString());
            return false;
        }

        if ($encryption_result === true) {
            $meta_file = $target_file . '.meta';
            $target_exists = file_exists($target_file);
            $meta_exists = file_exists($meta_file);
            pg_debug_log("[ENCRYPT] Post-encryption check: Target exists='$target_exists', Meta exists='$meta_exists'");

            if (!$target_exists) {
                 pg_debug_log("[ENCRYPT] ERROR: Success reported but target file not found!");
                 if ($meta_exists) @unlink($meta_file);
                 return false;
            }
             if (!$meta_exists) {
                 pg_debug_log("[ENCRYPT] ERROR: Success reported but meta file not found!");
                 if ($target_exists) @unlink($target_file);
                 return false;
            }
            if (filesize($target_file) === 0) {
                pg_debug_log("[ENCRYPT] ERROR: Encrypted file is empty after successful report.");
                @unlink($target_file);
                if ($meta_exists) @unlink($meta_file);
                return false;
            }
        } else {
            pg_debug_log("[ENCRYPT] Encryption function returned failure.");
            if (file_exists($target_file)) @unlink($target_file);
            if (file_exists($target_file . '.meta')) @unlink($target_file . '.meta');
        }

        $total_time = round(microtime(true) - $start_time, 2);
        pg_debug_log("[ENCRYPT] Encryption process completed in $total_time seconds with result: " . ($encryption_result === true ? "SUCCESS" : "FAILURE"));
        return $encryption_result === true;
    }

    private function decrypt_file($encrypted_file, $target_file) {
        $start_time = microtime(true);
        pg_debug_log("[DECRYPT] Starting decryption - " . pg_debug_memory());
        pg_debug_log("[DECRYPT] Source='$encrypted_file', Target='$target_file', Slug='{$this->plugin_slug}'");

        if (!function_exists('spudcryption_decrypt_file')) {
            pg_debug_log("[DECRYPT] Function not found. Falling back to copy.");
            $result = @copy($encrypted_file, $target_file);
            pg_debug_log("[DECRYPT] Fallback copy result: " . ($result ? 'Success' : 'Failed'));
            return $result;
        }

        $meta_file = $encrypted_file . '.meta';
        if (!file_exists($encrypted_file)) {
             pg_debug_log("[DECRYPT] Failed: Source file not found at '$encrypted_file'");
             return false;
        }
         if (!is_readable($encrypted_file)) {
             pg_debug_log("[DECRYPT] Failed: Source file not readable at '$encrypted_file'");
             return false;
         }
         if (!file_exists($meta_file)) {
             pg_debug_log("[DECRYPT] Failed: Meta file not found at '$meta_file'");
             return false;
         }
         if (!is_readable($meta_file)) {
             pg_debug_log("[DECRYPT] Failed: Meta file not readable at '$meta_file'");
             return false;
         }

        pg_debug_log("[DECRYPT] Memory before decryption: " . pg_debug_memory());
        
        try {
            $decryption_result = spudcryption_decrypt_file($encrypted_file, $target_file, $this->plugin_slug);
            pg_debug_log("[DECRYPT] Decryption call completed in " . round(microtime(true) - $start_time, 2) . " seconds");
            pg_debug_log("[DECRYPT] Memory after decryption: " . pg_debug_memory());
            pg_debug_log("[DECRYPT] spudcryption_decrypt_file result: " . var_export($decryption_result, true));
        } catch (Exception $e) {
            pg_debug_log("[DECRYPT] EXCEPTION during decryption: " . $e->getMessage());
            pg_debug_log("[DECRYPT] EXCEPTION trace: " . $e->getTraceAsString());
            return false;
        }

        if ($decryption_result === true) {
            if (!file_exists($target_file)) {
                pg_debug_log("[DECRYPT] ERROR: Success reported but target file not found!");
                return false;
            }
            if (filesize($target_file) === 0) {
                 pg_debug_log("[DECRYPT] ERROR: Decrypted file is empty after successful report.");
                 @unlink($target_file);
                 return false;
            }
        } else {
             pg_debug_log("[DECRYPT] Decryption function returned failure.");
             if (file_exists($target_file)) @unlink($target_file);
        }

        $total_time = round(microtime(true) - $start_time, 2);
        pg_debug_log("[DECRYPT] Decryption process completed in $total_time seconds with result: " . ($decryption_result === true ? "SUCCESS" : "FAILURE"));
        return $decryption_result === true;
    }

    public function get_security_levels() {
        $default_levels = array(
            'wb1' => __('WB1 (Allows WB1, WB2, WB3)', 'production-goals'),
            'wb2' => __('WB2 (Allows WB2, WB3)', 'production-goals'),
            'wb3' => __('WB3 (Allows WB3 only)', 'production-goals')
        );
        return apply_filters('production_goals_security_levels', $default_levels);
    }

    private function security_level_to_roles($security_level) {
        $roles = array();
        switch (strtolower($security_level)) {
            case 'wb3':
                $roles = array('wb3');
                break;
            case 'wb2':
                $roles = array('wb2', 'wb3');
                break;
            case 'wb1':
            default:
                $roles = array('wb1', 'wb2', 'wb3');
                break;
        }

        $admin_roles = array('administrator', 'wbadmin');

        $final_roles = array_unique(array_merge($roles, $admin_roles));
        sort($final_roles);

        $final_roles_str = implode(',', $final_roles);
        pg_debug_log("Mapping security level '$security_level' to roles: " . $final_roles_str);
        return $final_roles_str;
    }

    public function roles_to_security_level($allowed_roles_str) {
        if (empty($allowed_roles_str) || !is_string($allowed_roles_str)) {
            pg_debug_log("Roles string empty or invalid, defaulting to security level 'wb1'");
            return 'wb1';
        }

        $allowed_roles = array_map('strtolower', array_map('trim', explode(',', $allowed_roles_str)));
        $allowed_roles = array_filter($allowed_roles);

        if (empty($allowed_roles)) {
             pg_debug_log("Roles string contained only whitespace or commas, defaulting to 'wb1'");
             return 'wb1';
        }

        $has_wb1 = in_array('wb1', $allowed_roles);
        $has_wb2 = in_array('wb2', $allowed_roles);
        $has_wb3 = in_array('wb3', $allowed_roles);

        $inferred_level = 'wb1';

        if ($has_wb3 && !$has_wb2 && !$has_wb1) {
            $inferred_level = 'wb3';
        } elseif ($has_wb2 && !$has_wb1) {
            $inferred_level = 'wb2';
        } elseif ($has_wb1) {
            $inferred_level = 'wb1';
        } else {
             pg_debug_log("Could not clearly infer WB security level from roles: [" . implode(', ', $allowed_roles) . "]. Defaulting to 'wb1'.");
             $inferred_level = 'wb1';
        }

         pg_debug_log("Inferred security level '$inferred_level' from roles string: '" . $allowed_roles_str . "' (Processed: [" . implode(', ', $allowed_roles) . "])");
         return $inferred_level;
    }

    public function handle_project_file_upload($project_id, $is_update = false) {
        if ($this->processing_lock) {
            pg_debug_log("[UPLOAD] ERROR: File upload already in progress. Lock active. Aborting to prevent concurrent uploads.");
            return false;
        }
        
        $this->processing_lock = true;
        $start_time = microtime(true);
        
        global $wpdb;
        pg_debug_log("[UPLOAD] STARTING UPLOAD for Project ID: $project_id, Is Update: " . ($is_update ? 'Yes' : 'No') . " - " . pg_debug_memory());
        pg_debug_log("[UPLOAD] POST data: " . print_r($_POST, true));
        pg_debug_log("[UPLOAD] FILES data: " . print_r($_FILES, true));

        $security_level_changed = false;
        $file_uploaded = isset($_FILES['project_file'])
                         && is_uploaded_file($_FILES['project_file']['tmp_name'])
                         && $_FILES['project_file']['error'] === UPLOAD_ERR_OK;

        if ($is_update && isset($_POST['security_level'])) {
            pg_debug_log("[UPLOAD] Security level update processing");
            $security_level = sanitize_text_field($_POST['security_level']);
            $new_allowed_roles = $this->security_level_to_roles($security_level);
            pg_debug_log("[UPLOAD] Security level posted: '$security_level', mapped to roles: '$new_allowed_roles'");

            $existing_file_record = $this->get_project_file($project_id);
            if ($existing_file_record) {
                 pg_debug_log("[UPLOAD] Existing file record found (ID: {$existing_file_record->id}). Current roles: '{$existing_file_record->allowed_roles}'");
                 $current_roles_array = array_map('trim', explode(',', $existing_file_record->allowed_roles));
                 $new_roles_array = array_map('trim', explode(',', $new_allowed_roles));
                 sort($current_roles_array);
                 sort($new_roles_array);

                 if ($current_roles_array !== $new_roles_array) {
                     pg_debug_log("[UPLOAD] Roles differ. Attempting to update DB record {$existing_file_record->id}");
                     $update_roles_result = $wpdb->update(
                        $this->file_table,
                        array('allowed_roles' => $new_allowed_roles),
                        array('id' => $existing_file_record->id),
                        array('%s'),
                        array('%d')
                    );
                     if ($update_roles_result !== false) {
                         $security_level_changed = true;
                         pg_debug_log("[UPLOAD] Successfully updated security roles for file ID {$existing_file_record->id}. Rows affected: $update_roles_result");
                     } else {
                          pg_debug_log("[UPLOAD] ERROR: Failed to update security roles for file ID {$existing_file_record->id}: " . $wpdb->last_error);
                     }
                 } else {
                      pg_debug_log("[UPLOAD] Posted roles match existing roles. No update needed.");
                 }
            } else {
                 pg_debug_log("[UPLOAD] Security level posted, but no existing file record found for project $project_id.");
            }
        }

        if (!$file_uploaded) {
            pg_debug_log("[UPLOAD] No valid file uploaded or error occurred. Exiting file handling. " . pg_debug_memory());
            $this->processing_lock = false;
            return $security_level_changed;
        }

        pg_debug_log("[UPLOAD] Valid file upload detected. Processing...");
        $file = $_FILES['project_file'];
        $original_filename = sanitize_file_name($file['name']);
        pg_debug_log("[UPLOAD] Original filename (sanitized): $original_filename");

        $allowed_extensions = ['zip'];
        $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
             pg_debug_log("[UPLOAD] ERROR: Invalid file type: '$original_filename'. Must be: " . implode(', ', $allowed_extensions));
             @unlink($file['tmp_name']);
             $this->processing_lock = false;
             return false;
        }

        $this->ensure_directories();

        // Generate a unique temp file path
        $temp_upload_path = $this->temp_dir . 'upload_' . uniqid('', true) . '_' . $original_filename;
        pg_debug_log("[UPLOAD] Moving uploaded file from '{$file['tmp_name']}' to '$temp_upload_path'");

        $move_result = move_uploaded_file($file['tmp_name'], $temp_upload_path);
        pg_debug_log("[UPLOAD] Move result: " . ($move_result ? 'Success' : 'Failed'));

        if (!$move_result) {
            pg_debug_log("[UPLOAD] ERROR: Failed to move uploaded file. Error code: {$file['error']}");
            if ($file['error'] == UPLOAD_ERR_INI_SIZE || $file['error'] == UPLOAD_ERR_FORM_SIZE) pg_debug_log("[UPLOAD] Reason: File size exceeds PHP limit.");
            if ($file['error'] == UPLOAD_ERR_PARTIAL) pg_debug_log("[UPLOAD] Reason: File was only partially uploaded.");
            if ($file['error'] == UPLOAD_ERR_NO_TMP_DIR) pg_debug_log("[UPLOAD] Reason: Missing PHP temporary directory.");
            if ($file['error'] == UPLOAD_ERR_CANT_WRITE) pg_debug_log("[UPLOAD] Reason: Failed to write file to disk.");
            if ($file['error'] == UPLOAD_ERR_EXTENSION) pg_debug_log("[UPLOAD] Reason: A PHP extension stopped the upload.");
            $this->processing_lock = false;
            return false;
        }

        pg_debug_log("[UPLOAD] Successfully moved file to: $temp_upload_path");
        pg_debug_log("[UPLOAD] Temp file exists: " . (file_exists($temp_upload_path) ? 'Yes (' . filesize($temp_upload_path) . ' bytes)' : 'No'));

        // Generate the target encrypted filename
        $encrypted_file_name = $this->generate_random_token(16) . '_' . time() . '.enc';
        $encrypted_path = $this->upload_dir . $encrypted_file_name;
        pg_debug_log("[UPLOAD] Generated encrypted filename: $encrypted_file_name");
        pg_debug_log("[UPLOAD] Target encrypted path: $encrypted_path");

        // Generate a token for the file
        $random_token = $this->generate_random_token(64);
        $security_level = isset($_POST['security_level']) ? sanitize_text_field($_POST['security_level']) : 'wb1';
        $allowed_roles = $this->security_level_to_roles($security_level);

        $project_name = isset($_POST['project_name']) ? sanitize_text_field($_POST['project_name']) : '';
        if (empty($project_name)) {
            $project_data = Production_Goals_DB::get_project($project_id);
            $project_name = $project_data ? $project_data->name : 'Project ' . $project_id;
        }
        $display_name = $project_name . ' - Files';

        pg_debug_log("[UPLOAD] Preparing DB data: Name='$display_name', URL='$encrypted_file_name', Token='$random_token', Roles='$allowed_roles', OrigName='$original_filename'");

        // Prepare database data - now with encryption_status
        $data_to_save = array(
            'file_name'         => $display_name,
            'file_url'          => $encrypted_file_name,
            'random_token'      => $random_token,
            'allowed_roles'     => $allowed_roles,
            'original_filename' => $original_filename,
            'project_id'        => $project_id,
            'encryption_status' => 'pending'
        );
        $data_formats = array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%d',
            '%s'
        );

        $existing_file_record = $this->get_project_file($project_id);
        $db_operation_success = false;
        $new_file_id = 0;

        if ($existing_file_record) {
            pg_debug_log("[UPLOAD] Updating existing DB record ID: {$existing_file_record->id}");

            $old_file_url = $existing_file_record->file_url;
            if (!empty($old_file_url) && $old_file_url !== $encrypted_file_name) {
                $old_file_path = $this->upload_dir . $old_file_url;
                $old_meta_path = $old_file_path . '.meta';
                pg_debug_log("[UPLOAD] Marking old file for cleanup: $old_file_path");
                
                // Instead of deleting now, we'll flag it for later cleanup
                update_option('pg_pending_cleanup_' . $existing_file_record->id, 
                              json_encode(['file' => $old_file_path, 'meta' => $old_meta_path]), 
                              false);
            } else {
                 pg_debug_log("[UPLOAD] Old file URL is empty or same as new, skipping deletion.");
            }

            $where = array('id' => $existing_file_record->id);
            $where_formats = array('%d');

            $result = $wpdb->update($this->file_table, $data_to_save, $where, $data_formats, $where_formats);

            if ($result !== false) {
                 pg_debug_log("[UPLOAD] DB update completed. Rows affected: $result");
                 $db_operation_success = true;
                 $new_file_id = $existing_file_record->id;
            } else {
                 pg_debug_log("[UPLOAD] ERROR: DB update failed: " . $wpdb->last_error);
                 if (strpos($wpdb->last_error, 'Duplicate entry') !== false && strpos($wpdb->last_error, 'random_token') !== false) {
                     pg_debug_log("[UPLOAD] DATABASE ERROR: Duplicate entry for random_token.");
                 }
            }

        } else {
             pg_debug_log("[UPLOAD] Inserting new DB record for project ID: $project_id");
             $data_to_save['download_count'] = 0;
             $data_to_save['last_download'] = null;
             $data_formats[] = '%d';
             $data_formats[] = '%s';

             $result = $wpdb->insert($this->file_table, $data_to_save, $data_formats);

             if ($result !== false) {
                 $new_file_id = $wpdb->insert_id;
                 pg_debug_log("[UPLOAD] DB insert successful. New file ID: $new_file_id");
                 $db_operation_success = true;
             } else {
                  pg_debug_log("[UPLOAD] ERROR: DB insert failed: " . $wpdb->last_error);
                  if (strpos($wpdb->last_error, 'Duplicate entry') !== false && strpos($wpdb->last_error, 'random_token') !== false) {
                     pg_debug_log("[UPLOAD] DATABASE ERROR: Duplicate entry for random_token on INSERT.");
                 }
             }
        }

        if (!$db_operation_success) {
            pg_debug_log("[UPLOAD] ERROR: Database operation failed. Cleaning up temp file.");
            if (file_exists($temp_upload_path)) @unlink($temp_upload_path);
            $this->processing_lock = false;
            return false;
        }

        // Store information needed for background encryption
        $encryption_data = [
            'file_id' => $new_file_id,
            'temp_path' => $temp_upload_path,
            'target_path' => $encrypted_path,
            'timestamp' => time()
        ];
        
        update_option('pg_encryption_pending_' . $new_file_id, json_encode($encryption_data), false);
        
        // Ensure the background encryption process is scheduled
        if (!wp_next_scheduled('pg_process_pending_encryptions')) {
            wp_schedule_event(time(), 'pg_one_minute', 'pg_process_pending_encryptions');
        }
        
        $total_time = round(microtime(true) - $start_time, 2);
        pg_debug_log("[UPLOAD] FINISHED handle_project_file_upload for Project ID: $project_id in $total_time seconds - " . pg_debug_memory());
        pg_debug_log("[UPLOAD] File queued for background encryption with ID: $new_file_id");

        $this->processing_lock = false;
        return true;
    }

    /**
     * Process pending file encryptions
     * This function will be called by WordPress cron
     */
    public function process_pending_encryptions() {
        global $wpdb;
        pg_debug_log("[BACKGROUND] Starting pending encryption processing...");
        
        // Get all options that match our encryption prefix
        $encryption_options = $wpdb->get_results(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE 'pg_encryption_pending_%'"
        );
        
        if (empty($encryption_options)) {
            pg_debug_log("[BACKGROUND] No pending encryptions found");
            return;
        }
        
        foreach ($encryption_options as $option) {
            $option_name = $option->option_name;
            $file_id = intval(str_replace('pg_encryption_pending_', '', $option_name));
            
            pg_debug_log("[BACKGROUND] Processing encryption for file ID: $file_id");
            $encryption_data = json_decode(get_option($option_name), true);
            
            if (!$encryption_data || !is_array($encryption_data)) {
                pg_debug_log("[BACKGROUND] Invalid encryption data for file ID: $file_id. Removing.");
                delete_option($option_name);
                continue;
            }
            
            $temp_path = $encryption_data['temp_path'] ?? '';
            $target_path = $encryption_data['target_path'] ?? '';
            
            if (!file_exists($temp_path)) {
                pg_debug_log("[BACKGROUND] Temp file not found for file ID: $file_id at path: $temp_path");
                $this->handle_encryption_failure($file_id, $option_name);
                continue;
            }
            
            pg_debug_log("[BACKGROUND] Starting encryption for file ID: $file_id");
            pg_debug_log("[BACKGROUND] Source: $temp_path");
            pg_debug_log("[BACKGROUND] Target: $target_path");
            
            // Update status to in progress
            $wpdb->update(
                $this->file_table,
                ['encryption_status' => 'processing'],
                ['id' => $file_id],
                ['%s'],
                ['%d']
            );
            
            // Perform encryption
            $encryption_success = $this->encrypt_file($temp_path, $target_path);
            
            if ($encryption_success) {
                pg_debug_log("[BACKGROUND] Encryption successful for file ID: $file_id");
                
                // Update status to complete
                $wpdb->update(
                    $this->file_table,
                    ['encryption_status' => 'complete'],
                    ['id' => $file_id],
                    ['%s'],
                    ['%d']
                );
                
                // Clean up temp file
                @unlink($temp_path);
                
                // Process any old files that need cleanup
                $cleanup_option = 'pg_pending_cleanup_' . $file_id;
                $cleanup_data = json_decode(get_option($cleanup_option), true);
                
                if ($cleanup_data && is_array($cleanup_data)) {
                    if (!empty($cleanup_data['file']) && file_exists($cleanup_data['file'])) {
                        pg_debug_log("[BACKGROUND] Cleaning up old file: " . $cleanup_data['file']);
                        @unlink($cleanup_data['file']);
                    }
                    
                    if (!empty($cleanup_data['meta']) && file_exists($cleanup_data['meta'])) {
                        pg_debug_log("[BACKGROUND] Cleaning up old meta file: " . $cleanup_data['meta']);
                        @unlink($cleanup_data['meta']);
                    }
                    
                    delete_option($cleanup_option);
                }
                
            } else {
                pg_debug_log("[BACKGROUND] Encryption failed for file ID: $file_id");
                $this->handle_encryption_failure($file_id, $option_name);
                continue;
            }
            
            // Remove the pending encryption entry
            delete_option($option_name);
            pg_debug_log("[BACKGROUND] Encryption processing completed for file ID: $file_id");
        }
        
        pg_debug_log("[BACKGROUND] Finished processing all pending encryptions");
    }

    /**
     * Handle encryption failure
     */
    private function handle_encryption_failure($file_id, $option_name) {
        global $wpdb;
        
        // Update the DB record to show encryption failed
        $wpdb->update(
            $this->file_table,
            ['encryption_status' => 'failed'],
            ['id' => $file_id],
            ['%s'],
            ['%d']
        );
        
        // Optionally, set a notification or alert for admins
        update_option('pg_encryption_failed_' . $file_id, time(), false);
        
        // Remove the pending encryption record
        delete_option($option_name);
        
        pg_debug_log("[BACKGROUND] Updated file ID: $file_id to failed encryption status");
    }

    public function get_project_file($project_id) {
        global $wpdb;
        if (!is_numeric($project_id) || $project_id <= 0) {
             pg_debug_log("Invalid project_id requested in get_project_file: " . print_r($project_id, true));
             return null;
        }
         if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->file_table)) != $this->file_table) {
             pg_debug_log("Table {$this->file_table} does not exist in get_project_file.");
             return null;
         }
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->file_table} WHERE project_id = %d ORDER BY id DESC LIMIT 1",
            $project_id
        );
        $file_record = $wpdb->get_row($query);

        if ($file_record) {
            pg_debug_log("Found file record for project ID $project_id: ID {$file_record->id}");
        } else {
            pg_debug_log("No file record found for project ID $project_id.");
        }

        return $file_record;
    }


    public function delete_project_file($project_id) {
        global $wpdb;
        pg_debug_log("--- Starting delete_project_file for Project ID: $project_id ---");

        if (!is_numeric($project_id) || $project_id <= 0) {
             pg_debug_log("Invalid project_id provided for deletion: " . print_r($project_id, true));
             return false;
        }

        $file_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->file_table)) == $this->file_table;
        $log_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table)) == $this->log_table;

        if (!$file_table_exists) {
            pg_debug_log("File table {$this->file_table} does not exist. Cannot delete file record.");
            return false;
        }

        $file_records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, file_url FROM {$this->file_table} WHERE project_id = %d",
            $project_id
        ));

        if (empty($file_records)) {
             pg_debug_log("No file records found to delete for project ID: $project_id");
             pg_debug_log("--- Finished delete_project_file (no records) for Project ID: $project_id ---");
             return true;
        }

        $all_deleted_successfully = true;

        foreach ($file_records as $file) {
            pg_debug_log("Processing deletion for file record ID: {$file->id}, Filename: {$file->file_url}");

            if (!empty($file->file_url)) {
                $file_path = $this->upload_dir . $file->file_url;
                $meta_path = $file_path . '.meta';

                pg_debug_log("Attempting to delete physical file: $file_path");
                if (file_exists($file_path)) {
                    if (@unlink($file_path)) {
                         pg_debug_log("Successfully deleted: $file_path");
                    } else {
                         pg_debug_log("ERROR: Failed to delete physical file: $file_path. Check permissions.");
                         $all_deleted_successfully = false;
                    }
                } else {
                     pg_debug_log("Physical file not found, skipping deletion: $file_path");
                }

                pg_debug_log("Attempting to delete meta file: $meta_path");
                 if (file_exists($meta_path)) {
                    if (@unlink($meta_path)) {
                         pg_debug_log("Successfully deleted meta file: $meta_path");
                    } else {
                         pg_debug_log("ERROR: Failed to delete meta file: $meta_path. Check permissions.");
                    }
                } else {
                     pg_debug_log("Meta file not found, skipping deletion: $meta_path");
                }
            } else {
                pg_debug_log("File URL is empty for record ID {$file->id}, skipping physical file deletion.");
            }

            if ($log_table_exists) {
                pg_debug_log("Attempting to delete download logs for file ID: {$file->id}");
                $deleted_logs = $wpdb->delete($this->log_table, array('file_id' => $file->id), array('%d'));
                 if ($deleted_logs !== false) {
                     pg_debug_log("Successfully deleted download logs. Rows affected: $deleted_logs");
                 } else {
                      pg_debug_log("ERROR: Failed to delete download logs for file ID {$file->id}: " . $wpdb->last_error);
                 }
            } else {
                 pg_debug_log("Log table {$this->log_table} does not exist, skipping log deletion.");
            }

            pg_debug_log("Attempting to delete DB record for file ID: {$file->id}");
            $deleted_db = $wpdb->delete($this->file_table, array('id' => $file->id), array('%d'));
            if ($deleted_db !== false) {
                 pg_debug_log("Successfully deleted DB record. Rows affected: $deleted_db");
            } else {
                 pg_debug_log("ERROR: Failed to delete DB record for file ID {$file->id}: " . $wpdb->last_error);
                 $all_deleted_successfully = false;
            }
        }

        pg_debug_log("--- Finished delete_project_file for Project ID: $project_id. Overall success: " . ($all_deleted_successfully ? 'Yes' : 'No (check logs)') . " ---");
        return $all_deleted_successfully;
    }


    public function get_download_url($file) {
        if (is_object($file) && isset($file->random_token) && !empty($file->random_token)) {
            $token = $file->random_token;
            return add_query_arg('download_token', $token, home_url('/'));
        }
        pg_debug_log("Could not generate download URL. Invalid file data provided: " . print_r($file, true));
        return false;
    }

    public function get_download_shortcode($file) {
        if (is_object($file) && isset($file->id) && is_numeric($file->id) && $file->id > 0) {
            return '[download_counter id="' . esc_attr($file->id) . '"]';
        }
        pg_debug_log("Could not generate download shortcode. Invalid file data provided: " . print_r($file, true));
        return false;
    }

    public function process_download() {
        if (!isset($_GET['download_token']) || empty(trim($_GET['download_token']))) {
            return;
        }

        global $wpdb;
        $token = trim(sanitize_text_field($_GET['download_token']));
        pg_debug_log("[DOWNLOAD] Starting process_download for Token: $token - " . pg_debug_memory());

        $file_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->file_table)) == $this->file_table;
        $log_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table)) == $this->log_table;

        if (!$file_table_exists || !$log_table_exists) {
            $missing_tables = [];
            if (!$file_table_exists) $missing_tables[] = $this->file_table;
            if (!$log_table_exists) $missing_tables[] = $this->log_table;
            pg_debug_log("[DOWNLOAD] ERROR: Required database tables missing: " . implode(', ', $missing_tables));
            wp_die(
                __('Download system error. Required database tables are missing. Please contact the administrator.', 'production-goals') . ' [Code: DB1]',
                __('Download Error', 'production-goals'),
                array('response' => 500)
            );
        }

        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->file_table} WHERE random_token = %s",
            $token
        ));

        if (!$file) {
            pg_debug_log("[DOWNLOAD] ERROR: Invalid or expired token provided: $token");
            wp_die(
                __('Invalid or expired download link. Please request a new link or contact the administrator.', 'production-goals') . ' [Code: T1]<br>' . sprintf(__('Token: %s', 'production-goals'), esc_html($token)),
                __('Invalid Link', 'production-goals'),
                array('response' => 404)
            );
        }
        pg_debug_log("[DOWNLOAD] Found file record ID: {$file->id} for Project #{$file->project_id}");

        // Check encryption status
        $encryption_status = isset($file->encryption_status) ? $file->encryption_status : 'complete';
        if ($encryption_status === 'pending' || $encryption_status === 'processing') {
            pg_debug_log("[DOWNLOAD] File is still being encrypted. Status: $encryption_status");
            wp_die(
                __('This file is still being processed and encrypted. Please try again in a few minutes.', 'production-goals') . ' [Code: E1]',
                __('File Processing', 'production-goals'),
                array('response' => 202)
            );
        } else if ($encryption_status === 'failed') {
            pg_debug_log("[DOWNLOAD] File encryption failed for ID: {$file->id}");
            wp_die(
                __('There was a problem processing this file. Please contact the administrator.', 'production-goals') . ' [Code: E2]',
                __('File Error', 'production-goals'),
                array('response' => 500)
            );
        }

        if (!is_user_logged_in()) {
             pg_debug_log("[DOWNLOAD] User not logged in - redirecting to login");
             $redirect_url = add_query_arg('download_token', $token, home_url('/'));
             wp_safe_redirect(wp_login_url($redirect_url), 302);
             exit;
        }

        $user = wp_get_current_user();
        $user_roles_raw = (array) $user->roles;
        $user_roles = array_map('strtolower', $user_roles_raw);
        pg_debug_log("[DOWNLOAD] User ID: {$user->ID}, Roles: [" . implode(', ', $user_roles) . "]");

        $privileged_roles = array('administrator', 'wbadmin');
        $is_privileged = !empty(array_intersect($privileged_roles, $user_roles));
        $has_permission = $is_privileged;

        if (!$has_permission) {
            if (!empty($file->allowed_roles)) {
                $allowed_roles_raw = explode(',', $file->allowed_roles);
                $allowed_roles = array_filter(array_map('strtolower', array_map('trim', $allowed_roles_raw)));
                pg_debug_log("[DOWNLOAD] File allows roles: [" . implode(', ', $allowed_roles) . "]");

                if (!empty(array_intersect($user_roles, $allowed_roles))) {
                    $has_permission = true;
                    pg_debug_log("[DOWNLOAD] Permission granted: User roles intersect with allowed roles.");
                } else {
                    pg_debug_log("[DOWNLOAD] Permission denied: User roles don't match allowed roles.");
                }
            } else {
                 pg_debug_log("[DOWNLOAD] Permission denied: File has no allowed roles and user is not privileged.");
            }
        } else {
             pg_debug_log("[DOWNLOAD] Permission granted: User has a privileged role.");
        }

        if (!$has_permission) {
            wp_die(
                __('You do not have permission to download this file. Your user role does not grant access.', 'production-goals') . ' [Code: P1]',
                __('Permission Denied', 'production-goals'),
                array('response' => 403)
            );
        }

        $encrypted_path = $this->upload_dir . $file->file_url;
        $meta_path = $encrypted_path . '.meta';
        pg_debug_log("[DOWNLOAD] Checking file existence: Encrypted='$encrypted_path', Meta='$meta_path'");

        if (!file_exists($encrypted_path) || !is_file($encrypted_path)) {
            pg_debug_log("[DOWNLOAD] ERROR: Encrypted source file not found or is not a regular file");
            wp_die(
                __('The source file is missing or cannot be accessed. Please contact the administrator.', 'production-goals') . ' [Code: F1]',
                __('File Error', 'production-goals'),
                array('response' => 404)
            );
        }
         if (!file_exists($meta_path) || !is_file($meta_path)) {
            pg_debug_log("[DOWNLOAD] ERROR: Meta file not found or is not a regular file");
            wp_die(
                __('The required meta file is missing. Download cannot proceed. Please contact the administrator.', 'production-goals') . ' [Code: F2]',
                 __('File Error', 'production-goals'),
                 array('response' => 500)
            );
        }

        $update_count_result = $wpdb->update(
            $this->file_table,
            array(
                'download_count' => $wpdb->get_var($wpdb->prepare("SELECT download_count + 1 FROM {$this->file_table} WHERE id = %d", $file->id)),
                'last_download' => current_time('mysql', 1)
            ),
            array('id' => $file->id),
            array('%d', '%s'),
            array('%d')
        );

         if ($update_count_result === false) {
             pg_debug_log("[DOWNLOAD] Warning: Failed to update download count: " . $wpdb->last_error);
         } else {
              pg_debug_log("[DOWNLOAD] Download count updated. Rows affected: $update_count_result");
         }

        $log_insert_result = $wpdb->insert(
            $this->log_table,
            array(
                'file_id' => $file->id,
                'user_id' => $user->ID,
                'download_date' => current_time('mysql', 1)
            ),
            array(
                '%d',
                '%d',
                '%s'
            )
        );
         if ($log_insert_result === false) {
             pg_debug_log("[DOWNLOAD] Warning: Failed to log download: " . $wpdb->last_error);
         } else {
              pg_debug_log("[DOWNLOAD] Download logged successfully. Log ID: " . $wpdb->insert_id);
         }

        $this->ensure_directories();
        $temp_file = $this->temp_dir . 'decrypted_' . $file->id . '_' . $user->ID . '_' . uniqid('', true) . '.zip';
        pg_debug_log("[DOWNLOAD] Prepared temp path for decryption: $temp_file");
        pg_debug_log("[DOWNLOAD] Memory before decryption: " . pg_debug_memory());

        if ($this->decrypt_file($encrypted_path, $temp_file)) {
            pg_debug_log("[DOWNLOAD] File decrypted successfully - " . pg_debug_memory());

            if (file_exists($temp_file) && is_readable($temp_file) && filesize($temp_file) > 0) {
                $file_size = filesize($temp_file);
                pg_debug_log("[DOWNLOAD] Decrypted file ready - size: $file_size bytes");

                $download_filename = !empty($file->original_filename) ? $file->original_filename : basename($file->file_name);
                if (strtolower(pathinfo($download_filename, PATHINFO_EXTENSION)) !== 'zip') {
                    $download_filename = pathinfo($download_filename, PATHINFO_FILENAME) . '.zip';
                }
                $download_filename = sanitize_file_name($download_filename);
                pg_debug_log("[DOWNLOAD] Using download filename: $download_filename");

                nocache_headers();
                if (ob_get_level()) {
                    @ob_end_clean();
                }

                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $download_filename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                header('Content-Length: ' . $file_size);

                $readfile_result = @readfile($temp_file);

                if (@unlink($temp_file)) {
                     pg_debug_log("[DOWNLOAD] Successfully deleted temp decrypted file");
                } else {
                     pg_debug_log("[DOWNLOAD] Warning: Failed to delete temp decrypted file");
                }

                if ($readfile_result === false) {
                     pg_debug_log("[DOWNLOAD] ERROR: readfile() failed");
                } elseif ($readfile_result !== $file_size) {
                     pg_debug_log("[DOWNLOAD] Warning: readfile() output $readfile_result bytes, expected $file_size bytes");
                } else {
                     pg_debug_log("[DOWNLOAD] Successfully sent file - bytes: $readfile_result");
                }

                pg_debug_log("[DOWNLOAD] Finished process_download for Token: $token");
                exit;

            } else {
                pg_debug_log("[DOWNLOAD] ERROR: Decrypted file is empty, missing, or unreadable");
                if (file_exists($temp_file)) @unlink($temp_file);
                wp_die(
                    __('Error processing the file after decryption. The resulting file is empty or missing. Please contact the administrator.', 'production-goals') . ' [Code: D2]',
                    __('Decryption Error', 'production-goals'),
                    array('response' => 500)
                );
            }

        } else {
            pg_debug_log("[DOWNLOAD] ERROR: Decryption failed");
            if (file_exists($temp_file)) @unlink($temp_file);
            wp_die(
                __('Could not decrypt the file. The file may be corrupted or the encryption key is incorrect. Please contact the administrator.', 'production-goals') . ' [Code: D1]',
                __('Decryption Error', 'production-goals'),
                array('response' => 500)
            );
        }
    }

    public function download_counter_shortcode($atts) {
        global $wpdb;
        $atts = shortcode_atts(array(
            'id' => 0,
            'label' => __('Downloads:', 'production-goals'),
            'show_if_zero' => true,
        ), $atts, 'download_counter');

        $file_id = intval($atts['id']);
        $label = sanitize_text_field($atts['label']);
        $show_if_zero = filter_var($atts['show_if_zero'], FILTER_VALIDATE_BOOLEAN);

        if ($file_id <= 0) {
            pg_debug_log("Download counter shortcode: Invalid or missing file ID.");
            return '';
        }

         if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->file_table)) != $this->file_table) {
             pg_debug_log("Download counter shortcode: File table {$this->file_table} does not exist.");
             return '';
         }

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT download_count FROM {$this->file_table} WHERE id = %d",
            $file_id
        ));

        if ($count !== null) {
            $count_int = intval($count);
            if ($count_int > 0 || $show_if_zero) {
                $formatted_count = number_format_i18n($count_int);
                return '<span class="pg-download-count" data-file-id="' . esc_attr($file_id) . '">'
                       . esc_html($label) . ' ' . esc_html($formatted_count)
                       . '</span>';
            } else {
                return '';
            }
        } else {
            pg_debug_log("Download counter shortcode: File ID $file_id not found in table {$this->file_table}.");
            return '';
        }
    }

    public function get_file_download_logs($file_id, $limit = 10, $offset = 0) {
        global $wpdb;

        if (!is_numeric($file_id) || $file_id <= 0) {
            pg_debug_log("get_file_download_logs: Invalid file_id provided: " . print_r($file_id, true));
            return array();
        }
        $limit = absint($limit);
        $offset = absint($offset);
        if ($limit <= 0) $limit = 10;

         if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table)) != $this->log_table) {
             pg_debug_log("Table {$this->log_table} does not exist in get_file_download_logs.");
             return array();
         }

        $query = $wpdb->prepare(
            "SELECT logs.*, users.user_login, users.display_name
             FROM {$this->log_table} AS logs
             LEFT JOIN {$wpdb->users} AS users ON logs.user_id = users.ID
             WHERE logs.file_id = %d
             ORDER BY logs.download_date DESC
             LIMIT %d OFFSET %d",
            $file_id,
            $limit,
            $offset
        );

        $logs = $wpdb->get_results($query);

        if ($logs === null) {
             pg_debug_log("Error executing query in get_file_download_logs for file ID $file_id: " . $wpdb->last_error);
             return array();
        }

        pg_debug_log("Retrieved " . count($logs) . " download logs for file ID $file_id (Limit: $limit, Offset: $offset).");
        return $logs;
    }

    public function delete_download_log($log_id) {
        global $wpdb;
        $log_id = intval($log_id);

        if ($log_id <= 0) {
            pg_debug_log("Invalid log_id provided for deletion: $log_id");
            return false;
        }

        $log_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table)) == $this->log_table;
        $file_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->file_table)) == $this->file_table;

        if (!$log_table_exists) {
            pg_debug_log("Log table {$this->log_table} does not exist in delete_download_log.");
            return false;
        }
         if (!$file_table_exists) {
            pg_debug_log("File table {$this->file_table} does not exist in delete_download_log. Cannot decrement count.");
         }

        $file_id = null;
        if ($file_table_exists) {
            $file_id = $wpdb->get_var($wpdb->prepare(
                "SELECT file_id FROM {$this->log_table} WHERE id = %d",
                $log_id
            ));
            if (!$file_id) {
                pg_debug_log("Warning: Could not find file_id for log entry ID $log_id before deletion. Cannot decrement count.");
            } else {
                 pg_debug_log("Found file_id $file_id associated with log entry $log_id.");
            }
        }

        pg_debug_log("Attempting to delete download log entry with ID: $log_id");
        $result = $wpdb->delete($this->log_table, array('id' => $log_id), array('%d'));

        if ($result === false) {
            pg_debug_log("ERROR: Failed to delete download log entry ID $log_id: " . $wpdb->last_error);
            return false;
        } elseif ($result === 0) {
            pg_debug_log("Warning: No download log entry found with ID $log_id to delete.");
            return false;
        } else {
            pg_debug_log("Successfully deleted download log entry ID $log_id. Rows affected: $result");

            if ($file_id && $file_table_exists) {
                pg_debug_log("Attempting to decrement download count for file ID: $file_id");
                $update_count_result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->file_table} SET download_count = GREATEST(0, download_count - 1) WHERE id = %d",
                    $file_id
                ));

                if ($update_count_result === false) {
                     pg_debug_log("Warning: Failed to decrement download count for file ID {$file_id} after deleting log $log_id: " . $wpdb->last_error);
                } elseif ($update_count_result > 0) {
                     pg_debug_log("Successfully decremented download count for file ID {$file_id}.");
                } else {
                     pg_debug_log("Download count for file ID {$file_id} was not decremented (likely already 0 or file record missing).");
                }
            }

            return true;
        }
    }
}

global $production_file_handler;
if (!isset($production_file_handler) || !$production_file_handler instanceof Production_File_Handler) {
    pg_debug_log("Instantiating Production_File_Handler globally.");
    $production_file_handler = new Production_File_Handler();
} else {
     pg_debug_log("Production_File_Handler already instantiated.");
}