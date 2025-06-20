<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Models\User;

// ✅ Livewire Components
use App\Livewire\Pages\Admin\CreateUser;
use App\Livewire\Pages\Admin\UserList;
use App\Livewire\Pages\Admin\VpnServerList;
use App\Livewire\Pages\Admin\ServerCreate;
use App\Livewire\Pages\Admin\VpnServerEdit; 
use App\Livewire\Pages\Admin\ServerInstallStatus;


// 🌐 Landing page
Route::get('/', fn () => view('welcome'));

// 🌐 Shared fallback dashboard
Route::get('/dashboard', fn () => view('dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// ✅ Admin routes
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin' )
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', function () {
            return view('dashboards.admin', [
                'totalUsers' => User::count(),
                'activeUsers' => User::where('is_active', true)->count(),
                'totalResellers' => User::where('role', 'reseller')->count(),
                'totalClients' => User::where('role', 'client')->count(),
            ]);
        })->name('dashboard');

        // ✅ Admin user management
        Route::get('/users', UserList::class)->name('users.index');
        Route::get('/create-user', CreateUser::class)->name('create-user');

        // ✅ VPN server management
        Route::get('/servers', VpnServerList::class)->name('servers.index');
        Route::get('/servers/create', ServerCreate::class)->name('servers.create');
        Route::get('/servers/{vpnServer}/edit', VpnServerEdit::class)->name('servers.edit');
	    Route::get('/servers/{vpnServer}/install-status', ServerInstallStatus::class)->name('servers.install-status');
        Route::delete('/servers/{vpnServer}', [\App\Http\Controllers\VpnServerController::class, 'destroy'])->name('servers.destroy');

        // ✅ Settings
        Route::get('/settings', fn () => view('admin.settings'))->name('settings');
    });

// ✅ Reseller routes
Route::middleware(['auth', 'verified', 'role:reseller'])
    ->prefix('reseller')
    ->name('reseller.')
    ->group(function () {
        Route::get('/dashboard', fn () => view('dashboards.reseller'))->name('dashboard');
    });

// ✅ Client routes
Route::middleware(['auth', 'verified', 'role:client'])
    ->prefix('client')
    ->name('client.')
    ->group(function () {
        Route::get('/dashboard', \App\Livewire\Pages\Client\Dashboard::class)->name('dashboard');
        Route::get('/vpn/{server}/download', [\App\Http\Controllers\VpnConfigController::class, 'download'])->name('vpn.download');
    });


// ✅ Profile settings
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ✅ Auth routes
require __DIR__.'/auth.php';
