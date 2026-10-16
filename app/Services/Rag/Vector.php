<?php

namespace App\Services\Rag;

class Vector
{
    /** Format a float array as a pgvector literal: "[0.1,0.2,...]". */
    public static function literal(array $values): string
    {
        return '['.implode(',', array_map(fn ($v) => sprintf('%.7F', $v), $values)).']';
    }
}
