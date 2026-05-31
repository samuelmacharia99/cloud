@extends('emails._layout')

@section('content')
    <div style="white-space:pre-wrap;line-height:1.6;color:#374151;">{!! nl2br(e($bodyText)) !!}</div>
@endsection
