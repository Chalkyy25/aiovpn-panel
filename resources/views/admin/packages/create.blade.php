@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <h1 class="text-xl font-bold">Create New Package</h1>

    <form method="POST" action="{{ route('admin.packages.store') }}" class="aio-section space-y-4">
        @csrf

        {{-- Package Name --}}
        <div class="form-group">
            <label class="form-label">Package Name</label>
            <input type="text" name="name" class="form-input" value="{{ old('name') }}" required>
            @error('name') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Description --}}
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-textarea">{{ old('description') }}</textarea>
            @error('description') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Credit Cost --}}
        <div class="form-group">
            <label class="form-label">Credit Cost</label>
            <input type="number" name="price_credits" class="form-input" value="{{ old('price_credits') }}" required>
            @error('price_credits') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Max Devices --}}
        <div class="form-group">
            <label class="form-label">Max Devices</label>
            <select name="max_connections" class="form-select" required>
                <option value="1" {{ old('max_connections') == 1 ? 'selected' : '' }}>1 Device</option>
                <option value="2" {{ old('max_connections') == 2 ? 'selected' : '' }}>2 Devices</option>
                <option value="3" {{ old('max_connections') == 3 ? 'selected' : '' }}>3 Devices</option>
                <option value="5" {{ old('max_connections') == 5 ? 'selected' : '' }}>5 Devices</option>
                <option value="10" {{ old('max_connections') == 10 ? 'selected' : '' }}>10 Devices</option>
                <option value="0" {{ old('max_connections') == 0 ? 'selected' : '' }}>Unlimited</option>
            </select>
            @error('max_connections') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Duration --}}
        <div class="form-group">
            <label class="form-label">Duration</label>
            <select name="duration_months" class="form-select" required>
                <option value="1" {{ old('duration_months') == 1 ? 'selected' : '' }}>1 Month</option>
                <option value="3" {{ old('duration_months') == 3 ? 'selected' : '' }}>3 Months</option>
                <option value="6" {{ old('duration_months') == 6 ? 'selected' : '' }}>6 Months</option>
                <option value="12" {{ old('duration_months') == 12 ? 'selected' : '' }}>12 Months (1 Year)</option>
                <option value="24" {{ old('duration_months') == 24 ? 'selected' : '' }}>24 Months (2 Years)</option>
            </select>
            @error('duration_months') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Featured --}}
        <div class="form-group">
            <label class="form-label">Featured</label>
            <select name="is_featured" class="form-select">
                <option value="0" {{ old('is_featured') == 0 ? 'selected' : '' }}>No</option>
                <option value="1" {{ old('is_featured') == 1 ? 'selected' : '' }}>Yes</option>
            </select>
            @error('is_featured') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Status --}}
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="is_active" class="form-select">
                <option value="1" {{ old('is_active', 1) == 1 ? 'selected' : '' }}>Active</option>
                <option value="0" {{ old('is_active') == 0 ? 'selected' : '' }}>Inactive</option>
            </select>
            @error('is_active') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Buttons --}}
        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.packages.index') }}" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn">Save Package</button>
        </div>
    </form>
</div>
@endsection