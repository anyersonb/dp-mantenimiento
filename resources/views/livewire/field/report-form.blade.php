<div>
    @if ($submitted)
        <div class="field-card success-box {{ $submittedWithoutLocation ? 'is-warning' : '' }}">
            <div class="check">{{ $submittedWithoutLocation ? '⚠️' : '✅' }}</div>
            <h2>{{ $submittedWithoutLocation ? __('field.report_success_no_location') : __('field.report_success') }}</h2>
            <button type="button" class="btn btn-primary" wire:click="startNew">
                {{ __('field.report_new') }}
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

            <label>{{ __('field.report_condition') }}</label>
            <div style="display:flex; gap:.5rem; margin-top:.35rem;">
                <button type="button" class="btn btn-ok {{ $condition === 'ok' ? 'active' : '' }}"
                        style="flex:1; padding:.75rem .25rem; font-size:.85rem;"
                        wire:click="$set('condition', 'ok')">
                    🟢 {{ __('field.report_condition_ok') }}
                </button>
                <button type="button" class="btn btn-warn {{ $condition === 'attention' ? 'active' : '' }}"
                        style="flex:1; padding:.75rem .25rem; font-size:.85rem;"
                        wire:click="$set('condition', 'attention')">
                    🟡 {{ __('field.report_condition_attention') }}
                </button>
                <button type="button" class="btn btn-danger {{ $condition === 'critical' ? 'active' : '' }}"
                        style="flex:1; padding:.75rem .25rem; font-size:.85rem;"
                        wire:click="$set('condition', 'critical')">
                    🔴 {{ __('field.report_condition_critical') }}
                </button>
            </div>

            <label for="hours">{{ __('field.report_hours') }}</label>
            <input id="hours" type="number" step="1" min="0" inputmode="numeric" wire:model="hours">
            @error('hours') <div class="error-msg">{{ $message }}</div> @enderror

            <label for="notes">{{ __('field.report_notes') }}</label>
            <textarea id="notes" rows="3" wire:model="notes"></textarea>

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
                <p class="warning-msg">⚠️ {{ __('field.report_will_submit_without_location') }}</p>
            @endunless

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">
                {{ __('field.report_submit') }}
            </button>
        </form>
    @endif

    @include('livewire.field.partials.geolocation-script')
</div>
