<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Roles {
    const METADATA_OPTION = 'olama_users_role_metadata';
    const SEEDED_OPTION = 'olama_users_roles_seeded';

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
        return array('olama_family', 'olama_employee_no_access', 'olama_users_administrator');
    }

    public static function management_capabilities() {
        return array(
            'olama_users_access',
            'olama_users_accounts_view',
            'olama_users_accounts_manage',
            'olama_users_roles_manage',
            'olama_users_matrix_manage',
            'olama_users_sync_preview',
            'olama_users_sync_apply',
            'olama_users_audit_view',
        );
    }

    public static function non_delegable_capabilities() {
        return array_unique(array_merge(self::management_capabilities(), array(
            'manage_options',
            'edit_users',
            'create_users',
            'delete_users',
            'promote_users',
            'remove_users',
            'list_users',
            'activate_plugins',
            'install_plugins',
            'update_plugins',
            'delete_plugins',
            'edit_plugins',
            'switch_themes',
            'edit_theme_options',
            'install_themes',
            'update_themes',
            'delete_themes',
            'edit_themes',
            'update_core',
            'unfiltered_html',
        )));
    }

    public static function install() {
        $definitions = self::definitions();
        $seeded = (bool) get_option(self::SEEDED_OPTION, false);
        $install = $seeded ? array_intersect_key($definitions, array_flip(self::required_keys())) : $definitions;
        foreach ($install as $key => $label) {
            if (!get_role($key)) {
                add_role($key, $label, array('read' => true));
            }
        }

        $metadata = self::metadata();
        foreach ($definitions as $key => $label) {
            if (!get_role($key) || isset($metadata[$key])) {
                continue;
            }
            $metadata[$key] = array(
                'managed' => true,
                'protected' => in_array($key, self::required_keys(), true),
                'origin' => 'seeded',
                'created_at' => current_time('mysql', true),
            );
        }
        self::save_metadata($metadata);
        update_option(self::SEEDED_OPTION, 1, false);

        foreach (array('administrator', 'olama_users_administrator') as $role_key) {
            $role = get_role($role_key);
            if (!$role) {
                continue;
            }
            foreach (self::management_capabilities() as $capability) {
                $role->add_cap($capability);
            }
        }
        update_option('olama_users_roles_version', OLAMA_USERS_VERSION);
    }

    public static function metadata() {
        $metadata = get_option(self::METADATA_OPTION, array());
        return is_array($metadata) ? $metadata : array();
    }

    private static function save_metadata(array $metadata) {
        update_option(self::METADATA_OPTION, $metadata, false);
    }

    public static function is_managed($key) {
        $metadata = self::metadata();
        return !empty($metadata[$key]['managed']);
    }

    public static function is_protected($key) {
        if ('administrator' === $key || in_array($key, self::required_keys(), true)) {
            return true;
        }
        $metadata = self::metadata();
        return !empty($metadata[$key]['protected']);
    }

    public static function editable() {
        $roles = array();
        $wp_roles = wp_roles();
        foreach ($wp_roles->roles as $key => $definition) {
            if ('olama_users_administrator' === $key || !self::is_managed($key)) {
                continue;
            }
            $roles[$key] = translate_user_role($definition['name']);
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
                'protected' => self::is_protected($key),
                'source' => $managed ? (!empty($metadata[$key]['origin']) ? $metadata[$key]['origin'] : 'olama') : (in_array($key, $core, true) ? 'wordpress' : 'external'),
            );
        }
        uasort($roles, function($left, $right) {
            if ($left['managed'] !== $right['managed']) {
                return $left['managed'] ? -1 : 1;
            }
            return strcasecmp($left['label'], $right['label']);
        });
        return $roles;
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
            if (!$source || !self::is_managed($copy_from) || 'olama_users_administrator' === $copy_from) {
                return new WP_Error('invalid_source_role', __('Only a non-administrator OLAMA role can be duplicated.', 'olama-users'));
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
        $metadata = self::metadata();
        $metadata[$key] = array(
            'managed' => true,
            'protected' => false,
            'origin' => $copy_from ? 'duplicated' : 'custom',
            'created_at' => current_time('mysql', true),
        );
        self::save_metadata($metadata);
        return array('key' => $key, 'label' => $label, 'copied_from' => $copy_from);
    }

    public static function rename($key, $label) {
        $key = sanitize_key($key);
        $label = self::normalize_label($label);
        if (is_wp_error($label)) {
            return $label;
        }
        if (!get_role($key) || !self::is_managed($key)) {
            return new WP_Error('role_not_editable', __('Only OLAMA-managed roles can be renamed.', 'olama-users'));
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
        $replacement = sanitize_key($replacement);
        if (!get_role($key) || !self::is_managed($key)) {
            return new WP_Error('role_not_deletable', __('Only OLAMA-managed roles can be deleted.', 'olama-users'));
        }
        if (self::is_protected($key)) {
            return new WP_Error('protected_role', __('This role is required by OLAMA and cannot be deleted.', 'olama-users'));
        }

        $users = get_users(array('role' => $key, 'fields' => array('ID')));
        if ($users) {
            if (!$replacement || $replacement === $key || !get_role($replacement) || !self::is_managed($replacement)) {
                return new WP_Error('replacement_required', __('Choose another OLAMA role for the assigned users before deleting this role.', 'olama-users'));
            }
            if ('olama_users_administrator' === $replacement) {
                return new WP_Error('unsafe_replacement', __('Users cannot be moved automatically into the OLAMA Users Administrator role.', 'olama-users'));
            }
            foreach ($users as $item) {
                $user = get_userdata((int) $item->ID);
                if (!$user) {
                    continue;
                }
                $user->add_role($replacement);
                $user->remove_role($key);
            }
        }

        remove_role($key);
        $metadata = self::metadata();
        unset($metadata[$key]);
        self::save_metadata($metadata);
        return array('key' => $key, 'replacement' => $replacement, 'reassigned_users' => count($users));
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
