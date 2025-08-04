<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\VpnUser;

// ✅ Controllers
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VpnUserController;
use App\Http\Controllers\VpnServerController;
use App\Http\Controllers\VpnConfigController;
use App\Http\Controllers\ClientAuthController;

// ✅ Livewire Pages
use App\Livewire\Pages\Admin\{
    CreateUser,
    UserList,
    VpnUserList,
    VpnServerList,
    ServerCreate,
    ServerEdit,
    ServerShow,
    ServerInstallStatus,
    VpnUserConfigs,
    CreateReseller,
    ResellerList
};
use App\Livewire\Pages\Client\Dashboard;

// 🌐 Public Landing Page
Route::get('/', fn () => view('welcome'));

// ✅ Shared Fallback Dashboard
Route::get('/dashboard', fn () => view('dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');


// ======================
// ✅ Admin Routes
// ======================
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Dashboard
        Route::get('/dashboard', function () {
            return view('dashboards.admin', [
                'totalUsers'     => User::count(),
                'activeUsers'    => User::where('is_active', true)->count(),
                'totalVpnUsers'  => VpnUser::count(),
                'totalResellers' => User::where('role', 'reseller')->count(),
                'totalClients'   => User::where('role', 'client')->count(),
                'activeVpnUsers' => VpnUser::has('vpnServers')->count(),
            ]);
        })->name('dashboard');

        // Users
        Route::get('/users', UserList::class)->name('users.index');
        Route::get('/users/create', CreateUser::class)->name('users.create');

        // Settings
        Route::get('/settings', fn () => view('admin.settings'))->name('settings');

        // VPN Users (global list)
        Route::get('/vpn-users', VpnUserList::class)->name('vpn-users.index');

	    // Reseller Create
        Route::get('/resellers/create', CreateReseller::class)->name('resellers.create');

        //Reseller List
        Route::get('/resellers', ResellerList::class)->name('resellers.index');

    });


// ============================
// ✅ VPN Server Management
// ============================
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin/servers')
    ->name('admin.servers.')
    ->group(function () {
        // Core Server Routes
        Route::get('/', VpnServerList::class)->name('index');
        Route::get('/create', ServerCreate::class)->name('create');
        Route::get('/{vpnserver}/edit', ServerEdit::class)->name('edit');
        Route::get('/{vpnserver}/install-status', ServerInstallStatus::class)->name('install-status');
        Route::get('/{vpnserver}', ServerShow::class)->name('show');
        Route::delete('/{vpnserver}', [VpnServerController::class, 'destroy'])->name('destroy');

        // VPN Users for a Specific Server
        Route::prefix('/{vpnserver}/users')->group(function () {
            Route::get('/', [VpnUserController::class, 'index'])->name('users.index');
            Route::get('/create', [VpnUserController::class, 'create'])->name('users.create');
            Route::post('/', [VpnUserController::class, 'store'])->name('users.store'); // ✅ FIXED
            Route::post('/sync', [VpnUserController::class, 'sync'])->name('users.sync');
        });
    });


// ============================
// ✅ VPN Config Downloads
// ============================
Route::get('/clients/{vpnuser}/config', [VpnConfigController::class, 'download'])
    ->name('clients.config.download');

Route::get('/clients/{vpnuser}/config/{vpnserver}', [VpnConfigController::class, 'downloadForServer'])
    ->name('clients.config.downloadForServer');

Route::get('/clients/{vpnuser}/configs/download-all', [VpnConfigController::class, 'downloadAll'])
    ->name('clients.configs.downloadAll');

Route::get('/vpn-users/{vpnuser}/configs', VpnUserConfigs::class)
    ->name('clients.configs.index');

// ✅ WireGuard Config Downloads
Route::get('/wireguard/configs/{filename}', function ($filename) {
    $path = storage_path("app/configs/$filename");
    abort_unless(file_exists($path), 404);
    return response()->download($path);
})->name('wireguard.configs.download');


// ============================
// ✅ Reseller Routes
// ============================
Route::middleware(['auth', 'verified', 'role:reseller'])
    ->prefix('reseller')
    ->name('reseller.')
    ->group(function () {
        Route::get('/dashboard', fn () => view('dashboards.reseller'))->name('dashboard');
    });


// ============================
// ✅ Client Routes
// ============================
Route::prefix('client')->name('client.')->group(function () {
    // Login
    Route::get('/login', [ClientAuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [ClientAuthController::class, 'login']);
    Route::post('/logout', [ClientAuthController::class, 'logout'])->name('logout');

    // Authenticated Client Area
    Route::middleware('auth:client')->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('dashboard');
        Route::get('/vpn/{server}/download', [VpnConfigController::class, 'download'])->name('vpn.download');
    });
});


// ============================
// ✅ Profile Settings
// ============================
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


// ✅ Laravel Auth (Fortify / Breeze / Jetstream)
require __DIR__.'/auth.php';
