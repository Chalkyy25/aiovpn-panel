@extends('layouts.app')

@section('content')
<div class="container">
    <h1 class="mb-4">Client Dashboard</h1>
    <div class="row">
        <div class="col-md-4">
            <div class="card text-center mb-4">
                <div class="card-body">
                    <h5 class="card-title">Account Info</h5>
                    <p class="card-text">Welcome, {{ Auth::user()->name }}!</p>
                    <p class="card-text">Email: {{ Auth::user()->email }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    Recent Activity
                </div>
                <ul class="list-group list-group-flush">
                    <!-- Example static items, replace with dynamic data as needed -->
                    <li class="list-group-item">Logged in on {{ now()->toDayDateTimeString() }}</li>
                    <li class="list-group-item">No recent activity.</li>
                </ul>
            </div>
            <div class="card">
                <div class="card-header">
                    Quick Actions
                </div>
                <div class="card-body">
                    <a href="#" class="btn btn-primary">Manage Profile</a>
                    <a href="#" class="btn btn-secondary">Support</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection