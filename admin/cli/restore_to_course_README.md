# CLI Restore to Course - Dokumentation

## Beschreibung

Das Skript `restore_to_course.php` ermöglicht die Wiederherstellung einer Moodle Backup-Datei (.mbz) in einen existierenden Kurs über die Kommandozeile.

## Features

- ✅ Wiederherstellung von Backup-Dateien (.mbz) in existierende Kurse
- ✅ Merge/Import-Funktion: Bestehende Kursinhalte bleiben erhalten
- ✅ Vollständige Validierung von Eingabeparametern
- ✅ Detaillierte Fortschrittsanzeigen
- ✅ Automatische Bereinigung bei Fehlern
- ✅ Verwendung der Moodle Core Restore APIs

## Verwendung

### Syntax

```bash
php admin/cli/restore_to_course.php --courseid=<ID> --file=<PFAD>
```

### Parameter

| Parameter | Kurzform | Typ | Pflicht | Beschreibung |
|-----------|----------|-----|---------|--------------|
| `--courseid` | `-c` | INT | Ja | ID des Zielkurses, in den wiederhergestellt werden soll |
| `--file` | `-f` | STRING | Ja | Pfad zur Backup-Datei (.mbz) |
| `--showdebugging` | `-s` | Flag | Nein | Aktiviert Developer-Debugging-Modus |
| `--help` | `-h` | Flag | Nein | Zeigt die Hilfe an |

### Beispiele

#### Einfache Wiederherstellung

```bash
sudo -u www-data php admin/cli/restore_to_course.php \
  --courseid=2 \
  --file=/path/to/backup.mbz
```

#### Mit Debugging

```bash
sudo -u www-data php admin/cli/restore_to_course.php \
  --courseid=5 \
  --file=/tmp/course_backup_2025.mbz \
  --showdebugging
```

#### Kurzform der Parameter

```bash
sudo -u www-data php admin/cli/restore_to_course.php \
  -c 10 \
  -f /backups/my_course.mbz
```

## Ablauf

Das Skript führt folgende Schritte aus:

1. **Validierung**
   - Prüfung der Admin-Rechte
   - Validierung der Kurs-ID
   - Prüfung der Backup-Datei (Existenz, Lesbarkeit, .mbz Extension)

2. **Extraktion**
   - Entpacken der .mbz Datei in ein temporäres Verzeichnis

3. **Preprocessing**
   - Analyse der Backup-Informationen
   - Anzeige von Backup-Typ, Original-Kurs und Datum

4. **Precheck**
   - Ausführung der Moodle Restore-Vorprüfungen
   - Erkennung möglicher Probleme vor der eigentlichen Wiederherstellung

5. **Wiederherstellung**
   - Ausführung des Restore-Plans
   - Import der Backup-Inhalte in den Zielkurs

6. **Cleanup**
   - Aufräumen temporärer Dateien
   - Anzeige der Erfolgsmeldung mit Kurs-URL

## Fehlerbehandlung

Das Skript führt umfassende Fehlerprüfungen durch:

- **Ungültige Kurs-ID**: Fehlermeldung bei nicht existierendem Kurs
- **Fehlende Backup-Datei**: Prüfung auf Existenz und Lesbarkeit
- **Falsches Dateiformat**: Nur .mbz Dateien werden akzeptiert
- **Restore-Fehler**: Bei Fehlern während der Wiederherstellung wird automatisch aufgeräumt

Alle Fehler werden mit aussagekräftigen Meldungen ausgegeben.

## Technische Details

### Verwendete Moodle APIs

- `restore_controller`: Hauptklasse für die Restore-Operationen
- `backup::TARGET_EXISTING_ADDING`: Merge-Modus (Inhalte hinzufügen)
- `get_file_packer()`: Extraktion der .mbz Archive
- `cli_heading()`, `mtrace()`: CLI-Ausgaben

### Berechtigungen

Das Skript muss als Web-Server-User ausgeführt werden:

```bash
sudo -u www-data php admin/cli/restore_to_course.php ...
```

### Temporäre Dateien

Temporäre Dateien werden in `$CFG->tempdir` erstellt und nach erfolgreichem Abschluss oder bei Fehlern automatisch gelöscht.

## Unterschied zu restore_backup.php

Das vorhandene `restore_backup.php` Skript bietet mehr Optionen:
- Wiederherstellung in neue Kurse (über categoryid)
- Wiederherstellung in existierende Kurse

Das neue `restore_to_course.php` Skript ist fokussiert auf:
- **Nur** Wiederherstellung in existierende Kurse
- Klarere, spezifischere Parameter
- Bessere Validierung und Fehlerbehandlung
- Detailliertere Fortschrittsanzeigen

Verwenden Sie `restore_to_course.php` wenn Sie explizit in einen existierenden Kurs importieren möchten.

## Logs und Debugging

Bei Problemen:

1. Aktivieren Sie das Debugging: `--showdebugging`
2. Prüfen Sie die Moodle-Logs unter "Site administration → Reports → Logs"
3. Überprüfen Sie die Berechtigungen der Backup-Datei

## Sicherheit

- Das Skript verwendet automatisch den Admin-User für die Wiederherstellung
- Alle Eingaben werden validiert
- Moodle Core Restore APIs werden verwendet (keine direkten Datenbankzugriffe)
- Capability-Checks werden durch die Restore-Controller durchgeführt

## Support

Bei Problemen oder Fragen wenden Sie sich an das MBS Moodle Development Team.

