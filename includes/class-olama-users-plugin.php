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
        add_action('init', array('Olama_Users_Roles', 'enforce_deleted_roles'), 9999);
        add_action('init', array('Olama_Users_Roles', 'enforce_approved_roles'), 9999);
        add_action('wp_loaded', array('Olama_Users_Roles', 'enforce_approved_roles'), 9999);
        add_filter('user_has_cap', array($this, 'enforce_service_access'), 9999, 4);
        add_filter('authenticate', array($this, 'authenticate_temp_family'), 24, 3);
        add_filter('authenticate', array($this, 'authenticate_family'), 25, 3);
        add_filter('wp_authenticate_user', array($this, 'block_suspended_user'), 20, 2);
        add_action('admin_init', array($this, 'restrict_temp_family_admin'));
        add_filter('show_admin_bar', array($this, 'hide_temp_family_admin_bar'));
        add_filter('olama_dashboard_cards', array($this, 'register_hub_card'), 30);
        add_action('olama_users_register_modules', array($this, 'register_access_module'));
        add_action('admin_menu', array('Olama_Users_Registry', 'discover_admin_menus'), PHP_INT_MAX);
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
        Olama_Users_Roles::apply_default_deny_policy();
        Olama_Users_Roles::seed_default_capabilities(Olama_Users_Registry::default_capabilities());
        Olama_Users_Temp_Families::seed_portal_capabilities();
    }

    public function enforce_service_access($allcaps, $caps, $args, $user) {
        if (!$user instanceof WP_User) {
            return $allcaps;
        }
        $requested = isset($args[0]) ? sanitize_key((string) $args[0]) : '';
        if (!$requested) {
            return $allcaps;
        }
        $declared = Olama_Users_Registry::capabilities();
        $is_olama_service = isset($declared[$requested]) ||
            0 === strpos($requested, 'olama_') ||
            0 === strpos($requested, 'os_');
        $is_administrator = in_array('administrator', (array) $user->roles, true);
        if ($is_administrator) {
            if ($is_olama_service) {
                $allcaps[$requested] = true;
            }
            return $allcaps;
        }

        $privileged = array_diff(
            Olama_Users_Roles::non_delegable_capabilities(),
            Olama_Users_Roles::management_capabilities()
        );
        if (in_array($requested, $privileged, true)) {
            $allcaps[$requested] = false;
            return $allcaps;
        }

        if (!$is_olama_service) {
            return $allcaps;
        }
        if (!isset($declared[$requested])) {
            $allcaps[$requested] = false;
            return $allcaps;
        }

        $granted_by_role = false;
        foreach ((array) $user->roles as $role_key) {
            if (Olama_Users_Roles::role_has_declared_capability($role_key, $requested)) {
                $granted_by_role = true;
                break;
            }
        }
        $allcaps[$requested] = $granted_by_role;
        return $allcaps;
    }

    public function block_suspended_user($user, $password) {
        if (is_wp_error($user) || !$user instanceof WP_User) {
            return $user;
        }
        $is_expired = 'temp_family' === get_user_meta($user->ID, 'olama_identity_type', true)
            && Olama_Users_Temp_Families::is_expired($user->ID);
        if ('suspended' === get_user_meta($user->ID, 'olama_account_status', true) || $is_expired) {
            Olama_Users_DB::audit('login_blocked', $user->ID, get_user_meta($user->ID, 'olama_identity_type', true), get_user_meta($user->ID, 'olama_oracle_identifier', true), 'blocked');
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }
        return $user;
    }

    public function restrict_temp_family_admin() {
        if (wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }
        if ('temp_family' === get_user_meta(get_current_user_id(), 'olama_identity_type', true)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    public function hide_temp_family_admin_bar($show) {
        if (is_user_logged_in() && 'temp_family' === get_user_meta(get_current_user_id(), 'olama_identity_type', true)) {
            return false;
        }
        return $show;
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
        $prefix = Olama_Users_Sync::password_prefix('family');
        $password = (string) $password;
        if ('' !== $prefix && 0 !== strpos($password, $prefix)) {
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }
        $base_password = '' === $prefix ? $password : substr($password, strlen($prefix));
        $normalized = $this->normalize_phone($base_password);
        $candidate = $prefix . $normalized;
        if (!$normalized || !wp_check_password($candidate, $user->user_pass, $user->ID)) {
            Olama_Users_DB::audit('login_failed', $user->ID, 'family', $username, 'failed');
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }
        Olama_Users_DB::audit('login_succeeded', $user->ID, 'family', $username);
        return $user;
    }

    public function authenticate_temp_family($result, $username, $password) {
        if ($result instanceof WP_User) {
            return $result;
        }
        $username = trim((string) $username);
        $password = (string) $password;
        if ('' === $username || '' === $password) {
            return $result;
        }

        $login = $username;
        if (0 !== strpos($login, 'tf_')) {
            if (!preg_match('/^\d+$/', $login)) {
                return $result;
            }
            // A real Core family number always keeps priority over the
            // convenient prefix-free alias for a temporary account.
            if (Olama_Users_DB::get_identity('family', $login)) {
                return $result;
            }
            $login = 'tf_' . $login;
        }

        $user = get_user_by('login', $login);
        if (!$user instanceof WP_User) {
            return $result;
        }
        $identity = Olama_Users_DB::get_identity_by_user($user->ID);
        if (!$identity || 'temp_family' !== (string) $identity['identity_type']) {
            return $result;
        }
        if (
            'active' !== (string) $identity['account_status'] ||
            Olama_Users_Temp_Families::is_expired($user->ID) ||
            !wp_check_password($password, $user->user_pass, $user->ID)
        ) {
            Olama_Users_DB::audit('login_failed', $user->ID, 'temp_family', $identity['oracle_identifier'], 'failed');
            return new WP_Error('invalid_username', __('Invalid username or password.', 'olama-users'));
        }

        Olama_Users_DB::audit('login_succeeded', $user->ID, 'temp_family', $identity['oracle_identifier']);
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
                array('id' => 'users.temp_families', 'label' => __('Temp Families', 'olama-users'), 'icon' => 'dashicons-clock', 'url' => admin_url('admin.php?page=olama-users-temp-families'), 'capability' => 'olama_users_temp_families_manage', 'color' => '#4f46e5'),
                array('id' => 'users.roles', 'label' => __('Roles', 'olama-users'), 'icon' => 'dashicons-id-alt', 'url' => admin_url('admin.php?page=olama-users-roles'), 'capability' => 'olama_users_roles_manage', 'color' => '#4f46e5'),
                array('id' => 'users.matrix', 'label' => __('Capabilities', 'olama-users'), 'icon' => 'dashicons-privacy', 'url' => admin_url('admin.php?page=olama-users-matrix'), 'capability' => 'olama_users_matrix_manage', 'color' => '#4f46e5'),
                array('id' => 'users.settings', 'label' => __('Settings', 'olama-users'), 'icon' => 'dashicons-admin-settings', 'url' => admin_url('admin.php?page=olama-users-settings'), 'capability' => 'olama_users_settings_manage', 'color' => '#4f46e5'),
            ),
        );
        return $cards;
    }

    public function register_access_module() {
        Olama_Users_Registry::register(array(
            'id' => 'olama_users',
            'plugin' => 'olama-users',
            'label' => __('OLAMA Users', 'olama-users'),
            'capability' => 'olama_users_access',
            'items' => array(
                array(
                    'id' => 'olama_users.accounts',
                    'type' => 'submenu',
                    'label' => __('Accounts', 'olama-users'),
                    'capability' => 'olama_users_accounts_view',
                    'actions' => array(
                        array('id' => 'olama_users.accounts.manage', 'type' => 'action', 'label' => __('Manage accounts', 'olama-users'), 'capability' => 'olama_users_accounts_manage'),
                        array('id' => 'olama_users.accounts.preview', 'type' => 'action', 'label' => __('Preview synchronization', 'olama-users'), 'capability' => 'olama_users_sync_preview'),
                        array('id' => 'olama_users.accounts.apply', 'type' => 'action', 'label' => __('Apply synchronization', 'olama-users'), 'capability' => 'olama_users_sync_apply'),
                    ),
                ),
                array('id' => 'olama_users.temp_families', 'type' => 'submenu', 'label' => __('Manage Temp Families', 'olama-users'), 'capability' => 'olama_users_temp_families_manage'),
                array('id' => 'olama_users.roles', 'type' => 'submenu', 'label' => __('Manage roles', 'olama-users'), 'capability' => 'olama_users_roles_manage'),
                array('id' => 'olama_users.capabilities', 'type' => 'submenu', 'label' => __('Manage capabilities', 'olama-users'), 'capability' => 'olama_users_matrix_manage'),
                array('id' => 'olama_users.settings', 'type' => 'submenu', 'label' => __('Manage password settings', 'olama-users'), 'capability' => 'olama_users_settings_manage'),
                array('id' => 'olama_users.audit', 'type' => 'submenu', 'label' => __('View audit log', 'olama-users'), 'capability' => 'olama_users_audit_view'),
                array('id' => 'olama_users.ministry', 'type' => 'submenu', 'label' => 'البيانات الإحصائية للطلبة', 'capability' => 'olama_users_ministry_view',
                    'actions' => array(
                        array('id' => 'olama_users.ministry.review', 'type' => 'action', 'label' => 'مراجعة البيانات الإحصائية', 'capability' => 'olama_users_ministry_review'),
                        array('id' => 'olama_users.ministry.configure', 'type' => 'action', 'label' => 'إعداد بيانات المدرسة الإحصائية', 'capability' => 'olama_users_ministry_configure'),
                    )),
            ),
        ));
    }
}
