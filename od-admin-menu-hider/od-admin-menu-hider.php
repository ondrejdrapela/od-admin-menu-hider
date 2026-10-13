<?php
/**
 * Plugin Name: OD Skrývač admin menu
 * Plugin URI:  https://www.odmarketing.cz
 * Description: Skryje vybrané položky levého admin menu a umožňuje měnit pořadí položek i podpoložek. Nastavuje se zvlášť pro každou roli. Nabídku generuje dynamicky podle aktuálního stavu menu.
 * Version:     1.5.0
 * Author:      OD Marketing
 * Author URI:  https://www.odmarketing.cz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: od-admin-menu-hider
 */

if (!defined('ABSPATH')) {
    exit;
}

// Automatické aktualizace z privátního GitHub repozitáře.
require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$od_admin_menu_hider_updater = PucFactory::buildUpdateChecker(
    'https://github.com/ondrejdrapela/od-admin-menu-hider/',
    __FILE__,
    'od-admin-menu-hider'
);
$od_admin_menu_hider_updater->getVcsApi()->enableReleaseAssets();
if (defined('ODM_GITHUB_TOKEN')) {
    $od_admin_menu_hider_updater->setAuthentication(ODM_GITHUB_TOKEN);
}

class OD_Admin_Menu_Hider {

    const OPTION     = 'od_amh_hidden';
    const OPTION_BAR = 'od_amh_bar_items';
    /** Uzly horní lišty zachycené při návštěvě frontendu administrátorem. */
    const OPTION_BAR_FRONT = 'od_amh_bar_front';
    const PAGE_SLUG  = 'od-admin-menu-hider';
    const NONCE      = 'od_amh_save';

    /** Parametr v URL, kterým si dočasně vynutíš zobrazení skrytého levého menu. */
    const ESCAPE_ARG = 'amh_menu';

    /** @var array Snapshot horního menu pořízený PŘED skrytím */
    private $snapshot_menu = array();

    /** @var array Snapshot submenu pořízený PŘED skrytím */
    private $snapshot_submenu = array();

    /** @var string Hook suffix vlastní stránky */
    private $page_hook = '';

    /** @var array Snapshot uzlů horní lišty pořízený PŘED jejich úpravou */
    private $snapshot_bar = array();

    public function __construct() {
        // Pořadí priorit:
        // 100   -> zaregistrujeme vlastní stránku
        // 9998  -> snapshot KOMPLETNÍHO menu (ještě nic není skryté)
        // 9999  -> skryjeme vybrané položky
        // 10000 -> přeuspořádáme podpoložky (už jen ty viditelné)
        add_action('admin_menu', array($this, 'register_page'), 100);
        add_action('admin_menu', array($this, 'take_snapshot'), 9998);
        add_action('admin_menu', array($this, 'apply_hiding'), 9999);
        add_action('admin_menu', array($this, 'apply_submenu_order'), 10000);
        add_action('admin_post_' . self::NONCE, array($this, 'handle_save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Vlastní pořadí položek horní úrovně (oficiální cesta přes filtr)
        add_filter('custom_menu_order', array($this, 'enable_custom_order'));
        add_filter('menu_order', array($this, 'apply_custom_order'));

        // Horní lišta:
        // admin_bar_menu 10000          -> vlastní položky
        // wp_before_admin_bar_render 9998 -> snapshot všech uzlů (ještě nic není skryté ani přepsané)
        // wp_before_admin_bar_render 9999 -> skrytí, přejmenování a vlastní ikony
        // Snapshot a úpravy běží až těsně před vykreslením, aby zachytily i uzly,
        // které jádro nebo jiné pluginy přidávají pozdě (např. Hledat na frontendu, priorita 9999).
        add_action('admin_bar_menu', array($this, 'apply_bar_custom_items'), 10000);
        add_action('wp_before_admin_bar_render', array($this, 'take_bar_snapshot'), 9998);
        add_action('wp_before_admin_bar_render', array($this, 'apply_bar_changes'), 9999);

        // CSS pro skryté levé menu a pro vlastní ikony v liště
        add_action('admin_head', array($this, 'print_front_styles'));
        add_action('wp_head', array($this, 'print_front_styles'));

        // Odkaz na nastavení v seznamu pluginů
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'action_links'));
    }

    /* ---------------------------------------------------------------------
     * Role a nastavení
     * ------------------------------------------------------------------- */

    private function get_roles() {
        if (!function_exists('wp_roles')) {
            return array();
        }
        return wp_roles()->get_names();
    }

    /** Kompletní uložené nastavení pro všechny role, s migrací staré struktury. */
    private function get_all_settings() {
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        // Migrace z verze 1.0 (neroleová struktura)
        if (isset($stored['top']) || isset($stored['sub'])) {
            $legacy = array(
                'top'       => isset($stored['top']) && is_array($stored['top']) ? $stored['top'] : array(),
                'sub'       => isset($stored['sub']) && is_array($stored['sub']) ? $stored['sub'] : array(),
                'order'     => array(),
                'order_sub' => array(),
            );
            $migrated = array();
            foreach (array_keys($this->get_roles()) as $role_key) {
                $migrated[$role_key] = $legacy;
            }
            update_option(self::OPTION, $migrated);
            return $migrated;
        }

        return $stored;
    }

    /** Nastavení pro jednu roli s výchozími hodnotami. */
    private function get_role_settings($role_key) {
        $all = $this->get_all_settings();
        $r   = isset($all[$role_key]) && is_array($all[$role_key]) ? $all[$role_key] : array();
        return array(
            'top'        => isset($r['top']) && is_array($r['top']) ? $r['top'] : array(),
            'sub'        => isset($r['sub']) && is_array($r['sub']) ? $r['sub'] : array(),
            'order'      => isset($r['order']) && is_array($r['order']) ? $r['order'] : array(),
            'order_sub'  => isset($r['order_sub']) && is_array($r['order_sub']) ? $r['order_sub'] : array(),
            'hide_menu'  => !empty($r['hide_menu']),
            'bar_hidden' => isset($r['bar_hidden']) && is_array($r['bar_hidden']) ? $r['bar_hidden'] : array(),
            'bar_edit'   => isset($r['bar_edit']) && is_array($r['bar_edit']) ? $r['bar_edit'] : array(),
        );
    }

    /* ---------------------------------------------------------------------
     * Vlastní položky horní lišty (globální seznam, role se u nich zaškrtávají)
     * ------------------------------------------------------------------- */

    /** Všechny vlastní položky lišty, v uloženém pořadí. */
    private function get_bar_items() {
        $stored = get_option(self::OPTION_BAR, array());
        if (!is_array($stored)) {
            return array();
        }

        $items = array();
        foreach ($stored as $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }
            $items[] = array(
                'id'      => isset($item['id']) ? (string) $item['id'] : '',
                'icon'    => isset($item['icon']) ? (string) $item['icon'] : '',
                'title'   => (string) $item['title'],
                'url'     => isset($item['url']) ? (string) $item['url'] : '',
                'new_tab' => !empty($item['new_tab']),
                'roles'   => isset($item['roles']) && is_array($item['roles']) ? $item['roles'] : array(),
            );
        }
        return $items;
    }

    /** Vlastní položky, které se mají zobrazit přihlášenému uživateli. */
    private function get_bar_items_for_user() {
        $roles = $this->current_user_roles();
        $out   = array();
        foreach ($this->get_bar_items() as $item) {
            if (array_intersect($item['roles'], $roles)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function current_user_roles() {
        $user = wp_get_current_user();
        return ($user && !empty($user->roles)) ? (array) $user->roles : array();
    }

    /** Sjednocené skryté položky napříč rolemi přihlášeného uživatele. */
    private function get_effective_hidden() {
        $top = array();
        $sub = array();
        foreach ($this->current_user_roles() as $role_key) {
            $rs  = $this->get_role_settings($role_key);
            $top = array_merge($top, $rs['top']);
            $sub = array_merge($sub, $rs['sub']);
        }
        return array('top' => array_unique($top), 'sub' => array_unique($sub));
    }

    /** Má se přihlášenému uživateli skrýt celé levé menu? Stačí jedna role. */
    private function should_hide_menu() {
        foreach ($this->current_user_roles() as $role_key) {
            $rs = $this->get_role_settings($role_key);
            if ($rs['hide_menu']) {
                return true;
            }
        }
        return false;
    }

    /** Sjednocené skryté uzly horní lišty napříč rolemi uživatele. */
    private function get_effective_bar_hidden() {
        $ids = array();
        foreach ($this->current_user_roles() as $role_key) {
            $rs  = $this->get_role_settings($role_key);
            $ids = array_merge($ids, $rs['bar_hidden']);
        }
        return array_unique($ids);
    }

    /** Úpravy uzlů lišty (název, ikona) z první role, která daný uzel definuje. */
    private function get_effective_bar_edit() {
        $result = array();
        foreach ($this->current_user_roles() as $role_key) {
            $rs = $this->get_role_settings($role_key);
            foreach ($rs['bar_edit'] as $node_id => $edit) {
                if (!isset($result[$node_id]) && is_array($edit)) {
                    $result[$node_id] = $edit;
                }
            }
        }
        return $result;
    }

    /** Pořadí horní úrovně z první role uživatele, která ho má uložené. */
    private function get_effective_order() {
        foreach ($this->current_user_roles() as $role_key) {
            $rs = $this->get_role_settings($role_key);
            if (!empty($rs['order'])) {
                return $rs['order'];
            }
        }
        return array();
    }

    /** Pořadí podpoložek, per rodič, první role která daného rodiče definuje. */
    private function get_effective_order_sub() {
        $result = array();
        foreach ($this->current_user_roles() as $role_key) {
            $rs = $this->get_role_settings($role_key);
            foreach ($rs['order_sub'] as $parent => $children) {
                if (!isset($result[$parent]) && is_array($children) && $children) {
                    $result[$parent] = $children;
                }
            }
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     * Aplikace na živé menu
     * ------------------------------------------------------------------- */

    public function take_snapshot() {
        global $menu, $submenu;
        $this->snapshot_menu    = is_array($menu) ? $menu : array();
        $this->snapshot_submenu = is_array($submenu) ? $submenu : array();
    }

    public function apply_hiding() {
        $hidden = $this->get_effective_hidden();

        foreach ($hidden['top'] as $slug) {
            if ($slug === self::PAGE_SLUG) {
                continue; // vlastní stránku nikdy neskrýváme
            }
            remove_menu_page($slug);
        }
        foreach ($hidden['sub'] as $combo) {
            $parts = explode('||', $combo, 2);
            if (count($parts) === 2) {
                remove_submenu_page($parts[0], $parts[1]);
            }
        }
    }

    public function enable_custom_order($enabled) {
        return $this->get_effective_order() ? true : $enabled;
    }

    public function apply_custom_order($menu_ord) {
        $order = $this->get_effective_order();
        if (!$order || !is_array($menu_ord)) {
            return $menu_ord;
        }
        $desired = array_values(array_intersect($order, $menu_ord));
        $rest    = array_values(array_diff($menu_ord, $desired));
        return array_merge($desired, $rest);
    }

    /**
     * Přeuspořádá podpoložky přímo v globálním poli $submenu.
     * WordPress pro submenu nemá filtr, vykresluje je v pořadí pole,
     * a pokud je někde ksort, sekvenční klíče 0..n pořadí zachovají.
     */
    public function apply_submenu_order() {
        global $submenu;

        $orders = $this->get_effective_order_sub();
        if (!$orders || !is_array($submenu)) {
            return;
        }

        foreach ($orders as $parent => $desired) {
            if (empty($submenu[$parent]) || !is_array($submenu[$parent]) || !is_array($desired)) {
                continue;
            }

            $items = array_values($submenu[$parent]);

            // index podle slugu (může se teoreticky opakovat, proto fronta)
            $by_slug = array();
            foreach ($items as $i => $item) {
                if (isset($item[2])) {
                    $by_slug[$item[2]][] = $i;
                }
            }

            $used = array();
            $new  = array();

            foreach ($desired as $slug) {
                if (!empty($by_slug[$slug])) {
                    $idx        = array_shift($by_slug[$slug]);
                    $new[]      = $items[$idx];
                    $used[$idx] = true;
                }
            }
            // položky, které v uloženém pořadí nejsou (nové pluginy apod.), na konec
            foreach ($items as $i => $item) {
                if (!isset($used[$i])) {
                    $new[] = $item;
                }
            }

            $submenu[$parent] = array_values($new);
        }
    }

    /* ---------------------------------------------------------------------
     * Horní lišta
     * ------------------------------------------------------------------- */

    /** Snapshot uzlů lišty ještě předtím, než do nich sáhneme. */
    public function take_bar_snapshot() {
        global $wp_admin_bar;
        if (!is_object($wp_admin_bar)) {
            return;
        }
        $nodes = $wp_admin_bar->get_nodes();
        $nodes = is_array($nodes) ? $nodes : array();

        // Naše vlastní položky do seznamu k úpravám nepatří.
        foreach (array_keys($nodes) as $id) {
            if (0 === strpos((string) $id, 'odbar-')) {
                unset($nodes[$id]);
            }
        }
        $this->snapshot_bar = $nodes;

        if (!is_admin() && current_user_can('manage_options')) {
            $this->remember_front_nodes($nodes);
        }
    }

    /**
     * Zapamatuje si uzly lišty z frontendu, aby šly nastavit i v administraci,
     * kde je WordPress vůbec nevytváří. Do databáze zapisuje jen při změně.
     */
    private function remember_front_nodes(array $nodes) {
        $data = array();
        foreach ($nodes as $id => $node) {
            $data[(string) $id] = array(
                'title'  => $this->bar_node_title($node, $id),
                'parent' => isset($node->parent) ? (string) $node->parent : '',
                'group'  => !empty($node->group),
            );
            if (count($data) >= 300) {
                break;
            }
        }

        $stored = get_option(self::OPTION_BAR_FRONT, array());
        if ($stored !== $data) {
            update_option(self::OPTION_BAR_FRONT, $data, false);
        }
    }

    /**
     * Známé uzly jádra WP, které existují jen na frontendu.
     * Názvy jsou pevné, protože třeba "Upravit stránku" se mění podle typu obsahu.
     */
    private function known_front_nodes() {
        return array(
            'site-editor' => array('title' => 'Upravit web', 'parent' => '', 'group' => false),
            'customize'   => array('title' => 'Přizpůsobit', 'parent' => '', 'group' => false),
            'edit'        => array('title' => 'Upravit stránku / příspěvek', 'parent' => '', 'group' => false),
            'search'      => array('title' => 'Hledat', 'parent' => 'top-secondary', 'group' => false),
        );
    }

    /** Složí obsah položky lišty z ikony a názvu. */
    private function bar_title_html($icon, $label) {
        $html = '';
        if ('' !== $icon) {
            $html .= '<span class="ab-icon od-amh-icon dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }
        $html .= '<span class="ab-label">' . esc_html($label) . '</span>';
        return $html;
    }

    /** Skrytí uzlů a přepsání jejich názvů či ikon. */
    public function apply_bar_changes() {
        global $wp_admin_bar;
        if (!is_object($wp_admin_bar)) {
            return;
        }

        foreach ($this->get_effective_bar_hidden() as $node_id) {
            $wp_admin_bar->remove_node($node_id);
        }

        foreach ($this->get_effective_bar_edit() as $node_id => $edit) {
            $icon  = isset($edit['icon']) ? (string) $edit['icon'] : '';
            $label = isset($edit['title']) ? (string) $edit['title'] : '';
            if ('' === $icon && '' === $label) {
                continue;
            }

            $node = $wp_admin_bar->get_node($node_id);
            if (!$node) {
                continue;
            }

            // Prázdný název znamená "nech původní", jen vyčištěný od bublin a spanů.
            if ('' === $label) {
                $label = $this->clean_title($node->title);
            }

            $wp_admin_bar->add_node(array(
                'id'    => $node_id,
                'title' => $this->bar_title_html($icon, $label),
            ));
        }
    }

    /** Přidá do lišty vlastní položky nastavené pro role uživatele. */
    public function apply_bar_custom_items($wp_admin_bar) {
        foreach ($this->get_bar_items_for_user() as $item) {
            $meta = array();
            if ($item['new_tab']) {
                $meta['target'] = '_blank';
                $meta['rel']    = 'noopener noreferrer';
            }

            $wp_admin_bar->add_node(array(
                'id'     => $item['id'],
                'title'  => $this->bar_title_html($item['icon'], $item['title']),
                'href'   => $this->resolve_url($item['url']),
                'meta'   => $meta,
            ));
        }
    }

    /**
     * Doplní adresu. Když uživatel napíše jen "edit.php?post_type=faktura",
     * bereme to jako adresu v administraci. Absolutní odkazy necháváme být.
     */
    private function resolve_url($url) {
        $url = trim((string) $url);
        if ('' === $url) {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $url) || 0 === strpos($url, '/')) {
            return esc_url_raw($url);
        }
        return esc_url_raw(admin_url($url));
    }

    /* ---------------------------------------------------------------------
     * Styly (skryté levé menu, vlastní ikony v liště)
     * ------------------------------------------------------------------- */

    /** Je právě aktivní nouzové zobrazení menu přes URL? */
    private function escape_active() {
        return isset($_GET[self::ESCAPE_ARG]);
    }

    public function print_front_styles() {
        $css = '';

        if (is_admin() && $this->should_hide_menu() && !$this->escape_active()) {
            $css .= '#adminmenumain, #adminmenuback, #adminmenuwrap { display:none !important; }';
            $css .= '#wpcontent, #wpfooter, .auto-fold #wpcontent, .auto-fold #wpfooter { margin-left:0 !important; }';
            $css .= '#wpbody-content { padding-bottom:65px; }';
            $css .= '@media screen and (max-width:782px){ #wpcontent, #wpfooter { margin-left:0 !important; } }';
        }

        // U položek s vlastní ikonou potlačíme původní ikonu z motivu administrace.
        $ids = array();
        foreach ($this->get_effective_bar_edit() as $node_id => $edit) {
            if (!empty($edit['icon'])) {
                $ids[] = '#wpadminbar #wp-admin-bar-' . sanitize_html_class($node_id) . ' > .ab-item:before';
            }
        }
        if ($ids) {
            $css .= implode(',', $ids) . ' { content:none !important; display:none !important; }';
        }

        $css .= '#wpadminbar .od-amh-icon { float:left; font-family:dashicons !important; font-size:20px; line-height:1; width:20px; height:20px; margin-right:6px; }';
        $css .= '#wpadminbar .od-amh-icon:before { font-family:dashicons !important; }';

        if ('' === $css) {
            return;
        }
        echo '<style id="od-amh-style">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /* ---------------------------------------------------------------------
     * Administrace
     * ------------------------------------------------------------------- */

    public function action_links($links) {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        array_unshift($links, '<a href="' . esc_url($url) . '">Nastavení</a>');
        return $links;
    }

    public function register_page() {
        $this->page_hook = add_menu_page(
            'Skrývač menu',
            'Skrývač menu',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_page'),
            'dashicons-hidden',
            81
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== $this->page_hook) {
            return;
        }
        wp_enqueue_script('jquery-ui-sortable');
    }

    private function selected_role() {
        $roles = $this->get_roles();
        $req   = isset($_GET['amh_role']) ? sanitize_text_field(wp_unslash($_GET['amh_role'])) : '';
        if ($req && isset($roles[$req])) {
            return $req;
        }
        if (isset($roles['administrator'])) {
            return 'administrator';
        }
        $keys = array_keys($roles);
        return !empty($keys) ? $keys[0] : '';
    }

    public function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění.');
        }
        check_admin_referer(self::NONCE);

        $roles = $this->get_roles();
        $role  = isset($_POST['amh_role']) ? sanitize_text_field(wp_unslash($_POST['amh_role'])) : '';
        if (!$role || !isset($roles[$role])) {
            wp_die('Neplatná role.');
        }

        $top = array();
        if (!empty($_POST['hidden_top']) && is_array($_POST['hidden_top'])) {
            foreach ($_POST['hidden_top'] as $slug) {
                $top[] = sanitize_text_field(wp_unslash($slug));
            }
        }

        $sub = array();
        if (!empty($_POST['hidden_sub']) && is_array($_POST['hidden_sub'])) {
            foreach ($_POST['hidden_sub'] as $combo) {
                $sub[] = sanitize_text_field(wp_unslash($combo));
            }
        }

        $order     = array();
        $order_sub = array();
        if (!empty($_POST['order_data'])) {
            $decoded = json_decode(wp_unslash($_POST['order_data']), true);
            if (is_array($decoded)) {
                if (!empty($decoded['top']) && is_array($decoded['top'])) {
                    foreach ($decoded['top'] as $slug) {
                        if (is_string($slug)) {
                            $order[] = sanitize_text_field($slug);
                        }
                    }
                }
                if (!empty($decoded['sub']) && is_array($decoded['sub'])) {
                    foreach ($decoded['sub'] as $parent => $children) {
                        if (!is_string($parent) || !is_array($children)) {
                            continue;
                        }
                        $clean = array();
                        foreach ($children as $child) {
                            if (is_string($child)) {
                                $clean[] = sanitize_text_field($child);
                            }
                        }
                        if ($clean) {
                            $order_sub[sanitize_text_field($parent)] = $clean;
                        }
                    }
                }
            }
        }

        $bar_hidden = array();
        if (!empty($_POST['bar_hidden']) && is_array($_POST['bar_hidden'])) {
            foreach ($_POST['bar_hidden'] as $node_id) {
                $bar_hidden[] = sanitize_text_field(wp_unslash($node_id));
            }
        }

        $bar_edit = array();
        if (!empty($_POST['bar_edit']) && is_array($_POST['bar_edit'])) {
            foreach ($_POST['bar_edit'] as $node_id => $edit) {
                if (!is_array($edit)) {
                    continue;
                }
                $title = isset($edit['title']) ? sanitize_text_field(wp_unslash($edit['title'])) : '';
                $icon  = isset($edit['icon']) ? $this->sanitize_icon(wp_unslash($edit['icon'])) : '';
                if ('' === $title && '' === $icon) {
                    continue; // nic nepřepisujeme, nemá smysl to ukládat
                }
                $bar_edit[sanitize_text_field(wp_unslash($node_id))] = array(
                    'title' => $title,
                    'icon'  => $icon,
                );
            }
        }

        $all        = $this->get_all_settings();
        $all[$role] = array(
            'top'        => $top,
            'sub'        => $sub,
            'order'      => $order,
            'order_sub'  => $order_sub,
            'hide_menu'  => !empty($_POST['hide_menu']),
            'bar_hidden' => $bar_hidden,
            'bar_edit'   => $bar_edit,
        );
        update_option(self::OPTION, $all);

        update_option(self::OPTION_BAR, $this->collect_bar_items_from_post());

        wp_safe_redirect(add_query_arg(
            array('page' => self::PAGE_SLUG, 'amh_role' => $role, 'updated' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    /** Nabídka ikon pro výběr (dashicons, které jsou v administraci vždy k dispozici). */
    private function dashicon_choices() {
        return array(
            'dashicons-admin-home', 'dashicons-dashboard', 'dashicons-admin-site', 'dashicons-admin-page',
            'dashicons-admin-post', 'dashicons-admin-media', 'dashicons-admin-links', 'dashicons-admin-comments',
            'dashicons-admin-appearance', 'dashicons-admin-plugins', 'dashicons-admin-users', 'dashicons-admin-tools',
            'dashicons-admin-settings', 'dashicons-admin-network', 'dashicons-admin-generic', 'dashicons-admin-collapse',
            'dashicons-admin-customizer', 'dashicons-admin-multisite',
            'dashicons-menu', 'dashicons-list-view', 'dashicons-grid-view', 'dashicons-filter', 'dashicons-sort',
            'dashicons-search', 'dashicons-screenoptions', 'dashicons-hidden', 'dashicons-visibility',
            'dashicons-cart', 'dashicons-products', 'dashicons-store', 'dashicons-money', 'dashicons-money-alt',
            'dashicons-bank', 'dashicons-chart-bar', 'dashicons-chart-line', 'dashicons-chart-pie',
            'dashicons-chart-area', 'dashicons-businessman', 'dashicons-businesswoman', 'dashicons-groups',
            'dashicons-id', 'dashicons-id-alt', 'dashicons-portfolio', 'dashicons-clipboard', 'dashicons-analytics',
            'dashicons-megaphone', 'dashicons-tickets-alt', 'dashicons-awards', 'dashicons-printer',
            'dashicons-media-document', 'dashicons-media-spreadsheet', 'dashicons-media-text', 'dashicons-media-archive',
            'dashicons-media-default', 'dashicons-media-code', 'dashicons-media-interactive', 'dashicons-pdf',
            'dashicons-download', 'dashicons-upload', 'dashicons-backup', 'dashicons-update', 'dashicons-database',
            'dashicons-cloud', 'dashicons-archive', 'dashicons-category', 'dashicons-tag', 'dashicons-book',
            'dashicons-book-alt', 'dashicons-index-card', 'dashicons-sticky', 'dashicons-paperclip',
            'dashicons-calendar', 'dashicons-calendar-alt', 'dashicons-clock', 'dashicons-schedule',
            'dashicons-email', 'dashicons-email-alt', 'dashicons-phone', 'dashicons-format-chat',
            'dashicons-testimonial', 'dashicons-buddicons-activity', 'dashicons-share', 'dashicons-rss',
            'dashicons-location', 'dashicons-location-alt', 'dashicons-building', 'dashicons-palmtree',
            'dashicons-car', 'dashicons-truck', 'dashicons-airplane', 'dashicons-bell',
            'dashicons-star-filled', 'dashicons-heart', 'dashicons-flag', 'dashicons-thumbs-up',
            'dashicons-yes', 'dashicons-yes-alt', 'dashicons-no', 'dashicons-no-alt', 'dashicons-dismiss',
            'dashicons-warning', 'dashicons-info', 'dashicons-marker', 'dashicons-lightbulb', 'dashicons-sos',
            'dashicons-lock', 'dashicons-unlock', 'dashicons-privacy', 'dashicons-shield', 'dashicons-key',
            'dashicons-edit', 'dashicons-edit-large', 'dashicons-trash', 'dashicons-plus', 'dashicons-plus-alt',
            'dashicons-minus', 'dashicons-insert', 'dashicons-saved', 'dashicons-external',
            'dashicons-admin-links', 'dashicons-art', 'dashicons-camera', 'dashicons-format-image',
            'dashicons-format-gallery', 'dashicons-format-video', 'dashicons-format-audio', 'dashicons-video-alt3',
            'dashicons-images-alt2', 'dashicons-desktop', 'dashicons-smartphone', 'dashicons-tablet',
            'dashicons-laptop', 'dashicons-wordpress', 'dashicons-networking', 'dashicons-performance',
            'dashicons-hammer', 'dashicons-forms', 'dashicons-feedback', 'dashicons-text-page',
            'dashicons-welcome-write-blog', 'dashicons-welcome-view-site', 'dashicons-welcome-widgets-menus',
            'dashicons-welcome-learn-more', 'dashicons-align-left', 'dashicons-align-center', 'dashicons-align-right',
            'dashicons-arrow-up-alt', 'dashicons-arrow-down-alt', 'dashicons-arrow-left-alt', 'dashicons-arrow-right-alt',
            'dashicons-controls-play', 'dashicons-randomize', 'dashicons-redo', 'dashicons-undo',
            'dashicons-tide', 'dashicons-universal-access', 'dashicons-translation', 'dashicons-carrot',
            'dashicons-food', 'dashicons-coffee', 'dashicons-drumstick', 'dashicons-pets', 'dashicons-superhero',
        );
    }

    /** Povolíme jen platný název dashicon třídy. */
    private function sanitize_icon($icon) {
        $icon = sanitize_html_class((string) $icon);
        return (0 === strpos($icon, 'dashicons-')) ? $icon : '';
    }

    /** Přečte vlastní položky lišty z odeslaného formuláře, v pořadí z přetahování. */
    private function collect_bar_items_from_post() {
        $raw = (!empty($_POST['bar_item']) && is_array($_POST['bar_item'])) ? $_POST['bar_item'] : array();
        if (!$raw) {
            return array();
        }

        // Pořadí řádků po přetažení; co v něm chybí, doplníme na konec.
        $order = array();
        if (!empty($_POST['bar_order'])) {
            $decoded = json_decode(wp_unslash($_POST['bar_order']), true);
            if (is_array($decoded)) {
                foreach ($decoded as $key) {
                    if (isset($raw[$key]) && !in_array((string) $key, $order, true)) {
                        $order[] = (string) $key;
                    }
                }
            }
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, $order, true)) {
                $order[] = (string) $key;
            }
        }

        $roles = $this->get_roles();
        $items = array();
        $used  = array();

        foreach ($order as $key) {
            $row   = is_array($raw[$key]) ? $raw[$key] : array();
            $title = isset($row['title']) ? sanitize_text_field(wp_unslash($row['title'])) : '';
            if ('' === $title) {
                continue; // prázdný název = smazaný řádek
            }

            $id = isset($row['id']) ? sanitize_html_class(wp_unslash($row['id'])) : '';
            if ('' === $id || 0 !== strpos($id, 'odbar-') || isset($used[$id])) {
                $id = 'odbar-' . wp_generate_password(8, false, false);
            }
            $used[$id] = true;

            $item_roles = array();
            if (!empty($row['roles']) && is_array($row['roles'])) {
                foreach ($row['roles'] as $role_key) {
                    $role_key = sanitize_text_field(wp_unslash($role_key));
                    if (isset($roles[$role_key])) {
                        $item_roles[] = $role_key;
                    }
                }
            }

            $items[] = array(
                'id'      => $id,
                'icon'    => isset($row['icon']) ? $this->sanitize_icon(wp_unslash($row['icon'])) : '',
                'title'   => $title,
                'url'     => isset($row['url']) ? esc_url_raw(trim(wp_unslash($row['url'])), array('http', 'https')) : '',
                'new_tab' => !empty($row['new_tab']),
                'roles'   => $item_roles,
            );
        }

        return $items;
    }

    /**
     * Uzly horní lišty jako ploché pole id => ['title'=>..., 'depth'=>..., 'front'=>bool].
     * Základ je snapshot z administrace, doplněný o uzly, které existují jen na frontendu
     * (zapamatované z návštěvy webu + pevný seznam známých uzlů jádra).
     * Skupiny (neviditelné obaly) přeskakujeme, jejich děti napojíme o úroveň výš.
     */
    private function bar_nodes() {
        $all   = array();
        $front = array();

        foreach ($this->snapshot_bar as $id => $node) {
            $all[(string) $id] = array(
                'title'  => $this->bar_node_title($node, $id),
                'parent' => isset($node->parent) ? (string) $node->parent : '',
                'group'  => !empty($node->group),
            );
        }

        $stored = get_option(self::OPTION_BAR_FRONT, array());
        $stored = is_array($stored) ? $stored : array();
        $known  = $this->known_front_nodes();

        foreach (array($stored, $known) as $source) {
            foreach ($source as $id => $node) {
                $id = (string) $id;
                if (isset($all[$id]) && !isset($front[$id])) {
                    continue; // existuje i v administraci, nejde o čistě frontendový uzel
                }
                if (!is_array($node) || 0 === strpos($id, 'odbar-')) {
                    continue;
                }
                $all[$id] = array(
                    'title'  => isset($node['title']) && '' !== $node['title'] ? (string) $node['title'] : $id,
                    'parent' => isset($node['parent']) ? (string) $node['parent'] : '',
                    'group'  => !empty($node['group']),
                );
                $front[$id] = true;
            }
        }
        // Známé uzly mají přednost s pevným názvem (např. Hledat má v liště místo názvu formulář).
        foreach ($known as $id => $node) {
            if (isset($front[$id])) {
                $all[$id]['title'] = $node['title'];
            }
        }

        $children = array();
        $groups   = array();

        foreach ($all as $id => $node) {
            if ($node['group']) {
                $groups[$id] = true;
            }
        }

        foreach ($all as $id => $node) {
            $parent = $node['parent'];
            // Skupinu přeskočíme a dítě pověsíme na jejího rodiče.
            $guard = 0;
            while ('' !== $parent && isset($groups[$parent]) && $guard < 10) {
                $parent = isset($all[$parent]) ? $all[$parent]['parent'] : '';
                $guard++;
            }
            if (isset($groups[$id])) {
                continue;
            }
            $children[$parent][] = $id;
        }

        $out = array();
        // Položky nejvyšší úrovně mají rodiče buď prázdného, nebo explicitně 'root'.
        $this->walk_bar_nodes($all, $front, $children, '', 0, $out);
        $this->walk_bar_nodes($all, $front, $children, 'root', 0, $out);

        // Uzly, na které se přes strom nedostaneme (osiřelý rodič), doplníme na konec.
        foreach ($all as $id => $node) {
            if (!isset($groups[$id]) && !isset($out[$id])) {
                $out[$id] = array('title' => $node['title'], 'depth' => 0, 'front' => isset($front[$id]));
            }
        }

        return $out;
    }

    private function walk_bar_nodes(array $all, array $front, array $children, $parent, $depth, array &$out) {
        if (empty($children[$parent]) || $depth > 4) {
            return;
        }
        foreach ($children[$parent] as $id) {
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = array(
                'title' => $all[$id]['title'],
                'depth' => $depth,
                'front' => isset($front[$id]),
            );
            $this->walk_bar_nodes($all, $front, $children, $id, $depth + 1, $out);
        }
    }

    /** Název uzlu lišty; u čistě ikonových položek použijeme jejich id. */
    private function bar_node_title($node, $id) {
        $title = $this->clean_title(isset($node->title) ? $node->title : '');
        return ('' !== $title) ? $title : $id;
    }

    /** Top level položky ze snapshotu jako slug => název (bez oddělovačů). */
    private function top_items() {
        $items = array();
        foreach ($this->snapshot_menu as $item) {
            if (empty($item[2])) {
                continue;
            }
            $slug  = $item[2];
            $title = $this->clean_title(isset($item[0]) ? $item[0] : '');
            if ($title === '' || strpos($slug, 'separator') === 0) {
                continue;
            }
            $items[$slug] = $title;
        }
        return $items;
    }

    /** Podpoložky daného rodiče jako slug => název. */
    private function sub_items($parent) {
        $items = array();
        if (empty($this->snapshot_submenu[$parent]) || !is_array($this->snapshot_submenu[$parent])) {
            return $items;
        }
        foreach ($this->snapshot_submenu[$parent] as $sub) {
            if (empty($sub[2])) {
                continue;
            }
            $title = $this->clean_title(isset($sub[0]) ? $sub[0] : '');
            if ($title === '') {
                continue;
            }
            $items[$sub[2]] = $title;
        }
        return $items;
    }

    /** Seřadí klíče podle uloženého pořadí, zbytek doplní na konec. */
    private function merge_order(array $saved, array $available) {
        $out = array();
        foreach ($saved as $slug) {
            if (isset($available[$slug]) && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        foreach (array_keys($available) as $slug) {
            if (!in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** Tlačítko pro výběr ikony. Vlastní mřížka ikon je na stránce jen jednou, sdílená. */
    private function render_icon_picker($name, $value, array $icons) {
        $value = in_array($value, $icons, true) ? $value : $this->sanitize_icon($value);
        ?>
        <span class="od-amh-icon-field">
            <button type="button" class="button od-amh-icon-btn">
                <span class="dashicons <?php echo esc_attr($value ? $value : 'dashicons-plus-alt2'); ?>"></span>
                <span class="od-amh-icon-name"><?php echo esc_html($value ? $value : 'vybrat'); ?></span>
            </button>
            <input type="hidden" class="od-amh-icon-input" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
        </span>
        <?php
    }

    /** Jeden řádek vlastní položky lišty. $item = null znamená prázdnou šablonu. */
    private function render_bar_item_row($index, $item, array $roles, array $icons) {
        $base    = 'bar_item[' . $index . ']';
        $id      = $item ? $item['id'] : '';
        $icon    = $item ? $item['icon'] : '';
        $title   = $item ? $item['title'] : '';
        $url     = $item ? $item['url'] : '';
        $new_tab = $item ? $item['new_tab'] : false;
        $checked = $item ? $item['roles'] : array('administrator');
        ?>
        <div class="od-amh-item-row" data-key="<?php echo esc_attr($index); ?>">
            <span class="dashicons dashicons-menu od-amh-grip od-amh-item-grip"></span>
            <input type="hidden" name="<?php echo esc_attr($base); ?>[id]" value="<?php echo esc_attr($id); ?>">

            <div class="od-amh-item-fields">
                <div class="od-amh-item-line">
                    <?php $this->render_icon_picker($base . '[icon]', $icon, $icons); ?>
                    <input type="text" name="<?php echo esc_attr($base); ?>[title]" value="<?php echo esc_attr($title); ?>"
                           placeholder="Název (např. Faktury)" class="od-amh-item-title">
                    <input type="text" name="<?php echo esc_attr($base); ?>[url]" value="<?php echo esc_attr($url); ?>"
                           placeholder="Adresa (např. edit.php?post_type=faktura)" class="od-amh-item-url">
                    <button type="button" class="button-link od-amh-item-remove" title="Odebrat položku">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>

                <div class="od-amh-item-line od-amh-item-meta">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($base); ?>[new_tab]" value="1" <?php checked($new_tab); ?>>
                        otevřít v novém okně
                    </label>
                    <span class="od-amh-roles">
                        <strong>Zobrazit rolím:</strong>
                        <?php foreach ($roles as $key => $name) : ?>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($base); ?>[roles][]"
                                       value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $checked, true)); ?>>
                                <?php echo esc_html($name); ?>
                            </label>
                        <?php endforeach; ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    /** Sdílená nabídka ikon, na stránce jen jednou. */
    private function render_icon_palette(array $icons) {
        ?>
        <div id="od-amh-icon-pop" hidden>
            <input type="search" id="od-amh-icon-search" placeholder="Hledat ikonu&hellip;" autocomplete="off">
            <div class="od-amh-icon-grid">
                <button type="button" class="od-amh-icon-opt" data-icon="" title="Bez ikony">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
                <?php foreach ($icons as $icon) : ?>
                    <button type="button" class="od-amh-icon-opt" data-icon="<?php echo esc_attr($icon); ?>"
                            title="<?php echo esc_attr($icon); ?>">
                        <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $roles      = $this->get_roles();
        $role       = $this->selected_role();
        $settings   = $this->get_role_settings($role);
        $hidden_top = array_flip($settings['top']);
        $hidden_sub = array_flip($settings['sub']);

        $top_items     = $this->top_items();
        $ordered_top   = $this->merge_order($settings['order'], $top_items);

        $bar_nodes  = $this->bar_nodes();
        $bar_items  = $this->get_bar_items();
        $bar_hidden = array_flip($settings['bar_hidden']);
        $icons      = $this->dashicon_choices();

        // Předpřipravíme strom pro pravý panel a zároveň JSON pro hidden input.
        $tree = array();
        $json = array('top' => array(), 'sub' => array());
        foreach ($ordered_top as $slug) {
            $subs        = $this->sub_items($slug);
            $saved_subs  = isset($settings['order_sub'][$slug]) ? (array) $settings['order_sub'][$slug] : array();
            $ordered_sub = $subs ? $this->merge_order($saved_subs, $subs) : array();

            $tree[$slug] = array(
                'title' => $top_items[$slug],
                'subs'  => $ordered_sub,
                'names' => $subs,
            );

            $json['top'][] = $slug;
            if ($ordered_sub) {
                $json['sub'][$slug] = $ordered_sub;
            }
        }
        ?>
        <div class="wrap">
            <h1>Skrývač admin menu</h1>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Nastavení uloženo.</p></div>
            <?php endif; ?>

            <p style="max-width:820px">
                Nastavení se ukládá zvlášť pro každou roli. Vyber roli a pak uprav levé menu i horní lištu.
                Změny se projeví uživatelům s danou rolí, u sebe je uvidíš podle své vlastní role.
                Skrytí je pouze vizuální, stránka zůstane dostupná přes přímou URL.
            </p>

            <form method="get" style="margin:16px 0">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <label for="amh_role"><strong>Role:</strong></label>
                <select name="amh_role" id="amh_role" onchange="this.form.submit()">
                    <?php foreach ($roles as $key => $name) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $role); ?>>
                            <?php echo esc_html($name); ?> (<?php echo esc_html($key); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="button">Zobrazit</button></noscript>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::NONCE); ?>">
                <input type="hidden" name="amh_role" value="<?php echo esc_attr($role); ?>">
                <input type="hidden" name="order_data" id="od-amh-order" value="<?php echo esc_attr(wp_json_encode($json)); ?>">
                <input type="hidden" name="bar_order" id="od-amh-bar-order" value="">
                <?php wp_nonce_field(self::NONCE); ?>

                <h2 class="nav-tab-wrapper od-amh-tabs">
                    <a href="#od-amh-tab-menu" class="nav-tab nav-tab-active">Levé menu</a>
                    <a href="#od-amh-tab-bar" class="nav-tab">Horní lišta</a>
                    <a href="#od-amh-tab-custom" class="nav-tab">Vlastní položky lišty</a>
                </h2>

                <!-- ============ Záložka 1: levé menu ============ -->
                <div id="od-amh-tab-menu" class="od-amh-panel">

                    <div class="od-amh-box">
                        <label>
                            <input type="checkbox" name="hide_menu" value="1" <?php checked($settings['hide_menu']); ?>>
                            <strong>Skrýt celé levé menu</strong>
                        </label>
                        <p class="description" style="margin:8px 0 0">
                            Levé menu zmizí i s místem po něm, obsah se roztáhne na celou šířku.
                            Platí i pro administrátora, takže si ho můžeš skrýt i sám sobě.
                            Kdyby ses potřeboval k menu dostat, přidej do adresy
                            <code>&amp;<?php echo esc_html(self::ESCAPE_ARG); ?>=1</code> &ndash; menu se na té stránce dočasně objeví.
                            Záchranná adresa na toto nastavení:<br>
                            <code><?php echo esc_html(admin_url('admin.php?page=' . self::PAGE_SLUG . '&' . self::ESCAPE_ARG . '=1')); ?></code>
                        </p>
                    </div>

                <div style="display:flex;gap:32px;flex-wrap:wrap;align-items:flex-start">

                    <!-- Sekce 1: skrývání -->
                    <div style="flex:1 1 460px;min-width:420px">
                        <h2>Skrýt položky</h2>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:50px">Skrýt</th>
                                    <th>Položka menu</th>
                                    <th>Slug</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($ordered_top as $slug) :
                                $is_self = ($slug === self::PAGE_SLUG); ?>
                                <tr>
                                    <td>
                                        <?php if ($is_self) : ?>
                                            <span title="Tuto stránku nelze skrýt" style="color:#999">&ndash;</span>
                                        <?php else : ?>
                                            <input type="checkbox" name="hidden_top[]" value="<?php echo esc_attr($slug); ?>" <?php checked(isset($hidden_top[$slug])); ?>>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo esc_html($tree[$slug]['title']); ?></strong></td>
                                    <td><code><?php echo esc_html($slug); ?></code></td>
                                </tr>
                                <?php foreach ($tree[$slug]['subs'] as $child_slug) :
                                    $combo = $slug . '||' . $child_slug; ?>
                                    <tr>
                                        <td><input type="checkbox" name="hidden_sub[]" value="<?php echo esc_attr($combo); ?>" <?php checked(isset($hidden_sub[$combo])); ?>></td>
                                        <td style="padding-left:28px">&#8627; <?php echo esc_html($tree[$slug]['names'][$child_slug]); ?></td>
                                        <td><code><?php echo esc_html($child_slug); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sekce 2: pořadí -->
                    <div style="flex:0 1 420px;min-width:360px">
                        <h2>Pořadí položek</h2>
                        <p class="description" style="margin-bottom:12px">
                            Přetáhni položky do požadovaného pořadí. Podpoložky lze přetahovat jen v rámci
                            svého rodiče. Kliknutím na šipku rozbalíš podpoložky.
                        </p>

                        <ul id="od-amh-sortable" class="od-amh-list">
                            <?php foreach ($ordered_top as $slug) : ?>
                                <li data-slug="<?php echo esc_attr($slug); ?>" class="od-amh-item">
                                    <div class="od-amh-row">
                                        <span class="dashicons dashicons-menu od-amh-grip"></span>
                                        <span class="od-amh-title"><?php echo esc_html($tree[$slug]['title']); ?></span>
                                        <code class="od-amh-slug" title="<?php echo esc_attr($slug); ?>"><?php echo esc_html($slug); ?></code>
                                        <?php if ($tree[$slug]['subs']) : ?>
                                            <button type="button" class="button-link od-amh-toggle" aria-expanded="false">
                                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($tree[$slug]['subs']) : ?>
                                        <ul class="od-amh-sub" data-parent="<?php echo esc_attr($slug); ?>" hidden>
                                            <?php foreach ($tree[$slug]['subs'] as $child_slug) : ?>
                                                <li data-slug="<?php echo esc_attr($child_slug); ?>" class="od-amh-subitem">
                                                    <span class="dashicons dashicons-menu od-amh-grip"></span>
                                                    <span class="od-amh-title"><?php echo esc_html($tree[$slug]['names'][$child_slug]); ?></span>
                                                    <code class="od-amh-slug" title="<?php echo esc_attr($child_slug); ?>"><?php echo esc_html($child_slug); ?></code>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                </div><!-- /tab menu -->

                <!-- ============ Záložka 2: horní lišta ============ -->
                <div id="od-amh-tab-bar" class="od-amh-panel" hidden>
                    <p class="description" style="max-width:820px;margin-bottom:12px">
                        Seznam se bere z živé horní lišty, takže obsahuje i položky přidané jinými pluginy.
                        Zaškrtnutím položku skryješ. Vyplněný název přepíše původní, prázdné pole nechá původní.
                        Vlastní ikona nahradí tu původní.
                    </p>
                    <p class="description" style="max-width:820px;margin-bottom:12px">
                        Položky označené <span class="od-amh-front-tag">jen na frontendu</span> WordPress zobrazuje
                        jen při prohlížení webu (např. Upravit web, Přizpůsobit, Upravit stránku). Aby se tu objevily
                        i takové položky z jiných pluginů, otevři si jednou jako administrátor web a tuto stránku načti znovu.
                    </p>

                    <?php if (!$this->snapshot_bar) : ?>
                        <div class="notice notice-warning inline"><p>
                            Horní lišta se teď nevykresluje, takže chybí položky z administrace. Zapni si ji v
                            <a href="<?php echo esc_url(admin_url('profile.php')); ?>">svém profilu</a>
                            (Zobrazovat panel nástrojů při prohlížení webu) a stránku načti znovu.
                        </p></div>
                    <?php endif; ?>

                    <?php if ($bar_nodes) : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:50px">Skrýt</th>
                                    <th>Položka lišty</th>
                                    <th style="width:220px">Vlastní název</th>
                                    <th style="width:200px">Vlastní ikona</th>
                                    <th style="width:160px">ID</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bar_nodes as $node_id => $node) :
                                $edit  = isset($settings['bar_edit'][$node_id]) ? (array) $settings['bar_edit'][$node_id] : array();
                                $e_tit = isset($edit['title']) ? $edit['title'] : '';
                                $e_ico = isset($edit['icon']) ? $edit['icon'] : ''; ?>
                                <tr>
                                    <td><input type="checkbox" name="bar_hidden[]" value="<?php echo esc_attr($node_id); ?>" <?php checked(isset($bar_hidden[$node_id])); ?>></td>
                                    <td style="padding-left:<?php echo (int) (12 + $node['depth'] * 24); ?>px">
                                        <?php if ($node['depth'] > 0) : ?>&#8627; <?php endif; ?>
                                        <?php echo esc_html($node['title']); ?>
                                        <?php if (!empty($node['front'])) : ?>
                                            <span class="od-amh-front-tag">jen na frontendu</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="text" class="regular-text" style="width:100%"
                                               name="bar_edit[<?php echo esc_attr($node_id); ?>][title]"
                                               value="<?php echo esc_attr($e_tit); ?>" placeholder="ponechat původní">
                                    </td>
                                    <td><?php $this->render_icon_picker('bar_edit[' . $node_id . '][icon]', $e_ico, $icons); ?></td>
                                    <td><code style="font-size:11px"><?php echo esc_html($node_id); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div><!-- /tab bar -->

                <!-- ============ Záložka 3: vlastní položky lišty ============ -->
                <div id="od-amh-tab-custom" class="od-amh-panel" hidden>
                    <p class="description" style="max-width:820px;margin-bottom:12px">
                        Vlastní položky platí pro celý web, u každé si zaškrtneš role, kterým se má zobrazit
                        (nejsou tedy vázané na roli vybranou nahoře). Do adresy můžeš napsat celou URL
                        i jen část pro administraci, například <code>edit.php?post_type=faktura</code>.
                        Pořadí změníš přetažením za úchyt vlevo. Položku smažeš tlačítkem Odebrat.
                    </p>

                    <div id="od-amh-bar-items">
                        <?php foreach ($bar_items as $i => $item) {
                            $this->render_bar_item_row($i, $item, $roles, $icons);
                        } ?>
                    </div>

                    <p>
                        <button type="button" class="button" id="od-amh-add-item">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align:text-top"></span> Přidat položku
                        </button>
                    </p>

                    <script type="text/html" id="od-amh-item-template">
                        <?php $this->render_bar_item_row('__INDEX__', null, $roles, $icons); ?>
                    </script>
                </div><!-- /tab custom -->

                <?php submit_button('Uložit pro roli ' . $roles[$role]); ?>
            </form>

            <?php $this->render_icon_palette($icons); ?>
        </div>

        <style>
            .od-amh-list, .od-amh-sub { margin:0; padding:0; list-style:none; }
            .od-amh-item { margin:0 0 6px; }
            .od-amh-row, .od-amh-subitem {
                display:flex; align-items:center; gap:8px;
                padding:9px 12px; background:#fff; border:1px solid #dcdcde; border-radius:6px;
            }
            .od-amh-row { cursor:move; }
            .od-amh-subitem { cursor:move; background:#f6f7f7; margin:6px 0 0 26px; padding:7px 12px; }
            .od-amh-grip { color:#a7aaad; flex:0 0 auto; }
            .od-amh-title { flex:1 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .od-amh-slug {
                flex:0 1 auto; max-width:170px; font-size:11px; color:#888; background:#f0f0f1;
                overflow:hidden; text-overflow:ellipsis; white-space:nowrap; direction:rtl; text-align:left;
            }
            .od-amh-toggle { flex:0 0 auto; text-decoration:none; line-height:1; }
            .od-amh-toggle .dashicons { transition:transform .15s; }
            .od-amh-toggle[aria-expanded="true"] .dashicons { transform:rotate(180deg); }
            .od-amh-placeholder { border:1px dashed #8c8f94; border-radius:6px; background:#f0f6fc; }

            .od-amh-tabs { margin-top:20px; }
            .od-amh-front-tag {
                display:inline-block; margin-left:6px; padding:1px 8px; border-radius:999px;
                background:#f1efe8; color:#50575e; font-size:11px; line-height:18px; vertical-align:middle;
            }
            .od-amh-panel { padding-top:16px; }
            .od-amh-box {
                background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1;
                border-radius:4px; padding:12px 16px; margin:0 0 20px; max-width:820px;
            }

            /* vlastní položky lišty */
            .od-amh-item-row {
                display:flex; align-items:flex-start; gap:8px; max-width:980px;
                background:#fff; border:1px solid #dcdcde; border-radius:6px;
                padding:10px 12px; margin:0 0 8px;
            }
            .od-amh-item-grip { cursor:move; margin-top:6px; }
            .od-amh-item-fields { flex:1 1 auto; min-width:0; }
            .od-amh-item-line { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
            .od-amh-item-title { flex:0 1 220px; }
            .od-amh-item-url { flex:1 1 300px; }
            .od-amh-item-remove { color:#b32d2e; text-decoration:none; line-height:1; margin-left:auto; }
            .od-amh-item-meta { margin-top:8px; font-size:12px; color:#50575e; }
            .od-amh-roles { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
            .od-amh-roles label { display:inline-flex; align-items:center; gap:3px; }

            /* výběr ikony */
            .od-amh-icon-field { display:inline-flex; position:relative; }
            .od-amh-icon-btn { display:inline-flex !important; align-items:center; gap:4px; }
            .od-amh-icon-name { font-size:11px; color:#646970; max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            #od-amh-icon-pop {
                position:absolute; z-index:9999; width:290px; padding:10px;
                background:#fff; border:1px solid #c3c4c7; border-radius:6px; box-shadow:0 6px 20px rgba(0,0,0,.15);
            }
            #od-amh-icon-search { width:100%; margin-bottom:8px; }
            .od-amh-icon-grid { display:grid; grid-template-columns:repeat(8,1fr); gap:2px; max-height:260px; overflow:auto; }
            .od-amh-icon-opt {
                display:flex; align-items:center; justify-content:center;
                width:32px; height:32px; padding:0; cursor:pointer;
                background:none; border:1px solid transparent; border-radius:4px;
            }
            .od-amh-icon-opt:hover { background:#f0f6fc; border-color:#2271b1; }
        </style>

        <script>
        jQuery(function ($) {
            var $root  = $('#od-amh-sortable');
            var $field = $('#od-amh-order');
            if (!$root.length || !$.fn.sortable) { return; }

            function collect() {
                var data = { top: [], sub: {} };
                $root.children('li').each(function () {
                    var $li  = $(this);
                    var slug = $li.attr('data-slug');
                    data.top.push(slug);

                    var subs = [];
                    $li.children('ul.od-amh-sub').children('li').each(function () {
                        subs.push($(this).attr('data-slug'));
                    });
                    if (subs.length) { data.sub[slug] = subs; }
                });
                $field.val(JSON.stringify(data));
            }

            $root.sortable({
                axis: 'y',
                items: '> li',
                handle: '> .od-amh-row',
                cancel: '.od-amh-toggle',
                placeholder: 'od-amh-placeholder',
                forcePlaceholderSize: true,
                update: collect
            });

            $root.find('ul.od-amh-sub').sortable({
                axis: 'y',
                items: '> li',
                placeholder: 'od-amh-placeholder',
                forcePlaceholderSize: true,
                update: collect
            });

            $root.on('click', '.od-amh-toggle', function () {
                var $btn = $(this);
                var $sub = $btn.closest('li').children('ul.od-amh-sub');
                var open = $btn.attr('aria-expanded') === 'true';
                $btn.attr('aria-expanded', open ? 'false' : 'true');
                $sub.prop('hidden', open);
            });

            collect();
        });

        jQuery(function ($) {

            /* ---------- záložky ---------- */
            var $tabs = $('.od-amh-tabs .nav-tab');

            $tabs.on('click', function (e) {
                e.preventDefault();
                var target = $(this).attr('href');
                $tabs.removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.od-amh-panel').prop('hidden', true);
                $(target).prop('hidden', false);
                try { window.localStorage.setItem('odAmhTab', target); } catch (err) {}
            });

            try {
                var saved = window.localStorage.getItem('odAmhTab');
                if (saved && $(saved).length) {
                    $tabs.filter('[href="' + saved + '"]').trigger('click');
                }
            } catch (err) {}

            /* ---------- výběr ikony ---------- */
            var $pop    = $('#od-amh-icon-pop');
            var $search = $('#od-amh-icon-search');
            var $field  = null;

            function closePop() {
                if ($pop.prop('hidden')) { return; }
                $pop.prop('hidden', true).appendTo('.wrap');
                $field = null;
            }

            $(document).on('click', '.od-amh-icon-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $this = $(this);
                var $new  = $this.closest('.od-amh-icon-field');

                if ($field && $field.is($new) && !$pop.prop('hidden')) { closePop(); return; }

                $field = $new;
                $pop.appendTo($field).prop('hidden', false).css({ top: $this.outerHeight() + 4, left: 0 });
                $search.val('').trigger('input').trigger('focus');
            });

            $search.on('input', function () {
                var q = $.trim($(this).val()).toLowerCase();
                $pop.find('.od-amh-icon-opt').each(function () {
                    var icon = $(this).attr('data-icon') || '';
                    $(this).toggle(!q || icon.indexOf(q) !== -1);
                });
            }).on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); }   // ať se neodešle formulář
                if (e.key === 'Escape') { closePop(); }
            });

            $pop.on('click', function (e) { e.stopPropagation(); });

            $pop.on('click', '.od-amh-icon-opt', function (e) {
                e.preventDefault();
                if (!$field) { return; }
                var icon = $(this).attr('data-icon') || '';
                $field.find('.od-amh-icon-input').val(icon);
                $field.find('.od-amh-icon-btn .dashicons').attr('class', 'dashicons ' + (icon || 'dashicons-plus-alt2'));
                $field.find('.od-amh-icon-name').text(icon || 'vybrat');
                closePop();
            });

            $(document).on('click', closePop);

            /* ---------- vlastní položky lišty ---------- */
            var $items = $('#od-amh-bar-items');
            var $order = $('#od-amh-bar-order');
            if (!$items.length) { return; }

            var counter = 0;

            function collectBar() {
                var keys = [];
                $items.children('.od-amh-item-row').each(function () {
                    keys.push($(this).attr('data-key'));
                });
                $order.val(JSON.stringify(keys));
            }

            $('#od-amh-add-item').on('click', function () {
                var tpl  = $('#od-amh-item-template').html() || '';
                var html = tpl.replace(/__INDEX__/g, 'new' + (counter++));
                $items.append(html);
                $items.children('.od-amh-item-row').last().find('.od-amh-item-title').trigger('focus');
                collectBar();
            });

            $items.on('click', '.od-amh-item-remove', function () {
                closePop();   // kdyby byla nabídka ikon otevřená právě v mazaném řádku
                $(this).closest('.od-amh-item-row').remove();
                collectBar();
            });

            if ($.fn.sortable) {
                $items.sortable({
                    axis: 'y',
                    items: '> .od-amh-item-row',
                    handle: '.od-amh-item-grip',
                    placeholder: 'od-amh-placeholder',
                    forcePlaceholderSize: true,
                    update: collectBar
                });
            }

            collectBar();
        });
        </script>
        <?php
    }

    /**
     * Vyčistí název položky menu.
     * WP do názvů vkládá <span> bubliny s počty a text pro čtečky obrazovky,
     * například: Komentáře <span class="awaiting-mod">...</span><span class="screen-reader-text">...</span>
     * Tyto spany odstraňujeme včetně obsahu, od nejvnitřnějšího ven.
     */
    private function clean_title($raw) {
        $text  = (string) $raw;
        $guard = 0;

        do {
            $count   = 0;
            $stripped = preg_replace('#<span\b[^>]*>(?:(?!<span\b)[\s\S])*?</span>#i', '', $text, -1, $count);
            if (null === $stripped) {
                break; // regex selhal, necháme původní text
            }
            $text = $stripped;
            $guard++;
        } while ($count > 0 && $guard < 10);

        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));

        // Kdyby byl celý název uvnitř spanu, radši vrátíme aspoň něco.
        if ('' === $text) {
            $fallback = html_entity_decode(wp_strip_all_tags((string) $raw), ENT_QUOTES, 'UTF-8');
            $text     = trim(preg_replace('/\s+/', ' ', $fallback));
        }

        return $text;
    }
}

new OD_Admin_Menu_Hider();
