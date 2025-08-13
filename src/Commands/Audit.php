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
                    
                    // Get the latest version from the marketplace
                    $latestVersion = self::getModuleVersionFromMarketplace($marketplaceUrl);
                    $versionInfo = $latestVersion ? " (Latest: $latestVersion)" : "";
                    Messenger::info("Module " . $moduleDetails['module_name'] . " - Current: {$moduleDetails['module_version']}" . $versionInfo);
                    
                    file_put_contents('module-check.csv', "$module;{$moduleDetails['module_author']};{$moduleDetails['module_key']};{$moduleDetails['module_version']};{$marketplaceUrl};{$latestVersion};\n", FILE_APPEND);
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

    /**
     * Get the latest version of a module from the PrestaShop marketplace
     * 
     * @param string $placeholderUrl The placeholder URL to visit
     * @return string|null The latest version number or null if not found
     */
    public static function getModuleVersionFromMarketplace($placeholderUrl)
    {
        try {
            // Extract module ID from the placeholder URL
            if (preg_match('/\/(\d+)-/', $placeholderUrl, $matches)) {
                $moduleId = $matches[1];
                
                // Try different URL patterns for the actual product page
                $possibleUrls = [
                    "https://addons.prestashop.com/en/modules/{$moduleId}",
                    "https://addons.prestashop.com/en/module/{$moduleId}",
                    "https://addons.prestashop.com/en/product/{$moduleId}",
                    $placeholderUrl  // fallback to original
                ];
                
                foreach ($possibleUrls as $url) {
                    $result = self::fetchModulePageWithRetry($url);
                    if ($result !== null) {
                        return $result;
                    }
                    // Add a small delay between requests to be respectful
                    sleep(1);
                }
            }
            
            return null;

        } catch (Exception $e) {
            Messenger::warning("Error fetching module version: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Fetch module page with retry logic and different strategies
     */
    private static function fetchModulePageWithRetry($url)
    {
        // Try with different user agents and techniques
        $userAgents = [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ];
        
        foreach ($userAgents as $userAgent) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Accept-Encoding: gzip, deflate, br',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                    'Cache-Control: max-age=0',
                    'sec-ch-ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
                    'sec-ch-ua-mobile: ?0',
                    'sec-ch-ua-platform: "macOS"',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                    'Sec-Fetch-Site: none',
                    'Sec-Fetch-User: ?1'
                ],
                CURLOPT_ENCODING => '', // This automatically handles gzip/deflate decompression
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_COOKIEJAR => '/tmp/prestashop_cookies.txt',
                CURLOPT_COOKIEFILE => '/tmp/prestashop_cookies.txt'
            ]);

            // Messenger::info("Trying URL: $url"); // Debug line - commented out
            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($html === false || !empty($error)) {
                // Messenger::warning("Failed to fetch $url - Error: $error"); // Debug line - commented out
                continue;
            }
            
            if ($httpCode >= 400) {
                // Messenger::warning("HTTP error $httpCode when fetching: $url"); // Debug line - commented out
                continue;
            }
            
            // Messenger::info("Successfully fetched: $finalUrl"); // Debug line - commented out
            
            // Debug: Uncomment the lines below for debugging
            // if (strpos($html, 'product_version') !== false) {
            //     if (preg_match('/.{0,100}product_version.{0,100}/', $html, $matches)) {
            //         Messenger::info("product_version context: " . htmlspecialchars($matches[0]));
            //     }
            // }

            // Look for version information in the HTML content
            $version = self::extractVersionFromHtml($html);
            if ($version !== null) {
                // Messenger::info("Found version: $version"); // Debug line - commented out
                return $version;
            }
            
            // Try next user agent if no version found
        }
        
        return null; // No version found with any user agent
    }
    
    /**
     * Extract version information from HTML content
     */
    private static function extractVersionFromHtml($html)
    {
        // Try multiple patterns to find version information
        $patterns = [
            // Pattern for PrestaShop marketplace product_version in JSON data - THIS IS THE KEY ONE!
            '/"product_version"\s*:\s*"([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"/',
            // Specific pattern for PrestaShop marketplace "About module v. X.X.X" format
            '/<span[^>]*class=["\'][^"\']*muik-about-module__title-version[^"\']*["\'][^>]*>v\.\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)<\/span>/i',
            // Alternative pattern for the same structure
            '/<h4[^>]*>About module[^<]*<span[^>]*>v\.\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)<\/span>/i',
            // Pattern for version in JavaScript/JSON data structures
            '/"moduleVersion"\s*:\s*"([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"/',
            '/"version"\s*:\s*"([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"/',
            '/"currentVersion"\s*:\s*"([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"/',
            '/"latestVersion"\s*:\s*"([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"/',
            // Pattern looking for version in window/global JavaScript variables
            '/window\.__INITIAL_STATE__[^}]*version[^}]*"([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)"/i',
            // Pattern for version in meta tags
            '/<meta[^>]+property=["\']product:version["\'][^>]+content=["\']([^"\']+)["\']/',
            // Pattern for version in the page content
            '/Version\s*:?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)/',
            '/version\s*:?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)/i',
            // Pattern for version in download links or buttons
            '/download[^>]*version[^>]*([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)/i',
            // Pattern for version in class names or data attributes
            '/data-version=["\']([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)["\']/',
            // Pattern for version in spans or divs with version class
            '/<(?:span|div)[^>]*class=["\'][^"\']*version[^"\']*["\'][^>]*>([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?)<\/(?:span|div)>/i',
            // More generic pattern for semantic version numbers (but more restrictive to avoid false positives)
            '/\b([0-9]+\.[0-9]+\.[0-9]+)\b/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $version = $matches[1];
                // Validate that it looks like a proper version number
                if (preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?$/', $version)) {
                    return $version;
                }
            }
        }

        // If no version found with patterns, try to extract from common locations
        // Look for version in title or h1 tags
        if (preg_match('/<title[^>]*>.*?([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?).*?<\/title>/i', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/<h1[^>]*>.*?([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:\.[0-9]+)?).*?<\/h1>/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
