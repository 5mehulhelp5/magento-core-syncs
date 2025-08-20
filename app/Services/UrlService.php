<?php
namespace MagentoSync\Services;

class UrlService extends SystemService
{
    private ConfigService $config;

    public function __construct(?ConfigService $config = null, ?string $baseDir = null)
    {
        parent::__construct($baseDir);
        $this->config = $config ?? new ConfigService($this->baseDir());
    }

    public function getUrl(string $key = ''): string
    {
        $base = rtrim($this->config->baseUrl(), '/');
        $suffix = $this->config->urlSuffix();
        if ($key !== '') {
            return $base . '/' . ltrim($key, '/') . $suffix;
        }
        return $base . '/';
    }

    public function getBaseUrl(string $key = ''): string
    {
        return rtrim($this->config->baseUrl(), '/') . '/' . ltrim($key, '/');
    }

    public function getAdminUrl(string $key = ''): string
    {
        $admin = $this->config->adminPath();
        $base = rtrim($this->config->baseUrl(), '/');
        $suffix = $this->config->urlSuffix();
        $path = $admin . ($key !== '' ? '/' . ltrim($key, '/') : '');
        return $base . '/' . $path . $suffix;
    }
}
