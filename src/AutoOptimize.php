<?php

namespace Nexphant\Runtime;

class AutoOptimize
{
    private OptimizeManifest $manifest;
    private OptimizeCompiler $compiler;
    private string $storagePath;

    public function __construct(string $storagePath, ?string $basePath = null)
    {
        $this->storagePath = $storagePath;
        $this->manifest = new OptimizeManifest($storagePath);
        $this->compiler = new OptimizeCompiler($storagePath, $basePath);
    }

    public function boot(array $files = []): void
    {
        $files = $files !== [] ? $files : $this->compiler->sourceFiles();
        $this->manifest->load();

        if ($this->manifest->isStale($files)) {
            $this->compiler->compile($files);
            $this->manifest->save($files);
        }
        
        CompiledHotPath::load($this->storagePath . '/nexphant/compiled');
    }

    public function clear(): void
    {
        $compiled = $this->storagePath . '/nexphant/compiled';
        
        if (is_dir($compiled)) {
            foreach (glob("$compiled/*.php") ?: [] as $file) {
                @unlink($file);
            }
        }
    }
}
