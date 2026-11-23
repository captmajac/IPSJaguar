# Jaguar Account

I/O-Instanz („Socket") für den **JLR Data Hub**, die offizielle Schnittstelle für Besitzer von Jaguar und Land Rover. Sie hält die Kopplung mit JLR; die Fahrzeug-Instanzen lesen über sie.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Visualisierung](#5-statusvariablen-und-visualisierung)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

- Erzeugt Schlüsselpaar und Zertifikat; der private Schlüssel verlässt Symcon nicht.
- Kopplung per Freigabe im Browser, ohne dass ein Passwort gespeichert wird.
- Erneuert den Zugriff selbstständig, gemeinsam für alle angeschlossenen Fahrzeuge.

### 2. Voraussetzungen

- IP-Symcon ab 9.0
- JLR-Konto, dessen Fahrzeug auf [data.jlr.com](https://data.jlr.com) aufgelistet ist

### 3. Software-Installation

Über den Module Store oder in der Konsole unter *Module Control* die URL `https://github.com/captmajac/IPSJaguar` hinzufügen.

### 4. Einrichten der Instanzen in IP-Symcon

1. Das Zertifikat unten im Formular vollständig kopieren.
2. Auf [data.jlr.com](https://data.jlr.com) einen Client anlegen: **Connect a device or headless system**, Fahrzeuge und alle Datenkategorien wählen, das Zertifikat als Public Key einfügen.
3. Die angezeigte Client-ID (`dsas-...`) eintragen und übernehmen.
4. **Mit JLR koppeln** drücken, den Link öffnen, anmelden und freigeben. Die Instanz wird danach von selbst aktiv.

Das Zertifikat gilt ein Jahr. Danach **Neues Zertifikat erzeugen**, beim Client hochladen und neu koppeln.

### 5. Statusvariablen und Visualisierung

Die Instanz hat keine Variablen und keine Kachel.

### 6. PHP-Befehlsreferenz

Keine öffentlichen Funktionen.
