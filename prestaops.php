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
    if (isset($options[1])) {
        if ($options[1] == '--config') {
            return Migration::checkVariables();
        }

        if ($options[1] == '--show-variables') {
            echo "Are you sure you want to display all variables? This will display passwords, too. (yes/no): ";
            $handle = fopen("php://stdin", "r");
            $response = trim(fgets($handle));
            fclose($handle);

            if (strtolower($response) !== 'yes') {
                Messenger::danger("Operatie afgebroken.");
            }

            return Migration::getVariables();
        }
    }
    return Migration::run();
}

Messenger::danger('Command not found.');
