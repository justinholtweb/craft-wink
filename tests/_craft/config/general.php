<?php

return [
    'devMode' => true,
    // Craft derives the request's cookieValidationKey from this, and the
    // assignment service reads/writes visitor cookies.
    'securityKey' => 'wink-test-security-key',
];
