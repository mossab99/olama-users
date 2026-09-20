<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Temp_Families {
    const ROLE = 'olama_temp_family';
    const IDENTITY_TYPE = 'temp_family';
    const EXPIRY_META = 'olama_temp_family_expires_on';
    const NOTES_META = 'olama_temp_family_notes';
    const CAPABILITIES_SEEDED_OPTION = 'olama_users_temp_family_caps_seeded_v3';

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
        $member_input = isset($input['members']) ? $input['members'] : array();
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
        $members = self::validate_members($member_input);
        if (is_wp_error($members)) {
            return $members;
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
        self::save_profile($user_id, $input, $members);
        Olama_Users_DB::audit('temp_family_created', $user_id, self::IDENTITY_TYPE, $identity_key, 'success', array(
            'member_count' => count($members),
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
        $members = self::validate_members(isset($input['members']) ? $input['members'] : array());
        if (is_wp_error($members)) {
            return $members;
        }
        $updated = wp_update_user(array('ID' => absint($user_id), 'display_name' => $display_name, 'user_email' => $email));
        if (is_wp_error($updated)) {
            return $updated;
        }
        self::save_profile($user_id, $input, $members);
        Olama_Users_DB::audit('temp_family_updated', $user_id, self::IDENTITY_TYPE, $account['identity']['oracle_identifier'], 'success', array(
            'member_count' => count($members),
            'expires_on' => get_user_meta($user_id, self::EXPIRY_META, true),
        ));
        return $user_id;
    }

    private static function save_profile($user_id, array $input, array $members) {
        $expires_on = self::sanitize_expiry(isset($input['expires_on']) ? $input['expires_on'] : '');
        $notes = sanitize_textarea_field(isset($input['notes']) ? $input['notes'] : '');
        update_user_meta($user_id, self::EXPIRY_META, $expires_on);
        update_user_meta($user_id, self::NOTES_META, $notes);
        self::replace_members($user_id, $members);
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
            'members' => self::members($user_id),
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

    public static function members($user_id) {
        global $wpdb;
        $table = Olama_Users_DB::temp_family_students_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($table) . '` WHERE wp_user_id=%d ORDER BY id ASC',
            absint($user_id)
        ), ARRAY_A);
        if (!class_exists('Olama_School_Grade') || !class_exists('Olama_School_Section')) {
            return array();
        }
        $members = array();
        foreach ((array) $rows as $row) {
            $grade = Olama_School_Grade::get_grade(absint($row['grade_id']));
            $section = Olama_School_Section::get_section(absint($row['section_id']));
            if (!$grade || !$section || absint($section->grade_id) !== absint($row['grade_id']) || '' === trim((string) $row['member_name'])) {
                continue;
            }
            $members[] = array(
                'student_uid' => (string) $row['student_uid'],
                'student_name' => (string) $row['member_name'],
                'is_local_member' => true,
                'academic' => array(
                    'study_year' => isset($section->core_study_year) ? (string) $section->core_study_year : '',
                    'class_id' => isset($section->core_grade_id) ? (string) $section->core_grade_id : '',
                    'class_name' => isset($section->grade_name) ? (string) $section->grade_name : (isset($grade->grade_name) ? (string) $grade->grade_name : ''),
                    'section_id' => isset($section->core_section_id) ? (string) $section->core_section_id : '',
                    'section_name' => isset($section->section_name) ? (string) $section->section_name : '',
                    'school_grade_id' => absint($row['grade_id']),
                    'school_section_id' => absint($row['section_id']),
                ),
            );
        }
        return $members;
    }

    private static function replace_members($user_id, array $members) {
        global $wpdb;
        $table = Olama_Users_DB::temp_family_students_table();
        $wpdb->delete($table, array('wp_user_id' => absint($user_id)), array('%d'));
        foreach ($members as $member) {
            $wpdb->insert($table, array(
                'wp_user_id' => absint($user_id),
                'student_uid' => $member['student_uid'],
                'member_name' => $member['member_name'],
                'grade_id' => absint($member['grade_id']),
                'section_id' => absint($member['section_id']),
                'created_at' => current_time('mysql', true),
            ), array('%d', '%s', '%s', '%d', '%d', '%s'));
        }
    }

    private static function validate_members($items) {
        if (!is_array($items) || !$items) {
            return new WP_Error('temp_family_members_required', __('Define at least one family member.', 'olama-users'));
        }
        if (!class_exists('Olama_School_Grade') || !class_exists('Olama_School_Section')) {
            return new WP_Error('temp_family_school_missing', __('OLAMA School is required to select grades and sections.', 'olama-users'));
        }
        $members = array();
        $seen = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = sanitize_text_field(isset($item['name']) ? $item['name'] : '');
            $grade_id = isset($item['grade_id']) ? absint($item['grade_id']) : 0;
            $section_id = isset($item['section_id']) ? absint($item['section_id']) : 0;
            if (!$name || !$grade_id || !$section_id) {
                return new WP_Error('temp_family_member_incomplete', __('Every member needs a name, grade, and section.', 'olama-users'));
            }
            $grade = Olama_School_Grade::get_grade($grade_id);
            $section = Olama_School_Section::get_section($section_id);
            if (!$grade || !$section || absint($section->grade_id) !== $grade_id) {
                return new WP_Error('temp_family_member_section_invalid', __('A selected section does not belong to the selected grade.', 'olama-users'));
            }
            $uid = isset($item['uid']) ? sanitize_text_field($item['uid']) : '';
            if (!$uid || 0 !== strpos($uid, 'LOCAL-MEMBER-') || isset($seen[$uid])) {
                $uid = 'LOCAL-MEMBER-' . wp_generate_uuid4();
            }
            $seen[$uid] = true;
            $members[] = array('student_uid' => $uid, 'member_name' => $name, 'grade_id' => $grade_id, 'section_id' => $section_id);
        }
        if (!$members) {
            return new WP_Error('temp_family_members_required', __('Define at least one family member.', 'olama-users'));
        }
        return $members;
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
