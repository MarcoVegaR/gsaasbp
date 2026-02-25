<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use Composer\InstalledVersions;
use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;

final class GeneratedModuleLinter
{
    /**
     * @param  array<string, string>  $phpFiles
     * @param  array<string, string>  $tsFiles
     */
    public function lint(array $phpFiles, array $tsFiles): void
    {
        $this->assertParserCompatibility();

        foreach ($phpFiles as $path => $contents) {
            $this->assertForbiddenSnippets($path, $contents);
            $this->assertPhpAstInvariants($path, $contents);
        }

        foreach ($tsFiles as $path => $contents) {
            $this->assertForbiddenSnippets($path, $contents);
            $this->assertNoEagerGlobImports($path, $contents);
        }
    }

    public function assertParserCompatibility(): void
    {
        if (! class_exists(ParserFactory::class)) {
            throw new RuntimeException('nikic/php-parser is required for Phase 8 linting but is not installed.');
        }

        $version = InstalledVersions::getPrettyVersion('nikic/php-parser');

        if (! is_string($version) || ! str_starts_with($version, 'v5.')) {
            throw new RuntimeException(sprintf(
                'Unsupported nikic/php-parser version [%s]. Expected v5.x for PHP %s.',
                (string) $version,
                PHP_VERSION,
            ));
        }

    }

    private function assertForbiddenSnippets(string $path, string $contents): void
    {
        $forbiddenSnippets = [
            ...array_values((array) config('phase8.linter.forbidden_snippets', [])),
            ...array_values((array) config('phase8.linter.additional_forbidden_snippets', [])),
        ];

        foreach ($forbiddenSnippets as $snippet) {
            $candidate = trim((string) $snippet);

            if ($candidate === '') {
                continue;
            }

            if (str_contains($contents, $candidate)) {
                throw new InvalidArgumentException(sprintf(
                    'Generated file [%s] contains forbidden snippet [%s].',
                    $path,
                    $candidate,
                ));
            }
        }
    }

    private function assertPhpAstInvariants(string $path, string $contents): void
    {
        $parser = (new ParserFactory)->createForHostVersion();

        try {
            $ast = $parser->parse($contents);
        } catch (Error $error) {
            throw new InvalidArgumentException(sprintf(
                'Generated PHP file [%s] is not parseable: %s',
                $path,
                $error->getMessage(),
            ), previous: $error);
        }

        if (! is_array($ast)) {
            return;
        }

        $nodeFinder = new NodeFinder;

        $forbiddenStaticCall = $nodeFinder->findFirst($ast, static function (Node $node): bool {
            if (! $node instanceof StaticCall || ! $node->class instanceof Name || ! $node->name instanceof Identifier) {
                return false;
            }

            $className = strtolower($node->class->toString());
            $methodName = strtolower($node->name->toString());

            return $className === 'db' && in_array($methodName, ['select', 'table'], true);
        });

        if ($forbiddenStaticCall instanceof Node) {
            throw new InvalidArgumentException(sprintf(
                'Generated PHP file [%s] violates AST invariant: DB::select()/DB::table() is forbidden.',
                $path,
            ));
        }

        $forbiddenMethodCall = $nodeFinder->findFirst($ast, static function (Node $node): bool {
            if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
                return false;
            }

            return in_array($node->name->toString(), ['withoutGlobalScope', 'withoutGlobalScopes'], true);
        });

        if ($forbiddenMethodCall instanceof Node) {
            throw new InvalidArgumentException(sprintf(
                'Generated PHP file [%s] violates AST invariant: withoutGlobalScope(s) is forbidden.',
                $path,
            ));
        }
    }

    private function assertNoEagerGlobImports(string $path, string $contents): void
    {
        if (! str_contains($contents, 'import.meta.glob(')) {
            return;
        }

        if (preg_match('/import\.meta\.glob\([^\)]*eager\s*:\s*true/sm', $contents) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Generated frontend file [%s] uses eager import.meta.glob(), which is forbidden.',
                $path,
            ));
        }
    }
}
