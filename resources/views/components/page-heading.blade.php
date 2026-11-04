@props(['title', 'description' => null, 'eyebrow' => 'Workspace'])

<div {{ $attributes->class(['mb-8 flex flex-wrap items-start justify-between gap-4']) }}>
    <div>
        <p class="page-eyebrow">{{ $eyebrow }}</p>
        <h1 class="page-title">{{ $title }}</h1>
        @if ($description)<p class="page-description">{{ $description }}</p>@endif
    </div>
    @if ($slot->isNotEmpty())<div class="flex items-center gap-3">{{ $slot }}</div>@endif
</div>
