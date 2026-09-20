<?php

define('ABSPATH', __DIR__);

class WP_Error {
    private $code;

    public function __construct($code, $message = '') {
        $this->code = $code;
    }

    public function get_error_code() {
        return $this->code;
    }
}

class WP_User {
    public $ID;
    public $user_login;
    public $user_pass;

    public function __construct($id, $login, $password) {
        $this->ID = $id;
        $this->user_login = $login;
        $this->user_pass = $password;
    }
}

$temp_user = new WP_User(11525, 'tf_999888', 'stored-password');
$core_family_exists = false;
$audit_events = array();

function __($text) {
    return $text;
}

function get_user_by($field, $value) {
    global $temp_user;
    return 'login' === $field && $temp_user->user_login === $value ? $temp_user : false;
}

function wp_check_password($password, $hash, $user_id) {
    return 'correct-password' === $password && 'stored-password' === $hash && 11525 === $user_id;
}

class Olama_Users_DB {
    public static function get_identity($type, $identifier) {
        global $core_family_exists;
        return $core_family_exists && 'family' === $type && '999888' === $identifier
            ? array('wp_user_id' => 77)
            : null;
    }

    public static function get_identity_by_user($user_id) {
        return 11525 === $user_id
            ? array(
                'identity_type' => 'temp_family',
                'account_status' => 'active',
                'oracle_identifier' => 'LOCAL-TEMP-11525',
            )
            : null;
    }

    public static function audit($event, $user_id, $type, $identifier, $result = 'success') {
        global $audit_events;
        $audit_events[] = compact('event', 'user_id', 'type', 'identifier', 'result');
    }
}

class Olama_Users_Temp_Families {
    public static function is_expired($user_id) {
        return false;
    }
}

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/includes/class-olama-users-plugin.php';

$reflection = new ReflectionClass('Olama_Users_Plugin');
$plugin = $reflection->newInstanceWithoutConstructor();
$core_error = new WP_Error('invalid_username');

$exact = $plugin->authenticate_temp_family($core_error, 'tf_999888', 'correct-password');
assert_true($exact instanceof WP_User && 11525 === $exact->ID, 'The complete temporary username should authenticate.');

$alias = $plugin->authenticate_temp_family($core_error, '999888', 'correct-password');
assert_true($alias instanceof WP_User && 11525 === $alias->ID, 'The prefix-free numeric alias should authenticate.');

$wrong_password = $plugin->authenticate_temp_family($core_error, '999888', 'wrong-password');
assert_true($wrong_password instanceof WP_Error && 'invalid_username' === $wrong_password->get_error_code(), 'A wrong password must fail.');

$core_family_exists = true;
$canonical = $plugin->authenticate_temp_family($core_error, '999888', 'correct-password');
assert_true($canonical === $core_error, 'A canonical Core family number must take priority over a temporary alias.');

echo "Temp Family authentication tests passed.\n";
