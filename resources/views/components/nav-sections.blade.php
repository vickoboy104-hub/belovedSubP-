@props(['sections', 'supportUrl'])

<a href="{{ route('dashboard') }}" class="reference-nav-link {{ request()->routeIs('dashboard') ? 'reference-nav-active' : '' }}">Dashboard</a>

@foreach($sections as $section)
    <div>
        <div class="reference-nav-heading">{{ $section['title'] }}</div>
        <div>
            @foreach($section['items'] as $item)
                <a href="{{ $item['route'] ? route($item['route']) : $supportUrl }}"
                   @unless($item['route']) target="_blank" rel="noopener noreferrer" @endunless
                   class="reference-nav-link {{ $item['route'] && request()->routeIs($item['route']) ? 'reference-nav-active' : '' }}">
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endforeach

@if((auth()->user()->is_admin ?? false) && Route::has('admin.dashboard'))
    <a href="{{ route('admin.dashboard') }}" class="nav-item">Admin Panel</a>
@endif

<form method="POST" action="{{ route('logout', absolute: false) }}">
    @csrf
    <button type="submit" class="nav-item w-full text-left">Logout</button>
</form>
