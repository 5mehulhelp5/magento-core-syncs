<?php
namespace MagentoSync\Services;

class SessionService extends SystemService
{
    protected array $bucket = [];
    protected string $sessionId;

    public function __construct(?string $sessionId = null, ?string $baseDir = null)
    {
        parent::__construct($baseDir);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $host = $_SERVER['HTTP_HOST'] ?? (string) microtime(true);
        $this->sessionId = $sessionId ?: md5($host . '_session');

        if (isset($_SESSION[$this->sessionId])) {
            $decoded = json_decode((string) $_SESSION[$this->sessionId], true);
            $this->bucket = is_array($decoded) ? $decoded : [];
        } else {
            $_SESSION[$this->sessionId] = json_encode([]);
            $this->bucket = [];
        }
    }

    public function get(string $key)
    {
        return array_key_exists($key, $this->bucket) ? $this->bucket[$key] : null;
    }

    public function set(string $key, $value = null): void
    {
        if ($value === null) {
            unset($this->bucket[$key]);
        } else {
            $this->bucket[$key] = $value;
        }
        $_SESSION[$this->sessionId] = json_encode($this->bucket);
    }

    public function destroy(): void
    {
        unset($_SESSION[$this->sessionId]);
        $this->bucket = [];
    }

    public function getAsFlash(string $key)
    {
        $v = $this->get($key);
        $this->set($key, null);
        return $v;
    }
}
