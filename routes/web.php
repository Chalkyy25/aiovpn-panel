<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\WireGuardConfigController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\VpnUserController;
use App\Http\Controllers\VpnDisconnectController;
use App\Http\Controllers\VpnServerController;
use App\Http\Controllers\VpnConfigController;
use App\Http\Controllers\AdminImpersonationController;
use App\Http\Controllers\Client\AuthController as ClientAuthController;
use App\Http\Controllers\Admin\Auth\LoginController as AdminLogin;

// Downloads
use App\Http\Controllers\AppBuildPublicDownloadController;
use App\Http\Controllers\Admin\AppBuildDownloadController as AdminAppBuildDownloadController;

/*
|--------------------------------------------------------------------------
| Legacy Livewire Pages (Admin)
|--------------------------------------------------------------------------
*/
use App\Livewire\Pages\Admin\{
    CreateUser,
    AppBuilds,
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
    VpnDashboard
};

/*
|--------------------------------------------------------------------------
| Legacy Livewire Pages (Reseller)
|--------------------------------------------------------------------------
*/
use App\Livewire\Pages\Reseller\Dashboard as ResellerDashboard;
use App\Livewire\Pages\Reseller\Credits as ResellerCredits;
use App\Livewire\Pages\Reseller\ClientsList;
use App\Livewire\Pages\Reseller\CreateClientLine;

/*
|--------------------------------------------------------------------------
| Livewire Pages (Client)
|--------------------------------------------------------------------------
*/
use App\Livewire\Pages\Client\Dashboard as ClientDashboard;

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('welcome'));

Route::get('/debug-auth', function () {
    return response()->json([
        'url'            => request()->fullUrl(),
        'session_driver' => config('session.driver'),
        'session_domain' => config('session.domain'),
        'session_cookie' => config('session.cookie'),

        'web_check'      => auth('web')->check(),
        'web_id'         => auth('web')->id(),
        'web_role'       => optional(auth('web')->user())->role,

        'client_check'   => auth('client')->check(),
        'client_id'      => auth('client')->id(),
    ]);
});

Route::get('/debug/reverb', fn () => response()->json([
    'apps'    => config('reverb.apps'),
    'servers' => config('reverb.servers'),
]));

Route::get('/debug-db', fn () => config('database.connections.mysql'));

/*
|--------------------------------------------------------------------------
| PUBLIC DOWNLOADS (STABLE URL)
|--------------------------------------------------------------------------
*/
Route::get('/downloads/app.apk', [AppBuildPublicDownloadController::class, 'latest'])
    ->name('downloads.app.latest');

/*
|--------------------------------------------------------------------------
| STAFF LOGIN (web guard)
|--------------------------------------------------------------------------
| This is your global staff login page.
| Filament will handle its own /admin/login and /reseller/login too,
| but keeping this is fine while transitioning.
*/
Route::middleware('guest:web')->group(function () {
    Route::get('/login', [AdminLogin::class, 'show'])->name('login.form');
    Route::post('/login', [AdminLogin::class, 'login'])->name('login');
});

Route::post('/logout', [AdminLogin::class, 'logout'])
    ->middleware('auth:web')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| SHARED FALLBACK DASHBOARD (optional)
|--------------------------------------------------------------------------
*/
Route::get('/dashboard', fn () => view('dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| CLIENT (client guard) - unchanged
|--------------------------------------------------------------------------
*/
Route::prefix('client')->name('client.')->group(function () {

    Route::middleware('guest:client')->group(function () {
        Route::get('login', [ClientAuthController::class, 'showLoginForm'])->name('login.form');
        Route::post('login', [ClientAuthController::class, 'login'])->name('login');
    });

    Route::middleware('auth:client')->group(function () {
        Route::post('logout', [ClientAuthController::class, 'logout'])->name('logout');
        Route::get('dashboard', ClientDashboard::class)->name('dashboard');

        Route::get('vpn/{vpnserver}/download', [VpnConfigController::class, 'clientDownload'])
            ->name('vpn.download');
    });
});

/*
|--------------------------------------------------------------------------
| VPN CONFIG DOWNLOADS (keep URLs so nothing breaks)
|--------------------------------------------------------------------------
*/
Route::get('/clients/{vpnuser}/config', [VpnConfigController::class, 'download'])
    ->name('clients.config.download');

Route::get('/clients/{vpnuser}/config/{vpnserver}', [VpnConfigController::class, 'downloadForServer'])
    ->name('clients.config.downloadForServer');

Route::get('/clients/{vpnuser}/configs/download-all', [VpnConfigController::class, 'downloadAll'])
    ->name('clients.configs.downloadAll');

Route::get('/vpn-users/{vpnuser}/configs', VpnUserConfigs::class)
    ->name('clients.configs.index');

/*
|--------------------------------------------------------------------------
| LEGACY PANEL (LIVEWIRE) - MOVED OUT OF THE WAY
|--------------------------------------------------------------------------
| Everything old that used to live at /admin and /reseller now lives under /legacy/*
| so Filament can own /admin and /reseller cleanly.
*/
Route::prefix('legacy')->name('legacy.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Legacy Admin (Livewire)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth', 'verified', 'role:admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {

            Route::get('/dashboard', DashboardController::class)->name('dashboard');

            Route::get('/vpn-dashboard', VpnDashboard::class)->name('vpn-dashboard');
            Route::post('/servers/{server}/disconnect', [VpnDisconnectController::class, 'disconnect'])
                ->name('servers.disconnect');

            Route::get('/users', UserList::class)->name('users.index');
            Route::get('/users/create', CreateUser::class)->name('users.create');

            Route::get('/vpn-users/{user}/wg/{server}/download', [WireGuardConfigController::class, 'download'])
                ->name('vpn-users.wg.download');

            Route::get('/settings', fn () => view('admin.settings'))->name('settings');

            Route::resource('packages', PackageController::class);

            Route::get('/vpn-users', VpnUserList::class)->name('vpn-users.index');
            Route::get('/vpn-users/create', CreateVpnUser::class)->name('vpn-users.create');
            Route::get('/vpn-users/{vpnUser}/edit', EditVpnUser::class)->name('vpn-users.edit');
            Route::get('/vpn-users/trial', CreateTrialLine::class)->name('vpn-users.trial');

            Route::get('/resellers', ResellerList::class)->name('resellers.index');
            Route::get('/resellers/create', CreateReseller::class)->name('resellers.create');

            Route::get('/credits', ManageCredits::class)->name('credits');

            Route::post('/impersonate/{vpnUser}', [AdminImpersonationController::class, 'impersonate'])
                ->name('impersonate');
            Route::post('/stop-impersonation', [AdminImpersonationController::class, 'stopImpersonation'])
                ->name('stop-impersonation');

            Route::get('/app-builds', AppBuilds::class)->name('app-builds.index');
            Route::get('/app-builds/{build}/download', AdminAppBuildDownloadController::class)
                ->name('app-builds.download');

            // Legacy VPN Server Management
            Route::prefix('servers')->name('servers.')->group(function () {
                Route::get('/', VpnServerList::class)->name('index');
                Route::get('/create', ServerCreate::class)->name('create');
                Route::get('/{vpnserver}/edit', ServerEdit::class)->name('edit');
                Route::get('/{vpnserver}/install-status', ServerInstallStatus::class)->name('install-status');
                Route::get('/{vpnserver}', ServerShow::class)->whereNumber('vpnserver')->name('show');
                Route::delete('/{vpnserver}', [VpnServerController::class, 'destroy'])->name('destroy');

                Route::prefix('{vpnserver}/users')->group(function () {
                    Route::get('/', [VpnUserController::class, 'index'])->name('users.index');
                    Route::get('/create', [VpnUserController::class, 'create'])->name('users.create');
                    Route::post('/', [VpnUserController::class, 'store'])->name('users.store');
                    Route::post('/sync', [VpnUserController::class, 'sync'])->name('users.sync');
                });
            });
        });

    /*
    |--------------------------------------------------------------------------
    | Legacy Reseller (Livewire)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth', 'verified', 'role:reseller'])
        ->prefix('reseller')
        ->name('reseller.')
        ->group(function () {

            Route::get('/dashboard', ResellerDashboard::class)->name('dashboard');
            Route::get('/credits', ResellerCredits::class)->name('credits');

            Route::get('/clients', ClientsList::class)->name('clients.index');
            Route::get('/clients/create', CreateClientLine::class)->name('clients.create');
        });
});

/*
|--------------------------------------------------------------------------
| Profile (web auth)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Default Auth Routes
|--------------------------------------------------------------------------
*/
require __DIR__ . '/auth.php';