<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#f4f5f7; font-family: -apple-system, Segoe UI, Roboto, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7; padding: 24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius: 12px; overflow: hidden;">
          <tr>
            <td style="background: {{ $primaryColor }}; padding: 20px 28px;">
              @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $businessName }}" style="max-height: 32px; display:block;">
              @else
                <span style="color:#ffffff; font-weight:600; font-size:16px;">{{ $businessName }}</span>
              @endif
            </td>
          </tr>
          <tr>
            <td style="padding: 28px; color:#1a1a1a; font-size:15px; line-height:1.6; white-space: pre-line;">{{ $bodyText }}</td>
          </tr>
          <tr>
            <td style="padding: 16px 28px; border-top: 1px solid #eee; color:#999; font-size:12px;">
              Sent by {{ $businessName }} via SpiderNetOS
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
