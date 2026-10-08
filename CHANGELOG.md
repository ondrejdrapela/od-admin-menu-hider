# Changelog

## 1.4.0
- Skrytí celého levého menu pro vybranou roli (platí i pro administrátora). Obsah se roztáhne na celou šířku.
- Nouzové zobrazení menu přes parametr `amh_menu=1` v adrese, kdyby sis menu skryl i sobě.
- Horní lišta: seznam živých položek s možností je skrýt, přepsat jim název a nastavit vlastní ikonu.
- Vlastní položky horní lišty: ikona z nabídky dashicons, vlastní název, adresa, otevření v novém okně a výběr rolí.
  Ukládají se globálně do nové volby `od_amh_bar_items`.
- Nastavení rozděleno do tří záložek: Levé menu, Horní lišta, Vlastní položky lišty.
- Vizuální náhled: `_nahled/nahled-1.4.0.html`.

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
