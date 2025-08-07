<?php
namespace MagentoSync\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model {
    protected $table = 'kiwicommerce_activity';
    protected $primaryKey = 'entity_id';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'username', 'name', 'admin_id', 'store_id', 'scope', 'action_type', 'remote_ip',
        'forwarded_ip', 'user_agent', 'module', 'fullaction', 'item_name', 'item_url',
        'is_revertable', 'revert_by'
    ];
}