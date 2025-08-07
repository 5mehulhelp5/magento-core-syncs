<?php
namespace MagentoSync\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model {
    protected $table = 'cataloginventory_stock_item';
    protected $primaryKey = 'item_id';
    public $timestamps = false;
    protected $fillable = ['product_id', 'qty', 'is_in_stock'];
}