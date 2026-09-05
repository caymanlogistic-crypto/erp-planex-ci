<?php

$router->get('/company/crews', function () {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    redirect_to('/company/route-executors', 302);
}, 'company.route_executors');

$router->get('/company/crews/create', function () {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    redirect_to('/company/route-executors/create', 302);
}, 'company.route_executors');

$router->get('/company/driver-vehicle-blocks', function () {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    redirect_to('/company/route-executors', 302);
}, 'company.route_executors');

$router->get('/company/driver-vehicle-blocks/create', function () {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    redirect_to('/company/route-executors/create', 302);
}, 'company.route_executors');

$router->get('/company/contractor-assignments', function () {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    redirect_to('/company/responsible-assignments', 302);
}, 'company.roles_access');
