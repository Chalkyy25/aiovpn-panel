<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index()
    {
        $packages = Package::latest()->paginate(10);
        return view('packages.index', compact('packages'));
    }

    public function create()
    {
        return view('packages.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'price_credits'   => 'required|integer|min:0',
            'max_connections' => 'required|integer|min:0',
        ]);

        Package::create($validated);

        return redirect()->route('packages.index')->with('success', 'Package created successfully!');
    }
}
