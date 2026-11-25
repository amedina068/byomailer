<x-mail::message>
@php
    $formattedStart = $periodStart->timezone($timezone)->format('F j, Y');
    $formattedEnd = $periodEnd->timezone($timezone)->format('F j, Y g:i A T');
@endphp

# Weekly summary for {{ $client->name }}

Hi {{ $recipient->name }},

Here is what happened between **{{ $formattedStart }}** and **{{ $formattedEnd }}**.

<x-mail::table>
| Metric | Count |
| :----- | ----: |
| New subscribers | {{ number_format($metrics['new_subscribers'] ?? 0) }} |
| Unsubscribes | {{ number_format($metrics['new_unsubscribes'] ?? 0) }} |
| Active subscribers | {{ number_format($metrics['active_subscribers'] ?? 0) }} |
| Mails sent | {{ number_format($metrics['mails_sent'] ?? 0) }} |
</x-mail::table>


<x-mail::button :url="$dashboardUrl">
View dashboard
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
