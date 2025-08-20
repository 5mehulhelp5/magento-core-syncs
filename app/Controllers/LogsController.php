<?php
namespace MagentoSync\Controllers;

use Illuminate\Support\Facades\DB;

class LogsController
{
    protected $db;

    public function __construct()
    {
        // legacy connection
        $this->db = DB::connection('legacy');
    }

    public function handle(float $starttime, array $segments = [])
    {
        // For LogsController:
        $action = $segments[0] ?? null;

        if ($action === 'sku') {
            $sku = $segments[1] ?? 'Test Product';
            return $this->sku($sku, $starttime);
        }

        if ($action === 'rawdata') {
            return $this->rawdata();
        }

        if ($action === 'query_data') {
            return $this->query_data();
        }

        $sku = $_GET['sku'] ?? 'Test Product';
        return $this->index($sku, $starttime);
    }



    public function index($sku = 'Test Product', $starttime = false)
    {
        if (is_bool($starttime)) {
            $starttime = microtime(true);
        }
        $sku = urldecode($sku);

        $data = $this->db->table('products_extract')
            ->where('sku', $sku)
            ->orderByDesc('products_extract.created_at')
            ->limit(50)
            ->get();

        echo <<<HTML
<style>
    body {
        font-family: "Segoe UI", Roboto, Arial, sans-serif;
        background-color: #f9fafb;
        color: #333;
        padding: 20px;
    }
    h2 {
        text-align: center;
        margin-bottom: 20px;
        color: #2c3e50;
    }
    table.log-table {
        width: 90%;
        margin: 0 auto;
        border-collapse: collapse;
        background-color: #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        border-radius: 6px;
        overflow: hidden;
    }
    table.log-table th,
    table.log-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
        vertical-align: top;
        font-size: 14px;
    }
    table.log-table th {
        background-color: #f3f4f6;
        font-weight: 600;
        color: #374151;
    }
    table.log-table tr:nth-child(even) {
        background-color: #f9fafb;
    }
    table.log-table tr:hover {
        background-color: #f1f5f9;
    }
    /* Column-specific widths & styling */
    table.log-table th:first-child,
    table.log-table td:first-child {
        width: 14%;
        min-width: 120px;
    }
    table.log-table th:nth-child(2),
    table.log-table td:nth-child(2) {
        width: 15%;
        min-width: 130px;
        background-color: #f8fafc;
        border-right: 1px solid #e5e7eb;
    }
    .scroll-cell {
        max-height: 120px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: break-word;
        background-color: #fdfdfd;
        padding: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        font-family: Consolas, monospace;
        font-size: 13px;
    }
    .timestamp {
        color: #6b7280;
        font-size: 13px;
        white-space: nowrap;
    }
    /* Badge styles */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 600;
        color: #fff;
    }
    .badge.product { background-color: #f97316; } /* orange */
    .badge.image   { background-color: #8b5cf6; } /* purple */
    .badge.stock   { background-color: #14b8a6; } /* teal */
</style>

<h2>Logs for SKU: {$sku}</h2>
<table class="log-table">
    <thead>
        <tr>
            <th>Request Type</th>
            <th>SKU</th>
            <th>Data Received</th>
            <th>Server Response</th>
            <th>Request Time</th>
        </tr>
    </thead>
    <tbody>
HTML;

        foreach ($data as $value) {
            $type = strtolower($value->update_type ?? '');
            $badgeClass = $type;
            $icon = '📄'; // default fallback

            if ($type === 'product') {
                $icon = '📦';
            } elseif ($type === 'image') {
                $icon = '🖼️';
            } elseif ($type === 'stock') {
                $icon = '📊';
            }

            echo "<tr>
            <td><span class='badge {$badgeClass}'>{$icon} {$value->update_type}</span></td>
            <td>{$value->sku}</td>
            <td class='scroll-cell'>" . htmlentities($value->data) . "</td>
            <td class='scroll-cell'>" . htmlentities($value->server_response) . "</td>
            <td class='timestamp'>" . date('F j, Y, g:i a', strtotime($value->created_at)) . "</td>
        </tr>";
        }

        echo <<<HTML
    </tbody>
</table>
HTML;

        $duration = round(microtime(true) - $starttime, 2);
        echo "<p style='text-align:right; font-size:13px; color:#6b7280'>Page processed in {$duration}s</p>";
    }




    public function sku(string $sku)
    {
        if (!$sku) {
            echo "Invalid SKU request.";
            return;
        }
        return $this->index($sku);
    }

    public function rawdata()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!count($_POST) || !isset($_POST['sku'])) {
            return;
        }
        $SKU = $_POST['sku'];
        $output = [];

        if (is_array($SKU)) {
            $quotedArray = array_map(fn($v) => "'$v'", $SKU);
            $skus = '(' . implode(', ', $quotedArray) . ')';

            $query = "
                WITH ranked_products AS (
                    SELECT *,
                        ROW_NUMBER() OVER (PARTITION BY sku ORDER BY created_at DESC) AS row_num
                    FROM products_extract
                    WHERE sku IN $skus
                      AND status = 1
                      AND update_type = 'product'
                )
                SELECT id, sku, data, status, created_at
                FROM ranked_products
                WHERE row_num = 1
            ";

            $data_out = $this->db->select($query);
            foreach ($data_out as $_product) {
                $data = (array) $_product;
                $output[$data['sku']] = isset($data['data']) ? json_decode($data['data'], true) : false;
                if (isset($output[$data['sku']]['description'])) {
                    unset($output[$data['sku']]['description']);
                }
            }
        }
        echo json_encode($output, true);
    }

    public function query_data()
    {
        $dateLimit = date('Y-m-d H:i:s', strtotime('-3 days'));

        $data = $this->db->table('products_extract')
            ->selectRaw('sku as StockNumber, update_type as UpdateType, data as Data, server_response as MagentoResponse, created_at as CreatedAt')
            ->where('products_extract.created_at', '>=', $dateLimit)
            ->where('status', 1)
            ->orderByDesc('products_extract.created_at')
            ->get();

        echo '<style>table{width:100%; max-width: 1600px; border-collapse:collapse;border:2px solid #ddd}td,th{padding:12px;text-align:left;border-bottom:1px solid #ddd; word-wrap: break-word;}th{background-color:#f2f2f2}tr:hover{background-color:#f5f5f5}</style>';
        echo '<table width="100%"> <thead> <tr> <th>StockNumber</th> <th>UpdateType</th> <th>Data</th> <th>MagentoResponse</th> <th>CreatedAt</th> </tr> </thead> <tbody>';
        foreach ($data as $_data) {
            echo "<tr>
                <td>{$_data->StockNumber}</td>
                <td>{$_data->UpdateType}</td>
                <td>{$_data->Data}</td>
                <td>{$_data->MagentoResponse}</td>
                <td>" . date("F j, Y, g:i a", strtotime($_data->CreatedAt)) . "</td>
            </tr>";
        }
        echo '</tbody></table>';
    }
}
