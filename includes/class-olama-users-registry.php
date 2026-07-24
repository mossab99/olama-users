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

    /**
     * Merge the active OLAMA admin navigation into the declared registry.
     *
     * WordPress has no registry for page tabs, so plugins still declare tabs
     * through olama_users_register_module(). Menus and submenus, however, are
     * authoritative in the global admin menu and can be included automatically.
     */
    public static function discover_admin_menus() {
        if (!is_admin()) {
            return;
        }

        self::load();

        global $menu, $submenu;
        if (!is_array($menu)) {
            return;
        }

        foreach ($menu as $menu_entry) {
            if (!is_array($menu_entry) || empty($menu_entry[2])) {
                continue;
            }

            $menu_slug = self::normalize_menu_slug($menu_entry[2]);
            $capability = isset($menu_entry[1]) ? sanitize_key($menu_entry[1]) : '';
            if (!$menu_slug) {
                continue;
            }

            $module_id = self::find_module_for_menu($menu_slug, $capability);
            if (!$module_id) {
                if (
                    !self::is_delegable_plugin_capability($capability) ||
                    (0 !== strpos($menu_slug, 'olama-') && 0 !== strpos($menu_slug, 'olama_'))
                ) {
                    continue;
                }
                $module_id = 'admin_' . sanitize_key($menu_slug);
                self::register(array(
                    'id' => $module_id,
                    'plugin' => $menu_slug,
                    'label' => self::plain_menu_label(isset($menu_entry[0]) ? $menu_entry[0] : $menu_slug),
                    'capability' => $capability,
                    'items' => array(),
                ));
            }

            if (empty(self::$modules[$module_id])) {
                continue;
            }

            $submenu_entries = isset($submenu[$menu_entry[2]]) && is_array($submenu[$menu_entry[2]])
                ? $submenu[$menu_entry[2]]
                : array();

            foreach ($submenu_entries as $submenu_entry) {
                if (!is_array($submenu_entry) || empty($submenu_entry[2])) {
                    continue;
                }
                $item_capability = isset($submenu_entry[1]) ? sanitize_key($submenu_entry[1]) : '';
                if (!self::is_delegable_plugin_capability($item_capability)) {
                    continue;
                }
                $item_slug = self::normalize_menu_slug($submenu_entry[2]);
                $item_id = sanitize_key($module_id . '.menu.' . $item_slug);
                $item_label = self::plain_menu_label(isset($submenu_entry[0]) ? $submenu_entry[0] : $item_slug);
                if (
                    !$item_id ||
                    self::module_has_menu_item(self::$modules[$module_id]['items'], $item_slug, $item_label, $item_capability)
                ) {
                    continue;
                }
                self::$modules[$module_id]['items'][] = array(
                    'id' => $item_id,
                    'type' => 'submenu',
                    'label' => $item_label,
                    'capability' => $item_capability,
                    'url' => admin_url('admin.php?page=' . rawurlencode($item_slug)),
                );
            }

            $administrator = get_role('administrator');
            if ($administrator) {
                foreach (array_keys(self::module_capabilities($module_id)) as $registered_capability) {
                    $administrator->add_cap($registered_capability);
                }
            }
        }
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
        foreach (array('submenus', 'tabs', 'actions', 'items') as $child_key) {
            if (empty($item[$child_key]) || !is_array($item[$child_key])) {
                continue;
            }
            foreach ($item[$child_key] as $child) {
                self::collect_item_capabilities($child, $caps);
            }
        }
    }

    private static function find_module_for_menu($menu_slug, $capability) {
        foreach (self::$modules as $id => $module) {
            $plugin = isset($module['plugin']) ? self::normalize_menu_slug($module['plugin']) : '';
            if ($plugin && ($plugin === $menu_slug || 0 === strpos($menu_slug, $plugin) || 0 === strpos($plugin, $menu_slug))) {
                return $id;
            }
        }
        foreach (self::$modules as $id => $module) {
            if (isset($module['capability']) && sanitize_key($module['capability']) === $capability) {
                return $id;
            }
        }
        return '';
    }

    private static function normalize_menu_slug($slug) {
        $slug = (string) $slug;
        if (false !== strpos($slug, '?')) {
            $query = wp_parse_url($slug, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $args);
                if (!empty($args['page'])) {
                    $slug = $args['page'];
                }
            }
        }
        return sanitize_key(basename($slug, '.php'));
    }

    private static function plain_menu_label($label) {
        $label = wp_strip_all_tags((string) $label);
        $label = preg_replace('/\s+/', ' ', $label);
        return trim($label);
    }

    private static function is_delegable_plugin_capability($capability) {
        return $capability && (
            0 === strpos($capability, 'olama_') ||
            0 === strpos($capability, 'os_') ||
            0 === strpos($capability, 'manage_olama_')
        );
    }

    private static function module_has_menu_item(array $items, $menu_slug, $label, $capability) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_capability = isset($item['capability']) ? sanitize_key($item['capability']) : '';
            $item_label = isset($item['label']) ? self::plain_menu_label($item['label']) : '';
            $item_slug = !empty($item['url']) ? self::normalize_menu_slug($item['url']) : '';
            if (
                $item_capability === $capability &&
                (($item_slug && $item_slug === $menu_slug) || (!$item_slug && $item_label === $label))
            ) {
                return true;
            }
            foreach (array('submenus', 'tabs', 'actions', 'items') as $child_key) {
                if (
                    !empty($item[$child_key]) &&
                    is_array($item[$child_key]) &&
                    self::module_has_menu_item($item[$child_key], $menu_slug, $label, $capability)
                ) {
                    return true;
                }
            }
        }
        return false;
    }
}
