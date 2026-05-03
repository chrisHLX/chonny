<?php

namespace App\Livewire;

use App\Models\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class JobDashboard extends Component
{
    public string $tab = 'active';

    public function render(): View
    {
        $user    = Auth::user();
        $isAdmin = $user->is_admin;

        $base = Pipeline::with(['steps', 'module', 'user'])
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id));

        $active = (clone $base)
            ->whereIn('status', ['pending', 'running'])
            ->orderByDesc('created_at')
            ->get();

        $recent = (clone $base)
            ->whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '>=', now()->subHours(24))
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return view('livewire.job-dashboard', [
            'active'  => $active,
            'recent'  => $recent,
            'isAdmin' => $isAdmin,
            'polling' => $active->isNotEmpty(),
        ]);
    }
}
