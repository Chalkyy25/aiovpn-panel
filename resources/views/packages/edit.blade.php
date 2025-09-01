@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <h1 class="text-xl font-bold">Edit Package</h1>

    <form method="POST" action="{{ route('admin.packages.update', $package) }}" class="aio-section space-y-4">
        @csrf
        @method('PUT')

        {{-- Package Name --}}
        <div class="form-group">
            <label class="form-label">Package Name</label>
            <input type="text" name="name" class="form-input" 
                   value="{{ old('name', $package->name) }}" required>
            @error('name') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Description --}}
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-textarea">{{ old('description', $package->description) }}</textarea>
            @error('description') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Credit Cost --}}
        <div class="form-group">
            <label class="form-label">Credit Cost</label>
            <input type="number" name="price_credits" class="form-input"
                   value="{{ old('price_credits', $package->price_credits) }}" required>
            @error('price_credits') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Max Devices --}}
        <div class="form-group">
            <label class="form-label">Max Devices</label>
            <input type="number" name="max_connections" class="form-input"
                   value="{{ old('max_connections', $package->max_connections) }}" required>
            <p class="form-help">Enter 0 for Unlimited devices</p>
            @error('max_connections') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Duration --}}
        <div class="form-group">
            <label class="form-label">Duration (months)</label>
            <input type="number" name="duration_months" class="form-input"
                   value="{{ old('duration_months', $package->duration_months) }}" required>
            @error('duration_months') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Toggles --}}
        <div class="form-check">
            <input type="checkbox" name="is_featured" value="1" 
                   {{ old('is_featured', $package->is_featured) ? 'checked' : '' }}>
            <span>Featured</span>
        </div>

        <div class="form-check">
            <input type="checkbox" name="is_active" value="1" 
                   {{ old('is_active', $package->is_active) ? 'checked' : '' }}>
            <span>Active</span>
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.packages.index') }}" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn">Update Package</button>
        </div>
    </form>
</div>
@endsection