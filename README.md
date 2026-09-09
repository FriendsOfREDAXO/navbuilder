# NavBuilder

*🇬🇧 [English version](README.en.md)*

Navigationen im REDAXO-Backend per Drag & Drop bauen und über überschreibbare Fragmente überall
ausgeben.

- Backend-Editor: rekursiver Baum, natives HTML5-Drag-&-Drop, per Tastatur bedienbare
  Verschiebe-Buttons, "darunter einfügen" pro Eintrag, Linkmap aus dem Core + Artikel-
  Autocomplete, live nachgeladene Artikelnamen (ein umbenannter Artikel hinterlässt nie eine
  veraltete Beschriftung).
- Frontend: `rex_navbuilder::render()` (HTML über Fragmente) und `rex_navbuilder::tree()` (Array),
  dazu der Output-Filter `REX_NAVBUILDER[name=…]` für Templates/Module.
- Kein Build-Step: Vue 3.5.13 liegt als `assets/vue.global.prod.js` bei (kein CDN) und wird nur
  auf der Backend-Seite des Addons geladen.

## Voraussetzungen

- REDAXO `^5.18`
- PHP `>=8.1`
- Addon `structure` `^2.9`
- Optional: Addon [`url`](https://github.com/FriendsOfREDAXO/url) — nur für den Item-Typ `url`.
  Bewusst keine Abhängigkeit: fehlt das Addon, fehlt schlicht dieser Typ, alles andere
  funktioniert unverändert.

Kein `yform`, kein `phpmailer` (beides in 2.0 entfernt — siehe Changelog).

## Installation

Wie gewohnt über den Paket-Manager installieren/aktivieren. Dabei wird die Tabelle
`rex_navbuilder_navigation` angelegt (`id`, `name`, `structure`, `structure_legacy`,
`updated_at`).

## Update von 1.x

Wird 2.0 über eine bestehende 1.x-Installation aktiviert, läuft `update.php` und

1. legt die neuen Spalten (`structure_legacy`, `updated_at`) sowie einen `UNIQUE(name)`-Index an
   (wird mit einer Log-Warnung übersprungen, falls bereits doppelte Namen existieren —
   `Navigation::save()` prüft Namen ab jetzt ohnehin),
2. migriert jede gespeicherte Navigation vom v1-Format
   (`{"type":"intern","text":"Seite [12]","href":"12"}`) auf das v2-Schema (siehe unten) und
   bildet dabei `intern → article`, `extern → link`, `group → text` ab. Das v1-Feld `text` (ein
   Artikelnamen-Cache, der beim Umbenennen veraltete) entfällt — das Frontend hat es nie gelesen,
   Namen und URLs wurden schon immer beim Rendern aufgelöst,
3. sichert das JSON vor der Migration in `structure_legacy` (nur wenn diese Spalte noch leer ist,
   ein erneuter Lauf überschreibt das Original also nie).

Die Migration ist **idempotent**: Zeilen mit `"v":2` werden übersprungen, `update.php` kann also
beliebig oft laufen. Nicht-numerische `href`-Werte an einem v1-`intern`-Eintrag werden verworfen
und über `rex_logger` protokolliert, statt als Müll migriert zu werden. Musste die Migration
Einträge verwerfen, nennt die Update-Meldung deren Anzahl (Details im Systemlog). Vor dem Update
werden die vorhandenen Namen geprüft: Namen über 191 Zeichen brechen das Update mit einer klaren
Meldung ab (vorher kürzen), Nicht-Slug-Namen werden im Systemlog gemeldet — sie funktionieren
weiter über `rex_navbuilder::render()`, sind aber per `REX_NAVBUILDER[name=…]` nicht adressierbar. Die Frontend-API bleibt
davon unberührt — `render()`/`tree()`/die veralteten Shims funktionieren während und nach der
Migration unverändert.

## Datenmodell (v2)

```jsonc
{
  "v": 2,
  "maxDepth": 3,                       // optional, fehlt = unbegrenzt (harte Grenze: 10)
  "items": [
    { "id": "a1b2c3d4", "type": "article", "articleId": 12, "clang": null, "children": [],
      "label": "optionale redaktionelle Überschreibung", "hiddenIn": [2] },
    { "id": "e5f6a7b8", "type": "link", "url": "https://example.com", "label": "Extern",
      "target": "_blank", "children": [] },
    { "id": "b1c2d3e4", "type": "media", "file": "prospekt.pdf", "label": "Prospekt",
      "target": "_blank", "children": [] },
    { "id": "d3e4f5a6", "type": "url", "profileId": 3, "dataId": 17,
      "target": "_self", "children": [] },
    { "id": "c9d0e1f2", "type": "text", "label": "Service", "text": "<p>…</p>",
      "children": [] }
  ]
}
```

`article`-Einträge speichern nur die Artikel-ID; Name und URL werden beim Rendern aufgelöst. Ein
`label` an einem `article`-Eintrag ist eine bewusste Überschreibung, nie ein Cache — ohne `label`
erscheint immer der aktuelle Artikelname.

`media`-Einträge speichern nur den Dateinamen aus dem Medienpool. URL und Existenz werden beim
Rendern über `rex_media::get()` aufgelöst; ohne `label` erscheint der Dateiname. Eine gelöschte
Datei verschwindet aus der Frontend-Ausgabe und wird im Backend markiert — genau wie ein
gelöschter Artikel.

Damit es gar nicht erst so weit kommt, schützt NavBuilder beide Referenzen beim Löschen: Ein
Artikel, der noch in einer Navigation verwendet wird, lässt sich nicht löschen (die Fehlermeldung
verlinkt die betroffenen Navigationen), und der Medienpool meldet eine noch referenzierte Datei
als „in Benutzung" und verweigert das Löschen ebenfalls.

`url`-Einträge zeigen auf eine vom [url-Addon](https://github.com/FriendsOfREDAXO/url) generierte
Datensatz-URL und setzen dieses Addon voraus (siehe unten). Gespeichert werden nur `profileId` und
`dataId` — Adresse und Name werden beim Rendern aufgelöst (Name: der SEO-Titel des Generators,
ersatzweise der Pfad). Wie überall ist ein `label` eine redaktionelle Überschreibung, nie ein Cache.

`text`-Einträge sind rein strukturell (früher `group`, siehe Changelog). Neben `label` können sie
ein optionales Feld `text` mit **HTML** tragen. Beide sind unabhängig voneinander: `text` ist immer
der Inhalt, nie ein Ersatz für die Beschriftung — ein Eintrag ohne `label` behält ein leeres `label`
(im Backend zeigt die Zeile stattdessen einen Textauszug ohne Markup). Der alte Typname `group` wird beim Einlesen
automatisch auf `text` abgebildet, gespeicherte Daten funktionieren also unverändert weiter.

E-Mail- und Telefon-Einträge sind normale `link`-Einträge (`mailto:…` / `tel:…`) — die Erkennung
im Editor baut nur die URL zusammen und erweitert das Datenmodell nicht.

### Datensatz-URLs (url-Addon)

Das [url-Addon](https://github.com/FriendsOfREDAXO/url) ist ein **optionaler Nachbar, keine
Voraussetzung**: Der Typ `url` erscheint im Backend nur, wenn das Addon installiert ist.

Aufgelöst wird ausschließlich die kanonische Datensatz-URL. Zeilen, die das url-Addon als
angehängte Unter-URL eines Datensatzes führt (`is_user_path`/`is_structure`, z. B. eine
Galerie-Seite unter einer Detailseite), tauchen weder im Picker noch in der Ausgabe auf.

Die Auflösung ist **sprachabhängig**: Gibt es für die gerenderte Sprache keine generierte URL —
weil das Profil diese Sprache nicht generiert oder der Datensatz gelöscht wurde — wird der Eintrag
samt allen Unterpunkten übersprungen, genau wie bei einem gelöschten Artikel. `active` gilt, wenn
der Pfad des Requests der generierten URL entspricht (ein abschließender `/` spielt keine Rolle).

Im Backend durchsucht der Picker alle generierten URLs der im Backend gewählten Sprache — nach
Name und Adresse; jeder Treffer zeigt Name, Profil-Namespace als Badge und den Pfad. Gibt es mehr
als ein Profil, steht darüber ein Profil-Filter.

Wird das url-Addon später deinstalliert, bleiben gespeicherte `url`-Einträge erhalten: Im Backend
sind sie mit dem Hinweis „URL-Addon nicht verfügbar" markiert und lassen sich weiterhin bearbeiten
und speichern (auch in einen anderen Typ umwandeln), im Frontend werden sie übersprungen — mit
genau einer Warnung im Systemlog pro Request, nicht einer pro Eintrag.

### Sichtbarkeit pro Sprache

Jeder Eintrag — unabhängig vom Typ — kann ein optionales `hiddenIn: [clang_id, …]` tragen: die
Sprachen, in denen er **nicht** ausgegeben wird. Fehlt der Schlüssel oder ist die Liste leer, ist
der Eintrag überall sichtbar (leere Listen werden beim Speichern verworfen). Beim Rendern wird ein
so ausgeblendeter Eintrag samt allen Unterpunkten übersprungen, und zwar **vor** der Prüfung auf
`rex_article::status` — die beiden Filter sind unabhängig voneinander: ein offline geschalteter
Artikel verschwindet weiterhin überall, `hiddenIn` steuert nur die Sprache. Im Backend erscheint
die Sprachauswahl als Chip-Reihe im Bearbeiten-Formular, aber nur, wenn es überhaupt mehr als eine
Sprache gibt; betroffene Zeilen tragen im Baum einen kleinen Hinweis mit den Sprachcodes.

### Maximale Tiefe

Auf der Wurzel des Struktur-JSON kann `maxDepth` stehen: die maximale Verschachtelungstiefe dieser
Navigation. `1` heißt flach — Einträge ohne Unterpunkte; fehlt der Schlüssel, gilt die globale harte
Grenze von 10 Ebenen.

Die Grenze ist **redaktionelle Vorgabe und damit Sache der Administratoren**: nur sie sehen das Feld
im Backend, und nur ihre Speichervorgänge schreiben den Wert. Speichert jemand ohne Admin-Rechte,
wird ein mitgeschickter `maxDepth` ignoriert und der gespeicherte Wert übernommen (bei neuen
Navigationen: keiner) — die Vorgabe lässt sich also nicht per manipuliertem Formular aushebeln.

Der Editor sperrt live, was die Grenze reißen würde (der Einrücken-Button wird deaktiviert,
„hineinziehen" wird verweigert). Verbindlich ist aber der Server: ein zu tief verschachtelter Baum
wird beim Speichern **abgelehnt** (mit Angabe der Grenze und der tatsächlichen Tiefe), nie still
flachgeklopft — auch nicht an der globalen 10-Ebenen-Grenze.

## Backend

*Menüs → NavBuilder*: Navigation anlegen, Artikel/Links/Medien/Datensätze/Texte in den Baum ziehen (oder die
Buttons hoch/runter/rein/raus benutzen — vollständiger Tastaturweg, alles mit `aria-label`). Der
**+**-Button einer Zeile fügt einen neuen Eintrag direkt unter diesem Eintrag ein (unterhalb
seiner Unterpunkte), statt ihn ans Ende zu hängen — gleich als Artikel im Bearbeiten-Formular,
der Typ lässt sich dort umschalten. Es ist immer nur ein Formular offen: Hinzufügen oder
**Duplizieren** eines Eintrags übernimmt zuerst das gerade offene Formular, das Duplikat öffnet
sich direkt zum Bearbeiten. Gelöschte oder offline geschaltete Artikel
werden im Baum markiert, statt die Navigation still zu zerstören; in der Frontend-Ausgabe
erscheinen sie nicht. Mit **Duplizieren** in der Liste lässt sich eine Navigation abzweigen
(z. B. Hauptnavigation → Footer-Navigation), ohne sie neu zu bauen.

Das Formular für Links hat **ein einziges Feld** für URL, E-Mail-Adresse oder
Telefonnummer. Die Eingabe wird live erkannt und als kleiner Indikator im Feld angezeigt
(Link / E-Mail / Telefon): eine nackte Adresse wird zu `mailto:…`, eine Telefonnummer zu `tel:…`
(Ziffern und ein führendes `+` bleiben erhalten), eine nackte Domain (`example.com`,
`sub.example.ch:8080`) oder ein `www.`-Anfang bekommt `https://` vorangestellt. Alles andere mit
einem `/` bleibt unangetastet — `downloads/broschuere.pdf` ist ein gültiges relatives Ziel, und
`example.com/path` verzichtet auf die Bequemlichkeit, damit kein Pfad kaputtgeht. Relative Ziele (`/kontakt/`, `#top`, `?p=1`, `//cdn…`) und alles mit
eigenem Schema werden unverändert gespeichert. Beim erneuten Bearbeiten steht die nackte Adresse
bzw. Nummer im Feld, mit passendem Indikator — außer die Erkennung würde das Schema nicht
identisch wiederherstellen (z. B. `tel:0800-REDAXO`), dann bleibt die vollständige URL stehen. "In neuem Fenster öffnen" (`target="_blank"`) erscheint nur bei
echten URLs. Die Erkennung ist eine Eingabehilfe — geprüft wird weiterhin gegen dieselbe
Schema-Positivliste, clientseitig und verbindlich auf dem Server.

**Alle Typen lassen sich direkt ineinander umwandeln**: das Bearbeiten-Formular hat oben
einen Umschalter **Artikel | Link | Medium | URL | Text** (die URL-Schaltfläche nur bei
installiertem url-Addon — oder wenn der Eintrag bereits einer ist). Umgeschaltet wird nur die Anzeige — übernommen
wird die Umwandlung erst mit *Übernehmen*, und dabei bleiben `id`, Beschriftung,
Sprach-Sichtbarkeit und alle Unterpunkte erhalten, während die Felder der anderen Typen entfernt
werden. Eine Umwandlung ohne Ziel (kein Artikel gewählt, keine Adresse getippt, keine Datei
gewählt, kein Datensatz gewählt) wird mit einer Meldung im Formular abgelehnt, statt den Eintrag samt Unterpunkten zu
verlieren; die Umwandlung **zu** Text braucht kein Ziel, da Beschriftung und Inhalt beide optional
sind. Wird ein Text-Eintrag mit Inhalt in einen anderen Typ umgewandelt, steht neben dem Umschalter
ein Hinweis, dass der Inhalt beim Übernehmen entfernt wird — er ist das Einzige, was eine
Umwandlung nicht mitnehmen kann. *Abbrechen* stellt Typ **und** Inhalt wieder her.

**Medien** werden über den Medienpool gewählt (Button *Datei wählen*, derselbe Popup-Weg wie beim
Core-Widget); das Dateinamensfeld ist bewusst nur lesbar. Wie bei Links gibt es "In neuem Fenster
öffnen" (`target="_blank"`). **Datensatz-URLs** werden über ein Suchfeld gewählt (Details oben
unter *Datensatz-URLs*), ebenfalls mit "In neuem Fenster öffnen" und einem Feld für die abweichende
Beschriftung. **Text**-Einträge haben neben der
Beschriftung ein Textfeld für HTML — siehe die Warnung unter *Fragmente überschreiben*.

Das Bearbeiten-Formular zeigt zusätzlich die interne `id` des Eintrags (klein, grau, unten
rechts) — sie ist der Anknüpfungspunkt für Styling pro Eintrag, siehe unten.

Namen sind Slugs — `Navigation::save()` akzeptiert nur `[a-z0-9_-]+`. Das entspricht exakt der
Regex des Output-Filters `REX_NAVBUILDER[name=…]`; jeder andere Name ließe sich zwar speichern,
aber nie per Snippet ansprechen.

Das Snippet zum Einbinden einer Navigation (`REX_NAVBUILDER[name=…]`) steht in der Liste neben
jedem Eintrag.

### Berechtigungen (MultiSite / MultiDomain)

Drei Stufen, alle im Rollen-Editor unter *Benutzer → Rollen*:

- **`navbuilder[]`** (Seiten-Berechtigung): öffnet die NavBuilder-Seite — Voraussetzung für
  alles Weitere.
- **Navigationen** (Auswahlliste): welche Navigationen die Rolle **bearbeiten** darf. Die
  Auswahl ist an die Navigation gebunden (nicht an ihren Namen), ein Umbenennen ändert also
  nichts an den Rechten. *„Alle Navigationen bearbeiten"* erlaubt das Bearbeiten sämtlicher
  Navigationen — aber weiterhin kein Anlegen oder Löschen.
- **`navbuilder[manage]`** (Option): Navigationen **anlegen, duplizieren und löschen** —
  schließt das Bearbeiten aller Navigationen ein. Admins haben dieses Recht implizit.

Damit lässt sich das MultiSite-Szenario abbilden: Die Site-Administration bekommt
`navbuilder[manage]`, die Redaktion je Domain eine Rolle mit genau ihren Navigationen.
Redakteure ohne Auswahl sehen eine leere Liste. Wird eine Navigation gelöscht, wird sie
automatisch aus allen Rollen entfernt. Alle Prüfungen laufen serverseitig — die ausgeblendeten
Buttons sind nur Komfort.

## Frontend-API

```php
// Ausgabe über fragments/navbuilder/{navigation,item}.php:
echo rex_navbuilder::render('main');

// Eigenes Fragment und begrenzte Verschachtelungstiefe:
echo rex_navbuilder::render('main', ['fragment' => 'project/nav.php', 'depth' => 2]);

// Aufgelöster Baum als Array, für eigene Ausgabe:
$tree = rex_navbuilder::tree('main');

// In Template-/Modul-Ausgabe:
REX_NAVBUILDER[name=main]
```

Optionen von `render()`/`tree()`: `clang`, `currentId` (Standard: aktueller Artikel), `depth`
(0 = unbegrenzt), `absolute` (nutzt `rex_yrewrite`-Volllinks, sofern vorhanden), dazu
`fragment`/`itemFragment`/`class` für `render()`.

Jeder Knoten aus `tree()` (und an die Fragmente übergeben):

| Key | Typ | Bedeutung |
|---|---|---|
| `id` | string | stabile interne ID, für `:key`/Diffing und Styling pro Eintrag |
| `type` | string | `article` \| `link` \| `media` \| `url` \| `text` |
| `label` | string | Artikelname, Dateiname, Datensatz-Titel, Überschreibung oder Link-/Textbeschriftung |
| `url` | `?string` | `null` bei `text`-Einträgen |
| `text` | `?string` | rohes HTML, nur bei `text`-Einträgen |
| `file` | `?string` | Dateiname aus dem Medienpool, nur bei `media`-Einträgen (z. B. für eine Verzweigung nach Endung) |
| `articleId` | `?int` | nur bei `article`-Einträgen |
| `categoryId` | `?int` | Kategorie des Artikels (`0` auf oberster Ebene, bei einem Startartikel die Kategorie selbst); `null` bei allen anderen Typen |
| `profileId` | `?int` | url-Addon-Profil, nur bei `url`-Einträgen |
| `dataId` | `?int` | Datensatz-ID im Profil, nur bei `url`-Einträgen |
| `target` | `?string` | nur bei `link`-, `media`- und `url`-Einträgen mit explizitem Ziel |
| `online` | bool | immer `true` — offline/gelöschte Artikel sind bereits herausgefiltert |
| `active` | bool | zeigt genau auf den aktuellen Artikel (bei `url`-Einträgen: auf den aufgerufenen Pfad) |
| `activePath` | bool | aktiv, Vorfahre davon oder mit aktivem Nachfahren |
| `depth` | int | 1-basiert |
| `children` | list | gleiche Struktur |

## Fragmente überschreiben

Zum Überschreiben eine der Dateien ins Projekt kopieren — REDAXOs Fragment-Lookup findet die
Projektkopie automatisch:

```
fragments/navbuilder/navigation.php  → project/fragments/navbuilder/navigation.php
fragments/navbuilder/item.php        → project/fragments/navbuilder/item.php
fragments/navbuilder/text.php        → project/fragments/navbuilder/text.php
```

`navigation.php` und `item.php` werden über die Vars von `render()` mitgereicht, eine
Überschreibung greift also auf jeder Verschachtelungsebene, nicht nur auf der obersten. Alle
Ausgaben der mitgelieferten Fragmente laufen durch `rex_escape()` — **mit einer Ausnahme**:

> ⚠️ `text.php` gibt das Feld `text` eines `text`-Eintrags **roh** aus. Das ist Absicht: es ist
> redakteursgeschriebenes HTML, genau wie der Inhalt einer Modul-Textarea, und escapen würde das
> Feld sinnlos machen. Damit gilt dieselbe Vertrauensgrenze wie bei Modulen — nur
> Backend-Benutzer mit Zugriff auf die Navigation können es schreiben. Wer das nicht will,
> überschreibt `text.php` und escapet dort.

### Editor für das Textfeld (`NAVBUILDER_INIT`)

Die Textarea trägt immer die Klasse `navbuilder-text-input`. Über den Extension Point
`NAVBUILDER_INIT` lässt sich eine weitere Klasse anhängen, z. B. um einen WYSIWYG-Editor
anzudocken:

```php
// project/boot.php
rex_extension::register('NAVBUILDER_INIT', function (rex_extension_point $ep) {
    $init = $ep->getSubject();
    $init['textClass'] = 'tiny-editor';   // wird auf die Textarea gesetzt
    return $init;
});
```

Der Extension Point bekommt das komplette `window.NavBuilderInit`-Array, kurz bevor es als JSON
in die Seite geschrieben wird (siehe Kopf von `pages/menus.php`).

### Einzelne Einträge stylen

Jeder Knoten trägt genug Identität, damit eine Überschreibung auf einen einzelnen Eintrag
verzweigen kann — ohne über Beschriftungen zu gehen, die sich ändern. `id` ist die stabile
interne ID (im Bearbeiten-Formular im Backend sichtbar), `articleId` und `categoryId` stammen aus
dem aufgelösten Artikel:

```php
// project/fragments/navbuilder/item.php
$item = (array) $this->getVar('item', []);
$classes = ['nav__item', 'nav__item--' . rex_escape($item['id'])];          // genau dieser Eintrag
$classes[] = 'nav__item--cat-' . (int) ($item['categoryId'] ?? 0);          // ganze Kategorie
$highlight = 22 === ($item['articleId'] ?? null);                           // ein Artikel
```

`categoryId` ist bei `link`-, `media`-, `url`- und `text`-Einträgen `null`, vor dem Vergleich also
casten; `url`-Einträge tragen stattdessen `profileId`/`dataId` — der stabile Anknüpfungspunkt für
einen einzelnen Datensatz.

## Veraltete API

Bleibt als dünner Shim erhalten, eine Entfernung ist vor einem künftigen Major nicht geplant:

| veraltet | stattdessen | warum |
|---|---|---|
| `rex_navbuilder::get($name)` | `rex_navbuilder::render($name)` | die fest verdrahteten `rex-*`-Klassen sind weg; die Ausgabe kommt jetzt aus Fragmenten |
| `rex_navbuilder::getStructure($name)` | `rex_navbuilder::tree($name)` | Knoten haben `activePath`, `online`, `url` bekommen; Offline-Artikel werden jetzt einheitlich gefiltert (die beiden Einstiegspunkte in v1 waren sich darin uneinig) |

## Lizenz

[MIT](LICENSE.md)

## Credits

Ursprünglicher Autor: Peter Thiel ([@thielpeter](https://github.com/thielpeter)).
