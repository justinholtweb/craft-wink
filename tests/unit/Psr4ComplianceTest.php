<?php

namespace justinholtweb\wink\tests\unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every class under src/ must live in the file PSR-4 expects, otherwise it is
 * only loadable by accident — when some other file that happens to declare it
 * has already been required. Such a class blows up with a fatal error the
 * moment it is referenced first.
 */
final class Psr4ComplianceTest extends TestCase
{
    private const NAMESPACE_PREFIX = 'justinholtweb\\wink\\';

    private static function srcPath(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /**
     * @return array<string, array{string, string}> class => [class, file]
     */
    public static function declaredClassProvider(): array
    {
        $cases = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::srcPath(), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (!preg_match('/^namespace\s+([^;]+);/m', $source, $nsMatch)) {
                continue;
            }
            $namespace = trim($nsMatch[1]);

            preg_match_all(
                '/^(?:final\s+|abstract\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
                $source,
                $typeMatches,
            );

            foreach ($typeMatches[1] as $shortName) {
                $fqcn = $namespace . '\\' . $shortName;
                $cases[$fqcn] = [$fqcn, $file->getPathname()];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider declaredClassProvider
     */
    public function testClassLivesInTheFilePsr4Expects(string $fqcn, string $file): void
    {
        $this->assertStringStartsWith(self::NAMESPACE_PREFIX, $fqcn);

        $relative = substr($fqcn, strlen(self::NAMESPACE_PREFIX));
        $expected = self::srcPath() . '/' . str_replace('\\', '/', $relative) . '.php';

        $this->assertSame(
            realpath($expected) ?: $expected,
            realpath($file),
            sprintf(
                '%s is declared in %s but PSR-4 resolves it to %s. Give it its own file.',
                $fqcn,
                basename($file),
                basename($expected),
            ),
        );
    }
}
