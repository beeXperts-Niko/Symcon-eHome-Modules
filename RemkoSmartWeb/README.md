# RemkoSmartWeb – Symcon 8.1 Modul

Objektorientierte Neuimplementierung der Remko Smart Wärmepumpe-Anbindung für IP-Symcon 8.1.

**Autor:** Niko Sinthern

## Technik

- **PHP 8.0+**: `declare(strict_types=1)`, Typen, `str_starts_with`
- **Trennung der Verantwortlichkeiten**:
  - **RemkoSmartWebClient**: HTTP-Client (GET, curl), immer `curl_close`, Timeout und Connect-Timeout
  - **RemkoSmartWebVariableRegistry**: Profile und Variablen-Definition (eine zentrale Datenstruktur), `ensureProfiles()`, `ensureVariables()`
  - **RemkoSmartWeb**: IPSModule, schlank, delegiert an Client und Registry
- **Kommunikation**: Lokales Gerät per HTTP (`http://{Address}/cgi-bin/`). Keine Cloud, kein Session-Management.
- **Endpunkte**: `index.cgi?read`, `heating.cgi?read`, `solar.cgi?read` (CSV); Schreiben über `index.cgi?{ident}={value}`.

## Dateien

- `module.php` – Modul und alle Klassen
- `module.json` – Modul-Metadaten, Prefix `RSW`
- `form.json` – Konfigurationsformular inkl. Button „Werte abrufen“
- `locale.json` – Übersetzungen (de)

## Installation

Modul-Ordner in die Symcon-Bibliothek legen (z. B. über Module Control / Repository-URL) und Instanz anlegen. **Address** = Hostname oder IP der Remko Smart Wärmepumpe im lokalen Netzwerk.

## Anforderungen

- IP-Symcon 8.1
- PHP 8.0 oder höher
- Remko Smart Wärmepumpe im gleichen Netzwerk erreichbar (HTTP, Port 80)
