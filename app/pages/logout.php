<?php
declare(strict_types=1);

if (is_post()) {
    csrf_check();
    log_activity('logout');
    Auth::logout();
}
redirect('login');
