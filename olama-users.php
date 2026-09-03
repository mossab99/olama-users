<?php
/**
 * Plugin Name: OLAMA Users
 * Description: Central OLAMA identities, account provisioning, roles, and functionality access.
 * Version: 0.6.7
 * Author: Olama
 * Text Domain: olama-users
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_USERS_VERSION', '0.6.7');
define('OLAMA_USERS_FILE', __FILE__);
define('OLAMA_USERS_PATH', plugin_dir_path(__FILE__));
define('OLAMA_USERS_URL', plugin_dir_url(__FILE__));

require_once OLAMA_USERS_PATH . 'includes/class-olama-users-db.php';
require_once OLAMA_USERS_PATH . 'includes/class-olama-users-roles.php';
require_once OLAMA_USERS_PATH . 'includes/class-olama-users-registry.php';
require_once OLAMA_USERS_PATH . 'includes/class-olama-users-sync.php';
require_once OLAMA_USERS_PATH . 'includes/class-olama-users-admin.php';
require_once OLAMA_USERS_PATH . 'includes/class-olama-users-plugin.php';

register_activation_hook(__FILE__, array('Olama_Users_Plugin', 'activate'));
add_action('plugins_loaded', array('Olama_Users_Plugin', 'instance'), 30);

function olama_users_register_module(array $definition) {
    return Olama_Users_Registry::register($definition);
}

function olama_users_registry() {
    return Olama_Users_Registry::all();
}

function olama_users_get_identity($user_id) {
    return Olama_Users_DB::get_identity_by_user(absint($user_id));
}

function olama_users_can($capability, $user_id = 0) {
    $user_id = $user_id ? absint($user_id) : get_current_user_id();
    return $user_id > 0 && user_can($user_id, sanitize_key($capability));
}

function olama_users_role_is_deleted($role_key) {
    return Olama_Users_Roles::is_deleted($role_key);
}
