{{ $bodyText }}

--
{{ $footer['reason'] }}
{{ $footer['legal_name'] }}@if(!empty($footer['postal_address'])), {{ $footer['postal_address'] }}@endif

Unsubscribe: {{ $footer['unsubscribe_url'] }}
