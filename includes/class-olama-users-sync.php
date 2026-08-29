<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Sync {
    const FAMILY_PASSWORD_PREFIX_OPTION = 'olama_users_family_password_prefix';
    const EMPLOYEE_PASSWORD_PREFIX_OPTION = 'olama_users_employee_password_prefix';

    public static function password_prefix($type) {
        $option = 'family' === $type
            ? self::FAMILY_PASSWORD_PREFIX_OPTION
            : self::EMPLOYEE_PASSWORD_PREFIX_OPTION;
        return (string) get_option($option, '');
    }

    public static function build_password($type, $base_value) {
        return self::password_prefix($type) . (string) $base_value;
    }

    public function preview($type) {
        return $this->run($type, false);
    }

    public function apply($type) {
        if (!Olama_Users_Roles::default_roles_ready()) {
            return new WP_Error(
                'default_roles_required',
                __('Assign valid default roles for both families and employees before applying synchronization.', 'olama-users')
            );
        }
        return $this->run($type, true);
    }

    /** Apply one previewable create/update operation without running the lifecycle pass. */
    public function apply_one($type, $identifier, $operation) {
        $records = 'family' === $type ? $this->family_records() : $this->employee_records();
        if (is_wp_error($records)) {
            return $records;
        }
        $identifier_key = 'family' === $type ? 'oracle_family_id' : 'employee_id';
        foreach ($records as $record) {
            if ((string) $identifier === trim((string) (isset($record[$identifier_key]) ? $record[$identifier_key] : ''))) {
                $preview = 'family' === $type ? $this->process_family($record, false) : $this->process_employee($record, false);
                if (!isset($preview['status']) || $preview['status'] !== $operation) {
                    return new WP_Error('sync_operation_mismatch', __('The requested action no longer matches the current account status. Refresh the preview and try again.', 'olama-users'));
                }
                return $this->result_for_event('family' === $type ? $this->process_family($record, true) : $this->process_employee($record, true));
            }
        }
        return new WP_Error('sync_identifier_missing', __('The selected source record is no longer available. Refresh the preview and try again.', 'olama-users'));
    }

    /** Apply all current records whose preview status matches the requested operation. */
    public function apply_batch($type, $operation) {
        $records = 'family' === $type ? $this->family_records() : $this->employee_records();
        if (is_wp_error($records)) {
            return $records;
        }
        $summary = array('scanned' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'suspend' => 0, 'conflict' => 0, 'invalid' => 0, 'failed' => 0, 'events' => array());
        foreach ($records as $record) {
            $preview = 'family' === $type ? $this->process_family($record, false) : $this->process_employee($record, false);
            if (!isset($preview['status']) || $preview['status'] !== $operation) {
                continue;
            }
            $summary['scanned']++;
            $event = 'family' === $type ? $this->process_family($record, true) : $this->process_employee($record, true);
            $status = isset($event['status']) ? $event['status'] : 'failed';
            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['failed']++;
            }
            if (count($summary['events']) < 200) {
                $summary['events'][] = $event;
            }
        }
        return $summary;
    }

    private function result_for_event(array $event) {
        $summary = array('scanned' => 1, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'suspend' => 0, 'conflict' => 0, 'invalid' => 0, 'failed' => 0, 'events' => array($event));
        $status = isset($event['status']) ? $event['status'] : 'failed';
        if (isset($summary[$status])) {
            $summary[$status]++;
        } else {
            $summary['failed']++;
        }
        return $summary;
    }

    private function run($type, $apply) {
        $records = 'family' === $type ? $this->family_records() : $this->employee_records();
        if (is_wp_error($records)) {
            return $records;
        }
        $summary = array('scanned' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'suspend' => 0, 'conflict' => 0, 'invalid' => 0, 'failed' => 0, 'events' => array());
        $active_identifiers = array();
        foreach ($records as $record) {
            $summary['scanned']++;
            $identifier_key = 'family' === $type ? 'oracle_family_id' : 'employee_id';
            $result = 'family' === $type ? $this->process_family($record, $apply) : $this->process_employee($record, $apply);
            $status = isset($result['status']) ? $result['status'] : 'failed';
            // A valid source record remains eligible even if provisioning has a
            // temporary write failure. Only invalid source data may trigger the
            // missing-identity lifecycle pass for that identifier.
            if ('invalid' !== $status && isset($record[$identifier_key]) && '' !== (string) $record[$identifier_key]) {
                $active_identifiers[] = (string) $record[$identifier_key];
            }
            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['failed']++;
            }
            if (count($summary['events']) < 200 || 'invalid' === $status) {
                $summary['events'][] = $result;
            }
        }
        if (in_array($type, array('family', 'employee'), true)) {
            foreach ($this->missing_identity_results($type, $active_identifiers, $apply) as $result) {
                $status = isset($result['status']) ? $result['status'] : 'failed';
                if (isset($summary[$status])) {
                    $summary[$status]++;
                } else {
                    $summary['failed']++;
                }
                if (count($summary['events']) < 200) {
                    $summary['events'][] = $result;
                }
            }
        }
        return $summary;
    }

    private function missing_identity_results($type, array $active_identifiers, $apply) {
        global $wpdb;
        $type = 'family' === $type ? 'family' : 'employee';
        $active_identifiers = array_values(array_unique(array_map('strval', $active_identifiers)));
        if (!$active_identifiers) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($active_identifiers), '%s'));
        $params = array_merge(array($type, 'active'), $active_identifiers);
        $identities = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql(Olama_Users_DB::identities_table()) . '` WHERE identity_type=%s AND account_status=%s AND oracle_identifier NOT IN (' . $placeholders . ') ORDER BY id ASC',
            $params
        ), ARRAY_A);

        $results = array();
        foreach ($identities as $identity) {
            $user = get_userdata(absint($identity['wp_user_id']));
            if (!$user) {
                continue;
            }
            if (user_can($user, 'manage_options')) {
                $results[] = array(
                    'status' => 'conflict',
                    'identifier' => $identity['oracle_identifier'],
                    'username' => $user->user_login,
                    'display_name' => $user->display_name,
                    'message' => __('Privileged technical administrators are never suspended automatically.', 'olama-users'),
                );
                continue;
            }
            if ($apply) {
                $saved = Olama_Users_DB::save_identity($user->ID, $type, $identity['oracle_identifier'], 'suspended');
                if (is_wp_error($saved)) {
                    $results[] = array(
                        'status' => 'failed',
                        'identifier' => $identity['oracle_identifier'],
                        'username' => $user->user_login,
                        'display_name' => $user->display_name,
                        'message' => $saved->get_error_message(),
                    );
                    continue;
                }
                if (class_exists('WP_Session_Tokens')) {
                    WP_Session_Tokens::get_instance($user->ID)->destroy_all();
                }
                $reason = 'family' === $type
                    ? 'missing_from_eligible_core_family_directory'
                    : 'missing_from_active_core_employee_directory';
                Olama_Users_DB::audit('account_suspended', $user->ID, $type, $identity['oracle_identifier'], 'success', array('reason' => $reason));
            }
            $is_family = 'family' === $type;
            $results[] = array(
                'status' => 'suspend',
                'identifier' => $identity['oracle_identifier'],
                'username' => $user->user_login,
                'display_name' => $user->display_name,
                'message' => $is_family
                    ? ($apply
                        ? __('Suspended because the family is no longer eligible in Core for the active academic year.', 'olama-users')
                        : __('Will suspend: family is no longer eligible in Core for the active academic year.', 'olama-users'))
                    : ($apply
                        ? __('Suspended because the employee is no longer active in Core.', 'olama-users')
                        : __('Will suspend: employee is no longer active in Core.', 'olama-users')),
            );
        }
        return $results;
    }

    private function active_study_year() {
        if (function_exists('olama_core') && method_exists(olama_core(), 'academic_context')) {
            $year = olama_core()->academic_context()->current_year();
            if ($year) {
                return (string) (!empty($year->code) ? $year->code : $year->year_name);
            }
        }
        return '';
    }

    private function family_records() {
        $year = $this->active_study_year();
        if (!$year) {
            return new WP_Error('missing_active_year', __('No active academic year is configured.', 'olama-users'));
        }
        if (!function_exists('olama_core') || !method_exists(olama_core()->families(), 'active_for_study_year')) {
            return new WP_Error('core_family_directory_missing', __('The OLAMA Core family directory is unavailable.', 'olama-users'));
        }
        return olama_core()->families()->active_for_study_year($year);
    }

    private function employee_records() {
        if (!function_exists('olama_core') || !method_exists(olama_core(), 'employees')) {
            return new WP_Error('core_employees_missing', __('The OLAMA Core employee directory is unavailable.', 'olama-users'));
        }
        $employees = olama_core()->employees()->active(array('limit' => 1000, 'offset' => 0));
        if (!$employees) {
            return new WP_Error('core_employees_empty', __('No active employees are stored in OLAMA Core. Run the employee import in Olama Oracle Sync first.', 'olama-users'));
        }
        return $employees;
    }

    private function process_family(array $record, $apply) {
        $id = isset($record['oracle_family_id']) ? trim((string) $record['oracle_family_id']) : '';
        $family_name = $this->first_value($record, array('father_name', 'sponsor_full_name', 'mother_name'));
        $display_name = $this->family_display_name($id, $family_name, isset($record['family_uid']) ? $record['family_uid'] : '');
        $phone = $this->normalize_phone(isset($record['mother_mobile']) ? $record['mother_mobile'] : '');
        if (!preg_match('/^\d+$/', $id) || !$this->valid_jordan_mobile($phone)) {
            return array(
                'status' => 'invalid',
                'identifier' => $id,
                'username' => $id,
                'display_name' => $display_name,
                'message' => __('Invalid family ID or mother mobile.', 'olama-users'),
            );
        }
        $password = self::build_password('family', $phone);
        return $this->provision('family', $id, $id, $display_name, Olama_Users_Roles::default_role('family'), $password, $record, $apply, $family_name);
    }

    private function process_employee(array $record, $apply) {
        $id = isset($record['employee_id']) ? trim((string) $record['employee_id']) : '';
        $username = 'emp' . $id;
        $name = isset($record['full_name']) ? trim((string) $record['full_name']) : '';
        $display_name = $name ?: $username;
        $phone = $this->normalize_phone(isset($record['phones']) ? $record['phones'] : '');
        if (!preg_match('/^\d+$/', $id)) {
            return array(
                'status' => 'invalid',
                'identifier' => $id,
                'username' => $username,
                'display_name' => $display_name,
                'message' => __('Invalid employee ID.', 'olama-users'),
            );
        }
        $status = isset($record['employee_status']) ? trim((string) $record['employee_status']) : '';
        if ('مستمر' !== $status) {
            return array(
                'status' => 'invalid',
                'identifier' => $id,
                'username' => $username,
                'display_name' => $display_name,
                'message' => __('Employee is not active.', 'olama-users'),
            );
        }
        if (!$this->valid_jordan_mobile($phone)) {
            return array(
                'status' => 'invalid',
                'identifier' => $id,
                'username' => $username,
                'display_name' => $display_name,
                'message' => __('Invalid employee mobile.', 'olama-users'),
            );
        }
        $password = self::build_password('employee', $phone);
        return $this->provision('employee', $id, $username, $display_name, Olama_Users_Roles::default_role('employee'), $password, $record, $apply);
    }

    private function provision($type, $identifier, $username, $display_name, $default_role, $password, array $record, $apply, $family_name = '') {
        $display_name = sanitize_text_field(trim((string) $display_name));
        if ('' === $display_name) {
            $display_name = $username;
        }
        $profile_name = 'family' === $type
            ? array('first_name' => $display_name, 'last_name' => '')
            : $this->profile_name($display_name);
        $identity = Olama_Users_DB::get_identity($type, $identifier);
        $user = $identity ? get_userdata(absint($identity['wp_user_id'])) : false;
        $adopting = false;
        if (!$identity) {
            $collision = get_user_by('login', $username);
            if ($collision) {
                if (!$this->can_adopt_existing($collision, $type, $identifier)) {
                    return array(
                        'status' => 'conflict',
                        'identifier' => $identifier,
                        'username' => $username,
                        'display_name' => $display_name,
                        'message' => __('Username belongs to an unrelated or privileged WordPress user.', 'olama-users'),
                    );
                }
                $user = $collision;
                $adopting = true;
            }
        }
        $operation = $user ? 'update' : 'create';
        if (!$apply) {
            return array(
                'status' => $operation,
                'identifier' => $identifier,
                'username' => $username,
                'display_name' => $display_name,
                'message' => $adopting ? __('Adopt verified legacy account', 'olama-users') : ucfirst($operation),
            );
        }

        if (!$user) {
            $user_id = Olama_Users_Roles::run_authorized(function() use ($username, $password, $display_name, $profile_name, $default_role) {
                return wp_insert_user(array(
                    'user_login' => $username,
                    'user_pass' => null !== $password ? $password : wp_generate_password(64, true, true),
                    'first_name' => $profile_name['first_name'],
                    'last_name' => $profile_name['last_name'],
                    'display_name' => $display_name,
                    'role' => $default_role,
                ));
            });
            if (is_wp_error($user_id)) {
                Olama_Users_DB::audit('account_create_failed', 0, $type, $identifier, 'failed', array('code' => $user_id->get_error_code()));
                return array(
                    'status' => 'failed',
                    'identifier' => $identifier,
                    'username' => $username,
                    'display_name' => $display_name,
                    'message' => $user_id->get_error_message(),
                );
            }
            $user = get_userdata($user_id);
        } else {
            $user_id = $user->ID;
            $updated = wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $profile_name['first_name'],
                'last_name' => $profile_name['last_name'],
                'display_name' => $display_name,
            ));
            if (is_wp_error($updated)) {
                return array(
                    'status' => 'failed',
                    'identifier' => $identifier,
                    'username' => $username,
                    'display_name' => $display_name,
                    'message' => $updated->get_error_message(),
                );
            }
            if ($adopting && !in_array($default_role, (array) $user->roles, true)) {
                Olama_Users_Roles::run_authorized(function() use ($user, $default_role) {
                    $user->set_role($default_role);
                });
            }
            if (null !== $password && !wp_check_password($password, $user->user_pass, $user_id)) {
                wp_set_password($password, $user_id);
            }
        }

        $saved = Olama_Users_DB::save_identity($user_id, $type, $identifier, 'active');
        if (is_wp_error($saved)) {
            return array(
                'status' => 'failed',
                'identifier' => $identifier,
                'username' => $username,
                'display_name' => $display_name,
                'message' => $saved->get_error_message(),
            );
        }
        if ('employee' === $type && function_exists('olama_core')) {
            $staff_saved = olama_core()->staff()->save($user_id, array(
                'employee_id' => $identifier,
                'phone_number' => isset($record['phones']) ? $record['phones'] : '',
            ));
            if (is_wp_error($staff_saved)) {
                return array(
                    'status' => 'failed',
                    'identifier' => $identifier,
                    'username' => $username,
                    'display_name' => $display_name,
                    'message' => $staff_saved->get_error_message(),
                );
            }
        }
        Olama_Users_DB::audit('account_' . $operation . 'd', $user_id, $type, $identifier, 'success', array(
            'username' => $username,
            'display_name' => $display_name,
        ));
        return array(
            'status' => $operation,
            'identifier' => $identifier,
            'username' => $username,
            'display_name' => $display_name,
            'message' => ucfirst($operation) . 'd',
        );
    }

    /**
     * Convert the canonical full name into WordPress' native profile fields.
     * Core currently provides one full-name value, so keep every word by using
     * the first word as the given name and the remainder as the last name.
     */
    private function profile_name($full_name) {
        $parts = preg_split('/\s+/u', trim((string) $full_name), 2, PREG_SPLIT_NO_EMPTY);
        return array(
            'first_name' => isset($parts[0]) ? sanitize_text_field($parts[0]) : '',
            'last_name' => isset($parts[1]) ? sanitize_text_field($parts[1]) : '',
        );
    }

    /** Build the family label used in WordPress and in the synchronization preview. */
    private function family_display_name($id, $family_name, $family_uid) {
        $family_name = trim((string) $family_name);
        $label = $family_name ?: sprintf(__('Family %s', 'olama-users'), $id);
        $students = array();

        $family_uid = $family_uid ?: ('ORA-FAM-' . trim((string) $id));
        if ($family_uid && function_exists('olama_core') && method_exists(olama_core(), 'families')) {
            $rows = olama_core()->families()->get_students($family_uid);
            $year = $this->active_study_year();
            $years = $year && method_exists(olama_core(), 'student_years')
                ? olama_core()->student_years()->get_by_family($family_uid, $year)
                : array();
            $classes = array();
            foreach ($years as $student_year) {
                $student_uid = isset($student_year['student_uid']) ? (string) $student_year['student_uid'] : '';
                if ($student_uid) {
                    $classes[$student_uid] = array(
                        'grade' => trim((string) (isset($student_year['class_name']) ? $student_year['class_name'] : '')),
                        'section' => trim((string) (isset($student_year['section_name']) ? $student_year['section_name'] : '')),
                    );
                }
            }
            foreach ((array) $rows as $student) {
                $student_name = is_array($student) ? (isset($student['student_name']) ? $student['student_name'] : '') : (isset($student->student_name) ? $student->student_name : '');
                $student_uid = is_array($student) ? (isset($student['student_uid']) ? $student['student_uid'] : '') : (isset($student->student_uid) ? $student->student_uid : '');
                $student_name = $this->short_student_name($student_name, $family_name);
                if ('' === $student_name || ($classes && !isset($classes[$student_uid]))) {
                    continue;
                }
                $student_first_name = preg_split('/\s+/u', $student_name, 2, PREG_SPLIT_NO_EMPTY);
                $student_first_name = isset($student_first_name[0]) ? $student_first_name[0] : $student_name;
                $grade = !empty($classes[$student_uid]['grade']) ? $this->short_class_name($classes[$student_uid]['grade']) : '';
                $section = !empty($classes[$student_uid]['section']) ? $classes[$student_uid]['section'] : '';
                $student_parts = array_filter(array($student_first_name, $grade, $section), 'strlen');
                $students[] = implode(' ', $student_parts);
            }
        }

        return 'عائلة ' . trim((string) $id) . ' ' . $label . ($students ? ' - ' . implode(' - ', $students) : '');
    }

    private function short_student_name($student_name, $family_name) {
        $student_name = trim((string) $student_name);
        $family_name = trim((string) $family_name);
        if ($family_name && preg_match('/\s+' . preg_quote($family_name, '/') . '$/u', $student_name)) {
            $student_name = trim((string) preg_replace('/\s+' . preg_quote($family_name, '/') . '$/u', '', $student_name));
        }
        return $student_name;
    }

    private function short_class_name($class_name) {
        $class_name = trim((string) $class_name);
        if (preg_match('/\s+[اأ]ساسي$/u', $class_name)) {
            return trim((string) preg_replace('/\s+[اأ]ساسي$/u', '', $class_name));
        }
        return $class_name;
    }

    private function can_adopt_existing(WP_User $user, $type, $identifier) {
        if (user_can($user, 'manage_options')) {
            return false;
        }
        if ('family' === $type) {
            return (bool) array_intersect(array('family', 'student', 'olama_family', Olama_Users_Roles::default_role('family')), (array) $user->roles);
        }
        if ('employee' !== $type) {
            return false;
        }
        global $wpdb;
        $staff_table = olama_core()->read_models()->table('staff_profiles');
        $staff_employee_id = $wpdb->get_var($wpdb->prepare(
            'SELECT employee_id FROM `' . esc_sql($staff_table) . '` WHERE user_id=%d LIMIT 1',
            $user->ID
        ));
        if ('' !== (string) $staff_employee_id) {
            return (string) $staff_employee_id === (string) $identifier;
        }

        // Legacy OLAMA employee accounts predate the shared staff mapping.
        // Exact emp{Oracle ID} usernames plus a known employee role are enough
        // to adopt non-privileged accounts while preserving their current roles.
        $expected_username = 'emp' . (string) $identifier;
        $legacy_employee_roles = array('teacher', 'assistant', 'editor', 'accountant', 'supervisor', 'author', 'olama_teacher');
        return $user->user_login === $expected_username
            && (bool) array_intersect($legacy_employee_roles, (array) $user->roles);
    }

    private function normalize_phone($value) {
        $value = strtr((string) $value, array('٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'));
        $digits = preg_replace('/\D+/', '', $value);
        if (0 === strpos($digits, '00962')) {
            $digits = '0' . substr($digits, 5);
        } elseif (0 === strpos($digits, '962')) {
            $digits = '0' . substr($digits, 3);
        }
        return $digits;
    }

    private function valid_jordan_mobile($phone) {
        return (bool) preg_match('/^07[789]\d{7}$/', $phone);
    }

    private function first_value(array $record, array $keys) {
        foreach ($keys as $key) {
            if (!empty($record[$key])) {
                return trim((string) $record[$key]);
            }
        }
        return '';
    }
}
