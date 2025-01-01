<?php

namespace Z3rka\HasRelations\Helpers;

class Utils
{
    public static function stringToArray(array|string $value)
    {
        if (is_array($value) || (is_string($value) && preg_match('/\s*,\s*/', $value))) {
            return $value = is_string($value) ? explode(',', $value) : $value;
        }

        return false;
    }


    public static function isNumeric(mixed $value): bool
    {
        return is_numeric($value) || (is_string($value) && is_numeric(filter_var($value, FILTER_SANITIZE_NUMBER_INT)));
    }

    public static function isStringifiedBoolean($value): mixed
    {
        if ($value === "true") {
            return true;
        } elseif ($value === "false") {
            return false;
        }

        return $value;
    }
}

