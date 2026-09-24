<?php

declare(strict_types=1);

use ZeroToProd\LaravelPackage\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);
pest()->tia()->locally();
