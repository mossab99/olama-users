<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Olama_Users_Plugin {
    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        Olama_Users_DB::install();
        Olama_Users_Roles::install();
    }

    private function __construct() {
        load_plugin_textdomain('olama-users', false, dirname(plugin_basename(OLAMA_USERS_FILE)) . '/languages');
        add_action('init', array($this, 'initialize'), 60);
        add_filter('authenticate', array($this, 'authenticate_family'), 25, 3);
        add_filter('wp_authenticate_user', array($this, 'block_suspended_user'), 20, 2);
        add_filter('olama_dashboard_cards', array($this, 'register_hub_card'), 30);
        if (is_admin()) {
            new Olama_Users_Admin(new Olama_Users_Sync());
        }
    }

    public function initialize() {
        if (get_option('olama_users_db_version') !== OLAMA_USERS_VERSION) {
            Olama_Users_DB::install();
        }
        if (get_option('olama_users_roles_version') !== OLAMA_USERS_VERSION) {
            Olama_Users_Roles::install();
        }
        Olama_Users_Registry::load();
    }

    public function block_suspended_user($user, $password) {
        if (is_wp_error($user) || !$user instanceof WP_User) {
            return $user;
        }
        if ('suspended' === get_user_meta($user->ID, 'olama_account_status', true)) {
            Olama_Users_DB::audit('login_blocked', $user->ID, get_user_meta($user->ID, 'olama_identity_type', true), get_user_meta($user->ID, 'olama_oracle_identifier', true), 'blocked');
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }
        return $user;
    }

    public function authenticate_family($result, $username, $password) {
        $username = trim((string) $username);
        if (!preg_match('/^\d+$/', $username) || '' === (string) $password) {
            return $result;
        }
        $identity = Olama_Users_DB::get_identity('family', $username);
        if (!$identity) {
            return $result;
        }
        $user = get_userdata(absint($identity['wp_user_id']));
        if (!$user || 'active' !== $identity['account_status']) {
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }
        $normalized = $this->normalize_phone($password);
        if (!$normalized || !wp_check_password($normalized, $user->user_pass, $user->ID)) {
            Olama_Users_DB::audit('login_failed', $user->ID, 'family', $username, 'failed');
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }
        Olama_Users_DB::audit('login_succeeded', $user->ID, 'family', $username);
        return $user;
    }

    private function normalize_phone($value) {
        $value = strtr((string) $value, array('٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'));
        $digits = preg_replace('/\D+/', '', $value);
        if (0 === strpos($digits, '00962')) {
            return '0' . substr($digits, 5);
        }
        if (0 === strpos($digits, '962')) {
            return '0' . substr($digits, 3);
        }
        return $digits;
    }

    public function register_hub_card($cards) {
        $cards[] = array(
            'id' => 'olama-users',
            'label' => __('OLAMA Users', 'olama-users'),
            'description' => __('Accounts, roles, synchronization, and access matrix.', 'olama-users'),
            'icon' => 'dashicons-admin-users',
            'accent' => '#4f46e5',
            'accent_rgb' => '79,70,229',
            'active' => true,
            'capability' => 'olama_users_access',
            'primary_url' => admin_url('admin.php?page=olama-users'),
            'submenus' => array(
                array('id' => 'users.accounts', 'label' => __('Accounts', 'olama-users'), 'icon' => 'dashicons-groups', 'url' => admin_url('admin.php?page=olama-users'), 'capability' => 'olama_users_accounts_view', 'color' => '#4f46e5'),
                array('id' => 'users.roles', 'label' => __('Roles', 'olama-users'), 'icon' => 'dashicons-id-alt', 'url' => admin_url('admin.php?page=olama-users-roles'), 'capability' => 'olama_users_roles_manage', 'color' => '#4f46e5'),
                array('id' => 'users.matrix', 'label' => __('Access Matrix', 'olama-users'), 'icon' => 'dashicons-privacy', 'url' => admin_url('admin.php?page=olama-users-matrix'), 'capability' => 'olama_users_matrix_manage', 'color' => '#4f46e5'),
            ),
        );
        return $cards;
    }
}
