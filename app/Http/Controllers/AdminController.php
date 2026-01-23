<?php

namespace App\Http\Controllers;

use App\Models\User;


class AdminController extends Controller {

    public function index() 
    {
        $user = Auth()->user();

        $user->load(['modules.questions']);
        return view('admindash', ['user' => $user]);
    }

}