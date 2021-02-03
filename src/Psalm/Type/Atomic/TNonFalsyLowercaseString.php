<?php
namespace Psalm\Type\Atomic;

/**
 * Denotes a non-empty-string where every character is lowercased. (which can also result from a `strtolower` call).
 */
class TNonFalsyLowercaseString extends TNonFalsyString
{
    public function getKey(bool $include_extra = true): string
    {
        return 'string';
    }

    public function getId(bool $nested = false): string
    {
        return 'non-falsy-lowercase-string';
    }

    /**
     * @return false
     */
    public function canBeFullyExpressedInPhp(int $php_major_version, int $php_minor_version): bool
    {
        return false;
    }
}
