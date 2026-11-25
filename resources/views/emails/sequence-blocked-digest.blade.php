<x-mail::message>
# Sequence emails blocked

Hi {{ $recipient->name }},

**{{ number_format($blockedCount) }}** sequence {{ Str::plural('email', $blockedCount) }} {{ $blockedCount === 1 ? 'is' : 'are' }} waiting for the `ses:SendEmail` permission. Your AWS credentials do not have this permission. The count includes the blocked emails of earlier days.

BYOMailer tries these emails again when the permission is back.

<x-mail::button :url="$settingsUrl">
Review credentials
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
