# Changelog

## 2.0.0
- Redesign administrace podle Finance (3.0.0) a Systému Soulado: na stránce pluginu se skryje lišta, levé menu
  i patička WP, místo nich rám s bočním menu (Levé menu, Horní lišta, Vlastní položky lišty jako sekce bez načtení stránky).
- Dole v bočním menu Zkontrolovat aktualizace (`admin_post_od_amh_check_update`) a aktualizace na pozadí přes AJAX `update-plugin`,
  Zpět do WordPressu, uživatel s odhlášením a verze. Po klasické aktualizaci odkaz Zpět do Skrývače menu.
- Výběr role jako pilulky (odkazy), tlačítko Uložit v hlavičce, štítek Nastavení uloženo.
- Karty, tabulky, vlastní zaškrtávátka, přepínač Skrýt celé levé menu, role u vlastních položek jako pilulky.
  Skryté položky se přeškrtnou (CSS `:has`).
- Styly a skripty přesunuty do `assets/admin.css` a `assets/admin.js`, písma Sora a Plus Jakarta Sans lokálně v `assets/fonts/`.
- Názvy rolí přes `translate_user_role` (česky).
- Konstanta `VERSION`. Ověřeno ve WordPress Playground (uložení, sekce, ikony, vlastní položky, skrytí na frontendu, mobil, kontrola aktualizací).
- Náhled: `_nahled/redesign-2.0.0.html`.

## Repozitář (13. 10. 2026)
- Repozitář `od-admin-menu-hider` přepnut na veřejný, aktualizace ve WordPressu fungují bez `ODM_GITHUB_TOKEN`.
  Před přepnutím zkontrolována celá historie, žádná hesla ani tokeny. Ostatní pluginy zůstávají soukromé.

## 1.5.0
- Horní lišta: seznam položek nově obsahuje i uzly, které WordPress přidává jen na frontendu
  (`site-editor` Upravit web, `customize` Přizpůsobit, `edit` Upravit stránku, `search` Hledat).
  Jsou označené štítkem „jen na frontendu“.
- Uzly zachycené při návštěvě frontendu administrátorem se ukládají do nové volby `od_amh_bar_front`
  (zápis jen při změně, bez autoloadu), takže jdou nastavit i frontendové položky jiných pluginů.
- Snapshot a úpravy lišty přesunuty z `admin_bar_menu` 9998/9999 na `wp_before_admin_bar_render` 9998/9999,
  aby zachytily i pozdě přidané uzly (např. Hledat má v jádře prioritu 9999). Vlastní položky zůstávají na `admin_bar_menu` 10000.
- `uninstall.php` maže i `od_amh_bar_front`.
- Vizuální náhled: `_nahled/nahled-1.5.0.html`.

## 1.4.0
- Skrytí celého levého menu pro vybranou roli (platí i pro administrátora). Obsah se roztáhne na celou šířku.
- Nouzové zobrazení menu přes parametr `amh_menu=1` v adrese, kdyby sis menu skryl i sobě.
- Horní lišta: seznam živých položek s možností je skrýt, přepsat jim název a nastavit vlastní ikonu.
- Vlastní položky horní lišty: ikona z nabídky dashicons, vlastní název, adresa, otevření v novém okně a výběr rolí.
  Ukládají se globálně do nové volby `od_amh_bar_items`.
- Nastavení rozděleno do tří záložek: Levé menu, Horní lišta, Vlastní položky lišty.
- Vizuální náhled: `_nahled/nahled-1.4.0.html`.
- Nasazeno a ověřeno v provozu 8. 10. 2026.

## 1.3.0
- Plugin připraven na GitHub: soukromý repozitář `od-admin-menu-hider`.
- Přidána knihovna Plugin Update Checker (v5.7) – aktualizace jde nově spustit tlačítkem přímo ve WordPressu.
- Token se čte pouze z konstanty `ODM_GITHUB_TOKEN` ve `wp-config.php`, není nikde v kódu.

## 1.2.0
- Řazení podpoložek přetažením v rámci jejich rodiče.
- Oprava názvů položek v panelu pořadí (bubliny s počty a text pro čtečky obrazovky se už nezobrazují).
- Rozbalování podpoložek v panelu pořadí.

## 1.1.0
- Nastavení zvlášť pro každou uživatelskou roli.
- Změna pořadí položek horního menu přetažením.

## 1.0.0
- První verze: skrývání položek menu i podpoložek, seznam generovaný dynamicky z živého menu.
- Převedeno z mu-plugin (jeden soubor) na klasický plugin: přidán `readme.txt`, `uninstall.php` a odkaz Nastavení v seznamu pluginů.
