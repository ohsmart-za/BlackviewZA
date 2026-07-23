<?php
// ============================================================
// Blackview SA Portal — Xero API Client (OAuth2)
// Ported from the SageSync project's proven Xero provider,
// adapted to the portal's MySQL + settings table.
//
// Xero is OAuth2-only (Authorization Code, confidential client).
// After token exchange a second call to /connections discovers the
// "tenant" (organisation); every API call carries Xero-tenant-id.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function xeroSetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try { $cache = getSettings(getDB()); } catch (Throwable $e) { $cache = []; }
    }
    return isset($cache[$key]) && $cache[$key] !== '' ? (string)$cache[$key] : $default;
}

function xeroSaveSetting(string $key, string $value): void {
    saveSettings(getDB(), [$key => $value]);
}

class XeroClient
{
    const AUTH_URL        = 'https://login.xero.com/identity/connect/authorize';
    const TOKEN_URL       = 'https://identity.xero.com/connect/token';
    const API_BASE        = 'https://api.xero.com/api.xro/2.0';
    const CONNECTIONS_URL = 'https://api.xero.com/connections';
    // NB: accounting.transactions was retired for Xero apps registered after 2 Mar 2026.
    // accounting.invoices is its granular replacement and also covers Quotes.
    const DEFAULT_SCOPES  = 'openid profile email accounting.contacts accounting.invoices accounting.settings offline_access';

    /** GET an endpoint like 'Contacts'. Returns the decoded body (e.g. {"Contacts":[...]}). */
    public static function get(string $endpoint, array $query = []): array {
        return self::request('GET', $endpoint, $query);
    }

    /** POST a body to an endpoint like 'Invoices'. Xero uses the same shape for create+update. */
    public static function post(string $endpoint, $body, array $query = []): array {
        return self::request('POST', $endpoint, $query, $body);
    }

    /** Fetch every page of a Get endpoint. Xero pages with ?page=1,2,3… (100 records/page). */
    public static function getAll(string $endpoint, string $resultKey, array $query = []): array {
        $all = []; $page = 1;
        do {
            $res = self::get($endpoint, $query + ['page' => $page]);
            $results = $res[$resultKey] ?? [];
            foreach ($results as $r) $all[] = $r;
            $page++;
        } while (count($results) === 100);
        return $all;
    }

    private static function request(string $method, string $endpoint, array $query = [], $body = null): array {
        $url = self::API_BASE . '/' . ltrim($endpoint, '/');
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . self::accessToken(),
            'Xero-tenant-id: ' . self::tenantId(),
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("Xero request failed ($endpoint): $err");
        $decoded = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            // Prefer the specific per-element validation errors over the generic top-level Message.
            $msg = '';
            if (is_array($decoded)) {
                $ve = [];
                foreach ($decoded['Elements'] ?? [] as $el) {
                    foreach ($el['ValidationErrors'] ?? [] as $v) {
                        if (!empty($v['Message'])) $ve[] = $v['Message'];
                    }
                }
                $msg = $ve ? implode('; ', array_unique($ve)) : ($decoded['Message'] ?? $resp);
            } else {
                $msg = $resp;
            }
            throw new RuntimeException("Xero API $code on $endpoint: " . substr((string)$msg, 0, 500));
        }
        return is_array($decoded) ? $decoded : [];
    }

    // ---------- OAuth2 (Authorization Code, confidential client) ----------

    public static function redirectUri(): string {
        return xeroSetting('xero_redirect_uri', BASE_URL . '/auth/xero_callback.php');
    }

    public static function authorizeUrl(): string {
        $state = bin2hex(random_bytes(16));
        $_SESSION['xero_oauth_state'] = $state;
        $params = [
            'response_type' => 'code',
            'client_id'     => xeroSetting('xero_client_id'),
            'redirect_uri'  => self::redirectUri(),
            'scope'         => xeroSetting('xero_scopes', self::DEFAULT_SCOPES),
            'state'         => $state,
        ];
        // RFC3986 (%20 for spaces), not RFC1738 (+): Xero's IdentityServer does not
        // treat '+' as a space, so a '+'-joined scope string → "invalid_scope".
        return self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function exchangeCode(string $code, string $state): void {
        if (!hash_equals($_SESSION['xero_oauth_state'] ?? '', $state)) {
            throw new RuntimeException('OAuth state mismatch — please retry the connection.');
        }
        unset($_SESSION['xero_oauth_state']);
        $tok = self::tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => self::redirectUri(),
        ]);
        self::storeTokens($tok);
        self::discoverTenant();
    }

    /** List every organisation (tenant) this connection is authorised for. */
    public static function listTenants(): array {
        $ch = curl_init(self::CONNECTIONS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . self::accessToken()],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $list = json_decode((string)$resp, true);
        if ($code !== 200 || !is_array($list)) throw new RuntimeException('Could not list Xero connections: ' . $resp);
        return $list;
    }

    /** Auto-select the tenant if there's exactly one. */
    public static function discoverTenant(): void {
        $tenants = self::listTenants();
        if (count($tenants) === 1) {
            xeroSaveSetting('xero_tenant_id',   $tenants[0]['tenantId']);
            xeroSaveSetting('xero_tenant_name', $tenants[0]['tenantName'] ?? $tenants[0]['tenantId']);
        }
        // If several, admin/xero.php lists them and the admin picks one.
    }

    private static function tenantId(): string {
        $id = xeroSetting('xero_tenant_id');
        if ($id === '') throw new RuntimeException('No Xero organisation selected — open Admin → Xero Sync and pick one after connecting.');
        return $id;
    }

    public static function accessToken(): string {
        $pdo = getDB();
        $row = $pdo->query('SELECT * FROM xero_oauth_tokens WHERE id = 1')->fetch();
        if (!$row || !$row['access_token']) {
            throw new RuntimeException('Not connected to Xero — open Admin → Xero Sync and click "Connect to Xero".');
        }
        if ($row['expires_at'] && strtotime($row['expires_at']) - 60 < time()) {
            if (!$row['refresh_token']) throw new RuntimeException('Xero token expired and no refresh token available — reconnect in Admin → Xero Sync.');
            $tok = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $row['refresh_token']]);
            if (empty($tok['refresh_token'])) $tok['refresh_token'] = $row['refresh_token'];
            self::storeTokens($tok);
            $row = $pdo->query('SELECT * FROM xero_oauth_tokens WHERE id = 1')->fetch();
        }
        return $row['access_token'];
    }

    private static function tokenRequest(array $fields): array {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => xeroSetting('xero_client_id') . ':' . xeroSetting('xero_client_secret'),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $tok = json_decode((string)$resp, true);
        if ($code !== 200 || empty($tok['access_token'])) {
            $msg = is_array($tok) ? ($tok['error_description'] ?? $tok['error'] ?? $resp) : $resp;
            throw new RuntimeException('Xero OAuth token request failed: ' . substr((string)$msg, 0, 400));
        }
        return $tok;
    }

    private static function storeTokens(array $tok): void {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)($tok['expires_in'] ?? 1800));
        getDB()->prepare(
            'INSERT INTO xero_oauth_tokens (id, access_token, refresh_token, token_type, expires_at, raw)
             VALUES (1, :at, :rt, :tt, :exp, :raw)
             ON DUPLICATE KEY UPDATE access_token = VALUES(access_token),
                 refresh_token = VALUES(refresh_token), token_type = VALUES(token_type),
                 expires_at = VALUES(expires_at), raw = VALUES(raw)'
        )->execute([
            ':at'  => $tok['access_token'],
            ':rt'  => $tok['refresh_token'] ?? null,
            ':tt'  => $tok['token_type'] ?? 'Bearer',
            ':exp' => $expiresAt,
            ':raw' => json_encode($tok),
        ]);
    }

    public static function status(): array {
        try {
            $row = getDB()->query('SELECT expires_at, refresh_token FROM xero_oauth_tokens WHERE id = 1')->fetch();
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => 'Run migration_023 first.'];
        }
        if (!$row || empty($row['refresh_token'])) return ['connected' => false];
        return [
            'connected'   => true,
            'expires_at'  => $row['expires_at'],
            'tenant_id'   => xeroSetting('xero_tenant_id'),
            'tenant_name' => xeroSetting('xero_tenant_name'),
        ];
    }

    public static function disconnect(): void {
        // Null the tokens rather than DELETE — the app DB user has no DELETE grant.
        // status() reports disconnected when refresh_token IS NULL.
        getDB()->prepare('UPDATE xero_oauth_tokens SET access_token = NULL, refresh_token = NULL, expires_at = NULL, raw = NULL WHERE id = 1')
            ->execute();
        xeroSaveSetting('xero_tenant_id', '');
        xeroSaveSetting('xero_tenant_name', '');
    }

    // Xero's JSON mixes ISO8601 strings with legacy /Date(ms)/ tokens by field.
    public static function parseDate(?string $v): ?string {
        if (!$v) return null;
        if (preg_match('#/Date\((\d+)#', $v, $m)) return gmdate('Y-m-d H:i:s', (int)($m[1] / 1000));
        $t = strtotime($v);
        return $t ? gmdate('Y-m-d H:i:s', $t) : null;
    }
}
