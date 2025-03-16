<?php

namespace PrestaOps\Help;

/**
 * Main help documentation for PrestaOps
 */
class PrestaOpsHelp extends AbstractHelp
{
    /**
     * Show help information
     */
    public static function show(): void
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
               self::getCommands() .
               self::getExamples() .
               self::getNotes();
    }

    protected static function getHeader(): string
    {
        return <<<HELP
PrestaOps CLI Tool
=================

A command-line tool for managing PrestaShop installations.

HELP;
    }

    protected static function getUsage(): string
    {
        return <<<HELP

Usage:
  prestaops <command> [options]

HELP;
    }

    protected static function getOptions(): string
    {
        return <<<HELP

Global Options:
  --help                  Show help information for PrestaOps or a specific command
  --version              Show version information

HELP;
    }

    protected static function getCommands(): string
    {
        return <<<HELP

Available Commands:
  migrate            Migrate a PrestaShop installation
    Options:           Use 'prestaops migrate --help' for command-specific options

  audit              Audit a PrestaShop installation
    Options:           Use 'prestaops audit --help' for command-specific options

  backup             Backup a PrestaShop installation
    Options:           Use 'prestaops backup --help' for command-specific options

  deploy             Deploy a PrestaShop installation
    Options:           Use 'prestaops deploy --help' for command-specific options

HELP;
    }

    protected static function getExamples(): string
    {
        return <<<HELP

Examples:
  1. Show help for a specific command:
     prestaops migrate --help

  2. Run a security audit:
     prestaops audit --security

HELP;
    }

    protected static function getNotes(): string
    {
        return <<<HELP

Notes:
  - All commands support the --help option for detailed information
  - Configuration can be set in .env or .prestaops files
  - Some commands may require additional authentication
  - Use --version to check your PrestaOps installation

HELP;
    }

    protected static function getConfiguration(): string
    {
        return <<<HELP
        HELP;
    }
} 