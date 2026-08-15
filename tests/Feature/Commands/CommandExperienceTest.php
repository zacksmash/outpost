<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('exposes concise action-oriented descriptions for every public command', function () {
    $descriptions = [
        'outpost' => 'Create an isolated application instance',
        'outpost:build' => 'Build the configured Outpost image locally',
        'outpost:certify' => 'Configure trusted HTTPS for Outpost instances',
        'outpost:doctor' => 'Diagnose the host and application configuration',
        'outpost:exec' => 'Run a command inside an instance',
        'outpost:info' => 'Display instance details, endpoints, and credentials',
        'outpost:install' => 'Configure Outpost for this application',
        'outpost:list' => "List this application's Outpost instances",
        'outpost:logs' => "Display an instance's service logs",
        'outpost:open' => 'Open an instance endpoint in the default browser',
        'outpost:process' => "Inspect or restart an instance's application processes",
        'outpost:pull' => 'Pull the configured Outpost image',
        'outpost:remove' => 'Remove an instance and its container, worktree, and data',
        'outpost:shell' => 'Open an interactive shell inside an instance',
        'outpost:start' => 'Start an instance or recreate its missing container',
        'outpost:stop' => 'Stop an instance without removing its worktree or data',
        'outpost:upgrade' => 'Rebuild instance containers with the configured image',
        'outpost:verify' => 'Verify an instance for review or handoff',
    ];

    foreach ($descriptions as $name => $description) {
        expect(Artisan::all()[$name]->getDescription())->toBe($description);
    }
});

it('explains machine output and destructive options precisely', function () {
    $commands = Artisan::all();

    expect($commands['outpost:doctor']->getDefinition()->getOption('json')->getDescription())
        ->toBe('Output the diagnostic report as JSON')
        ->and($commands['outpost:info']->getDefinition()->getOption('json')->getDescription())
        ->toBe('Output instance details as JSON')
        ->and($commands['outpost:list']->getDefinition()->getOption('json')->getDescription())
        ->toBe('Output instances as JSON')
        ->and($commands['outpost:process']->getDefinition()->getOption('json')->getDescription())
        ->toBe('Output process states as JSON')
        ->and($commands['outpost:verify']->getDefinition()->getOption('json')->getDescription())
        ->toBe('Output the verification report as JSON')
        ->and($commands['outpost:install']->getDefinition()->getOption('https')->getDescription())
        ->toBe('Enable and prepare trusted local HTTPS')
        ->and($commands['outpost:upgrade']->getDefinition()->getOption('force')->getDescription())
        ->toBe('Rebuild from the current Outpost configuration even when the image is current')
        ->and($commands['outpost:upgrade']->getDefinition()->getOption('mount-path-repos')->getDescription())
        ->toBe('Approve newly discovered Composer path repositories without prompting')
        ->and($commands['outpost:start']->getDefinition()->getOption('mount-path-repos')->getDescription())
        ->toBe('Approve newly discovered Composer path repositories without prompting')
        ->and($commands['outpost:remove']->getDefinition()->getOption('force')->getDescription())
        ->toBe('Skip confirmation without bypassing worktree protection')
        ->and($commands['outpost:remove']->getDefinition()->getOption('discard-changes')->getDescription())
        ->toBe('Remove even when the worktree has uncommitted changes')
        ->and($commands['outpost:remove']->getDefinition()->getOption('forget')->getDescription())
        ->toBe('Remove local state without contacting the runtime or running teardown hooks');
});
