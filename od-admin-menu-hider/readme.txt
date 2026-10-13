=== OD Skrývač admin menu ===
Contributors: odmarketing
Tags: admin, menu, hide, order, role
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Skryje vybrané položky levého admin menu a mění pořadí položek i podpoložek. Nastavuje se zvlášť pro každou roli.

== Description ==

Plugin přidá do administrace stránku Skrývač menu. Nahoře vybereš roli a pak ve třech záložkách:

* **Levé menu** – skryješ celé levé menu, nebo jen vybrané položky a podpoložky, a přetažením nastavíš jejich pořadí,
* **Horní lišta** – skryješ položky horní lišty, přepíšeš jim název a nastavíš vlastní ikonu,
* **Vlastní položky lišty** – přidáš do horní lišty vlastní odkazy (ikona, název, adresa) a zaškrtneš, kterým rolím se mají zobrazit.

Seznam se generuje z živého menu při každém načtení stránky, takže vždy vidíš přesně to,
co je teď v menu. Snapshot se pořizuje ještě předtím, než se cokoli skryje, takže se v seznamu
objeví i položky, které už máš skryté (jsou zaškrtnuté) a lze je zase odškrtnout.

Názvy položek se čistí od bublin s počty aktualizací a od textu pro čtečky obrazovky,
takže v seznamu vidíš stejný název, jaký je v menu.

Skrytí i pořadí se aplikují podle role přihlášeného uživatele. Má-li uživatel víc rolí,
skrývání se sjednotí napříč rolemi, pro pořadí se použije první role, která ho má uložené.

Vlastní stránku pluginu nelze v levém menu omylem skrýt. Při skrytí celého levého menu
se k nastavení dostaneš přes adresu s parametrem amh_menu=1, která menu dočasně zobrazí.

Poznámky:
* Skrytí je pouze vizuální. Stránka zůstává dostupná přes přímou URL, nejde o bezpečnostní opatření.
* Pořadí horní úrovně se řeší filtrem menu_order, pořadí podpoložek přeuspořádáním globálního pole submenu.
* Podpoložky lze přetahovat jen v rámci jejich rodiče, přesun mezi rodiči WordPress neumožňuje.

== Installation ==

1. Nahraj ZIP přes Pluginy > Přidat plugin > Nahrát plugin.
2. Aktivuj plugin.
3. V levém menu otevři Skrývač menu, vyber roli, nastav skrytí a pořadí, ulož.

== Changelog ==

= 2.0.0 =
* Nový vzhled administrace stejný jako Finance a Systém Soulado: boční menu se sekcemi, výběr role pilulkami, karty a vlastní zaškrtávátka.
* Tlačítko Uložit je nahoře, skryté položky jsou přeškrtnuté.
* V bočním menu jde zkontrolovat a nainstalovat aktualizaci pluginu na pozadí.
* Názvy rolí jsou přeložené do češtiny.

= 1.5.0 =
* Horní lišta: v nastavení jsou nově i položky, které WordPress ukazuje jen na frontendu (Upravit web, Přizpůsobit, Upravit stránku, Hledat) a frontendové položky jiných pluginů.
* Úpravy lišty se aplikují až těsně před vykreslením, takže zachytí i pozdě přidané položky.

= 1.4.0 =
* Skrytí celého levého menu pro vybranou roli, včetně nouzového parametru amh_menu=1.
* Horní lišta: skrývání položek, vlastní názvy a vlastní ikony.
* Vlastní položky horní lišty s výběrem ikony, adresou a výběrem rolí.
* Nastavení rozděleno do tří záložek.

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
