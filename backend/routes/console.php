<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command("lfms:about", function () { $this->comment("LFMS API foundation"); })->purpose("Display LFMS application foundation information");
