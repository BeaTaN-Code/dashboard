<?php
require 'vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$vapid = VAPID::createVapidKeys();

print_r($vapid);
