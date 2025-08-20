<?php
namespace MagentoSync\Services;

abstract class SystemService
{
    protected string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        // project root = app/.. → normalize to trailing slash
        $root = $baseDir ?: realpath(__DIR__ . '/..' . '/..'); // app/Services/../.. => project
        $this->baseDir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }
}
