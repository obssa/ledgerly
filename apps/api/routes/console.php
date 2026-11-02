<?php

use Illuminate\Support\Facades\Schedule;

// Relay outbox events (webhooks, search indexing) and refresh FX rates. Jobs are added as modules land.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
