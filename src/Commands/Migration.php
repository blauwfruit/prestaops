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
        'SOURCE_DATABASE_HOST',
        'SOURCE_DATABASE_USER',
        'SOURCE_DATABASE_PASS',
        'SOURCE_DATABASE_NAME',
        'SOURCE_PATH',
        'DESTINATION_DATABASE_HOST',
        'DESTINATION_DATABASE_USER',
        'DESTINATION_DATABASE_PASS',
        'DESTINATION_DATABASE_NAME',
        'DESTINATION_PATH',
        'PRESTASHOP_VERSION',
    ];

    public static $configFile = PRESTA_OPS_CONFIG_FILE_NAME;
    public static $dotenvPath = PRESTA_OPS_ROOT_DIR;
    public static $credentials;
    public static $fileTransferProcessId;
    public static $databaseMigrationProcessId;
    public static $tablesToIgnore = [
        'ps_connections',
        'ps_connections_page',
        'ps_connections_source',
        'ps_log',
        'ps_guest',
        'ps_mail',
        'ps_smarty_cache',
        'ps_statssearch',
        'ps_search_index',
    ];

    public static $isSynchronous = false;
    public static $isDatabaseOnly = false;
    public static $isFilesOnly = false;

    public static function run($args = null)
    {
        self::checkVariables();

        self::rsyncFiles(self::$credentials);

        self::copyDatabase(self::$credentials);

        self::configureSite(self::$credentials);
        
        self::checkForCompletion(self::$credentials);
        if (!self::isSynchronous()) {
            self::checkForCompletion(self::$credentials);
        }
    }

    /**
     * Return the sync mode
     **/
    public static function isSynchronous()
    {
        return self::$isSynchronous;
    }

    /**
     * If you want to see what is happening, turn this on, this way  bash command is directly executing giving you the 
     * results rightaway, that can be good for debuggin
     **/
    public static function enableSynchronousMode()
    {
        self::$isSynchronous = true;
    }

    public function setDatabaseOnly()
    {
        self::$isDatabaseOnly = true;
    }

    public function setFilesOnly()
    {
        self::$isFilesOnly = true;
    }

    public static function getVariables($print = false)
    {
        if (file_exists(PRESTA_OPS_ROOT_DIR.PRESTA_OPS_CONFIG_FILE_NAME)) {
            Messenger::info('File ' . PRESTA_OPS_ROOT_DIR.PRESTA_OPS_CONFIG_FILE_NAME . ' exists');
            $dotenv = Dotenv::createImmutable(PRESTA_OPS_ROOT_DIR, PRESTA_OPS_CONFIG_FILE_NAME);
            self::$credentials = $dotenv->load();

            foreach (self::$credentials as $key => $value) {
                Messenger::info("$key: $value");
            }

            return self::$credentials;
        }
    }

    public static function checkVariables()
    {
        Messenger::info("Checking migration variables...");

        $credentials = self::getVariables();

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
            $statOutput,
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
        Messenger::info("Attempting database connection to {$credentials['SOURCE_DATABASE_HOST']}...");

        $dbTestCommand = "ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} \"mysql -h {$credentials['SOURCE_DATABASE_HOST']} -u {$credentials['SOURCE_DATABASE_USER']} -p{$credentials['SOURCE_DATABASE_PASS']} -D {$credentials['SOURCE_DATABASE_NAME']} -se \\\"SELECT CONCAT(' - ', domain), IF(active, 'Active', 'Not active') AS status FROM ps_shop_url;\\\"\"";
        exec($dbTestCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Messenger::danger("Database connection failed: " . implode("\n", $output));
        } else {
            Messenger::success("Database connection established successfully. Shop URL's:");
            foreach ($output as $line) {
                Messenger::success($line);
            }
            
        }

    }

    public static function rsyncFiles($credentials)
    {
        if (self::$isDatabaseOnly) {
            return;
        }

        $credentials = self::$credentials;

        // Proceed with migration steps
        Messenger::info("Starting migration steps...");

        // Rsync command with background execution
        $fileCopyCommand = "nohup rsync -avz --exclude='var/' --exclude='img/tmp/' --exclude='themes/*/cache/' --exclude='app/config/parameters.php' {$credentials['SSH_USER']}@{$credentials['SSH_HOST']}:{$credentials['SOURCE_PATH']}/* {$credentials['DESTINATION_PATH']} > /dev/null 2>&1 & echo $!";
        
        // Execute the command and capture process ID (PID)
        exec($fileCopyCommand, $fileOutput, $fileReturnCode);
        $command = "rsync -avz --exclude='var/' --exclude='img/tmp/' --exclude='themes/*/cache/' --exclude='app/config/parameters.php' {$credentials['SSH_USER']}@{$credentials['SSH_HOST']}:{$credentials['SOURCE_PATH']}/* {$credentials['DESTINATION_PATH']}";
        if (self::isSynchronous()) {
            self::runCommandLive($command);
        } else {
            $fileCopyCommand = "nohup $command > /dev/null 2>&1 & echo $!";

            exec($fileCopyCommand, $fileOutput, $fileReturnCode);

            if ($fileReturnCode !== 0) {
                Messenger::danger("Failed to start file transfer process.");
            }

            self::$fileTransferProcessId = $fileTransferProcessId = $fileOutput[0] ?? null;

            if (!$fileTransferProcessId) {
                Messenger::warning("Failed to start file transfer.");
                Messenger::warning("$fileOutput");
            } else {
                Messenger::success("File transfer started in the background. Process ID: $fileTransferProcessId");
                
                foreach ($fileOutput as $output) {
                    var_dump($output);
                }
            }
        }
    }

    /**
     * Copy database to the destination server
     * 
     * - Excludes tables for effeciency from self::$tablesToIgnore
     * - Use mysqldump in combination with exec() and nohup for background processing 
     * - Use the most efficient way of exporting, compressing and transfering the file
     * - Removes the file from the source server once it is transferred
     * */
    public static function copyDatabase($credentials)
    {
        Messenger::info("Starting database migration in the background...");
        if (self::$isFilesOnly) {
            return;
        }

        // List of tables to ignore
        $ignoreTables = "";
        if (!empty(self::$tablesToIgnore)) {
            foreach (self::$tablesToIgnore as $table) {
                $ignoreTables .= " --ignore-table={$credentials['SOURCE_DATABASE_NAME']}.$table";
            }
        }

        // Remote and local dump file paths
        $remoteDumpFile = "{$credentials['SOURCE_PATH']}/{$credentials['SOURCE_DATABASE_NAME']}.sql.gz";
        $localDumpFile = "{$credentials['DESTINATION_DATABASE_NAME']}.sql.gz";

        // Define the full Bash script
        $bashScript = <<<BASH
            #!/bin/bash
            echo "Starting database migration..." >> /tmp/db_migration.log

            # Export the database and compress it
            # ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} "mysqldump -h {$credentials['SOURCE_DATABASE_HOST']} -u {$credentials['SOURCE_DATABASE_USER']} -p'{$credentials['SOURCE_DATABASE_PASS']}' $ignoreTables {$credentials['SOURCE_DATABASE_NAME']} | gzip > $remoteDumpFile"

            # Transfer the database dump file from remote to local
            # ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} "stat $remoteDumpFile"
            
            # scp -C {$credentials['SSH_USER']}@{$credentials['SSH_HOST']}:"{$remoteDumpFile}" {$localDumpFile}

            gunzip -c {$localDumpFile} | mysql -h {$credentials['DESTINATION_DATABASE_HOST']} -u {$credentials['DESTINATION_DATABASE_USER']} -p'{$credentials['DESTINATION_DATABASE_PASS']}' {$credentials['DESTINATION_DATABASE_NAME']}
        BASH;

            // # Import the database into the destination server
            // gunzip -c $localDumpFile | mysql -h {$credentials['DESTINATION_DATABASE_HOST']} -u {$credentials['DESTINATION_DATABASE_USER']} -p'{$credentials['DESTINATION_DATABASE_PASS']}' {$credentials['DESTINATION_DATABASE_NAME']}
            // echo "Database import completed." >> /tmp/db_migration.log

            // # Remove the database dump file from the remote server
            // ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} "rm -f $remoteDumpFile"
            // echo "Remote dump file removed." >> /tmp/db_migration.log

            // echo "Database migration completed successfully!" >> /tmp/db_migration.log

        // Store the Bash script in a temporary file
        $bashFile = "/tmp/db_migration.sh";
        file_put_contents($bashFile, $bashScript);
        chmod($bashFile, 0755); // Make it executable

        // Execute the script in the background using nohup
        // $nohupCommand = "nohup $bashFile > /dev/null 2>&1 & echo $!";
        $nohupCommand = "bash $bashFile";
        exec($nohupCommand, $output, $returnCode);

        foreach ($output as $out) {
            Messenger::info($out);
        }
        if (self::isSynchronous()) {
            Messenger::info("Starting database migration...");
            self::runCommandLive($bashFile);
        } else {
            Messenger::info("Starting database migration in the background...");
            // Execute the script in the background using nohup
            $nohupCommand = "nohup $bashFile > /dev/null 2>&1 & echo $!";

            exec($nohupCommand, $output, $returnCode);

            self::$databaseMigrationProcessId = $output[0] ?? null;

            if (!self::$databaseMigrationProcessId) {
                Messenger::danger("Failed to start database migration.");
            } else {
                Messenger::success("Database migration started in the background. Process ID: " . self::$databaseMigrationProcessId);
            }
        }
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
            && self::isProcessRunning(self::$databaseMigrationProcessId)
        ) {
            var_dump([
                self::$fileTransferProcessId => self::isProcessRunning(self::$fileTransferProcessId),
                self::$databaseMigrationProcessId =>self::isProcessRunning(self::$databaseMigrationProcessId) 
            ]
            );
            // Messenger::removeLine();
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

    /**
     * Executes a shell command and displays its output live.
     *
     * @param string $command The shell command to execute.
     * @return int|false The exit code of the process, or false on failure.
     */
    public static function runCommandLive($command)
    {
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin (not used)
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            return false;
        }

        // Close the unused stdin pipe.
        fclose($pipes[0]);

        // Set stdout and stderr to non-blocking mode.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Loop to fetch and output command output live.
        while (true) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout !== false && strlen($stdout) > 0) {
                echo $stdout;
                flush(); // Ensure immediate output
            }

            if ($stderr !== false && strlen($stderr) > 0) {
                echo $stderr;
                flush();
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            // Short sleep to reduce CPU usage
            usleep(100000); // 100ms
        }

        // Close the pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Get and return the exit code of the process.
        $exitCode = proc_close($process);
        return $exitCode;
    }
}
