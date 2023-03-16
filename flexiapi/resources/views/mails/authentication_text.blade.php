Hello,

You are trying to authenticate to {{ config('app.name') }} using your email account.
Please follow the unique link bellow to finish the authentication process.

{{ $link }}

You can as well configure your new device using the following code or by directly flashing the QRCode in the following link:

{{ $provisioning_qrcode}}

Regards,
{{ config('mail.signature') }}