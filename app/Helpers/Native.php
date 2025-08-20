<?php

namespace MagentoSync\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MagentoSync\Models\FastModel;

class Native
{
    /** @var \Illuminate\Database\Connection */
    protected $db;

    /** @var FastModel */
    protected FastModel $fast;

    // Cached configs
    private string $_api_url;
    private string $_api_method;
    private string $_api_key;
    private array  $_api_integration;
    private array  $_sku = [];
    private array  $_attribute_set_json = [];

    // Lazy Magento helper
    private ?Magento $magento = null;

    public function __construct()
    {
        $this->db   = DB::connection('legacy');
        $this->fast = new FastModel();

        $baseUrl               = (string) $this->fast->getAPIConfigurations('magento_api_url');
        $this->_api_url        = rtrim($baseUrl, '/') . '/rest/V1/';
        $this->_api_method     = (string) $this->fast->getAPIConfigurations('magento_api_integration_method');
        $this->_api_key        = (string) $this->fast->getAPIConfigurations('magento_order_api_token');
        $integrations          = (string) $this->fast->getAPIConfigurations('magento_api_integrations');
        $this->_api_integration = $integrations !== '' ? array_map('trim', explode(',', $integrations)) : [];

        $attrSetJson = (string) $this->fast->getAPIConfigurations('attribute_set_json');
        $this->_attribute_set_json = $attrSetJson ? (json_decode($attrSetJson, true) ?: []) : [];
    }

    protected function magento(): Magento
    {
        if (!$this->magento) {
            $this->magento = new Magento();
        }
        return $this->magento;
    }

    /**
     * Build Magento product payload (keeps legacy behaviour).
     */
    public function updateArrayVar(array $product): array
    {
        $attribute_set_id = 12;
        if (!empty($product['specification_type']) && is_array($this->_attribute_set_json)) {
            foreach ($this->_attribute_set_json as $set) {
                if (($set['attribute_set_name'] ?? null) === $product['specification_type']) {
                    $attribute_set_id = (int) ($set['attribute_set_id'] ?? 12);
                    break;
                }
            }
        }

        $arrayVar = [
            'product' => [
                'attribute_set_id' => $attribute_set_id,
                'type_id'          => 'simple',
            ],
        ];

        foreach ($product as $attributeCode => $attributeValue) {
            // map to Magento option values where applicable
            if (!in_array($attributeCode, ['price','special_price','sku','title','freebies','qty','box_free_norton_active','addon_accessories'], true)) {
                $attributeValue = $this->fast->getMagentoValue($attributeCode, trim((string) $attributeValue));
            }

            switch ($attributeCode) {
                case 'sku':
                case 'weight':
                case 'price':
                case 'status':
                case 'name':
                case 'visibility':
                    $arrayVar['product'][$attributeCode] = $attributeValue;
                    break;

                case 'qty':
                    $arrayVar['product']['stock_item'] = ['qty' => $attributeValue, 'is_in_stock' => true];
                    break;

                default:
                    $arrayVar['product']['custom_attributes'][] = [
                        'attribute_code' => $attributeCode,
                        'value'          => $attributeValue,
                    ];
                    break;
            }
        }

        return $arrayVar;
    }

    /**
     * Create/Update product in Magento, then log rows to legacy DB — using Illuminate only.
     */
    public function processProduct(array $_product, array $postData, string $url, int $webhook_id = 0)
    {
        $org_data = $_product;

        // do not send these via magento product payload (legacy parity)
        unset($_product['categories'], $_product['images'], $_product['qty'], $_product['manufacturer_warranty']);

        if (isset($_product['special_price']) && (float) $_product['special_price'] == 0.0) {
            $_product['special_price'] = null;
        }

        $payload = $this->updateArrayVar($_product);

        // update if product exists, else create
        if ($this->getProduct($_product['sku'])) {
            $response = $this->magento()->postAction('products/' . urlencode((string) $_product['sku']), $payload, 'default', 'PUT');

            // log into products_extract
            $this->db->table('products_extract')->insert([
                'sku'             => $_product['sku'],
                'update_type'     => 'product',
                'data'            => json_encode($org_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'final_sent_data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'          => 1,
                'server_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'webhook_id'      => $webhook_id,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $this->db->table('data_by_webhooks')
                ->where('id', $webhook_id)
                ->update([
                    'data_processed' => 1,
                    'response'       => 'forwarded for internal batch processing.',
                    'batch'          => null,
                    'innerBatch'     => null,
                    'dispatched_at'  => date('Y-m-d H:i:s'),
                ]);

            return $response;
        }

        // create new product
        $response = $this->magento()->postAction('products/', $payload, 'default', 'POST');

        $this->db->table('products_extract')->insert([
            'sku'             => $_product['sku'],
            'update_type'     => 'product',
            'data'            => json_encode($org_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'final_sent_data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'          => 1,
            'server_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webhook_id'      => $webhook_id,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('data_by_webhooks')
            ->where('id', $webhook_id)
            ->update([
                'data_processed' => 1,
                'response'       => 'forwarded for internal batch processing.',
                'batch'          => null,
                'innerBatch'     => null,
                'dispatched_at'  => date('Y-m-d H:i:s'),
            ]);

        return $response;
    }

    /**
     * Cached existence check; returns bool unless $force=true (then returns raw response).
     */
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

        if (isset($response['status']) && !empty($response['response']['items'])) {
            $this->_sku[$sku] = $response;
            return (bool) $this->_sku[$sku]['status'];
        }

        return false;
    }

    /**
     * Update product (supports special-price-only & rename via $newSku), mirrors legacy logic.
     */
    public function updateProduct(
        string $sku,
        array  $updateData,
        string $store = 'en_UK',
        bool   $debugOnly = false,
        bool   $specialPriceOnly = false,
               $newSku = false
    ) {
        // Handle SKU rename
        if ($newSku) {
            $data = ['product' => ['sku' => trim((string) $newSku)]];
            $response = $this->magento()->postAction('products/' . urlencode($sku), $data, $store, 'PUT');
            return $response;
        }

        if ($specialPriceOnly) {
            $data = [
                'product' => [
                    'sku' => trim($sku),
                    'custom_attributes' => [],
                ],
            ];

            if (isset($updateData['price'])) {
                $data['product']['price'] = $updateData['price'];
            }

            $special = (float) ($updateData['special_price'] ?? 0);
            $data['product']['custom_attributes'][] = [
                'website_ids'    => [1],
                'attribute_code' => 'special_price',
                'value'          => $special > 0 ? $special : null,
            ];

            if ($debugOnly) {
                return $data;
            }
            return $this->magento()->postAction('products/' . urlencode($sku), $data, $store, 'PUT');
        }

        if (!$this->getProduct($sku)) {
            return 'Product validation error in update';
        }

        unset($updateData['sku']);
        if (!array_key_exists('special_price', $updateData)) {
            $updateData['special_price'] = 0;
        }

        $mixAndMatch = $this->matchAndMix($updateData, $this->_sku[$sku]['response']);
        $data = ['product' => $mixAndMatch];

        if ($debugOnly) {
            return $data;
        }

        return $this->magento()->postAction('products/' . urlencode($sku), $data, $store, 'PUT');
    }

    /**
     * Merge incoming fields into Magento product document (first item only), legacy-compatible.
     */
    public function matchAndMix(array $newData, array $oldData): array
    {
        $item = $oldData['items'][0] ?? [];

        // Strip non-updatable fields
        foreach ([
                     'extension_attributes','product_links','options','media_gallery_entries',
                     'tier_prices','id','attribute_set_id','visibility','created_at','updated_at'
                 ] as $unsetKey) {
            unset($item[$unsetKey]);
        }

        unset($newData['images']); // do not mix images here

        $newItem = $item;
        foreach ($newData as $key => $value) {
            $this->searchAndUpdateAttribute($newItem, $key, $value);
        }
        return $newItem;
    }

    /**
     * Deep update helper for Magento product array.
     */
    public function searchAndUpdateAttribute(&$from, $attribute_code, $attribute_value)
    {
        if (!is_array($from)) {
            return $attribute_value;
        }

        foreach ($from as $key => $value) {
            if (is_array($value)) {
                if ($key === 'custom_attributes') {
                    // Update existing matching attributes
                    foreach ($value as $k => $_v) {
                        if (!is_array($_v)) continue;

                        if (($_v['attribute_code'] ?? null) === 'part_number' && $attribute_code === 'part_number') {
                            $from[$key][$k]['value'] = $attribute_value;
                        }
                        if (($_v['attribute_code'] ?? null) === 'model_key' && $attribute_code === 'model_key') {
                            $from[$key][$k]['value'] = $attribute_value;
                        }
                        if (($_v['attribute_code'] ?? null) === 'special_price' && $attribute_code === 'special_price') {
                            $from[$key][$k]['value'] = ((float) $attribute_value === 0.0) ? null : $attribute_value;
                        }
                        if (($_v['attribute_code'] ?? null) === $attribute_code) {
                            // Keep non-empty numeric/zero strings; mirror legacy skip behavior
                            if ((isset($_v['value']) && ((int) $_v['value'] > 0 || $_v['value'] === "0"))) {
                                continue;
                            }
                            $from[$key][$k]['value'] = $attribute_value;
                        }
                        if (($_v['attribute_code'] ?? null) === 'image' || ($_v['attribute_code'] ?? null) === 'category_ids') {
                            unset($from[$key][$k]); // remove image/category_ids – handled elsewhere
                        }
                    }

                    // Always allow appending certain attributes if not present
                    $appendables = [
                        'special_price','short_description','description','part_number','model_key',
                        'fivetech_sku','awin_product_category','ean_code','google_category',
                        'google_product_category','manufacturerwarranty','manufacturer_warranty',
                        'custom_label1','custom_label2','custom_label3','custom_label4','custom_label5',
                    ];

                    if (in_array($attribute_code, $appendables, true)) {
                        $val = $attribute_code === 'special_price'
                            ? (((float) $attribute_value === 0.0) ? null : $attribute_value)
                            : $attribute_value;

                        $from[$key][] = [
                            'attribute_code' => $attribute_code,
                            'value'          => $val,
                        ];

                        // If any of the Google Merchant fields set, ensure google_merchant_active=1
                        if (in_array($attribute_code, ['custom_label1','custom_label2','custom_label3','custom_label4','custom_label5','google_category','google_product_category'], true)) {
                            $from[$key][] = ['attribute_code' => 'google_merchant_active', 'value' => 1];
                        }
                    }
                } else {
                    $from[$key] = $this->searchAndUpdateAttribute($value, $attribute_code, $attribute_value);
                }
                continue;
            }

            // Top-level assignments
            if ($key === $attribute_code || in_array($attribute_code, ['name', 'price'], true)) {
                $from[$key] = $attribute_value;
            }
        }

        return $from;
    }

    /**
     * Raw GET to Magento REST (kept for parity with old code).
     */
    public function getAction(string $api, string $parameters = '')
    {
        $token = (string) $this->fast->getAPIConfigurations('magento_order_api_token');
        $service_url = $this->_api_url . ltrim($api, '/') . $parameters;

        $ch = curl_init($service_url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $data     = curl_exec($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            $decoded = json_decode((string) $data, true);
            return json_encode([
                'status'          => false,
                'header_code'     => $httpcode,
                'original_output' => $decoded,
                'response'        => $decoded['message'] ?? 'Magento GET error',
            ]);
        }

        return ['status' => true, 'response' => json_decode((string) $data, true)];
    }

    /**
     * Raw POST/PUT to Magento REST (kept for parity with old code).
     */
    public function postAction(string $restAction, $postdata, string $store = 'default', string $alternativeMethod = 'POST')
    {
        $url = rtrim((string) $this->fast->getAPIConfigurations('magento_api_url'), '/')
            . "/rest/{$store}/V1/" . ltrim($restAction, '/');

        $postToken = (string) $this->fast->getAPIConfigurations('magento_order_api_token');
        $body      = is_string($postdata) ? $postdata : json_encode($postdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            'Authorization: Bearer ' . $postToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $alternativeMethod,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);

        $data     = curl_exec($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            $decoded = json_decode((string) $data, true);
            return [
                'status'          => false,
                'header_code'     => $httpcode,
                'original_output' => $decoded,
                'response'        => $decoded['message'] ?? 'Magento POST error',
            ];
        }

        return ['status' => true, 'response' => json_decode((string) $data, true)];
    }
}
