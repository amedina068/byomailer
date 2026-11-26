@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many requests')

@section('message')
    <p>This address sent too many requests in a short time. Wait one minute. Then try again.</p>
@endsection
