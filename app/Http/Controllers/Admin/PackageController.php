<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * Show a paginated list of packages.
     */
    public function index()
    {
        $packages = Package::latest()->paginate(10);

        return view('packages.index', compact('packages'));
    }

    /**
     * Show the form for creating a new package.
     */
    public function create()
    {
        return view('packages.create');
    }

    /**
     * Store a newly created package in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'price_credits'   => ['required', 'integer', 'min:0'],
            'max_connections' => ['required', 'integer', 'min:0'], // 0 = Unlimited
        ]);

        Package::create($validated);

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package created successfully.');
    }

    /**
     * Show the form for editing an existing package.
     */
    public function edit(Package $package)
    {
        return view('packages.edit', compact('package'));
    }

    /**
     * Update the specified package in storage.
     */
    public function update(Request $request, Package $package)
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'price_credits'   => ['required', 'integer', 'min:0'],
            'max_connections' => ['required', 'integer', 'min:0'],
        ]);

        $package->update($validated);

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package updated successfully.');
    }

    /**
     * Remove the specified package from storage.
     */
    public function destroy(Package $package)
    {
        $package->delete();

        return redirect()
            ->route('admin.packages.index')
            ->with('success', 'Package deleted successfully.');
    }
}