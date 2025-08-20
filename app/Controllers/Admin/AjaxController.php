<?php
namespace MagentoSync\Controllers\Admin;

use Illuminate\Database\Capsule\Manager as DB;
use MagentoSync\Helpers\Core as CoreHelper;
use MagentoSync\Models\FastModel;

class AjaxController
{
    protected CoreHelper $core;
    protected ?FastModel $fast = null;

    public function __construct(?CoreHelper $core = null, ?FastModel $fast = null)
    {
        // Instantiate helpers/models the legacy way (no framework DI required)
        $this->core = $core ?? new CoreHelper();

        // Optional: keep Fast_Model available for legacy calls that used it
        // (Index action below doesn't query DB, but we keep parity with the old controller.)
        $this->fast = $fast ?? (class_exists(FastModel::class) ? new FastModel() : null);

        // Legacy behavior: require an authenticated admin session; will redirect/exit if not
        // Matches: $this->core->validateLogin(true);
        $this->core->validateLogin(true);
    }

    /**
     * Unified entry point from router.
     *   /secret_admin/ajax            → handle($start, ['index'])
     *   /secret_admin/ajax/index      → handle($start, ['index'])
     */
    public function handle(float $starttime, array $segments = [])
    {
        $action = isset($segments[0]) && $segments[0] !== '' ? strtolower($segments[0]) : 'index';

        switch ($action) {
            case 'index':
            default:
                return $this->index();
        }
    }

    /**
     * Port of old Ajax::index()
     * - Keeps the POST behavior identical.
     * - Fast_Model is present (if you need it later), but not used here.
     */
    public function index()
    {
        // In the legacy controller: $this->getModel("Fast_Model", "fast");
        // We already instantiated $this->fast in __construct() for parity.

        // Original behavior preserved
        if (!empty($_POST['city'])) {
            $getcity = $_POST['city'];
            // Output exactly like the original
            echo "City: $getcity <br/>";
        } else {
            echo "<h2>Please enter all the values</h2>";
        }

        // Optionally return a string (router echoes it). Echoes above already sent output,
        // so we just return an empty string for consistency with other controllers.
        return '';
    }
}
