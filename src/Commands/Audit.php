<?php

namespace PrestaOps\Commands;

use PrestaOps\Tools\Messenger;
use PrestaOps\Help\AuditHelp;

class Audit
{
    /**
     * Show help information for the audit command
     */
    public static function showHelp()
    {
        AuditHelp::show();
    }

    public static function run($limit = null, $sliceStart = null, $sliceEnd = null)
    {
        if ($limit !== null) {
            Messenger::info("Scanning PrestaShop marketplace (limited to first $limit modules)...");
        } elseif ($sliceStart !== null && $sliceEnd !== null) {
            Messenger::info("Scanning PrestaShop marketplace (slice: modules $sliceStart to $sliceEnd)...");
        } else {
            Messenger::info("Scanning PrestaShop marketplace...");
        }
        
        $prestaShopRoot = getcwd();
        $paramatersFile = getcwd() . '/app/config/parameters.php';

        if (!file_exists($paramatersFile)) {    
            Messenger::danger("Not in PrestaShop root, cannot find parameters.php");
        }

        $prestaShopParameters = include($paramatersFile);

        if (!isset($prestaShopParameters['parameters']['database_host'])) {
            Messenger::danger('PrestaShop application was found, but does not seem to be configured. The parameters are not found inside app/config/parameters.php.');
        }

        // Check if a project path is provided
        // if ($argc < 2) {
        //     Messenger::danger("Usage: check_modules.php /path/to/prestashop/project\n");
        // }

        $modulesPath = $prestaShopRoot . '/modules';

        if (!is_dir($modulesPath)) {
            Messenger::danger("Modules directory not found in $projectPath");
        }

        function getModuleDetails($modulePath)
        {
            $mainFile = $modulePath . '/' . basename($modulePath) . '.php';

            if (!file_exists($mainFile)) {
                Messenger::warning("Skipping module " . basename($modulePath) . " - main file not found.");
                return null;
            }

            // Read file content instead of executing it
            $fileContent = file_get_contents($mainFile);

            // Extract module_key from __construct()
            preg_match('/\$this->module_key\s*=\s*[\'"]([^\'"]+)[\'"]/', $fileContent, $moduleKeyMatch);
            $moduleKey = isset($moduleKeyMatch[1]) ? $moduleKeyMatch[1] : null;

            // Extract module name from __construct()
            preg_match('/\$this->name\s*=\s*[\'"]([^\'"]+)[\'"]/', $fileContent, $moduleNameMatch);
            $moduleName = isset($moduleNameMatch[1]) ? $moduleNameMatch[1] : null;

            // Extract module name from __construct()
            preg_match('/\$this->author\s*=\s*[\'"]([^\'"]+)[\'"]/', $fileContent, $moduleNameMatch);
            $moduleAuthor = isset($moduleNameMatch[1]) ? $moduleNameMatch[1] : null;

            // Extract module name from __construct()
            preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]/', $fileContent, $moduleNameMatch);
            $moduleVersion = isset($moduleNameMatch[1]) ? $moduleNameMatch[1] : null;

            return [
                'module_key' => $moduleKey,
                'module_name' => $moduleName,
                'module_version' => $moduleVersion,
                'module_author' => $moduleAuthor
            ];
        }

        // Start module scanning
        Messenger::info("Scanning modules...");

        $modules = scandir($modulesPath);

        $responseData = [];

        file_put_contents('module-check.csv', "Module;Author;Module Key;Version;Marketplace URL;Marketplace Status;\n");

        // Filter out non-directory entries first
        $validModules = [];
        foreach ($modules as $module) {
            if ($module === '.' || $module === '..' || $module === '.htaccess') {
                continue;
            }
            
            $modulePath = $modulesPath . '/' . $module;
            if (is_dir($modulePath)) {
                $validModules[] = $module;
            }
        }

        // Apply limit or slice if specified
        if ($limit !== null && $limit > 0) {
            $totalModules = count($validModules);
            $validModules = array_slice($validModules, 0, $limit);
            Messenger::info("Processing " . count($validModules) . " modules (limited from " . $totalModules . " total)");
        } elseif ($sliceStart !== null && $sliceEnd !== null) {
            $totalModules = count($validModules);
            
            // Validate slice bounds against actual module count
            if ($sliceStart >= $totalModules) {
                Messenger::danger("Slice start ($sliceStart) is greater than or equal to total modules ($totalModules)");
            }
            
            // Calculate the length for array_slice
            $sliceLength = min($sliceEnd - $sliceStart, $totalModules - $sliceStart);
            $validModules = array_slice($validModules, $sliceStart, $sliceLength);
            
            $actualEnd = $sliceStart + count($validModules) - 1;
            Messenger::info("Processing " . count($validModules) . " modules (slice: $sliceStart to $actualEnd from $totalModules total)");
        }

        foreach ($validModules as $module) {
            $modulePath = $modulesPath . '/' . $module;

            $moduleDetails = getModuleDetails($modulePath);
            
            if (!$moduleDetails) {
                continue;
            }

            if ($moduleDetails['module_author'] == 'PrestaShop' && $moduleDetails['module_key'] == null) {
                $tag = self::getLatestTagOnGitHub("prestashop/$module");
                file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;{$tag};\n", FILE_APPEND);
                continue;
            }

            Messenger::info("Checking module: " . $moduleDetails['module_name']);

            if ($moduleDetails['module_key'] !== null) {
                $apiUrl = "https://api-addons.prestashop.com/?format=json&iso_lang=en&iso_code=EN"
                    . "&module_key=" . urlencode($moduleDetails['module_key'])
                    . "&method=check"
                    . "&module_name=" . urlencode($moduleDetails['module_name']);

                $apiResponse = file_get_contents($apiUrl);

                if ($apiResponse === false) {
                    Messenger::warning("Failed to connect to API for module: " . $moduleDetails['module_name']);
                    $responseData[] = [
                        'module' => $moduleDetails['module_name'],
                        'success' => 'error',
                        'message' => 'Failed to connect to API'
                    ];
                    file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};{$moduleDetails['module_key']};{$moduleDetails['module_version']};'API failed';;\n", FILE_APPEND);
                } else {
                    $decodedResponse = json_decode($apiResponse, true);
                    $id = isset($decodedResponse['id']) ? $decodedResponse['id'] : null;

                    Messenger::info("Module " . $moduleDetails['module_name'] . " checked successfully.");
                    $marketplaceUrl = "https://addons.prestashop.com/en/category-placeholder/{$id}-placeholder-title.html";
                    file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};{$moduleDetails['module_key']};{$moduleDetails['module_version']};{$marketplaceUrl};;\n", FILE_APPEND);
                }
            } else {
                if (file_exists($modulePath.'/composer.json')) {
                    $json = file_get_contents($modulePath.'/composer.json');
                    $object = json_decode($json);

                    if (property_exists($object, 'homepage')) {
                        $array = preg_split('/\//', $object->homepage);

                        $lastTwo = array_slice($array, -2); // Get last two elements
                        $repo = implode("/", $lastTwo); // Join with forward slash

                        $tag = self::getLatestTagOnGitHub($repo);
                        
                        file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;{$tag};\n", FILE_APPEND);
                    } else {
                        file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;;\n", FILE_APPEND);
                    }
                    

                } else {
                    file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;;\n", FILE_APPEND);
                }

                continue;
            }    
        }
        
        Messenger::info("Audit completed.");
    }

    public static function getLatestTagOnGitHub($repo)
    {
        // Fetch GitHub token using GitHub CLI
        $token = trim(shell_exec("gh auth token 2>/dev/null"));

        if (empty($token)) {
            Messenger::danger("No GitHub token found. Please authenticate using:\n gh auth login");
        }

        $url = "https://api.github.com/repos/$repo/releases/latest";

        $options = [
            "http" => [
                "method" => "GET",
                "header" => [
                    "User-Agent: PHP",
                    "Authorization: token $token"
                ]
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response) {
            $data = json_decode($response, true);
            return $data['tag_name'] ?? null;
        } else {
            Messenger::danger("Failed to fetch release data from GitHub.");
        }
    }
}
