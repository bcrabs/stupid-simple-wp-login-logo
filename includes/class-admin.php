<?php
namespace SSLL;

final class Admin {
    private static $instance = null;
    private $security;
    private $logo_manager;
    private $cache_manager;
    private $file_handler;
    private $page_hook = '';
    
    private function __construct() {
        $this->security = Security::get_instance();
        $this->logo_manager = Logo_Manager::get_instance();
        $this->cache_manager = Cache_Manager::get_instance();
        $this->file_handler = File_Handler::get_instance();
    }
    
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception('Unserialize is not allowed.');
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_ssll_save_logo', [$this, 'handle_save_logo']);
        add_action('admin_post_ssll_remove_logo', [$this, 'handle_remove_logo']);
    }
    
    public function add_admin_menu() {
        if (!$this->security->has_capability('manage_options')) {
            return;
        }
        
        $this->page_hook = add_options_page(
            __('Stupid Simple Login Logo Settings', 'ssll-for-wp'),
            __('Login Logo', 'ssll-for-wp'),
            'manage_options',
            'stupid-simple-login-logo',
            [$this, 'render_settings_page']
        );
        
        if ($this->page_hook) {
            add_action("load-{$this->page_hook}", [$this, 'page_load']);
        }
    }
    
    public function page_load() {
        if (!$this->security->has_capability('manage_options')) {
            return;
        }

        $this->add_help_tabs();
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    private function add_help_tabs() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        $screen->add_help_tab([
            'id'       => 'ssll_help_overview',
            'title'    => __('Overview', 'ssll-for-wp'),
            'content'  => sprintf(
                '<p>%s</p>',
                esc_html__('Choose an image from your Media Library to replace the default WordPress logo on the login page.', 'ssll-for-wp')
            )
        ]);
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== $this->page_hook) {
            return;
        }

        wp_enqueue_media();
        
        wp_enqueue_script(
            'ssll-media-upload',
            SSLL_URL . 'js/media-upload.js',
            ['jquery', 'media-upload'],
            SSLL_VERSION,
            true
        );
        
        wp_localize_script(
            'ssll-media-upload',
            'ssllData',
            [
                'nonce' => wp_create_nonce('ssll_media_upload'),
                'frame_title' => __('Select Login Logo', 'ssll-for-wp'),
                'frame_button' => __('Use as Login Logo', 'ssll-for-wp'),
                'translations' => [
                    'invalidType' => __('Please select a JPEG or PNG image.', 'ssll-for-wp'),
                    'fileTooBig' => __('File size must not exceed 5MB.', 'ssll-for-wp'),
                    'removeConfirm' => __('Are you sure you want to remove the custom logo? The default WordPress logo will be restored.', 'ssll-for-wp'),
                    'selectLogo' => __('Select Logo', 'ssll-for-wp'),
                    'changeLogo' => __('Change Logo', 'ssll-for-wp'),
                    'genericError' => __('An error occurred. Please try again.', 'ssll-for-wp')
                ]
            ]
        );
    }
    
    public function handle_save_logo() {
        if (!$this->security->verify_user_capability('manage_options')) {
            return;
        }
        
        check_admin_referer('ssll_save_logo', 'nonce');
        
        if (empty($_POST['logo_url'])) {
            wp_redirect(add_query_arg(
                ['page' => 'stupid-simple-login-logo', 'error' => 'no_logo'],
                admin_url('options-general.php')
            ));
            exit;
        }
        
        $url = esc_url_raw($_POST['logo_url']);
        $result = $this->logo_manager->update_logo($url);
        
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(
                [
                    'page' => 'stupid-simple-login-logo',
                    'error' => $result->get_error_code(),
                    'message' => urlencode($result->get_error_message())
                ],
                admin_url('options-general.php')
            ));
            exit;
        }
        
        wp_redirect(add_query_arg(
            ['page' => 'stupid-simple-login-logo', 'updated' => 'true'],
            admin_url('options-general.php')
        ));
        exit;
    }
    
    public function handle_remove_logo() {
        if (!$this->security->verify_user_capability('manage_options')) {
            return;
        }
        
        check_admin_referer('ssll_remove_logo', 'nonce');
        
        $result = $this->logo_manager->remove_logo();
        
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(
                [
                    'page' => 'stupid-simple-login-logo',
                    'error' => $result->get_error_code(),
                    'message' => urlencode($result->get_error_message())
                ],
                admin_url('options-general.php')
            ));
            exit;
        }
        
        wp_redirect(add_query_arg(
            ['page' => 'stupid-simple-login-logo', 'removed' => 'true'],
            admin_url('options-general.php')
        ));
        exit;
    }
    
    public function render_settings_page() {
        if (!$this->security->has_capability('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'),
                esc_html__('Permission Denied', 'ssll-for-wp'),
                ['response' => 403]
            );
        }
        
        // Handle messages
        if (isset($_GET['error'])) {
            $error_message = '';
            switch ($_GET['error']) {
                case 'no_logo':
                    $error_message = __('Please select a logo image.', 'ssll-for-wp');
                    break;
                case 'invalid_url':
                    $error_message = __('Invalid image URL provided.', 'ssll-for-wp');
                    break;
                case 'file_too_large':
                    $error_message = __('The selected image file is too large.', 'ssll-for-wp');
                    break;
                default:
                    if (isset($_GET['message'])) {
                        $error_message = urldecode($_GET['message']);
                    }
            }
            if ($error_message) {
                add_settings_error(
                    'ssll_messages',
                    'ssll_error',
                    $error_message,
                    'error'
                );
            }
        } elseif (isset($_GET['updated'])) {
            add_settings_error(
                'ssll_messages',
                'ssll_updated',
                __('Logo updated successfully.', 'ssll-for-wp'),
                'updated'
            );
        } elseif (isset($_GET['removed'])) {
            add_settings_error(
                'ssll_messages',
                'ssll_removed',
                __('Logo removed successfully.', 'ssll-for-wp'),
                'updated'
            );
        }
        
        $logo_url = $this->cache_manager->get_logo_url();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('ssll_messages'); ?>
            
            <div class="ssll-settings-wrapper">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ssll-logo-settings">
                    <div class="ssll-logo-preview" style="margin: 2em 0;">
                        <?php if (!empty($logo_url)) : ?>
                            <img src="<?php echo esc_url($logo_url); ?>" 
                                 id="logo_preview"
                                 alt="<?php esc_attr_e('Current login logo', 'ssll-for-wp'); ?>"
                                 style="max-width: 320px; height: auto;">
                        <?php endif; ?>
                    </div>
                    
                    <input type="hidden" name="action" value="ssll_save_logo">
                    <?php wp_nonce_field('ssll_save_logo', 'nonce'); ?>
                    
                    <input type="hidden" 
                           name="logo_url" 
                           id="logo_url" 
                           value="<?php echo esc_attr($logo_url); ?>">
                    
                    <div class="ssll-actions">
                        <button type="button" 
                                id="upload_logo_button"
                                class="button button-secondary">
                            <?php 
                            echo empty($logo_url) 
                                ? esc_html__('Select Logo', 'ssll-for-wp')
                                : esc_html__('Change Logo', 'ssll-for-wp');
                            ?>
                        </button>
                        
                        <input type="submit" 
                               name="submit" 
                               id="submit" 
                               class="button button-primary" 
                               value="<?php esc_attr_e('Save Logo', 'ssll-for-wp'); ?>"
                               <?php echo empty($logo_url) ? 'style="display:none;"' : ''; ?>>
                        
                        <?php if (!empty($logo_url)) : ?>
                            <a href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=ssll_remove_logo'),
                                'ssll_remove_logo',
                                'nonce'
                            )); ?>"
                               id="remove_logo_button"
                               class="button"
                               data-confirm="<?php esc_attr_e('Are you sure you want to remove the custom logo? The default WordPress logo will be restored.', 'ssll-for-wp'); ?>">
                                <?php esc_html_e('Remove Logo', 'ssll-for-wp'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <p class="description" style="margin-top: 1em;">
                        <?php esc_html_e('Choose an image from your Media Library to use as the login page logo.', 'ssll-for-wp'); ?>
                    </p>
                    
                    <div class="ssll-preview-notice" style="margin-top: 2em;">
                        <a href="<?php echo esc_url(wp_login_url()); ?>" 
                           target="_blank" 
                           rel="noopener noreferrer"
                           class="button button-secondary">
                            <?php esc_html_e('Preview Login Page', 'ssll-for-wp'); ?> ↗
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    public function cleanup() {
        if ($this->cache_manager) {
            $this->cache_manager->cleanup();
        }
        if ($this->file_handler) {
            $this->file_handler->cleanup();
        }
    }
    
    public function uninstall() {
        $this->cleanup();
    }
}