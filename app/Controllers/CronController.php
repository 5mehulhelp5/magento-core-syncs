<?php
namespace MagentoSync\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use MagentoSync\Helpers\Voloorder;
use MagentoSync\Helpers\Orderupdate;

class CronController
{
    protected $db;
    protected ?Voloorder $voloorder = null;
    protected ?Orderupdate $orderupdate = null;

    public function __construct(
        ?Voloorder $voloorder = null,
        ?Orderupdate $orderupdate = null
    ) {
        // Use the secondary "legacy" connection set in bootstrap.php
        $this->db = DB::connection('legacy');

        // Instantiate helpers (no Core_Helper dependency)
        $this->voloorder   = $voloorder   ?? (class_exists(Voloorder::class)   ? new Voloorder()   : null);
        $this->orderupdate = $orderupdate ?? (class_exists(Orderupdate::class) ? new Orderupdate() : null);
    }

    /**
     * Handle incoming request from both CLI and web.
     * - CLI: php public/index.php cron        → run()
     * - CLI: php public/index.php cron run    → run()
     * - Web: /cron?force=1                    → run()
     */
    public function handle(float $starttime, array $segments = [])
    {
        $isCli = (php_sapi_name() === 'cli');
        $forced = isset($_GET['force']) && $_GET['force'] == '1';

        if (!$isCli && !$forced) {
            http_response_code(403);
            return "Forbidden: CLI only (append ?force=1 to override)\n";
        }

        // Default to 'run' if no action is specified
        $action = isset($segments[0]) && strlen($segments[0]) ? strtolower($segments[0]) : 'run';

        switch ($action) {
            case 'run':
            default:
                return $this->run();
        }
    }

    /**
     * Port of old Cron::run()
     * 1) Restart “error products” (reset recent SQL errors not URL-related)
     * 2) Process orders and fix order IDs
     * 3) Push order status updates
     */
    public function run($starttime = null)
    {
        $starttime = $starttime ?? microtime(true);
        // (1) Restart error products in last hour
        $recentThreshold = date("Y-m-d H:i:s", strtotime("-1 hour"));

        $rows = $this->db->table('products_extract')
            ->where('server_response', 'LIKE', '%SQL%')
            ->where('server_response', 'NOT LIKE', '%URL%')
            ->where('created_at', '>', $recentThreshold)
            ->whereNotIn('status', [0, 10])
            ->orderBy('products_extract.created_at', 'asc')
            ->limit(15)
            ->get();

        foreach ($rows as $row) {
            $this->db->table('products_extract')
                ->where('id', $row->id)
                ->update([
                    'status' => 0,
                    'server_response' => null,
                ]);
        }

        // (2) Orders pipeline
        if ($this->voloorder && method_exists($this->voloorder, 'processOrders')) {
            $this->voloorder->processOrders();
        }

        if ($this->voloorder && method_exists($this->voloorder, 'fixOrderIds')) {
            $this->voloorder->fixOrderIds(); // backup to reduce errors
        }

        // (3) Push order status updates
        if ($this->orderupdate && method_exists($this->orderupdate, 'pushOrderStatus')) {
            $this->orderupdate->pushOrderStatus();
        }
        $duration = round(microtime(true) - $starttime, 2);
        return "Cron run completed at " . date('Y-m-d H:i:s') .
            " (reset: " . count($rows) . " products)\n" .
            "Cron processed in {$duration}s\n"
            ;
    }
}
