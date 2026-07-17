<x-mail::message>
# You have been invited to Settlo

**{{ $firmName }}** would like to manage your Settlo books as your accounting firm.

@if ($personalMessage)
<x-mail::panel>
{{ $personalMessage }}
</x-mail::panel>
@endif

Accept the invitation to grant them access to one of your businesses. You choose which business — nothing is shared until you confirm.

<x-mail::button :url="$acceptUrl">
Review invitation
</x-mail::button>

@isset($expiresAt)
This invitation expires on {{ $expiresAt->format('d.m.Y') }}.
@endisset

If you were not expecting this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
