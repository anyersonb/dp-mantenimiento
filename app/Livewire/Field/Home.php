<?php

namespace App\Livewire\Field;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Home extends Component
{
    public function render()
    {
        $user = Auth::user();

        return view('livewire.field.home', [
            'user' => $user,
            'canAccessAdmin' => $user->canAccessPanel(Filament::getDefaultPanel()),
        ])->layout('components.layouts.field', ['title' => __('field.home_title')]);
    }
}
