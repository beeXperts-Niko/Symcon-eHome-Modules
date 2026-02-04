# Symcon eHome Module

IP-Symcon 8.1 Module für Heiztechnik (Wolf Smartset Portal, Remko Smart Wärmepumpe).

**Autor:** [Niko Sinthern](https://github.com/beeXperts-Niko)

---

## Module

### WolfSmartset

Anbindung an das **Wolf Smartset Online-Portal**. Objektorientiert, sauberer Session-Lifecycle (UpdateSession, Logout), eine Session pro Instanz.

- Ordner: `WolfSmartset/`
- Prefix: `WSS`
- [WolfSmartset – README](WolfSmartset/README.md)

### RemkoSmartWeb

Anbindung an die **Remko Smart Wärmepumpe** im lokalen Netzwerk (HTTP/CGI).

- Ordner: `RemkoSmartWeb/`
- Prefix: `RSW`
- [RemkoSmartWeb – README](RemkoSmartWeb/README.md)

---

## Installation

Module per **Module Control** in IP-Symcon einbinden (Repository-URL oder Kopie der Ordner).

**Repository-URL (GitHub):**
```
https://github.com/beeXperts-Niko/Symcon-eHome-Modules
```

## Anforderungen

- IP-Symcon 8.1
- PHP 8.0+
