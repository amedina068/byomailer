<x-mail::message>
# You're invited to {{ $clientName }}

Hello {{ $invitee->name }},

{{ $clientName }} has invited you to collaborate inside {{ config('app.name') }}. Click the button below to accept and finish setting up your account.

<x-mail::button :url="$inviteUrl">
Accept invitation
</x-mail::button>

@if ($invitee->invite_expires_at)
This secure link expires on {{ $invitee->invite_expires_at->timezone(config('app.timezone'))->format('F j, Y g:i A T') }}.
@endif

If you cannot use the button above, copy and paste this URL into your browser:
{{ $inviteUrl }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
