<?php
namespace MagentoSync\Services;

class TypeService extends SystemService
{
    public function revertType(int $typeId): string
    {
        $types = [
            0 => 'Hidden',
            1 => 'Textbox',
            2 => 'Checkbox',
            3 => 'List',
        ];
        return $types[$typeId] ?? 'Unknown';
    }
}
