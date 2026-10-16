<?php

use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\QueryController;
use Illuminate\Support\Facades\Route;

Route::get('documents', [DocumentController::class, 'index']);
Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:10,1');
Route::get('documents/{document}', [DocumentController::class, 'show']);
Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
Route::post('documents/{document}/reingest', [DocumentController::class, 'reingest'])->middleware('throttle:10,1');
Route::get('documents/{document}/file', [DocumentController::class, 'file']);
Route::get('documents/{document}/pages/{page}', [DocumentController::class, 'page'])->whereNumber('page');

// Each question costs an embedding + LLM call, so rate-limit it.
Route::post('ask', [QueryController::class, 'ask'])->middleware('throttle:30,1');
