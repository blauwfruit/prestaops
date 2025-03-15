<?php

namespace PrestaOps\Help;

use PrestaOps\Commands\Migration;

/**
 * Help documentation for the migration command
 */
class MigrationHelp extends AbstractHelp
{
    protected static function getHeader(): string
    {
        return <<<HELP
PrestaOps Migration Tool
=======================

DESCRIPTION
-----------
A tool for migrating PrestaShop installations between environments.

HELP;
    }

    protected static function getUsage(): string
    {
        return <<<HELP

USAGE
-----
prestaops migrate [options]

HELP;
    }

    protected static function getOptions(): string
    {
        return <<<HELP

OPTIONS
-------
--help                  Show this help information
--database-only         Migrate only the database
--files-only           Migrate only the files
--configure-only       Configure only the domains
--staging-url-suffix   Specify the staging domain suffix (e.g. staging.example.com)
--sync                 Run the migration synchronously (direct visible output)
--disable-ssl         Disable SSL for local development purposes

HELP;
    }

    protected static function getExamples(): string
    {
        return <<<HELP

EXAMPLES
--------
1. Full migration to staging:
   prestaops migrate --staging-url-suffix=staging.example.com

2. Migrate database only:
   prestaops migrate --database-only

3. Migrate files only:
   prestaops migrate --files-only

4. Local migration without SSL:
   prestaops migrate --staging-url-suffix=local.test --disable-ssl

HELP;
    }

    protected static function getConfiguration(): string
    {
        return <<<HELP

CONFIGURATION
------------
Required environment variables in .prestaops file:
- SSH_HOST               The source server hostname
- SSH_USER              The SSH user for source server access
- SOURCE_PATH           Path to PrestaShop on source server
- DESTINATION_PATH      Path to PrestaShop on destination server
- DATABASE_PREFIX       The database table prefix (e.g. 'ps_')
- PRESTASHOP_VERSION    Version of PrestaShop

Database configuration:
- SOURCE_DATABASE_HOST      Source database hostname
- SOURCE_DATABASE_USER      Source database username
- SOURCE_DATABASE_PASS      Source database password
- SOURCE_DATABASE_NAME      Source database name
- DESTINATION_DATABASE_HOST Destination database hostname
- DESTINATION_DATABASE_USER Destination database username
- DESTINATION_DATABASE_PASS Destination database password
- DESTINATION_DATABASE_NAME Destination database name

HELP;
    }

    protected static function getNotes(): string
    {
        return <<<HELP

NOTES
-----
- Always backup your data before migration
- Ensure SSH key authentication is set up
- Check file permissions after migration
- Verify database credentials before starting
- Use --configure-only with --staging-url-suffix for domain setup

For support: support@prestaops.com

HELP;
    }
} 