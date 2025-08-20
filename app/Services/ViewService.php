<?php
namespace MagentoSync\Services;

class ViewService extends SystemService
{
    public function render(string $path, array $data = []): void
    {
        $file = $this->baseDir() . 'app' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . $path . '.phtml';
        if (!is_file($file)) {
            die("Unable to locate view <strong>{$path}</strong> in <strong>{$file}</strong>");
        }
        if ($data) extract($data, EXTR_OVERWRITE);
        require $file;
    }
}
