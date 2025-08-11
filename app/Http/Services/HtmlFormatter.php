<?php

namespace App\Http\Services;

use DOMDocument;

class HtmlFormatter
{
    /**
     * Map of tags to Tailwind classes.
     * Adjust styles here to control output look.
     */
    protected array $classMap = [
        'div'    => 'max-w-4xl mx-auto p-6 bg-white shadow-lg rounded-lg',
        'h1'     => 'text-3xl font-bold mb-4',
        'h2'     => 'text-xl font-semibold mt-6 mb-2',
        'h3'     => 'text-lg font-semibold mt-4 mb-2',
        'p'      => 'mb-4 text-gray-700',
        'ul'     => 'list-disc list-inside mb-4',
        'ol'     => 'list-decimal list-inside mb-4',
        'li'     => 'mb-2',
        'strong' => 'font-semibold',
        'em'     => 'italic',
    ];

    public function format(string $html): string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $body = $dom->getElementsByTagName('body')->item(0);
        $desiredClasses = 'container example-class'; // replace with your desired wrapper classes

        if ($body) {
            // Get body child nodes
            $children = [];
            foreach ($body->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE || $child->nodeType === XML_TEXT_NODE) {
                    $children[] = $child;
                }
            }

            // Check if it's already a single div wrapper with the desired classes
            if (
                count($children) === 1 &&
                $children[0] instanceof DOMElement &&
                $children[0]->tagName === 'div' &&
                strpos($children[0]->getAttribute('class'), 'container') !== false
            ) {
                // Ensure correct classes
                $children[0]->setAttribute('class', $desiredClasses);
            } else {
                // Create new wrapper div
                $wrapper = $dom->createElement('div');
                $wrapper->setAttribute('class', $desiredClasses);

                // Move all children into new wrapper
                while ($body->firstChild) {
                    $wrapper->appendChild($body->firstChild);
                }

                $body->appendChild($wrapper);
            }
        }

        return $dom->saveHTML();
    }

}
