<?php

namespace App\View\Components;

use Illuminate\View\Component;

class StatCard extends Component
{
    public string $title;
    public string $value;
    public ?string $icon;
    public ?string $hint;

    public function __construct(string $title = '', string $value = '', ?string $icon = 'o-chart-bar', ?string $hint = null)
    {
        $this->title = $title;
        $this->value = $value;
        $this->icon  = $icon;
        $this->hint  = $hint;
    }

    public function render()
    {
        return view('components.stat-card');
    }
}