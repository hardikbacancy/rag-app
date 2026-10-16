<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Body bigger than post_max_size: PHP drops it entirely, so explain the fix.
        $exceptions->render(fn (PostTooLargeException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Upload is larger than PHP allows (post_max_size = '.ini_get('post_max_size').'). '
                .'Start the app with "composer serve", or raise post_max_size and upload_max_filesize in php.ini.'], 413)
            : null);
    })->create();
