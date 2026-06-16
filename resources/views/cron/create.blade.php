@extends('layouts.app')
@section('title', 'New cron job')

@section('content')
@include('cron.form', ['action' => route('cron.store'), 'method' => 'POST'])
@endsection
