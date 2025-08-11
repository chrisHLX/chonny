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

        // Ensure UTF-8 handling
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Apply classes
        foreach ($this->classMap as $tag => $classes) {
            $elements = $dom->getElementsByTagName($tag);

            /** @var \DOMElement $el */
            foreach ($elements as $index => $el) {
                $existingClasses = $el->getAttribute('class');

                // Special case: first <div> gets container class
                if ($tag === 'div' && $index === 0 && $el->parentNode instanceof \DOMDocument) {
                    $el->setAttribute('class', trim($classes . ' ' . $existingClasses));
                } else {
                    $el->setAttribute('class', trim($existingClasses . ' ' . $classes));
                }
            }
        }

        return $dom->saveHTML();
    }
}
