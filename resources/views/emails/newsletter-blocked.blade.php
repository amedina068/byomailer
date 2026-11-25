<x-mail::message>
# Newsletter could not be sent

Hi {{ $recipient->name }},

Your newsletter **"{{ $newsletter->title }}"** could not be sent because your AWS credentials are missing required SES permissions.

**Missing permissions:**

@foreach ($missingPermissions as $permission)
- `{{ $permission }}`
@endforeach

The newsletter has been marked as failed. Once you update your IAM policy, you can reschedule it.

<x-mail::button :url="$settingsUrl">
Review credentials
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
