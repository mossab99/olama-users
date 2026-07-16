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
        add_action('admin_post_olama_users_save_matrix', array($this, 'handle_matrix'));
        add_action('admin_post_olama_users_role_action', array($this, 'handle_role_action'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }

    public function menus() {
        add_menu_page(__('OLAMA Users', 'olama-users'), __('OLAMA Users', 'olama-users'), 'olama_users_access', 'olama-users', array($this, 'accounts'), 'dashicons-admin-users', 27);
        add_submenu_page('olama-users', __('Accounts', 'olama-users'), __('Accounts', 'olama-users'), 'olama_users_accounts_view', 'olama-users', array($this, 'accounts'));
        add_submenu_page('olama-users', __('Roles', 'olama-users'), __('Roles', 'olama-users'), 'olama_users_roles_manage', 'olama-users-roles', array($this, 'roles'));
        add_submenu_page('olama-users', __('Access Matrix', 'olama-users'), __('Access Matrix', 'olama-users'), 'olama_users_matrix_manage', 'olama-users-matrix', array($this, 'matrix'));
        add_submenu_page('olama-users', __('Audit Log', 'olama-users'), __('Audit Log', 'olama-users'), 'olama_users_audit_view', 'olama-users-audit', array($this, 'audit'));
    }

    public function assets($hook) {
        if (false === strpos($hook, 'olama-users')) {
            return;
        }
        wp_enqueue_style('olama-users-admin', OLAMA_USERS_URL . 'assets/admin.css', array(), OLAMA_USERS_VERSION);
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
        $result = 'apply' === $mode ? $this->sync->apply($type) : $this->sync->preview($type);
        set_transient('olama_users_sync_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users', 'type' => $type, 'mode' => $mode), admin_url('admin.php')));
        exit;
    }

    public function handle_matrix() {
        if (!current_user_can('olama_users_matrix_manage')) {
            wp_die(esc_html__('You are not allowed to manage access.', 'olama-users'), '', array('response' => 403));
        }
        check_admin_referer('olama_users_save_matrix');
        $roles = Olama_Users_Roles::editable();
        $registered = array_keys(Olama_Users_Registry::capabilities());
        $submitted = isset($_POST['caps']) && is_array($_POST['caps']) ? wp_unslash($_POST['caps']) : array();
        foreach ($roles as $role_key => $label) {
            $role = get_role($role_key);
            if (!$role) {
                continue;
            }
            foreach ($registered as $capability) {
                if (!empty($submitted[$role_key][$capability])) {
                    $role->add_cap($capability);
                } else {
                    $role->remove_cap($capability);
                }
            }
        }
        Olama_Users_DB::audit('access_matrix_updated', 0, 'local_system', '', 'success', array('roles' => array_keys($roles), 'capabilities' => count($registered)));
        wp_safe_redirect(add_query_arg(array('page' => 'olama-users-matrix', 'updated' => 1), admin_url('admin.php')));
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
        $replacement = isset($_POST['replacement_role']) ? sanitize_key(wp_unslash($_POST['replacement_role'])) : '';

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
                $result = Olama_Users_Roles::delete($key, $replacement);
                $success = __('Role deleted and assigned users safely moved.', 'olama-users');
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

    public function accounts() {
        $this->authorize('olama_users_accounts_view');
        $result = get_transient('olama_users_sync_' . get_current_user_id());
        if (false !== $result) {
            delete_transient('olama_users_sync_' . get_current_user_id());
        }
        global $wpdb;
        $counts = $wpdb->get_results('SELECT identity_type, account_status, COUNT(*) AS total FROM `' . esc_sql(Olama_Users_DB::identities_table()) . '` GROUP BY identity_type, account_status', ARRAY_A);
        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('OLAMA Users', 'olama-users') . '</h1><p>' . esc_html__('Create and maintain family and employee WordPress accounts from approved OLAMA sources.', 'olama-users') . '</p>';
        echo '<div class="olama-users-cards">';
        foreach (array('family' => __('Families', 'olama-users'), 'employee' => __('Employees', 'olama-users')) as $type => $label) {
            $total = 0;
            foreach ($counts as $count) {
                if ($type === $count['identity_type']) {
                    $total += (int) $count['total'];
                }
            }
            echo '<section class="olama-users-card"><h2>' . esc_html($label) . '</h2><strong>' . esc_html(number_format_i18n($total)) . '</strong><p>' . esc_html__('Mapped WordPress accounts', 'olama-users') . '</p>';
            if (current_user_can('olama_users_sync_preview')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_sync"><input type="hidden" name="identity_type" value="' . esc_attr($type) . '">';
                wp_nonce_field('olama_users_sync');
                echo '<button class="button" name="sync_mode" value="preview">' . esc_html__('Preview', 'olama-users') . '</button> ';
                if (current_user_can('olama_users_sync_apply')) {
                    echo '<button class="button button-primary" name="sync_mode" value="apply">' . esc_html__('Apply', 'olama-users') . '</button>';
                }
                echo '</form>';
            }
            echo '</section>';
        }
        echo '</div>';
        if (false !== $result) {
            $this->render_sync_result($result);
        }
        echo '</div>';
    }

    private function render_sync_result($result) {
        echo '<section class="olama-users-panel"><h2>' . esc_html__('Synchronization result', 'olama-users') . '</h2>';
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($result->get_error_message()) . '</p></div></section>';
            return;
        }
        echo '<div class="olama-users-summary">';
        foreach (array('scanned', 'create', 'update', 'unchanged', 'suspend', 'conflict', 'invalid', 'failed') as $key) {
            echo '<div><span>' . esc_html(ucfirst($key)) . '</span><strong>' . esc_html(number_format_i18n((int) $result[$key])) . '</strong></div>';
        }
        echo '</div><table class="widefat striped"><thead><tr><th>' . esc_html__('Result', 'olama-users') . '</th><th>' . esc_html__('Identifier', 'olama-users') . '</th><th>' . esc_html__('Username', 'olama-users') . '</th><th>' . esc_html__('Message', 'olama-users') . '</th></tr></thead><tbody>';
        foreach ($result['events'] as $event) {
            echo '<tr><td>' . esc_html($event['status']) . '</td><td><code>' . esc_html($event['identifier']) . '</code></td><td><code>' . esc_html(isset($event['username']) ? $event['username'] : '') . '</code></td><td>' . esc_html($event['message']) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    public function roles() {
        $this->authorize('olama_users_roles_manage');
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'available';
        if (!in_array($tab, array('available', 'add', 'users'), true)) {
            $tab = 'available';
        }
        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('OLAMA Roles', 'olama-users') . '</h1><p>' . esc_html__('Review every WordPress role and safely manage the roles owned by OLAMA.', 'olama-users') . '</p>';
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
        $replacements = Olama_Users_Roles::editable();
        echo '<section class="olama-users-panel olama-roles-panel"><div class="olama-panel-heading"><div><h2>' . esc_html__('Available Roles', 'olama-users') . '</h2><p>' . esc_html__('External and WordPress roles are visible for reference. OLAMA protects roles required by account synchronization.', 'olama-users') . '</p></div><a class="button button-primary" href="' . esc_url(add_query_arg(array('page' => 'olama-users-roles', 'tab' => 'add'), admin_url('admin.php'))) . '">' . esc_html__('Add role', 'olama-users') . '</a></div>';
        echo '<div class="olama-role-table-wrap"><table class="widefat striped olama-role-table"><thead><tr><th>' . esc_html__('Role', 'olama-users') . '</th><th>' . esc_html__('Permanent key', 'olama-users') . '</th><th>' . esc_html__('Owner', 'olama-users') . '</th><th>' . esc_html__('Users', 'olama-users') . '</th><th>' . esc_html__('Capabilities', 'olama-users') . '</th><th>' . esc_html__('Management', 'olama-users') . '</th></tr></thead><tbody>';
        foreach ($roles as $role) {
            echo '<tr><td><strong>' . esc_html($role['label']) . '</strong>';
            if ($role['protected']) {
                echo ' <span class="olama-role-badge is-protected">' . esc_html__('Protected', 'olama-users') . '</span>';
            }
            echo '</td><td><code>' . esc_html($role['key']) . '</code></td><td>' . esc_html($this->role_source_label($role['source'])) . '</td><td><a href="' . esc_url(add_query_arg(array('page' => 'olama-users-roles', 'tab' => 'users', 'role' => $role['key']), admin_url('admin.php'))) . '">' . esc_html(number_format_i18n($role['users'])) . '</a></td><td>' . esc_html(number_format_i18n($role['capabilities'])) . '</td><td>';
            if (!$role['managed']) {
                echo '<span class="description">' . esc_html__('Read only', 'olama-users') . '</span>';
            } else {
                echo '<form class="olama-inline-role-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_role_action"><input type="hidden" name="role_operation" value="rename"><input type="hidden" name="role_key" value="' . esc_attr($role['key']) . '">';
                wp_nonce_field('olama_users_role_action');
                echo '<input type="text" name="role_label" value="' . esc_attr($role['label']) . '" maxlength="100" aria-label="' . esc_attr__('Role name', 'olama-users') . '"><button class="button">' . esc_html__('Rename', 'olama-users') . '</button></form>';
                if (!$role['protected']) {
                    echo '<form class="olama-inline-role-form is-delete" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr(wp_json_encode(__('Delete this role? Its users will be moved to the selected replacement role.', 'olama-users'))) . ');"><input type="hidden" name="action" value="olama_users_role_action"><input type="hidden" name="role_operation" value="delete"><input type="hidden" name="role_key" value="' . esc_attr($role['key']) . '">';
                    wp_nonce_field('olama_users_role_action');
                    if ($role['users'] > 0) {
                        echo '<select name="replacement_role" required aria-label="' . esc_attr__('Replacement role', 'olama-users') . '"><option value="">' . esc_html__('Move users to…', 'olama-users') . '</option>';
                        foreach ($replacements as $replacement_key => $replacement_label) {
                            if ($replacement_key === $role['key']) {
                                continue;
                            }
                            echo '<option value="' . esc_attr($replacement_key) . '">' . esc_html($replacement_label) . '</option>';
                        }
                        echo '</select>';
                    }
                    echo '<button class="button button-link-delete">' . esc_html__('Delete', 'olama-users') . '</button></form>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function render_add_role() {
        $roles = Olama_Users_Roles::editable();
        unset($roles['olama_users_administrator']);
        echo '<div class="olama-users-cards olama-role-create-grid"><section class="olama-users-card"><h2>' . esc_html__('Create a blank role', 'olama-users') . '</h2><p>' . esc_html__('The new role starts with login access only. Plugin access is assigned later in the Access Matrix.', 'olama-users') . '</p>';
        $this->role_form('create');
        echo '</section><section class="olama-users-card"><h2>' . esc_html__('Duplicate an OLAMA role', 'olama-users') . '</h2><p>' . esc_html__('Copy an existing OLAMA role as a starting point. User-management permissions are never copied.', 'olama-users') . '</p>';
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
        if (!$selected || !isset($roles[$selected])) {
            $selected = $roles ? (string) array_key_first($roles) : '';
        }
        echo '<section class="olama-users-panel"><h2>' . esc_html__('Role Users', 'olama-users') . '</h2><form class="olama-role-filter" method="get"><input type="hidden" name="page" value="olama-users-roles"><input type="hidden" name="tab" value="users"><label for="olama-role-filter">' . esc_html__('Choose role', 'olama-users') . '</label><select id="olama-role-filter" name="role">';
        foreach ($roles as $key => $role) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($role['label'] . ' (' . number_format_i18n($role['users']) . ')') . '</option>';
        }
        echo '</select><button class="button">' . esc_html__('Review', 'olama-users') . '</button></form>';
        if (!$selected) {
            echo '</section>';
            return;
        }
        $users = get_users(array('role' => $selected, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 200));
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('User', 'olama-users') . '</th><th>' . esc_html__('Username', 'olama-users') . '</th><th>' . esc_html__('Email', 'olama-users') . '</th><th>' . esc_html__('Account status', 'olama-users') . '</th></tr></thead><tbody>';
        if (!$users) {
            echo '<tr><td colspan="4">' . esc_html__('No users currently have this role.', 'olama-users') . '</td></tr>';
        }
        foreach ($users as $user) {
            $status = get_user_meta($user->ID, 'olama_account_status', true);
            echo '<tr><td><a href="' . esc_url(get_edit_user_link($user->ID)) . '">' . esc_html($user->display_name) . '</a></td><td><code>' . esc_html($user->user_login) . '</code></td><td>' . esc_html($user->user_email) . '</td><td>' . esc_html($status ?: __('Not managed by OLAMA', 'olama-users')) . '</td></tr>';
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

    public function matrix() {
        $this->authorize('olama_users_matrix_manage');
        $roles = Olama_Users_Roles::editable();
        $modules = Olama_Users_Registry::all();
        echo '<div class="wrap olama-users-wrap"><h1>' . esc_html__('OLAMA Access Matrix', 'olama-users') . '</h1><p>' . esc_html__('Assign declared plugin functionality to OLAMA roles. New functionality remains denied until selected.', 'olama-users') . '</p>';
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Access matrix saved.', 'olama-users') . '</p></div>';
        }
        if (!$modules) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('No OLAMA plugins have declared functionality yet.', 'olama-users') . '</p></div></div>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="olama_users_save_matrix">';
        wp_nonce_field('olama_users_save_matrix');
        echo '<div class="olama-matrix-wrap"><table class="widefat striped olama-matrix"><thead><tr><th>' . esc_html__('Functionality', 'olama-users') . '</th>';
        foreach ($roles as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($modules as $module) {
            $this->matrix_row($module['label'], $module['capability'], $roles, 0, 'module');
            foreach ($module['items'] as $item) {
                $this->matrix_item($item, $roles, 1, $module['capability']);
            }
        }
        echo '</tbody></table></div><p><button class="button button-primary">' . esc_html__('Save access matrix', 'olama-users') . '</button></p></form></div>';
    }

    private function matrix_item(array $item, array $roles, $depth, $parent_capability = '') {
        $item_capability = !empty($item['capability']) ? sanitize_key($item['capability']) : '';
        if ($item_capability && $item_capability !== sanitize_key($parent_capability)) {
            $this->matrix_row(isset($item['label']) ? $item['label'] : $item['capability'], $item['capability'], $roles, $depth, isset($item['type']) ? $item['type'] : 'item');
        }
        foreach (array('tabs', 'actions', 'items') as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) {
                continue;
            }
            foreach ($item[$key] as $child) {
                $this->matrix_item($child, $roles, $depth + 1, $item_capability ?: $parent_capability);
            }
        }
    }

    private function matrix_row($label, $capability, array $roles, $depth, $type) {
        $capability = sanitize_key($capability);
        echo '<tr class="is-' . esc_attr($type) . '"><td style="padding-inline-start:' . esc_attr(12 + ($depth * 24)) . 'px"><strong>' . esc_html($label) . '</strong><code>' . esc_html($capability) . '</code></td>';
        foreach ($roles as $role_key => $role_label) {
            $role = get_role($role_key);
            echo '<td><input type="checkbox" name="caps[' . esc_attr($role_key) . '][' . esc_attr($capability) . ']" value="1" ' . checked($role && $role->has_cap($capability), true, false) . ' aria-label="' . esc_attr($role_label . ': ' . $label) . '"></td>';
        }
        echo '</tr>';
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
