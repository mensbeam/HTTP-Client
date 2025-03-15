<?php
/**
 * @license MIT
 * Copyright 2025 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\HTTP\Client;

trait RetryAware {
    /**
     * @var int Used in retry callables to tell the retry handler to not retry the
     * request
     */
    public const REQUEST_STOP = 0;
    /**
     * @var int Used in retry callables to tell the retry handler to retry the
     * request
     */
    public const REQUEST_RETRY = 1;
    /**
     * @var int Used in retry callables to tell the retry handler to continue onto
     * its own logic
     */
    public const REQUEST_CONTINUE = 2;
}