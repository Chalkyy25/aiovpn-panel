@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto mt-10 bg-white p-6 rounded shadow">
    <h2 class="text-2xl font-bold mb-4">Client Login</h2>

    @if ($errors->any())
        <div class="bg-red-100 text-red-700 p-2 rounded mb-4">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('client.login') }}">
        @csrf

        <div class="mb-4">
            <label for="username" class="block font-medium text-sm text-gray-700">Username</label>
            <input type="text" name="username" id="username" class="mt-1 block w-full border-gray-300 rounded shadow-sm" required autofocus>
        </div>

        <div class="mb-6">
            <label for="password" class="block font-medium text-sm text-gray-700">Password</label>
            <input type="password" name="password" id="password" class="mt-1 block w-full border-gray-300 rounded shadow-sm" required>
        </div>

        <div class="flex items-center justify-between">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Login</button>
        </div>
    </form>
</div>
@endsection
