<?php

declare(strict_types=1);

/**
 * Wolf Smartset Portal – Symcon 8.1 Modul
 *
 * Objektorientierte Anbindung an das Wolf Smartset Online-Portal.
 * Session-Lifecycle: Eine Session wird wiederverwendet (UpdateSession),
 * bei Bedarf neu aufgebaut und beim Entfernen der Instanz bereinigt.
 *
 * @author Niko Sinthern
 */

// -----------------------------------------------------------------------------
// Konstanten
// -----------------------------------------------------------------------------

const WSS_BASE_URL = 'https://www.wolf-smartset.com/portal/';
const WSS_LANGUAGE = 'de-DE';
const WSS_HTTP_TIMEOUT = 30;
const WSS_USER_AGENT = 'Symcon-WolfSmartset/2.0 (IP-Symcon 8.1)';

// IPS-Statuscodes (Symcon Standard)
const WSS_STATUS_OK = 102;
const WSS_STATUS_INACTIVE = 104;
const WSS_STATUS_AUTH_FAILED = 201;
const WSS_STATUS_NO_CREDENTIALS = 202;
const WSS_STATUS_COMM_ERROR = 203;

// -----------------------------------------------------------------------------
// Wolf Portal HTTP-Client
// -----------------------------------------------------------------------------

final class WolfPortalClient
{
    private string $baseUrl;
    private string $language;
    private ?IPSModule $logger;

    public function __construct(string $baseUrl = WSS_BASE_URL, string $language = WSS_LANGUAGE, ?IPSModule $logger = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->language = $language;
        $this->logger = $logger;
    }

    /**
     * Führt einen JSON-API-Request aus. Schließt curl-Handle immer.
     * GetSystemList liefert ein JSON-Array → Rückgabe object|array|null.
     *
     * @param array<string> $headers
     * @param object|array<string, mixed>|null $body
     * @return object|array|null Dekodierte JSON-Antwort oder null bei Fehler
     */
    public function request(
        string $path,
        string $method = 'GET',
        array $headers = [],
        $body = null,
        bool $keepAlive = false
    ): object|array|null {
        $url = $this->baseUrl . ltrim($path, '/');

        $defaultHeaders = [
            'Accept-Language: ' . $this->language . ',de;q=0.8,en;q=0.6,en-US;q=0.4',
            'User-Agent: ' . WSS_USER_AGENT,
        ];
        $headers = array_merge($defaultHeaders, $headers);

        $postBody = null;
        if ($keepAlive) {
            $postBody = '{}';
            $headers[] = 'Content-Length: 2';
        } elseif ($body !== null) {
            if (is_object($body) || is_array($body)) {
                $postBody = json_encode($body);
                $headers[] = 'Content-Type: application/json;charset=UTF-8';
                $headers[] = 'Content-Length: ' . strlen($postBody);
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->log('WolfPortalClient', 'curl_init failed', 0);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => WSS_HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($this->logger !== null) {
            $this->logger->SendDebug('WolfPortalClient', "{$method} {$path} → {$httpCode}", 0);
        }

        if ($response === false) {
            $this->log('WolfPortalClient', 'curl_error: ' . $error, 0);
            return null;
        }

        $decoded = json_decode($response);
        if ($httpCode >= 200 && $httpCode < 300 && $decoded !== null) {
            return $decoded;
        }

        return null;
    }

    /**
     * POST mit application/x-www-form-urlencoded (z. B. OAuth connect/token).
     *
     * @param array<string, mixed> $formData
     * @return object|null
     */
    public function requestForm(string $path, array $formData): ?object
    {
        $url = $this->baseUrl . ltrim($path, '/');
        $postBody = http_build_query($formData);
        $headers = [
            'Accept-Language: ' . $this->language . ',de;q=0.8,en;q=0.6,en-US;q=0.4',
            'User-Agent: ' . WSS_USER_AGENT,
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($postBody),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => WSS_HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($this->logger !== null) {
            $this->logger->SendDebug('WolfPortalClient', "POST(form) {$path} → {$httpCode}", 0);
        }

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        $decoded = json_decode($response);
        return is_object($decoded) ? $decoded : null;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    private function log(string $sender, string $message, int $level): void
    {
        if ($this->logger !== null && method_exists($this->logger, 'SendDebug')) {
            $this->logger->SendDebug($sender, $message, $level);
        }
    }
}

// -----------------------------------------------------------------------------
// Session-Manager (Token-Lifecycle)
// -----------------------------------------------------------------------------

final class WolfSessionManager
{
    private IPSModule $module;
    private WolfPortalClient $client;

    public function __construct(IPSModule $module, WolfPortalClient $client)
    {
        $this->module = $module;
        $this->client = $client;
    }

    /**
     * Liefert gültige Auth-Header. Nutzt gespeicherten Token + UpdateSession,
     * oder führt neuen Login aus.
     *
     * @return array<string>|false
     */
    public function getAuthHeaders(): array|false
    {
        $tokenJson = $this->readStoredToken();
        if ($tokenJson !== '' && $tokenJson !== '0') {
            $headers = json_decode($tokenJson);
            if (is_array($headers)) {
                $ok = $this->client->request('api/portal/UpdateSession', 'POST', $headers, null, true);
                if ($ok !== null) {
                    return $headers;
                }
            }
            $this->clearStoredToken();
        }

        return $this->login();
    }

    /**
     * Neuer Login (connect/token + ExpertLogin). Nur eine Session pro Instanz.
     *
     * @return array<string>|false
     */
    public function login(): array|false
    {
        $username = $this->module->ReadPropertyString('Username');
        $password = $this->module->ReadPropertyString('Password');
        $expertPassword = $this->module->ReadPropertyString('ExpertPassword');

        if ($username === '' || $password === '') {
            $this->module->SetStatus(WSS_STATUS_NO_CREDENTIALS);
            return false;
        }

        $tokenData = $this->client->requestForm('connect/token', [
            'IsPasswordReset' => 'false',
            'IsProfessional' => 'true',
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'ServerWebApiVersion' => '2',
            'CultureInfoCode' => $this->client->getLanguage(),
        ]);

        if ($tokenData === null || !isset($tokenData->access_token)) {
            $this->module->SetStatus(WSS_STATUS_AUTH_FAILED);
            $this->clearStoredToken();
            return false;
        }

        $authHeaders = [
            'Authorization: ' . ($tokenData->token_type ?? 'Bearer') . ' ' . $tokenData->access_token,
            'Accept-Language: ' . $this->client->getLanguage() . ',de;q=0.8,en;q=0.6,en-US;q=0.4',
            'Content-Type: application/json;charset=UTF-8',
        ];

        $expertOk = $this->client->request(
            'api/portal/ExpertLogin?Password=' . rawurlencode($expertPassword) . '&_=' . time(),
            'GET',
            $authHeaders
        );

        if ($expertOk === null) {
            $this->module->SetStatus(WSS_STATUS_AUTH_FAILED);
            $this->clearStoredToken();
            return false;
        }

        $this->module->SetStatus(WSS_STATUS_OK);
        $this->writeStoredToken($authHeaders);
        return $authHeaders;
    }

    public function logout(): void
    {
        $this->clearStoredToken();
        $this->module->SetStatus(WSS_STATUS_INACTIVE);
    }

    /** Für Fehlerbehandlung (z. B. GetParameterValues-Error): Token verwerfen, nächster Aufruf macht Neu-Login. */
    public function clearStoredToken(): void
    {
        $tokenId = $this->getTokenVariableId();
        if ($tokenId !== false) {
            SetValueString($tokenId, '');
        }
    }

    private function readStoredToken(): string
    {
        $tokenId = $this->getTokenVariableId();
        if ($tokenId === false) {
            return '';
        }
        return (string) GetValueString($tokenId);
    }

    private function writeStoredToken(array $headers): void
    {
        $tokenId = $this->getTokenVariableId();
        if ($tokenId !== false) {
            SetValueString($tokenId, json_encode($headers));
        }
    }

    private function getTokenVariableId(): int|false
    {
        $connectionNode = $this->module->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return false;
        }
        $id = IPS_GetObjectIDByIdent('Token', $connectionNode);
        return $id !== false ? $id : false;
    }
}

// -----------------------------------------------------------------------------
// Variable-/Struktur-Builder (GUI-Beschreibung → Symcon-Variablen)
// -----------------------------------------------------------------------------

final class WolfVariableBuilder
{
    private IPSModule $module;

    public function __construct(IPSModule $module)
    {
        $this->module = $module;
    }

    public function buildNodeTree(object $guiDescription, int $parentNodeId, int $tabGuiId = 0, bool $updateOnly = false): void
    {
        if (isset($guiDescription->MenuItems) && is_array($guiDescription->MenuItems)) {
            foreach ($guiDescription->MenuItems as $item) {
                if (!$updateOnly) {
                    $nodeId = $this->ensureCategory('WSS_DIR_' . ($item->SortId ?? 0), (string) ($item->Name ?? ''), $parentNodeId);
                    $this->buildNodeTree($item, $nodeId, 0, false);
                } else {
                    $this->buildNodeTree($item, $parentNodeId, 0, true);
                }
            }
        }

        if (isset($guiDescription->TabViews) && is_array($guiDescription->TabViews)) {
            foreach ($guiDescription->TabViews as $tab) {
                $guiId = (int) ($tab->GuiId ?? 0);
                if (!$updateOnly) {
                    $nodeId = $this->ensureCategory('WSS_DIR_' . $guiId, (string) ($tab->TabName ?? ''), $parentNodeId);
                    $this->buildNodeTree($tab, $nodeId, $guiId, false);
                } else {
                    $this->buildNodeTree($tab, $parentNodeId, $guiId, true);
                }
            }
        }

        if (isset($guiDescription->SubMenuEntries) && is_array($guiDescription->SubMenuEntries)) {
            foreach ($guiDescription->SubMenuEntries as $sub) {
                if (!$updateOnly) {
                    $nodeId = $this->ensureCategory('WSS_DIR_' . ($sub->SortId ?? 0), (string) ($sub->Name ?? ''), $parentNodeId);
                    $this->buildNodeTree($sub, $nodeId, $tabGuiId, false);
                } else {
                    $this->buildNodeTree($sub, $parentNodeId, $tabGuiId, true);
                }
            }
        }

        if (isset($guiDescription->ParameterDescriptors) && is_array($guiDescription->ParameterDescriptors)) {
            $this->processParameterDescriptors($guiDescription->ParameterDescriptors, $parentNodeId, $tabGuiId, $updateOnly);
        }

        if (isset($guiDescription->ChildParameterDescriptors) && is_array($guiDescription->ChildParameterDescriptors)) {
            $this->processParameterDescriptors($guiDescription->ChildParameterDescriptors, $parentNodeId, $tabGuiId, $updateOnly);
        }
    }

    private function processParameterDescriptors(array $descriptors, int $parentNodeId, int $tabGuiId, bool $updateOnly): void
    {
        $connectionNode = $this->module->GetIDForIdent('SystemName');
        $propertiesId = $connectionNode !== false ? IPS_GetObjectIDByIdent('Properties', $connectionNode) : false;
        $properties = $propertiesId !== false ? (array) json_decode((string) GetValueString($propertiesId), true) : [];

        foreach ($descriptors as $desc) {
            // Wie Home-Assistant wolflink: „Reglertyp“ liefert die API keinen Wert → überspringen
            if (isset($desc->Name) && (string) $desc->Name === 'Reglertyp') {
                continue;
            }
            if ($updateOnly) {
                if ($propertiesId !== false && isset($properties[$tabGuiId])) {
                    $key = 'ID' . ($desc->ParameterId ?? 0);
                    if (isset($properties[$tabGuiId][$key])) {
                        $properties[$tabGuiId][$key]['ValueId'] = $desc->ValueId ?? 0;
                    }
                }
                if (isset($desc->ChildParameterDescriptors) && is_array($desc->ChildParameterDescriptors)) {
                    $this->processParameterDescriptors($desc->ChildParameterDescriptors, $parentNodeId, $tabGuiId, true);
                }
            } else {
                $this->registerDescriptor($desc, $parentNodeId, $tabGuiId);
                if (isset($desc->ChildParameterDescriptors) && is_array($desc->ChildParameterDescriptors)) {
                    $varId = $this->module->GetIDForIdent('ID' . ($desc->ParameterId ?? 0));
                    if ($varId !== false) {
                        $this->processParameterDescriptors($desc->ChildParameterDescriptors, $varId, $tabGuiId, false);
                    }
                }
            }
        }

        if ($updateOnly && $propertiesId !== false) {
            SetValue($propertiesId, json_encode($properties));
        }
    }

    private function ensureCategory(string $ident, string $name, int $parentId): int
    {
        if ($name === '' || $name === 'NULL') {
            return $parentId;
        }
        $id = IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            IPS_SetParent($id, $parentId);
        }
        return $id;
    }

    private function registerDescriptor(object $desc, int $parentId, int $tabGuiId): int
    {
        $paramId = $desc->ParameterId ?? -1;
        if ($paramId === -1) {
            $paramId = 'IDX' . random_int(10000, 99999);
        }
        $ident = 'ID' . $paramId;
        $existingId = IPS_GetObjectIDByIdent($ident, $parentId);
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $controlType = (int) ($desc->ControlType ?? 0);
        $name = (string) ($desc->Name ?? '');
        $profileName = 'WSS_' . preg_replace('/[^A-Za-z0-9 ]/', '', str_replace(' ', '_', $name));
        $valueId = $desc->ValueId ?? 0;
        $isReadOnly = (bool) ($desc->IsReadOnly ?? true);

        if (isset($desc->Decimals) && (int) $desc->Decimals === 1) {
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, 2);
            }
            $varId = $this->module->RegisterVariableFloat($ident, $name, $profileName, (float) ($desc->SortId ?? 0));
            IPS_SetVariableProfileValues($profileName, (float) ($desc->MinValue ?? 0), (float) ($desc->MaxValue ?? 100), (float) ($desc->StepWidth ?? 1));
            if (isset($desc->Unit)) {
                IPS_SetVariableProfileText($profileName, '', ' ' . $desc->Unit);
            }
        } elseif (in_array($controlType, [0, 1, 6, 13, 14, 19, 24], true)) {
            if (!IPS_VariableProfileExists($profileName)) {
                IPS_CreateVariableProfile($profileName, 1);
            }
            $varId = $this->module->RegisterVariableInteger($ident, $name, $profileName, (int) ($desc->SortId ?? 0));
            IPS_SetVariableProfileValues($profileName, (int) ($desc->MinValue ?? 0), (int) ($desc->MaxValue ?? 100), (int) ($desc->StepWidth ?? 1));
            if (isset($desc->ListItems) && is_array($desc->ListItems)) {
                foreach ($desc->ListItems as $item) {
                    $icon = $this->mapIcon((string) ($item->ImageName ?? ''));
                    IPS_SetVariableProfileAssociation($profileName, (int) $item->Value, (string) ($item->DisplayText ?? ''), $icon, -1);
                }
            }
            if (isset($desc->Unit)) {
                IPS_SetVariableProfileText($profileName, '', ' ' . $desc->Unit);
            }
        } elseif ($controlType === 5) {
            $varId = $this->module->RegisterVariableBoolean($ident, $name, '~Switch', (bool) ($desc->SortId ?? false));
        } else {
            $varId = $this->module->RegisterVariableString($ident, $name, '~String', (string) ($desc->SortId ?? ''));
        }

        IPS_SetParent($varId, $parentId);
        $isReadOnly ? $this->module->DisableAction($ident) : $this->module->EnableAction($ident);

        $connectionNode = $this->module->GetIDForIdent('SystemName');
        if ($connectionNode !== false) {
            $propId = IPS_GetObjectIDByIdent('Properties', $connectionNode);
            if ($propId !== false) {
                $props = (array) json_decode((string) GetValueString($propId), true);
                if (!isset($props[$tabGuiId])) {
                    $props[$tabGuiId] = [];
                }
                $props[$tabGuiId]['ID' . $paramId] = [
                    'ValueId' => $valueId,
                    'ParameterId' => $paramId,
                    'VarId' => $varId,
                    'TabGuiId' => $tabGuiId,
                ];
                SetValue($propId, json_encode($props));
            }
        }

        return $varId;
    }

    private function mapIcon(string $imageName): string
    {
        return str_starts_with($imageName, 'Icon_Lueftung') ? 'Itensity' : '';
    }
}

// -----------------------------------------------------------------------------
// Hauptmodul WolfSmartset (Symcon 8.1)
// -----------------------------------------------------------------------------

class WolfSmartset extends IPSModule
{
    private ?WolfPortalClient $client = null;
    private ?WolfSessionManager $sessionManager = null;
    private ?WolfVariableBuilder $variableBuilder = null;

    private function client(): WolfPortalClient
    {
        if ($this->client === null) {
            $this->client = new WolfPortalClient(WSS_BASE_URL, WSS_LANGUAGE, $this);
        }
        return $this->client;
    }

    private function session(): WolfSessionManager
    {
        if ($this->sessionManager === null) {
            $this->sessionManager = new WolfSessionManager($this, $this->client());
        }
        return $this->sessionManager;
    }

    private function variableBuilder(): WolfVariableBuilder
    {
        if ($this->variableBuilder === null) {
            $this->variableBuilder = new WolfVariableBuilder($this);
        }
        return $this->variableBuilder;
    }

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('ExpertPassword', '1111');
        $this->RegisterPropertyInteger('SystemNumber', 0);
        $this->RegisterPropertyInteger('RefreshInterval', 300);
        $this->RegisterTimer('WolfUpdate', 0, 'WSS_GetValues($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        $this->session()->logout();
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $interval = max(60, $this->ReadPropertyInteger('RefreshInterval'));
        $this->SetTimerInterval('WolfUpdate', $interval * 1000);
        $this->SetStatus(WSS_STATUS_INACTIVE);
        $this->ensureConnectionVariables();
        $this->GetSystemInfo();
    }

    /** Öffentliche API (Prefix WSS_) */

    public function Logout(): void
    {
        $this->session()->logout();
    }

    public function GetSystemInfo(bool $reauth = false): void
    {
        $this->ensureConnectionVariables();
        $headers = $this->session()->getAuthHeaders();
        if ($headers === false) {
            return;
        }

        $systemList = $this->client()->request('api/portal/GetSystemList?_=' . time(), 'GET', $headers);
        if ($systemList === null || !is_array($systemList)) {
            $this->SetStatus(WSS_STATUS_COMM_ERROR);
            return;
        }

        $systemIndex = $this->ReadPropertyInteger('SystemNumber');
        $current = $systemList[$systemIndex] ?? $systemList[0];
        $current = is_array($current) ? (object) $current : $current;
        $systemId = $current->Id ?? '';
        $gatewayId = $current->GatewayId ?? '';
        $systemShareId = $current->SystemShareId ?? '';

        $connectionNode = $this->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return;
        }

        $this->setConnectionValue('LastAccess', '1900-01-01T00:00:00Z');
        $this->setConnectionValue('SystemName', $current->Name ?? '');
        $this->setConnectionValue('SystemId', $systemId);
        $this->setConnectionValue('GatewayId', $gatewayId);
        $this->setConnectionValue('SystemShareId', $systemShareId);
        $this->setInstanceValue('ContactInfo', (string) ($current->ContactInfo ?? ''));
        $this->setInstanceValue('Description', (string) ($current->Description ?? ''));
        $this->setInstanceValue('GatewaySoftwareVersion', (string) ($current->GatewaySoftwareVersion ?? ''));
        $this->setInstanceValue('GatewayUsername', (string) ($current->GatewayUsername ?? ''));
        $this->setInstanceValue('InstallationDate', (string) ($current->InstallationDate ?? ''));
        $this->setInstanceValue('Location', (string) ($current->Location ?? ''));
        $this->setInstanceValue('OperatorName', (string) ($current->OperatorName ?? ''));

        $guiUrl = 'api/portal/GetGuiDescriptionForGateway?GatewayId=' . rawurlencode((string) $gatewayId) . '&SystemId=' . rawurlencode((string) $systemId) . '&_=' . time();
        $guiDescription = $this->client()->request($guiUrl, 'GET', $headers);

        if ($guiDescription === null) {
            return;
        }

        $propertiesRaw = $this->getConnectionValue('Properties');
        if ($propertiesRaw === '[]' || $propertiesRaw === '') {
            $rootId = $this->ensureCategory('WSS_DIR_Data', 'Data', $this->InstanceID);
            $this->variableBuilder()->buildNodeTree($guiDescription, $rootId, 0, false);
            $this->GetValues();
        } else {
            $this->variableBuilder()->buildNodeTree($guiDescription, 0, 0, true);
        }
    }

    public function GetValues(): void
    {
        $this->ensureConnectionVariables();
        $headers = $this->session()->getAuthHeaders();
        if ($headers === false) {
            return;
        }

        $connectionNode = $this->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return;
        }

        // Wie Home-Assistant: erst Online-Status prüfen; wenn Offline keine Parameter abfragen
        $wasOnline = $this->fetchOnlineStatusOnce($headers);
        if (!$wasOnline) {
            $this->SetStatus(WSS_STATUS_COMM_ERROR);
            return;
        }

        $propertiesJson = GetValueString(IPS_GetObjectIDByIdent('Properties', $connectionNode));
        $properties = json_decode($propertiesJson, true);
        if (!is_array($properties)) {
            return;
        }

        $systemId = GetValueString(IPS_GetObjectIDByIdent('SystemId', $connectionNode));
        $gatewayId = GetValueString(IPS_GetObjectIDByIdent('GatewayId', $connectionNode));
        $lastAccess = GetValueString(IPS_GetObjectIDByIdent('LastAccess', $connectionNode));

        $requestHeaders = array_merge($headers, [
            'X-Requested-With: XMLHttpRequest',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
        ]);

        foreach ($properties as $tabGuiId => $propertyTab) {
            $valueIds = [];
            $varIds = [];
            foreach ($propertyTab as $key => $prop) {
                if (!is_array($prop) || $key === '') {
                    continue;
                }
                $valueId = (int) ($prop['ValueId'] ?? 0);
                $valueIds[] = $valueId;
                $varIds[(string) $valueId] = (int) ($prop['VarId'] ?? 0);
            }

            if ($valueIds === []) {
                continue;
            }

            $body = (object) [
                'GuiId' => (int) $tabGuiId,
                'GatewayId' => $gatewayId,
                'GuiIdChanged' => 'true',
                'IsSubBundle' => 'false',
                'LastAccess' => $lastAccess,
                'SystemId' => $systemId,
                'ValueIdList' => $valueIds,
            ];

            $response = $this->client()->request('api/portal/GetParameterValues', 'POST', $requestHeaders, $body);
            if ($response === null) {
                continue;
            }
            // wolf_comm/HA: Bei ErrorCode/ErrorType/Message Token verwerfen, nächster Lauf holt ggf. neue GUI-Beschreibung
            if (isset($response->ErrorCode) || isset($response->ErrorType)) {
                $this->session()->clearStoredToken();
                $this->SetStatus(WSS_STATUS_COMM_ERROR);
                return;
            }
            if (!isset($response->Values)) {
                continue;
            }

            $this->setConnectionValue('LastAccess', $response->LastAccess ?? $lastAccess);

            foreach ($response->Values as $valueNode) {
                $valueId = (string) ($valueNode->ValueId ?? '');
                $varId = $varIds[$valueId] ?? 0;
                if ($varId === 0 || !isset($valueNode->Value)) {
                    continue;
                }

                $varInfo = IPS_GetObject($varId);
                $typed = match ($varInfo['ObjectType'] ?? 0) {
                    0 => (bool) $valueNode->Value,
                    1 => (int) $valueNode->Value,
                    2 => (float) $valueNode->Value,
                    default => (string) $valueNode->Value,
                };

                if (GetValue($varId) != $typed) {
                    SetValue($varId, $typed);
                }
            }
        }
    }

    public function GetOnlineStatus(): void
    {
        $headers = $this->session()->getAuthHeaders();
        if ($headers === false) {
            return;
        }
        $this->fetchOnlineStatusOnce($headers);
    }

    /**
     * Holt Online-Status (GetSystemStateList), aktualisiert Variable NetworkStatus.
     * Wie Home-Assistant: Gerät nur bei Online mit Werten aktualisieren.
     *
     * @param array<string> $headers Bereits ermittelte Auth-Header
     * @return bool true wenn Gateway IsOnline === 1, sonst false
     */
    private function fetchOnlineStatusOnce(array $headers): bool
    {
        $connectionNode = $this->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return false;
        }

        $system = (object) [
            'SystemId' => GetValueString(IPS_GetObjectIDByIdent('SystemId', $connectionNode)),
            'GatewayId' => GetValueString(IPS_GetObjectIDByIdent('GatewayId', $connectionNode)),
            'SystemShareId' => GetValueString(IPS_GetObjectIDByIdent('SystemShareId', $connectionNode)),
        ];

        $response = $this->client()->request('api/portal/GetSystemStateList', 'POST', $headers, ['SystemList' => [$system]]);
        if ($response === null || !is_array($response) || !isset($response[0]->GatewayState->IsOnline)) {
            $this->setInstanceValue('NetworkStatus', 'Offline');
            return false;
        }

        $isOnline = (int) $response[0]->GatewayState->IsOnline === 1;
        $this->setInstanceValue('NetworkStatus', $isOnline ? 'Online' : 'Offline');
        return $isOnline;
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        $this->WriteValue($ident, $value);
    }

    public function WriteValue(string $ident, $value): void
    {
        $this->ensureConnectionVariables();
        $headers = $this->session()->getAuthHeaders();
        if ($headers === false) {
            return;
        }

        $connectionNode = $this->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return;
        }

        $propertiesJson = GetValueString(IPS_GetObjectIDByIdent('Properties', $connectionNode));
        $properties = json_decode($propertiesJson, true);
        if (!is_array($properties)) {
            return;
        }

        $valueId = null;
        $varId = null;
        foreach ($properties as $tab) {
            if (!is_array($tab) || !isset($tab[$ident])) {
                continue;
            }
            $valueId = (int) ($tab[$ident]['ValueId'] ?? 0);
            $varId = (int) ($tab[$ident]['VarId'] ?? 0);
            break;
        }

        if ($valueId === null || $varId === null) {
            return;
        }

        SetValue($varId, $value);

        $systemId = GetValueString(IPS_GetObjectIDByIdent('SystemId', $connectionNode));
        $gatewayId = GetValueString(IPS_GetObjectIDByIdent('GatewayId', $connectionNode));

        $body = (object) [
            'WriteParameterValues' => [
                (object) [
                    'ValueId' => $valueId,
                    'Value' => $value,
                    'ParameterName' => 'NULL',
                ],
            ],
            'SystemId' => $systemId,
            'GatewayId' => $gatewayId,
        ];

        $this->client()->request('api/portal/WriteParameterValues', 'POST', $headers, $body);
        $this->GetValues();
    }

    // -------------------------------------------------------------------------
    // Private Hilfsmethoden
    // -------------------------------------------------------------------------

    private function ensureConnectionVariables(): void
    {
        if ($this->GetIDForIdent('SystemName') !== false) {
            return;
        }

        $parent = $this->RegisterVariableString('SystemName', 'System name');
        $this->registerHiddenVariable($parent, 'SystemId', 'System ID');
        $this->registerHiddenVariable($parent, 'GatewayId', 'Gateway ID');
        $this->registerHiddenVariable($parent, 'Token', 'Token');
        $this->registerHiddenVariable($parent, 'LastAccess', 'Last Access');
        $this->registerHiddenVariable($parent, 'SystemShareId', 'System Share Id');
        $propsId = $this->registerHiddenVariable($parent, 'Properties', 'Properties');
        if ($propsId !== false) {
            SetValue($propsId, json_encode([]));
        }
        $this->RegisterVariableString('NetworkStatus', 'Network status');
        $this->RegisterVariableString('ContactInfo', 'Contact info');
        $this->RegisterVariableString('Description', 'Description');
        $this->RegisterVariableString('GatewaySoftwareVersion', 'Gateway software version');
        $this->RegisterVariableString('GatewayUsername', 'Gateway username');
        $this->RegisterVariableString('InstallationDate', 'Installation date');
        $this->RegisterVariableString('Location', 'Location');
        $this->RegisterVariableString('OperatorName', 'Operator');
    }

    private function registerHiddenVariable(int $parentId, string $ident, string $name): int|false
    {
        $id = $this->RegisterVariableString($ident, $name);
        if ($id !== false) {
            IPS_SetParent($id, $parentId);
            IPS_SetHidden($id, true);
        }
        return $id;
    }

    private function ensureCategory(string $ident, string $name, int $parentId): int
    {
        $id = IPS_GetObjectIDByIdent($ident, $parentId);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $name);
            IPS_SetParent($id, $parentId);
        }
        return $id;
    }

    private function setConnectionValue(string $ident, string $value): void
    {
        $connectionNode = $this->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return;
        }
        $id = IPS_GetObjectIDByIdent($ident, $connectionNode);
        if ($id !== false) {
            SetValueString($id, $value);
        }
    }

    private function getConnectionValue(string $ident): string
    {
        $connectionNode = $this->GetIDForIdent('SystemName');
        if ($connectionNode === false) {
            return '';
        }
        $id = IPS_GetObjectIDByIdent($ident, $connectionNode);
        return $id !== false ? (string) GetValueString($id) : '';
    }

    /** Setzt einen Variablenwert anhand des Idents unter dieser Instanz. */
    private function setInstanceValue(string $ident, mixed $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ($id !== false) {
            if (is_string($value)) {
                SetValueString($id, $value);
            } else {
                SetValue($id, $value);
            }
        }
    }

}
