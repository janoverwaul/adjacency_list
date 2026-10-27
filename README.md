# adjacency_list – umgezogen

> **Dieses Repository wird nicht mehr gepflegt.**
> Die Klasse lebt jetzt zusammen mit dem Tree Editor in
> **[janoverwaul/nested_list](https://github.com/janoverwaul/nested_list)** (Version 2.0).

## Was sich geändert hat

- Basisklasse und Editor (früher `adjacency_list_tree_editor`) sind in **einem** Repository zusammengeführt.
- Die Klasse heißt jetzt `AdjacencyList` (`src/AdjacencyList.php`), die Erweiterung mit Zusatzfeldern `MetaTree`.
- Alle Methoden haben neue, einheitliche Namen, z. B. `get_menge()` → `get_subtree()`, `insert_knoten()` → `insert_node()`, `del_knoten()` → `delete_node()`.
- Mehrere Fehler sind behoben, unter anderem verwaiste Meta-Zeilen beim Löschen und fehlende Zielprüfung beim Verschieben.

Die vollständige Liste aller Umbenennungen und Korrekturen steht im
[CHANGELOG von nested_list](https://github.com/janoverwaul/nested_list/blob/main/CHANGELOG.md).

Der Code in diesem Repository (Version 1.0) bleibt zum Nachlesen erhalten.
