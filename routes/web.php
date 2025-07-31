<?php

use App\Http\Controllers\VpnConfigController;
use App\Http\Controllers\VpnServerController;
use App\Livewire\Pages\Admin\ServerEdit;
use App\Livewire\Pages\Admin\ServerShow;
use App\Livewire\Pages\Admin\VpnUserList;
use App\Livewire\Pages\Client\Dashboard;
use App\Models\VpnUser;
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
use App\Livewire\Pages\Admin\VpnUserConfigs;
use App\Http\Controllers\VpnUserController;
use App\Http\Controllers\ClientAuthController;

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
                'totalVpnUsers' => VpnUser::count(),
                'totalResellers' => User::where('role', 'reseller')->count(),
                'totalClients' => User::where('role', 'client')->count(),
                'activeVpnUsers' => VpnUser::has('vpnServers')->count(),
            ]);
        })->name('dashboard');

        // ✅ Admin user management
        Route::get('/users', UserList::class)->name('users.index');
        Route::get('/create-user', CreateUser::class)->name('create-user');

	// ✅ VPN config download
	Route::get('/clients/{vpnUser}/config', [VpnConfigController::class, 'download'])
	    ->name('clients.config.download');

	Route::get('/clients/{vpnUser}/config/{vpnServer}', [VpnConfigController::class, 'downloadForServer'])
	    ->name('clients.config.downloadForServer');

	Route::get('/clients/{vpnUser}/configs/download-all', [VpnConfigController::class, 'downloadAll'])
	    ->name('clients.configs.downloadAll');

	Route::get('/vpn-users/{vpnUser}/configs', VpnUserConfigs::class)
	    ->name('clients.configs.index');

        // ✅ VPN server management
        Route::get('/servers', VpnServerList::class)->name('servers.index');
        Route::get('/servers/create', ServerCreate::class)->name('servers.create');
        Route::get('/servers/{vpnServer}/edit', ServerEdit::class)->name('servers.edit');
	    Route::get('/servers/{vpnServer}/install-status', ServerInstallStatus::class)->name('servers.install-status');
        Route::get('/servers/{vpnServer}', ServerShow::class)->name('servers.show');
        Route::delete('/servers/{vpnServer}', [VpnServerController::class, 'destroy'])->name('servers.destroy');

	// ✅ VPN User Management per server
        Route::prefix('/servers/{vpnServer}/users')->group(function () {
        Route::get('/', [VpnUserController::class, 'index'])->name('servers.users.index');
        Route::get('/create', [VpnUserController::class, 'create'])->name('servers.users.create');
        Route::post('/', [VpnUserController::class, 'store'])->name('servers.users.store');
        Route::post('/sync', [VpnUserController::class, 'sync'])->name('servers.users.sync');
	 });

	// ✅ VPN Users page
	Route::get('/vpn-user-list', VpnUserList::class)->name('vpn-user-list');

	// ✅ WireGuard config download
	Route::get('/wireguard/configs/{filename}', function ($filename) {
	    $path = storage_path('app/configs/' . $filename);
	    if (!file_exists($path)) {
	        abort(404);
	    }
	    return response()->download($path);
	})->name('wireguard.configs.download');

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
Route::prefix('client')->name('client.')->group(function () {
    // 🚀 Client Login Routes
    Route::get('/login', [ClientAuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [ClientAuthController::class, 'login']);
    Route::post('/logout', [ClientAuthController::class, 'logout'])->name('logout');

    // ✅ Authenticated Client Routes
    Route::middleware('auth:client')->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('dashboard');
        Route::get('/vpn/{server}/download', [VpnConfigController::class, 'download'])->name('vpn.download');
    });
});


// ✅ Profile settings
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ✅ Auth routes
require __DIR__.'/auth.php';
