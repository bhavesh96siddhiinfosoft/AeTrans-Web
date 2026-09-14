<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may reach the network.
     *
     * Every page now draws its logo, name and footer from Firestore through a view
     * composer, so ANY test that renders a page makes an outbound call unless something
     * stops it. Before this guard the suite was quietly talking to the live Firebase
     * project on every render — slow, dependent on someone's wifi, and reading real
     * customer data to decide what a test asserts.
     *
     * The catch-all answers with an empty 200, which the readers treat as "the admin
     * has not filled that in" and fall back from. A test that wants real values calls
     * `fakeSettings()` (see FakesFirebase), which replaces this outright.
     *
     * `preventStrayRequests()` is the belt to that braces: if a future client is
     * pointed at a host the catch-all does not cover, the test fails loudly here rather
     * than succeeding slowly against production.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }
}
