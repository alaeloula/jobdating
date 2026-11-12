<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La sonde de disponibilité répond.
     */
    public function test_la_sonde_de_disponibilite_repond(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
