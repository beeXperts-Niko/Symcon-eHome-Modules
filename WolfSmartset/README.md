# WolfSmartset – Symcon 8.1 Modul

Objektorientierte Neuimplementierung der Wolf Smartset Portal-Anbindung für IP-Symcon 8.1.

**Autor:** Niko Sinthern

## Technik

- **PHP 8.0+**: `declare(strict_types=1)`, Typen, `match`, `str_starts_with`
- **Trennung der Verantwortlichkeiten**:
  - **WolfPortalClient**: HTTP-Client (curl), nur JSON/Form-Requests, immer `curl_close`
  - **WolfSessionManager**: Token-Lifecycle (UpdateSession, Login, Logout)
  - **WolfVariableBuilder**: GUI-Beschreibung → Symcon-Variablen/Kategorien
  - **WolfSmartset**: IPSModule, schlank, delegiert an die obigen Klassen
- **Session-Lifecycle**:
  - Eine Session wird wiederverwendet (`UpdateSession`), keine neuen Logins pro Request
  - Bei abgelaufenem Token: automatischer Neu-Login
  - Beim Entfernen der Instanz (`Destroy`) und per Button „Session beenden“: Token löschen
- **Sicherheit**: SSL-Verifizierung aktiv (`CURLOPT_SSL_VERIFYPEER`), keine Passwörter in Logs

## Dateien

- `module.php` – Modul und alle Klassen
- `module.json` – Modul-Metadaten, Prefix `WSS`
- `form.json` – Konfigurationsformular
- `locale.json` – Übersetzungen (de)

## Installation

Modul-Ordner in die Symcon-Bibliothek legen (z. B. über Module Control / Repository-URL) und Instanz anlegen.

## Anforderungen

- IP-Symcon 8.1
- PHP 8.0 oder höher (für Typen und moderne Syntax)
- Wolf Smartset Portal-Zugang (Benutzer, Passwort, Experten-Passwort, Standard 1111)
