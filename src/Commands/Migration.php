<?php

namespace PrestaOps\Commands;

use PrestaOps\Tools\Messenger;
use Dotenv\Dotenv;
use mysqli;

class Migration
{
    public static $requiredVariables = [
        'SSH_HOST',
        'SSH_USER',
        'SSH_PASS',
        'DB_HOST',
        'DB_USER',
        'DB_PASS',
        'DB_NAME',
        'SOURCE_PATH',
        'DESTINATION_PATH',
        'PRESTASHOP_VERSION',
    ];

    public static $dotenvPath = PRESTA_OPS_ROOT_DIR;

    public static function run($args = null)
    {
        Messenger::info("Checking migration variables   ...");

        $dotenv = Dotenv::createImmutable(self::$dotenvPath);
        $dotenv->load();

        $credentials = [];

        foreach (self::$requiredVariables as $value) {
            if (!isset($_ENV[$value])) {
                $credentials[$value] = self::prompt("Enter $value");
                Migration::setEnvValue($value, $credentials[$value]);
            } else {
                $credentials[$value] = $_ENV[$value];
            }
        }

        var_dump($credentials);

        exec(
            "ssh -o BatchMode=yes -o StrictHostKeyChecking=no {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} 'whoami' 2>&1",
            $output,
            $returnCode
        );

        if ($returnCode === 0) {
            Messenger::success("SSH Connection Successful!");
            Messenger::success("`whoami` determined successfully: " . implode("\n", $output));
        } else {
            Messenger::danger("SSH Connection Failed!\n");
            Messenger::danger("Error: " . implode("\n", $output) . "\n");
        }

        // Test DB connection using exec() with credentials from the array
        Messenger::info("Attempting database connection to {$credentials['DB_HOST']}...");

        $dbTestCommand = "mysqladmin ping -h {$credentials['DB_HOST']} -u {$credentials['DB_USER']} --password='{$credentials['DB_PASS']}' 2>&1";
        exec($dbTestCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Messenger::danger("Database connection failed: " . implode("\n", $output));
            exit(1);
        }

        Messenger::success("Database connection established successfully.");

        // If everything is good, write to .env
        Messenger::success(".env file updated with new credentials.");

        // Proceed with migration steps
        Messenger::info("Starting migration steps...");

        // Example: Copy files from source to destination
        $fileCopyCommand = "scp -r {$credentials['SSH_USER']}@{$credentials['SSH_HOST']}:{$credentials['SOURCE_PATH']} {$credentials['DESTINATION_PATH']}";
        exec($fileCopyCommand, $fileOutput, $fileReturnCode);

        if ($fileReturnCode !== 0) {
            Messenger::danger("File transfer failed: " . implode("\n", $fileOutput));
            exit(1);
        }

        Messenger::success("Files successfully transferred to {$credentials['DESTINATION_PATH']}.");

        // Finalizing migration
        Messenger::success("Migration completed successfully!");
    }

    /**
     * Prompt the user for input in the command line.
     *
     * @param  string  $message   The prompt message
     * @param  bool    $hidden    Whether to hide input (for passwords)
     * @return string  The user input
     */
    private static function prompt($message, $hidden = false)
    {
        // If you want to hide password input in a cross-platform way,
        // consider using a library (e.g. symfony/console).
        // For simplicity, we'll just show the input in this example,
        // but we'll demonstrate how you *could* hide it on *nix systems.
        
        echo $message . ": ";

        // Attempt to hide input on *nix if $hidden = true
        if ($hidden && DIRECTORY_SEPARATOR !== '\\') {
            // Stty trick only works on Unix-like systems
            system('stty -echo');
            $value = rtrim(fgets(STDIN), "\n");
            system('stty echo');
            echo "\n"; // move to a new line after typed password
        } else {
            // On Windows or for non-hidden input, just read normally
            $value = rtrim(fgets(STDIN), "\n");
        }

        return $value;
    }

    /**
     * Write credentials to .env file.
     * 
     * If you already have a .env file, you might want to parse it,
     * update only these keys, and rewrite. This example overwrites them.
     */
    // private static function writeEnvFile(array $credentials)
    // {
    //     $envPath = PRESTA_OPS_ROOT_DIR . '.env';

    //     // If .env already exists, you may want to read it, remove old lines
    //     // for these keys, and then append or replace. Here, we’ll just
    //     // *append* for simplicity.

    //     // Build .env content
    //     $envContent = "";
    //     foreach ($credentials as $key => $value) {
    //         // Escape double quotes inside the value
    //         $escapedValue = addslashes($value);
    //         $envContent .= "{$key}=\"{$escapedValue}\"\n";
    //     }

    //     // You could do a full replace approach, e.g.:
    //     // 1. Read existing .env
    //     // 2. Remove lines containing each key
    //     // 3. Append new lines
    //     // For simplicity, this example just overwrites the file.

    //     file_put_contents($envPath, $envContent, LOCK_EX);

    //     Messenger::info("Credentials written to .env at: {$envPath}");
    // }

    /**
     * Set or update an environment variable in the .env file.
     *
     * @param string $key   The environment variable name
     * @param string $value The new value to set
     */
    private static function setEnvValue($key, $value)
    {
        // Load existing .env content
        $envLines = file_exists(self::$dotenvPath . '.env') ? file(self::$dotenvPath . '.env', FILE_IGNORE_NEW_LINES) : [];

        // Prepare key-value pair
        $newLine = "{$key}=\"{$value}\"";
        $updated = false;

        // Loop through file and replace if key exists
        foreach ($envLines as &$line) {
            if (strpos($line, "{$key}=") === 0) {
                $line = $newLine;
                $updated = true;
                break;
            }
        }

        // If key does not exist, append it
        if (!$updated) {
            $envLines[] = $newLine;
        }

        // Write back to .env file
        file_put_contents(self::$dotenvPath . '.env', implode("\n", $envLines) . "\n", LOCK_EX);
    }
}
