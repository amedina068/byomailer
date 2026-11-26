@extends('errors.layout')

@section('code', '404')
@section('title', 'We cannot find this page')

@section('message')
    <p>The page does not exist, or the link is not correct.</p>
    <a class="action" href="/">Go to the dashboard</a>
@endsection
