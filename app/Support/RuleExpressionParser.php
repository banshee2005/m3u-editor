<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * RuleExpressionParser — evaluates NextPVR-style recording-rule expressions
 * against programme fields.
 *
 * Supported grammar (the subset of NextPVR's AdvancedRules used by this app):
 *
 *   expr      := andExpr ( 'or' andExpr )*
 *   andExpr   := factor ( 'and' factor )*
 *   factor    := '(' expr ')' | condition
 *   condition := field operator literal
 *   field     := title | description | subtitle | category | channel
 *   operator  := like | = | != | <>
 *   literal   := '...' | "..."
 *
 * `like` uses SQL-style wildcards: '%' matches any sequence of characters and
 * '_' matches a single character. All field comparisons are case-insensitive.
 *
 * As a convenience, a bare keyword with no operators/fields (e.g. "CFL") is
 * treated as `title like '%CFL%'`.
 */
final class RuleExpressionParser
{
    private const FIELDS = ['title', 'description', 'subtitle', 'category', 'channel'];

    /**
     * @param  array<string, mixed>  $fields  Field name => value map.
     */
    public static function matches(string $expression, array $fields): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return false;
        }

        // Bare keyword convenience: no condition syntax → title contains keyword.
        if (! self::looksLikeExpression($expression)) {
            $keyword = str_replace(['%', '_', "'", '"'], '', $expression);

            return self::matchesLike((string) ($fields['title'] ?? ''), "%{$keyword}%");
        }

        $tokens = self::tokenize($expression);
        if ($tokens === []) {
            return false;
        }

        $parser = new self($tokens, $fields);

        return $parser->parseExpression() && $parser->pos === count($tokens);
    }

    /**
     * True when the expression contains rule syntax (operators, combinators,
     * parentheses, or quoted literals) as opposed to a bare keyword.
     */
    private static function looksLikeExpression(string $expression): bool
    {
        return preg_match('/\b(like|=|!=|<>|and|or)\b|\(|\)|\'[^\']*\'|"[^"]*"/i', $expression) === 1;
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $fields
     */
    private function __construct(private readonly array $tokens, private readonly array $fields) {}

    private int $pos = 0;

    private function parseExpression(): bool
    {
        $value = $this->parseAnd();

        while ($this->peek() === 'or') {
            $this->pos++;
            $right = $this->parseAnd();
            $value = $value || $right;
        }

        return $value;
    }

    private function parseAnd(): bool
    {
        $value = $this->parseFactor();

        while ($this->peek() === 'and') {
            $this->pos++;
            $right = $this->parseFactor();
            $value = $value && $right;
        }

        return $value;
    }

    private function parseFactor(): bool
    {
        if ($this->peek() === '(') {
            $this->pos++;
            $value = $this->parseExpression();

            if ($this->peek() === ')') {
                $this->pos++;
            }

            return $value;
        }

        return $this->parseCondition();
    }

    private function parseCondition(): bool
    {
        $field = mb_strtolower((string) ($this->peek() ?? ''));
        if (! in_array($field, self::FIELDS, true)) {
            return false;
        }
        $this->pos++;

        $operator = $this->peek();
        if (! in_array($operator, ['like', '=', '!=', '<>'], true)) {
            return false;
        }
        $this->pos++;

        $literal = $this->peek();
        if ($literal === null) {
            return false;
        }
        $this->pos++;

        $value = (string) ($this->fields[$field] ?? '');

        return match ($operator) {
            'like' => self::matchesLike($value, $literal),
            '=' => self::equals($value, $literal),
            '!=', '<>' => ! self::equals($value, $literal),
        };
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private static function equals(string $value, string $literal): bool
    {
        return mb_strtolower($value) === mb_strtolower(self::unquote($literal));
    }

    private static function matchesLike(string $value, string $pattern): bool
    {
        $regex = '/^'.self::patternToRegex(self::unquote($pattern)).'$/iu';

        return preg_match($regex, $value) === 1;
    }

    private static function unquote(string $literal): string
    {
        if (strlen($literal) >= 2 && (($literal[0] === "'" && str_ends_with($literal, "'")) || ($literal[0] === '"' && str_ends_with($literal, '"')))) {
            return substr($literal, 1, -1);
        }

        return $literal;
    }

    private static function patternToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');

        // SQL LIKE wildcards. preg_quote leaves '%' untouched, and '.' (from '_')
        // is re-introduced as the any-char class after quoting.
        return str_replace(['%', '_'], ['.*', '.'], $quoted);
    }

    /**
     * Tokenize an expression into field names, operators, quoted literals, and
     * parentheses. Throws on anything it can't consume so malformed rules fail
     * loudly rather than silently matching everything.
     *
     * @return list<string>
     */
    private static function tokenize(string $expression): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($expression);

        $pattern = '/\G\s*(\(|\)|and\b|or\b|title\b|description\b|subtitle\b|category\b|channel\b|like\b|=|!=|<>|\'[^\']*\'|"[^"]*")\s*/i';

        while ($offset < $length) {
            if (! preg_match($pattern, $expression, $matches, 0, $offset)) {
                throw new InvalidArgumentException('Unexpected token in rule expression: '.substr($expression, $offset));
            }

            $token = $matches[1];
            if (trim($token) !== '') {
                $tokens[] = mb_strtolower($token);
            }
            $offset += strlen($matches[0]);
        }

        return $tokens;
    }
}
