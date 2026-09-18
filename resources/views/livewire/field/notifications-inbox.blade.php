<div>
    @if ($notifications->isEmpty())
        <div class="field-card muted">{{ __('field.notifications_empty') }}</div>
    @else
        @if ($notifications->whereNull('read_at')->isNotEmpty())
            <button type="button" class="btn btn-outline" style="margin-bottom:.75rem;" wire:click="markAllAsRead">
                {{ __('field.notifications_mark_all_read') }}
            </button>
        @endif

        @foreach ($notifications as $notification)
            <div class="field-card" style="{{ $notification->read_at ? 'opacity:.6;' : '' }}">
                <p style="margin:0; font-weight:600;">{{ $notification->data['title'] ?? '' }}</p>
                @if (!empty($notification->data['body']))
                    <p class="muted" style="margin:.25rem 0 0;">{{ $notification->data['body'] }}</p>
                @endif
                <p class="muted" style="margin:.35rem 0 0; font-size:.75rem;">
                    {{ $notification->created_at->diffForHumans() }}
                </p>

                @unless ($notification->read_at)
                    <button type="button" class="link-retry" style="margin-top:.5rem;"
                            wire:click="markAsRead('{{ $notification->id }}')">
                        {{ __('field.notifications_mark_read') }}
                    </button>
                @endunless
            </div>
        @endforeach
    @endif
</div>
