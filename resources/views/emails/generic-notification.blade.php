@extends('emails._layout')

@section('content')
    <h2 style="margin-top:0;color:#1f2937;">{{ $heading }}</h2>
    <div style="white-space:pre-wrap;line-height:1.6;color:#374151;">{!! nl2br(e($body)) !!}</div>
@endsection
