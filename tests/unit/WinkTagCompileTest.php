<?php

namespace justinholtweb\wink\tests\unit;

use justinholtweb\wink\twig\WinkNode;
use justinholtweb\wink\twig\WinkTokenParser;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\ArrayLoader;
use Twig\Source;

/**
 * The {% experiment %} tag compiles straight to raw PHP, so these tests assert
 * against the generated source rather than rendered output — no Craft app is
 * needed and compilation bugs surface directly.
 */
final class WinkTagCompileTest extends TestCase
{
    private const NAME = 'test-template';

    /**
     * A fresh environment per template — ArrayLoader must know the source by
     * name for Twig to resolve it during compilation.
     */
    private function makeTwig(string $template): Environment
    {
        $twig = new Environment(new ArrayLoader([self::NAME => $template]));
        $twig->addExtension(new class extends AbstractExtension {
            public function getTokenParsers(): array
            {
                return [new WinkTokenParser()];
            }
        });

        return $twig;
    }

    private function compile(string $template): string
    {
        return $this->makeTwig($template)->compileSource(new Source($template, self::NAME));
    }

    public function testTagParsesAndCompiles(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'headline-test' %}
              {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
              {% variant 'variant-a' %}<h1>Discover</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringContainsString('getRunningExperiment(\'headline-test\')', $php);
        $this->assertStringContainsString('assignVariant($_winkVisitorId, $_winkExperiment)', $php);
        $this->assertStringContainsString('recordImpression(', $php);
    }

    public function testCompiledOutputIsSyntacticallyValidPhp(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'headline-test' %}
              {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
              {% variant 'variant-a' %}<h1>Discover</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertNotFalse(
            // Throws ParseError on malformed output; a bare token_get_all won't catch
            // unbalanced braces, so lint via a real parse.
            $this->lint($php),
            'Compiled template must be valid PHP',
        );
    }

    private function lint(string $php): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'wink-lint-') . '.php';
        file_put_contents($file, $php);

        $output = [];
        $exitCode = 0;
        exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $exitCode);
        unlink($file);

        $this->assertSame(0, $exitCode, "PHP lint failed:\n" . implode("\n", $output) . "\n\nSource:\n" . $php);

        return true;
    }

    public function testEachVariantHandleBecomesABranch(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'pricing' %}
              {% variant 'control' %}A{% endvariant %}
              {% variant 'b' %}B{% endvariant %}
              {% variant 'c' %}C{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringContainsString("\$_winkVariant->handle === 'control'", $php);
        $this->assertStringContainsString("\$_winkVariant->handle === 'b'", $php);
        $this->assertStringContainsString("\$_winkVariant->handle === 'c'", $php);
        // One opening `if` plus one `elseif` per additional variant. Note that
        // "elseif" also contains "if", so count the elseifs and subtract.
        $total = substr_count($php, 'if ($_winkVariant->handle ===');
        $elseifs = substr_count($php, '} elseif ($_winkVariant->handle ===');
        $this->assertSame(2, $elseifs, 'Three variants should produce two elseif branches');
        $this->assertSame(1, $total - $elseifs, 'The chain should open with exactly one if');
    }

    public function testWrapperDivCarriesTrackingAttributes(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'headline-test' %}
              {% variant 'control' %}A{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->assertStringContainsString('data-wink-experiment=', $php);
        $this->assertStringContainsString('data-wink-variant=', $php);
    }

    public function testExperimentHandleIsEscapedIntoSource(): void
    {
        // A handle containing a quote must not break out of the generated PHP literal.
        $php = $this->compile("{% experiment 'it\\'s-a-test' %}{% variant 'control' %}A{% endvariant %}{% endexperiment %}");

        $this->lint($php);
        $this->assertStringContainsString("getRunningExperiment('it\\'s-a-test')", $php);
    }

    public function testEmptyExperimentBlockStillCompiles(): void
    {
        $php = $this->compile("{% experiment 'empty' %}{% endexperiment %}");

        $this->lint($php);
        $this->assertStringContainsString('getRunningExperiment(\'empty\')', $php);
    }

    public function testVariantBodiesSupportNestedTwig(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'nested' %}
              {% variant 'control' %}{% if true %}<p>{{ 'hi' }}</p>{% endif %}{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->lint($php);
        $this->assertStringContainsString('hi', $php);
    }

    public function testParserProducesAWinkNode(): void
    {
        $template = "{% experiment 'x' %}{% variant 'control' %}A{% endvariant %}{% endexperiment %}";
        $twig = $this->makeTwig($template);
        $node = $twig->parse($twig->tokenize(new Source($template, self::NAME)));

        $found = false;
        foreach ($node as $child) {
            foreach ($child as $grandchild) {
                if ($grandchild instanceof WinkNode) {
                    $found = true;
                }
            }
        }

        $this->assertTrue($found, 'The tag should produce a WinkNode in the compiled tree');
    }
}
