<?php

namespace PrestaOps\Help;

/**
 * Help documentation for the audit command
 */
class AuditHelp extends AbstractHelp
{
    protected static function getHeader(): string
    {
        return <<<HELP
PrestaOps Audit Tool
===================

DESCRIPTION
-----------
A tool for auditing PrestaShop installations and modules.

HELP;
    }

    protected static function getUsage(): string
    {
        return <<<HELP

USAGE
-----
prestaops audit [options]

HELP;
    }

    protected static function getOptions(): string
    {
        return <<<HELP

OPTIONS
-------
--help              Show this help information
--modules           Audit installed modules and check for updates

HELP;
    }

    protected static function getExamples(): string
    {
        return <<<HELP

EXAMPLES
--------
1. Audit all modules:
   prestaops audit --modules

2. Show audit help:
   prestaops audit --help

HELP;
    }

    protected static function getConfiguration(): string
    {
        return <<<HELP

CONFIGURATION
------------
- Requires a valid PrestaShop installation
- GitHub authentication for checking module updates
- Access to PrestaShop Marketplace API

HELP;
    }

    protected static function getNotes(): string
    {
        return <<<HELP

NOTES
-----
- Ensure you have GitHub CLI configured for module version checks
- Some modules may not be available in the PrestaShop Marketplace
- Private modules will require additional authentication

For support: support@prestaops.com

HELP;
    }
} 