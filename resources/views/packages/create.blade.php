@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-xl font-bold">Create New Package</h1>

    <form method="POST" action="{{ route('admin.packages.store') }}" class="aio-section space-y-5">
        @csrf

        {{-- Name --}}
        <div class="form-group">
            <label class="form-label">Package Name</label>
            <input type="text" name="name" class="form-input" value="{{ old('name') }}" required>
            @error('name') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Description --}}
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-textarea" placeholder="Short description...">{{ old('description') }}</textarea>
            @error('description') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Credits --}}
        <div class="form-group">
            <label class="form-label">Credit Cost</label>
            <input type="number" name="price_credits" class="form-input" value="{{ old('price_credits') }}" required>
            @error('price_credits') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Max Devices --}}
        <div class="form-group">
            <label class="form-label">Max Devices</label>
            <input type="number" name="max_connections" class="form-input" value="{{ old('max_connections', 1) }}" required>
            <p class="form-help">Enter 0 for Unlimited devices</p>
            @error('max_connections') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Duration --}}
        <div class="form-group">
            <label class="form-label">Duration (months)</label>
            <input type="number" name="duration_months" class="form-input" value="{{ old('duration_months', 1) }}" required>
            @error('duration_months') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Featured --}}
        <div class="form-group">
            <label class="form-label">Featured</label>
            <select name="is_featured" class="form-select">
                <option value="0" @selected(old('is_featured') == 0)>No</option>
                <option value="1" @selected(old('is_featured') == 1)>Yes</option>
            </select>
            @error('is_featured') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Status --}}
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="is_active" class="form-select">
                <option value="1" @selected(old('is_active', 1) == 1)>Active</option>
                <option value="0" @selected(old('is_active') == 0)>Inactive</option>
            </select>
            @error('is_active') <p class="form-help text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.packages.index') }}" class="btn-secondary">Cancel</a>
            <button type="submit" class="btn">Save Package</button>
        </div>
    </form>
</div>
@endsection