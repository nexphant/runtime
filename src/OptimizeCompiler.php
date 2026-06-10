<?php

namespace Nexph\Runtime;

class OptimizeCompiler
{
    private string $compiledPath;

    public function __construct(string $storagePath)
    {
        $this->compiledPath = $storagePath . '/nexph/compiled';
    }

    public function compile(): void
    {
        if (!is_dir($this->compiledPath)) {
            mkdir($this->compiledPath, 0755, true);
        }

        $this->compileRoutes();
        $this->compileMiddleware();
        $this->compileContainer();
        $this->compileConfig();
        $this->compileSchedule();
        $this->compilePreload();
    }

    private function compileRoutes(): void
    {
        file_put_contents(
            $this->compiledPath . '/routes.php',
            "<?php\nreturn [];\n"
        );
    }

    private function compileMiddleware(): void
    {
        file_put_contents(
            $this->compiledPath . '/middleware.php',
            "<?php\nreturn [];\n"
        );
    }

    private function compileContainer(): void
    {
        file_put_contents(
            $this->compiledPath . '/container.php',
            "<?php\nreturn [];\n"
        );
    }

    private function compileConfig(): void
    {
        file_put_contents(
            $this->compiledPath . '/config.php',
            "<?php\nreturn [];\n"
        );
    }

    private function compileSchedule(): void
    {
        file_put_contents(
            $this->compiledPath . '/schedule.php',
            "<?php\nreturn [];\n"
        );
    }

    private function compilePreload(): void
    {
        file_put_contents(
            $this->compiledPath . '/preload.php',
            "<?php\nreturn [\n    \\Nexph\\Runtime\\Runtime::class,\n    \\Nexph\\Runtime\\Channel::class,\n    \\Nexph\\Lifecycle\\Lifecycle::class,\n];\n"
        );
    }
}
