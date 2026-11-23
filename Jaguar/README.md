# Jaguar

Statuswerte eines Jaguar oder Land Rover aus dem **JLR Data Hub**. Das Modul liest nur; Befehle wie Verriegeln bietet die Schnittstelle nicht.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Visualisierung](#5-statusvariablen-und-visualisierung)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

- Kilometerstand, Tank, Reichweite, Service-Strecke
- Verriegelung, Kofferraum, Türen, Fenster, Haube, Verdeck, Alarm
- Reifendruck und Reifendruckwarnung je Rad
- 12-V-Spannung, Warnungen für Wischwasser, Bremsflüssigkeit, Kühlmittel und Öl
- Position
- Kachel für die Visualisierung mit Übersicht, Karte, Fahrtenbuch und Kilometer-Statistik der letzten 30 Tage

### 2. Voraussetzungen

- IP-Symcon ab 9.0
- Eine gekoppelte Instanz [Jaguar Account](../JaguarAccount/README.md)

### 3. Software-Installation

Über den Module Store oder in der Konsole unter *Module Control* die URL `https://github.com/captmajac/IPSJaguar` hinzufügen.

### 4. Einrichten der Instanzen in IP-Symcon

Unter *Instanz hinzufügen* die Instanz *Jaguar* auswählen. Pro Fahrzeug eine Instanz; alle nutzen dieselbe Konto-Instanz.

| Einstellung | Bedeutung |
|---|---|
| Fahrzeug (FIN) | Ohne Auswahl das erste Fahrzeug des Clients |
| Position lesen | Das Fahrzeug muss dafür auf data.jlr.com deklariert sein |
| Aktualisierungsintervall | 5 bis 1440 Minuten, Standard 15 |

### 5. Statusvariablen und Visualisierung

Variablen entstehen nur für Werte, die das Fahrzeug liefert. Name, Position und Darstellung gehören danach dir; das Modul ändert sie nicht mehr. **Alle Werte des Fahrzeugs anzeigen** listet auch Werte ohne eigene Variable.

Geliefert wird der zuletzt gemeldete Stand, das Fahrzeug wird nicht geweckt. Wie alt er ist, zeigt **Letzte Meldung des Fahrzeugs**. Direkt nach der Kopplung bleibt alles leer, bis sich das Fahrzeug einmal gemeldet hat, in der Regel bei der nächsten Fahrt.

Die Instanz bringt eine eigene **Kachel** mit:

- **Übersicht:** Tank, Reichweite, Kilometerstand, 12-V-Spannung, Verriegelung, Türen, Fenster, Reifendruck, Warnungen und Standort. Die Kachel zeigt nur an, denn die Schnittstelle kann das Fahrzeug nicht steuern.
- **Ruhemodus:** JLR meldet keinen Schlafzustand. Die Kachel zeigt „Ruhemodus“, wenn das Fahrzeug sich seit mindestens 4 Tagen nicht gemeldet hat. Das ist eine Ableitung; dieselbe Anzeige entsteht auch, wenn das Fahrzeug so lange keinen Empfang hat.
- **Fahrtenbuch:** Die letzten 30 Fahrten mit Zeit, Strecke, Dauer und Durchschnittsgeschwindigkeit, soweit JLR sie für das Fahrzeug liefert. Start- und Zielort speichert das Modul bewusst nicht. Lehnt JLR die Abfrage ab, zeigt der Tab einen Hinweis und das Modul fragt einmal täglich erneut.
- **Statistik:** Kilometer je Tag der letzten 30 Tage. Sie wird aus den Änderungen des Kilometerstands berechnet und beginnt mit der Installation. Wird über mehrere Tage nichts gemeldet, zählt die Strecke auf den Tag der nächsten Meldung.
- **Karte:** Sie kommt von OpenStreetMap. Der Browser, der die Kachel anzeigt, überträgt dafür die Fahrzeugposition an OpenStreetMap. Wer das nicht möchte, schaltet **Position lesen** aus.

### 6. PHP-Befehlsreferenz

Keine öffentlichen Funktionen. Aktualisieren über `IPS_RequestAction($InstanzID, 'Update', '');`.
