@extends('errors.layout')

@section('code', '403')
@section('title', 'You do not have access to this page')

@section('message')
    <p>Your role in this account does not give access to this page. If you need access, ask an admin of your account.</p>
    <a class="action" href="/">Go to the dashboard</a>
@endsection
