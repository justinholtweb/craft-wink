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

        $this->assertStringContainsString('getRunningExperiment((string)("headline-test"))', $php);
        $this->assertStringContainsString('assignVariant($_winkVisitorId_v0, $_winkExperiment_v0)', $php);
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

        $this->assertStringContainsString("\$_winkVariant_v0->handle === 'control'", $php);
        $this->assertStringContainsString("\$_winkVariant_v0->handle === 'b'", $php);
        $this->assertStringContainsString("\$_winkVariant_v0->handle === 'c'", $php);
        // One opening `if` plus one `elseif` per additional variant. Note that
        // "elseif" also contains "if", so count the elseifs and subtract.
        $total = substr_count($php, 'if ($_winkVariant_v0->handle ===');
        $elseifs = substr_count($php, '} elseif ($_winkVariant_v0->handle ===');
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
        // Twig's own string compilation escapes it.
        $this->assertStringContainsString('getRunningExperiment((string)("it\\x27s-a-test"))', $php);
    }

    public function testEmptyExperimentBlockStillCompiles(): void
    {
        $php = $this->compile("{% experiment 'empty' %}{% endexperiment %}");

        $this->lint($php);
        $this->assertStringContainsString('getRunningExperiment((string)("empty"))', $php);
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

    /**
     * Cache-safe experiments render every variant and leave the choice to the browser, so that
     * branch must not touch the visitor: no cookie read, no assignment, no impression.
     */
    public function testCacheSafeBranchRendersEveryVariantWithoutTheVisitor(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'headline-test' %}
              {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
              {% variant 'variant-a' %}<h1>Discover</h1>{% endvariant %}
            {% endexperiment %}
            TWIG);

        $start = strpos($php, 'delivery->isCacheSafe($_winkExperiment_v0)');
        $end = strpos($php, '} elseif ($_winkExperiment_v0) {');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $branch = substr($php, $start, $end - $start);
        $this->assertStringContainsString("\$_winkBodies_v0['control'] = ", $branch);
        $this->assertStringContainsString("\$_winkBodies_v0['variant-a'] = ", $branch);
        $this->assertStringContainsString('renderCacheSafe($_winkExperiment_v0, $_winkBodies_v0)', $branch);
        $this->assertStringNotContainsString('getVisitorId', $branch);
        $this->assertStringNotContainsString('assignVariant', $branch);
        $this->assertStringNotContainsString('recordImpression', $branch);
    }

    /**
     * #7: the handle is an expression, so a page-builder block can pass its own field value.
     */
    public function testHandleCanBeAnExpression(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment block.experimentHandle %}
              {% variant 'control' %}A{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->lint($php);
        $this->assertMatchesRegularExpression('/getRunningExperiment\(\(string\)\(.*"experimentHandle"/', $php);
        // The wrapper names the experiment found, not the template's expression.
        $this->assertStringContainsString('htmlspecialchars($_winkExperiment_v0->handle)', $php);
    }

    public function testHandleCanBeAConcatenation(): void
    {
        $php = $this->compile("{% experiment 'hero-' ~ suffix %}{% variant 'control' %}A{% endvariant %}{% endexperiment %}");

        $this->lint($php);
        $this->assertStringContainsString('"hero-"', $php);
    }

    /**
     * An experiment in another's variant must keep its own state: with shared variable names the
     * inner block overwrote the outer one's experiment, so the outer block rendered as the inner.
     */
    public function testNestedExperimentsUseTheirOwnVariables(): void
    {
        $php = $this->compile(<<<'TWIG'
            {% experiment 'outer' %}
              {% variant 'control' %}
                {% experiment 'inner' %}{% variant 'control' %}I{% endvariant %}{% endexperiment %}
              {% endvariant %}
              {% variant 'b' %}B{% endvariant %}
            {% endexperiment %}
            TWIG);

        $this->lint($php);
        preg_match_all('/(\$_winkExperiment_\w+) = \$_winkPlugin->experiments->getRunningExperiment/', $php, $m);
        // The outer block, and the inner one twice: once in each of the outer's branches.
        $this->assertCount(3, $m[1]);
        $this->assertCount(3, array_unique($m[1]));
        foreach ($m[1] as $name) {
            $this->assertStringContainsString('renderCacheSafe(' . $name . ', ', $php);
        }
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
