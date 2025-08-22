<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Admin Settings</h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- Site Branding -->
        <div class="bg-white p-6 rounded shadow">
            <h3 class="text-lg font-semibold mb-2">Site Branding</h3>
            <p class="text-sm text-gray-600">Set your site name, logo, and branding preferences here.</p>
            <button class="mt-2 text-blue-600 underline">Edit Branding</button>
        </div>

        <!-- VPN Defaults -->
        <div class="bg-white p-6 rounded shadow">
            <h3 class="text-lg font-semibold mb-2">VPN Defaults</h3>
            <p class="text-sm text-gray-600">Configure default VPN settings such as protocol, port, DNS.</p>
            <button class="mt-2 text-blue-600 underline">Edit VPN Defaults</button>
        </div>

        <!-- System Logs -->
        <div class="bg-white p-6 rounded shadow">
            <h3 class="text-lg font-semibold mb-2">System Logs</h3>
            <p class="text-sm text-gray-600">View recent system logs or errors from the panel.</p>
            <button class="mt-2 text-blue-600 underline">View Logs</button>
        </div>
    </div>
</x-app-layout>
