<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| tests/Feature: boots the full Laravel app and wraps every test in a
| database transaction that is rolled back afterwards (RefreshDatabase).
| Tests run on the MySQL "testing" database, never SQLite.
|
| tests/Unit: plain PHP, no framework and no database. Keep pure domain
| logic (enums, DTOs, slot maths) here so those tests stay fast.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
