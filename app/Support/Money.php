<?php
declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

/** Exact non-negative USD using integer picodollars (12 decimal places). */
final readonly class Money
{
    private const SCALE = 1_000_000_000_000;

    private function __construct(private int $pico) {}

    public static function zero(): self { return new self(0); }

    public static function fromDecimal(string $amount): self
    {
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,12}))?$/D', $amount, $m)) {
            throw new InvalidArgumentException('Expected an exact positive USD decimal string.');
        }
        $whole = (int) $m[1];
        if ((string)$whole !== $m[1] || $whole > intdiv(PHP_INT_MAX, self::SCALE)) {
            throw new OverflowException('Amount exceeds supported range.');
        }
        return new self($whole * self::SCALE + (int)str_pad($m[2] ?? '', 12, '0'));
    }

    /** Rate is an exact USD-per-million-token decimal (up to six decimal places). */
    public static function tokenCost(int $tokens, string $rate): self
    {
        if ($tokens < 0 || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/D', $rate, $m)) {
            throw new InvalidArgumentException('Invalid token count or provider rate.');
        }
        $whole = (int)$m[1];
        if ((string)$whole !== $m[1] || $whole > intdiv(PHP_INT_MAX, 1_000_000)) {
            throw new OverflowException('Rate exceeds supported range.');
        }
        // USD/million tokens * 1e12 pico/USD = USD rate * 1e6 pico/token.
        $picoPerToken = $whole * 1_000_000 + (int)str_pad($m[2] ?? '', 6, '0');
        if ($picoPerToken !== 0 && $tokens > intdiv(PHP_INT_MAX, $picoPerToken)) {
            throw new OverflowException('Token cost exceeds supported range.');
        }
        return new self($tokens * $picoPerToken);
    }

    public function add(self $other): self
    {
        if ($other->pico > PHP_INT_MAX - $this->pico) {
            throw new OverflowException('Amount exceeds supported range.');
        }
        return new self($this->pico + $other->pico);
    }

    /** 25% markup, exact for Pixelforge's currently published token rates. */
    public function withMarkup(): self
    {
        if ($this->pico > intdiv(PHP_INT_MAX, 5)) {
            throw new OverflowException('Amount exceeds supported range.');
        }
        $scaled = $this->pico * 5;
        if ($scaled % 4 !== 0) {
            throw new InvalidArgumentException('Markup exceeds 12-place precision; do not round silently.');
        }
        return new self(intdiv($scaled, 4));
    }

    public function toDecimal(): string
    {
        return intdiv($this->pico, self::SCALE).'.'.str_pad((string)($this->pico % self::SCALE), 12, '0', STR_PAD_LEFT);
    }
}
