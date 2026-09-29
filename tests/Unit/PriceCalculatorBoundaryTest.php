<?php

namespace Tests\Unit;

use App\Services\PriceCalculator;
use PHPUnit\Framework\TestCase;

class PriceCalculatorBoundaryTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator();
    }

    // 境界値: 割引率0%（有効範囲の下限）
    public function test_discount_at_lower_boundary()
    {
        $result = $this->calculator->applyDiscount(1000, 0);
        $this->assertEquals(1000, $result);
    }

    // 境界値: 割引率100%（有効範囲の上限）
    public function test_discount_at_upper_boundary()
    {
        $result = $this->calculator->applyDiscount(1000, 100);
        $this->assertEquals(0, $result);
    }

    // 直前値: 割引率-1（下限の1つ手前 → 無効）
    public function test_discount_below_lower_boundary()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->applyDiscount(1000, -1);
    }

    // 直後値: 割引率101（上限の1つ超 → 無効）
    public function test_discount_above_upper_boundary()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->applyDiscount(1000, 101);
    }
}
