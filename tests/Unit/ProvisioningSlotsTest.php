<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Zacksmash\Outpost\ProvisioningSlots;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/outpost-slots-'.Str::random(10);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('grants slots up to the configured limit without waiting', function () {
    Sleep::fake();

    $slots = new ProvisioningSlots($this->root, 2);

    $first = $slots->acquire();
    $second = $slots->acquire();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull();

    Sleep::assertNeverSlept();

    $first->release();
    $second->release();
});

it('waits for a held slot and reports the wait once', function () {
    Sleep::fake();

    $slots = new ProvisioningSlots($this->root, 1);
    $held = $slots->acquire();
    $waits = 0;

    Sleep::whenFakingSleep(fn () => $held->release());

    $slot = $slots->acquire(function (int $limit) use (&$waits) {
        $waits++;

        expect($limit)->toBe(1);
    });

    expect($slot)->not->toBeNull()
        ->and($waits)->toBe(1);

    Sleep::assertSleptTimes(1);

    $slot->release();
});

it('reuses a released slot without waiting', function () {
    Sleep::fake();

    $slots = new ProvisioningSlots($this->root, 1);

    $slots->acquire()->release();

    $slot = $slots->acquire();

    expect($slot)->not->toBeNull();

    Sleep::assertNeverSlept();

    $slot->release();
});

it('grants no slot and creates no lock files when unlimited', function () {
    $slots = new ProvisioningSlots($this->root, 0);

    expect($slots->acquire())->toBeNull()
        ->and(File::isDirectory($this->root.'/.slots'))->toBeFalse();
});

it('tolerates releasing the same slot twice', function () {
    $slots = new ProvisioningSlots($this->root, 1);

    $slot = $slots->acquire();

    $slot->release();
    $slot->release();

    expect(true)->toBeTrue();
});
