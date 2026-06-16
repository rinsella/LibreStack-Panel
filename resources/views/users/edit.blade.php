@extends('layouts.app')
@section('title', 'Edit ' . $user->name)

@section('content')
@include('users.form', ['action' => route('users.update', $user), 'method' => 'PUT', 'isNew' => false])
@endsection
