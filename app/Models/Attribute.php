<?php
namespace MagentoSync\Models;

use Illuminate\Database\Eloquent\Model;

class Attribute extends Model {
    protected $table = 'eav_attribute';
    protected $primaryKey = 'attribute_id';
    public $timestamps = false;
    protected $guarded = []; // Disable mass assignment protection
}