<?php
namespace MagentoSync\Helpers;

use Illuminate\Database\Capsule\Manager as DB;
use MagentoSync\Models\FastModel;

class Orderupdate
{
    private array $volo_order_fields = [];
    private FastModel $fast;
    private ?Volo $volo = null;
    private ?Voloorder $voloorder = null;

    public function __construct(?FastModel $fast = null, ?Volo $volo = null, ?Voloorder $voloorder = null)
    {
        // Illuminate-backed model for configs & any lookups you already have
        $this->fast = $fast ?? new FastModel();

        // Optional helpers (instantiate if present)
        $this->volo = $volo ?? (class_exists(\MagentoSync\Helpers\Volo::class) ? new \MagentoSync\Helpers\Volo() : null);
        $this->voloorder = $voloorder ?? (class_exists(\MagentoSync\Helpers\Voloorder::class) ? new \MagentoSync\Helpers\Voloorder($this->fast, $this->volo) : null);

        if (method_exists($this->fast, 'getAPIConfigurations')) {
            $this->volo_order_fields = (array) $this->fast->getAPIConfigurations('volo_order_fields');
        }
    }

    /** Old: pushOrderStatus() */
    public function pushOrderStatus(): void
    {
        $rows = DB::connection('legacy')->table('data_by_webhooks')
            ->select(['id','data'])
            ->where('data_processed', 0)
            ->where('post_for', 'orders_update')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($rows as $row) {
            $arr = (array) $row;
            $id  = (int) $arr['id'];
            $orderUpdate = $this->decodeXml($arr['data'], $id);
            $response = $this->processUpdateOrder($orderUpdate);

            DB::connection('legacy')->table('data_by_webhooks')
                ->where('id', $id)
                ->update([
                    'data_processed' => 1,
                    'dispatched_at'  => date('Y-m-d H:i:s'),
                    'response'       => is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE),
                ]);
        }
    }

    /** Port of processUpdateOrder() */
    protected array $order_items;

    public function processUpdateOrder($order)
    {
        $order = $order['Order'];
        $id = $order['ESPOrderNumber'];

        // Query Volo for full order (uses your Volo helper)
        $voloData = $this->volo && method_exists($this->volo, 'execute')
            ? $this->volo->execute('salesOrders', '?espOrderNo=' . $id)
            : ['outgoingOrders' => ['order' => [['orderItems' => ['item' => []]]]]];

        $finalOrder      = $voloData['outgoingOrders']['order'][0] ?? [];
        $voloOrderItems  = $finalOrder['orderItems']['item'] ?? [];
        $this->order_items = $voloOrderItems;

        $courier        = $order['Courier'] ?? '';
        $shippmentTime  = $order['Timestamp'] ?? '';
        $trackingNumber = $order['ConsignmentNumber'] ?? '';

        if (!is_array($trackingNumber) && !strlen($trackingNumber)) {
            $trackingNumber = "V-" . $id;
            $courier = 'Tracking Support Number';
        }

        // Lookup Magento increment_id from our legacy DB
        $row = DB::connection('legacy')->table('orders_magento')
            ->select(['increment_id'])
            ->where('volo_order_number', $id)
            ->first();

        $incrementID = $row ? $row->increment_id : null;

        if (($order['Status'] ?? '') === 'CANCELLED') {
            return $this->createCreditMemo($incrementID);
        }

        $response = json_decode($this->pullOrder($incrementID), true);
        if (!($response['status'] ?? false)) {
            return json_encode($response, JSON_UNESCAPED_UNICODE);
        }

        $items     = $this->walkOrder($response['response']['items']);
        $orderID   = $response['response']['items'][0]['entity_id'] ?? null;
        $shipment  = $this->finalizeProcessingData($items, $courier, $trackingNumber, $voloOrderItems);

        $shipResp  = json_decode($this->pushShipment($orderID, $shipment['shipment']), true);

        $output = [
            'shipmentData'       => $shipment['shipment'],
            'shipmentResponse'   => $shipResp,
            'VoloSerialResponse' => $shipment['serialNumber'],
        ];

        if (($shipResp['status'] ?? false)) {
            $shippmentID = $shipResp['response'] ?? null;
            return $this->pushTracking($orderID, $shippmentID, $courier, $trackingNumber, $output);
        }

        return json_encode($output, JSON_UNESCAPED_UNICODE);
    }

    /** Port finalizeProcessingData() */
    public function finalizeProcessingData($items, $courier, $trackingNumber, $voloItems)
    {
        $x = 0; $output = ['items' => []];

        foreach ($items as $_item) {
            $output['items'][$x] = [
                'order_item_id' => $_item['item_id'],
                'qty'           => $_item['qty']
            ];
            if (isset($_item['associated'])) {
                foreach ($_item['associated'] as $item) {
                    $x++;
                    $output['items'][$x] = [
                        'order_item_id' => $item['item_id'],
                        'qty'           => $item['qty']
                    ];
                }
            }
            $x++;
        }

        $output = array_merge($output, [
            'notify' => false,
            'tracks' => [[
                'track_number' => $trackingNumber,
                'title'        => $courier,
                'carrier_code' => 'custom',
                'extension_attributes' => [],
            ]],
        ]);

        return ['serialNumber' => $voloItems, 'shipment' => $output];
    }

    public function findSerialNumber($sku, $volo_items)
    {
        foreach ((array)$volo_items as $value) {
            if (($value['stockNumber'] ?? null) == $sku) {
                return $value['serialNumber'] ?? false;
            }
        }
        return false;
    }

    /** Port walkOrder() */
    public function walkOrder($order)
    {
        $items = [];
        $list  = $order[0]['items'] ?? [];
        foreach ($list as $data) {
            $fivetech_sku = $data['sku'] ?? '';
            if (isset($data['parent_item_id'])) {
                $itemID = $data['parent_item_id'];
                $items[$itemID]['associated'][] = [
                    'sku'     => $fivetech_sku,
                    'item_id' => $data['item_id'],
                    'total'   => $data['row_total_incl_tax'],
                    'qty'     => $data['qty_ordered'],
                ];
            } else {
                $itemID = $data['item_id'];
                $items[$itemID] = [
                    'sku'     => $fivetech_sku,
                    'item_id' => $data['item_id'],
                    'total'   => $data['row_total_incl_tax'],
                    'qty'     => $data['qty_ordered'],
                ];
            }
        }

        foreach ($items as $id => $_item) {
            if (($_item['total'] ?? 0) > 0 && isset($_item['associated'])) {
                $total = $_item['total'];
                foreach ($items[$id]['associated'] as $k => $v) {
                    if (($v['total'] ?? 0) == 0) {
                        $items[$id]['associated'][$k]['total'] = $total;
                    }
                }
            }
        }
        return $items;
    }

    public function findSkuInPd($_product)
    {
        if (isset($_product['response']['items'][0])) {
            $_product = $_product['response']['items'][0];
        }
        $output = false; $attribute_code = 'fivetech_sku';
        foreach ((array)$_product as $key => $value) {
            if ($key == $attribute_code) {
                $output = $value;
            }
            if (is_array($value) && $key === 'custom_attributes') {
                foreach ($value as $_v) {
                    if (($_v['attribute_code'] ?? '') === $attribute_code) {
                        $output = $_v['value'] ?? null;
                    }
                }
            }
        }
        return $output;
    }

    /** Port decodeXml(); also persists XML parse errors to DB (legacy) */
    public function decodeXml($xml, $id)
    {
        $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
        libxml_use_internal_errors(true);
        try {
            $xmlObj = simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (\Exception $e) {
            $xmlObj = false;
        }

        if ($xmlObj === false) {
            $errors = libxml_get_errors();
            $errorsStr = var_export($errors, true);
            DB::connection('legacy')->table('data_by_webhooks')
                ->where('id', (int)$id)
                ->update([
                    'data_processed' => 9,
                    'response'       => $errorsStr
                ]);
            // keep original behavior: both write & die()
            die($errorsStr);
        }

        $json = json_encode($xmlObj);
        return json_decode($json, true);
    }

    /** cURL calls left intact (minimal change) */
    public function pullOrder($incrementId)
    {
        $base = method_exists($this->fast, 'getAPIConfigurations')
            ? rtrim($this->fast->getAPIConfigurations('magento_api_url'), '/')
            : '';
        $url = $base . "/rest/V1/orders/?searchCriteria[filter_groups][0][filters][0][field]=increment_id"
            . "&searchCriteria[filter_groups][0][filters][0][condition_type]=eq"
            . "&searchCriteria[filter_groups][0][filters][0][value]=$incrementId";
        $apiToken = method_exists($this->fast, 'getAPIConfigurations')
            ? $this->fast->getAPIConfigurations('magento_order_api_token')
            : '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
        ]);
        $data = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            $arr = json_decode($data, true);
            return json_encode(['status' => false, 'header_code' => $http, 'original_output' => $arr, 'response' => $arr['message'] ?? ''], JSON_UNESCAPED_UNICODE);
        }

        $arr = json_decode($data, true);
        return json_encode(['status' => true, 'response' => $arr], JSON_UNESCAPED_UNICODE);
    }

    public function pushShipment($id, $postdata)
    {
        $base = method_exists($this->fast, 'getAPIConfigurations')
            ? rtrim($this->fast->getAPIConfigurations('magento_api_url'), '/')
            : '';
        $url = $base . "/rest/V1/order/$id/ship";
        $apiToken = method_exists($this->fast, 'getAPIConfigurations')
            ? $this->fast->getAPIConfigurations('magento_order_api_token')
            : '';

        $payload = is_array($postdata) ? json_encode($postdata, JSON_UNESCAPED_UNICODE) : (string)$postdata;

        $headers = [
            'Content-Type: application/json',
            'Content-Lenght: ' . strlen($payload),
            'Authorization: Bearer ' . $apiToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            $arr = json_decode($data, true);
            return json_encode(['status' => false, 'header_code' => $http, 'original_output' => $arr, 'response' => $arr['message'] ?? ''], JSON_UNESCAPED_UNICODE);
        }
        $arr = json_decode($data, true);
        return json_encode(['status' => true, 'response' => $arr], JSON_UNESCAPED_UNICODE);
    }

    public function pushTracking($orderID, $shippmentID, $courier, $trackingID, $additional_returns)
    {
        $base = method_exists($this->fast, 'getAPIConfigurations')
            ? rtrim($this->fast->getAPIConfigurations('magento_api_url'), '/')
            : '';
        $url = $base . "/rest/V1/shipment/track";
        $apiToken = method_exists($this->fast, 'getAPIConfigurations')
            ? $this->fast->getAPIConfigurations('magento_order_api_token')
            : '';

        $postdata = [
            'entity' => [
                'order_id'      => $orderID,
                'parent_id'     => $shippmentID,
                'track_number'  => $trackingID,
                'carrier_code'  => 'custom',
                'title'         => $courier,
            ]
        ];

        $payload = json_encode($postdata, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Content-Lenght: ' . strlen($payload),
            'Authorization: Bearer ' . $apiToken,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            $arr = json_decode($data, true);
            return json_encode([
                'status'             => false,
                'header_code'        => $http,
                'original_output'    => $arr,
                'response'           => $arr['message'] ?? '',
                'additional_returns' => $additional_returns
            ], JSON_UNESCAPED_UNICODE);
        }
        $arr = json_decode($data, true);
        return json_encode([
            'status'             => true,
            'response'           => $arr,
            'additional_returns' => $additional_returns
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Credit memo helpers */
    public function createCreditMemo($incrementID)
    {
        // pull full order via Voloorder helper to reuse existing logic
        if (!$this->voloorder) {
            $this->voloorder = new Voloorder($this->fast, $this->volo);
        }
        $orderData = $this->voloorder->pullOrder($incrementID);
        if (($orderData['status'] ?? false)) {
            $orderData = $orderData['response']['items'][0] ?? [];
        }

        $items = [];
        $stock_items = [];
        $id = $orderData['entity_id'] ?? null;

        foreach (($orderData['items'] ?? []) as $_item) {
            $items[] = [
                '_type'        => $_item['product_type'] ?? '',
                'order_item_id'=> $_item['item_id'] ?? null,
                'qty'          => $_item['qty_ordered'] ?? 0,
                'parent'       => $_item['parent_item_id'] ?? false,
            ];
        }

        foreach ($items as $key => $_item) {
            if (($_item['_type'] ?? '') !== 'configurable') {
                $stock_items[] = $_item['order_item_id'];
            }
            if ($_item['parent'] !== false) {
                unset($items[$key]);
            }
            unset($items[$key]['_type'], $items[$key]['parent']);
        }

        $userData = [
            'items'     => array_values($items),
            'notify'    => false,
            'arguments' => [
                'shipping_amount'      => 0,
                'adjustment_positive'  => 0,
                'adjustment_negative'  => 0,
                // 'extension_attributes' => [ 'return_to_stock_items' => $stock_items ]
            ],
        ];
        return $this->creditMemoPush($id, $userData);
    }

    public function creditMemoPush($id, $userdata)
    {
        $payload = is_array($userdata) ? json_encode($userdata, JSON_UNESCAPED_UNICODE) : (string)$userdata;

        $base = method_exists($this->fast, 'getAPIConfigurations')
            ? rtrim($this->fast->getAPIConfigurations('magento_api_url'), '/')
            : '';
        $url = $base . "/rest/V1/order/$id/refund";
        $apiToken = method_exists($this->fast, 'getAPIConfigurations')
            ? $this->fast->getAPIConfigurations('magento_order_api_token')
            : '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Lenght: ' . strlen($payload),
                'Authorization: Bearer ' . $apiToken
            ],
        ]);
        $data = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200) {
            $arr = json_decode($data, true);
            return ['status' => false, 'header_code' => $http, 'original_output' => $arr, 'response' => $arr['message'] ?? ''];
        }
        return ['status' => true, 'response' => $data];
    }
}
