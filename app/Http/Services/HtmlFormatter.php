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

        $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        // Normalize: ensure there’s always a body
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            $body = $dom->createElement('body');

            // Move everything into body
            while ($dom->firstChild) {
                $body->appendChild($dom->firstChild);
            }
            $dom->appendChild($body);
        }

        // Ensure wrapper container
        $desiredClasses = 'container example-class';
        $wrapper = $dom->createElement('div');
        $wrapper->setAttribute('class', $desiredClasses);

        while ($body->firstChild) {
            $wrapper->appendChild($body->firstChild);
        }
        $body->appendChild($wrapper);

        // Apply classes to tags in classMap
        $this->applyClasses($dom);

        return $dom->saveHTML();
    }

    protected function applyClasses(DOMDocument $dom): void
    {
        foreach ($this->classMap as $tag => $classes) {
            $elements = $dom->getElementsByTagName($tag);
            foreach ($elements as $el) {
                /** @var \DOMElement $el */
                $existing = $el->getAttribute('class');
                $merged = trim($existing . ' ' . $classes);
                $el->setAttribute('class', $merged);
            }
        }
    }

}
