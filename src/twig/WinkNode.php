<?php

namespace justinholtweb\wink\twig;

use Twig\Compiler;
use Twig\Node\CaptureNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;

/**
 * Compiled node for the {% experiment %} tag.
 *
 * Generates PHP that, for a server-side experiment:
 * 1. Gets the assigned variant for the visitor
 * 2. Renders only the matching variant block
 * 3. Wraps output in a data attribute div for auto-tracking
 *
 * and for a cache-safe one renders every variant block and hands them to
 * DeliveryService::renderCacheSafe(), which chooses nothing — the browser does.
 *
 * Each node compiles its own variable names, so an experiment nested in another's variant (a
 * page-builder block inside a page-builder block) can't overwrite the outer one's state.
 */
class WinkNode extends Node
{
    private array $variantBodies;

    /**
     * @param AbstractExpression $experimentHandle Evaluated at render time and cast to a string.
     * @param array<string, Node> $variantBodies
     */
    public function __construct(AbstractExpression $experimentHandle, array $variantBodies, int $lineno, string $tag)
    {
        $this->variantBodies = $variantBodies;

        parent::__construct(['handle' => $experimentHandle, 'variants' => new Node($variantBodies)], [], $lineno, $tag);
    }

    public function compile(Compiler $compiler): void
    {
        $id = $compiler->getVarName();
        $experiment = '$_winkExperiment' . $id;
        $bodies = '$_winkBodies' . $id;
        $visitorId = '$_winkVisitorId' . $id;
        $variant = '$_winkVariant' . $id;

        $compiler
            ->addDebugInfo($this)
            ->write('$_winkPlugin = \\justinholtweb\\wink\\Plugin::getInstance();' . "\n")
            ->write($experiment . ' = $_winkPlugin->experiments->getRunningExperiment((string)(')
            ->subcompile($this->getNode('handle'))
            ->raw('));' . "\n")
            ->write('if (' . $experiment . ' && $_winkPlugin->delivery->isCacheSafe(' . $experiment . ')) {' . "\n")
            ->indent()
            ->write($bodies . ' = [];' . "\n");

        foreach ($this->variantBodies as $handle => $body) {
            $capture = new CaptureNode($body, $body->getTemplateLine());
            $capture->setAttribute('raw', true);
            $compiler
                ->write($bodies . '[' . var_export((string)$handle, true) . '] = ')
                ->subcompile($capture)
                ->raw("\n");
        }

        $compiler
            ->write('echo $_winkPlugin->delivery->renderCacheSafe(' . $experiment . ', ' . $bodies . ');' . "\n")
            ->outdent()
            ->write('} elseif (' . $experiment . ') {' . "\n")
            ->indent()
            // A visitor who hasn't consented (when consent is required) gets the control, no cookie
            // and no impression.
            ->write($visitorId . ' = $_winkPlugin->assignment->hasConsent() ? $_winkPlugin->assignment->getVisitorId() : null;' . "\n")
            ->write($variant . ' = ' . $visitorId . ' !== null ? $_winkPlugin->assignment->assignVariant(' . $visitorId . ', ' . $experiment . ') : $_winkPlugin->assignment->getFallbackVariant(' . $experiment . ');' . "\n")
            ->write('if (' . $variant . ') {' . "\n")
            ->indent()
            ->write('if (' . $visitorId . ' !== null) {' . "\n")
            ->indent()
            ->write('$_winkPlugin->tracking->recordImpression(' . $experiment . '->id, ' . $variant . '->id, ' . $visitorId . ');' . "\n")
            ->outdent()
            ->write('}' . "\n")
            ->write('echo \'<div data-wink-experiment="\' . htmlspecialchars(' . $experiment . '->handle) . \'" data-wink-variant="\' . htmlspecialchars(' . $variant . '->handle) . \'">\';' . "\n");

        // Generate if/elseif chain for each variant handle
        $first = true;
        foreach ($this->variantBodies as $handle => $body) {
            if ($first) {
                $compiler->write('if (' . $variant . '->handle === ' . var_export((string)$handle, true) . ') {' . "\n");
                $first = false;
            } else {
                $compiler->write('} elseif (' . $variant . '->handle === ' . var_export((string)$handle, true) . ') {' . "\n");
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
