<?php

use App\Http\Controllers\Phase4\InviteController;
use App\Http\Controllers\Sso\Central\ClaimsController;
use App\Http\Controllers\Sso\Central\RedeemBackchannelCodeController;
use App\Http\Controllers\Sso\Central\StartSsoController;
use App\Http\Middleware\ResolveS2sCaller;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])
    ->post('sso/start', StartSsoController::class)
    ->name('sso.start');

Route::middleware(['auth', 'verified'])
    ->post('invites/{inviteToken}/accept', [InviteController::class, 'accept'])
    ->name('phase4.invites.accept');

Route::middleware([ResolveS2sCaller::class, 'throttle:sso-claims'])
    ->get('idp/claims/{userId}', ClaimsController::class)
    ->whereNumber('userId')
    ->name('idp.claims.show');

Route::middleware([ResolveS2sCaller::class])
    ->post('sso/redeem', RedeemBackchannelCodeController::class)
    ->name('sso.redeem');

require __DIR__.'/admin.php';

require __DIR__.'/settings.php';
