<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\VpnUser;

// ✅ Controllers
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\VpnUserController;
use App\Http\Controllers\VpnDisconnectController;
use App\Http\Controllers\VpnServerController;
use App\Http\Controllers\VpnConfigController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\Client\AuthController;
use App\Http\Controllers\Admin\Auth\LoginController as AdminLogin;

// ✅ Livewire Pages
use App\Livewire\Pages\Admin\{CreateUser,
    CreateVpnUser,
    CreateTrialLine,
    EditVpnUser,
    UserList,
    VpnUserList,
    VpnServerList,
    ServerCreate,
    ServerEdit,
    ServerShow,
    ServerInstallStatus,
    VpnUserConfigs,
    CreateReseller,
    ResellerList,
    ManageCredits,
    VpnDashboard};
    
// Reseller Routes
use App\Livewire\Pages\Reseller\Dashboard as ResellerDashboard;
use App\Livewire\Pages\Reseller\Credits as ResellerCredits;
use App\Livewire\Pages\Reseller\ClientsList;
use App\Livewire\Pages\Reseller\CreateClientLine;

// Client Routes
use App\Livewire\Pages\Client\Dashboard;

// 🌐 Public Landing Page
Route::get('/', fn () => view('welcome'));

// ✅ Shared Fallback Dashboard
Route::get('/dashboard', fn () => view('dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
    
Route::get('/debug/reverb', function () {
    return response()->json([
        'apps'    => config('reverb.apps'),
        'servers' => config('reverb.servers'),
    ]);
});

Route::get('/debug-db', function () {
    return config('database.connections.mysql');
});


// ======================
// ✅ Admin Routes
// ======================

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Dashboard (controller — invokes __invoke on DashboardController)
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // ...keep your other admin routes here (vpn-dashboard, users, servers, etc.)
        // VPN Dashboard
        Route::get('/vpn-dashboard', VpnDashboard::class)->name('vpn-dashboard');
        Route::post('/vpn/disconnect', VpnDisconnectController::class)->name('vpn.disconnect');

        // Users
        Route::get('/users', UserList::class)->name('users.index');
        Route::get('/users/create', CreateUser::class)->name('users.create');

        // Settings
        Route::get('/settings', fn () => view('admin.settings'))->name('settings');

        // VPN Users (global list)
        Route::get('/vpn-users', VpnUserList::class)->name('vpn-users.index');
        Route::get('/vpn-users/create', CreateVpnUser::class)->name('vpn-users.create');
        Route::get('/vpn-users/{vpnUser}/edit', EditVpnUser::class)->name('vpn-users.edit');
        
        //Trial Line Create
        Route::get('/vpn-users/trial', CreateTrialLine::class)->name('vpn-users.trial');

        // Reseller Create
        Route::get('/resellers/create', CreateReseller::class)->name('resellers.create');

        //Reseller List
        Route::get('/resellers', ResellerList::class)->name('resellers.index');
        
        //Manage Credits
         Route::get('/credits', ManageCredits::class)->name('credits');
        Route::resource('/packages', PackageController::class)->name('packages');

        // Admin Impersonation
        Route::post('/impersonate/{vpnUser}', [AdminImpersonationController::class, 'impersonate'])->name('impersonate');
        Route::post('/stop-impersonation', [AdminImpersonationController::class, 'stopImpersonation'])->name('stop-impersonation');
        
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
        Route::get('/{vpnserver}', ServerShow::class)->whereNumber('vpnserver')->name('show');
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
        Route::get('/dashboard', ResellerDashboard::class)->name('dashboard');
        Route::get('/credits',   ResellerCredits::class)->name('credits');

        // Lines/clients (the “subsellers/clients” that belong to the reseller)
        Route::get('/clients',        ClientsList::class)->name('clients.index');
        Route::get('/clients/create', CreateClientLine::class)->name('clients.create');
    });


// ============================
// ✅ Client Routes
// ============================

Route::prefix('client')->name('client.')->group(function () {
    // Guest-only routes (not logged in as client)
    Route::middleware('guest:client')->group(function () {
        Route::get('login',  [AuthController::class, 'showLoginForm'])->name('login.form');
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    // Authenticated client routes
    Route::middleware('auth:client')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('dashboard', Dashboard::class)->name('dashboard');

        // Client downloads an OVPN (or WG) for a specific server
        Route::get('vpn/{vpnserver}/download', [VpnConfigController::class, 'clientDownload'])
            ->name('vpn.download');
    });
});


// ADMIN auth (web guard)
Route::middleware('guest:web')->group(function () {
    Route::get('/login',  [AdminLogin::class, 'show'])->name('login.form');
    Route::post('/login', [AdminLogin::class, 'login'])->name('login');
});
Route::post('/logout', [AdminLogin::class, 'logout'])
    ->middleware('auth:web')
    ->name('logout');


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
