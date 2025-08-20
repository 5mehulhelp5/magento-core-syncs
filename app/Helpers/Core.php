<?php

declare(strict_types=1);

namespace MagentoSync\Helpers;

use Exception;
use Illuminate\Database\Capsule\Manager as DB; // illuminate/database only
use MagentoSync\Models\FastModel;              // keep legacy utility methods (autoMaping, getMpnByStockNumber, getAPIConfigurations, ...)
use MagentoSync\Services\SessionService;

/**
 * Core helper — framework agnostic.
 *
 * RULE (as requested):
 * - Replace ONLY the direct CRUD calls that used to go through $this->fast->select()/update() with local Capsule queries.
 * - KEEP other FastModel methods exactly as-is (e.g., getMpnByStockNumber, getAPIConfigurations, autoMaping, etc.).
 */
class Core
{
    /** @var FastModel */
    protected FastModel $fast;

    /** @var array<string, object> */
    protected array $helpers = [];
    private SessionService $session;


    public function __construct(?FastModel $fast = null)
    {
        // Preserve legacy behavior: rely on caller/bootstrap to init Capsule; we don't own it here.
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $this->session = $session ?? new SessionService();
        $this->fast = $fast ?? new FastModel();
    }

    public function get(string $key)         { return $this->session->get($key); }
    public function set(string $key, $value = null): void { $this->session->set($key, $value); }
    public function destroy(): void          { $this->session->destroy(); }
    public function getAsFlash(string $key)  { return $this->session->getAsFlash($key); }

    protected function redirect(string $path, ?string $guard = null): void
    {
        if (PHP_SAPI === 'cli') { return; }
        if (!headers_sent()) {
            header('Location: ' . $path, true, 302);
        } else {
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">';
        }
        exit;
    }

    protected function getHelper(string $name): object
    {
        $key = strtolower($name);
        if (isset($this->helpers[$key])) return $this->helpers[$key];
        $class = __NAMESPACE__ . '\\' . ucfirst($key);
        if (!class_exists($class)) {
            throw new \RuntimeException("Helper '{$name}' not found at {$class}");
        }
        return $this->helpers[$key] = new $class();
    }

    // =============== ported methods with ONLY direct CRUD moved to Capsule ===============

    public function validateLogin(bool $redirect = true): bool
    {
        if (!$this->get('is_logged_in') && $redirect === true) {
            $this->redirect('login/index', 'Admin');
            exit; // keep legacy exit semantics
        }
        return (bool) $this->get('is_logged_in');
    }

    public function get_gravatar(string $email, int $s = 256, string $d = 'mp', string $r = 'g'): string
    {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s={$s}&d={$d}&r={$r}";
        return $url;
    }

    public function getProfileImage(int $s = 256): string
    {
        $loginData = (string) $this->get('login_data');
        if (!strlen($loginData)) {
            return $this->get_gravatar('nosspam@askwhyweb.com', $s);
        }
        $decoded = json_decode($loginData, true) ?: [];
        $email = $decoded['username'] ?? 'nosspam@askwhyweb.com';
        return $this->get_gravatar($email, $s);
    }

    /**
     * REPLACED direct fast->select & fast->update with Capsule here ONLY.
     */
    public function getFeedPush(string $pushType = 'full_product', int $batch = 0, int $limit = 50): array
    {
        $this->getHelper('volo');

        $q = DB::connection('legacy')
            ->table('data_by_webhooks')
            ->select(['data','id','post_for'])
            ->where('data_processed', 0);

        if (strlen($pushType) > 0) {
            $q->where('post_for', $pushType)->limit($limit);
        } else {
            $q->whereIn('post_for', ['full_product','image_update','partial_product','price'])->limit(50);
        }

        $data = json_decode(json_encode($q->get()), true) ?: [];

        $finalProduct = [];
        $i = 0;
        $output = [];
        foreach ($data as $_product) {
            if (($_product['post_for'] ?? '') === 'price') {
                $tst = json_decode($_product['data'] ?? '[]', true);
            } else {
                $tst = $this->processEachProduct($_product);
            }
            $i++;
            $internalBatch = (int) round(microtime(true) * 1000) + $i;
            $tst = ['id' => $_product['id'], 'post_for' => $_product['post_for'], 'data' => $tst];

            if (isset($tst['data']['sku'])) {
                $finalProduct[] = $tst['data'];
                $output[] = [$tst];
            } else {
                $finalProduct = array_merge($finalProduct, $tst['data']);
                $output = array_merge($output, [$tst]);
            }

            // REPLACED: $this->fast->update(...)
            DB::connection('legacy')
                ->table('data_by_webhooks')
                ->where('id', $_product['id'])
                ->update(['batch' => $batch, 'data_processed' => 8, 'innerBatch' => $internalBatch]);
        }
        return $output;
    }

    public function processEachProduct(array $data)
    {
        $xml = $data['data'] ?? '';
        $id  = $data['id'] ?? null;
        if (!$xml) { return []; }

        $xml = iconv('UTF-8', 'UTF-8//IGNORE', (string) $xml);

        libxml_use_internal_errors(true);
        try {
            $sxe = simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Exception $e) { $sxe = false; }

        if ($sxe === false) {
            $errors = libxml_get_errors();
            $errorsDump = var_export($errors, true);
            // REPLACED: $this->fast->update(...)
            if ($id !== null) {
                DB::connection('legacy')->table('data_by_webhooks')
                    ->where('id', $id)
                    ->update(['data_processed' => 9, 'response' => $errorsDump]);
            }
            // keep legacy behaviour (was die()); safer to return []
            return [];
        }

        $json = json_encode($sxe);
        $dataArray = json_decode($json, true);

        $output = [];
        if (isset($dataArray['Product'])):
            foreach($dataArray['Product'] as $key => $_product){
                if(isset($_product['StockNumber'])){
                    $output[] = !is_array($_product)
                        ? $this->extractProduct(str_replace(' ','-', (string) $_product))
                        : $this->extractProduct($_product);
                    continue; // avoid re-processing single case
                }
                if($key === 'StockNumber'){
                    $output[] = $this->extractProduct($dataArray['Product']);
                }
            }
        elseif(isset($dataArray['ProductImage']) && isset($dataArray['ProductImage']['Variations'])):
            $variations = $dataArray['ProductImage']['Variations'];
            unset($dataArray['ProductImage']['Variations']);
            $output[] = $this->extractProduct($dataArray['ProductImage']);
            foreach($dataArray['ProductImage'] as $key => $_product){
                if(isset($_product['StockNumber'])){
                    $output[] = $this->extractProduct($_product);
                    continue;
                }
                if($key === 'StockNumber'){
                    $output[] = $this->extractProduct($dataArray['ProductImage']);
                }
            }
            foreach($variations as $key => $_product){
                if(isset($_product['StockNumber'])){
                    $output[] = $this->extractProduct($_product); continue; }
                elseif(is_array($_product) && !isset($_product['StockNumber'])){
                    foreach($_product as $_key => $_prod){
                        if(isset($_prod['StockNumber'])){ $output[] = $this->extractProduct($_prod); continue; }
                    }
                }
                if($key === 'StockNumber'){
                    $output[] = $this->extractProduct($dataArray['ProductImage']);
                }
            }
        elseif(isset($dataArray['ProductImage'])):
            $output[] = $this->extractProduct($dataArray['ProductImage']);
            foreach($dataArray['ProductImage'] as $key => $_product){
                if(isset($_product['StockNumber'])){ $output[] = $this->extractProduct($_product); continue; }
                if($key === 'StockNumber'){ $output[] = $this->extractProduct($dataArray['ProductImage']); }
            }
        elseif(isset($dataArray['Images'])):
            $output[] = $this->extractProduct($dataArray['Images']);
        endif;

        return $output;
    }

    public function extractProduct($dataArray)
    {
        $finalProduct = [];
        $sku = '';
        $variation_properties = [];
        $customGroups = isset($dataArray['UseCustomGroups']) ? $dataArray['UseCustomGroups'] : '';

        if (!is_array($dataArray)) {
            return ['name' => (string) $dataArray];
        }

        foreach($dataArray as $key => $_product){
            if($key === 'StockNumber'){
                // KEEP legacy FastModel method here
                $stockNumber = $this->fast->getMpnByStockNumber($_product);
                $data = [
                    'sku' => $this->validateSet('StockNumber', $stockNumber),
                    'fivetech_sku' => $this->validateSet('StockNumber', $_product),
                ];
                $finalProduct = array_merge($finalProduct, $data);
                $sku = $this->validateSet('StockNumber', $_product);
                continue;
            }
            if($key === 'StockLevel'){
                $finalProduct = array_merge($finalProduct, ['qty' => $this->validateSet('StockLevel', $_product)]);
                continue;
            }
            if($key === 'Weight'){
                $finalProduct = array_merge($finalProduct, ['weight' => (int) $this->validateSet('Weight', $_product)]);
                continue;
            }
            if($key === 'Images' && isset($_product['ImageURL']) && is_array($_product['ImageURL']) && count($_product['ImageURL']) > 0){
                $finalProduct = array_merge($finalProduct, ['images' => $_product['ImageURL']]);
                continue;
            } elseif($key === 'Images' && isset($_product['ImageURL']) && strlen((string) $_product['ImageURL'])){
                $finalProduct = array_merge($finalProduct, ['images' => [$_product['ImageURL']]]);
                continue;
            } elseif($key === 'Images'){
                // retain parity; do nothing
                continue;
            }
            if($key === 'Title'){
                $name = $this->validateSet('Title', $_product);
                if(is_array($name)){
                    $finalProduct = array_merge($finalProduct, $name);
                } else {
                    $finalProduct = array_merge($finalProduct, ['name' => $name]);
                }
            }
            if($key === 'ListingTitle'){
                $eBayTitle = $this->validateSet('ListingTitle', $_product);
                if(!is_array($eBayTitle) && strlen((string)$eBayTitle) > 0){
                    $finalProduct = array_merge($finalProduct, ['ebay_title' => str_replace(["\r\n, \n"], '', (string)$eBayTitle)]);
                }
            }
            if($key === 'WebsiteTitle'){
                $name = $this->validateSet('WebsiteTitle', $_product);
                if(is_array($name)){
                    $finalProduct = array_merge($finalProduct, $name);
                } elseif(strlen((string)$name) > 0){
                    $finalProduct = array_merge($finalProduct, ['name' => str_replace(["\r\n, \n"], '', (string)$name)]);
                }
                continue;
            }
            if($key === 'ShortDescription'){
                $finalProduct = array_merge($finalProduct, ['short_description' => $this->validateSet('ShortDescription', $_product)]);
                continue;
            }
            if($key === 'Description3'){
                $finalProduct = array_merge($finalProduct, ['description' => $this->validateSet('Description3', $_product)]);
                continue;
            }
            if($key === 'Price' && !isset($finalProduct['price'])){
                $finalProduct = array_merge($finalProduct, ['price' => $this->validateSet('Price', $_product)]);
                continue;
            }
            if($key === 'SellPrice' && !isset($finalProduct['price'])){
                $finalProduct = array_merge($finalProduct, ['price' => $this->validateSet('SellPrice', $_product)]);
                continue;
            }
            if($key === 'SalePrice'){
                $finalProduct = array_merge($finalProduct, ['special_price' => $this->validateSet('SalePrice', $_product)]);
                continue;
            }
            if($key === 'BOXMetaKeyword'){
                $finalProduct = array_merge($finalProduct, ['meta_keyword' => $this->validateSet('BOXMetaKeyword', $_product)]);
            }
            if($key === 'BOXMetaTitle'){
                $finalProduct = array_merge($finalProduct, ['meta_title' => $this->validateSet('BOXMetaTitle', $_product)]);
            }
            if($key === 'BOXMetaDescription'){
                $finalProduct = array_merge($finalProduct, ['meta_description' => $this->validateSet('BOXMetaDescription', $_product)]);
            }
            if($key === 'UseCustomGroups'){
                $customGroups = $_product;
            }
            if ($key === 'ProductCategories') {
                $categories = [];
                $i = 0;
                if (isset($_product['ProductCategory']) && is_array($_product['ProductCategory'])) {
                    foreach ($_product['ProductCategory'] as $_category) {
                        if (is_array($_category)) {
                            // legacy debug left as-is
                        } elseif (strlen((string)$_category) > 0) {
                            $cats = explode('>', (string)$_category);
                            if (is_array($cats) && count($cats) > 0) {
                                // KEEP legacy FastModel config access
                                $base_cat = $this->fast->getAPIConfigurations('volo_root_category');
                                $base_cat = htmlentities($base_cat);
                                if (trim($cats[0]) === $base_cat) {
                                    $i++;
                                    $categories[$i] = [$cats[0], end($cats)];
                                }
                            }
                        }
                    }
                } else {
                    $_category = $_product['ProductCategory'] ?? '';
                    if (strlen((string)$_category) > 0) {
                        $cats = explode('>', (string)$_category);
                        if (is_array($cats) && count($cats) > 0) {
                            $base_cat = $this->fast->getAPIConfigurations('volo_root_category');
                            $base_cat = htmlentities($base_cat);
                            if (trim($cats[0]) === $base_cat) {
                                $i++;
                                $categories[$i] = [$cats[0], end($cats)];
                            }
                        }
                    }
                }
                $data = ['categories' => $categories];
                if (count($categories)) $finalProduct = array_merge($finalProduct, $data);
                continue;
            }

            if($key === 'CustomGroups' && is_array($_product)){
                $useGroup = false;
                if(strlen((string)$customGroups) > 0){
                    $customGroups = explode(',', (string) $customGroups);
                    if(is_array($customGroups)){
                        foreach($customGroups as $cg => $gc){ $customGroups[$cg] = trim((string)$gc); }
                    }
                    $useGroup = true;
                }
                $__out = [];
                foreach(($_product['CustomGroup'] ?? []) as $level1){
                    $groupName = $level1['@attributes']['groupName'] ?? '';

                    if(($useGroup && in_array($groupName, $customGroups)) || !$useGroup){
                        $oldstr = $level1['CustomFields'] ?? [];
                        $_xval = $this->nestedLoop($oldstr); // this will use Capsule for override_attributes lookup
                        if(is_array($_xval) && count($_xval)){ $__out[] = $_xval; }
                    }

                    $attributeName = '';
                    if(isset($level1['CustomFields']) && count($level1['CustomFields']) > 0){
                        foreach(($level1['CustomFields']['NameValueList'] ?? []) as $_key => $_attr){
                            if(is_array($_attr)){
                                if(($useGroup && in_array($groupName, $customGroups)) || !$useGroup){
                                    // KEEP FastModel autoMaping (utility, not CRUD here)
                                    $data = $this->fast->autoMaping($_attr['Name'] ?? '', html_entity_decode((string)($_attr['Value'] ?? ''), ENT_QUOTES, 'UTF-8'));
                                    $finalProduct = array_merge($finalProduct, $data);
                                    if(($_attr['Name'] ?? '') === 'MPN'){
                                        $finalProduct = array_merge($finalProduct, ['mpn' => html_entity_decode((string)($_attr['Value'] ?? ''), ENT_QUOTES, 'UTF-8')]);
                                    }
                                    if(($_attr['Name'] ?? '') === 'FivetechMpn'){
                                        $val = html_entity_decode((string)($_attr['Value'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $finalProduct = array_merge($finalProduct, ['sku' => $val, 'fivetech_mpn' => $val, 'part_number' => $val]);
                                    }
                                    if(($_attr['Name'] ?? '') === 'ModelKey'){
                                        $finalProduct = array_merge($finalProduct, ['model_key' => html_entity_decode((string)($_attr['Value'] ?? ''), ENT_QUOTES, 'UTF-8')]);
                                    }
                                }
                                if($groupName === 'ASUS 2in1' && (($_attr['Name'] ?? '') === 'Store')){
                                    $finalProduct = array_merge($finalProduct, ['__stores__' => ($_attr['Value'] ?? null)]);
                                }
                                if(($_attr['Name'] ?? '') === 'Variation' && (($_attr['Value'] ?? '') === 'Yes')){
                                    $finalProduct = array_merge($finalProduct, ['associated_parent' => true]);
                                }
                                continue;
                            } else {
                                if($groupName === 'ASUS 2in1' && $attributeName === 'Store'){
                                    $finalProduct = array_merge($finalProduct, ['__stores__' => $_attr]);
                                }
                                if(($useGroup && in_array($groupName, $customGroups)) || !$useGroup){
                                    $data = $this->fast->autoMaping($attributeName, (string) $_attr);
                                    $finalProduct = array_merge($finalProduct, $data);
                                }
                                if($attributeName === 'Variation' && ((string)$_attr) === 'Yes'){
                                    $finalProduct = array_merge($finalProduct, ['associated_parent' => true]);
                                }
                                $attributeName = (string) $_attr;
                            }
                        }
                    }
                }
                $final_list = [];
                foreach($__out as $value){ if(is_array($value)){ $final_list = array_merge($final_list, $value); } }
                $finalProduct = array_merge($finalProduct, $final_list);
            }
        }
        return $finalProduct;
    }

    public function nestedLoop($arr)
    {
        $output = [];
        foreach($arr as $k => $sub_item){
            if(is_array($sub_item) && !isset($sub_item['Name'])){
                $output = array_merge($output, $this->nestedLoop($sub_item));
            } elseif(is_array($sub_item) && isset($sub_item['Name'])){
                // REPLACED direct fast->select with Capsule
                $row = DB::connection('legacy')->table('override_attributes')
                    ->select(['volo_attribute','magento_attribute'])
                    ->where('volo_attribute', $sub_item['Name'])
                    ->limit(1)
                    ->first();
                $data1 = $row ? json_decode(json_encode($row), true) : [];
                if(isset($data1['volo_attribute'])){
                    $sitem_name = $data1['magento_attribute'];
                } else { $sitem_name = $sub_item['Name']; }
                $sitem_name1 = strtolower(preg_replace('/\B([A-Z])/', '_$1', (string) $sitem_name));
                $output = array_merge($output, [$sitem_name1 => ($sub_item['Value'] ?? null)]);
            }
        }
        return $output;
    }

    public function validateSet($key, $val)
    {
        if (is_array($val)) { return isset($val[$key]) ? $val[$key] : ''; }
        return $val;
    }

    public function pushData($url, $postData)
    {
        @set_time_limit(0);
        $fields = (is_array($postData)) ? http_build_query($postData) : $postData;
        $ch = curl_init();
        $headers = [];
        $headers[] = 'Content-length:' . strlen((string)$fields);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, is_array($postData) ? count($postData) : 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 400);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 40000);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        if ($result === false) { echo 'Curl error: ' . curl_error($ch); }
        curl_close($ch);
        return $result;
    }
}
