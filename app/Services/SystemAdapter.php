<?php
namespace MagentoSync\Services;

/**
 * SystemAdapter: a compatibility layer that offers the old System API,
 * backed by the new granular Services. Use this where legacy code is
 * still calling getHelper/getModel/getView/getUrl/redirect/etc.
 */
class SystemAdapter extends SystemService
{
    private LegacyLoaderService $loader;
    private UrlService $urls;
    private ResponseService $response;
    private ViewService $views;
    private ConfigService $config;

    public function __construct(
        ?LegacyLoaderService $loader = null,
        ?UrlService $urls = null,
        ?ResponseService $response = null,
        ?ViewService $views = null,
        ?ConfigService $config = null,
        ?string $baseDir = null
    ) {
        parent::__construct($baseDir);
        $this->loader   = $loader   ?? new LegacyLoaderService($this->baseDir());
        $this->urls     = $urls     ?? new UrlService(null, $this->baseDir());
        $this->response = $response ?? new ResponseService(null, $this->baseDir());
        $this->views    = $views    ?? new ViewService($this->baseDir());
        $this->config   = $config   ?? new ConfigService($this->baseDir());
    }

    // ---- Legacy API below ----

    public function getHelper(string $helper = "", string $newName = "")
    {
        return $this->loader->getHelper($helper, $newName);
    }

    public function getModel(string $model = "", string $newName = "")
    {
        return $this->loader->getModel($model, $newName);
    }

    public function getController(string $call, string $dir = '')
    {
        // Intentionally NOT re‑implemented: your new public/index.php handles routing.
        // Keeping a stub to signal migration: call should go through router now.
        die('getController() is deprecated: route via public/index.php');
    }

    public function getControllerByUrl(bool $shell = false)
    {
        die('getControllerByUrl() is deprecated: route via public/index.php');
    }

    public function getView(string $path, array $data = [])
    {
        return $this->views->render($path, $data);
    }

    public function getUrl(string $key = ''): string
    {
        return $this->urls->getUrl($key);
    }

    public function getBaseUrl(string $key = ''): string
    {
        return $this->urls->getBaseUrl($key);
    }

    public function getAdminUrl(string $key = ''): string
    {
        return $this->urls->getAdminUrl($key);
    }

    public function redirect(string $action, string $mode = '')
    {
        return $this->response->redirect($action, $mode);
    }

    public function getLibrary(string $requireFile = '')
    {
        $file = $this->baseDir() . 'app' . DIRECTORY_SEPARATOR . 'Lib' . DIRECTORY_SEPARATOR . $requireFile . '.php';
        if (!is_file($file)) {
            die("Unable to locate Library <strong>{$requireFile}</strong> in <strong>{$file}</strong>");
        }
        require_once $file;
    }

    public function csv_to_array(string $filename = '', string $delimiter = ',')
    {
        $csv = new CsvService($this->baseDir());
        return $csv->csvToArray($filename, $delimiter);
    }

    public function revertType(int $typeId)
    {
        $types = new TypeService($this->baseDir());
        return $types->revertType($typeId);
    }
}
