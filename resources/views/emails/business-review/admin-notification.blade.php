<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>New Business Review Application</title>
<style>
  body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
  .header { background: #2d3748; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; }
  .content { background: #fff; padding: 24px; border: 1px solid #e0e0e0; }
  .field { margin: 10px 0; padding: 8px; background: #f7f7f7; border-left: 3px solid #4a90e2; }
  .label { font-weight: bold; font-size: 12px; color: #666; text-transform: uppercase; }
  .value { font-size: 15px; }
  .footer { background: #f0f0f0; padding: 12px; font-size: 12px; color: #666; border-radius: 0 0 8px 8px; text-align: center; }
  a.btn { display: inline-block; background: #4a90e2; color: #fff; padding: 10px 20px; border-radius: 4px; text-decoration: none; margin-top: 16px; }
</style>
</head>
<body>
<div class="header"><h2 style="margin:0;">New Business Review Application</h2></div>
<div class="content">
  <p>A new business review application has been submitted.</p>
  <div class="field"><div class="label">Applicant</div><div class="value">{{ $review->full_name }}</div></div>
  <div class="field"><div class="label">Email</div><div class="value">{{ $review->email }}</div></div>
  @if($review->phone)<div class="field"><div class="label">Phone</div><div class="value">{{ $review->phone }}</div></div>@endif
  <div class="field"><div class="label">Business Name</div><div class="value">{{ $review->business_name }}</div></div>
  @if($review->business_industry)<div class="field"><div class="label">Industry</div><div class="value">{{ $review->business_industry }}</div></div>@endif
  @if($review->business_stage)<div class="field"><div class="label">Stage</div><div class="value">{{ $review->business_stage }}</div></div>@endif
  @if($review->business_description)<div class="field"><div class="label">Business Description</div><div class="value">{{ $review->business_description }}</div></div>@endif
  @if($review->main_challenges)<div class="field"><div class="label">Main Challenges</div><div class="value">{{ $review->main_challenges }}</div></div>@endif
  @if($review->business_goals)<div class="field"><div class="label">Goals</div><div class="value">{{ $review->business_goals }}</div></div>@endif
  <div class="field"><div class="label">Preferred Contact</div><div class="value">{{ $review->preferred_contact ?? 'email' }}</div></div>
  <div class="field"><div class="label">Submitted</div><div class="value">{{ $review->created_at->format('d M Y, H:i') }}</div></div>
  <a class="btn" href="{{ config('app.frontend_url', config('app.url')) }}/admin/business-review/{{ $review->uuid }}">View in Admin</a>
</div>
<div class="footer">&copy; {{ date('Y') }} {{ config('app.name') }}</div>
</body>
</html>
