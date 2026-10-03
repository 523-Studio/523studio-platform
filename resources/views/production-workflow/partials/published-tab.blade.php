<div x-data="{ search: '', matches(...fields) { if (!this.search) return true; const s = this.search.toLowerCase(); return fields.some(f => f.toLowerCase().includes(s)); } }"
    class="px-4 sm:px-6 lg:px-8 pb-8">

    <form id="filter-published-form" method="GET" action="{{ route('production-workflow.index') }}"></form>
    <input type="hidden" name="tab" value="published" form="filter-published-form">

    <div class="grid grid-cols-2 sm:flex sm:items-center sm:flex-wrap gap-2 sm:gap-3 mb-4 sm:mb-5">
        <select name="client_id" form="filter-published-form" onchange="this.form.submit()"
            class="w-full h-10 min-w-0 border border-[var(--border)] rounded-lg px-3 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 sm:w-auto">
            <option value="">Semua Klien</option>
            @foreach ($clientOptions as $client)
                <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
            @endforeach
        </select>
        <select name="platform_id" form="filter-published-form" onchange="this.form.submit()"
            class="w-full h-10 min-w-0 border border-[var(--border)] rounded-lg px-3 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 sm:w-auto">
            <option value="">Semua Platform</option>
            @foreach ($platformOptions as $platform)
                <option value="{{ $platform->id }}" {{ (string) $selectedPlatformId === (string) $platform->id ? 'selected' : '' }}>{{ $platform->name }}</option>
            @endforeach
        </select>

        <div class="relative col-span-2 order-first sm:order-none sm:col-span-1 sm:flex-1 sm:min-w-[200px] sm:max-w-xs">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[19px]">search</span>
            <input x-model="search"
                class="pl-10 pr-4 h-10 bg-[var(--surface-card)] border border-[var(--border)] rounded-lg text-sm focus:outline-none focus:border-[#044b46]/40 w-full"
                placeholder="Cari konten..." type="text">
        </div>
    </div>

    @include('publishing-tracker.partials.table')
</div>
