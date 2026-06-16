@extends('layouts.app')
@section('title', 'Edit cron job')

@section('content')
@include('cron.form', ['action' => route('cron.update', $job), 'method' => 'PUT'])
@endsection
