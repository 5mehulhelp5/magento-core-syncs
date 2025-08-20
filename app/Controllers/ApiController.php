<?php
namespace MagentoSync\Controllers;

use MagentoSync\Models\FastModel;
use MagentoSync\Helpers\Orderupdate;
use MagentoSync\Helpers\Core as CoreHelper;
use MagentoSync\Helpers\Volo as VoloHelper;
use Illuminate\Database\Capsule\Manager as DB;

class ApiController
{
    protected FastModel $fast;
    protected ?Orderupdate $orderupdate = null;
    protected ?CoreHelper $core = null;
    protected ?VoloHelper $volo = null;

    public function __construct(?FastModel $fast = null, ?Orderupdate $orderupdate = null)
    {
        $this->fast        = $fast ?? new FastModel();
        $this->orderupdate = $orderupdate ?? (class_exists(\MagentoSync\Helpers\Orderupdate::class) ? new \MagentoSync\Helpers\Orderupdate() : null);
        $this->core        = class_exists(CoreHelper::class) ? new CoreHelper() : null;
        $this->volo        = class_exists(VoloHelper::class) ? new VoloHelper($this->fast) : null;
    }

    public function handle(float $starttime, array $segments = [])
    {
        $action = strtolower($segments[0] ?? 'index');
        switch ($action) {
            case 'price_update':        return $this->price_update();
            case 'orders_update_feed':  return $this->orders_update_feed();
            case 'full_product_feed':   return $this->full_product_feed();
            case 'image_update_feed':   return $this->image_update_feed();
            case 'partial_product_feed':return $this->partial_product_feed();
            case 'address_sku':         return $this->address_sku();
            default:                    return $this->index('general');
        }
    }

    public function index(string $post_for = 'general')
    {
        $hitType = [];
        $input   = ['data_processed' => 0];
        $body    = '';

        if (!empty($_POST)) {
            $hitType[] = 'post';
            $payload   = isset($_POST['xml']) ? $_POST['xml'] : $_POST;
            $input['data'] = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : (string)$payload;
            $input['data_processed'] = isset($_POST['xml']) ? 0 : 2;
        } else {
            $body = file_get_contents('php://input') ?: '';
            if ($body !== '') {
                $hitType[] = 'body';
                $input['data'] = $body;
            }
        }

        if (empty($input['data'])) {
            http_response_code(401);
            $input['data'] = 'Un-Authorized Attempt';
            $input['data_processed'] = 1;
            echo 'Not authorized, check your rights !!!';
        }

        $input['hit_type'] = implode(',', $hitType);
        $input['hit_ip']   = $this->getUserIP();
        $input['post_for'] = $post_for;

        // For full_product, also capture SKU→MPN pairs
        if ($post_for === 'full_product' && !empty($_POST)) {
            $pairs = $this->extractSkuTo90n($_POST);
            if (is_array($pairs) && !empty($pairs)) {
                // uses FastModel only for config’d table insert convenience
                $this->fast->addSkuTo90nData($pairs);
            }
        }

        // Insert webhook row (use DB directly; respect DDL)
        DB::connection('legacy')->table('data_by_webhooks')->insert([
            'hit_type'       => $input['hit_type'],
            'hit_ip'         => $input['hit_ip'],
            'data'           => $input['data'],
            'post_for'       => $input['post_for'],
            'data_processed' => $input['data_processed'],
            // received_at has DEFAULT CURRENT_TIMESTAMP (DDL); do not set created_at here
        ]);

        // orders_update stops here (legacy behaviour)
        if ($post_for === 'orders_update') {
            echo "Request received successfully.";
            return;
        }

        /** ---------------- LEGACY FLOW ----------------
         * 1) Volo->pushProducts('', 70)
         *    - Drains data_by_webhooks (data_processed: 0 → 8 → 10 → 1)
         *    - Adds rows into products_extract
         * 2) Then push pending rows from products_extract (status: 0 → 10 → 1)
         * ------------------------------------------------ */
        if ($this->volo && method_exists($this->volo, 'pushProducts')) {
            try {
                $this->volo->pushProducts('', 70);
            } catch (\Throwable $e) {
                error_log('Volo->pushProducts error: ' . $e->getMessage());
            }
        }else{
            die('legacy code volo helper to access push products failed');
        }

        // Now process products_extract queue (status 0 → 10 → 1)
        $this->processProductsExtractQueue(70);

        echo "Request received successfully.";
    }

    public function price_update()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(400);
            echo json_encode(['error' => 'Request does not match any route.']);
            return;
        }

        $body    = file_get_contents('php://input') ?: '';
        $payload = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Request body must be a valid JSON array.']);
            return;
        }

        // If you wire a Validator later:
        // $errors = $this->validator->validatePayload($payload);
        $errors = [];
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['errors' => $errors]);
            return;
        }

        return $this->index('price');
    }

    public function pushed_order_feed()  { return $this->index('pushed_order'); }
    public function full_product_feed()  { return $this->index('full_product'); }
    public function image_update_feed()  { return $this->index('image_update'); }
    public function partial_product_feed(){ return $this->index('partial_product'); }

    public function orders_update_feed()
    {
        $this->index('orders_update');
        if ($this->orderupdate && method_exists($this->orderupdate, 'pushOrderStatus')) {
            return $this->orderupdate->pushOrderStatus();
        }
        return null;
    }

    /**
     * Matches the legacy second stage:
     * - Fetch `products_extract` with status=0 LIMIT N
     * - Mark 10 (in progress)
     * - Send to Magento endpoint
     * - Mark 1 with server_response
     */
    protected function processProductsExtractQueue(int $limit = 70): void
    {
        // Config via FastModel (allowed)
        $baseUrl = (string)($this->fast->getAPIConfigurations('magento_api_url') ?? '');
        $mode    = (string)($this->fast->getAPIConfigurations('magento_api_integration_method') ?? '');
        $secret  = (string)($this->fast->getAPIConfigurations('magento_api_secret') ?? '');

        if ($baseUrl === '') {
            return;
        }

        $url = $baseUrl;
        if ($mode === 'ideal') {
            $url = rtrim($url, '/') . '/cloudburst/index/api';
        }

        $conn = DB::connection('legacy');
        $rows = $conn->table('products_extract')
            ->select(['id', 'update_type', 'data', 'status', 'webhook_id'])
            ->where('status', 0)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Mark in-progress
        foreach ($rows as $row) {
            $conn->table('products_extract')
                ->where('id', $row->id)
                ->update([
                    'status'     => 10,
                    'created_at' => $now, // this column exists in products_extract DDL
                ]);
        }

        foreach ($rows as $row) {
            $payload = [
                'secret' => $secret,
                'type'   => $row->update_type,
                'data'   => is_string($row->data) ? $row->data : json_encode($row->data, JSON_UNESCAPED_SLASHES),
            ];

            // Optional images processing through Volo->processImages (expects array)
            if ($this->volo && method_exists($this->volo, 'processImages')) {
                try {
                    $processed = $this->volo->processImages([
                        'update_type' => $row->update_type,
                        'data'        => json_decode((string)$row->data, true) ?? $row->data,
                        'webhook_id'  => $row->webhook_id,
                    ]);
                    if (is_array($processed) && isset($processed['data'])) {
                        $payload['data'] = is_array($processed['data'])
                            ? json_encode($processed['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : (string)$processed['data'];
                    }
                } catch (\Throwable $e) {
                    error_log('processImages failed: ' . $e->getMessage());
                }
            }

            // Push
            $resp = '';
            try {
                if ($this->core) {
                    $resp = $this->core->pushData($url, $payload);
                } else {
                    $resp = $this->curlPostCompat($url, $payload);
                }
            } catch (\Throwable $e) {
                $resp = 'Exception: ' . $e->getMessage();
            }

            // Mark done
            $conn->table('products_extract')
                ->where('id', $row->id)
                ->update([
                    'status'          => 1,
                    'created_at'      => date('Y-m-d H:i:s'),
                    'server_response' => (string)$resp,
                ]);
        }
    }

    protected function curlPostCompat(string $url, $postData)
    {
        $fields = is_array($postData) ? http_build_query($postData) : (string)$postData;
        $ch = curl_init();
        $headers = ['Content-length: ' . strlen($fields)];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, is_array($postData) ? count($postData) : 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = 'Curl error: ' . curl_error($ch);
            curl_close($ch);
            return $err;
        }
        curl_close($ch);
        return $result;
    }

    // ---------- legacy helpers (unchanged logic) ----------

    public function extractSkuTo90n($data)
    {
        $xml = $data['xml'] ?? null;
        if (!is_string($xml) || $xml === '') return null;

        $xml = trim(iconv('UTF-8', 'UTF-8//IGNORE', $xml));
        libxml_use_internal_errors(true);
        try {
            $xmlObject = simplexml_load_string($xml, "SimpleXMLElement", LIBXML_NOCDATA | LIBXML_NONET);
            if ($xmlObject === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                error_log("Failed to parse XML. Errors: " . var_export($errors, true));
                return null;
            }
        } catch (\Exception $e) {
            error_log("Exception during XML parsing: " . $e->getMessage());
            return null;
        }

        $dataArray    = json_decode(json_encode($xmlObject), true) ?? [];
        $skuTo90nData = [];

        if (isset($dataArray['Product'])) {
            if (isset($dataArray['Product']['StockNumber'])) {
                $sku = $dataArray['Product']['StockNumber'];
                $mpn = $this->findMpnInCustomFields($dataArray['Product']);
                $skuTo90nData[] = ['sku' => $sku, 'part_number' => $mpn];
            } else {
                foreach ((array)$dataArray['Product'] as $product) {
                    $sku = $product['StockNumber'] ?? null;
                    $mpn = $this->findMpnInCustomFields($product);
                    if ($sku !== null) {
                        $skuTo90nData[] = ['sku' => $sku, 'part_number' => $mpn];
                    }
                }
            }
        } else {
            error_log("'Product' key not found in array.");
        }
        return $skuTo90nData;
    }

    private function findMpnInCustomFields($product)
    {
        $mpn = null;
        if (!isset($product['CustomGroups']) || !is_array($product['CustomGroups'])) {
            return $mpn;
        }
        $groups = $product['CustomGroups']['CustomGroup'] ?? [];
        $groups = is_assoc($groups) ? [$groups] : $groups;

        foreach ($groups as $level1) {
            $fields = $level1['CustomFields']['NameValueList'] ?? [];
            $fields = is_assoc($fields) ? [$fields] : $fields;
            foreach ($fields as $_attr) {
                if (isset($_attr['Name']) && $_attr['Name'] == 'FivetechMpn') {
                    $mpn = trim(html_entity_decode($_attr['Value'] ?? '', ENT_QUOTES, 'UTF-8'));
                    return $mpn;
                }
            }
        }
        return $mpn;
    }

    private function getUserIP(): string
    {
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $_SERVER['REMOTE_ADDR']    = $_SERVER["HTTP_CF_CONNECTING_IP"];
            $_SERVER['HTTP_CLIENT_IP'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        }
        $client  = $_SERVER['HTTP_CLIENT_IP']       ?? null;
        $forward = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $remote  = $_SERVER['REMOTE_ADDR']          ?? '0.0.0.0';
        if ($client && filter_var($client, FILTER_VALIDATE_IP))  return $client;
        if ($forward && filter_var($forward, FILTER_VALIDATE_IP))return $forward;
        return $remote;
    }

    /**
     * Patch utility: read a CSV and run SKU checks/renames via Volo helper.
     * Expected headers: "SKU", "90ID"
     */
    public function address_sku()
    {
        $isCli  = (php_sapi_name() === 'cli');
        $forced = isset($_GET['force']) && $_GET['force'] == '1';
        if (!$isCli && !$forced) {
            http_response_code(403);
            echo "Forbidden: CLI only (append ?force=1 to override)\n";
            return;
        }

        if (!$this->volo) {
            http_response_code(500);
            echo "Volo helper not available; cannot run address_sku.\n";
            return;
        }

        $fileToUse = isset($_GET['file']) ? (string)$_GET['file'] : (__DIR__ . DIRECTORY_SEPARATOR . 'sku-rename.csv');
        $csvData = $this->fileToArray($fileToUse);
        if (!$csvData) {
            http_response_code(400);
            echo "CSV not found or empty: {$fileToUse}\n";
            return;
        }

        $logs = [];
        $processed = 0;
        $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 0;

        foreach ($csvData as $_list) {
            $sku  = trim((string)($_list['SKU']  ?? ''));
            $id90 = trim((string)($_list['90ID'] ?? ''));
            if ($sku === '' || $id90 === '' || $sku === $id90) continue;

            $processed++;
            try {
                $this->volo->checkAndAdd($sku, $id90);
                $logs[$sku] = $this->volo->updateProduct($sku, ['sku' => $sku], 'all', false, false, $id90);
            } catch (\Throwable $e) {
                $logs[$sku] = 'Exception: ' . $e->getMessage();
            }
            if ($limit > 0 && $processed >= $limit) break;
        }

        echo "<pre>address_sku run complete\nFile: {$fileToUse}\nProcessed: {$processed}\nResults:\n" . print_r($logs, true) . "</pre>";
    }

    private function fileToArray($filename = '', $delimiter = ',')
    {
        if (!file_exists($filename) || !is_readable($filename)) return false;

        $header = null;
        $data = [];
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (!$header) {
                    $header = $row;
                    foreach ($header as $k => $v) {
                        $k = str_replace([' ', '\'', '"'], ['_', '', ''], $k);
                        $v = str_replace([' ', '\'', '"'], ['_', '', ''], $v);
                        $header[$k] = $v;
                    }
                } else {
                    if (count($header) != count($row)) continue;
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }
        return $data;
    }
}

/** helper */
if (!function_exists('is_assoc')) {
    function is_assoc(array $arr): bool {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
