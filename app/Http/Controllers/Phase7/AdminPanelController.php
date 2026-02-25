<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AdminPanelController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.admin.access');

        return Inertia::render('admin/panel', [
            'guard' => Auth::getDefaultDriver(),
            'session_timeout_seconds' => (int) config('phase7.admin.inactivity_timeout_seconds', 900),
            'privacy_budget_window_seconds' => (int) config('phase7.telemetry.privacy_budget.window_seconds', 3600),
        ]);
    }
}
