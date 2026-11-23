<?php

declare(strict_types=1);

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Minimal PHP source inspector built on the tokenizer: which classes does a
 * file depend on (imports and fully-qualified names), and how is it declared?
 */
final readonly class SourceFile
{
    /**
     * @param  list<string>  $dependencies
     */
    private function __construct(
        public string $path,
        public string $code,
        public array $dependencies,
    ) {}

    public static function fromPath(string $path): self
    {
        return self::fromCode($path, (string) file_get_contents($path));
    }

    public static function fromCode(string $path, string $code): self
    {
        return new self($path, $code, self::dependenciesOf($code));
    }

    /**
     * @return list<self>
     */
    public static function in(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = self::fromPath($file->getPathname());
            }
        }

        usort($files, fn (self $a, self $b) => strcmp($a->path, $b->path));

        return $files;
    }

    public function declaresStrictTypes(): bool
    {
        return preg_match('/^<\?php\s+declare\(strict_types=1\);/', $this->code) === 1;
    }

    public function declaresClass(): bool
    {
        return preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+/m', $this->code) === 1;
    }

    public function isFinalOrAbstractClass(): bool
    {
        return preg_match('/^\s*(?:readonly\s+)?(?:final|abstract)\s+(?:readonly\s+)?class\s+\w+/m', $this->code) === 1;
    }

    /**
     * Dependencies outside every one of the allowed namespace prefixes.
     * Global classes (\DateTimeImmutable, \LogicException...) are always allowed.
     *
     * @param  list<string>  $allowedPrefixes
     * @return list<string>
     */
    public function dependenciesOutside(array $allowedPrefixes): array
    {
        return array_values(array_filter(
            $this->dependencies,
            static function (string $dependency) use ($allowedPrefixes): bool {
                if (! str_contains($dependency, '\\')) {
                    return false;
                }

                foreach ($allowedPrefixes as $prefix) {
                    if (str_starts_with($dependency, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * @return list<string>
     */
    private static function dependenciesOf(string $code): array
    {
        $tokens = token_get_all($code);
        $dependencies = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAME_FULLY_QUALIFIED) {
                $dependencies[] = ltrim($token[1], '\\');
            }

            // Top-level "use Foo\Bar;" imports (not closures' use, not trait use).
            if ($token[0] === T_USE && $depth === 0) {
                $next = self::nextMeaningfulToken($tokens, $index);

                if (is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $dependencies[] = ltrim($next[1], '\\');
                }
            }
        }

        return array_values(array_unique($dependencies));
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function nextMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
