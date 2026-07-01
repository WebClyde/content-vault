<?php
/**
 * Plugin Name: Content Vault
 * Plugin URI: https://webclyde.com
 * Description: Automatically archive posts and pages to the Content Vault using S3 API with async status checking.
 * Version: 1.0.1
 * Author: WebClyde
 * Author URI: https://webclyde.com
 * License: GPL v2 or later
 * Text Domain: content-vault
 */

if (!defined('ABSPATH')) {
    exit;
}

// constants
if (!defined('WEBCLYDE_CONTENT_VAULT_VERSION')) {
    define('WEBCLYDE_CONTENT_VAULT_VERSION', '1.0.1');
}
if (!defined('WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR')) {
    define('WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WEBCLYDE_CONTENT_VAULT_PLUGIN_FILE')) {
    define('WEBCLYDE_CONTENT_VAULT_PLUGIN_FILE', __FILE__);
}
if (!defined('WEBCLYDE_CONTENT_VAULT_PLUGIN_URL')) {
    define('WEBCLYDE_CONTENT_VAULT_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('WEBCLYDE_CONTENT_VAULT_TABLE_NAME')) {
    define('WEBCLYDE_CONTENT_VAULT_TABLE_NAME', 'webclyde_content_vault_logs');
}

// include core classes
require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-settings.php';
require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-logger.php';
require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-api.php';
require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-admin.php';
require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-404-handler.php';

require_once WEBCLYDE_CONTENT_VAULT_PLUGIN_DIR . 'includes/class-main.php';

// bootstrap
function webclyde_content_vault_init() {
    return WebClyde_Content_Vault::get_instance();
}

add_action('plugins_loaded', function () {
    webclyde_content_vault_init();
});
