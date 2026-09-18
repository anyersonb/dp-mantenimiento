<div>
    @if ($submitted)
        <div class="field-card success-box {{ $submittedWithoutLocation ? 'is-warning' : '' }}">
            <div class="check">{{ $submittedWithoutLocation ? '⚠️' : '✅' }}</div>
            <h2>{{ $submittedWithoutLocation ? __('field.fuel_success_no_location') : __('field.fuel_success') }}</h2>
            @unless ($submittedWithoutLocation)
                <p class="muted">{{ __('field.fuel_success_detail') }}</p>
            @endunless
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

            <label for="gallons">{{ __('field.fuel_gallons') }}</label>
            <input id="gallons" type="number" step="0.01" min="0" inputmode="decimal" wire:model="gallons">
            @error('gallons') <div class="error-msg">{{ $message }}</div> @enderror

            <label for="hours">{{ __('field.fuel_hours') }}</label>
            <input id="hours" type="number" step="1" min="0" inputmode="numeric" wire:model="hours">
            @error('hours') <div class="error-msg">{{ $message }}</div> @enderror

            <label for="note">{{ __('field.fuel_note') }}</label>
            <textarea id="note" rows="2" wire:model="note" placeholder="{{ __('field.fuel_note_placeholder') }}"></textarea>

            <p class="muted location-msg {{ $locationError ? 'is-error' : '' }}" style="margin-top:.75rem;">
                @if ($locationError)
                    ⚠️ {{ __('field.geolocation_error_'.$locationError) }}
                    <button type="button" class="link-retry" wire:click="retryLocation">{{ __('field.geolocation_retry') }}</button>
                @elseif ($locationCaptured)
                    📍 {{ __('field.geolocation_ok') }}
                @else
                    📍 {{ __('field.geolocation_capturing') }}
                @endif
            </p>

            @unless ($locationCaptured)
                <p class="warning-msg">⚠️ {{ __('field.fuel_will_submit_without_location') }}</p>
            @endunless

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                {{ __('field.fuel_submit') }}
            </button>
        </form>
    @endif

    @include('livewire.field.partials.geolocation-script')
</div>
