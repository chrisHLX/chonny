<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * A page's own title and description for search and sharing (layouts.app falls back to the
     * site's when they are empty), as a Livewire page passes them through ->layout(). `ogImage` is a
     * picture for link previews (shown large); `schema` is one more schema.org item for the page.
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $ogImage = null,
        public array $schema = [],
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
