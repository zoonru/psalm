<?php

namespace Psalm;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Analyzer\ProjectAnalyzer;

class FileStripper
{
    /** @var ProjectAnalyzer */
    private $project_analyzer;

    /** @var list<array{0: Ast, 1: IssueData}> */
    private $issue_ptrs = [];

    /** @var PrettyPrinter\Standard */
    private $printer;

    /** @var Ast */
    private $ast;

    /** @var string */
    private $file_path;

    /** @var string */
    private $file_name;

    public function __construct(string $file_path, ProjectAnalyzer $project_analyzer, IssueData ...$issues)
    {
        $this->printer = new PrettyPrinter\Standard;
        $this->project_analyzer = $project_analyzer;
        $codebase = $project_analyzer->getCodebase();

        $this->file_path = $file_path;
        $this->file_name = $codebase->config->shortenFileName($file_path);

        /** @var array<string, IssueData[]> */
        $fileList = [];
        foreach ($issues as $issue) {
            $fileList[$issue->file_path][] = $issue;
        }
        $files = $project_analyzer->getReferencedFilesFromDiff(\array_keys($fileList));

        // Merge files, take pointers to issues
        $allStmts = [];
        foreach ($files as $file_path) {
            $stmts = $codebase->getStatementsForFile($file_path);
            foreach ($fileList[$file_path] ?? [] as $k => $issue) {
                $issuePtr = new Ast($stmts);
                $found = false;
                foreach ($issuePtr as $node) {
                    $start = (int) $node->getAttribute('startFilePos');
                    if ($start === $issue->from && !($node instanceof Arg || $node instanceof Expression)) {
                        $issuePtr->ptr[1] += \count($allStmts);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new \RuntimeException('Could not find reference to issue '.$issue->message.'!');
                }
                unset($fileList[$file_path][$k]);
                $this->issue_ptrs []= [$issuePtr, $issue];
            }
            if (isset($fileList[$file_path]) && empty($fileList[$file_path])) {
                unset($fileList[$file_path]);
            }
            $allStmts = \array_merge($allStmts, $stmts);
        }
        if (!empty($fileList)) {
            throw new \RuntimeException('Could not find reference to certain issues!');
        }
        $this->ast = new Ast($allStmts);
        $new_contents = $this->printer->prettyPrintFile($this->ast->getStmts());
        $file_provider = $codebase->file_provider;
        $file_provider->setContents($this->file_path, $new_contents);
    }

    public function reproduce(): string
    {
        $project_analyzer = $this->project_analyzer;
        $codebase = $project_analyzer->getCodebase();
        $file_provider = $codebase->file_provider;
        do {
            $old = $file_provider->getContents($this->file_path);
            // Remove possibly unused statements
            foreach ($this->ast as $_) {
                $affectedIndexes = [];

                $ptr = $this->ast->ptr;
                foreach ($this->issue_ptrs as [$issue]) {
                    $len = \min(\count($ptr), \count($issue->ptr));
                    $equal = true;
                    for ($x = 0; $x < $len; $x++) {
                        if ($issue->ptr[$x] !== $ptr[$x]) {
                            $equal = false;
                            break;
                        }
                    }
                    if ($equal) {
                        continue 2;
                    }
                    $len--;
                    if (\is_int($issue->ptr[$len]) && \is_int($ptr[$len]) && $issue->ptr[$len] >= $ptr[$len]) {
                        $affectedIndexes []= &$issue->ptr[$len];
                    }
                }

                $this->ast->remove();

                foreach ($affectedIndexes as &$idx) {
                    $idx--;
                }

                $this->tryOrRollback(function () use ($affectedIndexes, $ptr, $_) {
                    $this->ast->ptr = $ptr;
                    foreach ($affectedIndexes as &$idx) {
                        $idx++;
                    }
                });
            }

            // From here on, a generic non-AST-aware transform method is used,
            // manually looking through the AST again to refind moved issue nodes.

            // Raise statements, if possible
            foreach ($this->applyGeneric() as $_) {
                $this->ast->raise();
            }
        } while ($old !== $file_provider->getContents($this->file_path));

        return $this->project_analyzer->getCodebase()->file_provider->getContents($this->file_path);
    }

    /**
     * Apply generic callback on AST, rolling back operations if any the issues disappears.
     *
     * @return \Generator<int, Node, null, void>
     */
    private function applyGeneric(): \Generator
    {
        foreach ($this->ast as $node) {
            $ptr = $this->ast->ptr;
            $ptrs = [];
            foreach ($this->issue_ptrs as [$issuePtr]) {
                $ptrs []= $issuePtr->ptr;
            }

            yield $node;

            $this->tryOrRollback(function () use ($ptr, $ptrs) {
                $this->ast->ptr = $ptr;
                foreach ($ptrs as $k => $ptr) {
                    $this->issue_ptrs[$k][0]->ptr = $ptr;
                }
            }, false);
        }
    }
    /**
     * Try applying updated AST, rolling back if necessary.
     *
     * @return void
     */
    private function tryOrRollback(callable $rollback, bool $expectCorrectPosition = true): void
    {
        $project_analyzer = $this->project_analyzer;
        $codebase = $project_analyzer->getCodebase();
        $file_provider = $codebase->file_provider;
        $file_path = $this->file_path;
        $file_name = $this->file_name;

        $old_contents = $file_provider->getContents($file_path);
        try {
            $new_contents = $this->printer->prettyPrintFile($this->ast->getStmts());
        } catch (\Throwable $e) {
            $rollback();
            return;
        }
        $file_provider->addTemporaryFileChanges($file_path, $new_contents);

        IssueBuffer::clear();

        $codebase->reloadFiles($project_analyzer, [$file_path]);
        $codebase->analyzer->addFilesToAnalyze([$file_path => $file_path]);
        $codebase->analyzer->analyzeFiles($project_analyzer, 1, false);

        $stmts = $codebase->getStatementsForFile($file_path);
        foreach ($this->issue_ptrs as [$ptr, $issue]) {
            $ok = true;
            if (!$expectCorrectPosition) {
                $node = $ptr->current();
                $ptr->rewind();
                $ptr->setStmts($this->ast);
                $ok = $ptr->find($node);
            }
            if ($ok && $ptr->setStmts($stmts)) {
                $node = $ptr->current();
                $line_start = (int) $node->getAttribute('startLine');
                $pos_start = (int) $node->getAttribute('startFilePos');
                $column_start = $pos_start -
                    (int) \strrpos($new_contents, "\n", $pos_start - \strlen($new_contents));

                if (IssueBuffer::alreadyEmitted($issue->getKey($file_name, $line_start, $column_start), false)) {
                    continue;
                }
            }

            $rollback();

            $file_provider->addTemporaryFileChanges($file_path, $old_contents);
            $stmts = $codebase->getStatementsForFile($file_path);

            $this->ast->setStmts($stmts);

            foreach ($this->issue_ptrs as [$ptr]) {
                $ptr->setStmts($this->ast);
            }

            return;
        }
    }
}
