<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Business Review Application Received</title>
<style>
  body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
  .header { background: #1a1a2e; color: #fff; padding: 24px; border-radius: 8px 8px 0 0; }
  .header h1 { margin: 0; font-size: 22px; }
  .content { background: #f9f9f9; padding: 24px; border: 1px solid #e0e0e0; }
  .footer { background: #f0f0f0; padding: 16px; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; text-align: center; }
  .detail-row { margin: 8px 0; }
  .label { font-weight: bold; color: #555; }
</style>
</head>
<body>
<div class="header">
  <h1>{{ config('app.name') }} — Business Review Application</h1>
</div>
<div class="content">
  <p>Dear {{ $review->full_name }},</p>
  <p>Thank you for submitting your business review application. We have received your request and our team will review it shortly.</p>
  <p>Here is a summary of what you submitted:</p>
  <div class="detail-row"><span class="label">Business Name:</span> {{ $review->business_name }}</div>
  @if($review->business_industry)<div class="detail-row"><span class="label">Industry:</span> {{ $review->business_industry }}</div>@endif
  @if($review->business_stage)<div class="detail-row"><span class="label">Stage:</span> {{ $review->business_stage }}</div>@endif
  <p>Our team will reach out to you via <strong>{{ $review->preferred_contact ?? 'email' }}</strong> within 2–5 business days.</p>
  <p>If you have any questions, please reply to this email.</p>
  <p>God bless your enterprise,<br><strong>The {{ config('app.name') }} Team</strong></p>
</div>
<div class="footer">
  <p>This email was sent to {{ $review->email }} because you submitted a business review application.</p>
  <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
</div>
</body>
</html>
