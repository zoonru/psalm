<?php

namespace Psalm;

use PhpParser\Node\Stmt;
use PhpParser\NodeAbstract;

/**
 * Root node abstraction
 *
 * @author Daniil Gentili <daniil@daniil.it>
 * @license MIT
 */
class RootNode extends NodeAbstract
{
    /**
     * Children.
     *
     * @var list<Stmt>
     */
    public $stmts = [];
    /**
     * Constructor
     *
     * @param list<Stmt> $stmts
     * @param array $attributes
     */
    public function __construct(array $stmts, array $attributes = [])
    {
        $this->stmts = $stmts;
        parent::__construct($attributes);
    }
    public function getSubNodeNames(): array
    {
        return ['stmts'];
    }
    public function getType(): string
    {
        return 'rootNode';
    }
}