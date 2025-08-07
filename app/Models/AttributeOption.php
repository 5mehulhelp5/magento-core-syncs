<?php
namespace MagentoSync\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeOption extends Model {
    protected $table = 'eav_attribute_option';
    protected $primaryKey = 'option_id';
    public $timestamps = false;
}