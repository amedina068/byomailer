@extends('errors.layout')

@section('code', '419')
@section('title', 'This page expired')

@section('message')
    <p>The form was open for too long. Go back. Reload the page, and then send the form again.</p>
    <a class="action" href="/">Go to the dashboard</a>
@endsection
