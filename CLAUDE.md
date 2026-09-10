# OD Skrývač admin menu (od-admin-menu-hider)

## Co plugin dělá
WordPress plugin, který skrývá vybrané položky levého admin menu a umožňuje měnit pořadí položek horní úrovně i podpoložek. Nastavuje se zvlášť pro každou uživatelskou roli.

## Aktuální stav
- Verze: 1.3.0
- Naposledy hotovo: plugin připraven na GitHub – soukromý repozitář `od-admin-menu-hider`, přidán Plugin Update Checker v5.7, aktualizace jdou spustit přímo ve WordPressu.
- Rozpracováno: na tomto pluginu nic.
- Další na řadě: pro tento plugin nezjištěno.
- Známé chyby: žádné potvrzené. Kód nebyl ověřen reálným PHP lintem ani spuštěn ve WP (v prostředí nešlo doinstalovat PHP), ověřeno jen staticky.
- Pozor: verzi 1.3.0 je potřeba do WordPressu nahrát ještě jednou ručně (ZIP) a doplnit `ODM_GITHUB_TOKEN` do `wp-config.php`. Od další verze už bude fungovat tlačítko Aktualizovat.

## Architektura
- Hlavní soubor: `od-admin-menu-hider/od-admin-menu-hider.php` (třída `OD_Admin_Menu_Hider`)
- Důležité soubory a složky: `readme.txt` (WP readme + changelog), `uninstall.php` (maže volbu `od_amh_hidden` při smazání pluginu). Distribuováno jako instalovatelný ZIP (Pluginy > Přidat plugin > Nahrát plugin).
- Vlastní DB tabulky: žádné. Nastavení uloženo v `wp_options` pod klíčem `od_amh_hidden`.
- Napojení na externí služby: žádné.

Klíčové části kódu:
- Konstanty: `OPTION = od_amh_hidden`, `PAGE_SLUG = od-admin-menu-hider`, `NONCE`/action = `od_amh_save`.
- Hooky `admin_menu` podle priority: 100 `register_page`, 9998 `take_snapshot`, 9999 `apply_hiding`, 10000 `apply_submenu_order`.
- `admin_post_od_amh_save` → `handle_save` (ukládání formuláře).
- `admin_enqueue_scripts` → načtení `jquery-ui-sortable` jen na stránce pluginu.
- Filtry: `custom_menu_order` + `menu_order` (pořadí horní úrovně), `plugin_action_links` (odkaz Nastavení).
- Struktura volby per role: `[ 'role' => ['top'=>[], 'sub'=>[], 'order'=>[], 'order_sub'=>[]] ]`.
- Formát skryté podpoložky: `"rodic||potomek"`. JSON pořadí z formuláře: `{ top:[...], sub:{ rodic:[...] } }`.

## Specifika tohoto pluginu
- Seznam menu se generuje dynamicky z živého menu. Snapshot (`$menu`, `$submenu`) se bere PŘED skrytím (priorita 9998), takže i aktuálně skryté položky zůstávají v seznamu a jdou zase odškrtnout. Pořadí hooků (100/9998/9999/10000) neměnit.
- Vlastní stránku pluginu nelze nikdy skrýt (guard na `PAGE_SLUG`).
- `clean_title` odstraňuje `<span>` i s obsahem, zevnitř ven (kvůli WP bublinám s počty aktualizací a textu pro čtečky), s fallbackem, kdyby byl název celý uvnitř spanu. Nesmí sežrat legitimní název (např. text ve `<strong>`).
- Skrytí je pouze vizuální, ne bezpečnostní opatření (stránka zůstává dostupná přes přímou URL).
- Nastavení je per role. Když má uživatel víc rolí: skrývání se sjednotí napříč rolemi; pořadí horní úrovně se vezme z první role, která ho má uložené; pořadí podpoložek per rodič z první role, která daného rodiče definuje.
- Pořadí horní úrovně jede přes oficiální filtr `menu_order` (`custom_menu_order` vrací true jen když pořadí existuje). Pořadí podpoložek se řeší přeuspořádáním globálního pole `$submenu` a přeindexací na klíče 0..n; položky mimo uložené pořadí se přidávají na konec.
- Podpoložky lze přetahovat jen v rámci jejich rodiče (mezi rodiče to WordPress neumožňuje).
- Migrace: stará neroleová struktura (klíče `top`/`sub` v kořeni volby, verze 1.0) se při načtení překlopí na všechny role.
- Pozor: `uninstall.php` maže `od_amh_hidden` při smazání pluginu, takže smazání staré verze přes admin smaže i nastavení. Při upgradu buď nastavit znovu, nebo přepsat soubory bez smazání pluginu.
- Komunikace a texty česky.
- Aktualizace: soukromý repozitář `https://github.com/ondrejdrapela/od-admin-menu-hider/`, PUC v5.7 ve složce `plugin-update-checker/`, proměnná `$od_admin_menu_hider_updater`, zapnuté release assets. Token jen z konstanty `ODM_GITHUB_TOKEN` ve `wp-config.php`.
