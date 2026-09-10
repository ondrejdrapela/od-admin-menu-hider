<?php
/**
 * Plugin Name: OD Skrývač admin menu
 * Plugin URI:  https://www.odmarketing.cz
 * Description: Skryje vybrané položky levého admin menu a umožňuje měnit pořadí položek i podpoložek. Nastavuje se zvlášť pro každou roli. Nabídku generuje dynamicky podle aktuálního stavu menu.
 * Version:     1.3.0
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

    const OPTION    = 'od_amh_hidden';
    const PAGE_SLUG = 'od-admin-menu-hider';
    const NONCE     = 'od_amh_save';

    /** @var array Snapshot horního menu pořízený PŘED skrytím */
    private $snapshot_menu = array();

    /** @var array Snapshot submenu pořízený PŘED skrytím */
    private $snapshot_submenu = array();

    /** @var string Hook suffix vlastní stránky */
    private $page_hook = '';

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
            'top'       => isset($r['top']) && is_array($r['top']) ? $r['top'] : array(),
            'sub'       => isset($r['sub']) && is_array($r['sub']) ? $r['sub'] : array(),
            'order'     => isset($r['order']) && is_array($r['order']) ? $r['order'] : array(),
            'order_sub' => isset($r['order_sub']) && is_array($r['order_sub']) ? $r['order_sub'] : array(),
        );
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

        $all        = $this->get_all_settings();
        $all[$role] = array(
            'top'       => $top,
            'sub'       => $sub,
            'order'     => $order,
            'order_sub' => $order_sub,
        );
        update_option(self::OPTION, $all);

        wp_safe_redirect(add_query_arg(
            array('page' => self::PAGE_SLUG, 'amh_role' => $role, 'updated' => '1'),
            admin_url('admin.php')
        ));
        exit;
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
                Nastavení se ukládá zvlášť pro každou roli. Vyber roli, zaškrtni položky ke skrytí
                a přetažením nastav pořadí položek i podpoložek. Skrytí i pořadí se projeví uživatelům
                s danou rolí. Vlastní menu uvidíš změněné podle své role, cizí role otestuješ až po
                přihlášení pod tou rolí. Skrytí je pouze vizuální, stránka zůstane dostupná přes přímou URL.
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
                <?php wp_nonce_field(self::NONCE); ?>

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

                <?php submit_button('Uložit pro roli ' . $roles[$role]); ?>
            </form>
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
