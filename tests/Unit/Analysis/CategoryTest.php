<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;

it('has a category for silenced errors', function (): void {
    // SL501 and SL502 are neither error-handling nor readability: they are
    // about a decision to stop being told something. Teams mute whole
    // categories, so this family needs its own.
    expect(Category::from('suppression'))->toBe(Category::Suppression)
        ->and(Category::Suppression->label())->toBe('Suppression');
});

it('labels every category it defines', function (): void {
    foreach (Category::cases() as $category) {
        expect($category->label())->not->toBe('');
    }
});
