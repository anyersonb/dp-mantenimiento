<div>
    @if ($submitted)
        <div class="field-card success-box">
            <div class="check">✅</div>
            <h2>{{ __('field.foreman_success') }}</h2>
            <button type="button" class="btn btn-primary" wire:click="startNew">
                {{ __('field.fuel_new') }}
            </button>
        </div>
    @else
        <form wire:submit="save" class="field-card">
            <label>{{ __('field.machine_search_label') }}</label>

            @if ($machineId)
                <div class="selected-machine">
                    <span>{{ $machineLabel }}</span>
                    <button type="button" wire:click="clearMachine">{{ __('field.machine_change') }}</button>
                </div>
            @else
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('field.machine_search_placeholder') }}" inputmode="text">
                @if (count($machineResults))
                    <div class="machine-list">
                        @foreach ($machineResults as $m)
                            <button type="button" wire:click="selectMachine({{ $m['id'] }})">{{ $m['id_code'] }}</button>
                        @endforeach
                    </div>
                @endif
            @endif
            @error('machineId') <div class="error-msg">{{ __('field.validation_required_machine') }}</div> @enderror

            <label for="locationId">{{ __('field.foreman_new_location') }}</label>
            <select id="locationId" wire:model="locationId">
                <option value="">—</option>
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
            </select>
            @error('locationId') <div class="error-msg">{{ $message }}</div> @enderror

            <label for="hours">{{ __('field.foreman_hours') }}</label>
            <input id="hours" type="number" step="1" min="0" inputmode="numeric" wire:model="hours">
            @error('hours') <div class="error-msg">{{ $message }}</div> @enderror

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                {{ __('field.foreman_submit') }}
            </button>
        </form>
    @endif

    <div class="field-card">
        <h3 style="margin-top:0;">{{ __('field.foreman_my_machines') }}</h3>
        @forelse ($myMachines as $m)
            <div style="display:flex; justify-content:space-between; padding:.5rem 0; border-bottom:1px solid #f1f5f9;">
                <strong>{{ $m->id_code }}</strong>
                <span class="muted">{{ $m->location?->name ?? '—' }}</span>
            </div>
        @empty
            <p class="muted">{{ __('field.foreman_no_machines') }}</p>
        @endforelse
    </div>
</div>
