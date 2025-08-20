<?php

namespace MagentoSync\Helpers;

use MagentoSync\Models\FastModel as FastModel; // adjust if your class path is different
use MagentoSync\Helpers\Core;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refactored Volo helper (no Medoo). All DB access via Illuminate on 'legacy' connection.
 */
class Volo
{
    /** @var FastModel */
    protected FastModel $fast;

    // Cached configs
    private string $apiUrl;
    private string $apiKey;
    private string $apiUsername;
    private string $apiPassword;
    private array  $apiIntegrations;

    // Local caches
    private array $_sku = [];

    // Optional cross-helpers (lazy)
    private ?\MagentoSync\Helpers\Core $core = null;
    private ?Native  $native  = null;  // MagentoSync\Helpers\Native
    private ?Magento $magento = null;  // MagentoSync\Helpers\Magento

    public function __construct(FastModel $fast = null)
    {
        $this->fast = $fast ?? new FastModel();

        // Pull required API configuration once at construction time.
        $this->apiUrl       = rtrim((string) $this->fast->getAPIConfigurations('volo_api_url'), '/') . '/';
        $this->apiKey       = (string) $this->fast->getAPIConfigurations('volo_api_key');
        $this->apiUsername  = (string) $this->fast->getAPIConfigurations('volo_api_username');
        $this->apiPassword  = (string) $this->fast->getAPIConfigurations('volo_api_password');
        $integrations       = (string) $this->fast->getAPIConfigurations('volo_api_integrations');
        $this->apiIntegrations = $integrations !== '' ? array_map('trim', explode(',', $integrations)) : [];
    }

    /** Convenience: legacy DB connection */
    protected function db()
    {
        return DB::connection('legacy');
    }

    /** Lazy helpers */
    protected function core(): Core
    {
        if (!$this->core) { $this->core = new Core();}
        return $this->core;
    }
    protected function native(): Native
    {
        if (!$this->native) { $this->native = new Native(); }
        return $this->native;
    }
    protected function magento(): Magento
    {
        if (!$this->magento) { $this->magento = new Magento(); }
        return $this->magento;
    }

    /* ---------------------------------------------------------------------
     | HTTP helpers
     |--------------------------------------------------------------------- */

    public function getAction(array $httpHeaders, string $api, string $parameters): string
    {
        $serviceUrl = $this->apiUrl . ltrim($api, '/') . $parameters;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $serviceUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $httpHeaders,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);

        $data     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            Log::warning('Volo GET cURL error', ['url' => $serviceUrl, 'error' => $err]);
        }

        if ($httpCode !== 200 || $data === false) {
            return json_encode([
                'status'          => false,
                'header_code'     => $httpCode,
                'original_output' => $data === false ? $err : $data,
            ]);
        }

        return (string) $data;
    }

    public function postAction(array $httpHeaders, string $api, string $parameters = '', $postData = []): string
    {
        $serviceUrl = $this->apiUrl . ltrim($api, '/') . $parameters;

        $payload = is_string($postData) ? $postData : json_encode($postData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = array_merge(
            [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
            $httpHeaders
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $serviceUrl,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);

        $result = curl_exec($ch);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            Log::error('Volo POST cURL error', ['url' => $serviceUrl, 'error' => $err]);
            return json_encode(['status' => false, 'error' => $err]);
        }

        return (string) $result;
    }

    /* ---------------------------------------------------------------------
     | High-level API flows
     |--------------------------------------------------------------------- */

    public function execute(string $api, string $parameters = ''): array
    {
        $contentType = 'Accept: application/json';
        $xapi        = 'x-api-key: ' . $this->apiKey;

        $voloToken = (string) $this->fast->getAPIConfigurations('volo_token');
        $auth      = 'Authorization: Bearer ' . $voloToken;
        $headers   = [$contentType, $auth, $xapi];

        $raw = $this->getAction($headers, $api, $parameters);
        $out = json_decode($raw, true);

        // If unauthorized or structured error, refresh token once
        if (isset($out['status']) && $out['status'] === false) {
            $auth    = 'Authorization: Basic ' . base64_encode($this->apiUsername . ':' . $this->apiPassword);
            $headers = [$contentType, $auth, $xapi];

            $tokenRaw = $this->getAction($headers, 'token', '');
            $tokenOut = json_decode($tokenRaw, true) ?? [];

            if (!isset($tokenOut['token'])) {
                return [
                    'error'           => true,
                    'error_message'   => 'Volo API auth failed',
                    'header_code'     => $tokenOut['header_code'] ?? null,
                    'original_output' => $tokenOut['original_output'] ?? null,
                ];
            }

            $voloToken = (string) $tokenOut['token'];
            $this->fast->setApiConfiguration('volo_token', $voloToken);

            $auth    = 'Authorization: Bearer ' . $voloToken;
            $headers = [$contentType, $auth, $xapi];

            $retryRaw = $this->getAction($headers, $api, $parameters);
            $retry    = json_decode($retryRaw, true);

            if (isset($retry['status']) && $retry['status'] === false) {
                return [
                    'error'           => true,
                    'error_message'   => 'Volo API error on retry',
                    'header_code'     => $retry['header_code'] ?? null,
                    'original_output' => $retry['original_output'] ?? null,
                ];
            }

            return is_array($retry) ? $retry : ['raw' => $retryRaw];
        }

        return is_array($out) ? $out : ['raw' => $raw];
    }

    public function getAuthorized(): array
    {
        $contentType = 'Accept: application/json';
        $xapi        = 'x-api-key: ' . $this->apiKey;
        $auth        = 'Authorization: Basic ' . base64_encode($this->apiUsername . ':' . $this->apiPassword);
        $headers     = [$contentType, $auth, $xapi];

        $raw = $this->getAction($headers, 'token', '');
        $out = json_decode($raw, true);

        if (!isset($out['token'])) {
            return [
                'error'           => true,
                'error_message'   => 'Volo API auth failed',
                'header_code'     => $out['header_code'] ?? null,
                'original_output' => $out['original_output'] ?? null,
            ];
        }

        $voloToken = (string) $out['token'];
        $this->fast->setApiConfiguration('volo_token', $voloToken);

        return ['error' => false, 'token' => $voloToken, 'xapi' => $xapi];
    }

    /* ---------------------------------------------------------------------
     | Data helpers / processing (DB via Illuminate)
     |--------------------------------------------------------------------- */

    public function processImages(array $productData, bool $force = false): array
    {
        if (!isset($productData['data'])) {
            return $productData;
        }

        $decoded = is_array($productData['data'])
            ? $productData['data']
            : (json_decode((string) $productData['data'], true) ?: []);

        $sku    = $decoded['sku']    ?? null;
        $images = (array)($decoded['images'] ?? []);

        if (!$sku) {
            return $productData;
        }

        // Only handle for image/product unless forced
        $type = $productData['update_type'] ?? null;
        if (!$force && !in_array($type, ['image', 'product'], true)) {
            return $productData;
        }

        if (empty($images)) {
            return $productData;
        }

        $qb = $this->db()->table('product_gallery')->where('sku', $sku);

        // How many of the requested images already exist?
        $existingCount = $this->db()->table('product_gallery')
            ->where('sku', $sku)
            ->whereIn('link', $images)
            ->count();

        if ($existingCount === count($images)) {
            // Nothing new to add; avoid resending images
            if (!$force) {
                unset($decoded['images']);
            }
            $productData['data'] = $decoded;
            return $productData;
        }

        // Fetch existing links for fast lookup
        $existingLinks = $this->db()->table('product_gallery')
            ->where('sku', $sku)
            ->whereIn('link', $images)
            ->pluck('link')
            ->all();

        $newImages = [];
        foreach ($images as $img) {
            if (!in_array($img, $existingLinks, true)) {
                $this->db()->table('product_gallery')->insert([
                    'sku'        => $sku,
                    'link'       => $img,
                    'webhook_id' => $productData['webhook_id'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $newImages[] = $img;
            }
        }

        // If we added some, keep only those in payload; else drop images
        if (count($newImages) > 0) {
            $decoded['images'] = $newImages;
        } elseif (!$force) {
            unset($decoded['images']);
        }

        $productData['data'] = $decoded;
        return $productData;
    }

    /**
     * Legacy passthrough used by CRON/HTTP pipes. Uses Illuminate DB.
     */
    public function syncPush(array $pushData, $id)
    {
        $secret = (string)$this->fast->getAPIConfigurations('magento_api_secret');
        $url    = (string)$this->fast->getAPIConfigurations('magento_api_url');
        $ideal  = (string)$this->fast->getAPIConfigurations('magento_api_integration_method');

        if ($ideal === 'ideal') {
            $url = rtrim($url, '/') . '/cloudburst/index/api';
        }

        foreach ($pushData as $_products) {
            $id = $_products['id'] ?? $id;

            foreach (($_products['data'] ?? []) as $_product) {
                if (!isset($_product['sku'])) {
                    $this->db()->table('data_by_webhooks')
                        ->where('id', $id)
                        ->update(['data_processed' => 7, 'response' => 'internal data processing error']);
                    continue;
                }

                $postData = [
                    'secret' => $secret,
                    'type'   => $this->typePost($_products['post_for'] ?? 'full_product'),
                    'data'   => json_encode($_product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];

                $this->db()->table('data_by_webhooks')
                    ->where('id', $id)
                    ->update([
                        'data_processed' => 10,
                        'dispatched_at'  => date('Y-m-d H:i:s'),
                        'response'       => 'Data dispatched for processing.'
                    ]);

                $apiCall  = 'N/A';
                $response = json_encode(['connector_update' => $this->core()->pushData($url, $postData)], JSON_UNESCAPED_SLASHES);

                $this->db()->table('products_extract')->insert([
                    'sku'             => $_product['sku'],
                    'update_type'     => $postData['type'],
                    'data'            => json_encode($_product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'webhook_id'      => $id,
                    'server_response' => $response,
                    'api_call'        => $apiCall,
                    'status'          => 1,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);

                $this->db()->table('data_by_webhooks')
                    ->where('id', $id)
                    ->update([
                        'data_processed' => 1,
                        'response'       => 'forwarded for internal batch processing.',
                        'batch'          => null,
                        'innerBatch'     => null,
                        'dispatched_at'  => date('Y-m-d H:i:s'),
                    ]);
            }
        }
    }

    public function typePost($pushType)
    {
        switch ($pushType) {
            case 'full_product':     return 'product';
            case 'partial_product':  return 'stock';
            case 'image_update':     return 'image';
            case 'pushed_order':     return 'order';
            case 'price':            return 'price';
            default:                 return 'product';
        }
    }

    public function pushProducts(string $pushType = 'full_product', int $limit = 10, bool $output = true): void
    {
        try {
            //echo 'I am in volo helper.';
            $batch  = (int) round(microtime(true) * 1000);
            $data   = $this->core()->getFeedPush($pushType, $batch, $limit);
            //echo 'I successfully reached to core getfeedpush function without issue';
        } catch (\Throwable $e) {
//            http_response_code(500);
//            echo "[pushProducts] Exception: " . $e->getMessage() . "\n";
//            echo $e->getFile() . ':' . $e->getLine() . "\n";
//            echo print_r($e->getTrace(), true);
        }

        $secret = (string)$this->fast->getAPIConfigurations('magento_api_secret');
        $url    = (string)$this->fast->getAPIConfigurations('magento_api_url');
        $ideal  = (string)$this->fast->getAPIConfigurations('magento_api_integration_method');
        if ($ideal === 'ideal') {
            $url = rtrim($url, '/') . '/cloudburst/index/api';
        } else {
            try {
                $this->native(); // keep native path available
            } catch (\Throwable $e) {
//                http_response_code(500);
//                echo "Exception: " . $e->getMessage() . "\n";
//                echo $e->getFile() . ':' . $e->getLine() . "\n";
//                echo print_r($e->getTrace(), true);
            }
        }


        foreach ($data as $_products) {
            $id = $_products['id'] ?? $id;

            foreach (($_products['data'] ?? []) as $_product) {
                if (!isset($_product['sku'])) {
                    $this->db()->table('data_by_webhooks')->where('id', $id)
                        ->update(['data_processed' => 7, 'response' => 'internal data processing error']);
                    continue;
                }

                if (isset($_product['weight'])) {
                    $_product['weight'] = preg_replace('/[^0-9.]/', '', (string)$_product['weight']);
                }

                $postType = $this->typePost($_products['post_for'] ?? 'full_product');

                $postData = [
                    'secret' => $secret,
                    'type'   => $postType,
                    'data'   => json_encode($_product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];

                $this->db()->table('data_by_webhooks')->where('id', $id)->update([
                    'data_processed' => 10,
                    'dispatched_at'  => date('Y-m-d H:i:s'),
                    'response'       => 'Data dispatched for processing.'
                ]);

                $apiCall  = 'N/A';
                $response = null;
                try {
                    if ($ideal !== 'ideal' && $postType === 'product') {
                        // Internal Native helper first
                        $response = $this->native()->processProduct($_product, $postData, $url, $id);

                        $tmp = [];
                        foreach (['sku', 'qty', 'categories', 'images', 'manufacturer_warranty', 'specification_type'] as $k) {
                            if (isset($_product[$k])) {
                                $tmp[$k] = $_product[$k];
                            }
                        }

                        $stockAndCategories = [
                            'secret' => $secret,
                            'type' => $postType,
                            'data' => json_encode($tmp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ];

                        $cloudUrl = rtrim((string)$this->fast->getAPIConfigurations('magento_api_url'), '/') . '/cloudburst/index/api';
                        $response = json_encode(['connector_update' => $this->core()->pushData($cloudUrl, $stockAndCategories)], JSON_UNESCAPED_SLASHES);

                    } else {
                        $cloudUrl = rtrim((string)$this->fast->getAPIConfigurations('magento_api_url'), '/') . '/cloudburst/index/api';
                        $response = json_encode(['connector_update' => $this->core()->pushData($cloudUrl, $postData)], JSON_UNESCAPED_SLASHES);
                    }

                    $this->db()->table('products_extract')->insert([
                        'sku' => $_product['sku'],
                        'update_type' => $postType,
                        'data' => json_encode($_product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'webhook_id' => $id,
                        'server_response' => $response,
                        'api_call' => $apiCall,
                        'status' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);

                    $this->db()->table('data_by_webhooks')->where('id', $id)->update([
                        'data_processed' => 1,
                        'response' => 'forwarded for internal batch processing.',
                        'batch' => null,
                        'innerBatch' => null,
                        'dispatched_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {
                    http_response_code(500);
                    echo "Exception: " . $e->getMessage() . "\n";
                    echo $e->getFile() . ':' . $e->getLine() . "\n";
                    echo print_r($e->getTrace(), true);
                }
            }
        }

        if ($output && !$id) {
            Log::info('pushProducts: Update not applicable (no id).');
        }
    }

    public function findInProduct(&$product, $attribute_code)
    {
        $output = false;
        foreach ($product as $key => $value) {
            if ($key === $attribute_code) {
                return $value;
            }
            if (is_array($value) && $key === 'custom_attributes') {
                foreach ($value as $_v) {
                    if (isset($_v['attribute_code']) && $_v['attribute_code'] === $attribute_code) {
                        return $_v['value'] ?? false;
                    }
                }
            }
        }
        return $output;
    }

    public function processOrder($orderPost)
    {
        $contentType = 'Accept: application/json';
        $xapi        = 'x-api-key: ' . $this->apiKey;
        $auth        = 'Authorization: Basic ' . base64_encode($this->apiUsername . ':' . $this->apiPassword);
        $headers     = [$contentType, $auth, $xapi];

        $raw = $this->getAction($headers, 'token', '');
        $out = json_decode($raw, true);
        if (!isset($out['token'])) {
            return [
                'error'           => true,
                'error_message'   => 'Volo API auth failed',
                'header_code'     => $out['header_code'] ?? null,
                'original_output' => $out['original_output'] ?? null,
            ];
        }

        $voloToken = (string) $out['token'];
        $this->fast->setApiConfiguration('volo_token', $voloToken);

        $headers = [$contentType, 'Authorization: Bearer ' . $voloToken, $xapi];

        // TODO: map $orderPost into real payload; placeholder kept per legacy behaviour
        $input = [1,2,3,4,5,6];
        return $this->postAction($headers, 'salesOrders', '', $input);
    }

    public function prepareOrder($orderPost)
    {
        $orderSource    = $this->fast->getAPIConfigurations('volo_order_source');
        $sellerUsername = $this->fast->getAPIConfigurations('volo_seller_name');
        // TODO: implement when legacy behaviour clarified
        return compact('orderSource', 'sellerUsername');
    }

    public function getProduct($sku, $force = false)
    {
        if (isset($this->_sku[$sku]['status']) && !$force) {
            return (bool) $this->_sku[$sku]['status'];
        }

        $token = (string) $this->fast->getAPIConfigurations('magento_order_api_token');
        $parameters = '?searchCriteria[filter_groups][0][filters][0][field]=sku'
            . '&searchCriteria[filter_groups][0][filters][0][value]=' . urlencode(trim((string) $sku))
            . '&searchCriteria[filter_groups][0][filters][0][condition_type]=eq';

        $response = $this->magento()->getAction('products', $parameters, $token);

        if ($force) {
            return $response;
        }

        if (isset($response['status']) && ($response['response']['items']['total_count'] ?? count($response['response']['items'] ?? [])) > 0) {
            $this->_sku[$sku] = $response;
            return (bool) $this->_sku[$sku]['status'];
        }

        return false;
    }
}