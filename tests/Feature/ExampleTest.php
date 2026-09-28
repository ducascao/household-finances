<?php

it('responde na raiz', function () {
    $this->get('/')->assertSuccessful();
});
