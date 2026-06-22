<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app-component', [
            'layout' => $this->resolvePortalLayout(),
        ]);
    }

    private function resolvePortalLayout(): string
    {
        $user = auth()->user();

        if (! $user) {
            return 'layouts.guest';
        }

        if ($user->isAdmin()) {
            return 'layouts.admin';
        }

        if ($user->isReseller()) {
            return 'layouts.reseller';
        }

        return 'layouts.customer';
    }
}
