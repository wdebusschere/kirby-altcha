<?php

namespace Akibeo\Altcha\Tests;

use Akibeo\Altcha\Altcha;

class HelperTest extends TestCase
{
    public function testAltchaHelperIsRegistered(): void
    {
        $this->assertTrue(function_exists('altcha'));
    }

    public function testAltchaHelperReturnsTheSharedInstance(): void
    {
        $this->kirby();

        $this->assertInstanceOf(Altcha::class, altcha());
        $this->assertSame(altcha(), altcha());
        $this->assertSame(altcha(), Altcha::instance());
    }
}
