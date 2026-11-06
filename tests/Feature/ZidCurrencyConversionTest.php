<?php

namespace Tests\Feature;

use App\Services\Zid\ZidMapper;
use Tests\TestCase;

class ZidCurrencyConversionTest extends TestCase
{
    /**
     * An order shaped as Zid sends it: amounts in the order's currency,
     * transaction_amount in whatever currency its string names.
     *
     * @return array<string, mixed>
     */
    private function order(string $currency, float $total, float $lineTotal, ?float $paid = null, ?string $paidIn = null, ?float $rate = null): array
    {
        return [
            'id' => 1, 'created_at' => '2026-11-03 10:00:00', 'order_status' => ['code' => 'delivered'],
            'currency_code' => $currency,
            'currency' => [
                'order_currency' => ['code' => $currency, 'exchange_rate' => $rate],
                'order_store_currency' => ['code' => 'SAR', 'exchange_rate' => null],
            ],
            'order_total' => (string) $total,
            'transaction_amount' => $paid ?? $total,
            'transaction_amount_string' => number_format($paid ?? $total, 2).' '.($paidIn ?? $currency),
            'products' => [['id' => 'p-1', 'quantity' => 1, 'total' => $lineTotal]],
        ];
    }

    private function revenue(array $order): float
    {
        return app(ZidMapper::class)->order($order)['lines'][0]['revenue'];
    }

    public function test_riyal_orders_are_unchanged(): void
    {
        $this->assertSame(249.0, $this->revenue($this->order('SAR', 267.02, 249, rate: 1)));
    }

    public function test_an_order_paid_in_riyals_converts_at_the_rate_paid(): void
    {
        // 20 KWD charged as 245 SAR: the 18 KWD dress earned 220.50 SAR.
        $order = $this->order('KWD', 20, 18, paid: 245, paidIn: 'SAR', rate: 0.0816);

        $this->assertSame(220.5, $this->revenue($order));
        $this->assertSame('transaction', app(ZidMapper::class)->conversion($order)['source']);
    }

    public function test_without_a_riyal_payment_the_exchange_rate_converts_through_the_dollar(): void
    {
        // Zid's rate is per US dollar: 1 USD = 0.3 KWD, and 1 USD = 3.75 SAR.
        $order = $this->order('KWD', 10, 8, paidIn: 'KWD', rate: 0.3);

        $this->assertSame(100.0, $this->revenue($order));
        $this->assertSame('exchange_rate', app(ZidMapper::class)->conversion($order)['source']);

        // A dollar order carries a rate of 1.
        $this->assertSame(375.0, $this->revenue($this->order('USD', 110, 100, paidIn: 'USD', rate: 1)));
    }

    public function test_the_rate_paid_and_the_exchange_rate_agree_on_live_orders(): void
    {
        // A KWD order as Zid sent it: 2027 SAR paid for 167.66 KWD at a rate of 0.311.
        $mapper = app(ZidMapper::class);
        $paid = $mapper->conversion($this->order('KWD', 167.66, 151.2, paid: 2027, paidIn: 'SAR', rate: 0.311))['factor'];
        $byRate = $mapper->conversion($this->order('KWD', 167.66, 151.2, paidIn: 'KWD', rate: 0.311))['factor'];

        $this->assertEqualsWithDelta($paid, $byRate, 0.05 * $paid);
    }

    public function test_an_order_with_no_rate_is_left_as_it_is_and_reported(): void
    {
        $order = $this->order('USD', 60, 50, paidIn: 'USD');

        $this->assertSame(50.0, $this->revenue($order));
        $this->assertSame('unconverted', app(ZidMapper::class)->conversion($order)['source']);
    }

    public function test_an_order_that_names_no_currency_is_in_the_store_currency(): void
    {
        $order = ['id' => 2, 'created_at' => '2026-11-03 10:00:00', 'status' => 'delivered', 'products' => [['id' => 'p-1', 'quantity' => 2, 'total' => 80]]];

        $this->assertSame(80.0, $this->revenue($order));
    }
}
