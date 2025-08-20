<?php
namespace MagentoSync\Services;

class ResponseService extends SystemService
{
    private UrlService $urls;

    public function __construct(?UrlService $urls = null, ?string $baseDir = null)
    {
        parent::__construct($baseDir);
        $this->urls = $urls ?? new UrlService(null, $this->baseDir());
    }

    public function redirect(string $action, string $mode = ''): void
    {
        $target = ($mode === 'Admin')
            ? $this->urls->getAdminUrl($action)
            : $this->urls->getUrl($action);

        header("Location: {$target}");
        exit;
    }
}
