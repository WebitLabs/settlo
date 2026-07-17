<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $issue = fake()->dateTimeBetween('-6 months', 'now');
        $subtotal = fake()->randomFloat(2, 500, 15000);
        $vat = round($subtotal * 0.081, 2);

        return [
            'business_entity_id' => BusinessEntity::factory(),
            'client_id' => fn (array $attrs) => Client::factory()->state([
                'business_entity_id' => $attrs['business_entity_id'],
            ]),
            'invoice_number' => 'INV-2026-'.fake()->unique()->numerify('####'),
            'status' => InvoiceStatus::Sent,
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => $subtotal + $vat,
            'currency_code' => 'CHF',
            'issue_date' => $issue,
            'due_date' => (clone $issue)->modify('+30 days'),
            'language' => 'en',
            'sent_at' => $issue,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Draft, 'sent_at' => null]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
            'paid_amount' => $attrs['total'] ?? 0,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => InvoiceStatus::Sent,
            'issue_date' => now()->subDays(60),
            'due_date' => now()->subDays(30),
            'sent_at' => now()->subDays(60),
        ]);
    }
}
