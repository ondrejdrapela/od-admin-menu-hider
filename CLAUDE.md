# OD Skrývač admin menu (od-admin-menu-hider)

## Co plugin dělá
WordPress plugin, který upravuje administraci podle uživatelské role: skrývá celé levé menu nebo jeho jednotlivé položky a podpoložky, mění jejich pořadí, a upravuje horní lištu (skrytí položek, vlastní názvy a ikony, vlastní odkazy).

## Aktuální stav
- Verze: 1.4.0
- Naposledy hotovo: skrytí celého levého menu per role (i pro admina, s nouzovým parametrem `amh_menu=1`), úpravy horní lišty (skrytí, vlastní název, vlastní ikona) a vlastní položky lišty s výběrem ikony a rolí. Nastavení rozděleno do tří záložek.
- Rozpracováno: na tomto pluginu nic.
- Další na řadě: pro tento plugin nezjištěno.
- Známé chyby: žádné. Verze 1.4.0 je nasazená na webu a ověřená v provozu (8. 10. 2026). Pozn.: v tomto prostředí není PHP, kód se dá před nasazením ověřit jen staticky.
- Pozor: aktualizace z GitHubu funguje až od verze nahrané ručně i s `ODM_GITHUB_TOKEN` ve `wp-config.php`.

## Architektura
- Hlavní soubor: `od-admin-menu-hider/od-admin-menu-hider.php` (třída `OD_Admin_Menu_Hider`)
- Důležité soubory a složky: `readme.txt` (WP readme + changelog), `uninstall.php` (maže volbu `od_amh_hidden` při smazání pluginu). Distribuováno jako instalovatelný ZIP (Pluginy > Přidat plugin > Nahrát plugin).
- Vlastní DB tabulky: žádné. Nastavení v `wp_options`: `od_amh_hidden` (per role) a `od_amh_bar_items` (globální vlastní položky horní lišty).
- Napojení na externí služby: žádné.

Klíčové části kódu:
- Konstanty: `OPTION = od_amh_hidden`, `PAGE_SLUG = od-admin-menu-hider`, `NONCE`/action = `od_amh_save`.
- Hooky `admin_menu` podle priority: 100 `register_page`, 9998 `take_snapshot`, 9999 `apply_hiding`, 10000 `apply_submenu_order`.
- Hooky `admin_bar_menu` podle priority: 9998 `take_bar_snapshot`, 9999 `apply_bar_changes` (skrytí + název + ikona), 10000 `apply_bar_custom_items`.
- `admin_head` + `wp_head` → `print_front_styles` (CSS pro skryté levé menu a pro potlačení původních ikon v liště).
- `admin_post_od_amh_save` → `handle_save` (ukládání formuláře).
- `admin_enqueue_scripts` → načtení `jquery-ui-sortable` jen na stránce pluginu.
- Filtry: `custom_menu_order` + `menu_order` (pořadí horní úrovně), `plugin_action_links` (odkaz Nastavení).
- Struktura volby per role: `[ 'role' => ['top'=>[], 'sub'=>[], 'order'=>[], 'order_sub'=>[], 'hide_menu'=>bool, 'bar_hidden'=>[], 'bar_edit'=>[id=>['title','icon']]] ]`.
- Struktura `od_amh_bar_items`: seznam `['id'=>'odbar-xxx','icon'=>'dashicons-…','title'=>'','url'=>'','new_tab'=>bool,'roles'=>[]]`, pořadí = pořadí v poli.
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
- Skrytí celého levého menu je čistě CSS (`#adminmenumain` + margin `#wpcontent`), platí i pro administrátora. Pojistka je parametr `amh_menu` v URL (konstanta `ESCAPE_ARG`), který menu na dané stránce dočasně vrátí.
- Snapshot horní lišty se bere na `admin_bar_menu` prioritě 9998, takže v seznamu jsou i položky cizích pluginů. Když má uživatel lištu vypnutou v profilu, snapshot je prázdný a stránka to místo seznamu napíše.
- Vlastní ikona existující položky lišty: název uzlu se přepíše přes `add_node`, původní ikona z `:before` se potlačí vlastním CSS. Nepotřebujeme tedy znát unicode dané dashicony.
- Vlastní položky lišty jsou globální (ne per role), role se u nich zaškrtávají jednotlivě. Pořadí řádků se posílá v JSON poli `bar_order`, prázdný název = smazaný řádek.
- Adresa vlastní položky bez `http` a bez `/` na začátku se bere jako adresa v administraci (`admin_url`), takže stačí napsat `edit.php?post_type=faktura`.
- Komunikace a texty česky.
- Aktualizace: soukromý repozitář `https://github.com/ondrejdrapela/od-admin-menu-hider/`, PUC v5.7 ve složce `plugin-update-checker/`, proměnná `$od_admin_menu_hider_updater`, zapnuté release assets. Token jen z konstanty `ODM_GITHUB_TOKEN` ve `wp-config.php`.
