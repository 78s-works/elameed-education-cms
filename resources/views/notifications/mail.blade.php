<!DOCTYPE html>
<html lang="{{ $direction === 'rtl' ? 'ar' : 'en' }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Tahoma,Arial,sans-serif;color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr>
            <td style="padding:20px 24px;background:#0f172a;color:#ffffff;font-size:16px;font-weight:bold;">
                {{ $senderName }}
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                @if (trim($subjectLine) !== '')
                    <h1 style="margin:0 0 12px;font-size:18px;line-height:1.5;color:#0f172a;">{{ $subjectLine }}</h1>
                @endif
                <div style="font-size:15px;line-height:1.8;white-space:pre-line;">{{ $bodyText }}</div>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;background:#f8fafc;font-size:12px;color:#64748b;">
                {{ $senderName }}
            </td>
        </tr>
    </table>
</body>
</html>
