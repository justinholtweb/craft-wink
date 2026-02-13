<?php

namespace jholt\wink\twig;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Compiled node for the {% experiment %} tag.
 *
 * Generates PHP that:
 * 1. Gets the assigned variant for the visitor
 * 2. Renders only the matching variant block
 * 3. Wraps output in a data attribute div for auto-tracking
 */
class WinkNode extends Node
{
    private string $experimentHandle;
    private array $variantBodies;

    /**
     * @param array<string, Node> $variantBodies
     */
    public function __construct(string $experimentHandle, array $variantBodies, int $lineno, string $tag)
    {
        $this->experimentHandle = $experimentHandle;
        $this->variantBodies = $variantBodies;

        parent::__construct(['variants' => new Node($variantBodies)], [], $lineno, $tag);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$_winkPlugin = \\jholt\\wink\\Plugin::getInstance();' . "\n")
            ->write('$_winkExperiment = $_winkPlugin->experiments->getRunningExperiment(' . var_export($this->experimentHandle, true) . ');' . "\n")
            ->write('if ($_winkExperiment) {' . "\n")
            ->indent()
            ->write('$_winkVisitorId = $_winkPlugin->assignment->getVisitorId();' . "\n")
            ->write('$_winkVariant = $_winkPlugin->assignment->assignVariant($_winkVisitorId, $_winkExperiment);' . "\n")
            ->write('if ($_winkVariant) {' . "\n")
            ->indent()
            ->write('$_winkPlugin->tracking->recordImpression($_winkExperiment->id, $_winkVariant->id, $_winkVisitorId);' . "\n")
            ->write('echo \'<div data-wink-experiment="\' . htmlspecialchars(' . var_export($this->experimentHandle, true) . ') . \'" data-wink-variant="\' . htmlspecialchars($_winkVariant->handle) . \'">\';' . "\n");

        // Generate if/elseif chain for each variant handle
        $first = true;
        foreach ($this->variantBodies as $handle => $body) {
            if ($first) {
                $compiler->write('if ($_winkVariant->handle === ' . var_export($handle, true) . ') {' . "\n");
                $first = false;
            } else {
                $compiler->write('} elseif ($_winkVariant->handle === ' . var_export($handle, true) . ') {' . "\n");
            }
            $compiler->indent()->subcompile($body)->outdent();
        }

        if (!$first) {
            $compiler->write('}' . "\n");
        }

        $compiler
            ->write('echo \'</div>\';' . "\n")
            ->outdent()
            ->write('}' . "\n")
            ->outdent()
            ->write('}' . "\n");
    }
}
