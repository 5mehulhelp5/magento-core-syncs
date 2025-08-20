<?php
namespace MagentoSync\Helpers;

use Illuminate\Database\Capsule\Manager as DB;
use MagentoSync\Models\FastModel;

class Voloorder
{
    private array $volo_order_fields = [];
    private FastModel $fast;
    private ?Volo $volo = null;

    public function __construct(?FastModel $fast = null, ?Volo $volo = null)
    {
        // Use converted FastModel (Illuminate-backed)
        $this->fast = $fast ?? new FastModel();

        // Optional Volo helper for API calls (if available)
        if ($volo !== null) {
            $this->volo = $volo;
        } elseif (class_exists(\MagentoSync\Helpers\Volo::class)) {
            $this->volo = new \MagentoSync\Helpers\Volo();
        }

        // Keep same behavior: read config for order fields
        // If your FastModel exposes getAPIConfigurations('volo_order_fields'), keep using it.
        if (method_exists($this->fast, 'getAPIConfigurations')) {
            $this->volo_order_fields = (array) $this->fast->getAPIConfigurations('volo_order_fields');
        }
    }

    /** Get orders pending processing */
    public function getOrders(array $ids = [])
    {
        $q = DB::connection('legacy')->table('orders_magento')
            ->select(['id','increment_id','order_data','datetime'])
            ->where('status', 0);

        if (!empty($ids)) {
            $q->whereIn('id', $ids);
        }

        // Return as array-of-arrays to match old code expectations
        return $q->orderBy('id', 'asc')
            ->get()
            ->map(fn($r) => (array)$r)
            ->toArray();
    }

    /** Process a list of orders (or all pending) */
    public function processOrders(array $ids = [], bool $bypass = false)
    {
        $orders = $this->getOrders($ids);
        if (!$orders) {
            return false;
        }
        foreach ($orders as $_order) {
            $this->processPerOrder($_order, $bypass);
        }
        return true;
    }

    /** Inspect order_data payloads to build attribute list */
    public function getOrderAttributes()
    {
        $rows = DB::connection('legacy')->table('orders_magento')
            ->select(['order_data'])
            ->orderBy('id', 'desc')
            ->limit(50) // safety
            ->get();

        if (!$rows->count()) {
            return false;
        }

        $fields = [];
        foreach ($rows as $_row) {
            $orderJson = $this->asArray($_row)['order_data'] ?? null;
            $order = $orderJson ? json_decode($orderJson, true) : [];
            $fields = array_merge($this->processArray($order), $fields);
            break; // keep same behavior: break after first row processed
        }
        return $fields;
    }

    /** Old per-order pipeline, kept intact but Illuminate-backed */
    public function processPerOrder(array $_order, bool $bypass=false)
    {
        $start = strtotime($_order['datetime']);
        $end   = strtotime(date("Y-m-d H:i:s"));
        $mins  = ($end - $start) / 60;
        if ($mins < 1 && $bypass !== true) {
            return;
        }

        $orderData = json_decode($_order['order_data'], true);
        $order     = $this->pullOrder($_order['increment_id']);
        $orderID   = (int) $_order['id'];

        if (is_array($order) && ($order['status'] ?? false)) {
            $order = $order['response']['items'][0] ?? [];
        }

        $items = $this->fixOrderItems($order);
        $items = false; // kept from original code (explicitly overriding)
        $orderData['order_items'] = is_array($items) ? $items : ($orderData['order_items'] ?? []);
        $order = $orderData;

        // status=2, store cron_order_data
        DB::connection('legacy')->table('orders_magento')
            ->where('id', $orderID)
            ->update([
                'status'          => 2,
                'cron_order_data' => json_encode($orderData, JSON_UNESCAPED_UNICODE),
            ]);

        // map + JSON encode
        $mapped = $this->mapOrder($order);
        $mappedJson = json_encode($mapped, JSON_UNESCAPED_UNICODE);

        DB::connection('legacy')->table('orders_magento')
            ->where('id', $orderID)
            ->update(['sent_order_data' => $mappedJson]);

        if ($bypass) {
            echo '<pre>'.print_r($mapped, true);
            return;
        }

        // Send to Volo
        if ($this->volo && method_exists($this->volo, 'getAuthorized')) {
            $tokenOut = $this->volo->getAuthorized();
            $token = $tokenOut['error'] ? null : ($tokenOut['token'] ?? null);
            $xapi  = $tokenOut['error'] ? null : (($tokenOut['xapi'] ?? [])['xapi'] ?? null);

            $auth        = $token ? "Authorization: Bearer $token" : '';
            $contentType = 'Accept: application/json';
            $headers     = array_filter([$contentType, $auth, $xapi]);

            $response = $this->volo->postAction($headers, 'salesOrders', '', $mappedJson);
        } else {
            // Fallback if Volo helper not present—don’t fail the pipeline
            $response = json_encode(['error' => 'Volo helper not available'], JSON_UNESCAPED_UNICODE);
        }

        $voloOrder = null;
        $respArr   = json_decode($response, true);
        if (isset($respArr['collectOrderResponse'][0]['status']) &&
            $respArr['collectOrderResponse'][0]['status'] === 'SUCCESS') {
            $voloOrder = $respArr['collectOrderResponse'][0]['espOrderNo'] ?? null;
        }

        DB::connection('legacy')->table('orders_magento')
            ->where('id', $orderID)
            ->update([
                'status'            => 1,
                'response'          => $response,
                'volo_order_number' => $voloOrder,
            ]);
    }

    /** Flatten array keys to list attributes (unchanged logic) */
    public function processArray($in)
    {
        $out = [];
        if (is_array($in)) {
            foreach ($in as $key => $val) {
                if ((int)$key == 0 && $key !== 'order') {
                    $out[] = $key;
                }
                if (is_array($val)) {
                    $out = array_merge($out, $this->processArray($val));
                    continue;
                }
            }
        }
        return $out;
    }

    /** Map Magento order payload to Volo order format (unchanged logic except minor safety) */
    public function mapOrder(array $_order)
    {
        $payment = false;
        if ((float)($_order['payments']['amount_paid'] ?? 0) > 0
            || (float)($_order['payments']['amount_authorized'] ?? 0) > 0) {
            $payment = true;
        }

        $billingStreet  = explode(PHP_EOL, $_order['billing_address']['street']  ?? '');
        $shippingStreet = explode(PHP_EOL, $_order['shipping_address']['street'] ?? '');

        if (!isset($_order['order']['created_at'])) {
            $_order['order']['created_at'] = date('Y-m-d H:i:s');
        }

        $output['order'][0] = [
            'orderType'       => 'ORDER',
            'orderSource'     => 'WWW',
            'externalReference'=> $_order['order']['increment_id'] ?? '',
            'date'            => date("Y-m-d\TH:i:s", strtotime($_order['order']['created_at'])) . "+0000",
            'customerCompany' => '',
            'customerName'    => trim(($_order['billing_address']['firstname'] ?? '').' '.($_order['billing_address']['lastname'] ?? '')),
            'customerAddress1'=> $billingStreet[0] ?? '',
            'customerAddress2'=> $billingStreet[1] ?? '',
            'customerAddress3'=> '',
            'customerCity'    => $_order['billing_address']['city'] ?? '',
            'customerCounty'  => $_order['billing_address']['country_id'] ?? '',
            'customerPostcode'=> $_order['billing_address']['postcode'] ?? '',
            'customerEmail'   => $_order['billing_address']['email'] ?? '',
            'customerTelephone'=> $_order['billing_address']['telephone'] ?? '',
            'customerReference'=> $_order['billing_address']['entity_id'] ?? '',
            'customerNotes'   => '',
            'deliveryCompany' => $_order['order']['shipping_method'] ?? '',
            'deliveryName'    => trim(($_order['shipping_address']['firstname'] ?? '').' '.($_order['shipping_address']['lastname'] ?? '')),
            'deliveryAddress1'=> $shippingStreet[0] ?? '',
            'deliveryAddress2'=> $shippingStreet[1] ?? '',
            'deliveryAddress3'=> '',
            'deliveryCity'    => $_order['shipping_address']['city'] ?? '',
            'deliveryCounty'  => $_order['shipping_address']['country_id'] ?? '',
            'deliveryPostcode'=> $_order['shipping_address']['postcode'] ?? '',
            'deliveryCountry' => $_order['shipping_address']['country_id'] ?? '',
            'deliveryTelephone'=> $_order['shipping_address']['telephone'] ?? '',
            'shippingMethod'  => $_order['order']['shipping_method'] ?? '',
            'shippingCost'    => $_order['order']['shipping_amount'] ?? 0,
            'insurance'       => 0,
            'discount'        => number_format(abs($_order['order']['discount_amount'] ?? 0), 2, '.', ''),
            'voucherCode'     => $_order['coupon_code'] ?? ($_order['order']['coupon_code'] ?? ''),
            'orderTotal'      => number_format($_order['order']['grand_total'] ?? 0, 2, '.', ''),
            'paymentComplete' => $payment,
        ];

        // Payments block (kept same branching)
        if (isset($_order['payments'])) {
            $pay = $_order['payments'];
            if (isset($pay['method']) && stripos($pay['method'], 'paypal') !== false) {
                $email = $pay['additional_information']['paypal_payer_status'] ?? ($pay['additional_information']['payerEmail'] ?? '');
                $tx    = $pay['last_trans_id'] ?? '';
                $output['order'][0]['payments']['payment'][] = [
                    'paymentMethod'                 => 'PayPal',
                    'paymentReference'              => $tx,
                    'paymentNotes'                  => '',
                    'paymentCCDetails'              => '',
                    'paymentGateway'                => 'PayPal',
                    'payPalEmail'                   => $email,
                    'payPalTransactionID'           => $tx,
                    'payPalProtectionEligibility'   => ($pay['additional_information']['paypal_protection_eligibility'] ?? '') === 'Eligible',
                    'amount'                        => number_format($pay['amount_ordered'] ?? 0, 2, '.', ''),
                    'paymentDate'                   => date("Y-m-d\TH:i:s", strtotime($_order['order']['created_at'])) . "+0000"
                ];
            } elseif (isset($pay['method']) && stripos($pay['method'], 'stripe') !== false) {
                $ref = $pay['additional_information']['payment_intent'] ?? ($pay['last_trans_id'] ?? '');
                $output['order'][0]['payments']['payment'][] = [
                    'paymentMethod'               => 'Credit Card',
                    'paymentReference'            => $ref,
                    'paymentNotes'                => '',
                    'paymentCCDetails'            => $ref,
                    'paymentGateway'              => 'Credit Card',
                    'payPalEmail'                 => '',
                    'payPalTransactionID'         => $ref,
                    'payPalProtectionEligibility' => false,
                    'amount'                      => number_format($pay['amount_ordered'] ?? 0, 2, '.', ''),
                    'paymentDate'                 => date("Y-m-d\TH:i:s", strtotime($_order['order']['created_at'])) . "+0000"
                ];
            } else {
                $tx = $pay['last_trans_id'] ?? '';
                $output['order'][0]['payments']['payment'][] = [
                    'paymentMethod'               => 'Credit Card',
                    'paymentReference'            => $tx,
                    'paymentNotes'                => '',
                    'paymentCCDetails'            => $tx,
                    'paymentGateway'              => 'Credit Card',
                    'payPalEmail'                 => '',
                    'payPalTransactionID'         => $tx,
                    'payPalProtectionEligibility' => false,
                    'amount'                      => number_format($pay['amount_ordered'] ?? 0, 2, '.', ''),
                    'paymentDate'                 => date("Y-m-d\TH:i:s", strtotime($_order['order']['created_at'])) . "+0000"
                ];
            }
        }

        $output['order'][0]['currencyCode']   = $_order['order']['order_currency_code'] ?? '';
        $output['order'][0]['sellerUsername'] = method_exists($this->fast, 'getAPIConfigurations')
            ? $this->fast->getAPIConfigurations('volo_seller_name')
            : '';

        // Items
        $total = 0;
        $totalItems = 0;
        if (isset($_order['order_items']) && is_array($_order['order_items'])) {
            foreach ($_order['order_items'] as $_item) {
                // SKU mapping via Volo helper if available
                $fivetech_sku = $_item['sku'] ?? '';
                if ($this->volo && method_exists($this->volo, 'getProduct')) {
                    $_product   = $this->volo->getProduct($fivetech_sku, true);
                    $candidate  = $this->findSkuInPd($_product);
                    if (is_string($candidate) && strlen($candidate)) {
                        $fivetech_sku = $candidate;
                    }
                }

                $taxCalculate = isset($_item['price_incl_tax']) ? ($_item['price_incl_tax'] / 1.2) : 0;
                $output['order'][0]['orderItems']['item'][] = [
                    'stockNumber'         => $fivetech_sku,
                    'quantity'            => (int)($_item['qty_ordered'] ?? 0),
                    'unitCost'            => $_item['price']         ?? 0,
                    'unitCostIncludesTax' => $_item['price_incl_tax']?? 0,
                    'unitItemTax'         => ($_item['price_incl_tax'] ?? 0) - $taxCalculate,
                    'unitShippingTax'     => 0,
                    'unitShippingAmount'  => 0,
                    'backOrder'           => false
                ];

                $total      += ($_item['price_incl_tax'] ?? 0);
                $totalItems++;
            }
        }

        if (isset($_order['order_items']) && $total == 0) {
            $total -= ($_order['order']['shipping_amount'] ?? 0);
            // Original code commented out recalculation; keep same behavior.
        }

        $output['order'][0]['orderStatus']       = 'WAITING_FOR_DELIVERY';
        $output['order'][0]['webOrderID']        = $_order['order']['entity_id'] ?? '';
        $output['webSiteDomainName']             = method_exists($this->fast, 'getAPIConfigurations')
            ? $this->fast->getAPIConfigurations('volo_order_source')
            : '';

        return $output;
    }

    /** Pull order from Magento REST (kept as cURL, minimum changes) */
    public function pullOrder($incrementId)
    {
        $url = method_exists($this->fast, 'getAPIConfigurations')
            ? rtrim($this->fast->getAPIConfigurations('magento_api_url'), '/')
            : '';
        $url = $url . "/rest/V1/orders/?searchCriteria[filter_groups][0][filters][0][field]=increment_id&searchCriteria[filter_groups][0][filters][0][condition_type]=eq&searchCriteria[filter_groups][0][filters][0][value]=$incrementId";
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
            return ['status' => false, 'header_code' => $http, 'original_output' => $arr, 'response' => $arr['message'] ?? ''];
        }

        $arr = json_decode($data, true);
        return ['status' => true, 'response' => $arr];
    }

    /** Normalize items combining parent/associated like original */
    public function fixOrderItems($order)
    {
        $items = [];
        if (!isset($order['items']) || !is_array($order['items'])) {
            return $items;
        }

        foreach ($order['items'] as $item) {
            if (isset($item['parent_item_id'])) {
                $itemID = $item['parent_item_id'];
                $items[$itemID]['associated'][] = $item;
            } else {
                $itemID = $item['item_id'];
                $items[$itemID] = $item;
            }
        }

        foreach ($items as $x => $_item) {
            $total = $_item['price']         ?? 0;
            $incl  = $_item['price_incl_tax']?? 0;

            if (isset($_item['associated']) && count($_item['associated']) === 1) {
                foreach ($items[$x]['associated'] as $k => $assoc) {
                    if (($assoc['price'] ?? 0) == 0 && $total != 0) {
                        $items[$x]['associated'][$k]['price']          = $total;
                        $items[$x]['associated'][$k]['price_incl_tax'] = $incl;
                    }
                }
                if (($_item['product_type'] ?? '') === 'configurable') {
                    $associated = $items[$x]['associated'][0];
                    unset($items[$x]);
                    $items[$x] = $associated;
                }
            }
        }
        return $items;
    }

    /** Extract fivetech_sku from product payload (unchanged logic) */
    public function findSkuInPd($_product)
    {
        if (isset($_product['response']['items'][0])) {
            $_product = $_product['response']['items'][0];
        }
        $output = false;
        $attribute_code = 'fivetech_sku';

        foreach ((array)$_product as $key => $value) {
            if ($key === $attribute_code) {
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

    public function extractOrderID($data)
    {
        $data = is_array($data) ? $data : json_decode($data, true);
        if (isset($data['collectOrderResponse'][0]['espOrderNo'])) {
            return $data['collectOrderResponse'][0]['espOrderNo'];
        }
        return false;
    }

    public function fixOrderIds()
    {
        $rows = DB::connection('legacy')->table('orders_magento')
            ->select(['id','response','volo_order_number'])
            ->whereNull('volo_order_number')
            ->where('status', 1)
            ->get();

        foreach ($rows as $row) {
            $arr = (array)$row;
            $id  = (int)$arr['id'];
            $voloOrderId = $this->extractOrderID($arr['response'] ?? '');
            if ($voloOrderId) {
                DB::connection('legacy')->table('orders_magento')
                    ->where('id', $id)
                    ->update(['volo_order_number' => $voloOrderId]);
            }
        }
    }

    /** Helper to safely cast stdClass row to array */
    private function asArray($row): array
    {
        return is_array($row) ? $row : (array)$row;
    }
}
