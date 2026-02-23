<?php

test('superadmin denylist contains unique non-empty string abilities', function () {
    $abilities = config('superadmin_denylist.abilities', []);

    expect($abilities)->toBeArray();
    expect($abilities)->toEqual(array_values(array_unique($abilities)));

    foreach ($abilities as $ability) {
        expect($ability)->toBeString();
        expect(trim($ability))->not->toBe('');
    }
});
