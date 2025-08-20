<?php
namespace MagentoSync\Services;

class ConfigService extends SystemService
{
    /** @var array<string,mixed> */
    private array $legacy = [];

    public function __construct(?string $baseDir = null)
    {
        parent::__construct($baseDir);
        $cfgPath = $this->baseDir() . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'legacy_database.php';
        $this->legacy = is_file($cfgPath) ? (include $cfgPath) : [];
    }

    public function get(string $dotKey, $default = null)
    {
        $ref = $this->legacy;
        foreach (explode('.', $dotKey) as $p) {
            if (!is_array($ref) || !array_key_exists($p, $ref)) return $default;
            $ref = $ref[$p];
        }
        return $ref;
    }

    public function adminPath(): string
    {
        $v = $this->get('app.admin_path', '') ?: (defined('ADMIN_PATH') ? ADMIN_PATH : '');
        $v = trim((string)$v, "/ \t");
        return $v !== '' ? $v : 'admin';
    }

    public function baseUrl(): string
    {
        // If you kept base_url/url_suffix in PHP config, surface them:
        return (string) ($this->get('app.base_url', '') ?: '');
    }

    public function urlSuffix(): string
    {
        return (string) ($this->get('app.url_suffix', '') ?: '');
    }
}
