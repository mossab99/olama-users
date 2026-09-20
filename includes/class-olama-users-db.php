<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_DB {
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $identities = $wpdb->prefix . 'olama_user_identities';
        $audit = $wpdb->prefix . 'olama_user_audit_log';
        $temp_students = $wpdb->prefix . 'olama_temp_family_students';

        dbDelta("CREATE TABLE {$identities} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            identity_type VARCHAR(30) NOT NULL,
            oracle_identifier VARCHAR(100) NOT NULL,
            account_status VARCHAR(20) NOT NULL DEFAULT 'active',
            source_system VARCHAR(30) NOT NULL DEFAULT 'oracle',
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_oracle_identity (identity_type, oracle_identifier),
            UNIQUE KEY uniq_user_identity (wp_user_id, identity_type),
            KEY idx_wp_user (wp_user_id),
            KEY idx_status (account_status)
        ) {$collate};");

        dbDelta("CREATE TABLE {$audit} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(80) NOT NULL,
            actor_wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subject_wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            identity_type VARCHAR(30) NULL,
            oracle_identifier VARCHAR(100) NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'success',
            safe_context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_event (event_type),
            KEY idx_subject (subject_wp_user_id),
            KEY idx_created (created_at)
        ) {$collate};");

        dbDelta("CREATE TABLE {$temp_students} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            student_uid VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_temp_student (wp_user_id, student_uid),
            KEY idx_student_uid (student_uid)
        ) {$collate};");

        update_option('olama_users_db_version', OLAMA_USERS_VERSION);
    }

    public static function identities_table() {
        global $wpdb;
        return $wpdb->prefix . 'olama_user_identities';
    }

    public static function audit_table() {
        global $wpdb;
        return $wpdb->prefix . 'olama_user_audit_log';
    }

    public static function temp_family_students_table() {
        global $wpdb;
        return $wpdb->prefix . 'olama_temp_family_students';
    }

    public static function get_identity($type, $oracle_identifier) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql(self::identities_table()) . '` WHERE identity_type=%s AND oracle_identifier=%s LIMIT 1',
            sanitize_key($type),
            (string) $oracle_identifier
        ), ARRAY_A);
    }

    public static function get_identity_by_user($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql(self::identities_table()) . '` WHERE wp_user_id=%d ORDER BY id ASC LIMIT 1',
            absint($user_id)
        ), ARRAY_A);
    }

    public static function save_identity($user_id, $type, $oracle_identifier, $status = 'active', $source_system = 'oracle') {
        global $wpdb;
        $table = self::identities_table();
        $existing = self::get_identity($type, $oracle_identifier);
        $now = current_time('mysql', true);
        $data = array(
            'wp_user_id' => absint($user_id),
            'identity_type' => sanitize_key($type),
            'oracle_identifier' => (string) $oracle_identifier,
            'account_status' => 'active' === $status ? 'active' : 'suspended',
            'source_system' => sanitize_key($source_system) ?: 'oracle',
            'last_synced_at' => $now,
            'updated_at' => $now,
        );
        if ($existing) {
            $wpdb->update($table, $data, array('id' => absint($existing['id'])));
        } else {
            $data['created_at'] = $now;
            $wpdb->insert($table, $data);
        }
        update_user_meta(absint($user_id), 'olama_identity_type', sanitize_key($type));
        update_user_meta(absint($user_id), 'olama_oracle_identifier', (string) $oracle_identifier);
        update_user_meta(absint($user_id), 'olama_account_status', $data['account_status']);
        return !empty($wpdb->last_error) ? new WP_Error('identity_save_failed', $wpdb->last_error) : true;
    }

    public static function audit($event_type, $subject_user_id, $type, $oracle_identifier, $result = 'success', array $context = array()) {
        global $wpdb;
        unset($context['password'], $context['mother_mobile'], $context['national_number']);
        $wpdb->insert(self::audit_table(), array(
            'event_type' => sanitize_key($event_type),
            'actor_wp_user_id' => get_current_user_id(),
            'subject_wp_user_id' => absint($subject_user_id),
            'identity_type' => sanitize_key($type),
            'oracle_identifier' => (string) $oracle_identifier,
            'result' => sanitize_key($result),
            'safe_context_json' => wp_json_encode($context),
            'created_at' => current_time('mysql', true),
        ));
    }
}
