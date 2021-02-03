<?php
namespace Psalm\Type\Atomic;

/**
 * Denotes a string, that is also non-empty
 */
class TNonFalsyString extends TString
{
    public function getId(bool $nested = false): string
    {
        return 'non-falsy-string';
    }
}
