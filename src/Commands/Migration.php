<?php

namespace PrestaOps\Commands;

use PrestaOps\Tools\Messenger;
use PrestaOps\Tools\CommandLineParser;
use PrestaOps\Help\MigrationHelp;
use Dotenv\Dotenv;

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
        'DATABASE_PREFIX',
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

    /**
     * Whether to run commands synchronously
     * When running synchronously, we see the output of commands
     * immediately, which can be useful for debugging
     */
    public static $isSynchronous = false;
    public static $isDatabaseOnly = false;
    public static $isFilesOnly = false;
    public static $isConfigureOnly = false;

    public static $stagingUrlSuffix;
    public static $disableSSL = false;

    /**
     * Show help information for the migration command
     */
    public static function showHelp()
    {
        MigrationHelp::show();
    }

    public static function run($args = null)
    {
        if (isset($args['help'])) {
            MigrationHelp::show();
            return;
        }

        self::checkVariables();
        self::rsyncFiles(self::$credentials);
        self::copyDatabase(self::$credentials);
        self::configureSite(self::$credentials);
        
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
     * Enable synchronous mode (--sync)
     * This will show command output in real-time
     */
    public static function enableSynchronousMode()
    {
        self::$isSynchronous = true;
    }

    public static function setDatabaseOnly()
    {
        self::$isDatabaseOnly = true;
    }

    public static function setFilesOnly()
    {
        self::$isFilesOnly = true;
    }

    public static function setConfigureOnly()
    {
        self::$isConfigureOnly = true;
    }

    public static function setStagingUrlSuffix($stagingUrlSuffix)
    {
        // Remove any leading/trailing dots and spaces
        $stagingUrlSuffix = trim($stagingUrlSuffix, '. ');

        if (!self::isValidDomain($stagingUrlSuffix)) {
            Messenger::warning("Domain $stagingUrlSuffix does not seem to be a valid domain.");
            return;
        }

        // Check if the suffix is already present
        if (self::$stagingUrlSuffix === $stagingUrlSuffix) {
            Messenger::info("Staging URL suffix '$stagingUrlSuffix' is already configured.");
            return;
        }

        self::$stagingUrlSuffix = $stagingUrlSuffix;
        Messenger::success("Domain $stagingUrlSuffix is configured as a staging domain suffix.");
    }

    public static function isValidDomain(string $domain): bool
    {
        return (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
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

        self::executeSqlInSourceDatabase("SELECT CONCAT(' - ', domain), IF(active, 'Active', 'Not active') AS status FROM {$credentials['DATABASE_PREFIX']}shop_url;");

    }

    public static function executeSqlInSourceDatabase($query)
    {
        $credentials = self::$credentials;

        $dbTestCommand = "ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} \"mysql -h {$credentials['SOURCE_DATABASE_HOST']} -u {$credentials['SOURCE_DATABASE_USER']} -p{$credentials['SOURCE_DATABASE_PASS']} -D {$credentials['SOURCE_DATABASE_NAME']} -se \\\"$query\\\"\"";
        exec($dbTestCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Messenger::warning("Database query failed: ");

            foreach ($output as $line) {
                Messenger::warning($line);
            }

            Messenger::danger("Stopping process.");
        } else {
            Messenger::success("Database query succeeded:");

            foreach ($output as $line) {
                Messenger::success($line);
            }   
        }
    }

    public static function rsyncFiles($credentials)
    {
        if (self::$isDatabaseOnly) {
            Messenger::info("Overslaan van bestandsoverdracht (database-only modus)");
            return;
        }

        if (self::$isConfigureOnly) {
            Messenger::info("Overslaan van bestandsoverdracht (configure-only modus)");
            return;
        }

        $credentials = self::$credentials;

        // Proceed with migration steps
        Messenger::info("Starting migration steps...");

        // Rsync command with background execution and permission preservation
        $command = "rsync -avzp --perms --chmod=D2775,F664 --exclude='var/' --exclude='img/tmp/' --exclude='themes/*/cache/' --exclude='app/config/parameters.php' {$credentials['SSH_USER']}@{$credentials['SSH_HOST']}:{$credentials['SOURCE_PATH']}/* {$credentials['DESTINATION_PATH']}";
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
        if (self::$isFilesOnly) {
            Messenger::info("Overslaan van database migratie (files-only modus)");
            return;
        }

        if (self::$isConfigureOnly) {
            Messenger::info("Overslaan van database migratie (configure-only modus)");
            return;
        }

        // List of tables to ignore
        $ignoreTables = "";
        if (!empty(self::$tablesToIgnore)) {
            foreach (self::$tablesToIgnore as $table) {
                $ignoreTables .= " --ignore-table={$credentials['SOURCE_DATABASE_NAME']}.$table";
            }
        }

        Messenger::info("Database migratie configuratie:");
        Messenger::info("Remote database: {$credentials['SOURCE_DATABASE_NAME']}");
        Messenger::info("Local database: {$credentials['DESTINATION_DATABASE_NAME']}");
        Messenger::info("Huidige directory: " . getcwd());

        // Define the command for direct database transfer
        $directTransferCommand = "ssh {$credentials['SSH_USER']}@{$credentials['SSH_HOST']} " .
                              "'mysqldump -h {$credentials['SOURCE_DATABASE_HOST']} " .
                              "-u {$credentials['SOURCE_DATABASE_USER']} " .
                              "-p\"{$credentials['SOURCE_DATABASE_PASS']}\" " .
                              "$ignoreTables {$credentials['SOURCE_DATABASE_NAME']}' | " .
                              "mysql -h {$credentials['DESTINATION_DATABASE_HOST']} " .
                              "-u {$credentials['DESTINATION_DATABASE_USER']} " .
                              "-p\"{$credentials['DESTINATION_DATABASE_PASS']}\" " .
                              "{$credentials['DESTINATION_DATABASE_NAME']}";

        if (self::isSynchronous()) {
            Messenger::info("Directe database overdracht starten...");
            self::runCommandLive($directTransferCommand);
            Messenger::success("Database migratie voltooid");
        } else {
            Messenger::info("Directe database overdracht starten in de achtergrond...");
            
            $backgroundCommand = "nohup $directTransferCommand > /dev/null 2>&1 & echo $!";
            exec($backgroundCommand, $output, $returnCode);

            self::$databaseMigrationProcessId = $output[0] ?? null;

            if (!self::$databaseMigrationProcessId) {
                Messenger::danger("Kan database migratie niet starten.");
            } else {
                Messenger::success("Database migratie gestart in de achtergrond. Proces ID: " . self::$databaseMigrationProcessId);
            }
        }
    }

    public static function configureSite($credentials)
    {
        if (self::$isFilesOnly) {
            Messenger::info("Skipping site configuration (files-only mode)");
            return;
        }

        if (!self::$stagingUrlSuffix && !self::$isConfigureOnly) {
            Messenger::info("No staging URL suffix configured, skipping site configuration");
            return;
        }

        if (!self::$stagingUrlSuffix && self::$isConfigureOnly) {
            Messenger::warning("No staging URL suffix configured.");
            Messenger::danger("In configure-only mode, a staging URL suffix must be provided.");
        }

        // Get current domains
        $selectQuery = "SELECT id_shop_url, domain, domain_ssl FROM {$credentials['DATABASE_PREFIX']}shop_url";
        $domains = self::executeSqlInDestinationDatabase($selectQuery, true);

        if (!$domains) {
            Messenger::warning("No domains found to update.");
            return;
        }

        $stagingSuffix = trim(self::$stagingUrlSuffix, '.');

        foreach ($domains as $domain) {
            try {
                // Check if the staging suffix is already present in the domain
                if (strpos($domain['domain'], $stagingSuffix) !== false) {
                    Messenger::info("Domain {$domain['domain']} already contains staging suffix, skipping.");
                    continue;
                }

                // Get the base domain by removing any existing staging suffixes and replacing dots
                $baseDomain = preg_replace('/\.' . preg_quote($stagingSuffix, '/') . '$/', '', $domain['domain']);
                // Remove the dot between domain and TLD
                $baseDomain = str_replace('.', '', $baseDomain);
                
                // Create new domain by appending the staging suffix
                $newDomain = $baseDomain . '.' . $stagingSuffix;

                $updateQuery = "UPDATE {$credentials['DATABASE_PREFIX']}shop_url 
                              SET domain = :domain,
                                  domain_ssl = :domain_ssl,
                                  physical_uri = '/',
                                  virtual_uri = ''";

                // Als SSL uitgeschakeld moet worden
                if (self::$disableSSL) {
                    try {
                        // Update SSL configuratie eerst
                        $disableSSLQuery = "UPDATE {$credentials['DATABASE_PREFIX']}configuration 
                                          SET value = '0' 
                                          WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE', 'PS_COOKIE_SAMESITE')";
                        
                        self::executePreparedStatement(
                            $disableSSLQuery,
                            []
                        );
                        
                        Messenger::info("SSL configuration is updated in PrestaShop");
                    } catch (\Exception $e) {
                        Messenger::warning("Error updating SSL configuration: " . $e->getMessage());
                    }
                }

                $updateQuery .= " WHERE id_shop_url = :id";

                $params = [
                    ':domain' => $newDomain,
                    ':domain_ssl' => $newDomain,
                    ':id' => $domain['id_shop_url']
                ];

                self::executePreparedStatement($updateQuery, $params);
                Messenger::success("Domain updated from {$domain['domain']} to $newDomain");
                
                if (self::$disableSSL) {
                    Messenger::info("SSL is disabled for domain: $newDomain");
                }
            } catch (\Exception $e) {
                Messenger::warning("Error updating domain {$domain['domain']}: " . $e->getMessage());
            }
        }

        // Always regenerate .htaccess after domain updates
        Messenger::info("Regenerating .htaccess file...");
        self::regenerateHtaccess($credentials);
    }

    private static function executePreparedStatement($query, $params)
    {
        $credentials = self::$credentials;

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $credentials['DESTINATION_DATABASE_HOST'],
                $credentials['DESTINATION_DATABASE_NAME']
            );
            
            $pdo = new \PDO(
                $dsn,
                $credentials['DESTINATION_DATABASE_USER'],
                $credentials['DESTINATION_DATABASE_PASS'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
                ]
            );

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            
            return true;
        } catch (\PDOException $e) {
            throw new \Exception("Database fout: " . $e->getMessage());
        }
    }

    public static function executeSqlInDestinationDatabase($query, $returnResults = false)
    {
        $credentials = self::$credentials;

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $credentials['DESTINATION_DATABASE_HOST'],
                $credentials['DESTINATION_DATABASE_NAME']
            );
            
            $pdo = new \PDO(
                $dsn,
                $credentials['DESTINATION_DATABASE_USER'],
                $credentials['DESTINATION_DATABASE_PASS'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
                ]
            );

            $result = $pdo->query($query);
            Messenger::success("Database query succesvol uitgevoerd.");

            if ($returnResults) {
                return $result->fetchAll();
            }

            // Als er resultaten zijn, toon deze
            if ($result !== false && !$returnResults) {
                while ($row = $result->fetch()) {
                    foreach ($row as $value) {
                        Messenger::success($value);
                    }
                }
            }

        } catch (\PDOException $e) {
            Messenger::warning("Database fout: " . $e->getMessage());
            Messenger::danger("Process gestopt. Regel " . __LINE__);
        }
    }

    public static function checkForCompletion($credentials)
    {
        if (self::$isConfigureOnly) {
            return;
        }

        $counter = 0;
        if (!self::$fileTransferProcessId) {
            Messenger::danger("Geen bestandsoverdracht proces ID gevonden.");
            return false;
        }

        while (
            self::isProcessRunning(self::$fileTransferProcessId)
            && self::isProcessRunning(self::$databaseMigrationProcessId)
        ) {
            // var_dump([
            //     self::$fileTransferProcessId => self::isProcessRunning(self::$fileTransferProcessId),
            //     self::$databaseMigrationProcessId =>self::isProcessRunning(self::$databaseMigrationProcessId) 
            // ]
            // );
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
    //     // for these keys, and then append or replace. Here, we'll just
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

    public static function setDisableSSL()
    {
        self::$disableSSL = true;
        Messenger::info("SSL will be disabled for local development.");
    }

    /**
     * Regenerate the .htaccess file for PrestaShop
     */
    public static function regenerateHtaccess($credentials)
    {
        Messenger::info("Regenerating .htaccess file using PrestaShop's native function...");

        try {
            // Laad PrestaShop's configuratie
            if (!defined('_PS_ROOT_DIR_')) {
                define('_PS_ROOT_DIR_', $credentials['DESTINATION_PATH']);
            }
            
            // Laad de benodigde PrestaShop bestanden
            $configPath = $credentials['DESTINATION_PATH'] . '/config/config.inc.php';
            if (!file_exists($configPath)) {
                throw new \Exception("PrestaShop config.inc.php not found at: $configPath");
            }

            // Bewaar de huidige working directory
            $originalDir = getcwd();
            
            // Verander naar PrestaShop directory voor het laden van de configuratie
            chdir($credentials['DESTINATION_PATH']);
            
            // Laad PrestaShop configuratie
            require_once $configPath;
            
            // Laad Tools class als deze nog niet beschikbaar is
            if (!class_exists('Tools')) {
                require_once _PS_ROOT_DIR_ . '/classes/Tools.php';
            }

            // Gebruik PrestaShop's native functie om .htaccess te genereren
            if (method_exists('Tools', 'generateHtaccess')) {
                \Tools::generateHtaccess();
                Messenger::success(".htaccess has been regenerated using PrestaShop's native function.");
            } else {
                throw new \Exception("PrestaShop Tools::generateHtaccess() method not found.");
            }

            // Herstel de originele working directory
            chdir($originalDir);

        } catch (\Exception $e) {
            Messenger::warning("Error regenerating .htaccess: " . $e->getMessage());
            
            // Fallback naar de template kopie als de native methode faalt
            Messenger::info("Falling back to template copy method...");
            
            try {
                if (file_exists("{$credentials['DESTINATION_PATH']}/config/htaccess.txt")) {
                    copy(
                        "{$credentials['DESTINATION_PATH']}/config/htaccess.txt",
                        "{$credentials['DESTINATION_PATH']}/.htaccess"
                    );
                    chmod("{$credentials['DESTINATION_PATH']}/.htaccess", 0644);
                    Messenger::success(".htaccess file has been regenerated from template.");
                }
            } catch (\Exception $fallbackError) {
                Messenger::danger("Fallback method also failed: " . $fallbackError->getMessage());
            }
        }
    }
}
