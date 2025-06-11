@extends('layouts.app')

@section('content')
<div class="max-w-xl mx-auto bg-white p-6 rounded shadow">
    <h2 class="text-2xl font-bold mb-4">Create New VPN Client</h2>

@if(session('success') && isset($client))
    <div class="mb-4 text-green-600">
        {{ session('success') }}<br>
        <a href="{{ route('clients.download', $client->id) }}" class="underline text-blue-600">
            ⬇️ Download Config
        </a>
    </div>
@endif

    <form method="POST" action="{{ route('clients.store') }}">
        @csrf

        <label for="vpn_server_id" class="block font-semibold mb-2">Select VPN Server</label>
        <select name="vpn_server_id" id="vpn_server_id" class="w-full border p-2 rounded mb-4" required>
            @foreach($servers as $server)
                <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->ip }})</option>
            @endforeach
        </select>

        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            Create & Deploy Client
        </button>
    </form>
</div>
@endsection
