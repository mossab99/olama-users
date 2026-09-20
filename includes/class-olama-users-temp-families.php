<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Temp_Families {
    const ROLE = 'olama_temp_family';
    const IDENTITY_TYPE = 'temp_family';
    const EXPIRY_META = 'olama_temp_family_expires_on';
    const NOTES_META = 'olama_temp_family_notes';
    const CAPABILITIES_SEEDED_OPTION = 'olama_users_temp_family_caps_seeded';

    public static function seed_portal_capabilities() {
        if ('1' === (string) get_option(self::CAPABILITIES_SEEDED_OPTION, '')) {
            return;
        }
        if (!get_role(self::ROLE) || !Olama_Users_Registry::get('olama_student_gateway')) {
            return;
        }
        $caps = array(
            'olama_student_gateway_access',
            'olama_student_gateway_weekly_plan_view',
            'olama_student_gateway_schedule_view',
            'olama_student_gateway_teachers_view',
            'olama_student_gateway_video_library_view',
            'olama_student_gateway_exams_view',
            'olama_student_gateway_evaluations_view',
            'olama_student_gateway_attendance_view',
            'olama_student_gateway_stores_view',
            'olama_student_gateway_messages_view',
        );
        $result = Olama_Users_Roles::save_plugin_capabilities(self::ROLE, 'olama_student_gateway', $caps);
        if (!is_wp_error($result)) {
            update_option(self::CAPABILITIES_SEEDED_OPTION, '1', false);
        }
    }

    public static function create(array $input) {
        $display_name = sanitize_text_field(isset($input['display_name']) ? $input['display_name'] : '');
        $login = sanitize_user(isset($input['user_login']) ? $input['user_login'] : '', true);
        $email = sanitize_email(isset($input['user_email']) ? $input['user_email'] : '');
        $password = isset($input['password']) ? (string) $input['password'] : '';
        $student_uids = self::sanitize_student_uids(isset($input['student_uids']) ? $input['student_uids'] : array());
        if (!$display_name || !$login) {
            return new WP_Error('temp_family_required_fields', __('Enter a display name and username.', 'olama-users'));
        }
        if (0 !== strpos($login, 'tf_')) {
            $login = 'tf_' . ltrim($login, '_');
        }
        if ('tf_' === $login) {
            return new WP_Error('temp_family_username_required', __('Enter a username after the tf_ prefix.', 'olama-users'));
        }
        if (username_exists($login)) {
            return new WP_Error('temp_family_username_exists', __('That username is already in use.', 'olama-users'));
        }
        if ($email && email_exists($email)) {
            return new WP_Error('temp_family_email_exists', __('That email address is already in use.', 'olama-users'));
        }
        if (strlen($password) < 12) {
            return new WP_Error('temp_family_weak_password', __('Use a password with at least 12 characters.', 'olama-users'));
        }
        $validated = self::validate_students($student_uids);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $user_id = Olama_Users_Roles::run_authorized(function() use ($login, $password, $email, $display_name) {
            return wp_insert_user(array(
                'user_login' => $login,
                'user_pass' => $password,
                'user_email' => $email,
                'display_name' => $display_name,
                'role' => self::ROLE,
            ));
        });
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        $identity_key = 'LOCAL-TEMP-' . absint($user_id);
        $saved = Olama_Users_DB::save_identity($user_id, self::IDENTITY_TYPE, $identity_key, 'active', 'local');
        if (is_wp_error($saved)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            return $saved;
        }
        self::save_profile($user_id, $input, $validated);
        Olama_Users_DB::audit('temp_family_created', $user_id, self::IDENTITY_TYPE, $identity_key, 'success', array(
            'students' => $validated,
            'expires_on' => get_user_meta($user_id, self::EXPIRY_META, true),
        ));
        return $user_id;
    }

    public static function update($user_id, array $input) {
        $account = self::get($user_id);
        if (!$account) {
            return new WP_Error('temp_family_missing', __('Temp Family account not found.', 'olama-users'));
        }
        $display_name = sanitize_text_field(isset($input['display_name']) ? $input['display_name'] : '');
        $email = sanitize_email(isset($input['user_email']) ? $input['user_email'] : '');
        if (!$display_name) {
            return new WP_Error('temp_family_name_required', __('Enter a display name.', 'olama-users'));
        }
        $email_owner = $email ? email_exists($email) : false;
        if ($email_owner && absint($email_owner) !== absint($user_id)) {
            return new WP_Error('temp_family_email_exists', __('That email address is already in use.', 'olama-users'));
        }
        $student_uids = self::sanitize_student_uids(isset($input['student_uids']) ? $input['student_uids'] : array());
        $validated = self::validate_students($student_uids);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $updated = wp_update_user(array('ID' => absint($user_id), 'display_name' => $display_name, 'user_email' => $email));
        if (is_wp_error($updated)) {
            return $updated;
        }
        self::save_profile($user_id, $input, $validated);
        Olama_Users_DB::audit('temp_family_updated', $user_id, self::IDENTITY_TYPE, $account['identity']['oracle_identifier'], 'success', array(
            'students' => $validated,
            'expires_on' => get_user_meta($user_id, self::EXPIRY_META, true),
        ));
        return $user_id;
    }

    private static function save_profile($user_id, array $input, array $student_uids) {
        $expires_on = self::sanitize_expiry(isset($input['expires_on']) ? $input['expires_on'] : '');
        $notes = sanitize_textarea_field(isset($input['notes']) ? $input['notes'] : '');
        update_user_meta($user_id, self::EXPIRY_META, $expires_on);
        update_user_meta($user_id, self::NOTES_META, $notes);
        self::replace_students($user_id, $student_uids);
    }

    public static function set_status($user_id, $status) {
        $account = self::get($user_id);
        if (!$account) {
            return new WP_Error('temp_family_missing', __('Temp Family account not found.', 'olama-users'));
        }
        $status = 'active' === $status ? 'active' : 'suspended';
        if ('active' === $status && self::is_expired($user_id)) {
            return new WP_Error('temp_family_expired', __('Extend or remove the expiry date before activating this account.', 'olama-users'));
        }
        $saved = Olama_Users_DB::save_identity($user_id, self::IDENTITY_TYPE, $account['identity']['oracle_identifier'], $status, 'local');
        if (is_wp_error($saved)) {
            return $saved;
        }
        if ('suspended' === $status) {
            WP_Session_Tokens::get_instance($user_id)->destroy_all();
        }
        Olama_Users_DB::audit('temp_family_' . ('active' === $status ? 'activated' : 'deactivated'), $user_id, self::IDENTITY_TYPE, $account['identity']['oracle_identifier']);
        return true;
    }

    public static function reset_password($user_id, $password) {
        $account = self::get($user_id);
        if (!$account) {
            return new WP_Error('temp_family_missing', __('Temp Family account not found.', 'olama-users'));
        }
        if (strlen((string) $password) < 12) {
            return new WP_Error('temp_family_weak_password', __('Use a password with at least 12 characters.', 'olama-users'));
        }
        wp_set_password((string) $password, absint($user_id));
        WP_Session_Tokens::get_instance($user_id)->destroy_all();
        Olama_Users_DB::audit('temp_family_password_reset', $user_id, self::IDENTITY_TYPE, $account['identity']['oracle_identifier']);
        return true;
    }

    public static function get($user_id) {
        $identity = Olama_Users_DB::get_identity_by_user(absint($user_id));
        if (!$identity || self::IDENTITY_TYPE !== (string) $identity['identity_type']) {
            return null;
        }
        $user = get_userdata(absint($user_id));
        if (!$user) {
            return null;
        }
        return array(
            'user' => $user,
            'identity' => $identity,
            'students' => self::students($user_id),
            'expires_on' => get_user_meta($user_id, self::EXPIRY_META, true),
            'notes' => get_user_meta($user_id, self::NOTES_META, true),
        );
    }

    public static function all() {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT wp_user_id FROM `' . esc_sql(Olama_Users_DB::identities_table()) . '` WHERE identity_type=%s ORDER BY id DESC',
            self::IDENTITY_TYPE
        ));
        return array_values(array_filter(array_map(array(__CLASS__, 'get'), $ids)));
    }

    public static function student_uids($user_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            'SELECT student_uid FROM `' . esc_sql(Olama_Users_DB::temp_family_students_table()) . '` WHERE wp_user_id=%d ORDER BY id ASC',
            absint($user_id)
        ));
    }

    public static function students($user_id) {
        $uids = self::student_uids($user_id);
        if (!$uids || !function_exists('olama_core')) {
            return array();
        }
        $rows = olama_core()->students()->get_by_uids($uids);
        $by_uid = array();
        foreach ((array) $rows as $row) {
            $by_uid[(string) $row['student_uid']] = $row;
        }
        $ordered = array();
        foreach ($uids as $uid) {
            if (isset($by_uid[$uid])) {
                $ordered[] = $by_uid[$uid];
            }
        }
        return $ordered;
    }

    private static function replace_students($user_id, array $uids) {
        global $wpdb;
        $table = Olama_Users_DB::temp_family_students_table();
        $wpdb->delete($table, array('wp_user_id' => absint($user_id)), array('%d'));
        foreach ($uids as $uid) {
            $wpdb->insert($table, array('wp_user_id' => absint($user_id), 'student_uid' => $uid, 'created_at' => current_time('mysql', true)), array('%d', '%s', '%s'));
        }
    }

    private static function validate_students(array $uids) {
        if (!$uids) {
            return new WP_Error('temp_family_students_required', __('Assign at least one student.', 'olama-users'));
        }
        if (!function_exists('olama_core')) {
            return new WP_Error('temp_family_core_missing', __('OLAMA Core is required to select students.', 'olama-users'));
        }
        $valid = array();
        foreach ($uids as $uid) {
            if (olama_core()->students()->get_by_uid($uid)) {
                $valid[] = $uid;
            }
        }
        if (count($valid) !== count($uids)) {
            return new WP_Error('temp_family_student_invalid', __('One or more selected students no longer exist in OLAMA Core.', 'olama-users'));
        }
        return $valid;
    }

    private static function sanitize_student_uids($uids) {
        if (!is_array($uids)) {
            $uids = preg_split('/[\r\n,]+/', (string) $uids);
        }
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $uids), 'strlen')));
    }

    private static function sanitize_expiry($value) {
        $value = sanitize_text_field((string) $value);
        if (!$value) {
            return '';
        }
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    public static function is_expired($user_id) {
        $expires_on = get_user_meta(absint($user_id), self::EXPIRY_META, true);
        return $expires_on && current_time('Y-m-d') > $expires_on;
    }
}
