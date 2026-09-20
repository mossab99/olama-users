<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Admin {
    private $sync;

    public function __construct(Olama_Users_Sync $sync) {
        $this->sync = $sync;
        add_action('admin_menu', array($this, 'menus'));
        add_action('admin_post_olama_users_sync', array($this, 'handle_sync'));
        add_action('admin_post_olama_users_sync_action', array($this, 'handle_sync_action'));
        add_action('admin_post_olama_users_save_matrix', array($this, 'handle_matrix'));
        add_action('admin_post_olama_users_role_action', array($this, 'handle_role_action'));
        add_action('admin_post_olama_users_assign_role', array($this, 'handle_assign_role'));
        add_action('admin_post_olama_users_save_settings', array($this, 'handle_settings'));
        add_action('admin_post_olama_users_temp_family', array($this, 'handle_temp_family'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }

    public function menus() {
        add_menu_page(__('OLAMA Users', 'olama-users'), __('OLAMA Users', 'olama-users'), 'olama_users_access', 'olama-users', array($this, 'accounts'), 'dashicons-admin-users', 27);
        add_submenu_page('olama-users', __('Accounts', 'olama-users'), __('Accounts', 'olama-users'), 'olama_users_accounts_view', 'olama-users', array($this, 'accounts'));
        add_submenu_page('olama-users', __('Temp Families', 'olama-users'), __('Temp Families', 'olama-users'), 'olama_users_temp_families_manage', 'olama-users-temp-families', array($this, 'temp_families'));
        add_submenu_page('olama-users', __('Roles', 'olama-users'), __('Roles', 'olama-users'), 'olama_users_roles_manage', 'olama-users-roles', array($this, 'roles'));
        add_submenu_page('olama-users', __('Capabilities', 'olama-users'), __('Capabilities', 'olama-users'), 'olama_users_matrix_manage', 'olama-users-matrix', array($this, 'matrix'));
        add_submenu_page('olama-users', __('Settings', 'olama-users'), __('Settings', 'olama-users'), 'olama_users_settings_manage', 'olama-users-settings', array($this, 'settings'));
        add_submenu_page('olama-users', __('Audit Log', 'olama-users'), __('Audit Log', 'olama-users'), 'olama_users_audit_view', 'olama-users-audit', array($this, 'audit'));
    }

    public function assets($hook) {
        if (false === strpos($hook, 'olama-users')) {
            return;
        }
        $asset_version = OLAMA_USERS_VERSION . '.' . (string) @filemtime(OLAMA_USERS_PATH . 'assets/admin.css');
        wp_enqueue_style('olama-users-admin', OLAMA_USERS_URL . 'assets/admin.css', array(), $asset_version);
        wp_enqueue_style('olama-users-temp-families', OLAMA_USERS_URL . 'assets/temp-families.css', array('olama-users-admin'), OLAMA_USERS_VERSION . '.' . (string) @filemtime(OLAMA_USERS_PATH . 'assets/temp-families.css'));
        wp_enqueue_script('olama-users-admin', OLAMA_USERS_URL . 'assets/admin.js', array(), $asset_version, true);
    }

    public function handle_temp_family() {
        $this->authorize('olama_users_temp_families_manage');
        check_admin_referer('olama_users_temp_family');
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ('create' === $operation) {
            $result = Olama_Users_Temp_Families::create(wp_unslash($_POST));
            $message = __('Temp Family account created.', 'olama-users');
        } elseif ('update' === $operation) {
            $result = Olama_Users_Temp_Families::update($user_id, wp_unslash($_POST));
            $message = __('Temp Family account updated.', 'olama-users');
        } elseif ('activate' === $operation || 'deactivate' === $operation) {
            $result = Olama_Users_Temp_Families::set_status($user_id, 'activate' === $operation ? 'active' : 'suspended');
            $message = 'activate' === $operation ? __('Temp Family account activated.', 'olama-users') : __('Temp Family account deactivated and signed out.', 'olama-users');
        } elseif ('reset_password' === $operation) {
            $result = Olama_Users_Temp_Families::reset_password($user_id, isset($_POST['password']) ? wp_unslash($_POST['password']) : '');
            $message = __('Password updated and existing sessions signed out.', 'olama-users');
        } else {
            $result = new WP_Error('temp_family_invalid_operation', __('Unknown Temp Family operation.', 'olama-users'));
            $message = '';
        }
        $notice = is_wp_error($result)
            ? array('type' => 'error', 'message' => $result->get_error_message())
            : array('type' => 'success', 'message' => $message);
        set_transient('olama_users_temp_family_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        $args = array('page' => 'olama-users-temp-families');
        if (!is_wp_error($result) && in_array($operation, array('create', 'update'), true)) {
            $args['edit'] = absint($result);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function handle_sync() {
        if (!current_user_can('olama_users_sync_preview')) {
            wp_die(esc_html__('You are not allowed to synchronize users.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_sync');
        $type = isset($_POST['identity_type']) && 'employee' === $_POST['identity_type'] ? 'employee' : 'family';
        $mode = isset($_POST['sync_mode']) && 'apply' === $_POST['sync_mode'] ? 'apply' : 'preview';
        if ('apply' === $mode && !current_user_can('olama_users_sync_apply')) {
            wp_die(esc_html__('You are not allowed to apply synchronization.', 'olama-users'), '', array('response' => 403));
        }
        if ('apply' === $mode && !Olama_Users_Roles::default_roles_ready()) {
            set_transient(
                'olama_users_sync_' . get_current_user_id(),
                new WP_Error(
                    'default_roles_required',
                    __('Assign valid default roles for both families and employees in OLAMA Users Settings before applying synchronization.', 'olama-users')
                ),
                10 * MINUTE_IN_SECONDS
            );
            wp_safe_redirect(add_query_arg(array('page' => 'olama-users', 'type' => $type, 'mode' => $mode), admin_url('admin.php')));
            exit;
        }
        $result = 'apply' === $mode ? $this->sync->apply($type) : $this->sync->preview($type);
        set_transient('olama_users_sync_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users', 'type' => $type, 'mode' => $mode), admin_url('admin.php')));
        exit;
    }

    public function handle_sync_action() {
        if (!current_user_can('olama_users_sync_apply')) {
            wp_die(esc_html__('You are not allowed to apply synchronization.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_sync_action');
        $type = isset($_POST['identity_type']) && 'employee' === $_POST['identity_type'] ? 'employee' : 'family';
        $operation = isset($_POST['sync_operation']) && 'update' === $_POST['sync_operation'] ? 'update' : 'create';
        $identifier = isset($_POST['identifier']) ? sanitize_text_field(wp_unslash($_POST['identifier'])) : '';
        if (!Olama_Users_Roles::default_roles_ready()) {
            $result = new WP_Error('default_roles_required', __('Assign valid default roles for both families and employees before applying synchronization.', 'olama-users'));
        } elseif ($identifier) {
            $result = $this->sync->apply_one($type, $identifier, $operation);
        } else {
            $result = $this->sync->apply_batch($type, $operation);
        }
        set_transient('olama_users_sync_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users', 'type' => $type, 'mode' => 'apply'), admin_url('admin.php')));
        exit;
    }

    public function handle_matrix() {
        if (!current_user_can('olama_users_matrix_manage')) {
            wp_die(esc_html__('You are not allowed to manage access.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_save_matrix');
        $role_key = isset($_POST['role_key']) ? sanitize_key(wp_unslash($_POST['role_key'])) : '';
        $plugin_id = isset($_POST['plugin_id']) ? sanitize_key(wp_unslash($_POST['plugin_id'])) : '';
        $submitted = isset($_POST['caps']) && is_array($_POST['caps']) ? array_map('sanitize_key', wp_unslash($_POST['caps'])) : array();
        $result = Olama_Users_Roles::save_plugin_capabilities($role_key, $plugin_id, $submitted);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', array('response' => 400));
        }
        Olama_Users_DB::audit('role_capabilities_updated', 0, 'local_system', $role_key, 'success', $result);
        do_action('olama_users_capabilities_updated', $role_key, $plugin_id, $result);
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users-matrix', 'role' => $role_key, 'plugin' => $plugin_id, 'updated' => 1), admin_url('admin.php')));
        exit;
    }

    public function handle_role_action() {
        if (!current_user_can('olama_users_roles_manage')) {
            wp_die(esc_html__('You are not allowed to manage roles.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_role_action');
        $operation = isset($_POST['role_operation']) ? sanitize_key(wp_unslash($_POST['role_operation'])) : '';
        $key = isset($_POST['role_key']) ? wp_unslash($_POST['role_key']) : '';
        $label = isset($_POST['role_label']) ? wp_unslash($_POST['role_label']) : '';
        $source = isset($_POST['source_role']) ? sanitize_key(wp_unslash($_POST['source_role'])) : '';

        switch ($operation) {
            case 'create':
                $result = Olama_Users_Roles::create($key, $label);
                $success = __('Role created.', 'olama-users');
                $tab = 'available';
                break;
            case 'duplicate':
                $result = Olama_Users_Roles::create($key, $label, $source);
                $success = __('Role duplicated.', 'olama-users');
                $tab = 'available';
                break;
            case 'rename':
                $result = Olama_Users_Roles::rename($key, $label);
                $success = __('Role name updated.', 'olama-users');
                $tab = 'available';
                break;
            case 'delete':
                $result = Olama_Users_Roles::delete($key);
                $success = __('Role deleted. Assigned users were moved to Subscriber. Any matching import default was cleared.', 'olama-users');
                $tab = 'available';
                break;
            default:
                $result = new WP_Error('invalid_role_operation', __('Unknown role operation.', 'olama-users'));
                $success = '';
                $tab = 'available';
        }

        if (is_wp_error($result)) {
            set_transient('olama_users_role_notice_' . get_current_user_id(), array('type' => 'error', 'message' => $result->get_error_message()), 5 * MINUTE_IN_SECONDS);
            Olama_Users_DB::audit('role_operation_failed', 0, 'local_system', sanitize_key($key), 'failed', array('operation' => $operation, 'code' => $result->get_error_code()));
        } else {
            set_transient('olama_users_role_notice_' . get_current_user_id(), array('type' => 'success', 'message' => $success), 5 * MINUTE_IN_SECONDS);
            $audit_key = isset($result['key']) ? $result['key'] : sanitize_key($key);
            Olama_Users_DB::audit('role_' . $operation . 'd', 0, 'local_system', $audit_key, 'success', $result);
        }
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users-roles', 'tab' => $tab), admin_url('admin.php')));
        exit;
    }

    public function handle_assign_role() {
        if (!current_user_can('olama_users_roles_manage')) {
            wp_die(esc_html__('You are not allowed to assign roles.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_assign_role');
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $role_key = isset($_POST['role_key']) ? sanitize_key(wp_unslash($_POST['role_key'])) : '';
        $return_role = isset($_POST['return_role']) ? sanitize_key(wp_unslash($_POST['return_role'])) : 'subscriber';
        $result = Olama_Users_Roles::assign($user_id, $role_key);
        if (is_wp_error($result)) {
            $notice = array('type' => 'error', 'message' => $result->get_error_message());
            Olama_Users_DB::audit('role_assignment_failed', $user_id, 'local_system', $role_key, 'failed', array('code' => $result->get_error_code()));
        } else {
            $notice = array('type' => 'success', 'message' => __('User role assigned.', 'olama-users'));
            Olama_Users_DB::audit('role_assigned', $user_id, 'local_system', $role_key, 'success', $result);
        }
        set_transient('olama_users_role_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users-roles', 'tab' => 'users', 'role' => $return_role), admin_url('admin.php')));
        exit;
    }

    public function handle_settings() {
        if (!current_user_can('olama_users_settings_manage')) {
            wp_die(esc_html__('You are not allowed to manage OLAMA Users settings.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_save_settings');
        $family_prefix = $this->sanitize_password_prefix(isset($_POST['family_password_prefix']) ? wp_unslash($_POST['family_password_prefix']) : '');
        $employee_prefix = $this->sanitize_password_prefix(isset($_POST['employee_password_prefix']) ? wp_unslash($_POST['employee_password_prefix']) : '');
        $family_role = isset($_POST['family_default_role']) ? sanitize_key(wp_unslash($_POST['family_default_role'])) : '';
        $employee_role = isset($_POST['employee_default_role']) ? sanitize_key(wp_unslash($_POST['employee_default_role'])) : '';
        if (
            !$family_role ||
            !$employee_role ||
            'administrator' === $family_role ||
            'administrator' === $employee_role ||
            !get_role($family_role) ||
            !get_role($employee_role) ||
            !in_array($family_role, Olama_Users_Roles::approved_keys(), true) ||
            !in_array($employee_role, Olama_Users_Roles::approved_keys(), true)
        ) {
            wp_safe_redirect(add_query_arg(array('page' => 'olama-users-settings', 'settings_error' => 'roles_required'), admin_url('admin.php')));
            exit;
        }
        Olama_Users_Roles::set_default_role('family', $family_role);
        Olama_Users_Roles::set_default_role('employee', $employee_role);
        update_option(Olama_Users_Sync::FAMILY_PASSWORD_PREFIX_OPTION, $family_prefix, false);
        update_option(Olama_Users_Sync::EMPLOYEE_PASSWORD_PREFIX_OPTION, $employee_prefix, false);
        Olama_Users_DB::audit('user_import_settings_updated', 0, 'local_system', 'family_and_employee', 'success', array(
            'family_default_role' => $family_role,
            'employee_default_role' => $employee_role,
            'family_prefix_length' => strlen($family_prefix),
            'employee_prefix_length' => strlen($employee_prefix),
        ));
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users-settings', 'updated' => 1), admin_url('admin.php')));
        exit;
    }

    private function sanitize_password_prefix($value) {
        $value = trim(sanitize_text_field((string) $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, 32) : substr($value, 0, 32);
    }

    public function accounts() {
        $this->authorize('olama_users_accounts_view');
        $result = get_transient('olama_users_sync_' . get_current_user_id());
        if (false !== $result) {
            delete_transient('olama_users_sync_' . get_current_user_id());
        }
        global $wpdb;
        // WordPress users are the source of truth for accounts that currently
        // exist. An identity row can outlive its user when an account is
        // deleted outside this plugin, so do not include orphaned mappings in
        // the dashboard totals.
        $counts = $wpdb->get_results(
            'SELECT identities.identity_type, identities.account_status, COUNT(DISTINCT identities.wp_user_id) AS total
            FROM `' . esc_sql(Olama_Users_DB::identities_table()) . '` AS identities
            INNER JOIN `' . esc_sql($wpdb->users) . '` AS users ON users.ID = identities.wp_user_id
            GROUP BY identities.identity_type, identities.account_status',
            ARRAY_A
        );
        echo '<div class="wrap olama-users-wrap"><div class="olama-users-hero"><div><span class="olama-eyebrow">' . esc_html__('Account centre', 'olama-users') . '</span><h1>' . esc_html__('OLAMA Users', 'olama-users') . '</h1><p>' . esc_html__('Create and maintain family and employee WordPress accounts from approved OLAMA sources.', 'olama-users') . '</p></div><span class="dashicons dashicons-groups"></span></div>';
        $default_roles_ready = Olama_Users_Roles::default_roles_ready();
        if (!$default_roles_ready) {
            echo '<div class="notice notice-warning inline"><p>' .
                esc_html__('Apply synchronization is locked until valid default roles are selected for both families and employees.', 'olama-users') .
                ' <a href="' . esc_url(add_query_arg('page', 'olama-users-settings', admin_url('admin.php'))) . '">' .
                esc_html__('Configure default roles', 'olama-users') .
                '</a></p></div>';
        }
        echo '<div class="olama-users-cards">';
        foreach (array('family' => __('Families', 'olama-users'), 'employee' => __('Employees', 'olama-users')) as $type => $label) {
            $total = 0;
            foreach ($counts as $count) {
                if ($type === $count['identity_type']) {
                    $total += (int) $count['total'];
                }
            }
            echo '<section class="olama-users-card olama-users-card-' . esc_attr($type) . '"><div class="olama-card-top"><h2>' . esc_html($label) . '</h2><span class="dashicons ' . esc_attr('family' === $type ? 'dashicons-admin-home' : 'dashicons-id') . '"></span></div><strong>' . esc_html(number_format_i18n($total)) . '</strong><p>' . esc_html__('Mapped WordPress accounts', 'olama-users') . '</p><div class="olama-card-actions">';
            if (current_user_can('olama_users_sync_preview')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_sync"><input type="hidden" name="identity_type" value="' . esc_attr($type) . '">';
                wp_nonce_field('olama_users_sync');
                echo '<button class="button" name="sync_mode" value="preview">' . esc_html__('Preview', 'olama-users') . '</button> ';
                if (current_user_can('olama_users_sync_apply') && $default_roles_ready) {
                    echo '<button class="button button-primary" name="sync_mode" value="apply">' . esc_html__('Apply', 'olama-users') . '</button>';
                }
                echo '</form>';
            }
            echo '</div></section>';
        }
        echo '</div>';
        if (false !== $result) {
            $this->render_sync_result($result);
        }
        echo '</div>';
    }

    private function render_sync_result($result) {
        $is_preview = !isset($_GET['mode']) || 'preview' === $_GET['mode'];
        echo '<section class="olama-users-panel"><div class="olama-panel-heading"><div><span class="olama-eyebrow">' . esc_html__('Latest run', 'olama-users') . '</span><h2>' . esc_html__('Synchronization status', 'olama-users') . '</h2></div><span class="olama-sync-mode">' . esc_html($is_preview ? __('Preview mode', 'olama-users') : __('Applied', 'olama-users')) . '</span></div>';
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($result->get_error_message()) . '</p></div></section>';
            return;
        }
        $type = isset($_GET['type']) && 'employee' === $_GET['type'] ? 'employee' : 'family';
        if ($is_preview && current_user_can('olama_users_sync_apply') && ($result['create'] || $result['update'])) {
            echo '<div class="olama-sync-batch-actions"><strong>' . esc_html__('Batch actions', 'olama-users') . '</strong>';
            foreach (array('create' => __('Create users', 'olama-users'), 'update' => __('Update users', 'olama-users')) as $operation => $label) {
                if (!$result[$operation]) {
                    continue;
                }
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_sync_action"><input type="hidden" name="identity_type" value="' . esc_attr($type) . '"><input type="hidden" name="sync_operation" value="' . esc_attr($operation) . '">';
                wp_nonce_field('olama_users_sync_action');
                echo '<button class="button" type="submit">' . esc_html($label) . ' (' . esc_html(number_format_i18n((int) $result[$operation])) . ')</button></form>';
            }
            echo '</div>';
        }
        echo '<div class="olama-users-summary">';
        foreach (array('scanned', 'create', 'update', 'unchanged', 'suspend', 'conflict', 'invalid', 'failed') as $key) {
            if ('invalid' === $key && $result[$key]) {
                echo '<button type="button" class="olama-summary-item is-invalid" data-olama-modal-open="olama-invalid-users"><span>' . esc_html(ucfirst($key)) . '</span><strong>' . esc_html(number_format_i18n((int) $result[$key])) . '</strong><small>' . esc_html__('View details', 'olama-users') . '</small></button>';
            } else {
                echo '<div class="olama-summary-item"><span>' . esc_html(ucfirst($key)) . '</span><strong>' . esc_html(number_format_i18n((int) $result[$key])) . '</strong></div>';
            }
        }
        echo '</div><div class="olama-sync-table-wrap"><table class="widefat striped olama-sync-table"><thead><tr><th>' . esc_html__('Status', 'olama-users') . '</th><th>' . esc_html__('Username', 'olama-users') . '</th><th>' . esc_html__('Display name', 'olama-users') . '</th><th>' . esc_html__('Action', 'olama-users') . '</th></tr></thead><tbody>';
        foreach ($result['events'] as $event) {
            echo '<tr><td>' . esc_html(ucfirst($event['status'])) . '</td><td><code>' . esc_html(isset($event['username']) ? $event['username'] : '') . '</code></td><td><strong>' . esc_html(isset($event['display_name']) ? $event['display_name'] : '') . '</strong></td><td>';
            if ($is_preview && current_user_can('olama_users_sync_apply') && in_array($event['status'], array('create', 'update'), true)) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_sync_action"><input type="hidden" name="identity_type" value="' . esc_attr($type) . '"><input type="hidden" name="sync_operation" value="' . esc_attr($event['status']) . '"><input type="hidden" name="identifier" value="' . esc_attr($event['identifier']) . '">';
                wp_nonce_field('olama_users_sync_action');
                echo '<button class="button button-small" type="submit">' . esc_html('create' === $event['status'] ? __('Create user', 'olama-users') : __('Update user', 'olama-users')) . '</button></form>';
            } else {
                echo '&mdash;';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($result['invalid']) {
            echo '<div id="olama-invalid-users" class="olama-modal" aria-hidden="true"><div class="olama-modal-backdrop" data-olama-modal-close></div><div class="olama-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="olama-invalid-users-title"><div class="olama-modal-heading"><h2 id="olama-invalid-users-title">' . esc_html__('Invalid users', 'olama-users') . '</h2><button type="button" class="olama-modal-close" data-olama-modal-close aria-label="' . esc_attr__('Close', 'olama-users') . '">&times;</button></div><div class="olama-invalid-list">';
            foreach ($result['events'] as $event) {
                if ('invalid' !== $event['status']) {
                    continue;
                }
                echo '<article><strong>' . esc_html(isset($event['username']) ? $event['username'] : $event['identifier']) . '</strong><span>' . esc_html(isset($event['display_name']) ? $event['display_name'] : '') . '</span><p>' . esc_html(isset($event['message']) ? $event['message'] : '') . '</p></article>';
            }
            echo '</div></div></div>';
        }
        echo '</section>';
    }

    public function roles() {
        $this->authorize('olama_users_roles_manage');
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'available';
        if (!in_array($tab, array('available', 'add', 'users'), true)) {
            $tab = 'available';
        }
        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('OLAMA Roles', 'olama-users') . '</h1><p>' . esc_html__('Review and manage roles. Administrator and required system roles are protected.', 'olama-users') . '</p>';
        $this->render_role_notice();
        $tabs = array(
            'available' => __('Available Roles', 'olama-users'),
            'add' => __('Add or Duplicate', 'olama-users'),
            'users' => __('Role Users', 'olama-users'),
        );
        echo '<nav class="nav-tab-wrapper olama-role-tabs">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => 'olama-users-roles', 'tab' => $key), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($key === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '<a class="nav-tab" href="' . esc_url(add_query_arg('page', 'olama-users-audit', admin_url('admin.php'))) . '">' . esc_html__('Audit Log', 'olama-users') . '</a></nav>';
        if ('add' === $tab) {
            $this->render_add_role();
        } elseif ('users' === $tab) {
            $this->render_role_users();
        } else {
            $this->render_available_roles();
        }
        echo '</div>';
    }

    private function render_role_notice() {
        $key = 'olama_users_role_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (false === $notice || !is_array($notice)) {
            return;
        }
        delete_transient($key);
        $class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    private function render_available_roles() {
        $roles = Olama_Users_Roles::all();
        echo '<section class="olama-users-panel olama-roles-panel"><div class="olama-panel-heading"><div><h2>' . esc_html__('Available Roles', 'olama-users') . '</h2><p>' . esc_html__('Custom roles can be renamed or deleted. Deleted-role users move to Subscriber, and matching import defaults are cleared.', 'olama-users') . '</p></div><a class="button button-primary" href="' . esc_url(add_query_arg(array('page' => 'olama-users-roles', 'tab' => 'add'), admin_url('admin.php'))) . '">' . esc_html__('Add role', 'olama-users') . '</a></div>';
        echo '<div class="olama-role-table-wrap"><table class="widefat striped olama-role-table"><thead><tr><th>' . esc_html__('Role', 'olama-users') . '</th><th>' . esc_html__('Permanent key', 'olama-users') . '</th><th>' . esc_html__('Owner', 'olama-users') . '</th><th>' . esc_html__('Users', 'olama-users') . '</th><th>' . esc_html__('Capabilities', 'olama-users') . '</th><th>' . esc_html__('Management', 'olama-users') . '</th></tr></thead><tbody>';
        foreach ($roles as $role) {
            echo '<tr><td><strong>' . esc_html($role['label']) . '</strong>';
            if ($role['protected']) {
                echo ' <span class="olama-role-badge is-protected">' . esc_html__('Protected', 'olama-users') . '</span>';
            }
            if (!empty($role['dependencies'])) {
                echo '<span class="olama-role-dependencies">' . esc_html(implode(' · ', $role['dependencies'])) . '</span>';
            }
            echo '</td><td><code>' . esc_html($role['key']) . '</code></td><td>' . esc_html($this->role_source_label($role['source'])) . '</td><td><a href="' . esc_url(add_query_arg(array('page' => 'olama-users-roles', 'tab' => 'users', 'role' => $role['key']), admin_url('admin.php'))) . '">' . esc_html(number_format_i18n($role['users'])) . '</a></td><td>' . esc_html(number_format_i18n($role['capabilities'])) . '</td><td>';
            if (!$role['editable']) {
                echo '<span class="description">' . esc_html__('Read only', 'olama-users') . '</span>';
            } else {
                echo '<form class="olama-inline-role-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_role_action"><input type="hidden" name="role_operation" value="rename"><input type="hidden" name="role_key" value="' . esc_attr($role['key']) . '">';
                wp_nonce_field('olama_users_role_action');
                echo '<input type="text" name="role_label" value="' . esc_attr($role['label']) . '" maxlength="100" aria-label="' . esc_attr__('Role name', 'olama-users') . '"><button class="button">' . esc_html__('Rename', 'olama-users') . '</button></form>';
                echo '<form class="olama-inline-role-form is-delete" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr(wp_json_encode(__('Delete this role? Its users will move to Subscriber, and any matching import default will be cleared.', 'olama-users'))) . ');"><input type="hidden" name="action" value="olama_users_role_action"><input type="hidden" name="role_operation" value="delete"><input type="hidden" name="role_key" value="' . esc_attr($role['key']) . '">';
                wp_nonce_field('olama_users_role_action');
                echo '<button class="button button-link-delete">' . esc_html__('Delete', 'olama-users') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function render_add_role() {
        $roles = Olama_Users_Roles::editable();
        echo '<div class="olama-users-cards olama-role-create-grid"><section class="olama-users-card"><h2>' . esc_html__('Create a blank role', 'olama-users') . '</h2><p>' . esc_html__('The new role starts with login access only. Plugin access is assigned later in the Access Matrix.', 'olama-users') . '</p>';
        $this->role_form('create');
        echo '</section><section class="olama-users-card"><h2>' . esc_html__('Duplicate a role', 'olama-users') . '</h2><p>' . esc_html__('Copy any non-Administrator role as a starting point. Administrator and user-management permissions are never copied.', 'olama-users') . '</p>';
        $this->role_form('duplicate', $roles);
        echo '</section></div>';
    }

    private function role_form($operation, array $sources = array()) {
        echo '<form class="olama-role-create-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_role_action"><input type="hidden" name="role_operation" value="' . esc_attr($operation) . '">';
        wp_nonce_field('olama_users_role_action');
        if ('duplicate' === $operation) {
            echo '<label><span>' . esc_html__('Source role', 'olama-users') . '</span><select name="source_role" required><option value="">' . esc_html__('Select a role', 'olama-users') . '</option>';
            foreach ($sources as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<label><span>' . esc_html__('Role name', 'olama-users') . '</span><input type="text" name="role_label" maxlength="100" required placeholder="' . esc_attr__('Admissions Officer', 'olama-users') . '"></label><label><span>' . esc_html__('Permanent key', 'olama-users') . '</span><input type="text" name="role_key" maxlength="60" required placeholder="' . esc_attr__('admissions_officer', 'olama-users') . '"><small>' . esc_html__('OLAMA adds the olama_ prefix. The key cannot be changed later.', 'olama-users') . '</small></label><button class="button button-primary">' . ('duplicate' === $operation ? esc_html__('Duplicate role', 'olama-users') : esc_html__('Create role', 'olama-users')) . '</button></form>';
    }

    private function render_role_users() {
        $roles = Olama_Users_Roles::all();
        $selected = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        if ('unassigned' !== $selected && (!$selected || !isset($roles[$selected]))) {
            $selected = $roles ? (string) array_key_first($roles) : '';
        }
        echo '<section class="olama-users-panel"><h2>' . esc_html__('Role Users', 'olama-users') . '</h2><form class="olama-role-filter" method="get"><input type="hidden" name="page" value="olama-users-roles"><input type="hidden" name="tab" value="users"><label for="olama-role-filter">' . esc_html__('Choose role', 'olama-users') . '</label><select id="olama-role-filter" name="role">';
        foreach ($roles as $key => $role) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($role['label'] . ' (' . number_format_i18n($role['users']) . ')') . '</option>';
        }
        echo '<option value="unassigned" ' . selected($selected, 'unassigned', false) . '>' . esc_html__('Unassigned users', 'olama-users') . '</option>';
        echo '</select><button class="button">' . esc_html__('Review', 'olama-users') . '</button></form>';
        if (!$selected) {
            echo '</section>';
            return;
        }
        $query = array('orderby' => 'display_name', 'order' => 'ASC', 'number' => 200);
        if ('unassigned' === $selected) {
            $query['role__not_in'] = array_keys($roles);
        } else {
            $query['role'] = $selected;
        }
        $users = get_users($query);
        $assignable_roles = Olama_Users_Roles::editable();
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('User', 'olama-users') . '</th><th>' . esc_html__('Username', 'olama-users') . '</th><th>' . esc_html__('Email', 'olama-users') . '</th><th>' . esc_html__('Account status', 'olama-users') . '</th><th>' . esc_html__('Assign role', 'olama-users') . '</th></tr></thead><tbody>';
        if (!$users) {
            echo '<tr><td colspan="5">' . esc_html__('No users currently have this role.', 'olama-users') . '</td></tr>';
        }
        foreach ($users as $user) {
            $status = get_user_meta($user->ID, 'olama_account_status', true);
            echo '<tr><td>' . esc_html($user->display_name) . '</td><td><code>' . esc_html($user->user_login) . '</code></td><td>' . esc_html($user->user_email) . '</td><td>' . esc_html($status ?: __('Not managed by OLAMA', 'olama-users')) . '</td><td>';
            if (in_array('administrator', (array) $user->roles, true)) {
                echo '<span class="description">' . esc_html__('Protected Administrator', 'olama-users') . '</span>';
            } else {
                echo '<form class="olama-inline-role-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_assign_role"><input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '"><input type="hidden" name="return_role" value="' . esc_attr($selected) . '"><select name="role_key" aria-label="' . esc_attr__('Assign role', 'olama-users') . '">';
                foreach ($assignable_roles as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected(in_array($key, (array) $user->roles, true), true, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
                wp_nonce_field('olama_users_assign_role');
                echo '<button class="button button-primary">' . esc_html__('Assign', 'olama-users') . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (count($users) >= 200) {
            echo '<p class="description">' . esc_html__('Showing the first 200 users.', 'olama-users') . '</p>';
        }
        echo '</section>';
    }

    private function role_source_label($source) {
        $labels = array(
            'seeded' => __('OLAMA standard', 'olama-users'),
            'custom' => __('OLAMA custom', 'olama-users'),
            'duplicated' => __('OLAMA duplicate', 'olama-users'),
            'olama' => __('OLAMA', 'olama-users'),
            'wordpress' => __('WordPress', 'olama-users'),
            'external' => __('Plugin or legacy', 'olama-users'),
        );
        return isset($labels[$source]) ? $labels[$source] : $source;
    }

    public function temp_families() {
        $this->authorize('olama_users_temp_families_manage');
        $accounts = Olama_Users_Temp_Families::all();
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $editing = $edit_id ? Olama_Users_Temp_Families::get($edit_id) : null;
        $notice_key = 'olama_users_temp_family_notice_' . get_current_user_id();
        $notice = get_transient($notice_key);
        if (false !== $notice) {
            delete_transient($notice_key);
        }

        echo '<div class="wrap olama-users-wrap"><div class="olama-panel-heading"><div><span class="olama-eyebrow">' . esc_html__('Local access', 'olama-users') . '</span><h1>' . esc_html__('Temp Families', 'olama-users') . '</h1><p>' . esc_html__('Create local family members and choose their grades and sections. No existing Core student is assigned.', 'olama-users') . '</p></div>';
        if ($editing) {
            echo '<a class="button" href="' . esc_url(add_query_arg('page', 'olama-users-temp-families', admin_url('admin.php'))) . '">' . esc_html__('Create another account', 'olama-users') . '</a>';
        }
        echo '</div>';
        if (is_array($notice)) {
            echo '<div class="notice ' . esc_attr('error' === $notice['type'] ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        $this->render_temp_family_form($editing);
        echo '<section class="olama-users-panel olama-temp-list"><h2>' . esc_html__('Temporary accounts', 'olama-users') . '</h2><div class="olama-role-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Account', 'olama-users') . '</th><th>' . esc_html__('Family members', 'olama-users') . '</th><th>' . esc_html__('Expiry', 'olama-users') . '</th><th>' . esc_html__('Status', 'olama-users') . '</th><th>' . esc_html__('Actions', 'olama-users') . '</th></tr></thead><tbody>';
        if (!$accounts) {
            echo '<tr><td colspan="5">' . esc_html__('No Temp Family accounts have been created.', 'olama-users') . '</td></tr>';
        }
        foreach ($accounts as $account) {
            $user = $account['user'];
            $expired = Olama_Users_Temp_Families::is_expired($user->ID);
            $active = 'active' === $account['identity']['account_status'] && !$expired;
            echo '<tr><td><strong>' . esc_html($user->display_name) . '</strong><br><code>' . esc_html($user->user_login) . '</code>';
            if ($user->user_email) {
                echo '<br><small>' . esc_html($user->user_email) . '</small>';
            }
            echo '</td><td>';
            foreach ($account['members'] as $member) {
                echo '<span class="olama-temp-member-summary"><strong>' . esc_html($member['student_name']) . '</strong> <small>' . esc_html($member['academic']['class_name'] . ' · ' . $member['academic']['section_name']) . '</small></span>';
            }
            echo '</td><td>' . esc_html($account['expires_on'] ?: __('No expiry', 'olama-users')) . '</td><td><span class="olama-temp-status ' . esc_attr($active ? 'is-active' : 'is-inactive') . '">' . esc_html($expired ? __('Expired', 'olama-users') : ('active' === $account['identity']['account_status'] ? __('Active', 'olama-users') : __('Deactivated', 'olama-users'))) . '</span></td><td><div class="olama-temp-actions"><a class="button button-small" href="' . esc_url(add_query_arg(array('page' => 'olama-users-temp-families', 'edit' => $user->ID), admin_url('admin.php'))) . '">' . esc_html__('Edit', 'olama-users') . '</a>';
            $next_operation = 'active' === $account['identity']['account_status'] ? 'deactivate' : 'activate';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_temp_family"><input type="hidden" name="operation" value="' . esc_attr($next_operation) . '"><input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
            wp_nonce_field('olama_users_temp_family');
            echo '<button class="button button-small ' . ('deactivate' === $next_operation ? 'button-link-delete' : '') . '">' . esc_html('deactivate' === $next_operation ? __('Deactivate', 'olama-users') : __('Activate', 'olama-users')) . '</button></form></div></td></tr>';
        }
        echo '</tbody></table></div></section></div>';
    }

    private function render_temp_family_form($account) {
        $editing = is_array($account);
        $user = $editing ? $account['user'] : null;
        $members = $editing ? $account['members'] : array(array());
        $grades = class_exists('Olama_School_Grade') ? (array) Olama_School_Grade::get_grades() : array();
        $sections = class_exists('Olama_School_Section') ? (array) Olama_School_Section::get_sections() : array();
        echo '<section class="olama-users-panel olama-temp-editor"><h2>' . esc_html($editing ? __('Edit Temp Family', 'olama-users') : __('Create Temp Family', 'olama-users')) . '</h2>';
        echo '<form class="olama-temp-family-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_temp_family"><input type="hidden" name="operation" value="' . esc_attr($editing ? 'update' : 'create') . '">';
        if ($editing) {
            echo '<input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
        }
        wp_nonce_field('olama_users_temp_family');
        echo '<div class="olama-temp-grid"><label><span>' . esc_html__('Display name', 'olama-users') . '</span><input type="text" name="display_name" required maxlength="100" value="' . esc_attr($editing ? $user->display_name : '') . '"></label>';
        echo '<label><span>' . esc_html__('Username', 'olama-users') . '</span><input type="text" name="user_login" ' . ($editing ? 'disabled' : 'required') . ' maxlength="60" value="' . esc_attr($editing ? $user->user_login : 'tf_') . '"><small>' . esc_html__('Local usernames use the tf_ prefix.', 'olama-users') . '</small></label>';
        echo '<label><span>' . esc_html__('Email (optional)', 'olama-users') . '</span><input type="email" name="user_email" value="' . esc_attr($editing ? $user->user_email : '') . '"></label>';
        echo '<label><span>' . esc_html__('Valid through (optional)', 'olama-users') . '</span><input type="date" name="expires_on" value="' . esc_attr($editing ? $account['expires_on'] : '') . '"><small>' . esc_html__('Access expires after this date.', 'olama-users') . '</small></label>';
        if (!$editing) {
            echo '<label><span>' . esc_html__('Initial password', 'olama-users') . '</span><input type="password" name="password" required minlength="12" autocomplete="new-password"><small>' . esc_html__('At least 12 characters. Copy it before creating the account; it is never displayed or stored as plain text.', 'olama-users') . '</small></label>';
        }
        echo '<label class="is-wide"><span>' . esc_html__('Internal notes (optional)', 'olama-users') . '</span><textarea name="notes" rows="3">' . esc_textarea($editing ? $account['notes'] : '') . '</textarea></label></div>';
        echo '<div class="olama-temp-members" data-temp-members><div class="olama-panel-heading"><div><h3>' . esc_html__('Family members', 'olama-users') . '</h3><p>' . esc_html__('Define each local member and select the grade and section whose portal content they may view.', 'olama-users') . '</p></div><button type="button" class="button" data-add-member>' . esc_html__('Add member', 'olama-users') . '</button></div><div data-member-list>';
        foreach ($members as $index => $member) {
            $this->render_temp_member_row($index, $member, $grades, $sections);
        }
        echo '</div><template data-member-template>';
        $this->render_temp_member_row('__INDEX__', array(), $grades, $sections);
        echo '</template></div><p><button class="button button-primary">' . esc_html($editing ? __('Save changes', 'olama-users') : __('Create Temp Family', 'olama-users')) . '</button></p></form>';
        if ($editing) {
            echo '<hr><form class="olama-temp-password-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_temp_family"><input type="hidden" name="operation" value="reset_password"><input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
            wp_nonce_field('olama_users_temp_family');
            echo '<label><span>' . esc_html__('New password', 'olama-users') . '</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label><button class="button">' . esc_html__('Reset password and sign out sessions', 'olama-users') . '</button></form>';
        }
        echo '</section>';
    }

    private function render_temp_member_row($index, array $member, array $grades, array $sections) {
        $academic = isset($member['academic']) && is_array($member['academic']) ? $member['academic'] : array();
        $uid = isset($member['student_uid']) ? $member['student_uid'] : '';
        $name = isset($member['student_name']) ? $member['student_name'] : '';
        $grade_id = isset($academic['school_grade_id']) ? absint($academic['school_grade_id']) : 0;
        $section_id = isset($academic['school_section_id']) ? absint($academic['school_section_id']) : 0;
        $prefix = 'members[' . $index . ']';
        echo '<div class="olama-temp-member" data-member-row><input type="hidden" name="' . esc_attr($prefix . '[uid]') . '" value="' . esc_attr($uid) . '">';
        echo '<label><span>' . esc_html__('Member name', 'olama-users') . '</span><input type="text" name="' . esc_attr($prefix . '[name]') . '" value="' . esc_attr($name) . '" required maxlength="190"></label>';
        echo '<label><span>' . esc_html__('Grade', 'olama-users') . '</span><select name="' . esc_attr($prefix . '[grade_id]') . '" data-member-grade required><option value="">' . esc_html__('Select grade', 'olama-users') . '</option>';
        foreach ($grades as $grade) {
            echo '<option value="' . esc_attr($grade->id) . '" ' . selected($grade_id, absint($grade->id), false) . '>' . esc_html($grade->grade_name) . '</option>';
        }
        echo '</select></label><label><span>' . esc_html__('Section', 'olama-users') . '</span><select name="' . esc_attr($prefix . '[section_id]') . '" data-member-section required><option value="">' . esc_html__('Select section', 'olama-users') . '</option>';
        foreach ($sections as $section) {
            echo '<option value="' . esc_attr($section->id) . '" data-grade-id="' . esc_attr($section->grade_id) . '" ' . selected($section_id, absint($section->id), false) . '>' . esc_html($section->section_name) . '</option>';
        }
        echo '</select></label><button type="button" class="button button-link-delete" data-remove-member>' . esc_html__('Remove', 'olama-users') . '</button></div>';
    }

    public function matrix() {
        $this->authorize('olama_users_matrix_manage');
        $roles = Olama_Users_Roles::editable();
        $modules = Olama_Users_Registry::all();
        $role_key = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        if (!$role_key || !isset($roles[$role_key])) {
            $role_key = $roles ? (string) array_key_first($roles) : '';
        }
        $plugin_id = isset($_GET['plugin']) ? sanitize_key(wp_unslash($_GET['plugin'])) : '';
        if (!$plugin_id || !isset($modules[$plugin_id])) {
            $plugin_id = $modules ? (string) array_key_first($modules) : '';
        }

        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('Role Capabilities', 'olama-users') . '</h1><p>' . esc_html__('Select one role, then choose a plugin and the functionality that role may use.', 'olama-users') . '</p>';
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Role capabilities saved.', 'olama-users') . '</p></div>';
        }
        if (!$roles || !$modules) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('No OLAMA plugins have declared functionality yet.', 'olama-users') . '</p></div></div>';
            return;
        }

        echo '<section class="olama-users-panel olama-cap-role-picker"><form method="get"><input type="hidden" name="page" value="olama-users-matrix"><input type="hidden" name="plugin" value="' . esc_attr($plugin_id) . '"><label for="olama-cap-role"><strong>' . esc_html__('Role', 'olama-users') . '</strong></label><select id="olama-cap-role" name="role">';
        foreach ($roles as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($role_key, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><button class="button">' . esc_html__('Select role', 'olama-users') . '</button><span class="description">' . esc_html__('Administrator always has every declared capability.', 'olama-users') . '</span></form></section>';

        echo '<div class="olama-capability-editor"><aside class="olama-plugin-list"><h2>' . esc_html__('Plugins', 'olama-users') . '</h2>';
        foreach ($modules as $id => $module) {
            $url = add_query_arg(array('page' => 'olama-users-matrix', 'role' => $role_key, 'plugin' => $id), admin_url('admin.php'));
            echo '<a class="' . ($id === $plugin_id ? 'is-active' : '') . '" href="' . esc_url($url) . '"><span class="dashicons dashicons-admin-plugins"></span><span>' . esc_html($module['label']) . '</span></a>';
        }
        echo '</aside>';

        $module = $modules[$plugin_id];
        $registered = Olama_Users_Registry::module_capabilities($plugin_id);
        $granted = 0;
        foreach (array_keys($registered) as $capability) {
            if (Olama_Users_Roles::role_has_declared_capability($role_key, $capability)) {
                $granted++;
            }
        }
        echo '<section class="olama-capability-panel"><div class="olama-panel-heading"><div><h2>' . esc_html($module['label']) . '</h2><p>' . esc_html(sprintf(__('%1$s of %2$s capabilities granted to %3$s.', 'olama-users'), number_format_i18n($granted), number_format_i18n(count($registered)), $roles[$role_key])) . '</p></div></div>';
        echo '<form class="olama-capability-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_save_matrix"><input type="hidden" name="role_key" value="' . esc_attr($role_key) . '"><input type="hidden" name="plugin_id" value="' . esc_attr($plugin_id) . '">';
        wp_nonce_field('olama_users_save_matrix');
        echo '<table class="widefat striped olama-capability-table"><thead><tr><th>' . esc_html__('Functionality', 'olama-users') . '</th><th><label class="olama-select-all-label"><input type="checkbox" class="olama-cap-select-all"><span>' . esc_html__('Select all', 'olama-users') . '</span></label></th></tr></thead><tbody>';
        $this->capability_row($module['label'], $module['capability'], $role_key, 0, 'plugin');
        foreach ($module['items'] as $item) {
            $this->capability_item($item, $role_key, 1);
        }
        echo '</tbody></table><p><button class="button button-primary">' . esc_html__('Save capabilities', 'olama-users') . '</button></p></form></section></div></div>';
    }

    public function settings() {
        $this->authorize('olama_users_settings_manage');
        $family_prefix = Olama_Users_Sync::password_prefix('family');
        $employee_prefix = Olama_Users_Sync::password_prefix('employee');
        $family_role = Olama_Users_Roles::default_role('family');
        $employee_role = Olama_Users_Roles::default_role('employee');
        $roles = Olama_Users_Roles::all();
        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('OLAMA Users Settings', 'olama-users') . '</h1><p>' . esc_html__('Choose the roles and password formulas used when family and employee accounts are synchronized.', 'olama-users') . '</p>';
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Import roles and password patterns saved.', 'olama-users') . '</p></div>';
        }
        if (isset($_GET['settings_error']) && 'roles_required' === $_GET['settings_error']) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Select valid default roles for both families and employees. Settings were not changed.', 'olama-users') . '</p></div>';
        }
        echo '<section class="olama-users-panel"><h2>' . esc_html__('Default roles for imported users', 'olama-users') . '</h2>';
        echo '<p>' . esc_html__('Both roles are required. Apply synchronization remains locked until these selections are saved.', 'olama-users') . '</p>';
        echo '<form class="olama-role-create-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_save_settings">';
        wp_nonce_field('olama_users_save_settings');
        foreach (array(
            'family' => array('label' => __('Family default role', 'olama-users'), 'selected' => $family_role, 'name' => 'family_default_role'),
            'employee' => array('label' => __('Employee default role', 'olama-users'), 'selected' => $employee_role, 'name' => 'employee_default_role'),
        ) as $role_setting) {
            echo '<label><span>' . esc_html($role_setting['label']) . '</span><select name="' . esc_attr($role_setting['name']) . '" required>';
            echo '<option value="">' . esc_html__('Select a role', 'olama-users') . '</option>';
            foreach ($roles as $role_key => $role) {
                if ('administrator' === $role_key || !in_array($role_key, Olama_Users_Roles::approved_keys(), true)) {
                    continue;
                }
                echo '<option value="' . esc_attr($role_key) . '"' . selected($role_setting['selected'], $role_key, false) . '>' . esc_html($role['label']) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<hr><h2>' . esc_html__('Default password patterns', 'olama-users') . '</h2>';
        echo '<p>' . esc_html__('The prefix is optional. Leave it empty to use only the source value.', 'olama-users') . '</p>';
        echo '<label><span>' . esc_html__('Family password prefix', 'olama-users') . '</span><input type="text" name="family_password_prefix" value="' . esc_attr($family_prefix) . '" maxlength="32" autocomplete="off"><small>' . esc_html__('Formula: prefix + mother mobile number. Example with prefix OLAMA@: OLAMA@0791234567', 'olama-users') . '</small></label>';
        echo '<label><span>' . esc_html__('Employee password prefix', 'olama-users') . '</span><input type="text" name="employee_password_prefix" value="' . esc_attr($employee_prefix) . '" maxlength="32" autocomplete="off"><small>' . esc_html__('Formula: prefix + employee mobile number. Example with prefix EMP@: EMP@0791234567', 'olama-users') . '</small></label>';
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('The next Apply synchronization updates existing matching accounts to the configured formula. Preview never changes passwords, and passwords are never shown in results or audit logs.', 'olama-users') . '</p></div>';
        echo '<p><button class="button button-primary">' . esc_html__('Save import settings', 'olama-users') . '</button></p></form></section></div>';
    }

    private function capability_item(array $item, $role_key, $depth) {
        if (!empty($item['capability'])) {
            $this->capability_row(
                isset($item['label']) ? $item['label'] : $item['capability'],
                $item['capability'],
                $role_key,
                $depth,
                isset($item['type']) ? $item['type'] : 'function'
            );
        }
        foreach (array('submenus', 'tabs', 'actions', 'items') as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) {
                continue;
            }
            foreach ($item[$key] as $child) {
                $this->capability_item($child, $role_key, $depth + 1);
            }
        }
    }

    private function capability_row($label, $capability, $role_key, $depth, $type) {
        $capability = sanitize_key($capability);
        if (!$capability) {
            return;
        }
        echo '<tr class="is-' . esc_attr($type) . '"><td style="padding-inline-start:' . esc_attr(16 + ($depth * 24)) . 'px"><span class="olama-cap-type">' . esc_html(ucfirst($type)) . '</span><strong>' . esc_html($label) . '</strong><code>' . esc_html($capability) . '</code></td><td><label><input type="checkbox" name="caps[]" value="' . esc_attr($capability) . '" data-capability="' . esc_attr($capability) . '" ' . checked(Olama_Users_Roles::role_has_declared_capability($role_key, $capability), true, false) . '><span class="screen-reader-text">' . esc_html(sprintf(__('Allow %s', 'olama-users'), $label)) . '</span></label></td></tr>';
    }

    public function audit() {
        $this->authorize('olama_users_audit_view');
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM `' . esc_sql(Olama_Users_DB::audit_table()) . '` ORDER BY id DESC LIMIT 200', ARRAY_A);
        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('OLAMA Users Audit Log', 'olama-users') . '</h1><table class="widefat striped"><thead><tr><th>Time</th><th>Event</th><th>Actor</th><th>Subject</th><th>Identity</th><th>Result</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['created_at']) . '</td><td>' . esc_html($row['event_type']) . '</td><td>' . esc_html($row['actor_wp_user_id']) . '</td><td>' . esc_html($row['subject_wp_user_id']) . '</td><td>' . esc_html($row['identity_type'] . ':' . $row['oracle_identifier']) . '</td><td>' . esc_html($row['result']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function authorize($capability) {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to access this page.', 'olama-users'), '', array('response' => 403));
        }
    }
}
