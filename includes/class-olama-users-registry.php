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
        self::$modules[$id] = $definition;
        return true;
    }

    public static function all() {
        self::load();
        return self::$modules;
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
