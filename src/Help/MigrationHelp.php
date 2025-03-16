<?php

namespace PrestaOps\Help;

/**
 * Help documentation for the migration command
 */
class MigrationHelp extends AbstractHelp
{
    /**
     * Show help information
     */
    public static function show() : void
    {
        echo self::getHelpText();
    }

    /**
     * Get the complete help text
     */
    protected static function getHelpText(): string
    {
        return self::getHeader() .
               self::getUsage() .
               self::getOptions() .
               self::getExamples() .
               self::getConfiguration() .
               self::getNotes();
    }

    protected static function getHeader(): string
    {
        return <<<HELP
PrestaOps Migration Command
==========================

Migrate a PrestaShop installation from one server to another.

HELP;
    }

    protected static function getUsage(): string
    {
        return <<<HELP

Usage:
  prestaops migrate [options]

HELP;
    }

    protected static function getOptions(): string
    {
        return <<<HELP

Options:
  --help                  Show this help information
  --staging-url-suffix    Specify the staging URL suffix (e.g., staging.example.com)
  --configure-only        Only configure the site, skip file and database transfer
  --database-only        Only transfer the database
  --files-only          Only transfer files
  --disable-ssl         Disable SSL for local development
  --sync               Run commands synchronously (see output in real-time)

HELP;
    }

    protected static function getExamples(): string
    {
        return <<<HELP

Examples:
  1. Basic migration with staging URL:
     prestaops migrate --staging-url-suffix=local.test

  2. Transfer only the database:
     prestaops migrate --database-only

  3. Transfer only files:
     prestaops migrate --files-only

  4. Configure site with staging URL:
     prestaops migrate --configure-only --staging-url-suffix=local.test

  5. Run migration synchronously:
     prestaops migrate --sync --staging-url-suffix=local.test

  6. Disable SSL for local development:
     prestaops migrate --staging-url-suffix=local.test --disable-ssl

HELP;
    }

    protected static function getConfiguration(): string
    {
        return <<<HELP

Configuration:
  The migration command requires several configuration variables to be set in your .env file:
  - SSH_HOST                        Remote server hostname
  - SSH_USER                        Remote server username
  - SOURCE_PATH                     Path to PrestaShop on remote server
  - SOURCE_DATABASE_HOST            Remote database host
  - SOURCE_DATABASE_USER            Remote database username
  - SOURCE_DATABASE_PASS            Remote database password
  - SOURCE_DATABASE_NAME            Remote database name
  - DESTINATION_DATABASE_HOST       The URL of the destination server
  - DESTINATION_DATABASE_USER       The username for the destination server
  - DESTINATION_DATABASE_PASS       The password for the destination server
  - DESTINATION_DATABASE_NAME       The name of the destination database
  - DESTINATION_PATH                Local path for PrestaShop installation
  - DATABASE_PREFIX                 PrestaShop database table prefix (usually 'ps_')

  These can be set in your .env file or will be prompted for if missing.

HELP;
    }

    protected static function getNotes(): string
    {
        return <<<HELP

Notes:
  - The migration process simply copies files and databases. It does not install a new PrestaShop.
  - SSL settings can be configured using the --disable-ssl option, useful for local development.
  - File permissions will be preserved during transfer.
  - The .htaccess file will be regenerated after migration, to adapt to local development environment.

HELP;
    }
} 