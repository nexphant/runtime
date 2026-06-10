<?php

namespace Nexph\Runtime;

class OptimizeCompiler
{
    private string $compiledPath;
    private array $sourceFiles = [];
    private string $basePath;

    public function __construct(string $storagePath, ?string $basePath = null)
    {
        $this->compiledPath = $storagePath . '/nexph/compiled';
        $this->basePath = $basePath ?? dirname($storagePath);
    }

    public function compile(array $sourceFiles = []): void
    {
        if (!is_dir($this->compiledPath)) {
            mkdir($this->compiledPath, 0755, true);
        }

        $this->sourceFiles = $sourceFiles !== [] ? $sourceFiles : $this->sourceFiles();
        $this->compileRoutes();
        $this->compileMiddleware();
        $this->compileContainer();
        $this->compileConfig();
        $this->compileSchedule();
        $this->compilePreload();
    }

    private function compileRoutes(): void
    {
        $routes = [
            'fast' => [],
            'dynamic' => [],
            'compiled_at' => time(),
        ];

        foreach ($this->readSources() as $file => $code) {
            $this->collectRoutes($routes, $file, $code);
        }

        $this->writeArray('routes.php', $routes);
    }

    private function compileMiddleware(): void
    {
        $middleware = [];
        foreach ($this->readSources() as $file => $code) {
            if (preg_match_all('/->use\s*\((.*?)\)\s*;/s', $code, $matches)) {
                foreach ($matches[1] as $expr) {
                    $middleware[] = [
                        'file' => $file,
                        'expression' => trim($expr),
                    ];
                }
            }
        }
        $this->writeArray('middleware.php', $middleware);
    }

    private function compileContainer(): void
    {
        $this->writeArray('container.php', [
            'bindings' => [],
            'singletons' => [],
            'compiled_at' => time(),
        ]);
    }

    private function compileConfig(): void
    {
        $config = [];
        foreach ($this->readSources() as $file => $code) {
            if (preg_match('/App::create\s*\((\[[\s\S]*?\])\s*\)/', $code, $match)) {
                $config[] = [
                    'file' => $file,
                    'source' => trim($match[1]),
                    'value' => $this->literalArray($match[1]),
                ];
            }
        }
        $this->writeArray('config.php', $config);
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
        $this->writeArray('preload.php', [
            \Nexph\Runtime\Runtime::class,
            \Nexph\Runtime\Channel::class,
            \Nexph\Lifecycle\Lifecycle::class,
            \Nexph\Server\HttpServer::class,
            \Nexph\Server\Router::class,
        ]);
    }

    private function writeArray(string $file, array $data): void
    {
        file_put_contents(
            $this->compiledPath . '/' . $file,
            "<?php\nreturn " . var_export($data, true) . ";\n"
        );
    }

    public function sourceFiles(?string $basePath = null): array
    {
        $root = $basePath ?? $this->basePath;
        $files = [];
        foreach (glob($root . '/*.php') ?: [] as $file) {
            $files[] = $file;
        }
        foreach (['routes', 'config'] as $dir) {
            $this->collectPhpFiles($root . '/' . $dir, $files, 0);
        }
        sort($files);
        return $files;
    }

    private function collectPhpFiles(string $dir, array &$files, int $depth): void
    {
        if ($depth > 4 || !is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->collectPhpFiles($path, $files, $depth + 1);
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    }

    private function readSources(): array
    {
        $sources = [];
        foreach ($this->sourceFiles as $file) {
            if (is_file($file)) {
                $sources[$file] = $this->stripComments((string) file_get_contents($file));
            }
        }
        return $sources;
    }

    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    private function collectRoutes(array &$routes, string $file, string $code, string $prefix = ''): void
    {
        $groups = $this->groupBodies($code);
        $plain = $this->stripGroupBodies($code, $groups);
        $this->collectFastRoutes($routes, $file, $plain, $prefix);
        $this->collectDynamicRoutes($routes, $file, $plain, $prefix);

        foreach ($groups as $group) {
            $this->collectRoutes($routes, $file, $group['body'], $prefix . $group['prefix']);
        }
    }

    private function collectFastRoutes(array &$routes, string $file, string $code, string $prefix): void
    {
        $pattern = '/->fast(Json|Text|Raw)\s*\(\s*([\'"])([A-Z]+)\2\s*,\s*([\'"])(.*?)\4\s*,\s*([\s\S]*?)\)\s*;/';
        if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $kind = strtolower($match[1]);
            $args = $this->splitArgs($match[6]);
            $payload = $args[0] ?? '';
            $payloadValue = $this->literalValue($payload);
            $routes['fast'][] = [
                'method' => $match[3],
                'path' => $this->joinPath($prefix, $match[5]),
                'type' => $kind,
                'payload' => trim($payload),
                'payload_value' => $payloadValue,
                'status' => isset($args[1]) && is_numeric(trim($args[1])) ? (int) trim($args[1]) : 200,
                'file' => $file,
            ];
        }
    }

    private function collectDynamicRoutes(array &$routes, string $file, string $code, string $prefix): void
    {
        if (!preg_match_all('/->(get|post|put|patch|delete|options|any)\s*\(\s*([\'"])(.*?)\2/s', $code, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $routes['dynamic'][] = [
                'method' => strtoupper($match[1]),
                'path' => $this->joinPath($prefix, $match[3]),
                'file' => $file,
            ];
        }
    }

    private function groupBodies(string $code): array
    {
        $groups = [];
        if (!preg_match_all('/->group\s*\(\s*([\'"])(.*?)\1\s*,\s*function\s*\([^)]*\)\s*\{/s', $code, $matches, PREG_OFFSET_CAPTURE)) {
            return $groups;
        }

        foreach ($matches[0] as $i => $full) {
            $open = $full[1] + strlen($full[0]) - 1;
            $close = $this->findMatchingBrace($code, $open);
            if ($close === null) {
                continue;
            }
            $groups[] = [
                'prefix' => $matches[2][$i][0],
                'body' => substr($code, $open + 1, $close - $open - 1),
                'start' => $full[1],
                'end' => $close,
            ];
        }
        return $groups;
    }

    private function stripGroupBodies(string $code, array $groups): string
    {
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $group = $groups[$i];
            $code = substr($code, 0, $group['start']) . substr($code, $group['end'] + 1);
        }
        return $code;
    }

    private function findMatchingBrace(string $code, int $open): ?int
    {
        $depth = 0;
        $len = strlen($code);
        for ($i = $open; $i < $len; $i++) {
            $char = $code[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    private function splitArgs(string $args): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $len = strlen($args);
        for ($i = 0; $i < $len; $i++) {
            $char = $args[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote && ($i === 0 || $args[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === ']' || $char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }
        return $parts;
    }

    private function joinPath(string $prefix, string $path): string
    {
        $prefix = '/' . trim($prefix, '/');
        $path = '/' . trim($path, '/');
        $joined = rtrim($prefix, '/') . $path;
        return $joined === '' ? '/' : $joined;
    }

    private function literalArray(string $source): ?array
    {
        try {
            $value = $this->literalValue($source);
            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function literalValue(string $source): mixed
    {
        try {
            return eval('return ' . $source . ';');
        } catch (\Throwable) {
            return null;
        }
    }
}
