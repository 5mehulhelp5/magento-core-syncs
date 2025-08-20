<?php
namespace MagentoSync\Services;

/**
 * Emulates legacy getHelper/getModel behavior.
 * 1) Try namespaced modern classes first:
 *    - Helper  => MagentoSync\Helpers\{UcfirstName}
 *    - Model   => MagentoSync\Models\{Name}  or MagentoSync\Models\Legacy\{Name}
 * 2) Fallback to legacy filesystem includes (app/Helper, app/Model)
 */
class LegacyLoaderService extends SystemService
{
    /** @var array<string,object> */
    private array $cache = [];

    public function getHelper(string $helper, string $newName = '')
    {
        $key = $newName ?: $helper;
        if (isset($this->cache[$key])) return $this->cache[$key];

        // Try namespaced modern class first
        $class = 'MagentoSync\\Helpers\\' . ucfirst($helper);
        if (class_exists($class)) {
            return $this->cache[$key] = new $class();
        }

        // Legacy filesystem fallback
        $file = $this->baseDir() . 'app' . DIRECTORY_SEPARATOR . 'Helper' . DIRECTORY_SEPARATOR . ucfirst($helper) . '.php';
        if (!is_file($file)) {
            die("Unable to locate helper {$helper}");
        }
        require_once $file;

        if (!class_exists($helper)) {
            // If legacy class name is ucfirst(helper) instead of helper
            $alt = ucfirst($helper);
            if (class_exists($alt)) {
                return $this->cache[$key] = new $alt();
            }
            die("Helper class {$helper} not found after including {$file}");
        }
        return $this->cache[$key] = new $helper();
    }

    public function getModel(string $model, string $newName = '')
    {
        $key = $newName ?: $model;
        if (isset($this->cache[$key])) return $this->cache[$key];

        // Modern namespaces (try both plain and Legacy sub-namespace)
        $candidates = [
            'MagentoSync\\Models\\' . $model,
            'MagentoSync\\Models\\Legacy\\' . $model,
        ];
        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $this->cache[$key] = new $class();
            }
        }

        // Legacy filesystem fallback
        $file = $this->baseDir() . 'app' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR . $model . '.php';
        if (!is_file($file)) {
            die("Unable to locate model {$model}");
        }
        require_once $file;

        if (!class_exists($model)) {
            die("Model class {$model} not found after including {$file}");
        }
        return $this->cache[$key] = new $model();
    }
}
