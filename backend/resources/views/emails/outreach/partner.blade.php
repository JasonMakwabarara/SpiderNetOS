<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#ffffff; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding: 24px 12px;">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%;">
          <tr>
            <td style="color:#1a1a1a; font-size:15px; line-height:1.6; white-space: pre-line;">{{ $bodyText }}</td>
          </tr>
          <tr>
            <td style="padding-top: 28px; color:#8a8a8a; font-size:12px; line-height:1.5; border-top: 1px solid #eeeeee; margin-top: 24px;">
              <p style="margin: 12px 0 4px 0;">{{ $footer['reason'] }}</p>
              <p style="margin: 0 0 4px 0;">{{ $footer['legal_name'] }}@if(!empty($footer['postal_address'])), {{ $footer['postal_address'] }}@endif</p>
              <p style="margin: 0;"><a href="{{ $footer['unsubscribe_url'] }}" style="color:#8a8a8a;">Unsubscribe</a> from partner invitations.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
