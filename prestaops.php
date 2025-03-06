<?php

use PrestaOps\Commands\Audit;
use PrestaOps\Commands\Migration;
use PrestaOps\Tools\Messenger;

// Long options with no values
$longopts = [
    "audit",
    "migrate",
];

// Parse command-line arguments
$options = array_slice($argv, 1); // Skip the script name

if ($options[0] == 'audit') {
    return Audit::run();
}

if ($options[0] == 'migrate') {
    return Migration::run();
}

Messenger::danger('Command not found.');