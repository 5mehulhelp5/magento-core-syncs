<?php
namespace MagentoSync\Services;

class CsvService extends SystemService
{
    public function csvToArray(string $filename, string $delimiter = ',')
    {
        if (!is_file($filename) || !is_readable($filename)) return false;

        $header = null;
        $data = [];
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (!$header) {
                    $header = array_map(function ($v) {
                        $v = str_replace([' ', '\'', '"'], ["_", '', ''], $v);
                        return $v;
                    }, $row);
                } else {
                    if (count($header) !== count($row)) continue;
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }
        return $data;
    }
}
