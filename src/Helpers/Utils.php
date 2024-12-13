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
}

