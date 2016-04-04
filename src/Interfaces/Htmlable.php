<?php

namespace Grendizer\MicroFramework\Interfaces;

interface Htmlable
{
    /**
     * Get content as a string of HTML.
     *
     * @return string
     */
    public function toHtml();
}
