<?php

namespace PrestaOps\Help;

/**
 * Abstract base class for help documentation
 */
abstract class AbstractHelp
{
    /**
     * Show help information
     */
    public static function show(): void
    {
        echo static::getHelpText();
        exit(0);
    }

    /**
     * Generate the help text
     */
    protected static function getHelpText(): string
    {
        $help = static::getHeader();
        $help .= static::getUsage();
        $help .= static::getOptions();
        $help .= static::getExamples();
        $help .= static::getConfiguration();
        $help .= static::getNotes();
        
        return $help;
    }

    abstract protected static function getHeader(): string;
    abstract protected static function getUsage(): string;
    abstract protected static function getOptions(): string;
    abstract protected static function getExamples(): string;
    abstract protected static function getConfiguration(): string;
    abstract protected static function getNotes(): string;
} 