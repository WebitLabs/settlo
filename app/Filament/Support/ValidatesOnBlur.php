<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Livewire\Component as LivewireComponent;

/**
 * Re-validates one field as soon as the user leaves it (or pauses typing), so an error
 * appears — and disappears once fixed — without pressing Save/Next.
 *
 * Usage (must be the LAST call in the chain so other afterStateUpdated hooks run first):
 *   TextInput::make('iban')->required()->rule(new ValidIban)->tap(new ValidatesOnBlur)
 */
final readonly class ValidatesOnBlur
{
    public function __construct(private ?int $debounceMs = null) {}

    public function __invoke(Field $field): void
    {
        match (true) {
            $field instanceof Select => $field->live(),
            $this->debounceMs !== null => $field->live(debounce: $this->debounceMs),
            default => $field->live(onBlur: true),
        };

        $field->afterStateUpdated(function (LivewireComponent $livewire, Field $component): void {
            $livewire->validateOnly($component->getStatePath());
        });
    }
}
