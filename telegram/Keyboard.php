<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Reusable keyboard builder for reply and inline keyboards.
 *
 * Fluent API so no handler ever hand-builds the raw Telegram keyboard arrays.
 */
final class Keyboard
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $rows = [];

    public static function reply(): self
    {
        return new self();
    }

    public static function inline(): self
    {
        return new self();
    }

    /**
     * Add a row of reply-keyboard buttons (plain text labels).
     */
    public function row(string ...$labels): self
    {
        $this->rows[] = array_map(static fn (string $l): array => ['text' => $l], $labels);
        return $this;
    }

    /**
     * Add a row of inline buttons. Each item is [label, callback_data] or
     * [label, ['url' => ...]].
     *
     * @param array{0: string, 1: string|array<string, mixed>} ...$buttons
     */
    public function inlineRow(array ...$buttons): self
    {
        $row = [];
        foreach ($buttons as $button) {
            [$label, $action] = $button;
            $entry = ['text' => $label];
            if (is_array($action)) {
                $entry += $action;
            } else {
                $entry['callback_data'] = $action;
            }
            $row[] = $entry;
        }
        $this->rows[] = $row;
        return $this;
    }

    /**
     * Build a reply keyboard markup array.
     *
     * @return array<string, mixed>
     */
    public function buildReply(bool $resize = true, bool $oneTime = false, ?string $placeholder = null): array
    {
        $markup = [
            'keyboard'          => $this->rows,
            'resize_keyboard'   => $resize,
            'one_time_keyboard' => $oneTime,
        ];
        if ($placeholder !== null) {
            $markup['input_field_placeholder'] = $placeholder;
        }
        return $markup;
    }

    /**
     * Build an inline keyboard markup array.
     *
     * @return array<string, mixed>
     */
    public function buildInline(): array
    {
        return ['inline_keyboard' => $this->rows];
    }

    /**
     * @return array<string, mixed>
     */
    public static function remove(): array
    {
        return ['remove_keyboard' => true];
    }
}
