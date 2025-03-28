<?php

namespace PrestaOps\Tools;

use PrestaOps\Commands\Audit;
use PrestaOps\Commands\Migration;
use PrestaOps\Tools\Messenger;
use PrestaOps\Help\PrestaOpsHelp;

class CommandLineParser
{
    const AUDIT = 'audit';
    const MIGRATE = 'migrate';

    public static $action;
    public static $options = [];

    public static $availableCommands = [
        self::AUDIT => [
            '--modules',
            '--help',
        ],
        self::MIGRATE => [
            '--help',
            '--config',
            '--show-variables',
            '--sync',
            '--database-only',
            '--files-only',
            '--configure-only',
            '--staging-url-suffix',
            '--disable-ssl',
        ],
    ];

    public static function execute($argv)
    {
        // Check for global help flag
        if (count($argv) === 2 && $argv[1] === '--help') {
            PrestaOpsHelp::show();
            return;
        }

        self::setCommand($argv);

        if (self::isCommand(self::AUDIT)) {
            if (self::hasOption('--help')) {
                return Audit::showHelp();
            }
            return Audit::run();
        }

        if (self::isCommand(self::MIGRATE)) {
            if (self::hasOption('--help')) {
                return Migration::showHelp();
            }

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

            if (self::hasOption('--sync')) {
                Migration::enableSynchronousMode();
            }

            if (self::hasOption('--disable-ssl')) {
                Migration::setDisableSSL();
            }

            $stagingUrlSuffix = self::getOptionValue('--staging-url-suffix');
            if ($stagingUrlSuffix !== null) {
                Messenger::info("Staging URL Suffix: $stagingUrlSuffix");
                Migration::setStagingUrlSuffix($stagingUrlSuffix);
            }

            if (self::hasOption('--configure-only')) {
                if (!self::hasOption('--staging-url-suffix')) {
                    Messenger::danger('Staging URL suffix is not set.');
                }

                Migration::setConfigureOnly();
            }

            return Migration::run();
        }

        Messenger::danger('Command not found.');
    }

    /**
     * Checks if an option exists.
     *
     * @param string $optionName The option name to check.
     * @return bool True if the option exists, false otherwise.
     */
    public static function hasOption($optionName): bool
    {
        return isset(self::$options[$optionName]);
    }

    /**
     * Returns the value of the given option.
     *
     * @param string $optionName The name of the option.
     * @return mixed|null The option value or null if it isn't set.
     */
    public static function getOptionValue($optionName)
    {
        return self::$options[$optionName] ?? null;
    }

    public static function isCommand($action): bool
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

        // Reset options array
        self::$options = [];

        foreach ($command as $value) {
            if (substr($value, 0, 2) == '--') {
                // Check if the option includes a value (e.g. --option=value)
                if (strpos($value, '=') !== false) {
                    list($optionName, $optionValue) = explode('=', $value, 2);
                    self::$options[$optionName] = $optionValue;
                } else {
                    self::$options[$value] = true;
                }
            }
        }

        if (!isset(self::$availableCommands[self::$action])) {
            var_dump(self::$availableCommands, self::$action);
            Messenger::danger("Unknown command " . self::$action);
        }

        // Validate provided options against available commands.
        foreach (array_keys(self::$options) as $option) {
            if (in_array($option, self::$availableCommands[self::$action])) {
                Messenger::success("Option $option is available for " . self::$action);
            } else {
                Messenger::danger("Option $option is not available for " . self::$action);
            }
        }
    }
}