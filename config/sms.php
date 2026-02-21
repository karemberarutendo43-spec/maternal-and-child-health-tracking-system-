<?php
// Placeholder SMS sender. Replace with Econet/Africa's Talking/Clickatell gateway.
function sendSms(string $phone, string $message): bool
{
    // Log for now (so you can see it in PHP error log)
    error_log("[SMS] To: {$phone} | Message: {$message}");
    return true;
}
