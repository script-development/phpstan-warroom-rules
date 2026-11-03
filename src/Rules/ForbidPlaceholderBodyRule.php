<?php

declare(strict_types = 1);

namespace ScriptDevelopment\PhpstanWarroomRules\Rules;

use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function is_array;
use function mb_trim;
use function preg_match;
use function preg_replace;
use function preg_split;
use function sprintf;

/**
 * Forbids a function, method or closure body that says it is unfinished. Two
 * shapes, each the artefact an agent leaves when it makes code compile instead
 * of writing it:
 *
 *   - an elided-code comment standing where code was dropped —
 *     `// ... existing code ...`, `// rest of the method unchanged`,
 *     `// your logic goes here` — matched against ELIDED_COMMENT_PATTERNS on a
 *     comment of at most MAX_ELISION_WORDS words, so prose about the code
 *     ("the rest of the code assumes a tenant") stays silent; and
 *   - a body whose only statement throws a new exception whose message says it
 *     is not written yet (UNWRITTEN_MESSAGE_PATTERNS), or, with no message, an
 *     exception class whose name says `NotImplemented`.
 *
 * A bare `// ...` is not an elision: Laravel writes it to mean "intentionally
 * empty". A message that names a reason (`Method not implemented by Laravel.`,
 * `Cannot stub a final class`) is a refusal, not a placeholder. An abstract or
 * interface method has no body and is never read. Comments inside a nested
 * closure or class belong to that body and are reported there, once; a
 * docblock on a nested declaration belongs to that declaration and is not
 * read at all.
 *
 * Doctrine source: war-room §Architectural Principles — Explicit over implicit
 * (#1): a placeholder ships a name that promises work the body does not do.
 *
 * Detection logic credit: both phrase lists are taken from
 * `PlaceholderImplementationRule` (SL112) of `heyosseus/sloppy` (MIT).
 *
 * @implements Rule<FunctionLike>
 */
final class ForbidPlaceholderBodyRule implements Rule
{
    /** Anchored to the start of the comment's text, delimiters stripped. */
    private const array ELIDED_COMMENT_PATTERNS = [
        '/^\.{2,}\s*\S.*\.{2,}$/u',
        '/^(\.{2,}|…)\s*(existing|rest|remaining|other|previous|same|unchanged)\b/iu',
        '/^(\.{2,}\s*)?(the )?(rest|remainder) of (the |your |this )?(code|implementation|method|logic|function|body)\b/iu',
        '/^(\.{2,}\s*)?(add|insert|put|write|place) (your |the )?(own )?(logic|code|implementation|business logic) here\b/iu',
        '/^(\.{2,}\s*)?(your |the )?(implementation|logic|code) goes here\b/iu',
    ];

    private const int MAX_ELISION_WORDS = 8;

    private const array UNWRITTEN_MESSAGE_PATTERNS = [
        '/^\s*(this )?(method |function |feature )?(is )?(not (yet )?implemented|unimplemented)( yet)?\s*[.!]*\s*$/iu',
        '/^\s*(todo|fixme|tbd|not implemented yet|not yet implemented|to be implemented|implement me)\b/iu',
        '/\b(is )?not (yet )?implemented( yet)?\s*[.!]*\s*$/iu',
        '/^\s*(this is (just )?a |a )?stub( method| function| implementation)?\s*[.!]*\s*$/iu',
    ];

    private const string NOT_IMPLEMENTED_CLASS_PATTERN = '/NotImplemented/i';

    private const string ELIDED_IDENTIFIER = 'forbidPlaceholderBody.elidedCode';

    private const string UNWRITTEN_IDENTIFIER = 'forbidPlaceholderBody.notImplemented';

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $body = $node->getStmts();

        if ($body === null) {
            return [];
        }

        $errors = [];

        foreach ($this->ownComments($body) as $comment) {
            $text = $this->textOf($comment);

            if ($this->isElision($text)) {
                $errors[] = RuleErrorBuilder::message(sprintf('Body has code elided by the comment "%s". Write out the code it stands for, or delete the comment.', $text))
                    ->identifier(self::ELIDED_IDENTIFIER)
                    ->line($comment->getStartLine())
                    ->build();
            }
        }

        $unwritten = $this->unwrittenThrow($body);

        if ($unwritten !== null) {
            $errors[] = $unwritten;
        }

        return $errors;
    }

    /**
     * @param array<Stmt> $body
     */
    private function unwrittenThrow(array $body): ?IdentifierRuleError
    {
        $statements = array_values(array_filter($body, static fn(Stmt $statement): bool => !$statement instanceof Nop));

        if (count($statements) !== 1 || !$statements[0] instanceof Expression) {
            return null;
        }

        $throw = $statements[0]->expr;

        if (!$throw instanceof Throw_ || !$throw->expr instanceof New_) {
            return null;
        }

        $new = $throw->expr;
        $message = $this->messageOf($new);

        if ($message === null) {
            if (!$new->class instanceof Name || preg_match(self::NOT_IMPLEMENTED_CLASS_PATTERN, $new->class->toString()) !== 1) {
                return null;
            }

            $message = $new->class->toString();
        } elseif (!$this->matchesAny(self::UNWRITTEN_MESSAGE_PATTERNS, $message)) {
            return null;
        }

        return RuleErrorBuilder::message(sprintf('Body only throws "%s": it is a placeholder, not an implementation. Implement it, or throw a specific exception whose message says why it is unsupported.', $message))
            ->identifier(self::UNWRITTEN_IDENTIFIER)
            ->line($throw->getStartLine())
            ->build();
    }

    /**
     * The literal message of a `new` — first positional argument or the `message:` named one; null when absent or not a literal.
     */
    private function messageOf(New_ $new): ?string
    {
        foreach ($new->args as $position => $arg) {
            if (!$arg instanceof Arg) {
                return null;
            }

            $isMessage = $arg->name === null ? $position === 0 : $arg->name->toLowerString() === 'message';

            if ($isMessage) {
                return $arg->value instanceof String_ ? $arg->value->value : '';
            }
        }

        return null;
    }

    private function isElision(string $text): bool
    {
        if ($text === '' || count(preg_split('/\s+/u', $text) ?: []) > self::MAX_ELISION_WORDS) {
            return false;
        }

        return $this->matchesAny(self::ELIDED_COMMENT_PATTERNS, $text);
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(array $patterns, string $text): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The words of a comment without its delimiters, on one line.
     */
    private function textOf(Comment $comment): string
    {
        $words = [];

        foreach (preg_split('/\R/u', $comment->getText()) ?: [] as $line) {
            $line = mb_trim((string) preg_replace('#^\s*(/\*+|\*+/?|//+|\#)|\*+/\s*$#u', '', $line));

            if ($line !== '') {
                $words[] = $line;
            }
        }

        return implode(' ', $words);
    }

    /**
     * Every line or block comment belonging to the body's own statements, once
     * each and in source order. A docblock is documentation, never an elision. A nested function or class is a body of its own, and the
     * comments php-parser attaches to its declaration node — its docblock —
     * are its, not the enclosing body's. A comment is attached to every node
     * starting at its position, so it is keyed by that position.
     *
     * @param array<Node> $nodes
     *
     * @return array<int, Comment>
     */
    private function ownComments(array $nodes): array
    {
        $comments = [];
        $this->collectComments($nodes, $comments);

        return $comments;
    }

    /**
     * @param array<int, Comment> $comments
     */
    private function collectComments(mixed $subject, array &$comments): void
    {
        if (!$subject instanceof Node) {
            foreach (is_array($subject) ? $subject : [] as $item) {
                $this->collectComments($item, $comments);
            }

            return;
        }

        if ($subject instanceof FunctionLike || $subject instanceof ClassLike) {
            return;
        }

        foreach ($subject->getComments() as $comment) {
            if (!$comment instanceof Doc) {
                $comments[$comment->getStartFilePos()] = $comment;
            }
        }

        foreach ($subject->getSubNodeNames() as $name) {
            $this->collectComments($subject->{$name}, $comments);
        }
    }
}
