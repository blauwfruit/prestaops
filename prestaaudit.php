<?php

$prestaShopRoot = getcwd();
$paramatersFile = getcwd() . '/app/config/parameters.php';

if (!file_exists($paramatersFile)) {    
    danger("Not in PrestaShop root, cannot find parameters.php");
}


$prestaShopParameters = include($paramatersFile);

if (!isset($prestaShopParameters['parameters']['database_host'])) {
    danger('PrestaShop application was found, but does not seem to be configured. The parameters are not found inside app/config/parameters.php.');
}

// Check if a project path is provided
if ($argc < 2) {
    danger("Usage: check_modules.php /path/to/prestashop/project\n");
}


$longopts = ["scan-marketplace"]; // Long options with no values

// Parse command-line arguments
$options = getopt("", $longopts);

// Check if --scan-marketplace was used
if (isset($options['scan-marketplace'])) {
    info("Scanning PrestaShop marketplace...");
}

$modulesPath = $prestaShopRoot . '/modules';

if (!is_dir($modulesPath)) {
    danger("Modules directory not found in $projectPath");
}

function getModuleDetails($modulePath)
{
    $mainFile = $modulePath . '/' . basename($modulePath) . '.php';

    if (!file_exists($mainFile)) {
        warning("Skipping module " . basename($modulePath) . " - main file not found.");
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

function getLatestTagOnGitHub($repo)
{
    // Fetch GitHub token using GitHub CLI
    $token = trim(shell_exec("gh auth token 2>/dev/null"));

    if (empty($token)) {
        danger("No GitHub token found. Please authenticate using:\n gh auth login");
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
        danger("Failed to fetch release data from GitHub.");
    }
}

// Start module scanning
info("Scanning modules...");

$modules = scandir($modulesPath);

$responseData = [];

file_put_contents('module-check.csv', "");

// $modules = array_slice($modules, 45);

foreach ($modules as $module) {

    if ($module === '.' || $module === '..' || $module === '.htaccess') {
        continue;
    }

    $modulePath = $modulesPath . '/' . $module;

    if (!is_dir($modulePath)) {
        continue;
    }

    $moduleDetails = getModuleDetails($modulePath);
    
    if (!$moduleDetails) {
        continue;
    }

    if ($moduleDetails['module_author'] == 'PrestaShop' && $moduleDetails['module_key'] == null) {
        $tag = getLatestTagOnGitHub("prestashop/$module");
        file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;{$tag};\n", FILE_APPEND);
        continue;
    }

    info("Checking module: " . $moduleDetails['module_name']);

    if ($moduleDetails['module_key'] !== null) {
        $apiUrl = "https://api-addons.prestashop.com/?format=json&iso_lang=en&iso_code=EN"
            . "&module_key=" . urlencode($moduleDetails['module_key'])
            . "&method=check"
            . "&module_name=" . urlencode($moduleDetails['module_name']);

        $apiResponse = file_get_contents($apiUrl);

        if ($apiResponse === false) {
            warning("Failed to connect to API for module: " . $moduleDetails['module_name']);
            $responseData[] = [
                'module' => $moduleDetails['module_name'],
                'success' => 'error',
                'message' => 'Failed to connect to API'
            ];
            file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};{$moduleDetails['module_key']};{$moduleDetails['module_version']};'API failed';;\n", FILE_APPEND);
        } else {
            $decodedResponse = json_decode($apiResponse, true);
            $id = isset($decodedResponse['id']) ? $decodedResponse['id'] : null;

            info("Module " . $moduleDetails['module_name'] . " checked successfully.");
            $marketplaceUrl = "https://addons.prestashop.com/en/category-placeholder/{$id}-placeholder-title.html";
            file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};{$moduleDetails['module_key']};{$moduleDetails['module_version']};{$marketplaceUrl};;\n", FILE_APPEND);
        }
    } else {
        if (file_exists($modulePath.'/composer.json')) {
            $json = file_get_contents($modulePath.'/composer.json');
            $object = json_decode($json);


            $array = preg_split('/\//', $object->homepage);

            $lastTwo = array_slice($array, -2); // Get last two elements
            $repo = implode("/", $lastTwo); // Join with forward slash

            $tag = getLatestTagOnGitHub($repo);
            
            file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;{$tag};\n", FILE_APPEND);
        } else {
            file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};;{$moduleDetails['module_version']};;;\n", FILE_APPEND);
        }

        continue;
    }    
}

// Print output in CLI
info("Audit completed.");

// Color functions
function danger($message) {
    echo "\e[31m$message\e[0m\n"; // Red
    exit(1);
}

function warning($message) {
    echo "\e[33m$message\e[0m\n"; // Yellow
}

function info($message) {
    echo "\e[36m$message\e[0m\n"; // Cyan
}
