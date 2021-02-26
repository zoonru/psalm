<?php

namespace Psalm;

use Iterator;
use PhpParser\Node\Param;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Nop;
use ReflectionClass;
use SplStack;

/**
 * @implements Iterator<int, Node>
 */
class Ast implements Iterator
{
    /**
     * Stack pointer.
     *
     * @var list<string|int>
     */
    public $ptr = [];

    /**
     * Whether we should recurse, next
     *
     * @var boolean
     */
    public $recurseNext = true;

    /**
     * Actual node stack.
     *
     * @var SplStack<Node>
     */
    public $parents;

    /**
     * Current node.
     *
     * @var Node
     */
    private $current;

    /**
     * AST.
     *
     * @var RootNode
     */
    private $stmts;
    /**
     * Raw index.
     *
     * @var integer
     */
    private $index = -1;

    /**
     * Constructor.
     *
     * @param list<Stmt> $stmts
     */
    public function __construct(array $stmts)
    {
        /** @var SplStack<Node> */
        $this->parents = new SplStack;
        $this->stmts = new RootNode($stmts);
    }
    /**
     * Replace AST.
     *
     * @param list<Stmt>|self $stmts
     * @return bool
     */
    public function setStmts($stmts): bool
    {
        /** @var SplStack<Node> */
        $stack = new SplStack;
        $stmts = $stmts instanceof self ? $stmts->stmts : new RootNode($stmts);
        $stmt = $stmts;
        foreach ($this->ptr as $part) {
            if (\is_int($part)) {
                /** @var list<Node> $stmt */
                if (!isset($stmt[$part])) {
                    return false;
                }
                $stmt = $stmt[$part];
            } else {
                /** @var Node $stmt */
                if (!isset($stmt->{$part})) {
                    return false;
                }
                /** @var Node|list<Node> $stmt */
                $stmt = $stmt->{$part};
            }
            if ($stmt instanceof Node) {
                $stack->push($stmt);
            }
        }

        if (!$stack->isEmpty()) $this->current = $stack->pop();
        
        $this->parents = $stack;
        $this->stmts = $stmts;
        return true;
    }
    /**
     * Get current top statement list.
     *
     * @return list<Stmt>
     */
    public function getStmts(): array
    {
        return $this->stmts->stmts;
    }

    /**
     * Get current node.
     */
    public function current(): Node
    {
        return $this->current;
    }
    /**
     * Get key.
     */
    public function key(): int
    {
        return $this->index;
    }
    public function next(): void
    {
        $this->index++;
        if ($this->recurseNext) {
            $chosen = null;
            /** @var string */
            foreach ($this->current->getSubNodeNames() as $name) {
                /** @var Node|list<Node>|null */
                $chosen = $this->current->{$name};
                if ((is_array($chosen) && !empty($chosen) && $chosen[0] instanceof Node) || $chosen instanceof Node) {
                    break;
                } else {
                    $chosen = null;
                }
            }
            if ($chosen) {
                $this->parents->push($this->current);
                array_push($this->ptr, $name);
                if (\is_array($chosen)) {
                    \array_push($this->ptr, 0);
                    $this->current = $chosen[0];
                } else {
                    $this->current = $chosen;
                }
                return;
            }
            $this->recurseNext = false;
        }
        while (true) {
            $parent = $this->parents->top();

            $node = &$this->ptr[\count($this->ptr)-1];

            if (\is_int($node)) {
                $idx = &$node;
                $node = &$this->ptr[\count($this->ptr)-2];
                if (\count($parent->{$node}) > ++$idx) {
                    /** @var Node */
                    $this->current = $parent->{$node}[$idx];
                    $this->recurseNext = true;
                    return;
                }
                \array_pop($this->ptr);
            }

            /** @var list<string> $sub */
            $sub = $parent->getSubNodeNames();
            $subIdx = ((int) \array_search($node, $sub)) + 1;
            if (isset($sub[$subIdx])) {
                $node = $sub[$subIdx];

                if (\is_array($parent->{$node}) && !empty($parent->{$node}) && $parent->{$node}[0] instanceof Node) {
                    \array_push($this->ptr, 0);
                    /** @var Node */
                    $this->current = $parent->{$node}[0];
                    $this->recurseNext = true;
                } else if ($parent->{$node} instanceof Node) {
                    /** @var Node */
                    $this->current = $parent->{$node};
                    $this->recurseNext = true;
                } else {
                    continue;
                }
                return;
            }

            array_pop($this->ptr);
            $this->current = $this->parents->pop();
            if ($this->parents->isEmpty()) {
                $this->index = -1;
                return;
            }
        }
    }
    public function rewind(): void
    {
        if (!empty($this->stmts->stmts)) {
            $this->ptr = ['stmts', 0];
            $this->index = 0;

            /** @var SplStack<Node> */
            $this->parents = new SplStack;
            $this->parents->push($this->stmts);
            $this->current = $this->stmts->stmts[0];
            $this->recurseNext = true;
        } else {
            $this->index = -1;
        }
    }
    public function valid(): bool
    {
        return $this->index !== -1;
    }

    private static function isNullable(Node $node, string $propertyName): bool
    {
        return (bool) preg_match("/@var (\S+|)?null(|\S+)?/", (new ReflectionClass($node))->getProperty($propertyName)->getDocComment());
    }


    private static function isIdentifier(Node $node, string $propertyName): bool
    {
        return (bool) preg_match("/@var (\S+|)?Identifier(|\S+)?/", (new ReflectionClass($node))->getProperty($propertyName)->getDocComment());
    }

    public function remove(): void
    {
        $node = $this->ptr[\count($this->ptr)-1];
        if (\is_int($node)) {
            $idx = $node;
            $node = $this->ptr[\count($this->ptr)-2];
            \array_splice($this->parents->top()->{$node}, $idx, 1);
            $this->ptr[\count($this->ptr)-1]--;
        } else {
            if (self::isNullable($this->parents->top(), $node)) {
                $this->parents->top()->{$node} = null;
            } else if (!self::isIdentifier($this->parents->top(), $node)) {
                $this->parents->top()->{$node} = new Nop();
            }
        }
    }
    public function raise(): void
    {
        if ($this->parents->count() < 2 || $this->parents[0] instanceof ClassLike) {
            return;
        }
        if (\is_int(\array_pop($this->ptr))) {
            \array_pop($this->ptr);
        }
        $popped = $this->parents->pop();

        $name = $this->ptr[\count($this->ptr)-1];
        if (\is_int($name)) {
            $idx = $name;
            $name = $this->ptr[\count($this->ptr)-2];
            $this->parents[0]->{$name}[$idx] = $this->current;
        } else {
            $this->parents[0]->{$name} = $this->current;
        }

        if ($popped instanceof Arg || $popped instanceof Expression) {
            $this->raise();
        }
    }
    /**
     * Find node.
     *
     * @param Node $node
     * @return boolean
     */
    public function find(Node $node): bool
    {
        foreach ($this as $test) {
            if ($test === $node) {
                return true;
            }
        }
        return false;
    }
}
