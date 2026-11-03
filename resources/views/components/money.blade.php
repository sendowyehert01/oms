@props(['cents'])

@php
    $wholeAmount = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) intdiv($cents, 100));
    $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
@endphp

{{ $wholeAmount.'.'.$fraction }}
