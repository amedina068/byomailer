@extends('errors.layout')

@section('code', '401')
@section('title', 'Log in to see this page')

@section('message')
    <p>This page is only for signed-in users.</p>
    <a class="action" href="/login">Log in</a>
@endsection
