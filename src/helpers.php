<?php

use Akibeo\Altcha\Altcha;

if (function_exists('altcha') === false) {
    /**
     * Access the ALTCHA plugin from templates, snippets and controllers:
     *
     *   altcha()->render()     // the widget, with its scripts
     *   altcha()->verify()     // check the payload of the submitted form
     *   altcha()->error()      // why the last verify() failed
     */
    function altcha(): Altcha
    {
        return Altcha::instance();
    }
}
