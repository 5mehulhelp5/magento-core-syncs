<?php

namespace MagentoSync\Models;

use Illuminate\Support\Facades\DB;

class FastModel
{
    private $_apiData = [];
    private $_attributeOptions = [];
    private $_attributes = [];
    private $_mapData = null;
    private $_alternativeMap = null;
    private $fieldsMap = null;
    private $_magattribute = null;
    private $db;

    public function __construct(){
        $this->db = DB::connection('legacy');
    }
    protected function conn()
    {
        return DB::connection('legacy');
    }

    protected function getRow($table, $columns = '*', array $where = [])
    {
        $query = $this->conn()->table($table)->select($columns);
        foreach ($where as $col => $val) {
            $query->where($col, $val);
        }
        $row = $query->first();
        return $row ? (array) $row : null;
    }

    public function getConfigData()
    {
        return $this->conn()->table('config')->get()->map(fn($r) => (array) $r)->toArray();
    }

    public function getAdminUrl($key = '', $store = 1)
    {
        $url = $this->getRow('config', 'value', ['key' => 'admin_url', 'store_id' => $store])['value'] ?? '';
        $system = new System();
        $admin_url = $system->getBaseUrl($url);
        $suffix = $system->getConfig('url_suffix');
        if (strlen($key)) {
            $admin_url .= '/' . $key . $suffix;
        }
        return $admin_url;
    }

    public function getLogin($login = [])
    {
        return $this->conn()->table('users')->where($login)->get()->map(fn($r) => (array) $r)->toArray();
    }

    public function getAPIConfigurations($key = '')
    {
        if (!count($this->_apiData)) {
            $this->_apiData = $this->conn()->table('config_api')->select('id', 'key', 'value')->get()->map(fn($r) => (array) $r)->toArray();
        }
        if (!strlen($key)) {
            return $this->_apiData;
        }
        foreach ($this->_apiData as $d) {
            if ($d['key'] == $key) {
                return $d['value'];
            }
        }
        return false;
    }

    public function setApiConfiguration($key, $value = "")
    {
        $previousData = $this->getRow('config_api', ['id', 'key', 'value'], ['key' => $key]);
        if (!$previousData) {
            $insertData = ['key' => $key];
            $insertData['value'] = is_array($value) ? json_encode($value) : $value;
            $this->conn()->table('config_api')->insert($insertData);
        } else {
            $updateData = [];
            $updateData['value'] = is_array($value) ? json_encode($value) : $value;
            $this->conn()->table('config_api')->where('id', (int) $previousData['id'])->update($updateData);
        }
        return true;
    }

    public function getSelectApiByKey($key, $value, $output = true)
    {
        $data = $this->getAPIConfigurations($key);
        $data = explode(",", $data);
        if (in_array($value, $data)) {
            if ($output) {
                echo " selected='selected'";
                return;
            }
            return true;
        }
        return false;
    }

    public function updateLastCheck($which, $time = false)
    {
        if (!in_array($which, ["volo_last_check", "magento_last_check", "magento_attribute_set_check"])) {
            return false;
        }
        if (!$time) {
            $time = date("Y-m-d H:i:s");
        }
        return $this->setApiConfiguration($which, $time);
    }

    public function getLastCheck($which)
    {
        $outputDate = $this->getAPIConfigurations($which);
        if (!$outputDate) {
            return " <strong>Never</strong>";
        }
        return date('l jS \of F Y h:i:s A', strtotime($outputDate));
    }

    public function getTotalForTable($table)
    {
        return $this->conn()->table($table)->count();
    }

    public function getVoloField($field = '', $group_name = 'System_Default_Attributes')
    {
        return $this->getRow('volo_fields', ["id", "attribute_name", "attribute_description", "exists_on_volo", "created_at", "last_checked"], [
            'attribute_name' => $field,
            'Group_Name' => $group_name
        ]);
    }

    public function addVoloField($data)
    {
        $this->conn()->table('volo_fields')->insert($data);
        return true;
    }

    public function updateVoloField($data, $id = 0)
    {
        $this->conn()->table('volo_fields')->where('id', (int) $id)->update($data);
        return true;
    }

    public function addUpdateVoloFields($fields)
    {
        $batch = round(microtime(true) * 1000);
        foreach ($fields as $field) {
            $testCase = $this->getVoloField($field['fieldName']);
            if (!$testCase) {
                $this->addVoloField([
                    "attribute_name" => $field['fieldName'],
                    "Display_Field" => $field['fieldName'],
                    "attribute_description" => $field['description'],
                    'last_checked' => date('Y-m-d H:i:s'),
                    'last_seen_in_batch' => $batch,
                    "exists_on_volo" => 1
                ]);
            } else {
                $this->updateVoloField([
                    "attribute_name" => $field['fieldName'],
                    "Display_Field" => $field['fieldName'],
                    "attribute_description" => $field['description'],
                    'last_checked' => date('Y-m-d H:i:s'),
                    'last_seen_in_batch' => $batch,
                    "exists_on_volo" => 1
                ], $testCase['id']);
            }
        }
        $this->conn()->table('volo_fields')
            ->where('last_seen_in_batch', '!=', $batch)
            ->where('Group_Name', 'System_Default_Attributes')
            ->update(["exists_on_volo" => 0]);
        return true;
    }

    public function addUpdateCustomVoloFields($fields)
    {
        $batch = round(microtime(true) * 1000);
        foreach ($fields as $field) {
            $testCase = $this->getVoloField($field['Name'], $field['Group_Name']);
            if (!$testCase) {
                $this->addVoloField([
                    "attribute_name" => $field['Name'],
                    "Group_Name" => $field['Group_Name'],
                    "Display_Field" => $field['Display_Field'],
                    "Type" => $field['Type'],
                    "Display_Order" => $field['Display_Order'],
                    "Visible" => $field['Visible'],
                    "Item_Specifics" => $field['Item_Specifics'],
                    "Default_Value" => $field['Default_Value'],
                    "Field_Definition" => $field['Field_Definition'],
                    'last_checked' => date('Y-m-d H:i:s'),
                    'last_seen_in_batch' => $batch,
                    "exists_on_volo" => 1
                ]);
            } else {
                $this->updateVoloField([
                    "attribute_name" => $field['Name'],
                    "Group_Name" => $field['Group_Name'],
                    "Display_Field" => $field['Display_Field'],
                    "Type" => $field['Type'],
                    "Display_Order" => $field['Display_Order'],
                    "Visible" => $field['Visible'],
                    "Item_Specifics" => $field['Item_Specifics'],
                    "Default_Value" => $field['Default_Value'],
                    "Field_Definition" => $field['Field_Definition'],
                    'last_checked' => date('Y-m-d H:i:s'),
                    'last_seen_in_batch' => $batch,
                    "exists_on_volo" => 1
                ], $testCase['id']);
            }
        }
        $this->conn()->table('volo_fields')
            ->where('last_seen_in_batch', '!=', $batch)
            ->where('Group_Name', '!=', 'System_Default_Attributes')
            ->update(["exists_on_volo" => 0]);
        return true;
    }

    // === All Magento attributes/options methods ===
    public function getMagentoAttribute($attribute = '')
    {
        if (strlen($attribute) > 0) {
            return $this->getRow('magento_attributes', "*", ["attribute_label" => $attribute]);
        }
        return $this->conn()->table('magento_attributes')
            ->where('exists_on_magento', 1)
            ->orderBy('attribute_label', 'ASC')
            ->get()->map(fn($r) => (array) $r)->toArray();
    }

    public function addMagentoAttribute($data)
    {
        $this->conn()->table('magento_attributes')->insert($data);
        return true;
    }

    public function updateMagentoAttribute($data, $id = 0)
    {
        $this->conn()->table('magento_attributes')->where('id', (int) $id)->update($data);
        return true;
    }

    public function addMagentoOptions($attribute_id, $attribute_type, $attribute_code, $attribute_value, $attribute_label, $batch): bool
    {
        $this->conn()->table('magento_dropdown_attributes')->insert([
            'attribute_id' => $attribute_id,
            'attribute_type' => $attribute_type,
            'attribute_code' => $attribute_code,
            'option_value' => trim($attribute_value),
            'option_label' => trim($attribute_label),
            'batch' => $batch,
            'status' => 1
        ]);
        return true;
    }

    public function updateMagentoOptions($attribute_id, $attribute_type, $attribute_code, $attribute_value, $attribute_label, $batch, $id = 0): bool
    {
        $this->conn()->table('magento_dropdown_attributes')->where('id', (int) $id)->update([
            'attribute_id' => $attribute_id,
            'attribute_type' => $attribute_type,
            'attribute_code' => $attribute_code,
            'option_value' => trim($attribute_value),
            'option_label' => trim($attribute_label),
            'batch' => $batch,
            'status' => 1
        ]);
        return true;
    }

    public function getMagentoOption($attribute_code, $attribute_label)
    {
        return $this->getRow('magento_dropdown_attributes', "*", [
            "attribute_code" => $attribute_code,
            'option_label' => $attribute_label
        ]);
    }

    public function getMagentoValue($attribute_code, $attributeValue)
    {
        if (!count($this->_attributes)) {
            $this->_attributes = $this->conn()->table('magento_attributes')->where('exists_on_magento', 1)->get()->map(fn($r) => (array) $r)->toArray();
        }
        $found = false;
        foreach ($this->_attributes as $a) {
            if ($a['attribute_code'] == $attribute_code && in_array($a['attribute_type'], ['text', 'textarea', 'date'])) {
                return $attributeValue;
            }
            if ($a['attribute_code'] == $attribute_code) {
                $found = true;
            }
        }
        if (!count($this->_attributeOptions)) {
            $this->_attributeOptions = $this->conn()->table('magento_dropdown_attributes')->where('status', 1)->get()->map(fn($r) => (array) $r)->toArray();
        }
        foreach ($this->_attributeOptions as $d) {
            if ($d['attribute_code'] == $attribute_code && $d['option_label'] == $attributeValue) {
                return $d['option_value'];
            }
        }
        if ($found) {
            $postdata = ["option" => ["label" => $attributeValue]];
            $restAction = "products/attributes/$attribute_code/options";
            $system = new System();
            $system->getHelper('magento');
            $option_value = $system->magento->postAction($restAction, $postdata);
            $option_value = json_decode($option_value['response'], true);
            $this->conn()->table('magento_dropdown_attributes')->insert([
                'attribute_code' => $attribute_code,
                'option_value' => $option_value,
                'option_label' => $attributeValue,
                'status' => 1
            ]);
            return $option_value;
        }
        return $attributeValue;
    }

    public function getVoloGroups($previousData = [], $reverse = false)
    {
        if (!is_array($previousData) && strlen($previousData) > 0) {
            $previousData = json_decode($previousData, true);
        }
        if (!count($previousData)) {
            return $this->db->table('volo_fields')
                ->select('id', 'Group_Name', 'Display_Field', 'Type')
                ->where('exists_on_volo', 1)
                ->where('Visible', 'yes')
                ->where('Field_Definition', 'Product')
                ->orderBy('Group_Name')
                ->orderBy('attribute_name')
                ->groupBy('Group_Name')
                ->get()
                ->toArray();
        }
        if ($reverse) {
            return $this->db->table('volo_fields')
                ->select('id', 'Group_Name', 'Display_Field', 'Type')
                ->where('exists_on_volo', 1)
                ->where('Visible', 'yes')
                ->where('Field_Definition', 'Product')
                ->whereIn('Group_Name', $previousData)
                ->orderBy('Group_Name')
                ->orderBy('attribute_name')
                ->groupBy('Group_Name')
                ->get()
                ->toArray();
        }
        return $this->db->table('volo_fields')
            ->select('id', 'Group_Name', 'Display_Field', 'Type')
            ->where('exists_on_volo', 1)
            ->where('Visible', 'yes')
            ->where('Field_Definition', 'Product')
            ->whereNotIn('Group_Name', $previousData)
            ->orderBy('Group_Name')
            ->orderBy('attribute_name')
            ->groupBy('Group_Name')
            ->get()
            ->toArray();
    }

    public function getVoloFields($previousData = [], $reverse = false)
    {
        $groups = $this->getAPIConfigurations('volo_groups_to_use');
        if ($groups && count($groups) > 0) {
            $groups = json_decode($groups, true);
        } else {
            $groups = [];
        }
        if (!is_array($previousData) && strlen($previousData) > 0) {
            $previousData = json_decode($previousData, true);
        }
        if (!count($previousData)) {
            return $this->db->table('volo_fields')
                ->select('id', 'Group_Name', 'Display_Field', 'Type')
                ->where('exists_on_volo', 1)
                ->where('Visible', 'yes')
                ->where('Field_Definition', 'Product')
                ->whereIn('Group_Name', $groups)
                ->orderBy('Group_Name')
                ->orderBy('attribute_name')
                ->get()
                ->toArray();
        }
        if ($reverse) {
            return $this->db->table('volo_fields')
                ->select('id', 'Group_Name', 'Display_Field', 'Type')
                ->where('exists_on_volo', 1)
                ->where('Visible', 'yes')
                ->where('Field_Definition', 'Product')
                ->whereIn('Group_Name', $groups)
                ->whereIn('id', $previousData)
                ->orderBy('Group_Name')
                ->orderBy('attribute_name')
                ->get()
                ->toArray();
        }
        return $this->db->table('volo_fields')
            ->select('id', 'Group_Name', 'Display_Field', 'Type')
            ->where('exists_on_volo', 1)
            ->where('Visible', 'yes')
            ->where('Field_Definition', 'Product')
            ->whereIn('Group_Name', $groups)
            ->whereNotIn('id', $previousData)
            ->orderBy('Group_Name')
            ->orderBy('attribute_name')
            ->get()
            ->toArray();
    }

    public function getMappedFields($field)
    {
        if (!$this->fieldsMap) {
            $set = [];
            $data = $this->db->table('field_mapping')
                ->select('magento_attribute_code', 'volo_field_label')
                ->get()
                ->toArray();
            foreach ($data as $val) {
                $set[$val->volo_field_label] = $val->magento_attribute_code;
            }
            $this->fieldsMap = $set;
        }
        return $this->fieldsMap[$field] ?? '';
    }

    public function addMapField($postData)
    {
        if (isset($postData['id']) && count($postData['id']) > 0) {
            foreach ($postData['id'] as $attribute_key => $attribtue_value) {
                if (is_array($attribtue_value)) {
                    foreach ($attribtue_value as $attribute_label => $saveValue) {
                        $val = $this->db->table('field_mapping')
                            ->where('volo_field', $attribute_key)
                            ->where('volo_field_label', $attribute_label)
                            ->first();
                        if (!$val && strlen($saveValue) == 0) {
                            continue;
                        } elseif ($val) {
                            $this->db->table('field_mapping')
                                ->where('id', $val->id)
                                ->update([
                                    'volo_field' => $attribute_key,
                                    'volo_field_label' => $attribute_label,
                                    'magento_attribute_code' => $saveValue
                                ]);
                        } else {
                            $this->db->table('field_mapping')
                                ->insert([
                                    'volo_field' => $attribute_key,
                                    'volo_field_label' => $attribute_label,
                                    'magento_attribute_code' => $saveValue
                                ]);
                        }
                    }
                }
            }
        }
        return true;
    }

    public function mapData($voloName, $voloGroup, $output)
    {
        if (is_null($this->_mapData)) {
            $this->_mapData = $this->db->table('field_mapping')
                ->join('volo_fields', 'field_mapping.volo_field', '=', 'volo_fields.id')
                ->select('field_mapping.magento_attribute_code', 'volo_fields.Group_Name', 'volo_fields.Display_Field', 'volo_fields.id')
                ->get()
                ->toArray();
        }
        $out = [];
        foreach ($this->_mapData as $d) {
            $name = str_replace(" ", "", $d->Display_Field);
            if ($d->Group_Name == $voloGroup && $voloName == $name) {
                $out[$d->magento_attribute_code] = $output;
            }
        }
        return $out;
    }

    public function autoMaping($voloName, $output)
    {
        if (is_null($this->_alternativeMap)) {
            $this->_alternativeMap = $this->db->table('magento_attributes')
                ->where('exists_on_magento', 1)
                ->get()
                ->toArray();
        }
        $out = [];
        foreach ($this->_alternativeMap as $d) {
            if ($d->attribute_label == $voloName) {
                $out[$d->attribute_code] = $output;
            }
        }
        return $out;
    }

    public function getMagentoAttributeByLabel($voloName)
    {
        if (is_null($this->_alternativeMap)) {
            $this->_alternativeMap = $this->db->table('magento_attributes')
                ->where('exists_on_magento', 1)
                ->get()
                ->toArray();
        }
        foreach ($this->_alternativeMap as $d) {
            if ($d->attribute_label == $voloName) {
                return $d->attribute_code;
            }
        }
        return '';
    }

    public function addOrderMapField($postData)
    {
        if (!is_array($postData) || count($postData) == 0) {
            return false;
        }
        foreach ($postData as $volo_attr => $magento_attr) {
            $val = $this->db->table('magento_order_mapp')
                ->where('volo_attribute', $volo_attr)
                ->first();
            if (!$val && strlen($magento_attr) == 0) {
                continue;
            } elseif ($val) {
                $this->db->table('magento_order_mapp')
                    ->where('id', $val->id)
                    ->update([
                        'volo_attribute' => $volo_attr,
                        'magento_attribute' => $magento_attr
                    ]);
            } else {
                $this->db->table('magento_order_mapp')
                    ->insert([
                        'volo_attribute' => $volo_attr,
                        'magento_attribute' => $magento_attr
                    ]);
            }
        }
        return true;
    }

    public function getOrderAttribute($attribute)
    {
        if (is_null($this->_magattribute)) {
            $this->_magattribute = $this->db->table('magento_order_mapp')
                ->get()
                ->toArray();
        }
        foreach ($this->_magattribute as $d) {
            if ($attribute == $d->volo_attribute) {
                return $d->magento_attribute;
            }
        }
        return false;
    }

    public function translateTotalAttributeSet()
    {
        $data = $this->getAPIConfigurations('attribute_set_json');
        if (!is_null($data) && strlen($data)) {
            $data = json_decode($data, true);
            return count($data);
        }
        return 0;
    }

    // Insert a webhook row into data_by_webhooks
    public function addWebHookData($data)
    {
        // $data is an associative array column => value
        $this->conn()->table('data_by_webhooks')->insert($data);
        return true;
    }

    public function addUpdateMagentoAttributes($attributes)
    {
        $batch = round(microtime(true) * 1000);
        foreach ($attributes as $attribute) {
            $label = $attribute['default_frontend_label'] ?? $attribute['frontend_label'] ?? '';
            if ($label === '') continue;

            // does attribute exist by label?
            $existing = $this->getMagentoAttribute($label); // you already have this method
            $payload = [
                "attribute_label" => $label,
                "attribute_code"  => $attribute['attribute_code'] ?? '',
                "attribute_type"  => $attribute['frontend_input'] ?? '',
                "last_checked"    => date('Y-m-d H:i:s'),
                "last_seen_in_batch" => $batch,
                "exists_on_magento"  => 1,
            ];

            if (!$existing) {
                $this->addMagentoAttribute($payload);
            } else {
                $this->updateMagentoAttribute($payload, $existing['id']);
            }

            // Options (if any)
            if (!empty($attribute['options']) && is_array($attribute['options'])) {
                // find attribute id again (inserted or existing)
                $attRow = $this->getMagentoAttribute($label);
                $attId  = $attRow['id'] ?? null;
                $attType = $attribute['frontend_input'] ?? '';
                $attCode = $attribute['attribute_code'] ?? '';

                if ($attId) {
                    foreach ($attribute['options'] as $_option) {
                        $optLabel = trim((string)($_option['label'] ?? ''));
                        if ($optLabel === '') continue;

                        $existingOpt = $this->getMagentoOption($attCode, $optLabel);
                        $optVal = $_option['value'] ?? null;

                        if (!$existingOpt) {
                            $this->addMagentoOptions($attId, $attType, $attCode, $optVal, $optLabel, $batch);
                        } else {
                            $this->updateMagentoOptions($attId, $attType, $attCode, $optVal, $optLabel, $batch, $existingOpt['id']);
                        }
                    }
                }
            }
        }

        // Mark attributes/options not seen in this batch as inactive/removed
        $this->conn()->table('magento_attributes')
            ->where('last_seen_in_batch', '!=', $batch)
            ->update(['exists_on_magento' => 0]);

        $this->conn()->table('magento_dropdown_attributes')
            ->where('batch', '!=', $batch)
            ->update(['status' => 0]);

        return true;
    }

    /**
     * Insert into sku_to_90n if SKU not present.
     * Accepts one assoc array or a list of them.
     */
    public function addSkuTo90nData($data)
    {
        $rows = isset($data['sku']) ? [$data] : (array)$data;

        foreach ($rows as $item) {
            if (!isset($item['sku'])) continue;
            $sku = $item['sku'];

            $exists = $this->getRow('sku_to_90n', ['sku'], ['sku' => $sku]);
            if (!$exists) {
                $this->conn()->table('sku_to_90n')->insert($item);
            }
        }
        return true;
    }

    /**
     * Returns MPN for a given SKU (or the SKU itself if not found)
     */
    public function getMpnByStockNumber($stockNumber)
    {
        $row = $this->conn()->table('sku_to_90n')
            ->select('part_number')
            ->where('sku', $stockNumber)
            ->first();

        return $row ? (string)$row->part_number : $stockNumber;
    }

    /* ======================================================
 | Backward-compatible generic CRUD adapter methods
 |====================================================== */

// In app/Models/FastModel.php

    protected function applyWhereFromMedoo(\Illuminate\Database\Query\Builder $qb, array $where): \Illuminate\Database\Query\Builder
    {
        foreach ($where as $key => $val) {
            // skip specials (handled later)
            if (in_array($key, ['ORDER','LIMIT','OFFSET','GROUP','HAVING'], true)) {
                continue;
            }

            // Operators like created_at[>=], price[!] etc.
            if (preg_match('/^(.+)\[(>=|<=|>|<|!|~|!~)\]$/', $key, $m)) {
                [$all, $col, $op] = $m;
                switch ($op) {
                    case '>=': $qb->where($col, '>=', $val); break;
                    case '<=': $qb->where($col, '<=', $val); break;
                    case '>':  $qb->where($col, '>',  $val); break;
                    case '<':  $qb->where($col, '<',  $val); break;
                    case '!':  // NOT EQUAL or NOT IN
                        is_array($val) ? $qb->whereNotIn($col, $val) : $qb->where($col, '!=', $val);
                        break;
                    case '~':  // LIKE
                        $qb->where($col, 'like', $val);
                        break;
                    case '!~': // NOT LIKE
                        $qb->where($col, 'not like', $val);
                        break;
                }
                continue;
            }

            // Arrays become whereIn
            if (is_array($val)) {
                $qb->whereIn($key, $val);
            } else {
                $qb->where($key, $val);
            }
        }

        return $qb;
    }

    public function select(string $table, $columns = ['*'], array $where = [])
    {
        $qb = $this->conn()->table($table);

        // Normalize columns
        if (is_string($columns)) {
            $columns = $columns === '*' ? ['*'] : array_map('trim', explode(',', $columns));
        }
        $qb->select($columns);

        // WHERE (excluding special keys)
        $qb = $this->applyWhereFromMedoo($qb, $where);

        // ORDER
        if (isset($where['ORDER'])) {
            if (is_array($where['ORDER'])) {
                foreach ($where['ORDER'] as $col => $dir) {
                    $qb->orderBy($col, strtolower($dir) === 'desc' ? 'desc' : 'asc');
                }
            } elseif (is_string($where['ORDER'])) {
                $qb->orderBy($where['ORDER']);
            }
        }

        // GROUP
        if (isset($where['GROUP'])) {
            if (is_array($where['GROUP'])) {
                foreach ($where['GROUP'] as $g) { $qb->groupBy($g); }
            } else {
                $qb->groupBy($where['GROUP']);
            }
        }

        // HAVING (simple map support)
        if (isset($where['HAVING']) && is_array($where['HAVING'])) {
            foreach ($where['HAVING'] as $col => $val) {
                // support operators like col[>]
                if (preg_match('/^(.+)\[(>=|<=|>|<|!|~|!~)\]$/', $col, $m)) {
                    [$all, $c, $op] = $m;
                    switch ($op) {
                        case '>=': $qb->having($c, '>=', $val); break;
                        case '<=': $qb->having($c, '<=', $val); break;
                        case '>':  $qb->having($c, '>',  $val); break;
                        case '<':  $qb->having($c, '<',  $val); break;
                        case '!':  $qb->having($c, '!=', $val); break;
                        case '~':  $qb->having($c, 'like', $val); break;
                        case '!~': $qb->having($c, 'not like', $val); break;
                    }
                } else {
                    $qb->having($col, is_array($val) ? reset($val) : '=', is_array($val) ? end($val) : $val);
                }
            }
        }

        // LIMIT / OFFSET
        if (isset($where['LIMIT'])) {
            // Support [limit, offset] & integer forms
            if (is_array($where['LIMIT'])) {
                $limit  = (int) ($where['LIMIT'][0] ?? 0);
                $offset = (int) ($where['LIMIT'][1] ?? 0);
                if ($limit > 0) { $qb->limit($limit); }
                if ($offset > 0) { $qb->offset($offset); }
            } else {
                $limit = (int) $where['LIMIT'];
                if ($limit > 0) { $qb->limit($limit); }
            }
        }
        if (isset($where['OFFSET'])) {
            $qb->offset((int) $where['OFFSET']);
        }

        return $qb->get();
    }

    public function get(string $table, $columns = ['*'], array $where = [])
    {
        $query = $this->conn()->table($table)->select($columns);

        if (!empty($where)) {
            $query = $this->applyWhere($query, $where);
        }

        $row = $query->first();
        return $row ? (array) $row : [];
    }

    public function insert(string $table, array $data)
    {
        return $this->conn()->table($table)->insert($data);
    }

    public function update(string $table, array $data, array $where = [])
    {
        $query = $this->conn()->table($table);

        if (!empty($where)) {
            $query = $this->applyWhere($query, $where);
        }

        return $query->update($data);
    }

    public function delete(string $table, array $where = [])
    {
        $query = $this->conn()->table($table);

        if (!empty($where)) {
            $query = $this->applyWhere($query, $where);
        }

        return $query->delete();
    }

    public function count(string $table, array $where = [])
    {
        $query = $this->conn()->table($table);

        if (!empty($where)) {
            $query = $this->applyWhere($query, $where);
        }

        return $query->count();
    }

    /* ======================================================
     | Utility: translate legacy medoo-like conditions
     |====================================================== */
    protected function applyWhere($query, array $where)
    {
        foreach ($where as $column => $value) {
            if (is_array($value)) {
                // Support for IN conditions
                $query->whereIn($column, $value);
            } elseif (is_string($column) && preg_match('/(.+)\[~\]$/', $column, $m)) {
                // LIKE %value%
                $query->where($m[1], 'LIKE', '%' . $value . '%');
            } elseif (is_string($column) && preg_match('/(.+)\[!\~\]$/', $column, $m)) {
                // NOT LIKE %value%
                $query->where($m[1], 'NOT LIKE', '%' . $value . '%');
            } elseif (is_string($column) && preg_match('/(.+)\[!\]$/', $column, $m)) {
                // NOT IN (single or array)
                $query->whereNotIn($m[1], (array) $value);
            } elseif (is_string($column) && preg_match('/(.+)\[>\]$/', $column, $m)) {
                // Greater than
                $query->where($m[1], '>', $value);
            } elseif (is_string($column) && preg_match('/(.+)\[<\]$/', $column, $m)) {
                // Less than
                $query->where($m[1], '<', $value);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }
}