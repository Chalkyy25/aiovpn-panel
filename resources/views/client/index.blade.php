@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto bg-white p-6 rounded shadow">
    <h2 class="text-2xl font-bold mb-4">All VPN Clients</h2>

    <table class="w-full border-collapse">
        <thead>
            <tr class="bg-gray-100">
                <th class="p-2 border">Username</th>
                <th class="p-2 border">Password</th>
                <th class="p-2 border">Server</th>
                <th class="p-2 border">Created</th>
                <th class="p-2 border">Download</th>
            </tr>
        </thead>
        <tbody>
            @foreach($clients as $client)
            <tr>
                <td class="p-2 border">{{ $client->username }}</td>
                <td class="p-2 border">{{ $client->password }}</td>
                <td class="p-2 border">{{ $client->vpnServer->name ?? 'N/A' }} ({{ $client->vpnServer->ip ?? 'N/A' }})</td>
                <td class="p-2 border">{{ $client->created_at->diffForHumans() }}</td>
                <td class="p-2 border text-center">
                    <a href="{{ route('clients.download', $client->id) }}" class="text-blue-600 hover:underline">⬇️</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
