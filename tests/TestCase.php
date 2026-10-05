<?php

namespace Akibeo\Altcha\Tests;

use Akibeo\Altcha\Altcha;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const SECRET = 'test-secret';

    protected string $tmp;

    protected function setUp(): void
    {
        // Keep Kirby from installing its Whoops error handlers per App,
        // which PHPUnit would otherwise flag on every test.
        App::$enableWhoops = false;

        $this->tmp = sys_get_temp_dir() . '/kirby-altcha-' . uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        App::destroy();
        Dir::remove($this->tmp);
    }

    protected function kirby(array $options = []): App
    {
        return new App([
            'roots' => ['index' => $this->tmp],
            'urls' => ['index' => 'https://example.com'],
            'options' => $options,
        ]);
    }

    /**
     * An instance with a secret and a challenge that is solved in a few
     * milliseconds. The plugin itself is not loaded in the tests, so its
     * cache is switched on by hand.
     */
    protected function altcha(array $options = [], array $kirbyOptions = []): Altcha
    {
        return new Altcha($this->kirby([
            'akibeo.altcha' => $options + [
                'secret' => static::SECRET,
                'cost' => 10,
                'counter' => [3, 8],
                'assetsUrl' => '/assets',
            ],
            'cache.akibeo.altcha' => true,
        ] + $kirbyOptions));
    }
}
