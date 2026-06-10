<?php

namespace Nexph\Runtime;

class AutoOptimize
{
    private OptimizeManifest $manifest;
    private OptimizeCompiler $compiler;
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = $storagePath;
        $this->manifest = new OptimizeManifest($storagePath);
        $this->compiler = new OptimizeCompiler($storagePath);
    }

    public function boot(array $files = []): void
    {
        $this->manifest->load();

        if ($this->manifest->isStale($files)) {
            $this->compiler->compile($files);
            $this->manifest->save($files);
        }
        
        CompiledHotPath::load($this->storagePath . '/nexph/compiled');
    }

    public function clear(): void
    {
        $compiled = $this->storagePath . '/nexph/compiled';
        
        if (is_dir($compiled)) {
            foreach (glob("$compiled/*.php") ?: [] as $file) {
                @unlink($file);
            }
        }
    }
}
