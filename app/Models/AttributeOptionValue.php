<?php
namespace MagentoSync\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeOptionValue extends Model {
    protected $table = 'eav_attribute_option_value';
    protected $primaryKey = 'value_id';
    public $timestamps = false;
}