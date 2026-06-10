<?php

namespace Nexph\Runtime;

class OptimizeManifest
{
    private string $manifestPath;
    private array $manifest = [];

    public function __construct(string $storagePath)
    {
        $this->manifestPath = $storagePath . '/nexph/compiled/manifest.php';
    }

    public function load(): void
    {
        if (file_exists($this->manifestPath)) {
            $this->manifest = require $this->manifestPath;
        }
    }

    public function isStale(array $files): bool
    {
        if (empty($this->manifest)) {
            return true;
        }

        $currentHash = $this->hashFiles($files);
        return ($this->manifest['hash'] ?? '') !== $currentHash;
    }

    public function save(array $files): void
    {
        $this->manifest = [
            'hash' => $this->hashFiles($files),
            'compiled_at' => time(),
            'files' => $files,
        ];

        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->manifestPath,
            '<?php return ' . var_export($this->manifest, true) . ';'
        );
    }

    private function hashFiles(array $files): string
    {
        $hash = '';
        foreach ($files as $file) {
            if (file_exists($file)) {
                $hash .= md5_file($file);
            }
        }
        return md5($hash);
    }
}
