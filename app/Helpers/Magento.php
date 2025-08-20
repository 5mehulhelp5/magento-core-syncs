<?php

namespace MagentoSync\Helpers;

use MagentoSync\Models\FastModel; // adjust if your class path is different

/**
 * Refactored Magento helper (no Core_Helper/Medoo).
 * - Uses FastModel for configuration (env-backed).
 * - Keeps public methods: refreshToken(), getAction(), execute(), postAction().
 * - Safer cURL + consistent JSON structures.
 */
class Magento
{
    /** @var FastModel */
    protected FastModel $fast;

    private string $baseUrl;   // e.g. https://domain/rest/V1/
    private string $method;    // integration method flag from config (kept for parity)
    private string $username;
    private string $password;
    private array  $integrations;

    public function __construct(?FastModel $fast = null)
    {
        $this->fast = $fast ?? new FastModel();

        $apiUrl  = (string) $this->fast->getAPIConfigurations('magento_api_url');
        $this->baseUrl     = rtrim($apiUrl, '/') . '/rest/V1/';
        $this->method      = (string) $this->fast->getAPIConfigurations('magento_api_integration_method');
        $this->username    = (string) $this->fast->getAPIConfigurations('magento_api_username');
        $this->password    = (string) $this->fast->getAPIConfigurations('magento_api_password');

        $ints = (string) $this->fast->getAPIConfigurations('magento_api_integrations');
        $this->integrations = strlen($ints) ? array_map('trim', explode(',', $ints)) : [];
    }

    /**
     * Request a fresh admin token using configured username/password.
     * Return JSON string with {status:bool, response|header_code|original_output}
     */
    public function refreshToken()
    {
        $userData = ["username" => $this->username, "password" => $this->password];
        $authUrl  = rtrim($this->baseUrl, '/V1/') . 'V1/integration/admin/token';

        $payload  = json_encode($userData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers  = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            // Original code sent a Basic header; it isn’t required by Magento, so we drop it.
        ];

        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);
        $data     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $decoded = json_decode((string)$data, true);
            return json_encode([
                "status"          => false,
                "header_code"     => $httpCode,
                "original_output" => $decoded,
                "response"        => $decoded['message'] ?? 'Auth failed',
            ]);
        }

        $decoded = json_decode((string)$data, true);
        return json_encode(["status" => true, "response" => $decoded]);
    }

    /**
     * GET /rest/V1/{api}{parameters} with stored bearer token.
     * Returns array: ["status" => bool, "response" => mixed] OR error array (same shape as legacy).
     */
    public function getAction($api, $parameters = "")
    {
        $token       = (string) $this->fast->getAPIConfigurations("magento_order_api_token");
        $serviceUrl  = $this->baseUrl . ltrim($api, '/') . $parameters;

        $ch = curl_init($serviceUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $token,
            ],
        ]);

        $data     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $decoded = json_decode((string)$data, true);
            return [
                "status"          => false,
                "header_code"     => $httpCode,
                "original_output" => $decoded,
                "response"        => $decoded['message'] ?? 'Request failed',
            ];
        }

        $decoded = json_decode((string)$data, true);
        return ["status" => true, "response" => $decoded];
    }

    /**
     * Legacy wrapper; keeps same signature/shape.
     * If getAction returns error, return an "error" array with details (compatible with old code paths).
     */
    public function execute($api, $parameters = '')
    {
        $output = $this->getAction($api, $parameters);

        if (isset($output['status']) && $output['status'] === false) {
            return [
                "error"         => true,
                "error_message" => "Magento API error. Header Code: " . ($output['header_code'] ?? 'n/a') . ", Response: " . ($output['response'] ?? 'n/a'),
            ];
        }

        return $output;
    }

    /**
     * POST/PUT/etc to /rest/{store}/V1/{restAction}
     * Returns array: ['status' => bool, 'response'|('header_code','original_output','response')]
     */
    public function postAction($restAction, $postdata, $store = 'default', $alternativeMethod = 'POST')
    {
        $url = rtrim((string) $this->fast->getAPIConfigurations('magento_api_url'), '/')
            . "/rest/{$store}/V1/" . ltrim($restAction, '/');

        $postToken = (string) $this->fast->getAPIConfigurations('magento_order_api_token');
        $payload   = is_string($postdata) ? $postdata : json_encode($postdata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'Authorization: Bearer ' . $postToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($alternativeMethod ?: 'POST'),
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);

        $data     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $decoded = json_decode((string)$data, true);
            return [
                'status'          => false,
                'header_code'     => $httpCode,
                'original_output' => $decoded,
                'response'        => $decoded['message'] ?? 'Request failed',
            ];
        }

        $decoded = json_decode((string)$data, true);
        return ['status' => true, 'response' => $decoded];
    }
}
