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
        'DATABASE_HOST',
        'DATABASE_USER',
        'DATABASE_PASS',
        'DATABASE_NAME',
        'SOURCE_PATH',
        'DESTINATION_PATH',
        'PRESTASHOP_VERSION',
    ];

    public static $configFile = PRESTA_OPS_CONFIG_FILE_NAME;
    public static $dotenvPath = PRESTA_OPS_ROOT_DIR;
    public static $credentials;
    public static $hash;
    public static $fileTransferProcessId;

    public static function run($args = null)
    {
        self::checkVariables();

        self::rsyncFiles(self::$credentials);

        self::copyDatabase(self::$credentials);

        self::configureSite(self::$credentials);
        
        self::checkForCompletion(self::$credentials);
    }

    public static function checkVariables()
    {
        Messenger::info("Checking migration variables...");

        if (file_exists(PRESTA_OPS_ROOT_DIR.PRESTA_OPS_CONFIG_FILE_NAME)) {
            Messenger::info('File ' . PRESTA_OPS_ROOT_DIR.PRESTA_OPS_CONFIG_FILE_NAME . ' exists');
            $dotenv = Dotenv::createImmutable(PRESTA_OPS_ROOT_DIR, PRESTA_OPS_CONFIG_FILE_NAME);
            self::$credentials = $credentials = $dotenv->load();
        }

        foreach (self::$requiredVariables as $value) {
            if (!isset($credentials[$value])) {
                Messenger::info("$value is not set.");

                $credentials[$value] = self::prompt("Enter $value");

                Migration::storeEnvValues($credentials);
            }
        }

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

        exec(
            "ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} \"stat {$credentials['SOURCE_PATH']}/app/config/parameters.php\"",
            $output,
            $returnCode
        );

        if ($returnCode === 0) {
            Messenger::success("parameters.php was found");
        } else {
            Messenger::warning("parameters.php was not found");
            $credentials['SOURCE_PATH'] = self::prompt("Enter SOURCE_PATH");
            Migration::setEnvValue('SOURCE_PATH', $credentials['SOURCE_PATH']);
        }

        // Test DB connection using exec() with credentials from the array
        Messenger::info("Attempting database connection to {$credentials['DATABASE_HOST']}...");


        $dbTestCommand = "ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} 'mysql -h {$credentials['DATABASE_HOST']} -u {$credentials['DATABASE_USER']} -p'{$credentials['DATABASE_PASS']}' -D {$credentials['DATABASE_NAME']} -se \"SELECT * FROM ps_shop_url;\"'";
        exec($dbTestCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Messenger::danger("Database connection failed: " . implode("\n", $output));
        } else {
            foreach ($output as $line) {
                Messenger::success($line);
            }
        }

        Messenger::success("Database connection established successfully.");
    }

    public static function rsyncFiles($credentials)
    {
        $credentials = self::$credentials;

        // Proceed with migration steps
        Messenger::info("Starting migration steps...");

        // Rsync command with background execution
        $hash = self::$hash = md5(time());
        
        exec("rm -rf {$credentials['DESTINATION_PATH']}/admin813ejh3uz");

        $fileCopyCommand = "nohup rsync -avz {$credentials['SSH_USER']}@{$credentials['SSH_HOST']}:{$credentials['SOURCE_PATH']}/admin813ejh3uz {$credentials['DESTINATION_PATH']} > /dev/null 2>&1 & echo $!";
        
        // Execute the command and capture process ID (PID)
        exec($fileCopyCommand, $fileOutput, $fileReturnCode);

        if ($fileReturnCode !== 0) {
            Messenger::danger("Failed to start file transfer process.");
        }

        // Get the process ID (PID) of rsync
        self::$fileTransferProcessId = $fileTransferProcessId = $fileOutput[0] ?? null;

        if (!$fileTransferProcessId) {
            Messenger::warning("Failed to start file transfer.");
            Messenger::warning("$fileOutput");
        } else {
            Messenger::success("File transfer started in the background. Process ID: $fileTransferProcessId");
        }
    }

    public static function copyDatabase($credentials)
    {
    }

    public static function configureSite($credentials)
    {

    }

    public static function checkForCompletion($credentials)
    {
        $counter = 0;
        if (!self::$fileTransferProcessId) {
            Messenger::danger("No file transfer process ID found.");
            return false;
        }

        while (
            self::isProcessRunning(self::$fileTransferProcessId)
        ) {
            echo "\033[A\033[2K";
            Messenger::info("\rStill checking transfer... " . $counter);
            $counter++;
            sleep(1);
        }

        // Once loop exits, rsync is finished
        Messenger::success("File transfer process ".self::$fileTransferProcessId." has completed successfully!");
        return true;
    }

    /**
     * Check if a process is still running
     */
    private static function isProcessRunning($pid)
    {
        exec("ps -p $pid", $output, $returnCode);

        return $returnCode === 0;
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
    private static function storeEnvValues($credentials)
    {
        $lines = '';
        foreach ($credentials as $key => $value) {
            $lines .= "{$key}=\"{$value}\"\n";
        }
        
        file_put_contents(PRESTA_OPS_ROOT_DIR.PRESTA_OPS_CONFIG_FILE_NAME, $lines);
    }
}
