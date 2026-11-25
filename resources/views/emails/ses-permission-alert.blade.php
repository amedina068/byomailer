<x-mail::message>
# Missing SES permissions detected

Hi {{ $recipient->name }},

We checked the AWS credentials attached to your account and found that some required SES permissions are missing. Until these are resolved, some features may not work correctly.

**Missing permissions:**

@foreach ($missingPermissions as $permission)
- `{{ $permission }}`
@endforeach

Please update your IAM policy in the AWS console to include the permissions listed above.

<x-mail::button :url="$settingsUrl">
Review credentials
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
