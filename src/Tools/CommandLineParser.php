<?php

namespace PrestaOps\Tools;

use PrestaOps\Commands\Audit;
use PrestaOps\Commands\Migration;
use PrestaOps\Tools\Messenger;

class CommandLineParser
{
    const AUDIT = 'audit';
    const MIGRATE = 'migrate';

    public static $action;
    public static $options;

    public static $availableCommands = [
        self::AUDIT => [
            '--modules',
        ],
        self::MIGRATE => [
            '--config',
            '--show-variables',
            '--enable-synchronous-mode',
            '--database-only',
        ],
    ];

    public static function execute($argv)
    {
        self::setCommand($argv);

        if (self::isCommand(self::AUDIT)) {
            return Audit::run();
        }

        if (self::isCommand(self::MIGRATE)) {
            if (self::hasOption('--config')) {
                return Migration::checkVariables();
            }

            if (self::hasOption('--database-only')) {
                Migration::setDatabaseOnly();
            }

            if (self::hasOption('--files-only')) {
                Migration::setFilesOnly();
            }

            if (self::hasOption('--show-variables')) {
                echo "Are you sure you want to display all variables? This will display passwords, too. (yes/no): ";
                $handle = fopen("php://stdin", "r");
                $response = trim(fgets($handle));
                fclose($handle);

                if (strtolower($response) !== 'yes') {
                    Messenger::danger("Operatie afgebroken.");
                }

                return Migration::getVariables();
            }

            if (self::hasOption('--enable-synchronous-mode')) {
               Migration::enableSynchronousMode();
            }

            return Migration::run();
        }

        Messenger::danger('Command not found.');
    }

    public static function hasOption($optionName) : bool
    {
        return in_array($optionName, self::$options);
    }

    public static function isCommand($action) : bool
    {
        return self::$action == $action;

    }

    public static function setCommand($argv)
    {
        $command = array_slice($argv, 1);

        if (isset($command[0])) {
            self::$action = $command[0];
        } else {
            Messenger::danger("Command not set.");
        }

        foreach ($command as $value) {
            if (substr($value, 0, 2) == '--') {
                self::$options[] = $value;
            }
        }

        if (!isset(self::$availableCommands[self::$action])) {
            var_dump(self::$availableCommands, self::$action);
            Messenger::danger("Unknown command " . self::$action);
        }

        foreach (self::$options as $option) {
            if (in_array($option, self::$availableCommands[self::$action])) {
                Messenger::success("Option $option is availble for " . self::$action);
            } else {
                Messenger::danger("Option $option is not availble for " . self::$action);
            }
        }
    }
}
