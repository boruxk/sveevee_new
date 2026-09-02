<!doctype html>
<html lang="{{ $messageLocale }}" dir="{{ $messageLocale === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mail.chat.subject', ['sender' => $senderName]) }}</title>
</head>
<body style="margin:0;background:#f4f5f7;color:#20242a;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 16px;background:#f4f5f7;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #e2e5e9;border-radius:8px;">
                <tr>
                    <td style="padding:30px;{{ $messageLocale === 'he' ? 'text-align:right;' : 'text-align:left;' }}">
                        <div style="font-size:22px;font-weight:700;color:#582a72;margin-bottom:24px;">Sveevee</div>
                        <h1 style="font-size:24px;line-height:1.3;margin:0 0 14px;">{{ __('mail.chat.heading') }}</h1>
                        <p style="font-size:16px;line-height:1.6;margin:0 0 24px;">{{ __('mail.chat.body', ['sender' => $senderName]) }}</p>
                        <a href="{{ $chatUrl }}" style="display:inline-block;padding:12px 18px;border-radius:6px;background:#6d3785;color:#ffffff;text-decoration:none;font-weight:700;">{{ __('mail.chat.action') }}</a>
                        <p style="font-size:13px;line-height:1.6;color:#656b73;margin:24px 0 0;">{{ __('mail.chat.privacy') }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
