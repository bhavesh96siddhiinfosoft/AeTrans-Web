<?php

namespace Tests\Feature;

use Tests\FakesFirebase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use FakesFirebase;

    public function test_the_front_page_is_served(): void
    {
        $this->fakeSettings([]);

        $this->get('/')->assertStatus(200);
    }
}
