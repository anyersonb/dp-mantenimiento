<div>
    <div class="field-card">
        <h2 style="margin-top:0; text-align:center;">{{ __('field.login_title') }}</h2>

        <form wire:submit="login">
            <label for="email">{{ __('field.login_email') }}</label>
            <input id="email" type="email" wire:model="email" autofocus autocomplete="username" inputmode="email">
            @error('email') <div class="error-msg">{{ $message }}</div> @enderror

            <label for="password">{{ __('field.login_password') }}</label>
            <input id="password" type="password" wire:model="password" autocomplete="current-password">
            @error('password') <div class="error-msg">{{ $message }}</div> @enderror

            <button type="submit" class="btn btn-primary" style="margin-top:1.25rem;">
                {{ __('field.login_submit') }}
            </button>
        </form>
    </div>
</div>
