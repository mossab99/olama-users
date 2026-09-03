<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Roles {
    const METADATA_OPTION = 'olama_users_role_metadata';
    const DELETED_OPTION = 'olama_users_deleted_roles';
    const APPROVED_OPTION = 'olama_users_approved_roles';
    const ACCESS_POLICY_OPTION = 'olama_users_access_policy_version';
    const GRANTS_OPTION = 'olama_users_role_capability_grants';
    const DEFAULT_GRANTS_SEEDED_OPTION = 'olama_users_default_capability_seeds';
    const SEEDED_OPTION = 'olama_users_roles_seeded';
    const FAMILY_DEFAULT_OPTION = 'olama_users_family_default_role';
    const EMPLOYEE_DEFAULT_OPTION = 'olama_users_employee_default_role';
    const FAMILY_DEFAULT_CONFIGURED_OPTION = 'olama_users_family_default_role_configured';
    const EMPLOYEE_DEFAULT_CONFIGURED_OPTION = 'olama_users_employee_default_role_configured';
    private static $authority_depth = 0;

    public static function definitions() {
        return array(
            'olama_family' => __('Family', 'olama-users'),
            'olama_employee_no_access' => __('Employee — No Access', 'olama-users'),
            'olama_teacher' => __('Teacher', 'olama-users'),
            'olama_exam_coordinator' => __('Exam Coordinator', 'olama-users'),
            'olama_exam_hall_manager' => __('Exam Hall Manager', 'olama-users'),
            'olama_exam_attendance' => __('Exam Attendance', 'olama-users'),
            'olama_school_manager' => __('School Manager', 'olama-users'),
            'olama_users_administrator' => __('OLAMA Users Administrator', 'olama-users'),
        );
    }

    public static function required_keys() {
        return array('administrator');
    }

    public static function management_capabilities() {
        return array(
            'olama_users_access',
            'olama_users_accounts_view',
            'olama_users_accounts_manage',
            'olama_users_roles_manage',
            'olama_users_matrix_manage',
            'olama_users_settings_manage',
            'olama_users_sync_preview',
            'olama_users_sync_apply',
            'olama_users_audit_view',
        );
    }

    public static function apply_default_deny_policy() {
        if ('1' === (string) get_option(self::ACCESS_POLICY_OPTION, '')) {
            return;
        }
        $declared = array_keys(Olama_Users_Registry::capabilities());
        foreach (wp_roles()->roles as $key => $definition) {
            if ('administrator' === $key) {
                continue;
            }
            $role = get_role($key);
            if (!$role) {
                continue;
            }
            foreach ($declared as $capability) {
                $role->remove_cap($capability);
            }
        }
        update_option(self::GRANTS_OPTION, array(), false);
        update_option(self::ACCESS_POLICY_OPTION, '1', false);
        Olama_Users_DB::audit('default_deny_policy_applied', 0, 'local_system', 'all_non_administrators', 'success', array(
            'capabilities_cleared' => count($declared),
        ));
    }

    public static function non_delegable_capabilities() {
        return array_unique(array_merge(self::management_capabilities(), array(
            'manage_options', 'edit_users', 'create_users', 'delete_users', 'promote_users',
            'remove_users', 'list_users', 'activate_plugins', 'install_plugins', 'update_plugins',
            'delete_plugins', 'edit_plugins', 'switch_themes', 'edit_theme_options',
            'install_themes', 'update_themes', 'delete_themes', 'edit_themes', 'update_core',
            'unfiltered_html',
        )));
    }

    public static function install() {
        if (!get_option(self::APPROVED_OPTION, false)) {
            self::save_approved_keys(array_keys(wp_roles()->roles));
        }
        $metadata = self::metadata();
        foreach (array(
            'teacher' => __('Teacher', 'olama-users'),
            'assistant' => __('Assistant', 'olama-users'),
            'supervisor' => __('Supervisor', 'olama-users'),
        ) as $key => $label) {
            if (!self::is_deleted($key) && !get_role($key)) {
                add_role($key, $label, array('read' => true));
            }
            if (get_role($key)) {
                self::approve($key);
                if (!isset($metadata[$key])) {
                    $metadata[$key] = array(
                        'managed' => true,
                        'origin' => 'migrated',
                        'created_at' => current_time('mysql', true),
                    );
                }
            }
        }
        self::save_metadata($metadata);

        $definitions = self::definitions();
        if (!(bool) get_option(self::SEEDED_OPTION, false)) {
            foreach ($definitions as $key => $label) {
                if (!self::is_deleted($key) && !get_role($key)) {
                    add_role($key, $label, array('read' => true));
                }
            }
            update_option(self::SEEDED_OPTION, 1, false);
        }

        $metadata = self::metadata();
        foreach ($definitions as $key => $label) {
            if (get_role($key) && !isset($metadata[$key])) {
                $metadata[$key] = array(
                    'managed' => true,
                    'origin' => 'seeded',
                    'created_at' => current_time('mysql', true),
                );
            }
        }
        self::save_metadata($metadata);

        foreach (array('administrator', 'olama_users_administrator') as $role_key) {
            $role = get_role($role_key);
            if (!$role) {
                continue;
            }
            foreach (self::management_capabilities() as $capability) {
                $role->add_cap($capability);
            }
        }
        self::enforce_deleted_roles();
        self::enforce_approved_roles();
        update_option('olama_users_roles_version', OLAMA_USERS_VERSION);
    }

    public static function approved_keys() {
        $keys = get_option(self::APPROVED_OPTION, array());
        if (!is_array($keys)) {
            $keys = array();
        }
        $keys = array_values(array_unique(array_filter(array_map('sanitize_key', $keys))));
        return array_values(array_unique(array_merge(array('administrator', 'subscriber'), $keys)));
    }

    private static function save_approved_keys(array $keys) {
        $keys = array_values(array_unique(array_filter(array_map('sanitize_key', $keys))));
        update_option(self::APPROVED_OPTION, array_values(array_unique(array_merge(array('administrator', 'subscriber'), $keys))), false);
    }

    private static function approve($key) {
        $keys = self::approved_keys();
        $keys[] = sanitize_key($key);
        self::save_approved_keys($keys);
    }

    private static function unapprove($key) {
        self::save_approved_keys(array_values(array_diff(self::approved_keys(), array(sanitize_key($key)))));
    }

    public static function run_authorized($callback) {
        self::$authority_depth++;
        try {
            return call_user_func($callback);
        } finally {
            self::$authority_depth--;
        }
    }

    public static function is_authorized_change() {
        return self::$authority_depth > 0;
    }

    public static function deleted_keys() {
        $keys = get_option(self::DELETED_OPTION, array());
        if (!is_array($keys)) {
            return array();
        }
        $keys = array_values(array_unique(array_filter(array_map('sanitize_key', $keys))));
        return array_values(array_diff($keys, array('administrator', 'subscriber')));
    }

    public static function is_deleted($key) {
        return in_array(sanitize_key($key), self::deleted_keys(), true);
    }

    private static function suppress($key) {
        $key = sanitize_key($key);
        if (!$key || in_array($key, array('administrator', 'subscriber'), true)) {
            return;
        }
        $keys = self::deleted_keys();
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::DELETED_OPTION, array_values($keys), false);
        }
    }

    private static function unsuppress($key) {
        $key = sanitize_key($key);
        $keys = array_values(array_diff(self::deleted_keys(), array($key)));
        update_option(self::DELETED_OPTION, $keys, false);
    }

    public static function enforce_deleted_roles() {
        foreach (self::deleted_keys() as $key) {
            if (!get_role($key)) {
                continue;
            }
            self::move_role_users_to_subscriber($key);
            remove_role($key);
        }
    }

    public static function enforce_approved_roles() {
        $approved = self::approved_keys();
        foreach (array_keys(wp_roles()->roles) as $key) {
            if (in_array($key, $approved, true)) {
                continue;
            }
            self::move_role_users_to_subscriber($key);
            remove_role($key);
            Olama_Users_DB::audit('external_role_removed', 0, 'local_system', $key, 'blocked');
        }
    }

    public static function metadata() {
        $metadata = get_option(self::METADATA_OPTION, array());
        return is_array($metadata) ? $metadata : array();
    }

    private static function save_metadata(array $metadata) {
        update_option(self::METADATA_OPTION, $metadata, false);
    }

    public static function grants() {
        $grants = get_option(self::GRANTS_OPTION, array());
        return is_array($grants) ? $grants : array();
    }

    private static function save_grants(array $grants) {
        update_option(self::GRANTS_OPTION, $grants, false);
    }

    /**
     * Grants newly declared defaults once per role/capability pair.
     *
     * The seed marker is intentionally retained when an administrator later
     * revokes the capability, so a manual matrix choice is never overwritten.
     */
    public static function seed_default_capabilities(array $capabilities) {
        $capabilities = array_values(array_unique(array_filter(array_map('sanitize_key', $capabilities))));
        if (!$capabilities) {
            return;
        }
        $seeds = get_option(self::DEFAULT_GRANTS_SEEDED_OPTION, array());
        $seeds = is_array($seeds) ? $seeds : array();
        $grants = self::grants();
        $changed = false;
        foreach (self::editable() as $role_key => $label) {
            if (!in_array($role_key, self::approved_keys(), true)) {
                continue;
            }
            $role = get_role($role_key);
            if (!$role) {
                continue;
            }
            $role_seeds = isset($seeds[$role_key]) && is_array($seeds[$role_key]) ? $seeds[$role_key] : array();
            $role_grants = isset($grants[$role_key]) && is_array($grants[$role_key]) ? $grants[$role_key] : array();
            foreach ($capabilities as $capability) {
                $default_grant_roles = Olama_Users_Registry::default_grant_roles($capability);
                if ($default_grant_roles && !in_array($role_key, $default_grant_roles, true)) {
                    continue;
                }
                if (in_array($capability, $role_seeds, true)) {
                    continue;
                }
                $role->add_cap($capability);
                $role_grants[] = $capability;
                $role_seeds[] = $capability;
                $changed = true;
            }
            $grants[$role_key] = array_values(array_unique($role_grants));
            $seeds[$role_key] = array_values(array_unique($role_seeds));
        }
        if ($changed) {
            self::save_grants($grants);
            update_option(self::DEFAULT_GRANTS_SEEDED_OPTION, $seeds, false);
        }
    }

    public static function role_has_declared_capability($role_key, $capability) {
        $role_key = sanitize_key($role_key);
        $capability = sanitize_key($capability);
        $grants = self::grants();
        return !empty($grants[$role_key]) && in_array($capability, (array) $grants[$role_key], true);
    }

    public static function is_managed($key) {
        $metadata = self::metadata();
        return !empty($metadata[$key]['managed']);
    }

    public static function is_protected($key) {
        return 'administrator' === sanitize_key($key);
    }

    public static function editable() {
        $roles = array();
        foreach (wp_roles()->roles as $key => $definition) {
            if ('administrator' !== $key) {
                $roles[$key] = translate_user_role($definition['name']);
            }
        }
        natcasesort($roles);
        return $roles;
    }

    public static function all() {
        $counts = count_users();
        $role_counts = isset($counts['avail_roles']) && is_array($counts['avail_roles']) ? $counts['avail_roles'] : array();
        $metadata = self::metadata();
        $core = array('administrator', 'editor', 'author', 'contributor', 'subscriber');
        $roles = array();
        foreach (wp_roles()->roles as $key => $definition) {
            $managed = !empty($metadata[$key]['managed']);
            $roles[$key] = array(
                'key' => $key,
                'label' => translate_user_role($definition['name']),
                'capabilities' => count(array_filter($definition['capabilities'])),
                'users' => isset($role_counts[$key]) ? (int) $role_counts[$key] : 0,
                'managed' => $managed,
                'editable' => 'administrator' !== $key,
                'protected' => 'administrator' === $key,
                'source' => $managed ? (!empty($metadata[$key]['origin']) ? $metadata[$key]['origin'] : 'olama') : (in_array($key, $core, true) ? 'wordpress' : 'external'),
                'dependencies' => self::dependencies($key),
            );
        }
        uasort($roles, function($left, $right) {
            return strcasecmp($left['label'], $right['label']);
        });
        return $roles;
    }

    public static function default_role($type) {
        $option = 'family' === $type ? self::FAMILY_DEFAULT_OPTION : self::EMPLOYEE_DEFAULT_OPTION;
        $configured_option = 'family' === $type
            ? self::FAMILY_DEFAULT_CONFIGURED_OPTION
            : self::EMPLOYEE_DEFAULT_CONFIGURED_OPTION;
        if ('1' !== (string) get_option($configured_option, '')) {
            return '';
        }
        $preferred = sanitize_key((string) get_option($option));
        if (
            $preferred &&
            get_role($preferred) &&
            'administrator' !== $preferred &&
            in_array($preferred, self::approved_keys(), true)
        ) {
            return $preferred;
        }
        return '';
    }

    public static function set_default_role($type, $role_key) {
        $type = 'family' === $type ? 'family' : 'employee';
        $role_key = sanitize_key($role_key);
        if (
            !$role_key ||
            'administrator' === $role_key ||
            !get_role($role_key) ||
            !in_array($role_key, self::approved_keys(), true)
        ) {
            return new WP_Error('invalid_default_role', __('Select a valid OLAMA-approved non-Administrator role.', 'olama-users'));
        }
        $option = 'family' === $type ? self::FAMILY_DEFAULT_OPTION : self::EMPLOYEE_DEFAULT_OPTION;
        $configured_option = 'family' === $type
            ? self::FAMILY_DEFAULT_CONFIGURED_OPTION
            : self::EMPLOYEE_DEFAULT_CONFIGURED_OPTION;
        update_option($option, $role_key, false);
        update_option($configured_option, '1', false);
        return $role_key;
    }

    public static function clear_default_role($type) {
        $type = 'family' === $type ? 'family' : 'employee';
        $option = 'family' === $type ? self::FAMILY_DEFAULT_OPTION : self::EMPLOYEE_DEFAULT_OPTION;
        $configured_option = 'family' === $type
            ? self::FAMILY_DEFAULT_CONFIGURED_OPTION
            : self::EMPLOYEE_DEFAULT_CONFIGURED_OPTION;
        delete_option($option);
        delete_option($configured_option);
    }

    public static function default_roles_ready() {
        return '' !== self::default_role('family') && '' !== self::default_role('employee');
    }

    public static function dependencies($key) {
        $dependencies = array();
        if ((string) get_option('default_role', 'subscriber') === $key) {
            $dependencies[] = __('WordPress default', 'olama-users');
        }
        if (self::default_role('family') === $key) {
            $dependencies[] = __('Family synchronization', 'olama-users');
        }
        if (self::default_role('employee') === $key) {
            $dependencies[] = __('Employee synchronization', 'olama-users');
        }
        return $dependencies;
    }

    public static function create($key, $label, $copy_from = '') {
        $key = self::normalize_key($key);
        $label = self::normalize_label($label);
        if (is_wp_error($key)) {
            return $key;
        }
        if (is_wp_error($label)) {
            return $label;
        }
        if (get_role($key)) {
            return new WP_Error('role_exists', __('That permanent role key already exists.', 'olama-users'));
        }

        $capabilities = array('read' => true);
        if ($copy_from) {
            $copy_from = sanitize_key($copy_from);
            $source = get_role($copy_from);
            if (!$source || 'administrator' === $copy_from) {
                return new WP_Error('invalid_source_role', __('Administrator cannot be duplicated.', 'olama-users'));
            }
            $capabilities = $source->capabilities;
            foreach (self::non_delegable_capabilities() as $capability) {
                unset($capabilities[$capability]);
            }
            $capabilities['read'] = true;
        }

        if (!add_role($key, $label, $capabilities)) {
            return new WP_Error('role_create_failed', __('WordPress could not create the role.', 'olama-users'));
        }
        self::approve($key);
        self::unsuppress($key);
        $metadata = self::metadata();
        $metadata[$key] = array(
            'managed' => true,
            'origin' => $copy_from ? 'duplicated' : 'custom',
            'created_at' => current_time('mysql', true),
        );
        self::save_metadata($metadata);
        self::seed_default_capabilities(Olama_Users_Registry::default_capabilities());
        return array('key' => $key, 'label' => $label, 'copied_from' => $copy_from);
    }

    public static function rename($key, $label) {
        $key = sanitize_key($key);
        $label = self::normalize_label($label);
        if (is_wp_error($label)) {
            return $label;
        }
        if (!get_role($key)) {
            return new WP_Error('role_missing', __('Role not found.', 'olama-users'));
        }
        if ('administrator' === $key) {
            return new WP_Error('protected_role', __('Administrator cannot be edited.', 'olama-users'));
        }
        $wp_roles = wp_roles();
        $old_label = isset($wp_roles->roles[$key]['name']) ? $wp_roles->roles[$key]['name'] : $key;
        $wp_roles->roles[$key]['name'] = $label;
        $wp_roles->role_names[$key] = $label;
        update_option($wp_roles->role_key, $wp_roles->roles);
        return array('key' => $key, 'label' => $label, 'old_label' => $old_label);
    }

    public static function delete($key, $replacement = '') {
        $key = sanitize_key($key);
        if (!get_role($key)) {
            return new WP_Error('role_missing', __('Role not found.', 'olama-users'));
        }
        if ('administrator' === $key) {
            return new WP_Error('protected_role', __('Administrator cannot be deleted.', 'olama-users'));
        }
        $replacement = 'subscriber';
        if ('subscriber' !== $key && !get_role($replacement)) {
            add_role($replacement, __('Subscriber', 'olama-users'), array('read' => true));
        }

        $was_wordpress_default = (string) get_option('default_role', 'subscriber') === $key;
        $was_family_default = self::default_role('family') === $key;
        $was_employee_default = self::default_role('employee') === $key;
        $users = get_users(array('role' => $key, 'fields' => array('ID')));
        if ('subscriber' !== $key) {
            self::move_role_users_to_subscriber($key, $users);
        }

        if ($was_wordpress_default || 'subscriber' === $key) {
            update_option('default_role', $replacement);
        }
        if ($was_family_default) {
            self::clear_default_role('family');
        }
        if ($was_employee_default) {
            self::clear_default_role('employee');
        }

        remove_role($key);
        $recreated_fallback = false;
        if ('subscriber' === $key) {
            add_role('subscriber', __('Subscriber', 'olama-users'), array('read' => true));
            $recreated_fallback = true;
            self::unsuppress($key);
        } else {
            self::suppress($key);
            self::unapprove($key);
        }
        $metadata = self::metadata();
        unset($metadata[$key]);
        self::save_metadata($metadata);
        $grants = self::grants();
        unset($grants[$key]);
        self::save_grants($grants);
        return array(
            'key' => $key,
            'replacement' => $replacement,
            'reassigned_users' => count($users),
            'recreated_fallback' => $recreated_fallback,
            'family_default_cleared' => $was_family_default,
            'employee_default_cleared' => $was_employee_default,
        );
    }

    private static function move_role_users_to_subscriber($key, $users = null) {
        $key = sanitize_key($key);
        if (!$key || in_array($key, array('administrator', 'subscriber'), true)) {
            return;
        }
        if (!get_role('subscriber')) {
            add_role('subscriber', __('Subscriber', 'olama-users'), array('read' => true));
        }
        if (null === $users) {
            $users = get_users(array('role' => $key, 'fields' => array('ID')));
        }
        foreach ($users as $item) {
            $user_id = is_object($item) ? (int) $item->ID : (int) $item;
            $user = get_userdata($user_id);
            if ($user) {
                self::run_authorized(function() use ($user, $key) {
                    $user->add_role('subscriber');
                    $user->remove_role($key);
                });
            }
        }
    }

    public static function assign($user_id, $role_key) {
        $user_id = absint($user_id);
        $role_key = sanitize_key($role_key);
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_missing', __('User not found.', 'olama-users'));
        }
        if (in_array('administrator', (array) $user->roles, true)) {
            return new WP_Error('administrator_account_protected', __('Administrator accounts cannot be reassigned.', 'olama-users'));
        }
        if ('administrator' === $role_key || !get_role($role_key) || !in_array($role_key, self::approved_keys(), true)) {
            return new WP_Error('invalid_assignment_role', __('Select an OLAMA-approved non-Administrator role.', 'olama-users'));
        }
        $old_roles = (array) $user->roles;
        self::run_authorized(function() use ($user, $role_key) {
            $user->set_role($role_key);
        });
        return array(
            'user_id' => $user_id,
            'old_roles' => $old_roles,
            'role' => $role_key,
        );
    }

    public static function block_external_set_role($user_id, $role, $old_roles) {
        if (self::is_authorized_change()) {
            return;
        }
        if (
            !in_array('administrator', (array) $old_roles, true) &&
            self::is_verified_wordpress_role_change($user_id, $role, 'set')
        ) {
            Olama_Users_DB::audit('wordpress_role_assignment', absint($user_id), 'local_system', sanitize_key($role), 'success');
            return;
        }
        self::restore_roles($user_id, (array) $old_roles);
        Olama_Users_DB::audit('external_role_assignment_blocked', absint($user_id), 'local_system', sanitize_key($role), 'blocked');
    }

    public static function block_external_add_role($user_id, $role) {
        if (self::is_authorized_change()) {
            return;
        }
        $user = get_userdata(absint($user_id));
        if (
            $user &&
            !in_array('administrator', (array) $user->roles, true) &&
            self::is_verified_wordpress_role_change($user_id, $role, 'add')
        ) {
            Olama_Users_DB::audit('wordpress_role_added', absint($user_id), 'local_system', sanitize_key($role), 'success');
            return;
        }
        self::run_authorized(function() use ($user_id, $role) {
            $user = get_userdata(absint($user_id));
            if ($user) {
                $user->remove_role(sanitize_key($role));
            }
        });
        Olama_Users_DB::audit('external_role_assignment_blocked', absint($user_id), 'local_system', sanitize_key($role), 'blocked');
    }

    public static function block_external_remove_role($user_id, $role) {
        if (self::is_authorized_change()) {
            return;
        }
        if (self::is_verified_wordpress_role_change($user_id, $role, 'remove')) {
            Olama_Users_DB::audit('wordpress_role_removed', absint($user_id), 'local_system', sanitize_key($role), 'success');
            return;
        }
        self::run_authorized(function() use ($user_id, $role) {
            $user = get_userdata(absint($user_id));
            if ($user && get_role(sanitize_key($role))) {
                $user->add_role(sanitize_key($role));
            }
        });
        Olama_Users_DB::audit('external_role_removal_blocked', absint($user_id), 'local_system', sanitize_key($role), 'blocked');
    }

    /**
     * Allow role changes submitted through WordPress' native Users screens.
     *
     * The request must carry the nonce used by WordPress core, the actor must
     * be allowed to promote the target user, and the submitted role must be an
     * approved non-Administrator OLAMA role. The Members plugin's nonce and
     * multi-role selection are also supported. Programmatic role changes
     * remain blocked unless they use run_authorized().
     */
    private static function is_verified_wordpress_role_change($user_id, $role, $operation) {
        if (
            !is_admin() ||
            !is_user_logged_in() ||
            'POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '') ||
            !current_user_can('promote_users')
        ) {
            return false;
        }

        $user_id = absint($user_id);
        if (!$user_id || !current_user_can('promote_user', $user_id)) {
            return false;
        }

        global $pagenow;
        $page = isset($pagenow) ? basename((string) $pagenow) : '';
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (('-1' === $action || '' === $action) && isset($_REQUEST['action2'])) {
            $action = sanitize_key(wp_unslash($_REQUEST['action2']));
        }

        $verified = false;
        if ('user-new.php' === $page && 'createuser' === $action && isset($_REQUEST['_wpnonce_create-user'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce_create-user']));
            $verified = (bool) wp_verify_nonce($nonce, 'create-user');
        } elseif ('user-edit.php' === $page && 'update' === $action && isset($_REQUEST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
            $verified = (bool) wp_verify_nonce($nonce, 'update-user_' . $user_id);
        } elseif ('users.php' === $page && 'promote' === $action && isset($_REQUEST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
            $verified = (bool) wp_verify_nonce($nonce, 'bulk-users');
        }

        if (!$verified) {
            return false;
        }

        $role = sanitize_key($role);
        $operation = sanitize_key($operation);
        if (
            !$role ||
            !in_array($operation, array('set', 'add', 'remove'), true) ||
            'administrator' === $role ||
            !get_role($role) ||
            !in_array($role, self::approved_keys(), true)
        ) {
            return false;
        }

        // Members replaces WordPress' single-role selector with checkboxes and
        // applies its changes later via add_role() and remove_role(). Respect
        // that desired role set only when its own nonce is also valid.
        if (isset($_POST['members_new_user_roles_nonce'])) {
            $members_nonce = sanitize_text_field(wp_unslash($_POST['members_new_user_roles_nonce']));
            if (!wp_verify_nonce($members_nonce, 'new_user_roles')) {
                return false;
            }
            $submitted_roles = isset($_POST['members_user_roles']) && is_array($_POST['members_user_roles'])
                ? array_values(array_unique(array_filter(array_map('sanitize_key', wp_unslash($_POST['members_user_roles'])))))
                : array();
            foreach ($submitted_roles as $submitted_role) {
                if (
                    'administrator' === $submitted_role ||
                    !get_role($submitted_role) ||
                    !in_array($submitted_role, self::approved_keys(), true)
                ) {
                    return false;
                }
            }
            return 'remove' === $operation
                ? !in_array($role, $submitted_roles, true)
                : in_array($role, $submitted_roles, true);
        }

        $submitted_role = 'users.php' === $page
            ? (isset($_REQUEST['new_role']) ? sanitize_key(wp_unslash($_REQUEST['new_role'])) : '')
            : (isset($_REQUEST['role']) ? sanitize_key(wp_unslash($_REQUEST['role'])) : '');
        if (
            !$submitted_role ||
            'none' === $submitted_role ||
            'administrator' === $submitted_role ||
            !get_role($submitted_role) ||
            !in_array($submitted_role, self::approved_keys(), true)
        ) {
            return false;
        }

        // WordPress core's single-role workflow removes the old role before
        // adding and finally setting the submitted one.
        return 'remove' === $operation || $submitted_role === $role;
    }

    private static function restore_roles($user_id, array $roles) {
        self::run_authorized(function() use ($user_id, $roles) {
            $user = get_userdata(absint($user_id));
            if (!$user) {
                return;
            }
            $user->set_role('');
            foreach (array_values(array_unique(array_filter(array_map('sanitize_key', $roles)))) as $role) {
                if (get_role($role)) {
                    $user->add_role($role);
                }
            }
        });
    }

    public static function save_plugin_capabilities($key, $plugin_id, array $submitted) {
        $key = sanitize_key($key);
        $plugin_id = sanitize_key($plugin_id);
        $role = get_role($key);
        $registered = array_keys(Olama_Users_Registry::module_capabilities($plugin_id));
        if (!$role || 'administrator' === $key || !in_array($key, self::approved_keys(), true)) {
            return new WP_Error('invalid_capability_role', __('Select a valid non-Administrator role.', 'olama-users'));
        }
        if (!$registered) {
            return new WP_Error('invalid_capability_plugin', __('Select a plugin that declares OLAMA capabilities.', 'olama-users'));
        }
        $submitted = array_values(array_unique(array_map('sanitize_key', $submitted)));
        $granted = array_values(array_intersect($registered, $submitted));
        $module = Olama_Users_Registry::get($plugin_id);
        $parent_capability = $module && !empty($module['capability']) ? sanitize_key($module['capability']) : '';
        if ($granted && $parent_capability && in_array($parent_capability, $registered, true)) {
            $granted[] = $parent_capability;
            $granted = array_values(array_unique($granted));
        }
        foreach ($registered as $capability) {
            if (in_array($capability, $granted, true)) {
                $role->add_cap($capability);
            } else {
                $role->remove_cap($capability);
            }
        }
        $grants = self::grants();
        $current = isset($grants[$key]) && is_array($grants[$key]) ? $grants[$key] : array();
        $current = array_values(array_diff($current, $registered));
        $grants[$key] = array_values(array_unique(array_merge($current, $granted)));
        self::save_grants($grants);
        return array(
            'key' => $key,
            'plugin' => $plugin_id,
            'granted' => $granted,
            'managed_capabilities' => count($registered),
        );
    }

    private static function normalize_key($key) {
        $key = strtolower(trim((string) $key));
        $key = str_replace(array('-', ' '), '_', $key);
        if (0 !== strpos($key, 'olama_')) {
            $key = 'olama_' . $key;
        }
        $key = sanitize_key($key);
        if (!preg_match('/^olama_[a-z0-9_]{2,54}$/', $key)) {
            return new WP_Error('invalid_role_key', __('Use at least two English letters or numbers for the permanent role key.', 'olama-users'));
        }
        return $key;
    }

    private static function normalize_label($label) {
        $label = sanitize_text_field((string) $label);
        $length = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
        if ('' === $label || $length > 100) {
            return new WP_Error('invalid_role_label', __('Enter a role name between 1 and 100 characters.', 'olama-users'));
        }
        return $label;
    }
}
