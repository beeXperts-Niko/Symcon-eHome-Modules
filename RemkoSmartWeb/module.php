<?php

declare(strict_types=1);

/**
 * Remko SmartWeb – Symcon 8.1 Modul
 *
 * Objektorientierte Anbindung an die Remko Smart Wärmepumpe (lokales Gerät per HTTP).
 * Kommunikation über CGI-Endpunkte (index.cgi, heating.cgi, solar.cgi).
 *
 * @author Niko Sinthern
 */

// -----------------------------------------------------------------------------
// Konstanten
// -----------------------------------------------------------------------------

const RSW_CGI_PATH = '/cgi-bin/';
const RSW_HTTP_TIMEOUT = 15;
const RSW_USER_AGENT = 'Symcon-RemkoSmartWeb/2.0 (IP-Symcon 8.1)';

const RSW_STATUS_OK = 102;
const RSW_STATUS_INACTIVE = 104;
const RSW_STATUS_ERROR = 201;

// -----------------------------------------------------------------------------
// HTTP-Client für Remko-Gerät (lokales Netzwerk)
// -----------------------------------------------------------------------------

final class RemkoSmartWebClient
{
    private string $address;
    private ?IPSModule $logger;

    public function __construct(string $address, ?IPSModule $logger = null)
    {
        $this->address = trim($address);
        $this->logger = $logger;
    }

    /**
     * GET-Request an das Gerät. Schließt curl-Handle immer.
     *
     * @return string|null Response-Body oder null bei Fehler
     */
    public function get(string $path): ?string
    {
        $url = 'http://' . $this->address . RSW_CGI_PATH . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            $this->log('RemkoSmartWebClient', 'curl_init failed', 0);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => RSW_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => RSW_USER_AGENT,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->logger !== null) {
            $this->logger->SendDebug('RemkoSmartWebClient', "GET {$path} → {$httpCode}", 0);
        }

        if ($response === false) {
            $this->log('RemkoSmartWebClient', 'curl_error: ' . $error, 0);
            return null;
        }

        if ($httpCode >= 200 && $httpCode < 400) {
            return (string) $response;
        }

        return null;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    private function log(string $sender, string $message, int $level): void
    {
        if ($this->logger !== null && method_exists($this->logger, 'SendDebug')) {
            $this->logger->SendDebug($sender, $message, $level);
        }
    }
}

// -----------------------------------------------------------------------------
// Variable-Registry (Profile + Variablen-Definition)
// -----------------------------------------------------------------------------

final class RemkoSmartWebVariableRegistry
{
    /** @var array<string, array{type: int, min?: float, max?: float, step?: float, text?: string, associations?: array}> */
    private const PROFILES = [
        'RSW_OperationMode' => [
            'type' => 1,
            'associations' => [
                1 => 'Störung',
                2 => 'HZG Puffer',
                3 => 'Abtaupuffer',
                4 => 'WW Puffer',
                5 => 'solar Heizen',
                6 => 'Heizen',
                7 => 'Kühlen',
                8 => 'Pool',
                9 => 'Umwälzung',
                10 => 'Standby',
            ],
        ],
        'RSW_WaterOperationMode' => [
            'type' => 1,
            'associations' => [
                0 => 'Automatic Komfort',
                1 => 'Automatik Eco',
                2 => 'nur Solar',
            ],
        ],
        'RSW_HeatingMode' => [
            'type' => 1,
            'associations' => [
                1 => 'Automatik',
                2 => 'Heizen',
                3 => 'Standby',
                4 => 'Kühlen',
            ],
        ],
        'RSW_ActiveInactive' => [
            'type' => 0,
            'associations' => [0 => 'Deaktiviert', 1 => 'Aktiviert'],
        ],
        'RSW_AvailableInavailable' => [
            'type' => 0,
            'associations' => [0 => 'Nicht verfügbar', 1 => 'Verfügbar'],
        ],
        'RSW_WaterVolume' => ['type' => 2, 'text' => ' l/min'],
        'RSW_KG' => ['type' => 1, 'text' => ' KG'],
        'RSW_Percentage' => ['type' => 1, 'text' => ' %'],
    ];

    /**
     * Variablen: ident => [name, profile, sortId, action, hidden]
     * @var array<string, array{name: string, profile: string, sortId: int, action: bool, hidden: bool}> */
    private const VARIABLES = [
        'ID5033' => ['name' => 'Aktueller Betriebsmodus', 'profile' => 'RSW_OperationMode', 'sortId' => 6, 'action' => false, 'hidden' => false],
        'ID5032' => ['name' => 'Außentemperatur', 'profile' => '~Temperature', 'sortId' => 1, 'action' => false, 'hidden' => false],
        'ID1079' => ['name' => 'Warmwassermodus', 'profile' => 'RSW_WaterOperationMode', 'sortId' => 7, 'action' => true, 'hidden' => false],
        'ID1088' => ['name' => 'Raumklimamodus', 'profile' => 'RSW_HeatingMode', 'sortId' => 8, 'action' => true, 'hidden' => false],
        'ID1992' => ['name' => '1x Warmwasser', 'profile' => 'RSW_ActiveInactive', 'sortId' => 9, 'action' => true, 'hidden' => false],
        'ID1894' => ['name' => 'Partymodus', 'profile' => 'RSW_ActiveInactive', 'sortId' => 10, 'action' => true, 'hidden' => false],
        'ID1893' => ['name' => 'Abwesenheit', 'profile' => 'RSW_ActiveInactive', 'sortId' => 11, 'action' => true, 'hidden' => false],
        'ID1022' => ['name' => 'Cooling functionality', 'profile' => 'RSW_AvailableInavailable', 'sortId' => 12, 'action' => false, 'hidden' => true],
        'IDX1' => ['name' => 'Aktuelle Leistung', 'profile' => '~Power', 'sortId' => 5, 'action' => false, 'hidden' => false],
        'IDX2' => ['name' => 'Radiatorheizkreis', 'profile' => '~Temperature', 'sortId' => 2, 'action' => false, 'hidden' => false],
        'IDX3' => ['name' => 'Flächenheizkreis', 'profile' => '~Temperature', 'sortId' => 3, 'action' => false, 'hidden' => false],
        'IDX4' => ['name' => 'Heizungspuffer', 'profile' => '~Temperature', 'sortId' => 4, 'action' => false, 'hidden' => false],
        'IDX5' => ['name' => 'Volumenstrom', 'profile' => 'RSW_WaterVolume', 'sortId' => 6, 'action' => false, 'hidden' => false],
        'IDS1' => ['name' => 'Leistung Solar', 'profile' => '~Power', 'sortId' => 13, 'action' => false, 'hidden' => false],
        'IDS2' => ['name' => 'CO2-Einsparung', 'profile' => 'RSW_KG', 'sortId' => 14, 'action' => false, 'hidden' => false],
        'IDS3' => ['name' => 'Kollektor', 'profile' => '~Temperature', 'sortId' => 15, 'action' => false, 'hidden' => false],
        'IDS4' => ['name' => 'Warmwasser', 'profile' => '~Temperature', 'sortId' => 1, 'action' => false, 'hidden' => false],
        'IDS5' => ['name' => 'Solar', 'profile' => '~Temperature', 'sortId' => 17, 'action' => false, 'hidden' => false],
        'IDS6' => ['name' => 'Ladezustand Solar', 'profile' => 'RSW_Percentage', 'sortId' => 18, 'action' => false, 'hidden' => false],
    ];

    public function ensureProfiles(): void
    {
        foreach (self::PROFILES as $profileName => $config) {
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, $config['type']);
            }
            if (isset($config['text'])) {
                IPS_SetVariableProfileText($profileName, '', $config['text']);
            }
            if (isset($config['associations'])) {
                foreach ($config['associations'] as $value => $label) {
                    IPS_SetVariableProfileAssociation($profileName, $value, $label, '', -1);
                }
            }
        }
    }

    public function ensureVariables(IPSModule $module): void
    {
        $this->ensureProfiles();

        foreach (self::VARIABLES as $ident => $def) {
            $id = $module->GetIDForIdent($ident);
            if ($id !== false) {
                continue;
            }

            $profile = $def['profile'];
            $name = $def['name'];
            $sortId = $def['sortId'];
            $action = $def['action'];
            $hidden = $def['hidden'];

            if (str_starts_with($profile, '~') || $profile === '~Temperature' || $profile === '~Power') {
                if ($profile === '~Power') {
                    $module->RegisterVariableFloat($ident, $name, $profile, (float) $sortId);
                } else {
                    $module->RegisterVariableFloat($ident, $name, '~Temperature', (float) $sortId);
                }
            } elseif (in_array($profile, ['RSW_ActiveInactive', 'RSW_AvailableInavailable'], true)) {
                $module->RegisterVariableBoolean($ident, $name, $profile, (bool) ($sortId === 1));
            } else {
                $module->RegisterVariableInteger($ident, $name, $profile, $sortId);
            }

            if ($action) {
                $module->EnableAction($ident);
            } else {
                $module->DisableAction($ident);
            }

            if ($hidden) {
                $id = $module->GetIDForIdent($ident);
                if ($id !== false) {
                    IPS_SetHidden($id, true);
                }
            }
        }
    }

    /** @return array<string, array{name: string, profile: string, sortId: int, action: bool, hidden: bool}> */
    public static function getVariableDefinitions(): array
    {
        return self::VARIABLES;
    }
}

// -----------------------------------------------------------------------------
// Hauptmodul RemkoSmartWeb (Symcon 8.1)
// -----------------------------------------------------------------------------

class RemkoSmartWeb extends IPSModule
{
    private ?RemkoSmartWebClient $client = null;
    private ?RemkoSmartWebVariableRegistry $registry = null;

    private function client(): RemkoSmartWebClient
    {
        if ($this->client === null) {
            $address = $this->ReadPropertyString('Address');
            $this->client = new RemkoSmartWebClient($address, $this);
        }
        return $this->client;
    }

    private function registry(): RemkoSmartWebVariableRegistry
    {
        if ($this->registry === null) {
            $this->registry = new RemkoSmartWebVariableRegistry();
        }
        return $this->registry;
    }

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Address', '');
        $this->RegisterPropertyInteger('RefreshInterval', 60);
        $this->RegisterTimer('RemkoUpdate', 0, 'RSW_GetValues($_IPS[\'TARGET\']);');
        $this->registry()->ensureVariables($this);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $interval = max(30, $this->ReadPropertyInteger('RefreshInterval'));
        $this->SetTimerInterval('RemkoUpdate', $interval * 1000);
        $this->SetStatus(RSW_STATUS_INACTIVE);

        if ($this->ReadPropertyString('Address') !== '') {
            $this->GetValues();
        }
    }

    /** Öffentliche API (Prefix RSW_) */

    public function GetValues(): void
    {
        $address = $this->ReadPropertyString('Address');
        if ($address === '') {
            $this->SetStatus(RSW_STATUS_INACTIVE);
            return;
        }

        $this->client = new RemkoSmartWebClient($address, $this);

        $this->fetchIndexValues();
        $this->fetchHeatingValues();
        $this->fetchSolarValues();

        $this->SetStatus(RSW_STATUS_OK);
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        $this->WriteValue($ident, $value);
        $id = $this->GetIDForIdent($ident);
        if ($id !== false) {
            SetValue($id, $value);
        }
    }

    public function WriteValue(string $ident, $value): void
    {
        $address = $this->ReadPropertyString('Address');
        if ($address === '') {
            return;
        }

        $this->client = new RemkoSmartWebClient($address, $this);
        $normalized = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $this->client->get("index.cgi?{$ident}=" . rawurlencode($normalized));
    }

    // -------------------------------------------------------------------------
    // Private: CSV-Abruf und Zuordnung
    // -------------------------------------------------------------------------

    private function fetchIndexValues(): void
    {
        $data = $this->client()->get('index.cgi?read');
        if ($data === null || $data === '') {
            $this->SetStatus(RSW_STATUS_ERROR);
            return;
        }

        $values = $this->parseCsvLine($data);
        if (count($values) < 8) {
            return;
        }

        $this->setVariableValue('ID5033', (int) $values[0]);
        $this->setVariableValue('ID5032', (float) $values[1]);
        $this->setVariableValue('ID1079', (int) $values[2]);
        $this->setVariableValue('ID1088', (int) $values[3]);
        $this->setVariableValue('ID1992', (bool) (int) $values[4]);
        $this->setVariableValue('ID1894', (bool) (int) $values[5]);
        $this->setVariableValue('ID1893', (bool) (int) $values[6]);
        $this->setVariableValue('ID1022', (bool) (int) $values[7]);

        // Kühlen-Modus im Profil nur anzeigen, wenn Gerät Kühlung unterstützt
        $coolingAvailable = (bool) (int) $values[7];
        if ($coolingAvailable && IPS_VariableProfileExists('RSW_HeatingMode')) {
            IPS_SetVariableProfileAssociation('RSW_HeatingMode', 4, 'Kühlen', '', -1);
        }
    }

    private function fetchHeatingValues(): void
    {
        $data = $this->client()->get('heating.cgi?read');
        if ($data === null || $data === '') {
            return;
        }

        $values = $this->parseCsvLine($data);
        if (count($values) < 5) {
            return;
        }

        $this->setVariableValue('IDX1', (float) $values[0]);
        $this->setVariableValue('IDX2', (float) $values[1]);
        $this->setVariableValue('IDX3', (float) $values[2]);
        $this->setVariableValue('IDX4', (float) $values[3]);
        $this->setVariableValue('IDX5', (float) $values[4]);
    }

    private function fetchSolarValues(): void
    {
        $data = $this->client()->get('solar.cgi?read');
        if ($data === null || $data === '') {
            return;
        }

        $values = $this->parseCsvLine($data);
        if (count($values) < 6) {
            return;
        }

        $this->setVariableValue('IDS1', (float) $values[0]);
        $this->setVariableValue('IDS2', (int) $values[1]);
        $this->setVariableValue('IDS3', (float) $values[2]);
        $this->setVariableValue('IDS4', (float) $values[3]);
        $this->setVariableValue('IDS5', (float) $values[4]);
        $this->setVariableValue('IDS6', (int) $values[5]);

        // Solar-Variablen ausblenden, wenn Kollektor-Wert 300 (nicht vorhanden)
        $hideSolar = isset($values[2]) && (float) $values[2] === 300.0;
        foreach (['IDS1', 'IDS2', 'IDS3', 'IDS5', 'IDS6'] as $ident) {
            $id = $this->GetIDForIdent($ident);
            if ($id !== false) {
                IPS_SetHidden($id, $hideSolar);
            }
        }
    }

    /** @return array<int, string> */
    private function parseCsvLine(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }
        return array_map('trim', explode(',', $line));
    }

    private function setVariableValue(string $ident, mixed $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ($id !== false && GetValue($id) != $value) {
            SetValue($id, $value);
        }
    }
}
