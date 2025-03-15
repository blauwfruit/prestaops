<?php

namespace PrestaOps\Help;

/**
 * Main help documentation for PrestaOps
 */
class PrestaOpsHelp extends AbstractHelp
{
    protected static function getHeader(): string
    {
        return <<<HELP
PrestaOps CLI Tool
=================

DESCRIPTION
-----------
A command-line tool for managing PrestaShop installations, including migrations,
deployments, and maintenance tasks.

HELP;
    }

    protected static function getUsage(): string
    {
        return <<<HELP

USAGE
-----
prestaops <command> [options]

HELP;
    }

    protected static function getOptions(): string
    {
        return <<<HELP

GLOBAL OPTIONS
-------------
--help                  Show help information for PrestaOps or a specific command
--version              Show version information
--verbose              Enable verbose output
--quiet                Suppress all output except errors

HELP;
    }

    protected static function getExamples(): string
    {
        return <<<HELP

AVAILABLE COMMANDS
----------------
migrate               Migrate a PrestaShop installation to another environment
  Options:           Use 'prestaops migrate --help' for command-specific options

backup               Create a backup of a PrestaShop installation
  Options:           Use 'prestaops backup --help' for command-specific options

deploy               Deploy a PrestaShop installation
  Options:           Use 'prestaops deploy --help' for command-specific options

EXAMPLES
--------
1. Show help for a specific command:
   prestaops migrate --help

2. Run a migration with verbose output:
   prestaops migrate --verbose --staging-url-suffix=staging.example.com

3. Create a backup with minimal output:
   prestaops backup --quiet

HELP;
    }

    protected static function getConfiguration(): string
    {
        return <<<HELP

CONFIGURATION
------------
PrestaOps can be configured using:
- Environment variables
- Configuration files (.env)
- Command-line options

Configuration precedence (highest to lowest):
1. Command-line options
2. Environment variables
3. Configuration files

HELP;
    }

    protected static function getNotes(): string
    {
        return <<<HELP

NOTES
-----
- Each command has its own specific options and configuration requirements
- Use --help with any command to see detailed information
- Configuration files should be properly secured
- Backup your data before running potentially destructive operations

For detailed documentation, visit: https://docs.prestaops.com
For support, contact: support@prestaops.com

HELP;
    }
} 