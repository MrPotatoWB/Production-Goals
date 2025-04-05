<?php
/**
 * Settings Page for Production Goals
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Production_Goals_Settings {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('production_goals_settings', 'production_goals_settings');
        
        add_settings_section(
            'production_goals_general_settings',
            __('General Settings', 'production-goals'),
            array($this, 'render_general_settings_section'),
            'production_goals_settings'
        );
        
        add_settings_field(
            'default_image',
            __('Default Project Image', 'production-goals'),
            array($this, 'render_default_image_field'),
            'production_goals_settings',
            'production_goals_general_settings'
        );
        
        add_settings_field(
            'enable_user_submissions',
            __('Enable User Submissions', 'production-goals'),
            array($this, 'render_enable_user_submissions_field'),
            'production_goals_settings',
            'production_goals_general_settings'
        );
        
        add_settings_section(
            'production_goals_display_settings',
            __('Display Settings', 'production-goals'),
            array($this, 'render_display_settings_section'),
            'production_goals_settings'
        );
        
        add_settings_field(
            'progress_bar_color',
            __('Progress Bar Color', 'production-goals'),
            array($this, 'render_progress_bar_color_field'),
            'production_goals_settings',
            'production_goals_display_settings'
        );
        
        add_settings_field(
            'progress_bar_background',
            __('Progress Bar Background', 'production-goals'),
            array($this, 'render_progress_bar_background_field'),
            'production_goals_settings',
            'production_goals_display_settings'
        );
    }
    
    /**
     * Render the settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('production_goals_settings');
                do_settings_sections('production_goals_settings');
                submit_button(__('Save Settings', 'production-goals'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render general settings section
     */
    public function render_general_settings_section() {
        echo '<p>' . __('Configure general settings for the Production Goals plugin.', 'production-goals') . '</p>';
    }
    
    /**
     * Render display settings section
     */
    public function render_display_settings_section() {
        echo '<p>' . __('Customize the display settings for production goals and progress bars.', 'production-goals') . '</p>';
    }
    
    /**
     * Render default image field
     */
    public function render_default_image_field() {
        $options = get_option('production_goals_settings');
        $default_image = isset($options['default_image']) ? $options['default_image'] : '';
        ?>
        <div class="pg-settings-field">
            <input type="text" id="production_goals_default_image" name="production_goals_settings[default_image]" class="regular-text" value="<?php echo esc_attr($default_image); ?>" placeholder="<?php echo esc_attr(PRODUCTION_GOALS_URL . 'assets/images/default-placeholder.png'); ?>">
            <button type="button" class="button" id="upload_default_image_button">Select Image</button>
            <p class="description"><?php _e('URL for the default image to display when no featured image is available.', 'production-goals'); ?></p>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('#upload_default_image_button').on('click', function(e) {
                    e.preventDefault();
                    
                    const image = wp.media({
                        title: 'Select Default Image',
                        multiple: false
                    }).open().on('select', function() {
                        const uploaded_image = image.state().get('selection').first();
                        const image_url = uploaded_image.toJSON().url;
                        $('#production_goals_default_image').val(image_url);
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Render enable user submissions field
     */
    public function render_enable_user_submissions_field() {
        $options = get_option('production_goals_settings');
        $enable_user_submissions = isset($options['enable_user_submissions']) ? $options['enable_user_submissions'] : 'yes';
        ?>
        <div class="pg-settings-field">
            <select id="production_goals_enable_user_submissions" name="production_goals_settings[enable_user_submissions]">
                <option value="yes" <?php selected($enable_user_submissions, 'yes'); ?>><?php _e('Yes', 'production-goals'); ?></option>
                <option value="no" <?php selected($enable_user_submissions, 'no'); ?>><?php _e('No', 'production-goals'); ?></option>
            </select>
            <p class="description"><?php _e('Allow users to submit contributions on the frontend.', 'production-goals'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render progress bar color field
     */
    public function render_progress_bar_color_field() {
        $options = get_option('production_goals_settings');
        $progress_bar_color = isset($options['progress_bar_color']) ? $options['progress_bar_color'] : '#007bff';
        ?>
        <div class="pg-settings-field">
            <input type="text" id="production_goals_progress_bar_color" name="production_goals_settings[progress_bar_color]" class="pg-colorpicker" value="<?php echo esc_attr($progress_bar_color); ?>">
            <p class="description"><?php _e('Color for the progress bar filled portion.', 'production-goals'); ?></p>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('.pg-colorpicker').wpColorPicker();
            });
        </script>
        <?php
    }
    
    /**
     * Render progress bar background field
     */
    public function render_progress_bar_background_field() {
        $options = get_option('production_goals_settings');
        $progress_bar_background = isset($options['progress_bar_background']) ? $options['progress_bar_background'] : '#e9ecef';
        ?>
        <div class="pg-settings-field">
            <input type="text" id="production_goals_progress_bar_background" name="production_goals_settings[progress_bar_background]" class="pg-colorpicker" value="<?php echo esc_attr($progress_bar_background); ?>">
            <p class="description"><?php _e('Background color for the progress bar.', 'production-goals'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Get plugin settings
     */
    public static function get_settings() {
        $defaults = array(
            'default_image' => PRODUCTION_GOALS_URL . 'assets/images/default-placeholder.png',
            'enable_user_submissions' => 'yes',
            'progress_bar_color' => '#007bff',
            'progress_bar_background' => '#e9ecef'
        );
        
        $options = get_option('production_goals_settings', array());
        
        return wp_parse_args($options, $defaults);
    }
}

// Initialize settings
new Production_Goals_Settings();
