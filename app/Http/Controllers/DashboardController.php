<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The home screen: a project search, empty until the user types. It carries no props of
 * its own - ProjectSearchController is what the page calls as the user searches.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard');
    }
}
