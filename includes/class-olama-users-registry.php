<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Users_Registry {
    private static $modules = array();
    private static $loaded = false;

    public static function load() {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        do_action('olama_users_register_modules');
        $administrator = get_role('administrator');
        if ($administrator) {
            foreach (array_keys(self::capabilities()) as $capability) {
                $administrator->add_cap($capability);
            }
        }
    }

    public static function register(array $definition) {
        $id = isset($definition['id']) ? sanitize_key($definition['id']) : '';
        $capability = isset($definition['capability']) ? sanitize_key($definition['capability']) : '';
        if (!$id || !$capability || isset(self::$modules[$id])) {
            return false;
        }
        $definition['id'] = $id;
        $definition['capability'] = $capability;
        $definition['label'] = isset($definition['label']) ? (string) $definition['label'] : $id;
        $definition['plugin'] = isset($definition['plugin']) ? sanitize_key($definition['plugin']) : '';
        $definition['items'] = isset($definition['items']) && is_array($definition['items']) ? $definition['items'] : array();
        $definition['default_grant'] = !empty($definition['default_grant']);
        self::$modules[$id] = $definition;
        return true;
    }

    public static function all() {
        self::load();
        return self::$modules;
    }

    public static function get($id) {
        $modules = self::all();
        $id = sanitize_key($id);
        return isset($modules[$id]) ? $modules[$id] : null;
    }

    public static function module_capabilities($id) {
        $module = self::get($id);
        if (!$module) {
            return array();
        }
        $caps = array($module['capability'] => $module['label']);
        foreach ($module['items'] as $item) {
            self::collect_item_capabilities($item, $caps);
        }
        return $caps;
    }

    public static function capabilities() {
        $caps = array();
        foreach (self::all() as $module) {
            $caps[$module['capability']] = $module['label'];
            foreach ($module['items'] as $item) {
                self::collect_item_capabilities($item, $caps);
            }
        }
        return $caps;
    }

    public static function default_capabilities() {
        $caps = array();
        foreach (self::all() as $module) {
            if (!empty($module['default_grant'])) {
                $caps[] = $module['capability'];
            }
        }
        return array_values(array_unique(array_filter(array_map('sanitize_key', $caps))));
    }

    private static function collect_item_capabilities(array $item, array &$caps) {
        if (!empty($item['capability'])) {
            $caps[sanitize_key($item['capability'])] = isset($item['label']) ? (string) $item['label'] : $item['capability'];
        }
        foreach (array('tabs', 'actions', 'items') as $child_key) {
            if (empty($item[$child_key]) || !is_array($item[$child_key])) {
                continue;
            }
            foreach ($item[$child_key] as $child) {
                self::collect_item_capabilities($child, $caps);
            }
        }
    }
}
