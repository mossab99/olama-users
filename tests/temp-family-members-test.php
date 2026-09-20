<?php

define('ABSPATH', __DIR__);

class WP_Error {
    private $code;
    public function __construct($code, $message) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

function __($text) { return $text; }
function sanitize_text_field($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_generate_uuid4() { return '11111111-2222-4333-8444-555555555555'; }
function is_wp_error($value) { return $value instanceof WP_Error; }

class Olama_School_Grade {
    public static function get_grade($id) {
        return in_array((int) $id, array(8, 9), true) ? (object) array('id' => (int) $id) : null;
    }
}

class Olama_School_Section {
    public static function get_section($id) {
        if (12 === (int) $id) {
            return (object) array('id' => 12, 'grade_id' => 8);
        }
        return null;
    }
}

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/includes/class-olama-users-temp-families.php';

$method = new ReflectionMethod('Olama_Users_Temp_Families', 'validate_members');
$method->setAccessible(true);

$valid = $method->invoke(null, array(array('name' => 'Local Member', 'grade_id' => 8, 'section_id' => 12)));
assert_true(is_array($valid) && 1 === count($valid), 'A local member with a matching grade and section should be valid.');
assert_true(0 === strpos($valid[0]['student_uid'], 'LOCAL-MEMBER-'), 'A stable local member namespace should be used.');

$mismatch = $method->invoke(null, array(array('name' => 'Wrong Grade', 'grade_id' => 9, 'section_id' => 12)));
assert_true($mismatch instanceof WP_Error && 'temp_family_member_section_invalid' === $mismatch->get_error_code(), 'A section from another grade must be rejected.');

$missing = $method->invoke(null, array());
assert_true($missing instanceof WP_Error && 'temp_family_members_required' === $missing->get_error_code(), 'At least one local member must be required.');

echo "Temp Family member tests passed.\n";
