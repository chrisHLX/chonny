<x-app-layout>
    @foreach($pages as $page)
        <p class="mt-4">{!! $page->content !!}</p>
        <hr class="my-4 mt-4">
    @endforeach
</x-app-layout>