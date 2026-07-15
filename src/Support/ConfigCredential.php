<?php

namespace Newtxt\Laravel\Support;

class ConfigCredential
{
    /**
     * Normalize optional credentials from config or env templates.
     */
    public static function value(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $lowerValue = strtolower($value);
        foreach (['placeholder', 'replace-with', 'replace_with', 'change-me', 'change_me', 'example'] as $fragment) {
            if (str_contains($lowerValue, $fragment)) {
                return '';
            }
        }

        return $value;
    }
}
