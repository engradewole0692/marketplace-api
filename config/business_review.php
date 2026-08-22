<?php

return [
    'admin_email' => env('BUSINESS_REVIEW_ADMIN_EMAIL', env('CMS_ADMIN_INBOX_EMAIL', env('ADMIN_EMAIL'))),
];
