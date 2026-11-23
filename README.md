# IPSJaguar

IP-Symcon-Modul, das die Statuswerte eines Jaguar / Land Rover liest: Kilometerstand, Tank, Reichweite, Verriegelung, Türen, Fenster, Verdeck, Reifendruck, 12-V-Batterie, Warnungen und Position.

Die Daten kommen vom **JLR Data Hub**, der offiziellen Schnittstelle für Besitzer ([data.jlr.com](https://data.jlr.com), Doku: [developer.jlr.com](https://developer.jlr.com/docs/getting-started)). Die frühere App-Schnittstelle (InControl, wie in jlrpy) sperrt JLR seit 2026 für alles außer der eigenen App; sie wird hier nicht verwendet. Das Modul liest nur, es sendet keine Befehle ans Fahrzeug.

## Module

- [Jaguar Account](JaguarAccount/README.md): I/O-Instanz mit der Kopplung zu JLR
- [Jaguar](Jaguar/README.md): ein Fahrzeug mit seinen Statuswerten

## Voraussetzungen

- IP-Symcon ab 9.0
- JLR-Konto, dessen Fahrzeug auf [data.jlr.com](https://data.jlr.com) aufgelistet ist

## Einrichten

Ausführliche Schritt-für-Schritt-Anleitung mit Stolpersteinen: [docs/einrichtung.md](docs/einrichtung.md).

Es gibt zwei Instanzen: **Jaguar Account** (I/O) hält die Kopplung mit JLR, **Jaguar** ist ein Fahrzeug und liest über die Konto-Instanz. Mehrere Fahrzeuge können sich eine Konto-Instanz teilen.

1. Instanz „Jaguar“ anlegen; die Konto-Instanz „Jaguar Account“ wird als übergeordnete Instanz mit angelegt. Sie erzeugt ein eigenes Schlüsselpaar und zeigt unten das **Zertifikat**.
2. Auf [data.jlr.com](https://data.jlr.com) anmelden und einen Client anlegen:
   - **„Connect a device or headless system“**
   - Fahrzeug auswählen, **alle** Datenkategorien auswählen
   - Zertifikat vollständig einfügen (von `-----BEGIN CERTIFICATE-----` bis `-----END CERTIFICATE-----`)
   - für die Position: beim Fahrzeug die **Deklaration** ausfüllen (alleiniger oder geteilter Nutzer), sonst verweigert JLR den Standort
3. Die angezeigte Client-ID (`dsas-...`) in der Konto-Instanz eintragen und übernehmen.
4. „Mit JLR koppeln“ drücken, den angezeigten Link öffnen, mit dem JLR-Konto anmelden und freigeben. Die Konto-Instanz wird danach von selbst aktiv, die Fahrzeuge lesen sofort ihren ersten Status.

Der private Schlüssel verlässt Symcon nie; ein Passwort wird nirgends gespeichert. Das Zertifikat gilt ein Jahr, danach „Neues Zertifikat erzeugen“, beim Client hochladen und neu koppeln.

Variablen entstehen nur für Werte, die das Fahrzeug tatsächlich liefert. „Alle Werte des Fahrzeugs anzeigen“ listet alles, was JLR liefert, auch Werte ohne eigene Variable. Geliefert wird der zuletzt vom Fahrzeug gemeldete Stand; das Fahrzeug wird nicht geweckt.

## Ohne Symcon prüfen

```bash
../tools/php/php tests/probe.php
```

Führt dieselbe Kopplung auf der Kommandozeile durch, speichert Schlüssel, Token und Rohantworten in `tests/fixtures/raw/` (nicht in git) und zeigt, welche Variablen das Modul setzen würde. Das Skript hat ein eigenes Zertifikat; für Symcon danach einen zweiten Client anlegen oder das Zertifikat des Clients durch das der Konto-Instanz ersetzen.

## Tests

```bash
../tools/php/php tests/run.php
```
