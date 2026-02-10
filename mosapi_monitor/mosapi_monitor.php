<?php
/**
 * ICANN MoSAPI Registrar Monitor for WHMCS (https://www.whmcs.com/)
 *
 * Displays ICANN MoSAPI registrar monitoring status and the latest Domain METRICA report
 * Written in 2026 by Taras Kondratyuk (https://namingo.org)
 *
 * @license MIT
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function mosapi_monitor_config()
{
    return [
        "name"        => "ICANN MoSAPI Monitor",
        "description" => "Displays ICANN MoSAPI registrar monitoring status and the latest Domain METRICA report.",
        "author"      => "Namingo",
        "language"    => "english",
        "version"     => "1.0.0",

        "fields" => [
            "base_url" => [
                "FriendlyName" => "Base URL (include your IANA ID)",
                "Type"         => "text",
                "Size"         => "80",
                "Default"      => "https://mosapi.icann.org/rr/your-iana-id",
                "Description"  => "Example: https://mosapi.icann.org/rr/999",
            ],
            "username" => [
                "FriendlyName" => "RR Username",
                "Type"         => "text",
                "Size"         => "40",
                "Default"      => "",
            ],
            "password" => [
                "FriendlyName" => "RR Password",
                "Type"         => "password",
                "Size"         => "40",
                "Default"      => "",
            ],
            "timeout" => [
                "FriendlyName" => "cURL Timeout (seconds)",
                "Type"         => "text",
                "Size"         => "5",
                "Default"      => "10",
            ],
            "cache_ttl" => [
                "FriendlyName" => "Cache TTL (seconds)",
                "Type"         => "text",
                "Size"         => "6",
                "Default"      => "290",
                "Description"  => "Recommended: 290 seconds (just under 5 minutes).",
            ],
            "show_domains" => [
                "FriendlyName" => "Show domain lists (METRICA)",
                "Type"         => "yesno",
                "Default"      => "off",
                "Description"  => "If disabled, only counts are shown (less sensitive / faster UI).",
            ],
			"source_ip" => [
				"FriendlyName" => "Source IP (Optional)",
				"Type" => "text",
				"Size" => "20",
				"Default" => "",
				"Description" => "Outgoing IP for API connections (if server has multiple IPs).",
			],
        ],
    ];
}

function mosapi_monitor_activate()
{
    // No DB tables required.
    return [
        "status"      => "success",
        "description" => "ICANN MoSAPI Monitor activated.",
    ];
}

function mosapi_monitor_deactivate()
{
    // No DB tables to drop.
    return [
        "status"      => "success",
        "description" => "ICANN MoSAPI Monitor deactivated.",
    ];
}

function mosapi_monitor_output($vars)
{
    $moduleName = "mosapi_monitor";

    // Read module config from WHMCS
    $cfg = [
        "base_url"     => trim((string)($vars["base_url"] ?? "")),
        "username"     => trim((string)($vars["username"] ?? "")),
        "password"     => (string)($vars["password"] ?? ""),
        "version"      => "v2",
        "timeout"      => (int)($vars["timeout"] ?? 10),
        "cache_ttl"    => (int)($vars["cache_ttl"] ?? 290),
        "show_domains" => !empty($vars["show_domains"]),
		"source_ip"    => trim((string)($vars["source_ip"] ?? "")),
    ];

    echo '<div class="container-fluid">';

    if ($cfg["base_url"] === "" || $cfg["username"] === "" || $cfg["password"] === "") {
        echo '<div class="alert alert-warning">
            Please configure <strong>Base URL</strong>, <strong>Username</strong>, and <strong>Password</strong> in:
            <em>Configuration → System Settings → Addon Modules</em>.
        </div>';
        echo '</div>';
        return;
    }

    $action = isset($_GET["mosapi_action"]) ? (string)$_GET["mosapi_action"] : "";
    $forceRefresh = ($action === "refresh");

    try {
        $data = mosapi_monitor_getData($cfg, $forceRefresh);

        $state   = $data["state"];
        $metrica = $data["metrica"];
        $meta    = $data["meta"];

        // Header / actions
        echo '<div class="row" style="margin-bottom:15px;">';
        echo '  <div class="col-md-8">';
        echo '    <div class="alert alert-info" style="margin-bottom:0;">';
        echo '      <strong>Source:</strong> ' . htmlspecialchars($cfg["base_url"]) . ' &nbsp; | &nbsp; ';
        echo '      <strong>API:</strong> ' . htmlspecialchars($cfg["version"]) . ' &nbsp; | &nbsp; ';
        echo '      <strong>Cache:</strong> ' . htmlspecialchars($meta["cache"] ?? "unknown");
        if (!empty($meta["fetched_at"])) {
            echo ' &nbsp; | &nbsp; <strong>Fetched:</strong> ' . htmlspecialchars($meta["fetched_at"]);
        }
        echo '    </div>';
        echo '  </div>';
        echo '  <div class="col-md-4 text-right">';
        $refreshUrl = mosapi_monitor_adminUrl(["mosapi_action" => "refresh"]);
        echo '    <a class="btn btn-primary" href="' . htmlspecialchars($refreshUrl) . '">
                    <i class="fas fa-sync"></i> Refresh Now
                  </a>';
        echo '  </div>';
        echo '</div>';

        // Registrar State
        echo '<div class="panel panel-default">';
        echo '  <div class="panel-heading"><strong>Registrar State</strong></div>';
        echo '  <div class="panel-body">';
        echo mosapi_monitor_renderState($state);
        echo '  </div>';
        echo '</div>';

        // Domain METRICA
        echo '<div class="panel panel-default">';
        echo '  <div class="panel-heading"><strong>Domain METRICA (latest)</strong></div>';
        echo '  <div class="panel-body">';
        echo mosapi_monitor_renderMetrica($metrica, $cfg["show_domains"]);
        echo '  </div>';
        echo '</div>';

    } catch (Exception $e) {
        echo '<div class="alert alert-danger"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    echo '</div>';
}

/**
 * Build an admin URL to this addon module.
 */
function mosapi_monitor_adminUrl(array $params = [])
{
    $base = "addonmodules.php?module=mosapi_monitor";
    if (!$params) {
        return $base;
    }
    return $base . "&" . http_build_query($params);
}

/**
 * Main data fetch wrapper with caching.
 */
function mosapi_monitor_getData(array $cfg, bool $forceRefresh = false): array
{
    $cacheKey = mosapi_monitor_cacheKey($cfg);

    if (!$forceRefresh) {
        $cached = mosapi_monitor_cacheGet($cacheKey, $cfg["cache_ttl"]);
        if ($cached !== null) {
            $cached["meta"]["cache"] = "HIT (file cache)";
            return $cached;
        }
    }

    $stateUrl   = rtrim($cfg["base_url"], "/") . "/" . $cfg["version"] . "/monitoring/state";
    $metricaUrl = rtrim($cfg["base_url"], "/") . "/" . $cfg["version"] . "/metrica/domainList/latest";

    // Cookie jar per request (avoid storing cookies in module directory)
    $cookieFile = mosapi_monitor_tempFile("mosapi_cookie_", ".txt");

    try {
        mosapi_monitor_login($cfg, $cookieFile);

        $state   = mosapi_monitor_fetchJson($stateUrl, $cfg, $cookieFile);
        $metrica = mosapi_monitor_fetchJson($metricaUrl, $cfg, $cookieFile);

        mosapi_monitor_logout($cfg, $cookieFile);

        $payload = [
            "state"   => $state,
            "metrica" => $metrica,
            "meta"    => [
                "cache"      => "MISS (fresh)",
                "fetched_at" => date("Y-m-d H:i:s"),
            ],
        ];

        mosapi_monitor_cacheSet($cacheKey, $payload);
        return $payload;

    } finally {
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }
    }
}

/**
 * Login using Basic Auth to /login endpoint.
 */
function mosapi_monitor_login(array $cfg, string $cookieFile): void
{
    $url = rtrim($cfg["base_url"], "/") . "/login";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $cfg["username"] . ":" . $cfg["password"],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_TIMEOUT        => (int)$cfg["timeout"],
		CURLOPT_INTERFACE      => $cfg['source_ip'] ?? null,
        CURLOPT_HTTPHEADER     => [
            "Accept: application/json",
        ],
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Login request failed: " . $err);
    }

    if ($status !== 200) {
        throw new Exception("Login failed (HTTP $status): " . mosapi_monitor_safeSnippet($response));
    }
}

/**
 * Fetch JSON from authenticated endpoint.
 */
function mosapi_monitor_fetchJson(string $url, array $cfg, string $cookieFile): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => (int)$cfg["timeout"],
        CURLOPT_ENCODING       => "gzip",
		CURLOPT_INTERFACE      => $cfg['source_ip'] ?? null,
        CURLOPT_HTTPHEADER     => [
            "Accept: application/json",
            "Accept-Encoding: gzip",
        ],
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("Fetch failed: " . $err);
    }

    if ($status !== 200) {
        throw new Exception("Failed to fetch data (HTTP $status): " . mosapi_monitor_safeSnippet($response));
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new Exception("Invalid JSON received from: " . $url);
    }

    return $json;
}

/**
 * Logout endpoint (best-effort).
 */
function mosapi_monitor_logout(array $cfg, string $cookieFile): void
{
    $url = rtrim($cfg["base_url"], "/") . "/logout";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => (int)$cfg["timeout"],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Render registrar state in WHMCS admin UI.
 */
function mosapi_monitor_renderState(array $data): string
{
    $registrarId = $data["tld"] ?? $data["registrarID"] ?? "N/A";
    $status      = $data["status"] ?? "Unknown";
    $updatedTs   = $data["lastUpdateApiDatabase"] ?? null;
    $updated     = is_numeric($updatedTs) ? date("Y-m-d H:i:s", (int)$updatedTs) : "N/A";

    $statusLabel = mosapi_monitor_labelForStatus($status);

    $html  = '';
    $html .= '<table class="table table-striped" style="margin-bottom:10px;">';
    $html .= '  <tr><th style="width:220px;">Registrar</th><td>' . htmlspecialchars((string)$registrarId) . '</td></tr>';
    $html .= '  <tr><th>Status</th><td>' . $statusLabel . '</td></tr>';
    $html .= '  <tr><th>Updated</th><td>' . htmlspecialchars($updated) . '</td></tr>';
    $html .= '</table>';

    $tested = $data["testedServices"] ?? [];
    if (!is_array($tested) || !$tested) {
        $html .= '<div class="alert alert-warning">No testedServices data found.</div>';
        return $html;
    }

    $html .= '<h4 style="margin-top:0;">Tested Services</h4>';
    $html .= '<table class="table table-bordered">';
    $html .= '<thead><tr>';
    $html .= '<th>Service</th><th>Status</th><th>Emergency Threshold</th><th>Incidents</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($tested as $name => $service) {
        $svcStatus = $service["status"] ?? "Unknown";
        $thr = (isset($service["emergencyThreshold"]) && is_numeric($service["emergencyThreshold"]))
            ? ((string)$service["emergencyThreshold"] . "%")
            : "0%";

        $incidentsHtml = mosapi_monitor_renderIncidents($service["incidents"] ?? []);

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars((string)$name) . '</td>';
        $html .= '<td>' . mosapi_monitor_labelForStatus($svcStatus) . '</td>';
        $html .= '<td>' . htmlspecialchars($thr) . '</td>';
        $html .= '<td>' . $incidentsHtml . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

function mosapi_monitor_renderIncidents($incidents): string
{
    if (!is_array($incidents) || !$incidents) {
        return '<span class="text-muted">None</span>';
    }

    $items = [];
    foreach ($incidents as $incident) {
        if (!is_array($incident)) {
            continue;
        }
        $id    = $incident["incidentID"] ?? "N/A";
        $state = $incident["state"] ?? "Unknown";

        $startTs = $incident["startTime"] ?? null;
        $endTs   = $incident["endTime"] ?? null;

        $start = is_numeric($startTs) ? date("Y-m-d H:i:s", (int)$startTs) : "N/A";
        $end   = ($endTs && is_numeric($endTs)) ? date("Y-m-d H:i:s", (int)$endTs) : "Active";

        $items[] = htmlspecialchars((string)$id) . ": " . htmlspecialchars((string)$state) .
            "<br><small class=\"text-muted\">since " . htmlspecialchars($start) . " (end: " . htmlspecialchars($end) . ")</small>";
    }

    if (!$items) {
        return '<span class="text-muted">None</span>';
    }

    return implode("<hr style=\"margin:6px 0;\">", $items);
}

/**
 * Render METRICA report.
 */
function mosapi_monitor_renderMetrica(array $data, bool $showDomains): string
{
    $date    = $data["domainListDate"] ?? "N/A";
    $ianaId  = $data["ianaId"] ?? "N/A";
    $unique  = $data["uniqueAbuseDomains"] ?? "N/A";

    $html  = '';
    $html .= '<table class="table table-striped" style="margin-bottom:10px;">';
    $html .= '  <tr><th style="width:220px;">Report Date</th><td>' . htmlspecialchars((string)$date) . '</td></tr>';
    $html .= '  <tr><th>IANA ID</th><td>' . htmlspecialchars((string)$ianaId) . '</td></tr>';
    $html .= '  <tr><th>Unique Abuses</th><td>' . htmlspecialchars((string)$unique) . '</td></tr>';
    $html .= '</table>';

    $list = $data["domainListData"] ?? [];
    if (!is_array($list) || !$list) {
        $html .= '<div class="alert alert-warning">No domainListData found.</div>';
        return $html;
    }

    $html .= '<h4 style="margin-top:0;">Threat Types</h4>';
    $html .= '<table class="table table-bordered">';
    $html .= '<thead><tr><th>Threat</th><th>Count</th><th>Domains</th></tr></thead><tbody>';

    $i = 0;
    foreach ($list as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $threat = $entry["threatType"] ?? "Unknown";
        $count  = $entry["count"] ?? -1;
        $count  = (is_numeric($count) && (int)$count >= 0) ? (string)$count : "N/A";

        $domains = $entry["domains"] ?? [];
        $domainsHtml = '<span class="text-muted">Hidden</span>';

        if ($showDomains) {
            if (is_array($domains) && $domains) {
                $collapseId = "mosapi_domains_" . (++$i);
                $domainsList = array_map(function ($d) {
                    return htmlspecialchars((string)$d);
                }, $domains);

                $domainsHtml = ''
                    . '<a class="btn btn-xs btn-default" data-toggle="collapse" href="#' . $collapseId . '" aria-expanded="false">'
                    . 'Show (' . count($domainsList) . ')</a>'
                    . '<div id="' . $collapseId . '" class="collapse" style="margin-top:8px;">'
                    . '<div style="max-height:220px; overflow:auto; border:1px solid #ddd; padding:8px; border-radius:4px;">'
                    . implode("<br>", $domainsList)
                    . '</div></div>';
            } else {
                $domainsHtml = '<span class="text-muted">None</span>';
            }
        }

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars((string)$threat) . '</td>';
        $html .= '<td>' . htmlspecialchars((string)$count) . '</td>';
        $html .= '<td>' . $domainsHtml . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    if (!$showDomains) {
        $html .= '<div class="alert alert-info" style="margin-top:10px;">
            Domain lists are hidden by configuration. Enable <strong>Show domain lists (METRICA)</strong> in Addon Module settings if you want them visible.
        </div>';
    }

    return $html;
}

/**
 * Status label helper.
 */
function mosapi_monitor_labelForStatus(string $status): string
{
    $s = strtolower(trim($status));

    // You can tweak these mappings as you see real MoSAPI statuses.
    $map = [
        "ok"       => "success",
        "healthy"  => "success",
        "pass"     => "success",
        "warning"  => "warning",
        "degraded" => "warning",
        "fail"     => "danger",
        "failed"   => "danger",
        "down"     => "danger",
        "error"    => "danger",
        "unknown"  => "default",
    ];

    $class = $map[$s] ?? "default";

    return '<span class="label label-' . htmlspecialchars($class) . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * Cache key based on base_url + version + username (not password).
 */
function mosapi_monitor_cacheKey(array $cfg): string
{
    return "mosapi_monitor_" . hash("sha256", $cfg["base_url"] . "|" . $cfg["version"] . "|" . $cfg["username"]);
}

/**
 * Get WHMCS storage dir (best-effort).
 */
function mosapi_monitor_storageDir(): string
{
    // WHMCS 8 typically has /storage. If not, fallback to /attachments or /tmp.
    $root = defined("ROOTDIR") ? ROOTDIR : dirname(__DIR__, 3);

    $candidates = [
        $root . "/storage",
        $root . "/storage/app",
        $root . "/attachments",
        sys_get_temp_dir(),
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            return rtrim($dir, "/");
        }
    }

    // Last resort: module dir (may be non-writable; will error later)
    return __DIR__;
}

function mosapi_monitor_cacheFilePath(string $cacheKey): string
{
    $dir = mosapi_monitor_storageDir() . "/mosapi_monitor";
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . "/" . $cacheKey . ".json";
}

function mosapi_monitor_cacheGet(string $cacheKey, int $ttlSeconds): ?array
{
    $path = mosapi_monitor_cacheFilePath($cacheKey);
    if (!is_file($path)) {
        return null;
    }

    $mtime = @filemtime($path);
    if (!$mtime || (time() - $mtime) > $ttlSeconds) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === "") {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data["state"]) || empty($data["metrica"])) {
        return null;
    }

    return $data;
}

function mosapi_monitor_cacheSet(string $cacheKey, array $payload): void
{
    $path = mosapi_monitor_cacheFilePath($cacheKey);
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function mosapi_monitor_tempFile(string $prefix, string $suffix): string
{
    $dir = mosapi_monitor_storageDir() . "/mosapi_monitor";
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $tmp = tempnam($dir, $prefix);
    if ($tmp === false) {
        // fallback
        $tmp = sys_get_temp_dir() . "/" . $prefix . bin2hex(random_bytes(8));
    }

    // tempnam creates a file; rename for nicer suffix
    $target = $tmp . $suffix;
    @rename($tmp, $target);

    return $target;
}

function mosapi_monitor_safeSnippet(string $s): string
{
    $s = trim($s);
    if (strlen($s) > 800) {
        return substr($s, 0, 800) . "...";
    }
    return $s;
}