<?php

namespace justinholtweb\wink\twig;

use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Parses the {% experiment %} tag.
 *
 * {% experiment 'headline-test' %}
 *   {% variant 'control' %}<h1>Welcome</h1>{% endvariant %}
 *   {% variant 'variant-a' %}<h1>Discover</h1>{% endvariant %}
 * {% endexperiment %}
 */
class WinkTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'experiment';
    }

    public function parse(Token $token): WinkNode
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        // Parse experiment handle: {% experiment 'handle' %}
        $experimentHandle = $stream->expect(Token::STRING_TYPE)->getValue();
        $stream->expect(Token::BLOCK_END_TYPE);

        // Parse variant blocks
        $variants = [];
        while (true) {
            // Look for {% variant %} or {% endexperiment %}
            $token = $stream->next();

            if ($token->test(Token::BLOCK_START_TYPE)) {
                $nextToken = $stream->next();

                if ($nextToken->test(Token::NAME_TYPE, 'endexperiment')) {
                    $stream->expect(Token::BLOCK_END_TYPE);
                    break;
                }

                if ($nextToken->test(Token::NAME_TYPE, 'variant')) {
                    $variantHandle = $stream->expect(Token::STRING_TYPE)->getValue();
                    $stream->expect(Token::BLOCK_END_TYPE);

                    // Parse variant body until {% endvariant %}
                    $body = $this->parser->subparse(function (Token $token) {
                        return $token->test(Token::NAME_TYPE, 'endvariant');
                    }, true);

                    $stream->expect(Token::BLOCK_END_TYPE);

                    $variants[$variantHandle] = $body;
                }
            }
        }

        return new WinkNode($experimentHandle, $variants, $lineno, $this->getTag());
    }
}
