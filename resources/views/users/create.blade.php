@extends('layouts.app')
@section('title', 'New user')

@section('content')
@include('users.form', ['action' => route('users.store'), 'method' => 'POST', 'isNew' => true])
@endsection
