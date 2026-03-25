# Task Manager für IP-Symcon

**Autor:** Christian Hagedorn  
**Version:** 1.0  
**Kompatibilität:** IP-Symcon ab Version 8.0  
**GitHub:** https://github.com/Hagi0815/Todo-Liste-IPSview

---

## Beschreibung

Der Task Manager ist ein IP-Symcon Modul das eine vollständige Aufgabenverwaltung direkt in IPS View und im WebFront ermöglicht. Alle Interaktionen (Aufgaben anlegen, bearbeiten, abhaken, löschen) erfolgen direkt in der HTMLBox ohne die Konfigurationsoberfläche öffnen zu müssen.

---

## Funktionen

- **Aufgaben anlegen** mit Titel, Beschreibung, Priorität und Fälligkeitsdatum
- **Aufgaben bearbeiten** per Klick auf den Aufgabentitel
- **Aufgaben abhaken** als erledigt markieren
- **Aufgaben löschen** einzeln oder alle erledigten auf einmal
- **Statistik-Leiste** mit Anzeige offener, überfälliger und heute fälliger Aufgaben
- **Prioritäten** Hoch (rot), Normal (grün), Niedrig (grau)
- **Farbiges Design** mit Dark Mode und Light Mode
- **Schriftgröße** einstellbar
- **Push-Benachrichtigungen** bei Erstellen, Ändern, Erledigen und Löschen von Aufgaben

---

## Installation

1. In IP-Symcon unter **Kern → Modulverwaltung** die URL des GitHub-Repos hinzufügen:  
   `https://github.com/Hagi0815/Todo-Liste-IPSview`
2. Branch `main` auswählen und bestätigen
3. Unter **Instanz hinzufügen** nach **Task Manager** suchen und eine neue Instanz anlegen
4. Die Variable **HtmlBox** (`~HTMLBox`) in IPS View als Kachel einbinden

---

## Einstellungen (Instanzkonfiguration)

| Einstellung | Beschreibung | Standard |
|---|---|---|
| Dunkles Design | Dark Mode aktivieren | Ein |
| Statistik-Leiste anzeigen | Zeigt Statistik-Kacheln oben an | Ein |
| Max. erledigte Aufgaben anzeigen | Wie viele erledigte Aufgaben maximal angezeigt werden (0 = alle) | 10 |
| Schriftgröße | Schriftgröße der Ansicht in Pixel | 14px |
| Push-Benachrichtigungen aktivieren | Aktiviert alle Push-Benachrichtigungen | Ein |
| Text bei neuer Aufgabe | Nachrichtentext wenn eine Aufgabe erstellt wird | `Neue Aufgabe: {title}` |
| Text bei Änderung | Nachrichtentext wenn eine Aufgabe geändert wird | `Aufgabe geaendert: {title}` |
| Text bei Erledigt | Nachrichtentext wenn eine Aufgabe abgehakt wird | `Aufgabe erledigt: {title}` |
| Text bei Löschen | Nachrichtentext wenn eine Aufgabe gelöscht wird | `Aufgabe geloescht: {title}` |
| Notification Center Instanz | Die IP-Symcon Notification Center Instanz | – |
| Ziel-Instanzen | Liste der IPS View Medien-Objekte die Benachrichtigungen erhalten | – |

### Platzhalter in Nachrichtentexten

| Platzhalter | Wird ersetzt durch |
|---|---|
| `{title}` | Titel der Aufgabe |
| `{priority}` | Priorität (Hoch / Normal / Niedrig) |
| `{due}` | Fälligkeitsdatum (Format: dd.mm.yy hh:mm) oder `kein Datum` |

---

## PHP-Befehlsreferenz

### TM_AddTask

Legt eine neue Aufgabe programmatisch an.

```php
TM_AddTask(int $InstanzID, string $Titel, string $Beschreibung, string $Priorität, int $Fälligkeit);
```

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$InstanzID` | int | ID der Task Manager Instanz |
| `$Titel` | string | Titel der Aufgabe |
| `$Beschreibung` | string | Optionale Beschreibung / Notiz |
| `$Priorität` | string | `'low'`, `'normal'` oder `'high'` |
| `$Fälligkeit` | int | Unix-Timestamp des Fälligkeitsdatums, `0` = kein Datum |

**Rückgabe:** `int` – die ID der neu erstellten Aufgabe

**Beispiele:**

```php
// Einfache Aufgabe ohne Fälligkeit
TM_AddTask(12345, 'Einkaufen', '', 'normal', 0);

// Aufgabe mit hoher Priorität und Fälligkeitsdatum
TM_AddTask(12345, 'Steuererklärung', 'Unterlagen vorbereiten', 'high', mktime(0, 0, 0, 12, 31, 2025));

// Aufgabe morgen fällig
TM_AddTask(12345, 'Zahnarzt anrufen', '', 'low', strtotime('+1 day'));
```

---

### TM_DeleteAllCompleted

Löscht alle als erledigt markierten Aufgaben.

```php
TM_DeleteAllCompleted(int $InstanzID);
```

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$InstanzID` | int | ID der Task Manager Instanz |

**Beispiel:**

```php
TM_DeleteAllCompleted(12345);
```

---

### TM_Refresh

Aktualisiert die HTMLBox-Anzeige manuell. Wird normalerweise automatisch nach jeder Änderung aufgerufen.

```php
TM_Refresh(int $InstanzID);
```

| Parameter | Typ | Beschreibung |
|---|---|---|
| `$InstanzID` | int | ID der Task Manager Instanz |

**Beispiel:**

```php
TM_Refresh(12345);
```

---

## Statusvariablen

| Variable | Typ | Beschreibung |
|---|---|---|
| `TasksJson` | String | Alle Aufgaben als JSON-Array (interne Verwendung) |
| `HtmlBox` | String (~HTMLBox) | Die interaktive Benutzeroberfläche für IPS View |
| `OpenTasks` | Integer | Anzahl der offenen (nicht erledigten) Aufgaben |
| `OverdueTasks` | Integer | Anzahl der überfälligen Aufgaben |

---

## WebHook

Das Modul registriert automatisch einen WebHook unter:

```
/hook/taskmanager_<InstanzID>
```

Über diesen WebHook kommuniziert die HTMLBox mit dem Modul (POST-Requests für Aktionen, GET-Request liefert die HTML-Seite).

---

## Lizenz

Frei verwendbar für private Zwecke.
