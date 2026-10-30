<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

$router->get('/migrate', 'MigrationController::migrate');
$router->get('/create-migration/{migration_class}', 'MigrationController::create_migration');
$router->get('/rollback', 'MigrationController::rollback');
$router->get('/rollback-all', 'MigrationController::rollback_all');
$router->get('/refresh', 'MigrationController::refresh');
$router->get('/status', 'MigrationController::status');

$router->group(['prefix' => '/api'], function ($router) {
    $router->post('/login', 'ApiController::login');
    $router->get('/oauth/{provider}/start', 'OAuthController::start');
    $router->get('/oauth/{provider}/callback', 'OAuthController::callback');
    $router->post('/logout', 'ApiController::logout');
    $router->post('/create', 'ApiController::create');
    $router->put('/update/{id}', 'ApiController::update');
    $router->delete('/delete/{id}', 'ApiController::delete');
    $router->get('/list', 'ApiController::list');
    $router->get('/profile', 'ApiController::profile');
    $router->get('/admin/database', 'AdminDatabaseController::index');
    $router->get('/marketplace', 'CommunityController::listings');
    $router->post('/marketplace', 'CommunityController::storeListing');
    $router->get('/marketplace/{id}/reviews', 'CommunityController::reviews');
    $router->post('/marketplace/{id}/reviews', 'CommunityController::create_review');
    $router->post('/marketplace/{id}/reviews/{reviewId}/comments', 'CommunityController::create_review_comment');
    $router->get('/marketplace/{id}/sales', 'CommerceController::listing_sales');
    $router->post('/support/complaints', 'SupportController::create_complaint');
    $router->get('/posts', 'CommunityController::posts');
    $router->post('/posts', 'CommunityController::storePost');
    $router->post('/media', 'CommunityController::uploadMedia');
    $router->post('/refresh', 'ApiController::refresh');
    // Community, commerce, social and admin features (specific routes must precede the generic resource routes)
    $router->post('/posts/{id}/like', 'SocialController::like');
    $router->post('/posts/{id}/comments', 'SocialController::comment');
    $router->put('/posts/{id}', 'SocialController::update_post');
    $router->delete('/posts/{id}', 'SocialController::delete_post');
    $router->get('/orders', 'CommerceController::index');
    $router->get('/orders/{id}/payment-status', 'CommerceController::payment_status');
    $router->post('/orders', 'CommerceController::store');
    $router->get('/analytics', 'SocialController::analytics');
    $router->get('/farmers', 'SocialController::farmers');
    $router->get('/members/{id}', 'SocialController::member_profile');
    $router->post('/follows/{id}/toggle', 'SocialController::toggle_follow');
    $router->get('/groups', 'SocialController::groups');
    $router->post('/groups/{id}/toggle', 'SocialController::toggle_group');
    $router->get('/saved', 'SocialController::saved');
    $router->post('/saved/{id}/toggle', 'SocialController::toggle_saved');
    $router->get('/notifications', 'SocialController::notifications');
    $router->put('/notifications/read', 'SocialController::read_notifications');
    $router->get('/messages/contacts', 'SocialController::contacts');
    $router->get('/messages/users', 'SocialController::message_users');
    $router->get('/messages/{id}', 'SocialController::conversation');
    $router->post('/messages', 'SocialController::send_message');
    $router->get('/admin/queues', 'AdminController::queues');
    $router->post('/admin/users', 'AdminController::create_user');
    $router->put('/admin/users/{id}/approve', 'AdminController::approve_user');
    $router->put('/admin/users/{id}/verify', 'AdminController::verify_user');
    $router->put('/admin/posts/{id}/approve', 'AdminController::approve_post');
    $router->put('/admin/reports/{id}/resolve', 'AdminController::resolve_report');
    $router->put('/admin/complaints/{id}/resolve', 'AdminController::resolve_complaint');

    // Sign-in second step, password reset
    $router->post('/login/verify', 'ApiController::login_verify');
    $router->post('/password/forgot', 'ApiController::forgot_password');
    $router->post('/password/reset', 'ApiController::reset_password');

    // Payments, trace codes and QR codes
    $router->post('/payments/xendit/webhook', 'CommerceController::xendit_webhook');
    $router->get('/trace/{code}', 'TraceController::show');
    $router->get('/admin/traces', 'TraceController::admin_index');
    $router->post('/admin/traces/{code}/qr', 'TraceController::regenerate_qr');

    // Administrator: reports, cloud files and database backups
    $router->post('/admin/reports/email', 'AdminController::email_report');
    $router->get('/admin/reports/{report}/pdf', 'AdminController::report_pdf');
    $router->get('/admin/cloud/files', 'CloudController::files');
    $router->post('/admin/cloud/upload', 'CloudController::upload');
    $router->delete('/admin/cloud/files', 'CloudController::delete_file');
    $router->get('/admin/backups', 'CloudController::backups');
    $router->post('/admin/backups', 'CloudController::create_backup');

    $router->get('/{resource}', 'AgriTraceController::index');
    $router->post('/{resource}', 'AgriTraceController::store');
    $router->get('/{resource}/{id}', 'AgriTraceController::show');
    $router->put('/{resource}/{id}', 'AgriTraceController::update');
    $router->delete('/{resource}/{id}', 'AgriTraceController::delete');
});
