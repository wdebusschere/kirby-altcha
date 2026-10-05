<?php
/**
 * ALTCHA widget, to be placed inside a <form>. The stylesheet and scripts
 * are printed with the first widget of a request. Prints nothing while the
 * plugin is disabled.
 *
 * @var array|null $attrs Widget attributes for this instance, e.g.
 *                        ['auto' => 'onsubmit', 'display' => 'floating']
 */
echo altcha()->render($attrs ?? []);
