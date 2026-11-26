@extends('errors.layout')

@section('code', '500')
@section('title', 'The server had an error')

@section('message')
    <p>BYOMailer did not complete the request. Try again in a few minutes.</p>
    <p>If you operate this instance, run <code>php artisan byomailer:doctor</code>. Then read the log in <code>storage/logs</code>.</p>
    <a class="action" href="/">Go to the dashboard</a>
@endsection
