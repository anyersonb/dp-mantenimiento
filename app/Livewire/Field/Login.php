<?php

namespace App\Livewire\Field;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function mount()
    {
        if (Auth::check()) {
            return redirect()->to($this->targetUrl(Auth::user()));
        }
    }

    public function login()
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            $this->addError('email', __('field.login_error'));

            return null;
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->active) {
            Auth::logout();
            $this->addError('email', __('field.login_error'));

            return null;
        }

        request()->session()->regenerate();

        return redirect()->to($this->targetUrl($user));
    }

    protected function targetUrl(User $user): string
    {
        return $user->canAccessPanel(Filament::getDefaultPanel())
            ? url('/admin')
            : route('field.home');
    }

    public function render()
    {
        return view('livewire.field.login')
            ->layout('components.layouts.field', ['title' => __('field.login_title')]);
    }
}
