<?php
namespace Deployer;

require 'recipe/laravel.php';

// Config

set('repository', 'git@github.com:markuspaks/reviewer.git');

set('http_user', 'virt53937');
add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

set('keep_releases', 1);

set('allow_anonymous_stats', false);

// Hosts

host('trulla.ee')
    ->set('remote_user', 'virt53937')
    ->set('php_version', '81-cli')
    ->set('deploy_path', '/data02/virt53937/domeenid/www.trulla.ee/reviewer');

// Tasks

task('build', function () {
    cd('{{release_path}}');
    run('npm install');
    run('npm run build');
});

after('artisan:migrate', 'build');
after('deploy:failed', 'deploy:unlock');
