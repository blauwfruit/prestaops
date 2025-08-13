<?php

namespace PrestaOps\Help;

/**
 * Help documentation for the audit command
 */
class AuditHelp extends AbstractHelp
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
PrestaOps Audit Command
======================

Audit a PrestaShop installation for security and performance issues.

HELP;
    }

    protected static function getUsage(): string
    {
        return <<<HELP

Usage:
  prestaops audit [options]

HELP;
    }

    protected static function getOptions(): string
    {
        return <<<HELP

Options:
  --help              Show this help information
  --modules           Checks modules for new updates
  --limit=N           Limit the number of modules to check (useful for testing)

HELP;
    }

    protected static function getExamples(): string
    {
        return <<<HELP

Examples:
  1. Run module audit:
     prestaops audit --modules

  2. Run module audit with limit (useful for testing):
     prestaops audit --modules --limit=10

HELP;
    }

    protected static function getConfiguration(): string
    {
        return <<<HELP

Configuration:
  The audit command requires no configuration.

HELP;
    }

    protected static function getNotes(): string
    {
        return <<<HELP

Notes:
  - The audit makes api calls to the PrestaShop marketplace to check for updates.
  - It is recommended to authenticate with GitHub to avoid rate limiting.
  - Modules with marketplace keys will be checked for availability status.
  - Modules no longer on the marketplace will be marked as "No longer on marketplace".
  - Use --limit option to avoid API rate limits during testing or development.

HELP;
    }
} 