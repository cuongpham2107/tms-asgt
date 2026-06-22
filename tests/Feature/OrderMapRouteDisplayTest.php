<?php

test('example', function () {
    $response = $this->get('/app/login');

    $response->assertStatus(200);
});
