<?php
require_once __DIR__ . "/_bootstrap.php";

api_ok([
    'service' => 'mch_system_api',
    'time' => date(DATE_ATOM),
]);

