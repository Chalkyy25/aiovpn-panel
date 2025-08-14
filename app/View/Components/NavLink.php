<?php

namespace App\View\Components;

use Illuminate\View\Component;

class NavLink extends Component
{
    public bool $active;
    public ?string $icon;

    public function __construct($active = false, ?string $icon = null)
    {
        // Normalize truthy values from Blade (bool or string)
        $this->active = filter_var($active, FILTER_VALIDATE_BOOLEAN);
        $this->icon   = $icon;
    }

    public function render()
    {
        return view('components.nav-link');
    }
}