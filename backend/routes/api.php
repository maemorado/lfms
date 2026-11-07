<?php
use Illuminate\Support\Facades\Route;
Route::get("/health", function () { return response()->json(["success" => true, "message" => "LFMS API is running.", "data" => ["application" => config("app.name")]]); });
