# Einrichtung: Jaguar mit dem JLR Data Hub koppeln

Stand: November 2026, durchgeführt mit einem Jaguar F-Type.

Diese Anleitung beschreibt den Weg, der tatsächlich funktioniert hat, und die Stolpersteine auf dem Weg dorthin.

## Überblick

| Was | Wo |
| --- | --- |
| Portal für Besitzer | [data.jlr.com](https://data.jlr.com) |
| Dokumentation von JLR | [developer.jlr.com](https://developer.jlr.com/docs/getting-started) |
| Anmeldung (Token) | `auth.jlr-vcdp.com` |
| Fahrzeugdaten | `data.jlr-vcdp.com` |

In Symcon gibt es zwei Instanzen:

- **Jaguar Account** (I/O, der „Socket“): hält Schlüsselpaar, Client-ID und die Kopplung mit JLR. Einmal pro JLR-Konto.
- **Jaguar** (Fahrzeug): liest über die Konto-Instanz den Status einer FIN. Mehrere Fahrzeuge können dieselbe Konto-Instanz nutzen.

Das Prinzip: Die Konto-Instanz erzeugt ein Schlüsselpaar. Das Zertifikat (öffentlicher Teil) hinterlegst du bei JLR, der private Schlüssel bleibt in Symcon. Danach gibst du den Zugriff einmal im Browser frei. Ein Passwort wird nirgends eingetragen.

## Schritt für Schritt

### 1. Zertifikat holen

- **In Symcon:** Instanz „Jaguar" anlegen; Symcon legt dabei die Konto-Instanz „Jaguar Account" als übergeordnete Instanz mit an (oder diese direkt anlegen). Im Formular der Konto-Instanz steht unten das Feld „Zertifikat für data.jlr.com".
- **Ohne Symcon (Test):** `../tools/php/php tests/probe.php` starten. Das Skript gibt das Zertifikat aus und wartet auf die Client-ID.

Kopiert wird der ganze Block, von `-----BEGIN CERTIFICATE-----` bis einschließlich `-----END CERTIFICATE-----`.

### 2. Client auf data.jlr.com anlegen

1. Auf [data.jlr.com](https://data.jlr.com) mit dem JLR-Konto anmelden (dasselbe wie in der Remote-App).
2. Bei der Frage nach der Verbindungsart **„Connect a device or headless system"** wählen.
   Nicht „Connect via browser" – das ist für Web-Apps mit eigener Anmeldeseite.
3. Das Fahrzeug auswählen.
4. Bei „Select the data categories that you would like to share" alle fünf anhaken:

   | Kategorie | Wofür |
   | --- | --- |
   | Status | Tank, Kilometerstand, Türen, Fenster, Reifendruck – zwingend |
   | Position | Breiten- und Längengrad |
   | Attributes | Marke, Modell, Baujahr |
   | Diagnostics | derzeit ungenutzt, für spätere Erweiterungen |
   | Journeys | derzeit ungenutzt |

5. Bei „Public key" bzw. „Public certificate" das Zertifikat aus Schritt 1 vollständig einfügen.
6. Die Seite zeigt danach die **Client-ID**. Sie sieht aus wie `dsas-0123456789ab`.

### 3. Client-ID eintragen

- **In Symcon:** in der Konto-Instanz ins Feld „Client-ID" eintragen und „Übernehmen".
- **Im Probe-Skript:** in die wartende Eingabe einfügen.

### 4. Zugriff freigeben

- **In Symcon:** in der Konto-Instanz „Mit JLR koppeln" drücken. Es erscheint ein Link und ein Code.
- **Im Probe-Skript:** Link und Code erscheinen direkt nach der Client-ID.

Den Link im Browser öffnen, mit dem JLR-Konto anmelden und freigeben. Der Code steckt schon im Link und muss nicht abgetippt werden. Symcon bzw. das Skript fragt alle paar Sekunden nach und wird nach der Freigabe von selbst aktiv („Waiting for the approval .... ok"). Die Fahrzeug-Instanzen lesen danach sofort ihren ersten Status.

Bei mehreren Fahrzeugen: in jeder Fahrzeug-Instanz die FIN auswählen. Ohne Auswahl gilt das erste Fahrzeug des Clients.

Der Button **„Link your device"** im Portal ist dafür nicht nötig: Er ist ein zweiter Weg zur selben Freigabe, bei dem man den Code von Hand eingibt.

### 5. Position freischalten (optional)

JLR gibt den Standort erst heraus, wenn das Fahrzeug auf data.jlr.com **deklariert** ist: in der Fahrzeugliste angeben, ob man alleiniger oder geteilter Nutzer ist. Ohne Deklaration funktioniert der restliche Status trotzdem.

### 6. Erste Daten

Direkt nach der Kopplung war der Status leer (`{"Vehicle":{}}`). JLR sammelt die Daten für den Data Hub erst ab der Freigabe; das Fahrzeug muss sich also einmal melden. Bestätigt: Nach einer Fahrt lieferte der Hub 56 Werte mit dem Stand vom Ende der Fahrt, einschließlich Position. Danach „Jetzt aktualisieren" drücken bzw. das Probe-Skript erneut starten.

Der Hub hält den zuletzt gemeldeten Stand: Auch später kamen die Werte vom Abstellen, das Auto wurde dafür nicht geweckt.

Gemessen am F-Type:

- Kilometerstand kommt in Metern (`50000000` = 50.000 km).
- Reifendruck kommt in kPa (`215` = 2,15 bar). Das Home-Assistant-Projekt nennt hPa – beim getesteten F-Type stimmt das nicht.
- Reifendruckwarnung je Rad als Text (`NORMAL`, `WARNING_LOW_SOFT`).
- Auch ein Zweitürer meldet hintere Türen und Fenster (immer `CLOSED`). Die Variablen dafür entstehen, sagen aber nichts; sie lassen sich in Symcon ausblenden. Gelöschte Variablen legt das Modul bei der nächsten Abfrage wieder an.
- Die Position kam ohne eigenen Zeitstempel, mit dem Zeitstempel des Status.

### 7. Kachel anzeigen

Die Instanz „Jaguar" in der Visualisierung (Kachel-Visualisierung) einfügen. Die Kachel zeigt die Tabs **Übersicht** und **Statistik**; die Statistik füllt sich ab jetzt mit jeder neuen Meldung des Fahrzeugs. Die Karte kommt von OpenStreetMap und bekommt dafür die Fahrzeugposition vom anzeigenden Browser. Ohne **Position lesen** entfällt sie.

## Stolpersteine

| Symptom | Ursache | Lösung |
| --- | --- | --- |
| `HTTP 498` beim Login mit E-Mail und Passwort | Die alte App-Schnittstelle (InControl, `…prod-row.jlrmotor.com`, wie in jlrpy) ist seit 2026 per App-Attestierung für alles außer der JLR-App gesperrt. | Nicht verwenden. Nur der Data Hub funktioniert. |
| `403 {"error":"client_error","message":"Forbidden"}` auf allen Datenabfragen, obwohl der Token Fahrzeug und Kategorien enthält | JLR lehnt jede Anfrage ohne `User-Agent`-Header ab. PHP/cURL schickt von sich aus keinen. | Das Modul schickt `User-Agent: IPSJaguar/1.0`. |
| `403` mit „Access to category is not permitted for this vehicle" nur bei der Position | Fahrzeug nicht deklariert. | Schritt 5. |
| Status leer, Zeitstempel ist die aktuelle Uhrzeit | Fahrzeug hat sich seit der Freigabe noch nicht gemeldet. | Einmal fahren (Schritt 6). |
| Kopplung wird abgelehnt (`invalid_client`) | Client-ID falsch, oder beim Client ist ein anderes Zertifikat hinterlegt. | Client-ID prüfen, Zertifikat der Instanz neu einfügen. |
| Ein verkauftes Fahrzeug steht noch in der Liste | Es hängt noch am JLR-Konto. | In der Remote-App bzw. im InControl-Konto entfernen, sonst über Händler oder JLR-Kundendienst. Für das Modul unschädlich: der Client gilt nur für die ausgewählten Fahrzeuge. |

## Gut zu wissen

- **Nur ein Device pro JLR-Konto.** Auf data.jlr.com lässt sich nur ein Device anlegen. Mehrere Fahrzeuge laufen deshalb über denselben Client und dieselbe Konto-Instanz.
- **Ein Zertifikat pro Client.** Das Probe-Skript und eine Konto-Instanz haben je ein eigenes Schlüsselpaar. Wer vom Skript zu Symcon wechselt, ersetzt beim Client das Zertifikat und koppelt neu; die Client-ID bleibt. Das Skript wird danach abgewiesen (`Invalid client credentials`).
- **Gültigkeit:** Das Zertifikat gilt ein Jahr. Danach in der Konto-Instanz „Neues Zertifikat erzeugen", beim Client hochladen und neu koppeln.
- **Token:** Der Zugriffstoken gilt nur 5 Minuten (die JLR-Doku nennt 1 Stunde). Das Modul erneuert ihn bei jeder Abfrage; der Erneuerungstoken wechselt dabei jedes Mal und wird sofort gespeichert.
- **Technik:** Token-Anfragen sind mit dem privaten Schlüssel signiert (`private_key_jwt`, PS256), jede Datenabfrage trägt einen DPoP-Nachweis mit demselben Schlüssel. PHP kann PS256 nicht von Haus aus; `libs/DataHubClient.php` rechnet die PSS-Signatur selbst.
- **Fehlersuche:** `../tools/php/php tests/diagnose.php` zeigt, welche Fahrzeuge und Kategorien der Token enthält, und die Rohantwort jedes Endpunkts.
