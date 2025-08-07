<?php
namespace MagentoSync\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model {
    protected $table = 'catalog_product_entity';
    protected $primaryKey = 'entity_id';
    public $timestamps = false;
    protected $fillable = ['sku', 'type_id', 'attribute_set_id'];
}