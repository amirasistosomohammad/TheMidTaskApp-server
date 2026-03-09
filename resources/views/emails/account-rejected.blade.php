<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Account Rejected – {{ config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#e8eef3; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 15px; line-height: 1.5; color: #1a365d;">
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#e8eef3;">
    <tr>
      <td style="padding: 32px 16px;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto; background-color:#ffffff; border: 1px solid #cbd5e0;">
          <!-- Header -->
          <tr>
            <td style="background-color: #0B558F; padding: 20px 28px;">
              <p style="margin: 0; font-size: 17px; font-weight: 700; color: #ffffff;">THE MID-TASK APP</p>
              <p style="margin: 4px 0 0 0; font-size: 11px; color: rgba(255,255,255,0.85);">Midsalip Integrated Digital Task and Administrative Synchronization Kit</p>
            </td>
          </tr>
          <!-- Content -->
          <tr>
            <td style="padding: 28px;">
              <p style="margin: 0 0 16px 0; font-size: 15px; color: #2d3748;">Dear {{ $name }},</p>
              <p style="margin: 0 0 20px 0; font-size: 15px; color: #2d3748;">Your account registration has been reviewed by the Central Administrative Officer and was not approved at this time.</p>
              
              @if(!empty($remarks))
              <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 20px 0;">
                <tr>
                  <td style="background-color: #fff5f5; border-left: 4px solid #e53e3e; padding: 16px 20px;">
                    <p style="margin: 0 0 4px 0; font-size: 12px; color: #718096; font-weight: 600;">Rejection remarks</p>
                    <p style="margin: 0; font-size: 15px; color: #2d3748;">{{ $remarks }}</p>
                  </td>
                </tr>
              </table>
              @endif
              
              <p style="margin: 20px 0 0 0; font-size: 15px; color: #4a5568;">If you have questions or need further clarification, please contact your administrator.</p>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="border-top: 1px solid #e2e8f0; padding: 16px 28px; font-size: 12px; color: #718096;">
              <p style="margin: 0;">{{ config('app.name') }} — Official communication. Do not reply to this email.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>