<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Acceptance');
pest()->tia()->locally();
uses()->in('Interpreter');
