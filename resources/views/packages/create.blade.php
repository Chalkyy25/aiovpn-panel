@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <h1 class="text-xl font-bold">Create New Package</h1>

    <form method="POST" action="{{ route('admin.packages.store') }}" class="aio-section space-y-4">
        @csrf

        <div class="form-group">
            <label class="form-label">Package Name</label>
            <input type="text" name="name" class="form-input" value="{{ old('name') }}" required>
            @error('name') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
            <label class="form-label">Credit Cost</label>
            <input type="number" name="price_credits" class="form-input" value="{{ old('price_credits') }}" required>
            @error('price_credits') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="form-group">
            <label class="form-label">Max Devices</label>
            <input type="number" name="max_connections" class="form-input" value="{{ old('max_connections', 1) }}" required>
            <p class="form-help">Enter 0 for Unlimited devices</p>
            @error('max_connections') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.packages.index') }}" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn">Save Package</button>
        </div>
    </form>
</div>
@endsection