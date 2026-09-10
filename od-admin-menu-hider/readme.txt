=== OD Skrývač admin menu ===
Contributors: odmarketing
Tags: admin, menu, hide, order, role
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Skryje vybrané položky levého admin menu a mění pořadí položek i podpoložek. Nastavuje se zvlášť pro každou roli.

== Description ==

Plugin přidá do administrace stránku Skrývač menu. Nahoře vybereš roli a pro ni pak:

* zaškrtneš položky menu i podpoložky, které se mají skrýt,
* přetažením nastavíš pořadí položek horní úrovně,
* rozbalíš položku a přetažením nastavíš pořadí jejích podpoložek.

Seznam se generuje z živého menu při každém načtení stránky, takže vždy vidíš přesně to,
co je teď v menu. Snapshot se pořizuje ještě předtím, než se cokoli skryje, takže se v seznamu
objeví i položky, které už máš skryté (jsou zaškrtnuté) a lze je zase odškrtnout.

Názvy položek se čistí od bublin s počty aktualizací a od textu pro čtečky obrazovky,
takže v seznamu vidíš stejný název, jaký je v menu.

Skrytí i pořadí se aplikují podle role přihlášeného uživatele. Má-li uživatel víc rolí,
skrývání se sjednotí napříč rolemi, pro pořadí se použije první role, která ho má uložené.

Vlastní stránku pluginu nelze omylem skrýt.

Poznámky:
* Skrytí je pouze vizuální. Stránka zůstává dostupná přes přímou URL, nejde o bezpečnostní opatření.
* Pořadí horní úrovně se řeší filtrem menu_order, pořadí podpoložek přeuspořádáním globálního pole submenu.
* Podpoložky lze přetahovat jen v rámci jejich rodiče, přesun mezi rodiči WordPress neumožňuje.

== Installation ==

1. Nahraj ZIP přes Pluginy > Přidat plugin > Nahrát plugin.
2. Aktivuj plugin.
3. V levém menu otevři Skrývač menu, vyber roli, nastav skrytí a pořadí, ulož.

== Changelog ==

= 1.3.0 =
* Automatické aktualizace pluginu přímo z WordPressu (GitHub + Plugin Update Checker).

= 1.2.0 =
* Řazení podpoložek přetažením v rámci rodiče.
* Oprava názvů položek (bubliny s počty a text pro čtečky se už nezobrazují).
* Rozbalování podpoložek v panelu pořadí.

= 1.1.0 =
* Nastavení zvlášť pro každou roli.
* Změna pořadí položek horního menu přetažením.

= 1.0.0 =
* První verze.
