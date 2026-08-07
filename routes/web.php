<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\RandomTextController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ReporterArticleController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->middleware('level:buttonpusher')->name('home');

Route::view('/login', 'login')->middleware('level:buttonpusher')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('level:buttonpusher')->name('login.attempt');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::redirect('/admin', '/admin/articles')->middleware('level:honcho')->name('admin');
Route::get('/admin/articles', [ArticleController::class, 'index'])->middleware('level:honcho')->name('admin.articles');
Route::post('/admin/articles', [ArticleController::class, 'store'])->middleware('level:honcho')->name('admin.articles.store');
Route::put('/admin/articles/{article}', [ArticleController::class, 'update'])->middleware('level:honcho')->name('admin.articles.update');
Route::patch('/admin/articles/{article}/approval', [ArticleController::class, 'updateApproval'])->middleware('level:honcho')->name('admin.articles.approval');
Route::get('/admin/articles/{article}/pic', [ArticleController::class, 'picture'])->middleware('level:honcho')->name('admin.articles.picture');
Route::delete('/admin/articles/{article}', [ArticleController::class, 'destroy'])->middleware('level:honcho')->name('admin.articles.destroy');
Route::get('/admin/random-texts', [RandomTextController::class, 'index'])->middleware('level:honcho')->name('admin.random-texts');
Route::post('/admin/random-texts', [RandomTextController::class, 'store'])->middleware('level:honcho')->name('admin.random-texts.store');
Route::put('/admin/random-texts/{randomText}', [RandomTextController::class, 'update'])->middleware('level:honcho')->name('admin.random-texts.update');
Route::delete('/admin/random-texts/{randomText}', [RandomTextController::class, 'destroy'])->middleware('level:honcho')->name('admin.random-texts.destroy');
Route::redirect('/reporter', '/reporter/suggest-a-story')->middleware('level:reporter')->name('reporter');
Route::get('/reporter/suggest-a-story', [ReporterArticleController::class, 'index'])->middleware('level:reporter')->name('reporter.suggest-story');
Route::post('/reporter/suggest-a-story', [ReporterArticleController::class, 'store'])->middleware('level:reporter')->name('reporter.suggest-story.store');
Route::get('/reporter/suggest-a-story/confirmation/{article}', [ReporterArticleController::class, 'confirmation'])->middleware('level:reporter')->name('reporter.suggest-story.confirmation');
Route::get('/reporter/suggest-a-story/confirmation/{article}/pic', [ReporterArticleController::class, 'picture'])->middleware('level:reporter')->name('reporter.suggest-story.picture');
